<?php

namespace App\Support\TempleAgent;

use App\Models\State;

/**
 * Finds a state's notable temples and researches each one from the web.
 * The Claude implementation does the real work; tests bind a fake.
 */
interface TempleResearcher
{
    /**
     * The state's most visited or most significant temples that are not
     * among those already listed.
     *
     * @param  array<int, string>  $listed  names (and other names) the site already has
     * @return array<int, array{name: string, city: ?string, district: ?string, why: ?string}>
     */
    public function discover(State $state, array $listed, int $limit): array;

    /**
     * One temple's full details, in the shape of database/data/temples/*.json,
     * or null when it could not be researched.
     *
     * @param  array{name: string, city?: ?string, district?: ?string}  $candidate
     * @return array<string, mixed>|null
     */
    public function research(State $state, array $candidate): ?array;
}
