<?php

namespace App\Domain\Organizations\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InviteMember
{
    /** @return array{invitation: Invitation, token: string} */
    public function invite(User $actor, Organization $organization, string $email, OrganizationRole $role): array
    {
        Gate::forUser($actor)->authorize('invite', $organization);
        $this->authorizeRoleAssignment($actor, $organization, $role);

        $normalizedEmail = Str::lower(trim($email));
        $activeKey = hash('sha256', $organization->id.'|'.$normalizedEmail);

        return DB::transaction(function () use ($actor, $organization, $normalizedEmail, $activeKey, $role): array {
            $existing = Invitation::query()
                ->withoutGlobalScopes()
                ->where('active_key', $activeKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->expires_at->isFuture()) {
                throw new ConflictHttpException('Вече има активна покана за този имейл.');
            }

            if ($existing !== null) {
                $existing->update(['active_key' => null]);
            }

            $token = Str::random(64);
            $invitation = Invitation::query()->withoutGlobalScopes()->create([
                'organization_id' => $organization->id,
                'email' => $normalizedEmail,
                'role' => $role,
                'token_hash' => hash('sha256', $token),
                'active_key' => $activeKey,
                'expires_at' => now()->addMinutes((int) config('invitations.expires_after_minutes')),
                'invited_by' => $actor->id,
            ]);

            return ['invitation' => $invitation, 'token' => $token];
        });
    }

    public function accept(User $user, string $selectedOrganizationId, string $token): OrganizationMembership
    {
        return DB::transaction(function () use ($user, $selectedOrganizationId, $token): OrganizationMembership {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_user_id', ?, true)", [$user->id]);
                DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$selectedOrganizationId]);
            }

            $invitation = Invitation::query()
                ->withoutGlobalScopes()
                ->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            if ($invitation === null || $invitation->organization_id !== $selectedOrganizationId) {
                throw new NotFoundHttpException('Поканата не е намерена.');
            }

            if (! hash_equals($invitation->email, Str::lower(trim($user->email)))) {
                throw new AuthorizationException('Поканата е за друг потребител.');
            }

            if ($invitation->accepted_at !== null) {
                if ($invitation->accepted_by !== $user->id) {
                    throw new ConflictHttpException('Поканата вече е използвана.');
                }

                return OrganizationMembership::query()
                    ->withoutGlobalScopes()
                    ->where('organization_id', $invitation->organization_id)
                    ->where('user_id', $user->id)
                    ->firstOrFail();
            }

            if ($invitation->expires_at->isPast()) {
                $invitation->update(['active_key' => null]);

                throw new GoneHttpException('Поканата е изтекла.');
            }

            $membership = OrganizationMembership::query()->withoutGlobalScopes()->firstOrCreate(
                [
                    'organization_id' => $invitation->organization_id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $invitation->role->value,
                    'status' => 'active',
                ],
            );

            $invitation->update([
                'accepted_at' => now(),
                'accepted_by' => $user->id,
                'active_key' => null,
            ]);

            return $membership;
        });
    }

    private function authorizeRoleAssignment(User $actor, Organization $organization, OrganizationRole $role): void
    {
        if ($role === OrganizationRole::PlatformAdmin || $role === OrganizationRole::OrganizationOwner) {
            throw new AuthorizationException('Тази роля не може да се задава чрез покана.');
        }

        $actorRole = OrganizationMembership::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->value('role');

        if ($actorRole === OrganizationRole::OrganizationAdmin->value
            && ! in_array($role, [OrganizationRole::Coordinator, OrganizationRole::Instructor, OrganizationRole::Finance, OrganizationRole::Viewer], true)) {
            throw new AuthorizationException('Нямате право да зададете тази роля.');
        }
    }
}
