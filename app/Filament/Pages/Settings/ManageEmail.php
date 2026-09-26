<?php

namespace App\Filament\Pages\Settings;

use App\Support\MailSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Outgoing email (SMTP): what password reset codes are sent through.
 *
 * Any SMTP service works: Hostinger mail, Gmail with an app password, Zoho,
 * Brevo, Amazon SES. Blank fields fall back to MAIL_* in .env.
 */
class ManageEmail extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|\UnitEnum|null $navigationGroup = 'App';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Email (SMTP)';

    protected static ?string $slug = 'app/email';

    protected static function definitions(): array
    {
        return [
            'mail_host' => ['string', null],
            'mail_port' => ['integer', 587],
            'mail_encryption' => ['string', 'tls'],
            'mail_username' => ['string', null],
            'mail_password' => ['secret', null],
            'mail_from_address' => ['string', null],
            'mail_from_name' => ['string', null],
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('SMTP server')
                ->description('For Hostinger email: host smtp.hostinger.com, port 465, SSL, and the mailbox address and password. For Gmail: smtp.gmail.com, 587, TLS, and an app password.')
                ->icon('heroicon-o-server')
                ->columns(2)
                ->schema([
                    TextInput::make('mail_host')->label('Host')->placeholder('smtp.hostinger.com'),
                    TextInput::make('mail_port')->label('Port')->numeric()->placeholder('587'),
                    Select::make('mail_encryption')->label('Encryption')
                        ->options(['tls' => 'TLS / STARTTLS (usually 587)', 'ssl' => 'SSL (usually 465)'])
                        ->default('tls')->selectablePlaceholder(false),
                    TextInput::make('mail_username')->label('Username')->autocomplete('off'),
                    static::secretInput('mail_password', 'Password'),
                ]),

            Section::make('Sender')
                ->icon('heroicon-o-user')
                ->columns(2)
                ->schema([
                    TextInput::make('mail_from_address')->label('From address')->email()->placeholder('no-reply@example.com')
                        ->helperText('Most providers require this to be the mailbox you sign in with, or a domain verified with them.'),
                    TextInput::make('mail_from_name')->label('From name')->placeholder(config('brand.name')),
                ]),
        ]);
    }

    public function save(): void
    {
        parent::save();

        // So the test below uses what was just saved, not the boot-time copy.
        MailSettings::apply();
        Mail::purge();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('test')
                ->label('Send a test email')
                ->icon('heroicon-o-paper-airplane')
                ->schema([
                    TextInput::make('to')->label('Send to')->email()->required()->default(fn () => Auth::user()?->email),
                ])
                ->action(function (array $data): void {
                    MailSettings::apply();
                    Mail::purge();

                    if (! MailSettings::configured()) {
                        Notification::make()->title('Email is not set up')->body('Fill in the SMTP host and save first.')->warning()->send();

                        return;
                    }

                    try {
                        Mail::raw('This is a test from '.config('brand.name').'. Outgoing email works, so password reset codes will reach devotees.', function ($m) use ($data): void {
                            $m->to($data['to'])->subject('Test email');
                        });
                        Notification::make()->title('Sent')->body('Check '.$data['to'].' (and its spam folder).')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Could not send')->body($e->getMessage())->danger()->persistent()->send();
                    }
                }),
        ];
    }
}
