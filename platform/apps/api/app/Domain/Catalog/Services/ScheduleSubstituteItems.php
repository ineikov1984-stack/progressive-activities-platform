<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SubstituteRequest;
use App\Domain\Catalog\Models\SubstituteRequestSession;
use App\Domain\Catalog\ValueObjects\PlannedSessionChange;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Request rows participate in schedule writes before the sessions are locked. */
final readonly class ScheduleSubstituteItems
{
    public function __construct(private SubstituteRequestLocks $locks) {}

    /** @param list<string> $sessions
     * @return list<string>
     */
    public function requestIds(array $sessions, ?string $requestId): array
    {
        return array_values(array_unique([...SubstituteRequestSession::query()->whereIn('session_id', $sessions)->where('status', 'open')->pluck('request_id')->all(), ...($requestId === null ? [] : SubstituteRequest::query()->whereKey($requestId)->pluck('id')->all())]));
    }

    /** @param list<string> $sessions
     * @param  list<string>  $groups
     * @return list<string>
     */
    public function groups(array $sessions, array $groups, ?string $requestId): array
    {
        $ids = $this->requestIds($sessions, $requestId);
        $related = SubstituteRequestSession::query()->whereIn('request_id', $ids)->pluck('session_id');
        $groups = array_values(array_unique([...$groups, ...Session::query()->whereIn('id', $related)->pluck('group_id')->all()]));
        sort($groups);

        return $groups;
    }

    /** @param list<string> $sessions
     * @return Collection<int, SubstituteRequestSession>
     */
    public function lock(array $sessions, ?string $requestId): Collection
    {
        if ($requestId !== null) {
            abort_unless(SubstituteRequest::query()->whereKey($requestId)->exists(), 404, 'Заявката не е намерена.');
        }

        return $this->locks->items($this->requestIds($sessions, $requestId));
    }

    /** @param Collection<int, SubstituteRequestSession> $items
     * @param  list<string>  $groups
     * @param  Collection<string, Session>  $sessions
     * @return list<string> requests changed by normalization
     */
    public function normalize(Collection $items, array $groups, Collection $sessions, CarbonImmutable $now): array
    {
        $changed = $items->filter(fn ($i) => isset($sessions[$i->session_id]) && SubstituteRequestLocks::expiredReason($i, $sessions[$i->session_id], $now) !== null)->pluck('request_id')->unique()->all();
        $this->locks->expire($items, $sessions, $groups, $now);

        return $changed;
    }

    /** @param list<PlannedSessionChange> $planned
     * @param  list<string>  $expired
     */
    public function apply(User $actor, array $planned, string $changeId, array $expired, CarbonImmutable $now): void
    {
        $targets = collect($planned)->keyBy(fn ($p) => $p->sessionId());
        $open = SubstituteRequestSession::query()->whereIn('session_id', $targets->keys())->where('status', 'open')->get();
        $batches = [];
        $changed = $expired;
        foreach ($open as $item) {
            $change = $targets[$item->session_id];
            $reason = match ($change->effect()) {
                'cancelled' => 'cancelled',
                'moved' => $change->before->starts_at->ne($change->after->starts_at) || $change->before->ends_at->ne($change->after->ends_at) ? 'moved' : null,
                default => null,
            };
            if ($reason !== null) {
                $key = 'expired:'.$reason;
                $values = ['status' => 'expired', 'closed_reason' => $reason, 'closed_at' => $now];
            } elseif ($change->effect() === 'substituted' && $item->requester_profile_id === $change->removedProfileId) {
                $key = 'assigned';
                $values = ['status' => 'assigned', 'substitute_profile_id' => $change->addedProfileId, 'decided_by_user_id' => $actor->id, 'schedule_change_id' => $changeId, 'closed_at' => $now];
            } else {
                continue;
            }
            $batches[$key]['ids'][] = $item->id;
            $batches[$key]['values'] = $values;
            $changed[] = $item->request_id;
        }
        foreach ($batches as $batch) {
            SubstituteRequestSession::query()->whereIn('id', $batch['ids'])->update($batch['values']);
        }
        $this->locks->finish(array_values(array_unique($changed)), $now);
    }
}
