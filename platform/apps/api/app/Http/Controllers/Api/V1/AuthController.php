<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PhoneNumber;
use App\Domain\Organizations\Actions\UpdateOwnStaffContact;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(Request $request, AuditRecorder $auditRecorder): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        $authenticated = Auth::guard('web')->attempt([
            'email' => Str::lower($credentials['email']),
            'password' => $credentials['password'],
            'is_active' => true,
        ]);

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'credentials' => ['Грешен имейл или парола.'],
            ])->status(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();
        $auditRecorder->record(
            AuditAction::IdentityLoginSucceeded,
            new AuditableReference('user', $user->id),
        );

        return response()->json(['user' => $this->presentUser($user)]);
    }

    public function logout(Request $request): Response
    {
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['user' => $this->presentUser($user)]);
    }

    /**
     * The signed-in person's own name and phone (docs/product/enrollment-contacts-and-emails.md, т. 5).
     * The phone is kept in E.164; the audit records only that it changed.
     */
    /**
     * The user's own name and phone (docs/product/enrollment-contacts-and-emails.md, т. 5): a parent's, and in "Моят
     * профил" a person of a team's — then also their record of the team in the active organization (the
     * `X-Organization-Id` header, otherwise the one selected in the session; `UpdateOwnStaffContact`). A header naming an
     * organization the user is not an active member of is 404 and nothing changes.
     */
    public function updateContact(Request $request, AuditRecorder $auditRecorder, UpdateOwnStaffContact $team): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ], ['name.required' => 'Напишете името си.', 'name.max' => 'Името е твърде дълго.', 'phone.max' => 'Проверете телефона — например 0888 123 456 или +359 888 123 456.']);

        $changes = [];
        if (array_key_exists('name', $validated)) {
            $changes['name'] = trim((string) $validated['name']);
        }
        if (array_key_exists('phone', $validated)) {
            $raw = trim((string) $validated['phone']);
            $phone = $raw === '' ? null : PhoneNumber::normalize($raw);
            if ($raw !== '' && $phone === null) {
                throw ValidationException::withMessages(['phone' => 'Проверете телефона — например 0888 123 456 или +359 888 123 456.']);
            }
            $changes['phone'] = $phone;
        }
        $header = $request->header('X-Organization-Id');
        $organizationId = is_string($header) && $header !== '' ? $header : $request->session()->get('active_organization_id');

        return DB::transaction(function () use ($user, $changes, $header, $organizationId, $auditRecorder, $team): JsonResponse {
            $member = is_string($organizationId) && $organizationId !== '' && $team->isMember($user, $organizationId);
            // Another organization's is never reached: as for every tenant, it is not found (AGENTS.md).
            abort_if(! $member && is_string($header) && $header !== '', 404, 'Организацията не е намерена.');

            $user->fill($changes);
            $changed = array_keys($user->getDirty());
            $user->save();
            if ($changed !== []) {
                // Which fields changed, never their values.
                $auditRecorder->record(AuditAction::IdentityContactUpdated, new AuditableReference('user', $user->id), ['fields' => $changed]);
            }
            if ($member) {
                $team->handle($user, $organizationId, $changes);
            }

            return response()->json(['user' => $this->presentUser($user)]);
        });
    }

    /**
     * @return array{id: string, name: string, email: string, phone: string|null, locale: string, timezone: string, email_verified: bool, mfa_enabled: bool}
     */
    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'locale' => $user->locale,
            'timezone' => $user->timezone,
            'email_verified' => $user->hasVerifiedEmail(),
            'mfa_enabled' => $user->mfa_enabled_at !== null,
        ];
    }
}
