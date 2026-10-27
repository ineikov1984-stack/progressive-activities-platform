<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
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
                'credentials' => ['Невалиден имейл или парола.'],
            ])->status(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $request->session()->regenerate();

        /** @var User $user */
        $user = $request->user();

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
     * @return array{id: string, name: string, email: string, locale: string, timezone: string, email_verified: bool, mfa_enabled: bool}
     */
    private function presentUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'timezone' => $user->timezone,
            'email_verified' => $user->hasVerifiedEmail(),
            'mfa_enabled' => $user->mfa_enabled_at !== null,
        ];
    }
}
