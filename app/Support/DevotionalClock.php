<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * What day it is for the people using the app.
 *
 * The server keeps UTC, as it should: timestamps are stored and compared in
 * one zone. But "today's deity" is a question about the devotee's calendar,
 * and between midnight and 05:30 in India the server's UTC clock is still on
 * yesterday. Asking it directly put Wednesday's deity on the dashboard well
 * into Thursday morning.
 */
final class DevotionalClock
{
    public static function timezone(): string
    {
        return (string) config('brand.timezone', 'Asia/Kolkata');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    /**
     * The latest date it currently is anywhere on Earth (UTC+14).
     *
     * For "not in the future" checks on dates a device sends: a devotee
     * whose day has already turned must not be told their visit has not
     * happened yet.
     */
    public static function latestDateAnywhere(): string
    {
        return CarbonImmutable::now('Pacific/Kiritimati')->toDateString();
    }
}
