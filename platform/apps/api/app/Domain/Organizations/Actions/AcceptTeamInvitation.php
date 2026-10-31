<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Models\StaffMember;
use App\Domain\Organizations\Services\InvitationTokens;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Accepts an invitation by its link (docs/product/team.md, т. 7.4). The organization is the invitation's, never the
 * session's. In one transaction: the membership with the invited role, the person of the team linked to the login,
 * the invitation used. The same person accepting again gets the same membership. A new profile is made only from the
 * e-mail's token (DECISIONS 31.10.2026).
 */
final readonly class AcceptTeamInvitation
{
    public function __construct(private InvitationTokens $tokens, private AuditRecorder $audit) {}

    /**
     * @param  bool  $fromMailOnly  the profile was just made for the invitation's address: only the e-mail's token proves it
     * @return array{organization: Organization, membership: OrganizationMembership}
     */
    public function handle(User $user, string $token, bool $fromMailOnly = false): array
    {
        return $this->tokens->within($token, $user->id, function (Invitation $found, string $hash) use ($user, $fromMailOnly): array {
            // The organization first, then the invitation, the person and the membership (as when inviting).
            $organization = Organization::query()->whereKey($found->organization_id)->lockForUpdate()->firstOrFail();
            $invitation = Invitation::query()->whereKey($found->id)->lockForUpdate()->firstOrFail();
            // Sent again meanwhile: the old token no longer matches the locked row.
            $kind = InvitationTokens::kind($invitation, $hash) ?? throw new NotFoundHttpException('Поканата не е намерена.');
            if ($fromMailOnly && $kind !== InvitationTokens::MAIL) {
                throw new AuthorizationException('За нов профил отворете връзката от писмото.');
            }
            $email = Str::lower(trim($user->email));
            if (! hash_equals($invitation->email, $email)) {
                throw new AuthorizationException('Поканата е изпратена на друг имейл. Влезте с профила, на който я получихте.');
            }
            if ($invitation->accepted_at !== null) {
                if ($invitation->accepted_by !== $user->id) {
                    throw new ConflictHttpException('Поканата вече е използвана.');
                }

                return ['organization' => $organization, 'membership' => OrganizationMembership::query()->withoutGlobalScopes()
                    ->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail()];
            }
            if ($invitation->expires_at->isPast()) {
                throw new GoneHttpException('Поканата е изтекла. Поискайте нова покана от организацията.');
            }

            $person = $invitation->staff_member_id === null ? null
                : StaffMember::query()->whereKey($invitation->staff_member_id)->lockForUpdate()->first();
            $own = StaffMember::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($person !== null) {
                if ($person->archived_at !== null) {
                    throw ValidationException::withMessages(['invitation' => 'Поканата вече не важи. Поискайте нова покана от организацията.']);
                }
                if ($person->user_id !== null && $person->user_id !== $user->id) {
                    throw new ConflictHttpException('Поканата е свързана с друг профил.');
                }
                if ($own !== null && $own->id !== $person->id) {
                    throw new ConflictHttpException('Вече сте в екипа на тази организация с друг запис. Свържете се с организацията.');
                }
            } else {
                // The old form: the person of the login, linked or new (as InviteMember::accept).
                $person = $own;
            }
            $existing = OrganizationMembership::query()->withoutGlobalScopes()->where('organization_id', $organization->id)
                ->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing !== null) {
                throw new ConflictHttpException('Вече сте в екипа на тази организация.');
            }

            $membership = OrganizationMembership::query()->withoutGlobalScopes()->create([
                'organization_id' => $organization->id, 'user_id' => $user->id, 'role' => $invitation->role->value, 'status' => 'active',
            ]);
            if ($person === null) {
                $person = new StaffMember;
                $person->forceFill([
                    'organization_id' => $organization->id, 'user_id' => $user->id, 'full_name' => $user->name,
                    'email' => $email, 'phone' => $user->getAttribute('phone'), 'version' => 1,
                ])->save();
            } elseif ($person->user_id === null) {
                $person->forceFill(['user_id' => $user->id, 'email' => $person->email ?? $email, 'version' => $person->version + 1])->save();
            }
            $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id, 'active_key' => null])->save();

            $this->audit->record(AuditAction::TeamInvitationAccepted, new AuditableReference('staff_member', $person->id), [
                'invitation_id' => $invitation->id, 'membership_id' => $membership->id, 'role' => $invitation->role->value,
            ]);

            return ['organization' => $organization, 'membership' => $membership];
        });
    }
}
