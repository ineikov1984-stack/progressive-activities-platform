<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Catalog\Actions\LinkInstructorProfile;
use App\Domain\Catalog\Services\StaffTeaching;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Actions\ArchiveStaffMember;
use App\Domain\Organizations\Actions\ChangeStaffRole;
use App\Domain\Organizations\Actions\InviteStaffMember;
use App\Domain\Organizations\Actions\SaveStaffMember;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page "Екип" (docs/product/team.md, т. 9, 10): people with or without a login, their roles and groups. Phones and
 * e-mails only for those who manage the team (т. 4, 11).
 */
class TeamController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly StaffTeaching $teaching) {}

    public function index(Request $request): JsonResponse
    {
        $organization = $this->organization();
        Gate::forUser($request->user())->authorize('viewTeam', $organization);
        $members = StaffMember::query()
            ->when($request->boolean('archived'), fn ($query) => $query->whereNotNull('archived_at'), fn ($query) => $query->whereNull('archived_at'))
            ->orderBy('full_name')->orderBy('id')->get();
        $contacts = Gate::forUser($request->user())->allows('manageTeam', $organization);

        return response()->json(['members' => $this->present($members, $contacts), 'can_manage' => $contacts]);
    }

    public function show(Request $request, string $member): JsonResponse
    {
        $organization = $this->organization();
        Gate::forUser($request->user())->authorize('viewTeam', $organization);
        $record = StaffMember::query()->findOrFail($member);
        $contacts = Gate::forUser($request->user())->allows('manageTeam', $organization);
        $body = ['member' => $this->present(collect([$record]), $contacts, withEnded: true)[0]];
        if ($contacts) {
            $body['history'] = $this->history($record);
        }

        return response()->json($body);
    }

    public function store(Request $request, SaveStaffMember $save, LinkInstructorProfile $link): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $teaches = $request->boolean('teaches');
        $result = $save->add($actor, $request->except('teaches'), trim((string) $request->header('Idempotency-Key')),
            function (StaffMember $member) use ($actor, $link, $teaches): void {
                if ($teaches) {
                    $link->handle($actor, $member, null);
                }
            });

        return response()->json(
            ['member' => $this->present(collect([$result['member']]), true)[0]],
            $result['created'] ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }

    public function update(Request $request, SaveStaffMember $save, string $member): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        Gate::forUser($actor)->authorize('manageTeam', $this->organization());
        $record = $save->update($actor, StaffMember::query()->findOrFail($member), $request->all());

        return response()->json(['member' => $this->present(collect([$record]), true)[0]]);
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
        $invitation = $result['invitation'];

        return response()->json([
            'invitation' => [
                'id' => $invitation->id,
                'staff_member_id' => $invitation->staff_member_id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'status' => $invitation->status(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ],
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
     * @return list<array<string, mixed>>
     */
    private function present(Collection $members, bool $contacts, bool $withEnded = false): array
    {
        $ids = $members->pluck('id')->map(fn ($id): string => (string) $id)->values()->all();
        $profiles = $this->teaching->profilesFor($ids);
        $groups = $this->teaching->assignments(array_values($profiles), $withEnded);
        $roles = OrganizationMembership::query()->withoutGlobalScopes()
            ->where('organization_id', $this->context->organizationId())
            ->whereIn('user_id', $members->pluck('user_id')->filter()->values()->all())
            ->get(['user_id', 'role', 'status'])->keyBy('user_id');
        // The latest invitation not yet accepted, for "Поканен · изтича след N дни" and "Изпрати поканата пак" (т. 10):
        // its state and end only, never the address or a token.
        $invitations = Invitation::query()->whereIn('staff_member_id', $ids)->whereNull('accepted_at')
            ->orderByDesc('expires_at')->orderByDesc('created_at')->get(['id', 'staff_member_id', 'expires_at', 'accepted_at', 'created_at'])
            ->unique('staff_member_id')->keyBy('staff_member_id');

        return $members->map(function (StaffMember $member) use ($contacts, $profiles, $groups, $roles, $invitations): array {
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
                'groups' => $profile === null ? [] : ($groups[$profile] ?? []),
                'archived_at' => $member->archived_at?->toIso8601String(),
                'invitation' => $invitation === null ? null : [
                    'status' => $invitation->status(), 'expires_at' => $invitation->expires_at->toIso8601String(),
                ],
                'version' => $member->version,
                ...($contacts ? ['phone' => $member->phone, 'email' => $member->email] : []),
            ];
        })->values()->all();
    }

    /** @return list<array{action: string, at: string, by: string|null}> */
    private function history(StaffMember $member): array
    {
        $events = AuditEvent::query()->where('organization_id', $this->context->organizationId())
            ->where('subject_type', 'staff_member')->where('subject_id', $member->id)
            ->latest()->limit(50)->get(['action', 'actor_id', 'created_at']);
        $names = User::query()->whereIn('id', $events->pluck('actor_id')->filter()->unique()->values()->all())->pluck('name', 'id');

        return $events->map(fn (AuditEvent $event): array => [
            'action' => (string) $event->getAttribute('action'),
            'at' => $event->created_at?->toIso8601String() ?? '',
            'by' => $event->getAttribute('actor_id') === null ? null : ($names[$event->getAttribute('actor_id')] ?? null),
        ])->values()->all();
    }

    private function organization(): Organization
    {
        return Organization::query()->findOrFail($this->context->organizationId());
    }
}
