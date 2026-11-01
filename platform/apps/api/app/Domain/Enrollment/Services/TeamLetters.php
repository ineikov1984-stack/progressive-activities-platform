<?php

namespace App\Domain\Enrollment\Services;

use App\Domain\Enrollment\Models\EnrollmentEvent;
use App\Domain\Organizations\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** The team outbox accepts identifiers only; text is read under RLS at composition. */
final class TeamLetters
{
    public const DECLINED = 'substitute.declined';

    public const REQUESTED = 'substitute.requested';

    public function __construct(private readonly TenantContext $context) {}

    /** @param array<string, mixed> $payload */
    public function queue(string $type, array $payload, CarbonImmutable $now): EnrollmentEvent
    {
        $declined = $type === self::DECLINED;
        $keys = $declined ? ['substitute_request_id', 'requester_user_id', 'user_id'] : ['substitute_request_id', 'requester_user_id'];
        $sessions = $payload['session_ids'] ?? null;
        if (! in_array($type, [self::REQUESTED, self::DECLINED], true)
            || count($payload) !== count($keys) + ($declined ? 1 : 0)
            || ! array_all($keys, fn (string $key): bool => isset($payload[$key]) && is_string($payload[$key]) && Str::isUuid($payload[$key]))
            || ($declined && (! is_array($sessions) || ! array_is_list($sessions) || count($sessions) < 1 || count($sessions) > 50
                || ! array_all($sessions, fn ($id): bool => is_string($id) && Str::isUuid($id))))) {
            throw new InvalidArgumentException('Unsupported team letter or payload.');
        }
        $event = new EnrollmentEvent(['type' => $type, 'application_id' => null, 'enrollment_id' => null,
            'payload' => $payload, 'occurred_at' => $now]);
        $event->organization_id = $this->context->organizationId();
        $event->save();

        return $event;
    }
}
