<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\SubmitSubstituteRequest;
use App\Domain\Catalog\Actions\WithdrawSubstituteRequest;
use App\Domain\Catalog\Services\SubstituteRequests;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class TeacherSubstituteRequestController extends Controller
{
    public function index(Request $request, SubstituteRequests $reader): JsonResponse
    {
        return response()->json($reader->mine($this->actor($request, $reader)));
    }

    public function options(Request $request, SubstituteRequests $reader): JsonResponse
    {
        $actor = $this->actor($request, $reader);
        $message = 'Изберете ден от днес до една година напред (най-много 31 дни).';
        $data = ['from' => $request->query('from'), 'to' => $request->query('to', $request->query('from'))];
        $validator = Validator::make($data, ['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);
        if ($validator->fails()) {
            throw ValidationException::withMessages(['from' => $message]);
        }
        $from = CarbonImmutable::parse($data['from'], 'Europe/Sofia')->startOfDay();
        $to = CarbonImmutable::parse($data['to'], 'Europe/Sofia')->startOfDay();
        $today = CarbonImmutable::now('Europe/Sofia')->startOfDay();
        if ($from->lt($today) || $to->lt($from) || $to->gt($from->addDays(31)) || $to->gt($today->addDays(365))) {
            throw ValidationException::withMessages(['from' => $message]);
        }

        return response()->json($reader->options($actor, $data['from'], $data['to']));
    }

    public function store(Request $request, SubstituteRequests $reader, SubmitSubstituteRequest $action): JsonResponse
    {
        $actor = $this->actor($request, $reader);
        $data = $request->validate([
            'sessions' => ['required', 'array', 'min:1', 'max:50'],
            'sessions.*.id' => ['required', 'uuid', 'distinct:ignore_case'],
            'sessions.*.version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'in:vacation,personal,other'],
            'note' => ['nullable', 'string', 'max:300'],
            'suggested_profile_id' => ['nullable', 'uuid'],
        ], [
            'sessions.*' => 'Изберете от 1 до 50 занимания.',
            'reason.*' => 'Изберете причина.',
            'note.*' => 'Бележката е до 300 знака.',
            'suggested_profile_id.*' => 'Изберете колега от списъка.',
        ]);
        $key = trim($request->header('Idempotency-Key', ''));
        if ($key === '' || mb_strlen($key) > 120) {
            throw ValidationException::withMessages(['idempotency_key' => 'Заредете отново и изпратете заявката.']);
        }
        $result = $action->execute($actor, $data['sessions'], $data['reason'], $data['note'] ?? null, $data['suggested_profile_id'] ?? null, $key);

        return response()->json($reader->request($result['request']), $result['replayed'] ? 200 : 201);
    }

    public function withdraw(Request $request, string $substituteRequest, SubstituteRequests $reader, WithdrawSubstituteRequest $action): JsonResponse
    {
        $actor = $this->actor($request, $reader);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']], ['version.*' => 'Заредете отново заявката.']);

        return response()->json($reader->request($action->execute($actor, strtolower($substituteRequest), (int) $data['version'])));
    }

    private function actor(Request $request, SubstituteRequests $reader): User
    {
        /** @var User $actor */
        $actor = $request->user();
        $reader->authorize($actor);

        return $actor;
    }
}
