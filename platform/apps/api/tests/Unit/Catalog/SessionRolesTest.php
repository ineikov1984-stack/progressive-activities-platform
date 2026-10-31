<?php

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Services\SessionRoles;
use PHPUnit\Framework\TestCase;

/**
 * Who leads, assists and stands in at a session (docs/product/schedule.md, т. 5): from the group's assignments for the
 * session's day and the lead of the offering — never from the role written on the session, which a change by hand writes
 * "lead" for everyone. Every name is synthetic.
 */
class SessionRolesTest extends TestCase
{
    private const DAY = '2026-11-12';

    public function test_an_assignment_for_the_day_tells_the_role(): void
    {
        $roles = SessionRoles::derive(
            [$this->onSession('b', 'Иван Петров'), $this->onSession('a', 'Мария Колева')],
            [$this->assignment('a', 'Мария Колева', 'lead'), $this->assignment('b', 'Иван Петров', 'assistant')],
            null,
            self::DAY,
        );

        // The leads first, then the assistants.
        $this->assertSame([
            ['profile_id' => 'a', 'name' => 'Мария Колева', 'role' => 'lead'],
            ['profile_id' => 'b', 'name' => 'Иван Петров', 'role' => 'assistant'],
        ], $roles);
    }

    public function test_a_teacher_changed_by_hand_stands_in_for_the_leads_of_the_day_who_are_not_there(): void
    {
        $roles = SessionRoles::derive(
            [$this->onSession('c', 'Деси Маринова')],
            [
                $this->assignment('a', 'Ани Петрова', 'lead'),
                // An assistant is not stood in for; an ended and a later assignment do not hold that day.
                $this->assignment('b', 'Иван Петров', 'assistant'),
                $this->assignment('d', 'Бивш Водещ', 'lead', null, '2026-11-11'),
                $this->assignment('e', 'Бъдещ Водещ', 'lead', '2026-11-13'),
            ],
            null,
            self::DAY,
        );

        $this->assertSame([[
            'profile_id' => 'c', 'name' => 'Деси Маринова', 'role' => 'substitute',
            'replaces' => [['profile_id' => 'a', 'name' => 'Ани Петрова']],
        ]], $roles);
    }

    public function test_with_nobody_to_stand_in_for_a_teacher_without_an_assignment_simply_leads(): void
    {
        $roles = SessionRoles::derive(
            [$this->onSession('a', 'Ани Петрова'), $this->onSession('c', 'Деси Маринова')],
            [$this->assignment('a', 'Ани Петрова', 'lead')],
            null,
            self::DAY,
        );

        $this->assertSame([
            ['profile_id' => 'a', 'name' => 'Ани Петрова', 'role' => 'lead'],
            ['profile_id' => 'c', 'name' => 'Деси Маринова', 'role' => 'lead'],
        ], $roles);
    }

    public function test_the_lead_of_the_offering_leads_and_an_assignment_holds_on_its_first_and_last_day(): void
    {
        $roles = SessionRoles::derive(
            [$this->onSession('l', 'Водещ на дейността'), $this->onSession('b', 'Иван Петров'), $this->onSession('d', 'Деян Колев')],
            [
                $this->assignment('b', 'Иван Петров', 'assistant', self::DAY, self::DAY),
                // An assistant whose assignment ended the day before stands in for the lead who is away.
                $this->assignment('d', 'Деян Колев', 'assistant', null, '2026-11-11'),
                $this->assignment('a', 'Ани Петрова', 'lead', '2026-11-01'),
            ],
            'l',
            self::DAY,
        );

        // The leads, those who stand in, then the assistants.
        $this->assertSame([
            ['profile_id' => 'l', 'name' => 'Водещ на дейността', 'role' => 'lead'],
            ['profile_id' => 'd', 'name' => 'Деян Колев', 'role' => 'substitute', 'replaces' => [['profile_id' => 'a', 'name' => 'Ани Петрова']]],
            ['profile_id' => 'b', 'name' => 'Иван Петров', 'role' => 'assistant'],
        ], $roles);
    }

    public function test_an_assignment_wins_over_the_lead_of_the_offering(): void
    {
        $roles = SessionRoles::derive(
            [$this->onSession('l', 'Водещ на дейността')],
            [$this->assignment('l', 'Водещ на дейността', 'assistant'), $this->assignment('a', 'Ани Петрова', 'lead')],
            'l',
            self::DAY,
        );

        $this->assertSame([['profile_id' => 'l', 'name' => 'Водещ на дейността', 'role' => 'assistant']], $roles);
    }

    /** @return array{profile_id: string, name: string} */
    private function onSession(string $profileId, string $name): array
    {
        return ['profile_id' => $profileId, 'name' => $name];
    }

    /** @return array{profile_id: string, name: string, role: string, starts_on: string|null, ends_on: string|null} */
    private function assignment(string $profileId, string $name, string $role, ?string $startsOn = null, ?string $endsOn = null): array
    {
        return ['profile_id' => $profileId, 'name' => $name, 'role' => $role, 'starts_on' => $startsOn, 'ends_on' => $endsOn];
    }
}
