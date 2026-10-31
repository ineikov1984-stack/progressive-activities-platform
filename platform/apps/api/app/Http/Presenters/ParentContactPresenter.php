<?php

namespace App\Http\Presenters;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\PhoneNumber;

/**
 * The parent's contact for the team (docs/product/team-rights.md, т. 6): whole for the owner and after "Покажи
 * телефона"; hidden for everyone else — the name and whether there is a phone and an e-mail, never the phone or the
 * e-mail themselves. The hidden one keeps the same fields, empty, so a screen that does not know it shows no
 * placeholder text of its own.
 */
final class ParentContactPresenter
{
    /** @return array{source: string, name: string, phone: string|null, phone_display: string|null, email: string, email_verified: bool} */
    public static function full(User $parent): array
    {
        return [
            'source' => 'account',
            'name' => $parent->name,
            'phone' => $parent->phone,
            'phone_display' => $parent->phone === null ? null : PhoneNumber::display($parent->phone),
            'email' => $parent->email,
            'email_verified' => $parent->hasVerifiedEmail(),
        ];
    }

    /** @return array{source: string, name: string, hidden: true, has_phone: bool, has_email: bool, can_reveal: true, phone: null, phone_display: null, email: null, email_verified: bool} */
    public static function hidden(User $parent): array
    {
        return [
            'source' => 'account',
            'name' => $parent->name,
            'hidden' => true,
            'has_phone' => $parent->phone !== null,
            'has_email' => $parent->email !== '',
            'can_reveal' => true,
            'phone' => null,
            'phone_display' => null,
            'email' => null,
            'email_verified' => $parent->hasVerifiedEmail(),
        ];
    }
}
