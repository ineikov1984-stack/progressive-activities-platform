<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PhoneNumber;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
    public function updateContact(Request $request, AuditRecorder $auditRecorder): JsonResponse
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
        $user->fill($changes);
        $changed = array_keys($user->getDirty());
        $user->save();
        if ($changed !== []) {
            // Which fields changed, never their values.
            $auditRecorder->record(AuditAction::IdentityContactUpdated, new AuditableReference('user', $user->id), ['fields' => $changed]);
        }

        return response()->json(['user' => $this->presentUser($user)]);
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
