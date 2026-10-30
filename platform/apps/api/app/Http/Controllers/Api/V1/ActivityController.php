<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Catalog\Actions\CatalogMediaStore;
use App\Domain\Catalog\Actions\CreateActivityDraft;
use App\Domain\Catalog\Actions\UpdateActivityDraft;
use App\Domain\Catalog\Enums\EnrollmentMode;
use App\Domain\Catalog\Enums\PriceUnit;
use App\Domain\Catalog\Models\Activity;
use App\Domain\Catalog\Models\Offering;
use App\Domain\Organizations\Models\Organization;
use App\Domain\Organizations\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ActivityController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $organization = $this->organization($context);
        Gate::forUser($request->user())->authorize('catalog.view', $organization);

        return response()->json([
            'activities' => Activity::query()->with('offerings.priceOptions', 'offerings.paymentTermsChoices')->latest()->get()
                ->map(fn (Activity $activity): array => $this->present($activity, $organization))->all(),
        ]);
    }

    public function store(Request $request, TenantContext $context, CreateActivityDraft $action, CatalogMediaStore $media): JsonResponse
    {
        $organization = $this->organization($context);
        Gate::forUser($request->user())->authorize('catalog.manage', $organization);
        $validated = $this->validated($request, $organization);
        $this->validateImages($validated, $media);
        $activity = $action->handle($request->user(), $organization, $validated);

        return response()->json(['activity' => $this->present($activity, $organization)], Response::HTTP_CREATED);
    }

    public function show(Request $request, TenantContext $context, string $activity): JsonResponse
    {
        $organization = $this->organization($context);
        Gate::forUser($request->user())->authorize('catalog.view', $organization);
        $record = Activity::query()->with('offerings.priceOptions', 'offerings.paymentTermsChoices')->findOrFail($activity);

        return response()->json(['activity' => $this->present($record, $organization)]);
    }

    public function update(
        Request $request,
        TenantContext $context,
        string $activity,
        UpdateActivityDraft $action,
        CatalogMediaStore $media,
    ): JsonResponse {
        $organization = $this->organization($context);
        Gate::forUser($request->user())->authorize('catalog.manage', $organization);
        $record = Activity::query()->findOrFail($activity);
        $validated = $this->validated($request, $organization, true);
        $this->validateImages($validated, $media, $record->id);
        $updated = $action->handle($request->user(), $record, $validated['version'], $validated);

        return response()->json(['activity' => $this->present($updated, $organization)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Organization $organization, bool $update = false): array
    {
        $core = $update ? 'sometimes' : 'required';
        $tenant = $organization->getKey();

        return $request->validate([
            'version' => $update ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'title' => [$core, 'required', 'string', 'max:255'],
            'category' => [$core, 'required', 'string', 'max:100'],
            'summary' => [$core, 'required', 'string'],
            'age_label' => [$core, 'required', 'string', 'max:100'],
            'hero_image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sections' => ['sometimes', 'array:about,benefits,methodology,participation_terms,faq,gallery,instructor_intro'],
            'sections.about' => ['sometimes', 'nullable', 'string'],
            'sections.benefits' => ['sometimes', 'nullable', 'array'],
            'sections.benefits.*' => ['required', 'string', 'max:500'],
            'sections.methodology' => ['sometimes', 'nullable', 'string'],
            'sections.participation_terms' => ['sometimes', 'nullable', 'string'],
            'sections.faq' => ['sometimes', 'nullable', 'array'],
            'sections.faq.*' => ['array:question,answer'],
            'sections.faq.*.question' => ['required', 'string', 'max:500'],
            'sections.faq.*.answer' => ['required', 'string'],
            'sections.gallery' => ['sometimes', 'nullable', 'array'],
            'sections.gallery.*' => ['array:path,alt'],
            'sections.gallery.*.path' => ['required', 'string', 'max:255'],
            'sections.gallery.*.alt' => ['required', 'string', 'max:255'],
            'sections.instructor_intro' => ['sometimes', 'nullable', 'string'],
            'offering' => ['sometimes', 'array'],
            'offering.location_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('locations', 'id')->where('organization_id', $tenant)],
            'offering.lead_instructor_profile_id' => ['sometimes', 'nullable', 'uuid', Rule::exists('instructor_profiles', 'id')->where('organization_id', $tenant)],
            'offering.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'offering.description' => ['sometimes', 'nullable', 'string'],
            'offering.terms' => ['sometimes', 'nullable', 'string'],
            'offering.cancellation_policy' => ['sometimes', 'nullable', 'string'],
            'offering.contact_email' => ['sometimes', 'nullable', 'email', 'max:254'],
            'offering.enrollment_instructions' => ['sometimes', 'nullable', 'string'],
            // A set for each way of paying the activity has (the organization's own, active); checked against
            // the prices in SyncPaymentTermsChoices. Left out or null — the organization's terms.
            'offering.payment_terms' => ['sometimes', 'array:monthly,full_period,package,visit'],
            'offering.payment_terms.*' => ['nullable', 'uuid', Rule::exists('payment_terms', 'id')->where('organization_id', $tenant)->where('status', 'active')],
            'offering.price_options' => ['sometimes', 'array', 'max:20'],
            'offering.price_options.*' => ['array:enrollment_mode,unit,amount_cents,currency,terms,is_available,cancellation_policy,payment_due'],
            'offering.price_options.*.enrollment_mode' => ['required', Rule::enum(EnrollmentMode::class), 'distinct'],
            'offering.price_options.*.unit' => ['required', Rule::enum(PriceUnit::class)],
            // Only a trial may be free (docs/product/visits.md, т. 5.5).
            'offering.price_options.*.amount_cents' => ['required', 'integer', 'min:0', function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                $mode = $request->input(str_replace('.amount_cents', '.enrollment_mode', $attribute));
                if (is_numeric($value) && (int) $value === 0 && $mode !== 'trial') {
                    $fail('Безплатно може да е само пробното занимание.');
                }
            }],
            'offering.price_options.*.currency' => ['sometimes', 'required', Rule::in(['EUR'])],
            'offering.price_options.*.terms' => ['sometimes', 'nullable', 'string'],
            'offering.price_options.*.is_available' => ['sometimes', 'boolean'],
            'offering.price_options.*.cancellation_policy' => ['sometimes', 'nullable', 'string'],
            'offering.price_options.*.payment_due' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], ['offering.payment_terms.*.exists' => 'Изберете условия от „Такси, отсъствия и отказ“.']);
    }

    /** @param array<string, mixed> $payload */
    private function validateImages(array $payload, CatalogMediaStore $media, ?string $activityId = null): void
    {
        $hero = $payload['hero_image_path'] ?? null;
        if ($hero !== null && ! $media->isAttachable($hero, $activityId)) {
            throw ValidationException::withMessages(['hero_image_path' => 'Изберете изображение, качено в тази организация.']);
        }

        foreach ($payload['sections']['gallery'] ?? [] as $index => $image) {
            if (! $media->isAttachable($image['path'], $activityId)) {
                throw ValidationException::withMessages(["sections.gallery.{$index}.path" => 'Изберете изображение, качено в тази организация.']);
            }
        }
    }

    private function organization(TenantContext $context): Organization
    {
        return Organization::query()->findOrFail($context->organizationId());
    }

    /** @return array<string, mixed> */
    private function present(Activity $activity, Organization $organization): array
    {
        /** @var Offering|null $offering */
        $offering = $activity->offerings->sortBy('created_at')->first();

        return [
            'id' => $activity->id,
            'slug' => $activity->slug,
            'status' => $activity->status->value,
            'version' => $activity->version,
            'title' => $activity->title,
            'category' => $activity->category,
            'summary' => $activity->summary,
            'age_label' => $activity->age_label,
            'hero_image_path' => $activity->hero_image_path,
            'sections' => [
                'about' => $activity->about,
                'benefits' => $activity->benefits,
                'methodology' => $activity->methodology,
                'participation_terms' => $activity->participation_terms,
                'faq' => $activity->faq,
                'gallery' => $activity->gallery,
                'instructor_intro' => $activity->instructor_intro,
            ],
            'offering' => $offering ? [
                'id' => $offering->id,
                'version' => $offering->version,
                'location_id' => $offering->location_id,
                'lead_instructor_profile_id' => $offering->lead_instructor_profile_id,
                'title' => $offering->title,
                'description' => $offering->description,
                'terms' => $offering->terms,
                'cancellation_policy' => $offering->cancellation_policy,
                'contact_email' => $offering->contact_email,
                // Shown when the activity has no address of its own; parents' questions and emails go there.
                'organization_contact_email' => $organization->public_contact_email,
                'enrollment_instructions' => $offering->enrollment_instructions,
                // The set chosen for each way of paying; a missing one uses the organization's terms.
                'payment_terms' => (object) $offering->paymentTermsByKind(),
                'price_options' => $offering->priceOptions->whereNull('group_id')->values()->map(fn ($price) => [
                    'id' => $price->id,
                    'enrollment_mode' => $price->enrollment_mode,
                    'unit' => $price->unit,
                    'amount_cents' => $price->amount_cents,
                    'currency' => $price->currency,
                    'terms' => $price->terms,
                    'is_available' => $price->is_available,
                    'cancellation_policy' => $price->cancellation_policy,
                    'payment_due' => $price->payment_due,
                ])->all(),
            ] : null,
        ];
    }
}
