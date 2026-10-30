<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An invitation is opened by its link before anyone knows its organization (docs/product/team.md, т. 7.4). The token
 * is looked up by its sha256 hash: on PostgreSQL a SECURITY DEFINER function gives only the organization's id, and the
 * invitation is then read under that organization's RLS and tenant context, in one transaction.
 */
final readonly class InvitationTokens
{
    public function __construct(private TenantContext $context) {}

    /**
     * @template T
     *
     * @param  string|null  $userId  the signed-in person, for the RLS setting; none on the public page
     * @param  Closure(Invitation): T  $work  runs inside the transaction, with the invitation's organization selected
     * @return T
     */
    public function within(string $token, ?string $userId, Closure $work): mixed
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/D', $token) !== 1) {
            throw new NotFoundHttpException('Поканата не е намерена.');
        }
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($hash, $userId, $work): mixed {
            $pgsql = DB::getDriverName() === 'pgsql';
            if ($pgsql) {
                DB::select("SELECT set_config('app.current_user_id', ?, true)", [$userId ?? '']);
            }
            $organizationId = $pgsql
                ? DB::selectOne('SELECT public.invitation_tenant(?) AS id', [$hash])?->id
                : DB::table('invitations')->where('token_hash', $hash)->value('organization_id');
            if (! is_string($organizationId) || $organizationId === '') {
                throw new NotFoundHttpException('Поканата не е намерена.');
            }
            if ($pgsql) {
                DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$organizationId]);
            }
            $this->context->select($organizationId);

            try {
                $invitation = Invitation::query()->where('token_hash', $hash)->first()
                    ?? throw new NotFoundHttpException('Поканата не е намерена.');

                return $work($invitation);
            } finally {
                $this->context->clear();
            }
        });
    }
}
