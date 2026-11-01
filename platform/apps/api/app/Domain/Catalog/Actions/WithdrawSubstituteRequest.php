<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Models\SubstituteRequest;
use App\Domain\Catalog\Models\SubstituteRequestSession;
use App\Domain\Catalog\Services\SubstituteRequestLocks;
use App\Domain\Catalog\Services\SubstituteRequests;
use App\Domain\Identity\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;

final readonly class WithdrawSubstituteRequest
{
    public function __construct(private SubstituteRequests $reader, private SubstituteRequestLocks $locks, private AuditRecorder $audit) {}

    public function execute(User $actor, string $requestId, int $version): SubstituteRequest
    {
        $this->reader->authorize($actor);
        $result = $this->locks->transaction(function () use ($actor, $requestId, $version): array {
            $request = SubstituteRequest::query()->whereKey(strtolower($requestId))->where('requester_user_id', $actor->id)->first();
            abort_if($request === null, 404, 'Заявката не е намерена.');
            $groups = $this->locks->groups([], [$request->id]);
            $items = $this->locks->items([$request->id]);
            $request->refresh();
            $sessions = Session::query()->whereIn('id', $items->pluck('session_id'))->orderBy('id')->lockForUpdate()->with('group')->get()->keyBy('id');
            $now = CarbonImmutable::now('UTC');
            $expired = $this->locks->expire($items, $sessions, $groups, $now);
            abort_unless($request->version === $version, 409, 'Заявката е променена междувременно. Заредете отново.');
            $open = SubstituteRequestSession::query()->where('request_id', $request->id)->where('status', 'open')->pluck('id');
            if ($open->isEmpty()) {
                if ($expired > 0) {
                    $this->locks->finish([$request->id], $now);
                }

                return ['request' => $request->refresh(), 'resolved' => true];
            }
            SubstituteRequestSession::query()->whereIn('id', $open)->update(['status' => 'withdrawn', 'closed_at' => $now]);
            $this->locks->finish([$request->id], $now);
            $this->audit->record(AuditAction::ScheduleSubstituteRequestWithdrawn, new AuditableReference('substitute_request', $request->id), ['sessions_withdrawn' => $open->count(), 'sessions_expired' => $expired]);

            return ['request' => $request->refresh(), 'resolved' => false];
        });
        if ($result['resolved']) {
            throw new HttpResponseException(response()->json(['message' => 'Заявката вече е решена.', 'errors' => ['request' => ['Заявката вече е решена.']], 'request' => $this->reader->request($result['request'])], 422));
        }

        return $result['request'];
    }
}
