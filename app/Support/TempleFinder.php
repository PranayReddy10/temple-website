<?php

namespace App\Support;

use App\Models\Temple;
use Illuminate\Support\Collection;

/**
 * Finding the temple someone meant from an address that is not quite right:
 * a slug typed by hand, shared from an older listing, or cut short by a
 * messaging app ("…-swamy-temple" for "…-swamy-temple-mattapalli").
 */
final class TempleFinder
{
    /** Words every other temple's name shares, which say nothing about which one. */
    private const COMMON = ['sri', 'shri', 'sree', 'swamy', 'swami', 'temple', 'mandir', 'devasthanam', 'kovil', 'gudi', 'the', 'and', 'of'];

    /**
     * The one published temple the slug clearly means, or null when there
     * is no single clear match (then the 404 page offers suggestions).
     */
    public static function closest(string $slug): ?Temple
    {
        $scored = self::scored($slug, 10);
        $best = $scored->first();

        if ($best === null || $best['score'] < 0.6) {
            return null;
        }
        // Two equally good answers: let the devotee choose.
        if ($scored->count() > 1 && $scored->get(1)['score'] >= $best['score']) {
            return null;
        }

        return $best['temple'];
    }

    /** @return Collection<int, Temple> Published temples like the slug or words, best first. */
    public static function suggestions(string $slugOrWords, int $limit = 6): Collection
    {
        return self::scored($slugOrWords, $limit)->pluck('temple');
    }

    /** @return Collection<int, array{temple: Temple, score: float}> */
    private static function scored(string $text, int $limit): Collection
    {
        $words = self::words($text);
        if ($words === []) {
            return collect();
        }

        $candidates = Temple::query()->published()
            ->where(function ($q) use ($words, $text) {
                $q->where('slug', 'like', str_replace(['%', '_'], '', strtolower(trim($text))).'%');
                foreach ($words as $w) {
                    $q->orWhere('slug', 'like', '%'.$w.'%')->orWhere('name', 'like', '%'.$w.'%');
                }
            })
            ->with(['state:id,name', 'deity:id,name', 'primaryPhoto'])
            ->limit(200)
            ->get();

        return $candidates
            ->map(function (Temple $t) use ($words) {
                $theirs = self::words($t->slug.' '.$t->name.' '.$t->city);
                $shared = count(array_intersect($words, $theirs));

                // How much of what was asked for this temple covers.
                return ['temple' => $t, 'score' => $shared / count($words)];
            })
            ->filter(fn ($r) => $r['score'] > 0)
            ->sortByDesc(fn ($r) => [$r['score'], (int) $r['temple']->is_featured])
            ->values()
            ->take($limit);
    }

    /** @return list<string> */
    private static function words(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/', strtolower($text)) ?: [];

        return array_values(array_unique(array_filter(
            $parts,
            fn ($w) => strlen($w) >= 3 && ! in_array($w, self::COMMON, true),
        )));
    }
}
