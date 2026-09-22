<?php

namespace App\Support;

use BackedEnum;

/**
 * Reads an enum out of Filament form state.
 *
 * Form state carries an enum INSTANCE when it comes from a field default or a
 * cast model attribute, and a plain STRING when it comes from user input. The
 * obvious `SomeEnum::tryFrom((string) $get('field'))` works for the second and
 * throws a TypeError for the first, because a backed enum cannot be cast to
 * string.
 *
 * That mistake reached production once and was then repeated in three more
 * places, each time in a `visible()` or `required()` closure that only runs
 * for particular field values — so it stays invisible until a specific
 * combination is opened. This helper exists so the decision is made once.
 */
class FormState
{
    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @return TEnum|null
     */
    public static function enum(string $enum, mixed $state): ?BackedEnum
    {
        if ($state instanceof $enum) {
            return $state;
        }

        // Another enum entirely, or any other object: not this enum's value,
        // and casting it would throw rather than return null.
        if (is_object($state)) {
            return null;
        }

        if ($state === null || $state === '') {
            return null;
        }

        if (! is_string($state) && ! is_int($state)) {
            return null;
        }

        return $enum::tryFrom($state);
    }
}
