<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\AbilityKind;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Enums\StaffScope;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffGrant;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Models\StaffScopeGroup;
use App\Domain\Organizations\Services\StaffRights;
use App\Domain\Organizations\Services\TeamAbilities;
use App\Domain\Organizations\Services\TeamRoles;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Domain\Organizations\ValueObjects\StaffAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Saves a person's card "Права" whole (docs/product/team-rights.md, т. 4, 5, 9): the scope, the selected groups and
 * activities, the added rights with their end dates and the template's label. The difference is written in one
 * transaction under the person's version, after the guards of т. 5:
 * - only the owner and the administrator; the administrator only for the roles they may give; nobody for themselves,
 *   for the owner, for a person without a login or an invitation, or for an archived person;
 * - everyone gives only what they have; finance only the owner, confirmed, for the whole organization, once it is on;
 * - the rights the actor may not give come back unchanged and are kept — any change to them is refused;
 * - a narrower scope that would take finance away is the owner's.
 * The groups are identifiers only: that they are of this organization the route checks (`Rule::exists`) and the
 * composite key keeps.
 */
final readonly class SaveStaffAccess
{
    private const TIMEZONE = 'Europe/Sofia';

    public function __construct(
        private TenantContext $context,
        private TeamRoles $roles,
        private StaffRights $rights,
        private AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $card  `{ scope, groups: [{group_id}|{activity_id}], grants: [{ability, expires_on}], template, confirm_finance, version }`
     * @param  bool  $withVersion  false when the person is added in the same request (`POST /team/members` with `access`)
     */
    public function handle(User $actor, StaffMember $member, array $card, bool $withVersion = true): StaffMember
    {
        abort_unless($member->getAttribute('organization_id') === $this->context->organizationId(), 404);
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($actor)->authorize('manageTeam', $organization);
        $data = $this->validate($card, $withVersion);

        return DB::transaction(function () use ($actor, $member, $organization, $data, $withVersion): StaffMember {
            // The order of the other team actions: the organization, the person, the membership.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $member = StaffMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            if ($member->user_id !== null) {
                OrganizationMembership::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
                    ->where('user_id', $member->user_id)->lockForUpdate()->first();
            }
            if ($withVersion) {
                abort_unless($member->version === (int) $data['version'], 409, 'Правата са променени междувременно. Заредете страницата отново.');
            }
            $role = $this->assertMayChange($actor, $member, $organization);
            $owner = $this->roles->actorRole($actor, $organization) === OrganizationRole::OrganizationOwner;
            $giver = $this->rights->for($actor);

            $scope = $this->scope($role, $data['scope'] ?? null);
            $now = now()->toImmutable();
            $open = StaffGrant::query()->where('staff_member_id', $member->id)->whereNull('revoked_at')->get()
                ->keyBy(fn (StaffGrant $grant): string => $grant->ability);
            $active = $open->filter(fn (StaffGrant $grant): bool => $grant->expires_at === null || $grant->expires_at->greaterThan($now));
            if (! $owner && $scope !== StaffScope::All
                && $active->contains(fn (StaffGrant $grant): bool => TeamAbilities::isFinance($grant->ability))) {
                throw new AuthorizationException('Това би отнело достъпа до финансите — може само собственикът.');
            }

            /** @var array<int, array<string, mixed>> $requested */
            $requested = $data['grants'] ?? [];
            /** @var array<int, array<string, mixed>> $targets */
            $targets = $scope === StaffScope::Groups ? $data['groups'] ?? [] : [];
            $wanted = $this->grants($role, $scope, $requested);
            $added = array_values(array_diff(array_keys($wanted), $active->keys()->all()));
            $removed = array_values(array_diff($active->keys()->all(), array_keys($wanted)));
            $changed = [];
            foreach ($wanted as $ability => $end) {
                $current = $active->get($ability);
                if ($current !== null && ! $this->sameEnd($current->expires_at, $end)) {
                    $changed[] = $ability;
                }
            }
            foreach ($added as $ability) {
                if (! $this->mayGive($owner, $giver, $ability)) {
                    throw new AuthorizationException(TeamAbilities::isFinance($ability)
                        ? 'Финансите дава само собственикът.' : 'Можете да давате само права, които имате.');
                }
            }
            foreach ([...$removed, ...$changed] as $ability) {
                if (! $this->mayGive($owner, $giver, $ability)) {
                    throw new AuthorizationException('Можете да променяте само права, които сами давате.');
                }
            }
            $index = array_flip(array_keys($wanted));
            foreach ([...$added, ...$changed] as $ability) {
                $this->assertEnd($wanted[$ability], 'grants.'.$index[$ability].'.expires_on');
                if (TeamAbilities::isFinance($ability)) {
                    $this->assertFinance($data);
                }
            }
            $template = $this->template($role, $scope, $data['template'] ?? null, array_keys($wanted));

            $subject = new AuditableReference('staff_member', (string) $member->id);
            $changes = 0;
            foreach ($removed as $ability) {
                $changes += $this->revoke($actor, $active[$ability], 'removed', $now, $subject);
            }
            foreach ($changed as $ability) {
                $active[$ability]->forceFill(['expires_at' => $wanted[$ability]])->save();
                $this->audit->record(AuditAction::TeamAccessGrantChanged, $subject, [
                    'grant_id' => $active[$ability]->id, 'ability' => $ability, 'expires_at' => $wanted[$ability]?->toIso8601String(),
                ]);
                $changes++;
            }
            foreach ($added as $ability) {
                // An expired row of the same right is closed first: one open row of a right per person.
                $expired = $open->get($ability);
                if ($expired !== null) {
                    $this->revoke($actor, $expired, 'expired', $now, $subject);
                }
                $grant = StaffGrant::query()->create([
                    'staff_member_id' => $member->id, 'ability' => $ability, 'granted_by' => $actor->id, 'granted_at' => $now,
                    'expires_at' => $wanted[$ability],
                ]);
                $this->audit->record(AuditAction::TeamAccessGrantAdded, $subject, [
                    'grant_id' => $grant->id, 'ability' => $ability, 'expires_at' => $wanted[$ability]?->toIso8601String(),
                ]);
                $changes++;
            }
            $changes += $this->saveGroups($actor, $member, $targets, $now, $subject);

            $stored = $scope === $role->defaultScope() ? null : $scope->value;
            if ($member->access_scope !== $stored) {
                $this->audit->record(AuditAction::TeamAccessScopeChanged, $subject, ['from' => $member->access_scope, 'to' => $stored]);
                $member->access_scope = $stored;
                $changes++;
            }
            if ($member->access_template !== $template) {
                $this->audit->record(AuditAction::TeamAccessTemplateChanged, $subject, ['from' => $member->access_template, 'to' => $template]);
                $member->access_template = $template;
                $changes++;
            }
            if ($changes > 0) {
                $member->version++;
                $member->save();
                $this->rights->forget();
            }

            return $member;
        });
    }

    /** The guards on whose card it is (т. 4.1, 5); the person's role, from the membership or the invitation. */
    private function assertMayChange(User $actor, StaffMember $member, Organization $organization): OrganizationRole
    {
        if ($member->archived_at !== null) {
            throw ValidationException::withMessages(['member' => 'Човекът е в архива.']);
        }
        if ($member->user_id !== null && $member->user_id === $actor->id) {
            throw ValidationException::withMessages(['member' => 'Не можете да променяте собствените си права.']);
        }
        $role = $this->roles->personRole($organization, $member);
        if ($role === null) {
            throw ValidationException::withMessages(['member' => 'Първо изпратете покана — правата важат за човек с достъп.']);
        }
        if ($role === OrganizationRole::OrganizationOwner) {
            throw ValidationException::withMessages(['member' => 'Собственикът има всички права.']);
        }
        $actorRole = $this->roles->actorRole($actor, $organization);
        if (! $role->isTeam() || ($actorRole !== OrganizationRole::OrganizationOwner && ! in_array($role, TeamRoles::ADMINISTRATOR_GIVES, true))) {
            throw new AuthorizationException('Нямате право да променяте правата на този човек.');
        }

        return $role;
    }

    private function scope(OrganizationRole $role, mixed $value): StaffScope
    {
        $chosen = is_string($value) ? StaffScope::from($value) : null;
        if ($chosen !== null && ! in_array($chosen, $role->allowedScopes(), true)) {
            throw ValidationException::withMessages(['scope' => $role->allowedScopes() === [StaffScope::All]
                ? 'За тази роля обхватът е цялата организация.' : 'Изберете за кои групи важат правата.']);
        }

        return $chosen ?? $role->defaultScope();
    }

    /**
     * The added rights the card asks for, by right, with the end as the start of the next day in Sofia (UTC).
     *
     * @param  array<int, array<string, mixed>>  $grants
     * @return array<string, CarbonImmutable|null>
     */
    private function grants(OrganizationRole $role, StaffScope $scope, array $grants): array
    {
        $gives = array_map(TeamAbilities::canonical(...), $role->abilities());
        $wanted = [];
        foreach (array_values($grants) as $index => $grant) {
            $ability = (string) $grant['ability'];
            if (! TeamAbilities::isGrantable($ability)) {
                throw ValidationException::withMessages(["grants.{$index}.ability" => 'Това право идва само с ролята.']);
            }
            if (in_array($ability, $gives, true)) {
                throw ValidationException::withMessages(["grants.{$index}.ability" => 'Ролята вече дава това право.']);
            }
            if ($scope !== StaffScope::All && TeamAbilities::kind($ability) === AbilityKind::WholeOrganization) {
                throw ValidationException::withMessages(["grants.{$index}.ability" => 'Финансите и настройките се дават само за цялата организация.']);
            }
            $day = $grant['expires_on'] ?? null;
            $wanted[$ability] = is_string($day)
                ? CarbonImmutable::createFromFormat('!Y-m-d', $day, self::TIMEZONE)?->addDay()->utc()
                : null;
        }

        return $wanted;
    }

    /** The actor gives only what they have; finance only the owner. Seeing the whole schedule comes with the scope "all". */
    private function mayGive(bool $owner, StaffAccess $giver, string $ability): bool
    {
        if ($owner) {
            return true;
        }
        if (TeamAbilities::isFinance($ability)) {
            return false;
        }

        return $giver->allowsAcrossOrganization($ability)
            || ($ability === 'schedule.view_all' && $giver->scope === StaffScope::All);
    }

    /** A new or changed end: after today and at most three years ahead. */
    private function assertEnd(?CarbonImmutable $end, string $field): void
    {
        if ($end === null) {
            return;
        }
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        if ($end->lessThanOrEqualTo($today->addDay())) {
            throw ValidationException::withMessages([$field => 'Крайната дата трябва да е след днес.']);
        }
        if ($end->greaterThan($today->addYears(3)->addDay())) {
            throw ValidationException::withMessages([$field => 'Изберете дата до три години напред.']);
        }
    }

    /** @param array<string, mixed> $data */
    private function assertFinance(array $data): void
    {
        if (! TeamAbilities::financeEnabled()) {
            throw ValidationException::withMessages(['grants' => 'Финансите още не са включени.']);
        }
        if (($data['confirm_finance'] ?? false) !== true) {
            throw ValidationException::withMessages(['confirm_finance' => 'Потвърдете, че давате достъп до финансите.']);
        }
    }

    /**
     * "Партньор от училище" is a viewer with selected groups and both of its rights; its label goes with any of them.
     *
     * @param  list<string>  $abilities
     */
    private function template(OrganizationRole $role, StaffScope $scope, mixed $template, array $abilities): ?string
    {
        if ($template === null) {
            return null;
        }
        if ($role !== OrganizationRole::Viewer || $scope !== StaffScope::Groups || array_diff(TeamAbilities::SCHOOL_PARTNER, $abilities) !== []) {
            throw ValidationException::withMessages(['template' => 'Партньорът от училище е „Само преглед“ с избрани групи, записване и присъствия.']);
        }

        return (string) $template;
    }

    private function sameEnd(?CarbonImmutable $current, ?CarbonImmutable $wanted): bool
    {
        return $current === null || $wanted === null ? $current === $wanted : $current->equalTo($wanted);
    }

    private function revoke(User $actor, StaffGrant $grant, string $reason, CarbonImmutable $now, AuditableReference $subject): int
    {
        $grant->forceFill(['revoked_at' => $now, 'revoked_by' => $actor->id, 'revoked_reason' => $reason])->save();
        $this->audit->record(AuditAction::TeamAccessGrantRevoked, $subject, [
            'grant_id' => $grant->id, 'ability' => $grant->ability, 'reason' => $reason,
        ]);

        return 1;
    }

    /**
     * The selected groups and activities: the ones no longer chosen are removed, the new ones added.
     *
     * @param  array<int, array<string, mixed>>  $targets
     */
    private function saveGroups(User $actor, StaffMember $member, array $targets, CarbonImmutable $now, AuditableReference $subject): int
    {
        // In lower case: PostgreSQL keeps a UUID in lower case whatever was sent.
        $wanted = [];
        foreach ($targets as $target) {
            $group = isset($target['group_id']) ? Str::lower((string) $target['group_id']) : null;
            $activity = $group === null ? Str::lower((string) $target['activity_id']) : null;
            $wanted[$group !== null ? 'group:'.$group : 'activity:'.$activity] = ['group_id' => $group, 'activity_id' => $activity];
        }
        $changes = 0;
        foreach (StaffScopeGroup::query()->where('staff_member_id', $member->id)->whereNull('removed_at')->get() as $row) {
            $key = $row->group_id !== null ? 'group:'.Str::lower($row->group_id) : 'activity:'.Str::lower((string) $row->activity_id);
            if (isset($wanted[$key])) {
                unset($wanted[$key]);

                continue;
            }
            $row->forceFill(['removed_at' => $now, 'removed_by' => $actor->id])->save();
            $this->audit->record(AuditAction::TeamAccessGroupRemoved, $subject, [
                'scope_group_id' => $row->id, 'group_id' => $row->group_id, 'activity_id' => $row->activity_id,
            ]);
            $changes++;
        }
        foreach ($wanted as $target) {
            $row = StaffScopeGroup::query()->create([...$target, 'staff_member_id' => $member->id, 'added_by' => $actor->id, 'added_at' => $now]);
            $this->audit->record(AuditAction::TeamAccessGroupAdded, $subject, [
                'scope_group_id' => $row->id, 'group_id' => $target['group_id'], 'activity_id' => $target['activity_id'],
            ]);
            $changes++;
        }

        return $changes;
    }

    /**
     * @param  array<string, mixed>  $card
     * @return array<string, mixed>
     */
    private function validate(array $card, bool $withVersion): array
    {
        $data = Validator::make($card, [
            'version' => $withVersion ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'scope' => ['sometimes', 'nullable', Rule::enum(StaffScope::class)],
            'groups' => ['sometimes', 'array', 'max:200'],
            'groups.*' => ['array:group_id,activity_id'],
            'groups.*.group_id' => ['sometimes', 'uuid'],
            'groups.*.activity_id' => ['sometimes', 'uuid'],
            'grants' => ['sometimes', 'array', 'max:30'],
            'grants.*' => ['array:ability,expires_on'],
            'grants.*.ability' => ['required', 'string', 'max:32', 'distinct'],
            'grants.*.expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'template' => ['sometimes', 'nullable', Rule::in(['school_partner'])],
            'confirm_finance' => ['sometimes', 'boolean'],
        ], [
            'scope.enum' => 'Изберете за кои групи важат правата.',
            'grants.*.ability.distinct' => 'Всяко право се добавя веднъж.',
            'grants.*.expires_on.date_format' => 'Изберете дата.',
        ])->validate();

        foreach ($data['groups'] ?? [] as $index => $target) {
            if (! is_array($target) || isset($target['group_id']) === isset($target['activity_id'])) {
                throw ValidationException::withMessages(["groups.{$index}" => 'Групата не е намерена.']);
            }
        }
        if (($data['scope'] ?? null) === StaffScope::Groups->value && ($data['groups'] ?? []) === []) {
            throw ValidationException::withMessages(['groups' => 'Изберете поне една група.']);
        }
        if (array_key_exists('confirm_finance', $data)) {
            $data['confirm_finance'] = filter_var($data['confirm_finance'], FILTER_VALIDATE_BOOLEAN);
        }

        return $data;
    }
}
