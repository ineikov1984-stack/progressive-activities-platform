<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Enrollment\Models\Child;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class ParentChildController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $children = $this->children($request)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return response()->json(['children' => $children->map($this->present(...))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $child = $this->children($request)->create($this->validated($request));

        return response()->json(['child' => $this->present($child)], Response::HTTP_CREATED);
    }

    public function show(Request $request, string $child): JsonResponse
    {
        $record = $this->resolve($request, $child);
        Gate::forUser($request->user())->authorize('viewChild', $record);

        return response()->json(['child' => $this->present($record)]);
    }

    public function update(Request $request, string $child): JsonResponse
    {
        $record = $this->resolve($request, $child);
        Gate::forUser($request->user())->authorize('manageChild', $record);
        $validated = $this->validated($request, true);
        $expectedVersion = $validated['version'];
        unset($validated['version']);

        $updated = $this->children($request)
            ->whereKey($record->id)
            ->where('version', $expectedVersion)
            ->update([
                ...$validated,
                'version' => $expectedVersion + 1,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            return response()->json([
                'message' => 'Профилът е променен междувременно. Презаредете и опитайте отново.',
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(['child' => $this->present($record->refresh())]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $update = false): array
    {
        $required = $update ? 'sometimes' : 'required';

        return $request->validate([
            'version' => $update ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'first_name' => [$required, 'required', 'string', 'max:100'],
            'last_name' => [$required, 'required', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'school_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'class_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'institution_group' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => $update
                ? ['sometimes', Rule::in(['active', 'archived'])]
                : ['prohibited'],
        ]);
    }

    private function resolve(Request $request, string $child): Child
    {
        return $this->children($request)->findOrFail($child);
    }

    /** @return HasMany<Child, User> */
    private function children(Request $request): HasMany
    {
        return $request->user()->children();
    }

    /** @return array<string, mixed> */
    private function present(Child $child): array
    {
        return [
            'id' => $child->id,
            'owner_user_id' => $child->owner_user_id,
            'first_name' => $child->first_name,
            'last_name' => $child->last_name,
            'birth_date' => $child->birth_date?->format('Y-m-d'),
            'school_name' => $child->school_name,
            'class_label' => $child->class_label,
            'institution_group' => $child->institution_group,
            'status' => $child->status,
            'version' => $child->version,
        ];
    }
}
