<?php

namespace App\Support;

/**
 * Times as people read them in India: the 12-hour clock ("5:30 AM").
 * Stored and sent to the apps as "HH:MM"; this is only for showing.
 */
final class Clock
{
    /** "05:30", "17:30:00" → "5:30 AM", "5:30 PM"; null when it is not a time. */
    public static function twelve(?string $time): ?string
    {
        if ($time === null || ! preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m) || (int) $m[1] > 23) {
            return null;
        }
        $h = (int) $m[1];

        return sprintf('%d:%s %s', $h % 12 === 0 ? 12 : $h % 12, $m[2], $h < 12 ? 'AM' : 'PM');
    }
}
