<?php

namespace App\Support;

use App\Models\Setting;

/**
 * One name everywhere.
 *
 * The brand name set under Administration → Settings used to rename only the
 * admin panel, while the website's pages, receipts and the payment screen
 * kept the .env name — so a renamed app still said its old name in its own
 * privacy policy. Folded over config at boot, like the storage and mail
 * settings, and as safe on a fresh database.
 */
final class BrandName
{
    public static function apply(): void
    {
        $brand = Setting::get('brand_name');

        if (filled($brand)) {
            config(['brand.name' => (string) $brand]);
        }
    }
}
