<?php

namespace App\Domain\Enrollment\Models;

use App\Domain\Catalog\Concerns\BelongsToCatalogTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email for one recipient of an enrollment event (docs/product/enrollment-contacts-and-emails.md, т. 4, 6).
 * The address itself is not stored: it is read from the account or the contact field when the email is composed.
 *
 * @property string $organization_id
 * @property string $event_id
 * @property string $audience
 * @property string $recipient_kind
 * @property string $recipient_key
 * @property string|null $recipient_user_id
 * @property string $status
 * @property string|null $skipped_reason
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $locked_until
 * @property CarbonImmutable|null $sent_at
 * @property string|null $last_error
 */
#[Fillable([
    'event_id', 'audience', 'recipient_kind', 'recipient_key', 'recipient_user_id', 'status',
    'skipped_reason', 'attempts', 'next_attempt_at', 'locked_until', 'sent_at', 'last_error',
])]
class EnrollmentEventDelivery extends Model
{
    use BelongsToCatalogTenant;
    use HasUuids;

    /** @return BelongsTo<EnrollmentEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EnrollmentEvent::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'locked_until' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }
}
