<?php

namespace Tests\Unit;

use App\Enums\DevotionalMediaType;
use App\Enums\PhotoCategory;
use App\Enums\TempleStatus;
use App\Support\FormState;
use PHPUnit\Framework\TestCase;

/**
 * Filament form state carries an enum instance in some paths and a string in
 * others. Reading it the obvious way throws for the first, which took the
 * live admin panel down.
 */
class FormStateTest extends TestCase
{
    public function test_it_accepts_an_enum_instance(): void
    {
        // The case that threw: (string) on a backed enum is a TypeError.
        $this->assertSame(
            DevotionalMediaType::Song,
            FormState::enum(DevotionalMediaType::class, DevotionalMediaType::Song),
        );
    }

    public function test_it_accepts_the_string_value(): void
    {
        $this->assertSame(
            DevotionalMediaType::Song,
            FormState::enum(DevotionalMediaType::class, 'song'),
        );
    }

    public function test_it_returns_null_for_empty_state(): void
    {
        $this->assertNull(FormState::enum(DevotionalMediaType::class, null));
        $this->assertNull(FormState::enum(DevotionalMediaType::class, ''));
    }

    public function test_it_returns_null_for_an_unknown_value(): void
    {
        $this->assertNull(FormState::enum(DevotionalMediaType::class, 'gramophone'));
    }

    public function test_an_enum_of_another_type_is_not_coerced(): void
    {
        // Must return null rather than throwing on the cast, which is what
        // made the original expression fragile.
        $this->assertNull(FormState::enum(DevotionalMediaType::class, TempleStatus::Draft));
    }

    public function test_it_ignores_arrays_and_other_objects(): void
    {
        $this->assertNull(FormState::enum(PhotoCategory::class, ['gallery']));
        $this->assertNull(FormState::enum(PhotoCategory::class, new \stdClass()));
    }

    public function test_it_works_for_every_enum_the_forms_use(): void
    {
        foreach ([DevotionalMediaType::cases(), PhotoCategory::cases(), TempleStatus::cases()] as $cases) {
            foreach ($cases as $case) {
                $class = $case::class;

                $this->assertSame($case, FormState::enum($class, $case));
                $this->assertSame($case, FormState::enum($class, $case->value));
            }
        }
    }
}
