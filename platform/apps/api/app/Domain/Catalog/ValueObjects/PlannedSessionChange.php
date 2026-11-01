<?php

namespace App\Domain\Catalog\ValueObjects;

use App\Domain\Catalog\Models\Session;

/**
 * One session of a change before it is written (docs/product/schedule-changes.md, т. 4): the session as it is, the same
 * session as it will be (an unsaved copy — Enrollment works out who comes to each), and who teaches it before and after.
 */
final readonly class PlannedSessionChange
{
    /**
     * @param  array<string, array{role: string, replaces_instructor_profile_id?: string|null}>|null  $syncTeachers  the teachers to put on the session from the assignments; null — they stay
     * @param  list<string>  $teachersBefore  the teacher profiles on the session now
     * @param  list<string>  $teachersAfter  the teacher profiles on the session after the change
     */
    public function __construct(
        public Session $before,
        public Session $after,
        public ?array $syncTeachers,
        public array $teachersBefore,
        public array $teachersAfter,
        public ?string $kind = null,
        public ?string $replacedProfileId = null,
        public ?string $removedProfileId = null,
        public ?string $addedProfileId = null,
    ) {}

    public function sessionId(): string
    {
        return (string) $this->before->id;
    }

    /** What the letters call the change of this session. */
    public function effect(): string
    {
        if (! $this->before->exists) {
            return 'added';
        }
        if ($this->kind !== null) {
            return $this->kind;
        }

        return $this->after->status === 'cancelled' ? 'cancelled' : ($this->before->status === 'cancelled' ? 'restored' : 'moved');
    }
}
