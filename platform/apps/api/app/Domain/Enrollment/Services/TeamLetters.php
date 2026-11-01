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
    public const REQUESTED = 'substitute.requested';

    public function __construct(private readonly TenantContext $context) {}

    /** @param array<string, mixed> $payload */
    public function queue(string $type, array $payload, CarbonImmutable $now): EnrollmentEvent
    {
        $keys = ['substitute_request_id', 'requester_user_id'];
        if ($type !== self::REQUESTED || count($payload) !== count($keys)
            || ! array_all($keys, fn (string $key): bool => isset($payload[$key]) && is_string($payload[$key]) && Str::isUuid($payload[$key]))) {
            throw new InvalidArgumentException('Unsupported team letter or payload.');
        }
        $event = new EnrollmentEvent(['type' => $type, 'application_id' => null, 'enrollment_id' => null,
            'payload' => $payload, 'occurred_at' => $now]);
        $event->organization_id = $this->context->organizationId();
        $event->save();

        return $event;
    }
}
