<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Adds a person to the team or changes their details (docs/product/team.md, т. 3, 9). The phone is required, the
 * e-mail is optional; the audit keeps which fields changed, never their values (т. 11).
 */
final readonly class SaveStaffMember
{
    public function __construct(private TenantContext $context, private AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  callable(StaffMember): void  $then  runs in the same transaction, e.g. to give the person a teacher profile
     * @return array{member: StaffMember, created: bool}
     */
    public function add(User $actor, array $payload, string $idempotencyKey, callable $then): array
    {
        $organization = $this->authorize($actor);
        $data = $this->validate($payload, true);
        if ($idempotencyKey === '') {
            return ['member' => $this->create($actor, $data, $then), 'created' => true];
        }
        if (mb_strlen($idempotencyKey) > 120) {
            throw ValidationException::withMessages(['idempotency_key' => 'Изпратете валиден ключ за заявката.']);
        }

        // The same request sent twice (a double tap, a retry) adds the person once.
        $key = 'team.member_added:'.$organization->id.':'.$actor->id.':'.hash('sha256', $idempotencyKey);

        return Cache::lock($key.':lock', 10)->block(5, function () use ($actor, $data, $then, $key): array {
            $existing = Cache::get($key);
            $member = is_string($existing) ? StaffMember::query()->find($existing) : null;
            if ($member !== null) {
                return ['member' => $member, 'created' => false];
            }
            $member = $this->create($actor, $data, $then);
            Cache::put($key, $member->id, now()->addDay());

            return ['member' => $member, 'created' => true];
        });
    }

    /** @param array<string, mixed> $payload */
    public function update(User $actor, StaffMember $member, array $payload): StaffMember
    {
        abort_unless($member->getAttribute('organization_id') === $this->context->organizationId(), 404);
        $this->authorize($actor);
        $data = $this->validate($payload, false);

        return DB::transaction(function () use ($member, $data): StaffMember {
            $member = StaffMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            abort_unless($member->version === (int) $data['version'], 409, 'Staff member version conflict.');
            if ($member->archived_at !== null) {
                throw ValidationException::withMessages(['member' => 'Човекът е архивиран.']);
            }
            $member->fill(Arr::only($data, ['full_name', 'phone', 'email']));
            $fields = array_keys($member->getDirty());
            if ($fields === []) {
                return $member;
            }
            $member->version++;
            $member->save();
            $this->audit->record(AuditAction::TeamMemberUpdated, new AuditableReference('staff_member', $member->id), ['fields' => $fields]);

            return $member;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  callable(StaffMember): void  $then
     */
    private function create(User $actor, array $data, callable $then): StaffMember
    {
        return DB::transaction(function () use ($actor, $data, $then): StaffMember {
            $member = StaffMember::query()->create([
                ...Arr::only($data, ['full_name', 'phone', 'email']), 'version' => 1, 'created_by' => $actor->id,
            ]);
            $this->audit->record(AuditAction::TeamMemberAdded, new AuditableReference('staff_member', $member->id), [
                'fields' => array_keys(array_filter(Arr::only($data, ['full_name', 'phone', 'email']), fn ($value) => $value !== null)),
            ]);
            $then($member);

            return $member;
        });
    }

    private function authorize(User $actor): Organization
    {
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($actor)->authorize('manageTeam', $organization);

        return $organization;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validate(array $payload, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';
        $data = Validator::make($payload, [
            'version' => $creating ? ['prohibited'] : ['required', 'integer', 'min:1'],
            'full_name' => [$required, 'required', 'string', 'max:255'],
            'phone' => [$required, 'required', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
        ], [
            'full_name.required' => 'Въведете името.',
            'phone.required' => 'Въведете телефон.',
            'email.email' => 'Въведете имейл, например ime@primer.bg.',
        ])->validate();
        if (array_key_exists('full_name', $data)) {
            $data['full_name'] = trim((string) $data['full_name']);
        }
        if (array_key_exists('phone', $data)) {
            $data['phone'] = PhoneNumber::normalize((string) $data['phone'])
                ?? throw ValidationException::withMessages(['phone' => 'Въведете телефон, например 0888 123 456.']);
        }
        if (array_key_exists('email', $data) && $data['email'] !== null) {
            $data['email'] = Str::lower(trim((string) $data['email']));
        }

        return $data;
    }
}
