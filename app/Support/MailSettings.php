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
 *
 * Most email services, whoever runs them, take mail over SMTP: the presets
 * below only fill in their server, port and encryption. Amazon SES can also
 * be reached through its API with an access key, through the AWS SDK the
 * media storage already installs.
 */
final class MailSettings
{
    /**
     * provider => label, SMTP server (null: not SMTP), and what goes in the
     * username and password fields.
     *
     * @var array<string, array{label: string, host: ?string, port: ?int, encryption: ?string, help: string}>
     */
    public const PROVIDERS = [
        'hostinger' => ['label' => 'Hostinger email', 'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl',
            'help' => 'Create the mailbox in hPanel → Emails (e.g. no-reply@darshansaathi.com). Username: the full mailbox address. Password: the mailbox password.'],
        'gmail' => ['label' => 'Gmail / Google Workspace', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Username: the Gmail address. Password: an app password (Google Account → Security → 2-Step Verification → App passwords), not the normal password. About 500 mails a day.'],
        'zoho' => ['label' => 'Zoho Mail', 'host' => 'smtp.zoho.in', 'port' => 465, 'encryption' => 'ssl',
            'help' => 'Username: the Zoho address. Password: an app-specific password from Zoho Accounts → Security. Accounts on zoho.com use smtp.zoho.com.'],
        'outlook' => ['label' => 'Outlook / Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Username: the mailbox address. Password: its password (or an app password when 2-step sign-in is on). SMTP AUTH must be allowed for the mailbox.'],
        'brevo' => ['label' => 'Brevo (Sendinblue)', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Brevo → SMTP & API → SMTP. Username: the SMTP login shown there. Password: an SMTP key. Verify the sender domain in Brevo first. 300 free mails a day.'],
        'sendgrid' => ['label' => 'SendGrid', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Username: the word apikey. Password: a SendGrid API key with Mail Send permission. Verify the sender or domain in SendGrid first.'],
        'mailgun' => ['label' => 'Mailgun', 'host' => 'smtp.mailgun.org', 'port' => 587, 'encryption' => 'tls',
            'help' => 'Mailgun → Sending → Domain settings → SMTP credentials. Username: e.g. postmaster@mg.darshansaathi.com. Password: that credential\'s password. EU accounts use smtp.eu.mailgun.org.'],
        'ses_smtp' => ['label' => 'Amazon SES (SMTP)', 'host' => 'email-smtp.ap-south-1.amazonaws.com', 'port' => 587, 'encryption' => 'tls',
            'help' => 'SES → SMTP settings → Create SMTP credentials. Use the SMTP username and password it gives (not your AWS keys). Change the region in the host if yours is not Mumbai.'],
        'ses' => ['label' => 'Amazon SES (API, access key)', 'host' => null, 'port' => null, 'encryption' => null,
            'help' => 'Sends through the SES API instead of SMTP. Needs an IAM access key allowed ses:SendRawEmail, and a verified sender domain in that region. New SES accounts can only mail verified addresses until you ask AWS for production access.'],
        'smtp' => ['label' => 'Other SMTP server', 'host' => null, 'port' => 587, 'encryption' => 'tls',
            'help' => 'Any SMTP service: fill in the server, port, encryption, username and password it gives you.'],
        'log' => ['label' => 'Do not send (write to the log)', 'host' => null, 'port' => null, 'encryption' => null,
            'help' => 'Nothing leaves the server: every mail is written to storage/logs/laravel.log. For testing only; devotees will not receive reset codes.'],
    ];

    public static function apply(): void
    {
        try {
            $provider = Setting::get('mail_provider');
            $host = Setting::get('mail_host');
        } catch (Throwable) {
            return;
        }

        if ($provider === 'ses') {
            Config::set('mail.default', 'ses');
            Config::set('services.ses.key', Setting::get('mail_ses_key'));
            Config::set('services.ses.secret', Setting::secret('mail_ses_secret'));
            Config::set('services.ses.region', Setting::get('mail_ses_region') ?: 'ap-south-1');
        } elseif ($provider === 'log') {
            Config::set('mail.default', 'log');
        } elseif (filled($host)) {
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
