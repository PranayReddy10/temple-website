<?php

namespace App\Http\Controllers\Api\V1\Trust;

use App\Enums\EventType;
use App\Enums\PhotoCategory;
use App\Enums\PujaKind;
use App\Enums\TimingKind;
use App\Filament\Resources\TempleAccess\Schemas\TempleAccessForm;
use App\Http\Controllers\Controller;
use App\Models\Deity;
use App\Models\State;
use App\Models\TempleSuggestion;
use App\Models\TempleTiming;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;

/**
 * Every choice list the trust app's forms offer, in one request, so a new
 * timing kind or event type reaches the app without a release.
 */
class TrustOptionsController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $enum = fn (string $class): array => collect($class::cases())
            ->map(fn ($case): array => ['value' => $case->value, 'label' => $case->getLabel()])
            ->values()
            ->all();

        return response()->json(['data' => [
            'timing_kinds' => $enum(TimingKind::class),
            'event_types' => $enum(EventType::class),
            'puja_kinds' => $enum(PujaKind::class),
            'photo_categories' => $enum(PhotoCategory::class),
            'days' => collect(TempleTiming::dayNames())
                ->map(fn (string $label, int $value): array => ['value' => $value, 'label' => $label])
                ->values(),
            'claim_levels' => collect(TempleAccessForm::levels())
                ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
                ->values(),
            'registration_roles' => collect(TrustTempleRegistrationController::ROLES)
                ->map(fn (string $value): array => ['value' => $value, 'label' => TempleSuggestion::ROLES[$value]])
                ->values(),
            'deities' => Deity::query()->orderBy('name')->get(['id', 'name']),
            'states' => State::query()->orderBy('name')->get(['id', 'name']),
            'max_registration_photos' => TempleSuggestion::MAX_PHOTOS,
            'upload_max_kb' => [
                'temple_photo' => UploadRules::maxKbFor('temple_photo'),
                'event_image' => UploadRules::maxKbFor('event_image'),
                'puja_image' => UploadRules::maxKbFor('puja_image'),
            ],
        ]]);
    }
}
