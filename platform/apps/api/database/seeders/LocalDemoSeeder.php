<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class LocalDemoSeeder extends Seeder
{
    private const ORGANIZATION_ID = '00000000-0000-4000-8000-000000000101';

    private const OPERATOR_ORGANIZATION_ID = '00000000-0000-4000-8000-000000000102';

    private const ACTIVITY_ID = '00000000-0000-4000-8000-000000000201';

    private const OFFERING_ID = '00000000-0000-4000-8000-000000000202';

    private const PROFILE_ID = '00000000-0000-4000-8000-000000000203';

    private const LOCATION_ID = '00000000-0000-4000-8000-000000000204';

    private const INSTRUCTOR_ID = '00000000-0000-4000-8000-000000000205';

    private const GROUP_ONE_ID = '00000000-0000-4000-8000-000000000206';

    private const GROUP_TWO_ID = '00000000-0000-4000-8000-000000000207';

    /** A synthetic family for the "Подробности" panel (docs/product/enrollment-contacts-and-emails.md, т. 11). */
    private const DEMO_PARENT_EMAIL = 'parent@demo.progressive.local';

    private const DEMO_CHILD_ID = '00000000-0000-4000-8000-000000000401';

    private const DEMO_APPLICATION_ID = '00000000-0000-4000-8000-000000000402';

    private const DEMO_APPROVED_APPLICATION_ID = '00000000-0000-4000-8000-000000000403';

    private const DEMO_ENROLLMENT_ID = '00000000-0000-4000-8000-000000000404';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Local demo data cannot be seeded in this environment.');
        }

        $email = (string) config('demo.email');
        $password = (string) config('demo.password');

        if ($email === '' || $password === '') {
            throw new RuntimeException('Local demo credentials are not configured.');
        }

        DB::transaction(function () use ($email, $password): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_organization_id', ?, true)", [self::ORGANIZATION_ID]);
            }

            $user = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'name' => 'Демо собственик',
                    // Synthetic number: the demo owner also applies as a parent in the local and e2e checks.
                    'phone' => '+359880000001',
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                    'locale' => 'bg',
                    'timezone' => 'Europe/Sofia',
                    'is_active' => true,
                ],
            );

            $organization = Organization::query()->withoutGlobalScopes()->updateOrCreate(
                ['id' => self::ORGANIZATION_ID],
                [
                    'name' => 'Progressive Academy Demo',
                    'slug' => 'progressive-academy-demo',
                    'status' => 'active',
                    // Synthetic: parents' questions and the team's copies of enrollment emails go here (Mailpit).
                    'public_contact_email' => 'kontakt@demo.progressive.local',
                ],
            );

            OrganizationMembership::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => 'organization_owner',
                    'status' => 'active',
                ],
            );

            $this->seedCatalog();
            $this->seedDemoFamily($user->id);

            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_organization_id', ?, true)", [self::OPERATOR_ORGANIZATION_ID]);
            }

            $operatorOrganization = Organization::query()->withoutGlobalScopes()->updateOrCreate(
                ['id' => self::OPERATOR_ORGANIZATION_ID],
                [
                    'name' => 'Платформена администрация',
                    'slug' => 'platform-operations-demo',
                    'status' => 'active',
                ],
            );

            OrganizationMembership::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'organization_id' => $operatorOrganization->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => 'platform_admin',
                    'status' => 'active',
                ],
            );
        });
    }

    private function seedCatalog(): void
    {
        $now = now();
        $owned = ['organization_id' => self::ORGANIZATION_ID, 'updated_at' => $now];

        DB::table('organization_catalog_profiles')->upsert([[
            'id' => self::PROFILE_ID,
            ...$owned,
            'slug' => 'progressive-academy-demo',
            'public_name' => 'Progressive Academy Demo',
            'intro' => 'Занимания, които развиват любопитството, увереността и уменията на децата.',
            'accent_color' => '#216653',
            'is_published' => true,
            'version' => 1,
            'created_at' => $now,
        ]], ['id'], ['public_name', 'intro', 'accent_color', 'is_published', 'version', 'updated_at']);

        DB::table('locations')->upsert([[
            'id' => self::LOCATION_ID,
            ...$owned,
            'name' => 'Учебен център Progressive Academy',
            'type' => 'physical',
            'space_usage' => 'exclusive',
            'address' => 'бул. „България“ 100, София',
            'latitude' => 42.6657000,
            'longitude' => 23.2868000,
            'map_url' => 'https://www.google.com/maps/search/?api=1&query=42.6657%2C23.2868',
            'public_directions' => 'Входът е откъм булеварда. На рецепцията кажете, че сте за заниманието по шах.',
            'is_public' => true,
            'version' => 1,
            'created_at' => $now,
        ]], ['id'], ['name', 'type', 'space_usage', 'address', 'latitude', 'longitude', 'map_url', 'public_directions', 'is_public', 'version', 'updated_at']);

        DB::table('instructor_profiles')->upsert([[
            'id' => self::INSTRUCTOR_ID,
            ...$owned,
            'public_name' => 'Александър Петров',
            'role_title' => 'Шахматен треньор',
            'bio' => 'Работи с деца чрез игри, практически задачи и спокойна индивидуална обратна връзка.',
            'qualifications' => json_encode(['Треньор по шах за деца', 'Опит с начинаещи'], JSON_THROW_ON_ERROR),
            'is_public' => true,
            'version' => 1,
            'created_at' => $now,
        ]], ['id'], ['public_name', 'role_title', 'bio', 'qualifications', 'is_public', 'version', 'updated_at']);

        DB::table('activities')->upsert([[
            'id' => self::ACTIVITY_ID,
            ...$owned,
            'slug' => 'shahmat',
            'title' => 'Шахмат',
            'category' => 'Логика и мислене',
            'summary' => 'Шахматни занимания, които чрез игра и практически задачи развиват логическото мислене, концентрацията и увереността.',
            'age_label' => '1.–4. клас',
            'about' => 'Децата постепенно опознават фигурите и правилата, решават задачи и играят учебни партии. Всяко занимание насърчава самостоятелното мислене и уважението към другия играч.',
            'benefits' => json_encode(['Концентрация и внимание', 'Логическо мислене', 'Стратегическо планиране', 'Търпение и самоконтрол'], JSON_THROW_ON_ERROR),
            'methodology' => 'Започваме с кратка задача, представяме нова идея, упражняваме я чрез игра и завършваме с анализ на решенията.',
            'participation_terms' => 'Подходящо е и за начинаещи. Записването за период не означава авансово плащане на целия период, освен ако изрично не е посочено.',
            'faq' => json_encode([
                ['question' => 'Подходящ ли е шахматът за дете без предишен опит?', 'answer' => 'Да. Групите са подходящи и за начинаещи, а задачите се адаптират към темпото на детето.'],
                ['question' => 'Необходимо ли е детето да носи шах?', 'answer' => 'Не. Всички материали са осигурени на място.'],
            ], JSON_THROW_ON_ERROR),
            'gallery' => json_encode([], JSON_THROW_ON_ERROR),
            'instructor_intro' => 'Спокоен и подкрепящ подход към всяко дете',
            'status' => 'published',
            'published_at' => '2026-10-27 10:00:00+00',
            'version' => 1,
            'created_at' => $now,
        ]], ['id'], ['slug', 'title', 'category', 'summary', 'age_label', 'about', 'benefits', 'methodology', 'participation_terms', 'faq', 'gallery', 'instructor_intro', 'status', 'published_at', 'version', 'updated_at']);

        DB::table('offerings')->upsert([[
            'id' => self::OFFERING_ID,
            ...$owned,
            'activity_id' => self::ACTIVITY_ID,
            'location_id' => self::LOCATION_ID,
            'lead_instructor_profile_id' => self::INSTRUCTOR_ID,
            'title' => 'Шахмат за ученици',
            'description' => 'Седмични групови занимания в малки групи.',
            'terms' => 'Мястото се пази след потвърждение от организацията.',
            'cancellation_policy' => 'За прекратяване на участието уведомете организацията до 25-о число на текущия месец.',
            'contact_email' => 'owner@demo.progressive.local',
            'enrollment_instructions' => 'Изберете подходящата група. Реалното записване ще бъде включено в следващ етап.',
            'version' => 1,
            'created_at' => $now,
        ]], ['id'], ['location_id', 'lead_instructor_profile_id', 'title', 'description', 'terms', 'cancellation_policy', 'contact_email', 'enrollment_instructions', 'version', 'updated_at']);

        $groups = [
            [self::GROUP_ONE_ID, 'Група 1 · 1.–2. клас', '1.–2. клас', 12, '2027-01-11', '2027-02-22', 1, '16:00'],
            [self::GROUP_TWO_ID, 'Група 2 · 3.–4. клас', '3.–4. клас', 14, '2027-01-13', '2027-02-24', 3, '17:30'],
        ];
        foreach ($groups as [$id, $name, $age, $capacity, $start, $end, $weekday, $startsAt]) {
            DB::table('activity_groups')->upsert([[
                'id' => $id,
                ...$owned,
                'offering_id' => self::OFFERING_ID,
                'location_id' => self::LOCATION_ID,
                'name' => $name,
                'age_label' => $age,
                'capacity_min' => 4,
                'capacity_max' => $capacity,
                'schedule_pattern' => json_encode([
                    'timezone' => 'Europe/Sofia', 'start_date' => $start, 'end_date' => $end,
                    'frequency' => 'weekly', 'slots' => [['weekday' => $weekday, 'starts_at' => $startsAt, 'duration_minutes' => 60]],
                ], JSON_THROW_ON_ERROR),
                'status' => 'published',
                'version' => 1,
                'created_at' => $now,
            ]], ['id'], ['location_id', 'name', 'age_label', 'capacity_min', 'capacity_max', 'schedule_pattern', 'status', 'version', 'updated_at']);
        }

        DB::table('activity_group_instructors')->upsert(array_map(fn (string $groupId): array => [
            'organization_id' => self::ORGANIZATION_ID, 'group_id' => $groupId,
            'instructor_profile_id' => self::INSTRUCTOR_ID, 'created_at' => $now, 'updated_at' => $now,
        ], [self::GROUP_ONE_ID, self::GROUP_TWO_ID]), ['group_id', 'instructor_profile_id'], ['updated_at']);

        $this->seedSessions($now);

        $prices = [];
        foreach ([self::GROUP_ONE_ID, self::GROUP_TWO_ID] as $index => $groupId) {
            $prices[] = $this->price('00000000-0000-4000-8000-000000000'.(208 + $index * 2), $groupId, 'monthly', 'month', 3000, 'Месечна такса; плащането е за текущия месец.', $now);
            $prices[] = $this->price('00000000-0000-4000-8000-000000000'.(209 + $index * 2), $groupId, 'trial', 'session', 1500, 'Еднократно пробно занимание при свободни места.', $now);
        }
        DB::table('price_options')->upsert($prices, ['id'], ['group_id', 'enrollment_mode', 'unit', 'amount_cents', 'currency', 'terms', 'is_available', 'version', 'updated_at']);
    }

    private function seedSessions(mixed $now): void
    {
        $definitions = [
            [self::GROUP_ONE_ID, 12, '16:00', ['2027-01-11', '2027-01-18', '2027-01-25', '2027-02-01', '2027-02-08', '2027-02-15', '2027-02-22']],
            [self::GROUP_TWO_ID, 14, '17:30', ['2027-01-13', '2027-01-20', '2027-01-27', '2027-02-03', '2027-02-10', '2027-02-17', '2027-02-24']],
        ];
        $sessions = [];
        $sequence = 301;
        foreach ($definitions as [$groupId, $capacity, $time, $dates]) {
            foreach ($dates as $date) {
                $startsAt = CarbonImmutable::parse($date.' '.$time, 'Europe/Sofia')->utc();
                $sessions[] = [
                    'id' => '00000000-0000-4000-8000-'.str_pad((string) $sequence++, 12, '0', STR_PAD_LEFT),
                    'organization_id' => self::ORGANIZATION_ID, 'group_id' => $groupId, 'location_id' => self::LOCATION_ID,
                    'starts_at' => $startsAt, 'ends_at' => $startsAt->addHour(), 'capacity' => $capacity,
                    'status' => 'scheduled', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }
        DB::table('activity_sessions')->upsert($sessions, ['id'], ['group_id', 'location_id', 'starts_at', 'ends_at', 'capacity', 'status', 'version', 'updated_at']);
        DB::table('activity_session_instructors')->upsert(array_map(fn (array $session): array => [
            'organization_id' => self::ORGANIZATION_ID, 'session_id' => $session['id'],
            'instructor_profile_id' => self::INSTRUCTOR_ID, 'created_at' => $now, 'updated_at' => $now,
        ], $sessions), ['session_id', 'instructor_profile_id'], ['updated_at']);
    }

    /**
     * Мария Демо (synthetic) with Ния: a new application with a note in Група 1 and an enrollment in
     * Група 2, decided by the demo owner. Created once and never reset, so a decision taken while
     * trying the screen stays; history rows and events are immutable and only inserted.
     */
    private function seedDemoFamily(string $ownerId): void
    {
        $now = now();
        $parent = User::query()->updateOrCreate(
            ['email' => self::DEMO_PARENT_EMAIL],
            [
                'name' => 'Мария Демо',
                'phone' => '+359880000002',
                // Not a sign-in account for the demo: an unknown random password.
                'password' => Hash::make(bin2hex(random_bytes(16))),
                'email_verified_at' => $now,
                'locale' => 'bg',
                'timezone' => 'Europe/Sofia',
                'is_active' => true,
            ],
        );
        DB::table('children')->insertOrIgnore([[
            'id' => self::DEMO_CHILD_ID, 'owner_user_id' => $parent->id, 'first_name' => 'Ния', 'last_name' => 'Демова',
            'birth_date' => '2018-05-14', 'school_name' => '120 СУ (демо)', 'class_label' => '2 клас',
            'status' => 'active', 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]]);
        $application = fn (string $id, string $groupId, string $priceId, string $status, ?string $note, string $key): array => [
            'id' => $id, 'organization_id' => self::ORGANIZATION_ID, 'parent_user_id' => $parent->id,
            'child_id' => self::DEMO_CHILD_ID, 'group_id' => $groupId, 'price_option_id' => $priceId,
            'scope' => 'full_period', 'source' => 'organization_catalog', 'status' => $status,
            'idempotency_key' => $key, 'accepted_terms_version' => 'demo', 'accepted_terms_at' => $now,
            'amount_cents_snapshot' => 3000, 'currency_snapshot' => 'EUR', 'unit_snapshot' => 'month',
            'parent_note' => $note, 'version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('enrollment_applications')->insertOrIgnore([
            $application(self::DEMO_APPLICATION_ID, self::GROUP_ONE_ID, '00000000-0000-4000-8000-000000000208', 'submitted',
                'Моля, обадете се след 17:00 — тогава съм свободна. Ния тренира и плуване във вторник.', 'demo-family-1'),
            $application(self::DEMO_APPROVED_APPLICATION_ID, self::GROUP_TWO_ID, '00000000-0000-4000-8000-000000000210', 'approved', null, 'demo-family-2'),
        ]);
        DB::table('enrollments')->insertOrIgnore([[
            'id' => self::DEMO_ENROLLMENT_ID, 'organization_id' => self::ORGANIZATION_ID,
            'application_id' => self::DEMO_APPROVED_APPLICATION_ID, 'child_id' => self::DEMO_CHILD_ID,
            'group_id' => self::GROUP_TWO_ID, 'price_option_id' => '00000000-0000-4000-8000-000000000210',
            'scope' => 'full_period', 'status' => 'active', 'starts_on' => '2027-01-13', 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ]]);
        $transitionId = '00000000-0000-4000-8000-000000000405';
        DB::table('enrollment_transitions')->insertOrIgnore([[
            'id' => $transitionId, 'organization_id' => self::ORGANIZATION_ID,
            'application_id' => self::DEMO_APPROVED_APPLICATION_ID, 'actor_user_id' => $ownerId,
            'from_status' => 'submitted', 'to_status' => 'approved', 'created_at' => $now, 'updated_at' => $now,
        ]]);
        // Already expanded: the demo never sends emails for its own history.
        $event = fn (string $id, string $type, string $applicationId, ?string $enrollmentId, array $payload): array => [
            'id' => $id, 'organization_id' => self::ORGANIZATION_ID, 'type' => $type, 'application_id' => $applicationId,
            'enrollment_id' => $enrollmentId, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred_at' => $now, 'expanded_at' => $now, 'attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('enrollment_events')->insertOrIgnore([
            $event('00000000-0000-4000-8000-000000000406', 'enrollment.application_submitted', self::DEMO_APPLICATION_ID, null, ['status' => 'submitted']),
            $event('00000000-0000-4000-8000-000000000407', 'enrollment.application_submitted', self::DEMO_APPROVED_APPLICATION_ID, null, ['status' => 'submitted']),
            $event('00000000-0000-4000-8000-000000000408', 'enrollment.application_approved', self::DEMO_APPROVED_APPLICATION_ID, self::DEMO_ENROLLMENT_ID, ['status' => 'approved', 'transition_id' => $transitionId]),
        ]);
    }

    /** @return array<string, mixed> */
    private function price(string $id, string $groupId, string $mode, string $unit, int $amount, string $terms, mixed $now): array
    {
        return [
            'id' => $id, 'organization_id' => self::ORGANIZATION_ID, 'offering_id' => self::OFFERING_ID,
            'group_id' => $groupId, 'enrollment_mode' => $mode, 'unit' => $unit, 'amount_cents' => $amount,
            'currency' => 'EUR', 'terms' => $terms, 'is_available' => true, 'version' => 1,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }
}
