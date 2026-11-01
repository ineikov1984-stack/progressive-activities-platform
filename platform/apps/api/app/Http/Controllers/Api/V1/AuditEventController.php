<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Models\AuditEvent;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * "Последни действия" on the home screen: only the roles that see the team ("team.view" — not the teacher). Who did it,
 * on which record, the details and the request id only for those who manage the team ("team.manage").
 */
class AuditEventController extends Controller
{
    private const TEAM_MANAGE_ONLY = [
        'schedule.substitute_requested', 'schedule.substitute_request_withdrawn',
        'team.access_grant_added', 'team.access_grant_changed', 'team.access_grant_revoked', 'team.access_scope_changed',
        'team.access_group_added', 'team.access_group_removed', 'team.access_template_changed', 'enrollment.contact_revealed',
    ];

    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $organization = Organization::query()->findOrFail($context->organizationId());
        Gate::forUser($request->user())->authorize('viewTeam', $organization);
        $details = Gate::forUser($request->user())->allows('manageTeam', $organization);

        $events = AuditEvent::query()
            ->where('organization_id', $context->organizationId())
            // Who got which rights and whose contact was shown: only for those who manage the team
            // (docs/product/team-rights.md, т. 6).
            ->when(! $details, fn ($query) => $query->whereNotIn('action', self::TEAM_MANAGE_ONLY))
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (AuditEvent $event): array => [
                'id' => $event->id,
                'action' => $event->action,
                'subject_type' => $event->subject_type,
                'created_at' => $event->created_at->toIso8601String(),
            ] + ($details ? [
                'actor_id' => $event->actor_id,
                'subject_id' => $event->subject_id,
                'metadata' => $event->metadata,
                'correlation_id' => $event->correlation_id,
            ] : []));

        return response()->json(['events' => $events]);
    }
}
