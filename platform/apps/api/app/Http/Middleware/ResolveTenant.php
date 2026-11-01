<?php

namespace App\Http\Middleware;

use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Models\OrganizationMembership;
use App\Domain\Organizations\Services\MembershipActivity;
use App\Domain\Organizations\Services\StaffRights;
use App\Domain\Organizations\Tenancy\TenantContext;
use Closure;
use Illuminate\Database\DeadlockException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    /** The membership whose "Последна активност" is due, kept on the request for `terminate`. */
    private const SEEN = 'tenant.membership_seen';

    public function __construct(
        private readonly TenantContext $context,
        private readonly StaffRights $rights,
        private readonly MembershipActivity $activity,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $organizationId = $request->header('X-Organization-Id')
            ?: $request->session()->get('active_organization_id');

        if (! is_string($organizationId) || $organizationId === '') {
            return new JsonResponse(
                ['message' => 'Изберете организация, за да продължите.'],
                Response::HTTP_CONFLICT,
            );
        }

        return DB::transaction(function () use ($next, $request, $organizationId): Response {
            $userId = (string) $request->user()->getAuthIdentifier();

            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_user_id', ?, true)", [$userId]);
            }

            // The same check as before; it also reads when the person was last seen here.
            $membership = OrganizationMembership::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->first(['id', 'last_seen_at']);

            $organization = Organization::query()
                ->withoutGlobalScopes()
                ->whereKey($organizationId)
                ->first();

            if ($membership === null || $organization === null) {
                return new JsonResponse(['message' => 'Организацията не е намерена.'], Response::HTTP_NOT_FOUND);
            }

            $this->context->select($organization);

            try {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$organizationId]);
                }
                // "Последна активност" is written after the response, in a transaction of its own (`terminate`).
                if ($this->activity->isDue($membership->last_seen_at)) {
                    $request->attributes->set(self::SEEN, ['organization' => $organizationId, 'membership' => (string) $membership->getKey()]);
                }

                // The rights of the request's user are read once for the request (docs/product/team-rights.md, т. 4.5).
                $response = $this->rights->remembering(fn (): Response => $next($request));
                // Routing's pipeline renders exceptions before returning through middleware. A nested deadlock has
                // aborted PostgreSQL's transaction; rethrow its response exception so this outer transaction rolls back.
                $error = $response instanceof JsonResponse ? $response->exception : null;
                if ($error instanceof DeadlockException || $error?->getPrevious() instanceof DeadlockException) {
                    throw $error;
                }

                return $response;
            } finally {
                $this->context->clear();
            }
        });
    }

    /**
     * After the response: "Последна активност" of the membership the request checked, at most once in five minutes —
     * outside the request's transaction, so the membership row is never held while a request runs.
     */
    public function terminate(Request $request, Response $response): void
    {
        $seen = $request->attributes->get(self::SEEN);
        if (is_array($seen) && is_string($seen['organization'] ?? null) && is_string($seen['membership'] ?? null)) {
            $this->activity->seen($seen['organization'], $seen['membership']);
        }
    }
}
