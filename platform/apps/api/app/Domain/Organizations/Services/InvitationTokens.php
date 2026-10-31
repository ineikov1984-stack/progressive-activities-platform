<?php

namespace App\Domain\Organizations\Services;

use App\Domain\Organizations\Models\Invitation;
use App\Domain\Organizations\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * An invitation is opened before anyone knows its organization (docs/product/team.md, т. 7.4). The token comes in the
 * request body, never in the path, and is looked up by its sha256 hash: on PostgreSQL a SECURITY DEFINER function gives
 * only the organization's id, and the invitation is then read under that organization's RLS and tenant context, in one
 * transaction.
 *
 * Two tokens (DECISIONS 31.10.2026): the e-mail's proves the address and may make a new profile; the link the inviter
 * copies only joins an existing profile with that address. The old form's single token was shown to the inviter, so it
 * counts as a copied link.
 */
final readonly class InvitationTokens
{
    public const MAIL = 'mail';

    public const COPY = 'copy';

    public function __construct(private TenantContext $context) {}

    /** Which of the invitation's tokens has this hash — checked again on the locked row, so a token replaced meanwhile fails. */
    public static function kind(Invitation $invitation, string $hash): ?string
    {
        $copy = $invitation->copy_token_hash;
        if (is_string($copy) && hash_equals($copy, $hash)) {
            return self::COPY;
        }
        if (hash_equals($invitation->token_hash, $hash)) {
            return is_string($copy) ? self::MAIL : self::COPY;
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  string|null  $userId  the signed-in person, for the RLS setting; none on the public page
     * @param  Closure(Invitation, string): T  $work  gets the invitation and the token's hash; runs inside the transaction,
     *                                                with the invitation's organization selected
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
                : DB::table('invitations')->where('token_hash', $hash)->orWhere('copy_token_hash', $hash)->value('organization_id');
            if (! is_string($organizationId) || $organizationId === '') {
                throw new NotFoundHttpException('Поканата не е намерена.');
            }
            if ($pgsql) {
                DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$organizationId]);
            }
            $this->context->select($organizationId);

            try {
                $invitation = Invitation::query()
                    ->where(fn ($query) => $query->where('token_hash', $hash)->orWhere('copy_token_hash', $hash))
                    ->first();
                if ($invitation === null || self::kind($invitation, $hash) === null) {
                    throw new NotFoundHttpException('Поканата не е намерена.');
                }

                return $work($invitation, $hash);
            } finally {
                $this->context->clear();
            }
        });
    }
}
