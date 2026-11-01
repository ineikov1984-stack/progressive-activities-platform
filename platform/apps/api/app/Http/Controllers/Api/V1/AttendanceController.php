<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Attendance\Actions\RecordAttendance;
use App\Domain\Attendance\Actions\RecordAttendanceSession;
use App\Domain\Attendance\Services\AttendanceAccess;
use App\Domain\Attendance\Services\AttendanceSheet;
use App\Domain\Attendance\Services\AttendanceToday;
use App\Domain\Catalog\Models\Session;
use App\Domain\Catalog\Services\AccessScope;
use App\Domain\Catalog\Services\ScheduleReader;
use App\Domain\Catalog\ValueObjects\ScheduleScope;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Presenters\AttendancePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttendanceController extends Controller
{
    public function today(Request $request, AttendanceToday $today): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($today->today($user));
    }

    public function pending(Request $request, AttendanceToday $today): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json($today->pending($user));
    }

    public function show(Request $request, string $session, AttendanceAccess $access, AttendanceSheet $sheet, AccessScope $scope, ScheduleReader $reader): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = Session::query()->findOrFail(strtolower($session));
        abort_unless($access->canView($user, $record), 404, 'Заниманието не е намерено.');
        $panel = $reader->panel(ScheduleScope::everything(), (string) $record->id);
        abort_if($panel === null, 404, 'Заниманието не е намерено.');
        $cards = $scope->scheduleChildren($user);

        return response()->json(AttendancePresenter::sheet($panel, $sheet->forSession($record), $access->abilities($user, $record),
            $cards === null || in_array((string) $record->group_id, $cards, true),
            $scope->allowsInScope($user, 'attendance.view_month', (string) $record->group_id),
            $scope->allowsEverywhere($user, 'schedule.manage')));
    }

    public function store(Request $request, string $session, RecordAttendance $action, RecordAttendanceSession $sessionAction, AttendanceAccess $access, AttendanceSheet $sheet, AccessScope $scope, ScheduleReader $reader): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $record = Session::query()->findOrFail(strtolower($session));
        $result = in_array($request->input('action'), ['topic', 'not_held', 'held'], true)
            ? $sessionAction->handle($user, $record, $request->all())
            : $action->handle($user, $record, $request->all(), $request->header('Idempotency-Key', ''));
        if ($result['retry'] ?? false) {
            return response()->json(['message' => $result['message'], 'retry' => true], 409);
        }
        $body = $this->show($request, $session, $access, $sheet, $scope, $reader)->getData(true);
        $status = $result['status'];
        unset($result['status']);

        return response()->json([...$body, ...$result], $status);
    }
}
