<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Services\TeamRoles;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Mail\TeamInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Invites a person of the team to log in, or sends the invitation again (docs/product/team.md, т. 7.1, 7.2, 7.5).
 * Sending again gives a new link: the old token stops working. The e-mail goes out after the commit; the audit keeps
 * identifiers and the role, never the address or the token.
 */
final readonly class InviteStaffMember
{
    public function __construct(private TenantContext $context, private TeamRoles $roles, private AuditRecorder $audit) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{invitation: Invitation, link: string, created: bool}
     */
    public function handle(User $actor, StaffMember $member, array $payload): array
    {
        abort_unless($member->getAttribute('organization_id') === $this->context->organizationId(), 404);
        $organization = Organization::query()->findOrFail($this->context->organizationId());
        Gate::forUser($actor)->authorize('manageTeam', $organization);
        $data = Validator::make($payload, [
            'role' => ['required', Rule::enum(OrganizationRole::class)],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
        ], [
            'role.required' => 'Изберете роля.',
            'role.enum' => 'Изберете роля от списъка.',
            'email.email' => 'Въведете имейл, например ime@primer.bg.',
            'email.max' => 'Имейлът е твърде дълъг.',
        ])->validate();
        $role = OrganizationRole::from((string) $data['role']);
        $this->roles->assertMayInvite($actor, $organization, $role);
        $given = is_string($data['email'] ?? null) && trim($data['email']) !== '' ? Str::lower(trim($data['email'])) : null;

        return DB::transaction(function () use ($actor, $member, $organization, $role, $given): array {
            // The organization first, then the person, then the invitations (the order of the other team actions).
            Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $member = StaffMember::query()->whereKey($member->id)->lockForUpdate()->firstOrFail();
            if ($member->archived_at !== null) {
                throw ValidationException::withMessages(['member' => 'Човекът е архивиран.']);
            }
            if ($member->user_id !== null) {
                throw ValidationException::withMessages(['member' => 'Този човек вече влиза в системата.']);
            }
            $email = $given ?? $member->email;
            if ($email === null) {
                throw ValidationException::withMessages(['email' => 'Въведете имейл, на който да изпратим поканата.']);
            }
            $inTeam = OrganizationMembership::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
                ->whereIn('user_id', User::query()->where('email', $email)->select('id'))->exists();
            if ($inTeam) {
                throw ValidationException::withMessages(['email' => 'Човек с този имейл вече е в екипа.']);
            }
            if ($member->email === null) {
                $member->email = $email;
                $member->version++;
                $member->save();
                $this->audit->record(AuditAction::TeamMemberUpdated, new AuditableReference('staff_member', $member->id), ['fields' => ['email']]);
            }

            $invitation = $this->replaceable($member, $organization, $email);
            $created = $invitation === null;
            $token = Str::random(64);
            $values = [
                'email' => $email,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'active_key' => $this->activeKey($organization, $email),
                'expires_at' => now()->addMinutes((int) config('invitations.expires_after_minutes')),
                'invited_by' => $actor->id,
                'staff_member_id' => $member->id,
            ];
            if ($invitation === null) {
                $invitation = Invitation::query()->create([...$values, 'organization_id' => $organization->id]);
            } else {
                // A new token in the same row: the old link is no longer found.
                $invitation->forceFill($values)->save();
            }

            $this->audit->record(AuditAction::TeamInvitationSent, new AuditableReference('staff_member', $member->id), [
                'invitation_id' => $invitation->id, 'role' => $role->value, 'resent' => ! $created,
            ]);
            $link = rtrim((string) config('platform.web_url'), '/').'/invite/'.$token;
            Mail::to($email)->queue(new TeamInvitationMail(
                $organization->name, $actor->name, $role->label(), $link, $invitation->expires_at,
            ));

            return ['invitation' => $invitation, 'link' => $link, 'created' => $created];
        });
    }

    /**
     * The person's active invitation, or one for the same e-mail without a person (the old form), is sent again. An
     * active invitation for the same e-mail to another person refuses; an expired one gives up the e-mail's key.
     */
    private function replaceable(StaffMember $member, Organization $organization, string $email): ?Invitation
    {
        $byPerson = Invitation::query()->where('staff_member_id', $member->id)->whereNull('accepted_at')
            ->where('expires_at', '>', now())->orderByDesc('created_at')->lockForUpdate()->first();
        $byEmail = Invitation::query()->where('active_key', $this->activeKey($organization, $email))->lockForUpdate()->first();
        $emailActive = $byEmail !== null && $byEmail->status() === 'pending';
        if ($emailActive && $byEmail->staff_member_id !== null && $byEmail->staff_member_id !== $member->id) {
            throw new ConflictHttpException('Вече има активна покана за този имейл.');
        }
        $invitation = $byPerson ?? ($emailActive ? $byEmail : null);
        if ($byEmail !== null && $byEmail->id !== $invitation?->id) {
            // Replaced by the person's invitation: an active one stops working now.
            $byEmail->forceFill(['active_key' => null, ...($emailActive ? ['expires_at' => now()] : [])])->save();
        }

        return $invitation;
    }

    private function activeKey(Organization $organization, string $email): string
    {
        return hash('sha256', $organization->id.'|'.$email);
    }
}
