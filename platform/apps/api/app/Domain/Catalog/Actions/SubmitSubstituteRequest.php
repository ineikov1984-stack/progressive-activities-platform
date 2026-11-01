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
use App\Domain\Catalog\Services\TeachingScope;
use App\Domain\Enrollment\Services\TeamLetters;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class SubmitSubstituteRequest
{
    public function __construct(private TenantContext $context, private TeachingScope $scope, private SubstituteRequests $reader, private SubstituteRequestLocks $locks, private TeamLetters $letters, private AuditRecorder $audit) {}

    /**
     * @param  list<array{id:string,version:int}>  $sessions
     * @return array{request:SubstituteRequest,replayed:bool}
     */
    public function execute(User $actor, array $sessions, string $reason, ?string $note, ?string $suggestedProfileId, string $key): array
    {
        $this->reader->authorize($actor);
        $data = Validator::make(['sessions' => $sessions, 'reason' => $reason, 'note' => $note === null ? null : trim($note), 'suggested_profile_id' => $suggestedProfileId, 'key' => trim($key)], [
            'sessions' => ['required', 'array', 'min:1', 'max:50'], 'sessions.*.id' => ['required', 'uuid', 'distinct:ignore_case'], 'sessions.*.version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'in:vacation,personal,other'], 'note' => ['nullable', 'string', 'max:300'], 'suggested_profile_id' => ['nullable', 'uuid'], 'key' => ['required', 'string', 'max:120'],
        ])->validate();
        $normalized = array_map(fn ($row) => ['id' => strtolower($row['id']), 'version' => (int) $row['version']], $data['sessions']);
        usort($normalized, fn ($a, $b) => $a['id'] <=> $b['id']);
        $suggestedProfileId = $suggestedProfileId === null ? null : strtolower($suggestedProfileId);
        $note = $data['note'] === '' ? null : $data['note'];
        $hash = hash('sha256', json_encode(['note' => $note, 'reason' => $reason, 'sessions' => $normalized, 'suggested_profile_id' => $suggestedProfileId], JSON_THROW_ON_ERROR));
        $key = trim($key);

        try {
            return $this->locks->transaction(function () use ($actor, $normalized, $reason, $note, $suggestedProfileId, $hash, $key): array {
                $ids = array_column($normalized, 'id');
                $this->locks->groups($ids);
                $prior = SubstituteRequest::query()->where('requester_user_id', $actor->id)->where('idempotency_key', $key)->first();
                if ($prior !== null) {
                    if (! hash_equals($prior->request_hash, $hash)) {
                        throw ValidationException::withMessages(['sessions' => 'Тази заявка вече е изпратена с други данни. Заредете отново.']);
                    }

                    return ['request' => $prior, 'replayed' => true];
                }
                $open = SubstituteRequestSession::query()->whereIn('session_id', $ids)->where('status', 'open')->pluck('request_id')->unique()->all();
                $this->locks->items($open);
                $found = Session::query()->whereIn('id', $ids)->with(['group.offering', 'instructors'])->orderBy('id')->sharedLock()->get();
                $profiles = $this->scope->profilesFor($actor);
                abort_unless($found->count() === count($ids), 404, 'Заниманието не е намерено.');
                $common = $profiles;
                foreach ($found as $session) {
                    $mine = array_intersect($profiles, $session->instructors->modelKeys());
                    abort_if($mine === [] || $session->group->status === 'draft', 404, 'Заниманието не е намерено.');
                    $common = array_intersect($common, $mine);
                }
                $now = CarbonImmutable::now('UTC');
                $today = $now->setTimezone('Europe/Sofia')->startOfDay();
                $versions = array_column($normalized, 'version', 'id');
                foreach ($found as $session) {
                    abort_unless($session->version === $versions[$session->id], 409, 'Заниманието е променено междувременно. Заредете отново.');
                    if ($session->starts_at->lte($now)) {
                        throw ValidationException::withMessages(['sessions' => 'Заниманието вече е започнало.']);
                    }
                    if ($session->status !== 'scheduled') {
                        throw ValidationException::withMessages(['sessions' => 'Отмененото занимание първо го върнете.']);
                    }
                    if ($session->group->status === 'archived') {
                        throw ValidationException::withMessages(['sessions' => 'Групата е в архива.']);
                    }
                    if ($session->starts_at->setTimezone('Europe/Sofia')->startOfDay()->gt($today->addDays(365))) {
                        throw ValidationException::withMessages(['sessions' => 'Изберете ден от днес до една година напред.']);
                    }
                }
                if ($found->max('starts_at')->setTimezone('Europe/Sofia')->startOfDay()->gt($found->min('starts_at')->setTimezone('Europe/Sofia')->startOfDay()->addDays(31))) {
                    throw ValidationException::withMessages(['sessions' => 'Изберете най-много 31 дни.']);
                }
                if ($common === []) {
                    throw ValidationException::withMessages(['sessions' => 'Изпратете отделни заявки.']);
                }
                if (SubstituteRequestSession::query()->whereIn('session_id', $ids)->whereIn('requester_profile_id', $profiles)->where('status', 'open')->exists()) {
                    throw ValidationException::withMessages(['sessions' => 'Вече търсите заместник за това занимание.']);
                }
                if ($suggestedProfileId !== null && ! in_array($suggestedProfileId, array_column($this->reader->colleagues($profiles, $found), 'profile_id'), true)) {
                    throw ValidationException::withMessages(['suggested_profile_id' => 'Изберете колега от списъка.']);
                }
                $profile = array_values($common)[0];
                $request = SubstituteRequest::query()->create(['requester_user_id' => $actor->id, 'requester_profile_id' => $profile, 'reason' => $reason, 'note' => $note, 'suggested_profile_id' => $suggestedProfileId, 'version' => 1, 'idempotency_key' => $key, 'request_hash' => $hash]);
                $rows = array_map(fn ($id) => ['id' => (string) Str::uuid(), 'organization_id' => $this->context->organizationId(), 'request_id' => $request->id, 'requester_profile_id' => $profile, 'session_id' => $id, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now], $ids);
                DB::table('substitute_request_sessions')->insert($rows);
                $this->letters->queue('substitute.requested', ['substitute_request_id' => $request->id, 'requester_user_id' => $actor->id], $now);
                $this->audit->record(AuditAction::ScheduleSubstituteRequested, new AuditableReference('substitute_request', $request->id), ['session_count' => count($ids), 'reason' => $reason, 'has_note' => $note !== null, 'has_suggestion' => $suggestedProfileId !== null]);

                return ['request' => $request, 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Concurrent submissions for disjoint groups can meet only at the unique key.
            $prior = SubstituteRequest::query()->where('requester_user_id', $actor->id)->where('idempotency_key', $key)->first();
            if ($prior === null) {
                throw $exception;
            }
            if (! hash_equals($prior->request_hash, $hash)) {
                throw ValidationException::withMessages(['sessions' => 'Тази заявка вече е изпратена с други данни. Заредете отново.']);
            }

            return ['request' => $prior, 'replayed' => true];
        }
    }
}
