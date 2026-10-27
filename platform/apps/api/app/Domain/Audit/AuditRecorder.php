<?php

namespace App\Domain\Audit;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Organizations\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AuditRecorder
{
    private const SENSITIVE_KEYS = [
        'password', 'password_confirmation', 'token', 'token_hash', 'access_token',
        'refresh_token', 'authorization', 'cookie', 'medical_data', 'health_data',
    ];

    public function __construct(
        private AuthFactory $auth,
        private TenantContext $tenantContext,
        private Request $request,
    ) {}

    /** @param array<string, mixed> $safeMetadata */
    public function record(
        AuditAction $action,
        AuditableReference $subject,
        array $safeMetadata = [],
    ): void {
        $actorId = $this->auth->guard('web')->id();

        $organizationId = $this->tenantContext->hasOrganization()
            ? $this->tenantContext->organizationId()
            : null;

        DB::transaction(function () use ($action, $actorId, $organizationId, $safeMetadata, $subject): void {
            if (DB::getDriverName() === 'pgsql') {
                DB::select("SELECT set_config('app.current_user_id', ?, true)", [is_string($actorId) ? $actorId : '']);
                DB::select("SELECT set_config('app.current_organization_id', ?, true)", [$organizationId ?? '']);
            }

            AuditEvent::query()->create([
                'organization_id' => $organizationId,
                'actor_id' => is_string($actorId) ? $actorId : null,
                'action' => $action->value,
                'subject_type' => $subject->type,
                'subject_id' => $subject->id,
                'correlation_id' => (string) ($this->request->attributes->get('correlation_id') ?: Str::uuid()),
                'metadata' => $this->sanitize($safeMetadata),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function sanitize(array $metadata): array
    {
        $safe = [];

        foreach ($metadata as $key => $value) {
            if (in_array(Str::lower((string) $key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $safe[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $safe;
    }
}
