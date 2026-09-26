<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * Outgoing mail, set in the admin panel (App → Email) rather than in .env.
 *
 * A setting overrides .env; a blank one falls back to it. Folded over config
 * once at boot, like MediaStorage, so every mailer sees the same answer.
 */
final class MailSettings
{
    public static function apply(): void
    {
        try {
            $host = Setting::get('mail_host');
        } catch (Throwable) {
            return;
        }

        if (filled($host)) {
            Config::set('mail.default', 'smtp');
            Config::set('mail.mailers.smtp.host', $host);
            Config::set('mail.mailers.smtp.port', (int) (Setting::get('mail_port') ?: 587));
            Config::set('mail.mailers.smtp.username', Setting::get('mail_username'));
            Config::set('mail.mailers.smtp.password', Setting::secret('mail_password'));

            // "ssl" is SMTPS on 465; "tls" (STARTTLS, usually 587) is what the
            // smtp scheme negotiates by itself.
            Config::set('mail.mailers.smtp.scheme', Setting::get('mail_encryption') === 'ssl' ? 'smtps' : 'smtp');
        }

        if (filled($from = Setting::get('mail_from_address'))) {
            Config::set('mail.from.address', $from);
        }

        if (filled($name = Setting::get('mail_from_name'))) {
            Config::set('mail.from.name', $name);
        }
    }

    /** Whether mail would reach anyone: not the log or array mailer. */
    public static function configured(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array', null], true);
    }
}
