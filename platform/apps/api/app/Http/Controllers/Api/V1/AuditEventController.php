<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuditEventController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $organization = Organization::query()->findOrFail($context->organizationId());
        Gate::forUser($request->user())->authorize('view', $organization);

        $events = AuditEvent::query()
            ->where('organization_id', $context->organizationId())
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'actor_id' => $event->actor_id,
                'action' => $event->action,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'metadata' => $event->metadata,
                'correlation_id' => $event->correlation_id,
                'created_at' => $event->created_at->toIso8601String(),
            ]);

        return response()->json(['events' => $events]);
    }
}
