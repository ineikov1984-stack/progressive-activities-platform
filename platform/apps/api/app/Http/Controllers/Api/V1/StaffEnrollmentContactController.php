<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\ValueObjects\AuditableReference;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\EnrollmentApplication;
use App\Domain\Enrollment\Services\ContactWindow;
use App\Domain\Identity\Models\User;
use App\Domain\Organizations\Enums\OrganizationRole;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ParentContactPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * "Покажи телефона" (docs/product/team-rights.md, т. 6): the parent's phone and e-mail for one application or
 * enrollment, to whoever sees contacts in its group — a POST, because it is written down, never cached. In this order:
 * 1. the record is found inside the organization — another organization's is 404;
 * 2. "contacts.view" in the record's group — otherwise 403;
 * 3. the contacts' window (enrollment-contacts-and-emails.md, т. 3.1) — otherwise 404.
 * At most 30 a hour and 100 a day for a person in an organization; the owner sees them at once and is not counted.
 * The audit holds identifiers only: never the phone, the e-mail or a name.
 */
final class StaffEnrollmentContactController extends Controller
{
    private const PER_HOUR = 30;

    private const PER_DAY = 100;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AccessScope $scope,
        private readonly ContactWindow $window,
        private readonly AuditRecorder $audit,
    ) {}

    public function application(Request $request, string $application): JsonResponse
    {
        $record = EnrollmentApplication::query()->with(['parent', 'enrollment'])->findOrFail($application);

        return $this->reveal($request, $record->group_id, fn (): bool => $this->window->application($record), $record->parent,
            new AuditableReference('enrollment_application', $record->id), $record->child_id);
    }

    public function enrollment(Request $request, string $enrollment): JsonResponse
    {
        $record = Enrollment::query()->with(['application.parent', 'session'])->findOrFail($enrollment);

        return $this->reveal($request, $record->group_id, fn (): bool => $this->window->enrollment($record), $record->application?->parent,
            new AuditableReference('enrollment', $record->id), $record->child_id);
    }

    /** @param callable(): bool $inWindow */
    private function reveal(Request $request, string $groupId, callable $inWindow, ?User $parent, AuditableReference $subject, string $childId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->scope->allows($user, 'contacts.view', $groupId), 403, 'Нямате право да виждате контактите на родителите в тази група.');
        if ($parent === null || ! $inWindow()) {
            abort(404, 'Контактът вече не е достъпен.');
        }

        $access = $this->scope->access($user);
        if ($access->role !== OrganizationRole::OrganizationOwner) {
            $hour = 'contact-reveal:hour:'.$this->context->organizationId().':'.$user->id;
            $day = 'contact-reveal:day:'.$this->context->organizationId().':'.$user->id;
            abort_if(RateLimiter::tooManyAttempts($hour, self::PER_HOUR) || RateLimiter::tooManyAttempts($day, self::PER_DAY),
                429, 'Показахте много контакти за кратко време. Опитайте по-късно.');
            RateLimiter::hit($hour, 3600);
            RateLimiter::hit($day, 86400);
        }

        $from = $request->input('from');
        $this->audit->record(AuditAction::EnrollmentContactRevealed, $subject, [
            'child_id' => $childId,
            'group_id' => $groupId,
            'parent' => 'user:'.$parent->id,
            'role' => $access->role?->value,
            // The right from an added one, so the history tells which.
            'grant_id' => $access->fromRole('contacts.view') ? null : $access->grantId('contacts.view'),
            'from' => in_array($from, ['details', 'roster'], true) ? $from : 'details',
        ]);

        return response()->json(['contact' => ParentContactPresenter::full($parent)])
            ->header('Cache-Control', 'no-store, private');
    }
}
