<?php

namespace Tests\Unit\Identity;

use App\Domain\Identity\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function writtenNumbers(): array
    {
        return [
            'mobile with spaces' => ['0881 234 567', '+359881234567'],
            'mobile with dashes' => ['088-123-4567', '+359881234567'],
            'Sofia landline' => ['02 123 4567', '+35921234567'],
            'with country code' => ['+359 88 123 4567', '+359881234567'],
            'with 00' => ['00359881234567', '+359881234567'],
            'country code without plus' => ['359881234567', '+359881234567'],
            'another country' => ['+44 7700 900123', '+447700900123'],
            'another country with 00' => ['0044 7700 900123', '+447700900123'],
            'brackets' => ['(02) 123 4567', '+35921234567'],
        ];
    }

    #[DataProvider('writtenNumbers')]
    public function test_numbers_are_kept_in_one_form(string $written, string $stored): void
    {
        $this->assertSame($stored, PhoneNumber::normalize($written));
    }

    /** @return array<string, array{string|null}> */
    public static function notNumbers(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'too short' => ['0888'],
            'letters' => ['abc'],
            'country code starting with 0' => ['+0123456789'],
            'Bulgarian number too long' => ['+3598812345678'],
            'Bulgarian national number with a second 0' => ['00359 0881 234 567'],
            'too long anywhere' => ['+1234567890123456'],
        ];
    }

    #[DataProvider('notNumbers')]
    public function test_anything_else_is_not_a_phone_number(?string $written): void
    {
        $this->assertNull(PhoneNumber::normalize($written));
    }

    public function test_bulgarian_numbers_are_shown_the_way_people_write_them(): void
    {
        $this->assertSame('088 123 4567', PhoneNumber::display('+359881234567'));
        $this->assertSame('02 123 4567', PhoneNumber::display('+35921234567'));
        $this->assertSame('+447700900123', PhoneNumber::display('+447700900123'));
    }
}
