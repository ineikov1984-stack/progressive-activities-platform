<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterUser
{
    public function execute(
        string $name,
        string $email,
        string $password,
        string $locale = 'bg',
        string $timezone = 'Europe/Sofia',
    ): User {
        $user = DB::transaction(fn (): User => User::query()->create([
            'name' => trim($name),
            'email' => Str::lower(trim($email)),
            'password' => $password,
            'locale' => $locale,
            'timezone' => $timezone,
            'is_active' => true,
        ]));

        event(new Registered($user));

        return $user;
    }
}
