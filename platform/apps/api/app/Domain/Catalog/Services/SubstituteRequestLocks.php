<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Group;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SubstituteRequest;
use App\Domain\Catalog\Models\SubstituteRequestSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Shared groups → requests → items → sessions lock order for substitute writers. */
final class SubstituteRequestLocks
{
    public function transaction(callable $work): mixed
    {
        try {
            return DB::transaction(function () use ($work) {
                if (DB::getDriverName() === 'pgsql') {
                    $timeout = max(1, (int) config('platform.schedule_lock_timeout_ms', 5000));
                    DB::statement("SET LOCAL lock_timeout = '{$timeout}ms'");
                }

                return $work();
            });
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['55P03', '40P01'], true)) {
                abort(409, 'Някой друг променя тези групи в момента. Опитайте отново след малко.');
            }
            throw $e;
        }
    }

    /**
     * @param  list<string>  $sessions
     * @param  list<string>  $requests
     * @return list<string>
     */
    public function groups(array $sessions = [], array $requests = []): array
    {
        $requests = array_unique([...$requests, ...SubstituteRequestSession::query()->whereIn('session_id', $sessions)->where('status', 'open')->pluck('request_id')->all()]);
        $sessions = array_unique([...$sessions, ...SubstituteRequestSession::query()->whereIn('request_id', $requests)->pluck('session_id')->all()]);
        $ids = Session::query()->whereIn('id', $sessions)->distinct()->orderBy('group_id')->pluck('group_id')->all();
        Group::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

        return $ids;
    }

    /**
     * Locks requests and items before callers lock sessions.
     *
     * @param  list<string>  $requests
     * @return Collection<int, SubstituteRequestSession>
     */
    public function items(array $requests): Collection
    {
        SubstituteRequest::query()->whereIn('id', $requests)->orderBy('id')->lockForUpdate()->get();

        return SubstituteRequestSession::query()->whereIn('request_id', $requests)->orderBy('id')->lockForUpdate()->get();
    }

    public static function expiredReason(SubstituteRequestSession $item, Session $session, CarbonImmutable $now): ?string
    {
        if ($item->status !== 'open') {
            return null;
        }
        if ($session->starts_at->lte($now)) {
            return 'started';
        }
        if ($session->status !== 'scheduled') {
            return 'cancelled';
        }
        if ($session->group->status === 'archived') {
            return 'archived';
        }

        return null;
    }

    /**
     * Only normalize items in groups already held by the caller.
     *
     * @param  Collection<int, SubstituteRequestSession>  $items
     * @param  Collection<string, Session>  $sessions
     * @param  list<string>  $groups
     */
    public function expire(Collection $items, Collection $sessions, array $groups, CarbonImmutable $now): int
    {
        $byReason = [];
        foreach ($items as $item) {
            $session = $sessions->get($item->session_id);
            if ($session === null || ! in_array($session->group_id, $groups, true)) {
                continue;
            }
            $reason = self::expiredReason($item, $session, $now);
            if ($reason !== null) {
                $byReason[$reason][] = $item->id;
            }
        }
        foreach ($byReason as $reason => $ids) {
            SubstituteRequestSession::query()->whereIn('id', $ids)->update(['status' => 'expired', 'closed_reason' => $reason, 'closed_at' => $now]);
        }

        return array_sum(array_map('count', $byReason));
    }

    /**
     * Bump each changed request once, closing only when all its items are closed (including other groups).
     *
     * @param  list<string>  $ids
     */
    public function finish(array $ids, CarbonImmutable $now): void
    {
        $open = SubstituteRequestSession::query()->whereIn('request_id', $ids)->where('status', 'open')->pluck('request_id')->all();
        SubstituteRequest::query()->whereIn('id', $ids)->whereIn('id', $open)->update(['version' => DB::raw('version + 1')]);
        SubstituteRequest::query()->whereIn('id', $ids)->whereNotIn('id', $open)->whereNull('closed_at')->update(['version' => DB::raw('version + 1'), 'closed_at' => $now]);
    }

    /** Exception to normal order: apply already holds this group and its sessions, never take another group. */
    public function noLongerTeaching(Group $group): void
    {
        $sessionIds = Session::query()->where('group_id', $group->id)->pluck('id');
        $requestIds = SubstituteRequestSession::query()->whereIn('session_id', $sessionIds)->where('status', 'open')->pluck('request_id')->unique()->all();
        $items = $this->items($requestIds);
        $teachers = DB::table('activity_session_instructors')->where('organization_id', $group->organization_id)->whereIn('session_id', $sessionIds)->get()->groupBy('session_id');
        $close = $items->filter(fn ($item) => $item->status === 'open' && $sessionIds->contains($item->session_id)
            && ! ($teachers[$item->session_id] ?? collect())->contains('instructor_profile_id', $item->requester_profile_id));
        if ($close->isEmpty()) {
            return;
        }
        $now = CarbonImmutable::now('UTC');
        SubstituteRequestSession::query()->whereIn('id', $close->pluck('id'))->update(['status' => 'expired', 'closed_reason' => 'not_teaching', 'closed_at' => $now]);
        $this->finish($close->pluck('request_id')->unique()->all(), $now);
    }
}
