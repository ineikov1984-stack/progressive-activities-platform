<?php

namespace App\Domain\PaymentTerms\Rules;

use App\Domain\PaymentTerms\Enums\BillingKind;
use App\Domain\PaymentTerms\Enums\TermsPreset;

/**
 * The ready-made sets "Гъвкави / Стандартни / Строги" for each way of paying (docs/product/payment-policies.md,
 * т. 6; the owner's decisions of 30.10.2026). "Собствени" starts from "Стандартни". Every set passes
 * TermsSchema and MarketplaceLimits (a test checks it).
 */
final class Presets
{
    /** @return array<string, mixed> */
    public static function rules(BillingKind $kind, TermsPreset $preset): array
    {
        $preset = $preset === TermsPreset::Custom ? TermsPreset::Standard : $preset;

        return match ($kind) {
            BillingKind::Monthly => self::monthly($preset),
            BillingKind::FullPeriod => self::fullPeriod($preset),
            BillingKind::Visit => self::visit($preset),
            BillingKind::Package => self::package($preset),
            BillingKind::DayCamp => self::dayCamp($preset),
            BillingKind::ResidentialCamp => self::residentialCamp($preset),
            BillingKind::Kindergarten => self::kindergarten($preset),
        };
    }

    /** @return array{absence: bool, pause_weeks: int, end: bool, cancel_visit: bool} */
    public static function selfService(TermsPreset $preset): array
    {
        return match ($preset) {
            TermsPreset::Flexible => ['absence' => true, 'pause_weeks' => 4, 'end' => true, 'cancel_visit' => true],
            TermsPreset::Strict => ['absence' => true, 'pause_weeks' => 0, 'end' => false, 'cancel_visit' => true],
            default => ['absence' => true, 'pause_weeks' => 2, 'end' => false, 'cancel_visit' => true],
        };
    }

    /** @return array<string, mixed> */
    private static function monthly(TermsPreset $preset): array
    {
        $bySessions = ['mode' => 'by_sessions', 'cutoff_day' => null, 'kept_until_cutoff' => null, 'kept_after_cutoff' => null];

        return match ($preset) {
            TermsPreset::Flexible => [
                'start' => $bySessions,
                'end' => [...$bySessions, 'notice_days' => 0],
                'pause' => ['notice_days' => 3, 'kept_on_time' => 0, 'kept_late' => 50, 'free_days_per_school_year' => 92, 'document_required' => false, 'min_days' => 0],
                'absences' => ['tiers' => [['up_to' => null, 'kept' => 100]]],
                'makeup' => ['enabled' => true, 'within_days' => 30, 'notice_hours' => 3, 'per_month' => null, 'only_if_space' => false],
                'refund' => 'parent_choice',
            ],
            TermsPreset::Strict => [
                'start' => ['mode' => 'cutoff', 'cutoff_day' => 15, 'kept_until_cutoff' => 100, 'kept_after_cutoff' => 50],
                'end' => ['mode' => 'full_month', 'cutoff_day' => null, 'kept_until_cutoff' => null, 'kept_after_cutoff' => null, 'notice_days' => 0],
                'pause' => ['notice_days' => 0, 'kept_on_time' => 50, 'kept_late' => 50, 'free_days_per_school_year' => 183, 'document_required' => true, 'min_days' => 21],
                'absences' => ['tiers' => [['up_to' => null, 'kept' => 100]]],
                'makeup' => ['enabled' => true, 'within_days' => 7, 'notice_hours' => 0, 'per_month' => null, 'only_if_space' => true],
                'refund' => 'parent_choice',
            ],
            default => [
                'start' => $bySessions,
                // The owner's example: last day up to the 15th — half the month; after — the whole month.
                'end' => ['mode' => 'cutoff', 'cutoff_day' => 15, 'kept_until_cutoff' => 50, 'kept_after_cutoff' => 100, 'notice_days' => 0],
                // 7 days' notice (the owner asked for less than 14); "2 months" a school year = 61 days.
                'pause' => ['notice_days' => 7, 'kept_on_time' => 0, 'kept_late' => 50, 'free_days_per_school_year' => 61, 'document_required' => false, 'min_days' => 0],
                // Up to 3 absences — the whole fee; more — half.
                'absences' => ['tiers' => [['up_to' => 3, 'kept' => 100], ['up_to' => null, 'kept' => 50]]],
                'makeup' => ['enabled' => true, 'within_days' => 14, 'notice_hours' => 3, 'per_month' => null, 'only_if_space' => false],
                'refund' => 'parent_choice',
            ],
        };
    }

    /** @return array<string, mixed> */
    private static function fullPeriod(TermsPreset $preset): array
    {
        $pause = ['notice_days' => 7, 'kept_on_time' => 0, 'kept_late' => 50, 'free_days_per_school_year' => 61, 'document_required' => false, 'min_days' => 0];
        $makeup = ['enabled' => true, 'within_days' => 14, 'notice_hours' => 3, 'per_month' => null, 'only_if_space' => false];

        return match ($preset) {
            TermsPreset::Flexible => [
                'before_start' => ['tiers' => [['min_days' => 0, 'kept' => 0]]],
                'after_start' => ['full_refund_days' => 30, 'kept_of_remaining' => 0],
                'pause' => [...$pause, 'notice_days' => 3, 'free_days_per_school_year' => 92],
                'makeup' => [...$makeup, 'within_days' => 30],
                'refund' => 'parent_choice',
            ],
            TermsPreset::Strict => [
                'before_start' => ['tiers' => [['min_days' => 7, 'kept' => 0], ['min_days' => 0, 'kept' => 25]]],
                'after_start' => ['full_refund_days' => 7, 'kept_of_remaining' => 75],
                'pause' => [...$pause, 'notice_days' => 0, 'kept_on_time' => 50, 'free_days_per_school_year' => 183, 'document_required' => true, 'min_days' => 21],
                'makeup' => [...$makeup, 'within_days' => 7, 'notice_hours' => 0, 'only_if_space' => true],
                'refund' => 'parent_choice',
            ],
            default => [
                'before_start' => ['tiers' => [['min_days' => 0, 'kept' => 0]]],
                'after_start' => ['full_refund_days' => 14, 'kept_of_remaining' => 50],
                'pause' => $pause,
                'makeup' => $makeup,
                'refund' => 'parent_choice',
            ],
        };
    }

    /** @return array<string, mixed> */
    private static function visit(TermsPreset $preset): array
    {
        $tiers = match ($preset) {
            TermsPreset::Flexible => [['min_hours' => 12, 'kept' => 0], ['min_hours' => 0, 'kept' => 50]],
            TermsPreset::Strict => [['min_hours' => 48, 'kept' => 0], ['min_hours' => 0, 'kept' => 100]],
            // At least 24 h before — nothing; 12–24 h — half; less than 12 h — the whole visit.
            default => [['min_hours' => 24, 'kept' => 0], ['min_hours' => 12, 'kept' => 50], ['min_hours' => 0, 'kept' => 100]],
        };

        return ['cancellation' => ['tiers' => $tiers], 'no_show_kept' => 100, 'refund' => 'parent_choice'];
    }

    /** @return array<string, mixed> */
    private static function package(TermsPreset $preset): array
    {
        $days = match ($preset) {
            TermsPreset::Flexible => 60,
            TermsPreset::Strict => 0,
            default => 30,
        };

        return ['freeze_days_with_document' => $days, 'extension_days_on_request' => $days, 'refund' => 'parent_choice'];
    }

    /** @return array<string, mixed> */
    private static function dayCamp(TermsPreset $preset): array
    {
        return match ($preset) {
            TermsPreset::Flexible => [
                'before_start' => ['tiers' => [['min_days' => 7, 'kept' => 0, 'credit_offered' => false], ['min_days' => 0, 'kept' => 50, 'credit_offered' => true]]],
                'absences' => ['kept_first_days' => 1, 'kept_after' => 0],
                'week_change_free_until_days' => 1,
                'refund' => 'parent_choice',
            ],
            TermsPreset::Strict => [
                'before_start' => ['tiers' => [['min_days' => 14, 'kept' => 0, 'credit_offered' => false], ['min_days' => 0, 'kept' => 100, 'credit_offered' => true]]],
                'absences' => ['kept_first_days' => 5, 'kept_after' => 100],
                'week_change_free_until_days' => 7,
                'refund' => 'parent_choice',
            ],
            default => [
                'before_start' => ['tiers' => [
                    ['min_days' => 14, 'kept' => 0, 'credit_offered' => false],
                    ['min_days' => 7, 'kept' => 50, 'credit_offered' => false],
                    // Under 7 days nothing comes back; the organization offers a credit, the parent decides.
                    ['min_days' => 0, 'kept' => 100, 'credit_offered' => true],
                ]],
                'absences' => ['kept_first_days' => 2, 'kept_after' => 50],
                'week_change_free_until_days' => 3,
                'refund' => 'parent_choice',
            ],
        };
    }

    /** @return array<string, mixed> */
    private static function residentialCamp(TermsPreset $preset): array
    {
        return match ($preset) {
            TermsPreset::Flexible => [
                'deposit' => ['mode' => 'percent', 'amount_cents' => null, 'percent' => 10],
                'before_start' => ['tiers' => [self::tier(21, 0), self::tier(8, 25), self::tier(4, 50), self::tier(0, 100)]],
                'illness_with_document' => ['kept' => 0, 'plus_costs' => false],
                'transfer_to_another_child_until_days' => 3,
                'early_departure_kept' => 50,
                'refund' => 'parent_choice',
            ],
            TermsPreset::Strict => [
                'deposit' => ['mode' => 'percent', 'amount_cents' => null, 'percent' => 30],
                'before_start' => ['tiers' => [self::tier(45, 0), self::tier(30, 25), self::tier(15, 50), self::tier(0, 100)]],
                'illness_with_document' => ['kept' => 25, 'plus_costs' => true],
                'transfer_to_another_child_until_days' => 14,
                'early_departure_kept' => 100,
                'refund' => 'parent_choice',
            ],
            default => [
                // 600 € with 150 € down: 30+ days — all back; 15–29 — 75 %; 8–14 — 50 %; 7 or fewer — nothing.
                'deposit' => ['mode' => 'percent', 'amount_cents' => null, 'percent' => 25],
                'before_start' => ['tiers' => [self::tier(30, 0), self::tier(15, 25), self::tier(8, 50), self::tier(0, 100)]],
                'illness_with_document' => ['kept' => 0, 'plus_costs' => true],
                'transfer_to_another_child_until_days' => 7,
                'early_departure_kept' => 100,
                'refund' => 'parent_choice',
            ],
        };
    }

    /** @return array{min_days: int, kept: int, credit_offered: bool} */
    private static function tier(int $days, int $kept): array
    {
        return ['min_days' => $days, 'kept' => $kept, 'credit_offered' => false];
    }

    /** @return array<string, mixed> */
    private static function kindergarten(TermsPreset $preset): array
    {
        return match ($preset) {
            TermsPreset::Flexible => ['notice_days' => 14, 'deposit_months' => 0, 'meals_by_attendance' => true, 'sick_over_working_days' => 10, 'sick_next_month_kept' => 50, 'refund' => 'parent_choice'],
            TermsPreset::Strict => ['notice_days' => 30, 'deposit_months' => 1, 'meals_by_attendance' => true, 'sick_over_working_days' => 0, 'sick_next_month_kept' => 100, 'refund' => 'parent_choice'],
            default => ['notice_days' => 30, 'deposit_months' => 1, 'meals_by_attendance' => true, 'sick_over_working_days' => 15, 'sick_next_month_kept' => 50, 'refund' => 'parent_choice'],
        };
    }
}
