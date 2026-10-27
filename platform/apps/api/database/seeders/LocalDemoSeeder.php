<?php

namespace Database\Seeders;

use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class LocalDemoSeeder extends Seeder
{
    private const ORGANIZATION_ID = '00000000-0000-4000-8000-000000000101';

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
        });
    }
}
