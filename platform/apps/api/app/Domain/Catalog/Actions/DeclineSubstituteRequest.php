<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SubstituteRequest;
use App\Domain\Catalog\Models\SubstituteRequestSession;
use App\Domain\Catalog\Services\OfficeSubstituteRequests;
use App\Domain\Catalog\Services\SubstituteRequestLocks;
use App\Domain\Enrollment\Services\TeamLetters;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

final readonly class DeclineSubstituteRequest
{
    public function __construct(private OfficeSubstituteRequests $reader, private SubstituteRequestLocks $locks, private AuditRecorder $audit, private TeamLetters $letters) {}

    public function execute(User $actor, string $requestId, int $version): SubstituteRequest
    {
        $this->reader->authorize($actor);
        $result = $this->locks->transaction(function () use ($actor, $requestId, $version): array {
            $request = SubstituteRequest::query()->whereKey(strtolower($requestId))->first();
            abort_if($request === null, 404, 'Заявката не е намерена.');
            $groups = $this->locks->groups([], [$request->id]);
            $items = $this->locks->items([$request->id]);
            $request->refresh();
            $sessions = Session::query()->whereIn('id', $items->pluck('session_id'))->orderBy('id')->lockForUpdate()->with('group')->get()->keyBy('id');
            $now = CarbonImmutable::now('UTC');
            $expired = $this->locks->expire($items, $sessions, $groups, $now);
            abort_unless($request->version === $version, 409, 'Заявката е променена междувременно. Заредете отново.');
            $open = SubstituteRequestSession::query()->where('request_id', $request->id)->where('status', 'open')->orderBy('id')->get();
            if ($open->isEmpty()) {
                if ($expired > 0) {
                    $this->locks->finish([$request->id], $now);
                }

                return ['request' => $request->refresh(), 'resolved' => true];
            }
            SubstituteRequestSession::query()->whereIn('id', $open->pluck('id'))->update(['status' => 'declined', 'decided_by_user_id' => $actor->id, 'closed_at' => $now]);
            $this->locks->finish([$request->id], $now);
            $this->letters->queue('substitute.declined', ['substitute_request_id' => $request->id, 'requester_user_id' => $request->requester_user_id, 'user_id' => $request->requester_user_id, 'session_ids' => $open->pluck('session_id')->sort()->values()->all()], $now);
            $this->audit->record(AuditAction::ScheduleSubstituteRequestDeclined, new AuditableReference('substitute_request', $request->id), ['sessions_declined' => $open->count(), 'sessions_expired' => $expired]);

            return ['request' => $request->refresh(), 'resolved' => false];
        });
        if ($result['resolved']) {
            throw new HttpResponseException(response()->json(['message' => 'Заявката вече е решена.', 'errors' => ['request' => ['Заявката вече е решена.']], 'request' => $this->reader->detail($actor, $result['request']->id)], 422));
        }

        return $result['request'];
    }
}
