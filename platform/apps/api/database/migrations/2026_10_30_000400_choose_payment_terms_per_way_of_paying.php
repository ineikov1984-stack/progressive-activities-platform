<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An activity chooses its terms for each way of paying it has (the owner's review of 30.10.2026): a monthly fee
 * and a single visit in the same activity follow different rules. Replaces the one choice per activity of
 * 2026_10_30_000300; an existing choice moves over for its way of paying.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Migrations run as the database owner (docs/process/DECISIONS.md, "Миграции с ролята-собственик").
        if (DB::getDriverName() === 'pgsql') {
            $role = DB::selectOne('SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = current_user');
            if ($role !== null && ! $role->rolsuper && ! $role->rolbypassrls) {
                throw new RuntimeException('Run migrations as the database owner: php artisan migrate --database=pgsql_admin --force');
            }
        }

        Schema::create('offering_payment_terms', function (Blueprint $table): void {
            $table->uuid('offering_id');
            $table->foreignUuid('organization_id')->constrained()->cascadeOnDelete();
            // The values of App\Domain\PaymentTerms\Enums\BillingKind, fixed here on purpose.
            $table->enum('billing_kind', ['monthly', 'full_period', 'visit', 'package', 'day_camp', 'residential_camp', 'kindergarten']);
            $table->uuid('payment_terms_id');
            $table->timestamps();
            $table->primary(['offering_id', 'billing_kind']);
            $table->index('payment_terms_id');
            if (DB::getDriverName() === 'sqlite') {
                $table->foreign('offering_id')->references('id')->on('offerings')->cascadeOnDelete();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                -- The chosen set belongs to the activity's organization and is for that way of paying. The key to
                -- the set is checked at commit (see 2026_10_30_000300 for why).
                ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_id_organization_kind_unique UNIQUE (id, organization_id, billing_kind);
                ALTER TABLE offering_payment_terms ADD CONSTRAINT offering_payment_terms_offering_tenant_fk
                    FOREIGN KEY (offering_id, organization_id) REFERENCES offerings (id, organization_id) ON DELETE CASCADE;
                ALTER TABLE offering_payment_terms ADD CONSTRAINT offering_payment_terms_terms_tenant_fk
                    FOREIGN KEY (payment_terms_id, organization_id, billing_kind) REFERENCES payment_terms (id, organization_id, billing_kind)
                    DEFERRABLE INITIALLY DEFERRED;

                ALTER TABLE offering_payment_terms ENABLE ROW LEVEL SECURITY;
                ALTER TABLE offering_payment_terms FORCE ROW LEVEL SECURITY;
                CREATE POLICY offering_payment_terms_tenant_policy ON offering_payment_terms
                    USING (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid)
                    WITH CHECK (organization_id = NULLIF(current_setting('app.current_organization_id', true), '')::uuid);
                SQL);
        }

        // An earlier choice moves over for the way of paying of its set.
        $moved = DB::table('offerings')
            ->join('payment_terms', function ($join): void {
                $join->on('payment_terms.id', '=', 'offerings.payment_terms_id')
                    ->on('payment_terms.organization_id', '=', 'offerings.organization_id');
            })
            ->whereNotNull('offerings.payment_terms_id')
            ->get(['offerings.id as offering_id', 'offerings.organization_id', 'payment_terms.billing_kind', 'offerings.payment_terms_id']);
        foreach ($moved as $row) {
            DB::table('offering_payment_terms')->insert([
                'offering_id' => $row->offering_id, 'organization_id' => $row->organization_id,
                'billing_kind' => $row->billing_kind, 'payment_terms_id' => $row->payment_terms_id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('ALTER TABLE offerings DROP CONSTRAINT IF EXISTS offerings_payment_terms_tenant_fk');
        }
        Schema::table('offerings', function (Blueprint $table): void {
            $table->dropIndex(['payment_terms_id']);
            $table->dropColumn('payment_terms_id');
        });
    }

    public function down(): void
    {
        Schema::table('offerings', function (Blueprint $table): void {
            $table->uuid('payment_terms_id')->nullable();
            $table->index('payment_terms_id');
        });
        // One choice per activity again: the first way of paying keeps its set.
        foreach (DB::table('offering_payment_terms')->orderBy('billing_kind')->get() as $row) {
            DB::table('offerings')->where('id', $row->offering_id)->whereNull('payment_terms_id')
                ->update(['payment_terms_id' => $row->payment_terms_id]);
        }
        Schema::dropIfExists('offering_payment_terms');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                ALTER TABLE payment_terms DROP CONSTRAINT IF EXISTS payment_terms_id_organization_kind_unique;
                ALTER TABLE offerings ADD CONSTRAINT offerings_payment_terms_tenant_fk
                    FOREIGN KEY (payment_terms_id, organization_id) REFERENCES payment_terms (id, organization_id)
                    DEFERRABLE INITIALLY DEFERRED;
                SQL);
        }
    }
};
