<?php

namespace App\Support\OfficialSite;

use App\Models\Temple;
use App\Models\TemplePuja;
use App\Models\TempleTiming;
use Illuminate\Support\Facades\DB;

/**
 * Reads a temple's own website into `official_import`, and puts into the
 * listing only what a staff member ticked on the review screen.
 */
final class OfficialSiteImport
{
    /** Reads the site and keeps what was found for review. Returns the reading. */
    public static function read(Temple $temple, ?string $url = null): array
    {
        $url ??= $temple->official_website;
        $found = OfficialSiteReader::read((string) $url);

        if (! isset($found['error'])) {
            $temple->forceFill([
                'official_import' => $found,
                'official_import_at' => now(),
                'official_import_reviewed_at' => null,
            ]);
            if (blank($temple->official_website)) {
                $temple->official_website = $found['source_url'];
            }
            $temple->saveQuietly();
        }

        return $found;
    }

    /** A one-line account of what a reading holds. */
    public static function summary(?array $found): string
    {
        if (! $found) {
            return 'Nothing read yet';
        }

        return collect([
            count($found['timings'] ?? []) ? count($found['timings']).' timings' : null,
            count($found['sevas'] ?? []) ? count($found['sevas']).' sevas with fees' : null,
            count($found['phones'] ?? []) ? 'phone' : null,
            count($found['emails'] ?? []) ? 'email' : null,
            isset($found['pincode']) ? 'PIN code' : null,
            isset($found['latitude']) ? 'map location' : null,
        ])->filter()->implode(', ') ?: 'No details found on the site';
    }

    /**
     * Applies what was ticked.
     *
     * @param  array<string, mixed>  $data  form state: use_* toggles, edited values, picked timing and seva indexes
     * @return array<int, string> what changed, for the message
     */
    public static function apply(Temple $temple, array $data): array
    {
        $found = $temple->official_import ?? [];
        $done = [];

        DB::transaction(function () use ($temple, $data, $found, &$done): void {
            $fields = [];
            foreach (['contact_phone', 'contact_email', 'address', 'pincode', 'short_description'] as $field) {
                if (! empty($data['use_'.$field]) && filled($data[$field] ?? null)) {
                    $fields[$field] = $data[$field];
                }
            }
            if (! empty($data['use_location']) && is_numeric($data['latitude'] ?? null) && is_numeric($data['longitude'] ?? null)) {
                $fields['latitude'] = (float) $data['latitude'];
                $fields['longitude'] = (float) $data['longitude'];
            }
            if ($fields !== []) {
                $temple->fill($fields);
                $done[] = count($fields).' '.(count($fields) === 1 ? 'detail' : 'details');
            }

            // The source of what was taken, so the listing says where it is from.
            $temple->forceFill([
                'source_name' => $temple->source_name ?: 'Official website',
                'source_url' => $found['source_url'] ?? $temple->source_url,
                'official_import_reviewed_at' => now(),
                'last_verified_at' => $fields !== [] ? now() : $temple->last_verified_at,
            ])->save();

            $timings = collect($data['timings'] ?? [])->map(fn ($i) => $found['timings'][(int) $i] ?? null)->filter();
            if ($timings->isNotEmpty()) {
                if (! empty($data['replace_timings'])) {
                    $temple->timings()->delete();
                }
                $order = (int) $temple->timings()->max('sort_order');
                foreach ($timings as $t) {
                    TempleTiming::create([
                        'temple_id' => $temple->id, 'kind' => $t['kind'] ?? 'general', 'label' => $t['label'],
                        'opens_at' => $t['opens_at'], 'closes_at' => $t['closes_at'], 'sort_order' => ++$order,
                    ]);
                }
                $done[] = $timings->count().' timings';
            }

            $sevas = collect($data['sevas'] ?? [])->map(fn ($i) => $found['sevas'][(int) $i] ?? null)->filter();
            $added = 0;
            foreach ($sevas as $s) {
                $existing = $temple->pujas()->whereRaw('lower(name) = ?', [mb_strtolower($s['name'])])->first();
                if ($existing) {
                    $existing->update(['fee_amount' => $s['fee'], 'is_free' => false]);
                } else {
                    TemplePuja::create([
                        'temple_id' => $temple->id, 'kind' => 'seva', 'name' => $s['name'],
                        'fee_amount' => $s['fee'], 'is_free' => false,
                        // Listed for devotees only when staff said so; in-app
                        // booking stays off until the temple turns it on.
                        'is_published' => ! empty($data['publish_sevas']),
                        'app_booking_enabled' => false,
                    ]);
                }
                $added++;
            }
            if ($added) {
                $done[] = $added.' sevas';
            }
        });

        return $done;
    }
}
