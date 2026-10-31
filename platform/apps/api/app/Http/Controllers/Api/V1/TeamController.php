<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Actions\LinkInstructorProfile;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\Services\StaffTeaching;
use App\Domain\Catalog\Services\TeamVisibility;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Actions\ArchiveStaffMember;
use App\Domain\Organizations\Actions\ChangeStaffRole;
use App\Domain\Organizations\Actions\InviteStaffMember;
use App\Domain\Organizations\Actions\SaveStaffAccess;
use App\Domain\Organizations\Actions\SaveStaffMember;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Services\TeamAbilities;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\StaffAccessCard;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page "Екип" (docs/product/team.md, т. 9, 10): people with or without a login, their roles and groups. Phones and
 * e-mails only for those who manage the team (т. 4, 11).
 */
class TeamController extends Controller
{
    /** The rights given, with a new end, or taken: in "История" with the rights they name. */
    private const GRANT_ACTIONS = ['team.access_grant_added', 'team.access_grant_changed', 'team.access_grant_revoked'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffTeaching $teaching,
        private readonly AccessScope $scope,
        private readonly TeamVisibility $visibility,
    ) {}

    /** With a narrower scope only the people of its groups and the user (docs/product/team-rights.md, т. 9). */
    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization();
        $user = $this->teamViewer($request);
        $people = $this->visibility->people($user);
        $members = StaffMember::query()
            ->when($people !== null, fn ($query) => $query->whereKey($people ?? []))
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('full_name')->orderBy('id')->get();
        $contacts = Gate::forUser($request->user())->allows('manageTeam', $organization);

        return response()->json(['members' => $this->present($members, $contacts, visibleGroups: $this->visibility->groups($user)), 'can_manage' => $contacts]);
    }

    public function show(Request $request, string $member): JsonResponse
    {
        $organization = $this->organization();
        $user = $this->teamViewer($request);
        $record = StaffMember::query()->findOrFail($member);
        abort_unless($this->visibility->sees($user, $record), 403, 'Нямате достъп до този човек.');
        $contacts = Gate::forUser($request->user())->allows('manageTeam', $organization);
        $body = ['member' => $this->present(collect([$record]), $contacts, withEnded: true, visibleGroups: $this->visibility->groups($user))[0]];
        if ($contacts) {
            $body['history'] = $this->history($record);
        }

        return response()->json($body);
    }

    /**
     * Adds a person; with `invitation` ({role, email}) invites them in the same transaction, and with `access` gives
     * their rights then too — only together with the invitation (docs/product/team-rights.md, т. 4.1, 9).
     */
    public function store(Request $request, SaveStaffMember $save, LinkInstructorProfile $link, InviteStaffMember $invite, SaveStaffAccess $access): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $teaches = $request->boolean('teaches');
        $invitation = $request->input('invitation');
        $card = $request->input('access');
        $sent = null;
        $result = $save->add($actor, $request->except(['teaches', 'invitation', 'access']), trim((string) $request->header('Idempotency-Key')),
            function (StaffMember $member) use ($actor, $link, $teaches, $invitation, $card, $invite, $access, &$sent): void {
                // In the transaction of the addition: a refusal adds nobody.
                if ($card !== null && ! is_array($invitation)) {
                    throw ValidationException::withMessages(['access' => 'Първо изпратете покана — правата важат за човек с достъп.']);
                }
                if ($teaches) {
                    $link->handle($actor, $member, null);
                }
                if (is_array($invitation)) {
                    $sent = $invite->handle($actor, $member, $invitation);
                }
                if (is_array($card)) {
                    $this->validateGroups($card);
                    $access->handle($actor, $member, $card, withVersion: false);
                }
            });
        $member = $result['member']->refresh();

        return response()->json(
            ['member' => $this->present(collect([$member]), true)[0]] + ($sent === null ? [] : [
                'invitation' => $this->presentInvitation($sent['invitation']), 'link' => $sent['link'],
            ]),
            $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    /**
     * The card "Права" of a person (docs/product/team-rights.md, т. 9, 10): their role (also from the invitation),
     * scope, selected groups, rights by area with where each comes from, the template and the version; whether the user
     * may change it and why not. For those who manage the team also what they may add and the history of the rights;
     * with `?role=` what a new role would take away.
     */
    public function access(Request $request, string $member, StaffAccessCard $card): JsonResponse
    {
        $user = $this->teamViewer($request);
        $validated = $request->validate(['role' => ['sometimes', Rule::enum(OrganizationRole::class)]], ['role.enum' => 'Изберете роля от списъка.']);
        $record = StaffMember::query()->findOrFail($member);
        abort_unless($this->visibility->sees($user, $record), 403, 'Нямате достъп до този човек.');

        return response()->json(['access' => $card->present($user, $record, isset($validated['role']) ? OrganizationRole::from((string) $validated['role']) : null)]);
    }

    /** The whole card at once under the person's version (`SaveStaffAccess`, т. 5); 200 with the new state. */
    public function saveAccess(Request $request, string $member, SaveStaffAccess $access, StaffAccessCard $card): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('manageTeam', $this->organization());
        $record = StaffMember::query()->findOrFail($member);
        $this->validateGroups($request->all());
        $saved = $access->handle($actor, $record, $request->all());

        return response()->json(['access' => $card->present($actor, $saved, null)]);
    }

    public function update(Request $request, SaveStaffMember $save, string $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('manageTeam', $this->organization());
        $record = $save->update($actor, StaffMember::query()->findOrFail($member), $request->all());

        return response()->json(['member' => $this->present(collect([$record]), true)[0]]);
    }

    /**
     * The selected groups and activities are of this organization — another's are "not found", without saying why.
     *
     * @param  array<string, mixed>  $card
     */
    private function validateGroups(array $card): void
    {
        $tenant = $this->context->organizationId();
        Validator::make($card, [
            'groups' => ['sometimes', 'array'],
            'groups.*.group_id' => ['sometimes', 'uuid', Rule::exists('activity_groups', 'id')->where('organization_id', $tenant)],
            'groups.*.activity_id' => ['sometimes', 'uuid', Rule::exists('activities', 'id')->where('organization_id', $tenant)],
        ], [
            'groups.*.group_id.uuid' => 'Групата не е намерена.',
            'groups.*.group_id.exists' => 'Групата не е намерена.',
            'groups.*.activity_id.uuid' => 'Групата не е намерена.',
            'groups.*.activity_id.exists' => 'Групата не е намерена.',
        ])->validate();
    }

    /** A team page needs "team.view" somewhere — otherwise 403 before anything is read, as the Gate refuses. */
    private function teamViewer(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();
        if (! $this->scope->access($user)->has('team.view')) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /** @return array<string, mixed> its state and end, never the address's token */
    private function presentInvitation(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'staff_member_id' => $invitation->staff_member_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'status' => $invitation->status(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }

    public function archive(Request $request, ArchiveStaffMember $archive, string $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('manageTeam', $this->organization());
        $validated = $request->validate(['version' => ['required', 'integer', 'min:1']]);
        $record = $archive->handle($actor, StaffMember::query()->findOrFail($member), (int) $validated['version']);

        return response()->json(['member' => $this->present(collect([$record]), true)[0]]);
    }

    public function linkProfile(Request $request, LinkInstructorProfile $link, string $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('manageTeam', $this->organization());
        $validated = $request->validate(['instructor_profile_id' => ['sometimes', 'nullable', 'uuid']]);
        $record = StaffMember::query()->findOrFail($member);
        $link->handle($actor, $record, $validated['instructor_profile_id'] ?? null);

        return response()->json(['member' => $this->present(collect([$record->refresh()]), true)[0]]);
    }

    /**
     * Invites the person to log in, or sends the invitation again with new links (т. 7). The link for copying is shown
     * only here and only joins an existing profile; the e-mail carries another token, the one that may make a profile.
     */
    public function invite(Request $request, InviteStaffMember $invite, string $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('manageTeam', $this->organization());
        $result = $invite->handle($actor, StaffMember::query()->findOrFail($member), $request->all());

        return response()->json([
            'invitation' => $this->presentInvitation($result['invitation']),
            'link' => $result['link'],
        ], $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /** The role of a person who logs in (т. 4). */
    public function changeRole(Request $request, ChangeStaffRole $change, string $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('manageTeam', $this->organization());
        $record = $change->handle($actor, StaffMember::query()->findOrFail($member), $request->all());

        return response()->json(['member' => $this->present(collect([$record]), true)[0]]);
    }

    /**
     * @param  Collection<int, StaffMember>  $members
     * @param  list<string>|null  $visibleGroups  only these groups of each person (a narrower scope); null — all
     * @return list<array<string, mixed>>
     */
    private function present(Collection $members, bool $contacts, bool $withEnded = false, ?array $visibleGroups = null): array
    {
        $ids = $members->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
        $profiles = $this->teaching->profilesFor($ids);
        $roles = OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $this->context->organizationId())
            ->whereIn('user_id', $members->pluck('user_id')->filter()->values()->all())
            ->get(['user_id', 'role', 'status'])->keyBy('user_id');
        // The latest invitation not yet accepted, for "Поканен · изтича след N дни" and "Изпрати поканата пак" (т. 10):
        // its state and end only, never the address or a token.
        $invitations = Invitation::query()->whereIn('staff_member_id', $ids)->whereNull('accepted_at')
            ->orderByDesc('expires_at')->orderByDesc('created_at')->get(['id', 'staff_member_id', 'role', 'expires_at', 'accepted_at', 'created_at'])
            ->unique('staff_member_id')->keyBy('staff_member_id');

        $groups = $this->teaching->assignments(array_values($profiles), $withEnded);

        return $members->map(function (StaffMember $member) use ($contacts, $profiles, $groups, $visibleGroups, $roles, $invitations): array {
            $membership = $member->user_id === null ? null : $roles->get($member->user_id);
            $profile = $profiles[$member->id] ?? null;
            $invitation = $invitations->get($member->id);
            $state = match (true) {
                $member->archived_at !== null => 'archived',
                $membership !== null && $membership->getAttribute('status') === 'active' => 'login',
                default => 'profile_only',
            };

            return [
                'id' => $member->id,
                'full_name' => $member->full_name,
                'state' => $state,
                'role' => $membership?->getAttribute('role'),
                'instructor_profile_id' => $profile,
                'groups' => $profile === null ? [] : array_values(array_filter($groups[$profile] ?? [],
                    fn (array $group): bool => $visibleGroups === null || in_array($group['group_id'], $visibleGroups, true))),
                'archived_at' => $member->archived_at?->toIso8601String(),
                // Only someone who does not log in yet waits for an invitation; an older one left unaccepted does not count.
                'invitation' => $invitation === null || $state !== 'profile_only' ? null : [
                    'status' => $invitation->status(), 'expires_at' => $invitation->expires_at->toIso8601String(),
                    // The role the invitation gives, so the list says it instead of "no role".
                    'role' => $invitation->role->value,
                ],
                'version' => $member->version,
                ...($contacts ? ['phone' => $member->phone, 'email' => $member->email] : []),
            ];
        })->values()->all();
    }

    /**
     * "История" of the profile: what, who and when; for the rights also which were given or taken — the rights of one
     * save in one entry — and the role before and after (the owner's review of 31.10.2026). Keys only, never a phone,
     * an e-mail or a name of a child.
     *
     * @return list<array<string, mixed>>
     */
    private function history(StaffMember $member): array
    {
        $events = AuditEvent::query()->where('organization_id', $this->context->organizationId())
            ->where('subject_type', 'staff_member')->where('subject_id', $member->id)
            ->latest()->limit(50)->get(['action', 'actor_id', 'correlation_id', 'metadata', 'created_at']);
        $names = User::query()->whereIn('id', $events->pluck('actor_id')->filter()->unique()->values()->all())->pluck('name', 'id');

        $entries = [];
        /** @var array<string, int> $together the entry of the rights given or taken in one request by one person */
        $together = [];
        /** @var array<int, list<string>> $abilities */
        $abilities = [];
        foreach ($events as $event) {
            $action = (string) $event->getAttribute('action');
            $actor = $event->getAttribute('actor_id');
            $metadata = $event->metadata;
            $details = match ($action) {
                AuditAction::TeamRoleChanged->value, AuditAction::TeamAccessScopeChanged->value, AuditAction::TeamAccessTemplateChanged->value => ['from', 'to'],
                AuditAction::TeamAccessGrantRevoked->value => ['reason'],
                default => [],
            };
            $entry = [
                'action' => $action,
                'at' => $event->created_at?->toIso8601String() ?? '',
                'by' => $actor === null ? null : ($names[$actor] ?? null),
                ...array_intersect_key($metadata, array_flip($details)),
            ];
            $ability = $metadata['ability'] ?? null;
            if (! in_array($action, self::GRANT_ACTIONS, true) || ! is_string($ability)) {
                $entries[] = $entry;

                continue;
            }
            $key = implode('|', [$action, (string) $event->getAttribute('correlation_id'), (string) $actor, (string) ($metadata['reason'] ?? '')]);
            if (! isset($together[$key])) {
                $together[$key] = count($entries);
                $entries[] = $entry;
            }
            $abilities[$together[$key]][] = $ability;
        }
        $result = [];
        foreach ($entries as $index => $entry) {
            $result[] = isset($abilities[$index]) ? [...$entry, 'abilities' => self::inCardOrder($abilities[$index])] : $entry;
        }

        return $result;
    }

    /**
     * @param  list<string>  $abilities
     * @return list<string>
     */
    private static function inCardOrder(array $abilities): array
    {
        $order = array_flip(TeamAbilities::GRANTABLE);
        $abilities = array_values(array_unique($abilities));
        usort($abilities, fn (string $a, string $b): int => ($order[$a] ?? PHP_INT_MAX) <=> ($order[$b] ?? PHP_INT_MAX));

        return $abilities;
    }

    private function organization(): Organization
    {
        return Organization::query()->findOrFail($this->context->organizationId());
    }
}
