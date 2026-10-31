<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterUser
{
    /**
     * @param  bool  $emailVerified  the address is already proven, e.g. it came from an invitation sent to it
     *                               (docs/product/team.md, т. 7.4): no confirmation e-mail is needed
     */
    public function execute(
        string $name,
        string $email,
        string $password,
        string $locale = 'bg',
        string $timezone = 'Europe/Sofia',
        bool $emailVerified = false,
    ): User {
        $user = DB::transaction(function () use ($name, $email, $password, $locale, $timezone, $emailVerified): User {
            $user = User::query()->create([
                'name' => trim($name),
                'email' => Str::lower(trim($email)),
                'password' => $password,
                'locale' => $locale,
                'timezone' => $timezone,
                'is_active' => true,
            ]);
            if ($emailVerified) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return $user;
        });

        event(new Registered($user));

        return $user;
    }
}
