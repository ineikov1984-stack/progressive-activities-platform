<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Actions\RegisterUser;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Actions\AcceptTeamInvitation;
use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Services\InvitationTokens;
use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * The page "/invite#{token}" (docs/product/team.md, т. 7.4): what the invitation is, then a login or a new profile,
 * then the organization of the invitation becomes the active one. The token comes in the request body, never in the
 * path (it would reach the access logs). The invitation's e-mail is shown only for the e-mail's link, whose holder
 * owns the address; never for the inviter's link for copying.
 */
class InvitationAcceptanceController extends Controller
{
    /**
     * Public: the organization, the role, until when, and which link was opened — `email` (it may make a new profile)
     * or `copy` (it only joins an existing one), so the page offers the right form.
     */
    public function lookup(Request $request, InvitationTokens $tokens): JsonResponse
    {
        $body = $tokens->within($this->token($request), null, function (Invitation $invitation, string $hash): array {
            $fromMail = InvitationTokens::kind($invitation, $hash) === InvitationTokens::MAIL;

            return [
                'organization_name' => (string) Organization::query()->whereKey($invitation->organization_id)->value('name'),
                'role' => $invitation->role->value,
                'role_label' => $invitation->role->label(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'status' => $invitation->status(),
                'link' => $fromMail ? 'email' : 'copy',
                // The holder of the e-mail's link owns the address; the inviter's link could be passed to anyone.
                ...($fromMail ? ['email' => $invitation->email] : []),
            ];
        });

        return response()->json(['invitation' => $body]);
    }

    /** Signed in with the invitation's address: either link joins. */
    public function join(Request $request, AcceptTeamInvitation $accept): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $result = $accept->handle($user, $this->token($request));
        $request->session()->put('active_organization_id', $result['organization']->id);

        return response()->json($this->accepted($result['organization'], $result['membership']));
    }

    /**
     * A new profile for the invitation's address, logged in, then the invitation accepted — only from the e-mail's
     * link, which proves the address (DECISIONS 31.10.2026); the inviter's link for copying does not.
     */
    public function register(Request $request, InvitationTokens $tokens, RegisterUser $register, AcceptTeamInvitation $accept): JsonResponse
    {
        if (Auth::guard('web')->check()) {
            return response()->json(['message' => 'Вече сте влезли. Приемете поканата с профила си или излезте.'], Response::HTTP_CONFLICT);
        }
        $token = $this->token($request);
        // The address is the invitation's, never the request's.
        $found = $tokens->within($token, null, fn (Invitation $invitation, string $hash): array => [
            'email' => $invitation->email, 'kind' => InvitationTokens::kind($invitation, $hash),
        ]);
        if ($found['kind'] !== InvitationTokens::MAIL) {
            return response()->json(['message' => 'За нов профил отворете връзката от писмото.'], Response::HTTP_FORBIDDEN);
        }
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:1024', 'confirmed', Password::min(10)],
        ], [
            'name.required' => 'Напишете името си.',
            'name.max' => 'Името е твърде дълго.',
            'password.required' => 'Изберете парола.',
            'password.max' => 'Паролата е твърде дълга.',
            'password.min' => 'Паролата трябва да е поне 10 знака.',
            'password.confirmed' => 'Паролите не съвпадат.',
        ]);
        $exists = fn (): JsonResponse => response()->json(['message' => 'Вече имате профил. Влезте, за да приемете поканата.'], Response::HTTP_CONFLICT);
        if (User::query()->where('email', $found['email'])->exists()) {
            return $exists();
        }

        try {
            $result = DB::transaction(function () use ($register, $accept, $validated, $found, $token): array {
                $user = $register->execute((string) $validated['name'], $found['email'], (string) $validated['password'], emailVerified: true);
                // The acceptance's audit has its actor; the session changes only after the commit.
                Auth::guard('web')->setUser($user);
                $accepted = $accept->handle($user, $token, fromMailOnly: true);

                return ['user' => $user, 'organization' => $accepted['organization'], 'membership' => $accepted['membership']];
            });
        } catch (UniqueConstraintViolationException) {
            // The same address registered a moment ago by another request.
            Auth::forgetGuards();

            return $exists();
        } catch (Throwable $exception) {
            Auth::forgetGuards();

            throw $exception;
        }

        Auth::guard('web')->login($result['user']);
        $request->session()->regenerate();
        $request->session()->put('active_organization_id', $result['organization']->id);

        return response()->json($this->accepted($result['organization'], $result['membership']), Response::HTTP_CREATED);
    }

    private function token(Request $request): string
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:128']], [
            'token.required' => 'Липсва връзката от поканата.',
            'token.string' => 'Липсва връзката от поканата.',
            'token.max' => 'Връзката от поканата не е вярна.',
        ]);

        return (string) $validated['token'];
    }

    /** @return array{organization: array{id: string, name: string, slug: string, status: string}, membership: array{id: string, role: string, status: string}} */
    private function accepted(Organization $organization, OrganizationMembership $membership): array
    {
        return [
            'organization' => [
                'id' => $organization->id, 'name' => $organization->name, 'slug' => $organization->slug, 'status' => $organization->status->value,
            ],
            'membership' => [
                'id' => (string) $membership->id, 'role' => (string) $membership->getAttribute('role'), 'status' => (string) $membership->getAttribute('status'),
            ],
        ];
    }
}
