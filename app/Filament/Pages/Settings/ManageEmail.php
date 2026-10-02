<?php

namespace App\Filament\Pages\Settings;

use App\Support\MailSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Outgoing email: what password reset codes and links are sent through.
 *
 * Pick a provider and its SMTP server, port and encryption fill in; any other
 * SMTP service works as "Other". Amazon SES can go through its API instead.
 * Blank fields fall back to MAIL_* in .env.
 */
class ManageEmail extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-envelope';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 5;

    protected static ?string $title = 'Email';

    protected static ?string $slug = 'app/email';

    protected static function definitions(): array
    {
        return [
            'mail_provider' => ['string', null],
            'mail_host' => ['string', null],
            'mail_port' => ['integer', 587],
            'mail_encryption' => ['string', 'tls'],
            'mail_username' => ['string', null],
            'mail_password' => ['secret', null],
            'mail_from_address' => ['string', null],
            'mail_from_name' => ['string', null],
            'mail_ses_key' => ['string', null],
            'mail_ses_secret' => ['secret', null],
            'mail_ses_region' => ['string', 'ap-south-1'],
        ];
    }

    public function form(Schema $schema): Schema
    {
        $smtp = fn (Get $get): bool => ! in_array($get('mail_provider'), ['ses', 'log'], true);

        return $schema->statePath('data')->components([
            Section::make('Email provider')
                ->description('Who delivers the mail. Choosing one fills in its server; then add the username and password it gives you, save, and send a test.')
                ->icon('heroicon-o-envelope')
                ->schema([
                    Select::make('mail_provider')->label('Provider')
                        ->options(collect(MailSettings::PROVIDERS)->map(fn (array $p): string => $p['label'])->all())
                        ->placeholder('Choose…')
                        ->live()
                        ->afterStateUpdated(function (?string $state, Set $set): void {
                            $preset = MailSettings::PROVIDERS[$state] ?? null;
                            if ($preset === null) {
                                return;
                            }
                            if ($preset['host'] !== null) {
                                $set('mail_host', $preset['host']);
                            }
                            if ($preset['port'] !== null) {
                                $set('mail_port', $preset['port']);
                            }
                            if ($preset['encryption'] !== null) {
                                $set('mail_encryption', $preset['encryption']);
                            }
                        }),
                    Text::make(fn (Get $get): string => MailSettings::PROVIDERS[$get('mail_provider')]['help'] ?? 'No provider chosen: mail uses MAIL_* from .env, or the SMTP server below if one is filled in.'),
                ]),

            Section::make('SMTP server')
                ->icon('heroicon-o-server')
                ->columns(2)
                ->visible($smtp)
                ->schema([
                    TextInput::make('mail_host')->label('Host')->placeholder('smtp.hostinger.com'),
                    TextInput::make('mail_port')->label('Port')->numeric()->placeholder('587'),
                    Select::make('mail_encryption')->label('Encryption')
                        ->options(['tls' => 'TLS / STARTTLS (usually 587)', 'ssl' => 'SSL (usually 465)'])
                        ->default('tls')->selectablePlaceholder(false),
                    TextInput::make('mail_username')->label('Username')->autocomplete('off'),
                    static::secretInput('mail_password', 'Password'),
                ]),

            Section::make('Amazon SES (API)')
                ->icon('heroicon-o-key')
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('mail_provider') === 'ses')
                ->schema([
                    TextInput::make('mail_ses_key')->label('Access key ID')->autocomplete('off'),
                    static::secretInput('mail_ses_secret', 'Secret access key'),
                    Select::make('mail_ses_region')->label('Region')
                        ->options([
                            'ap-south-1' => 'Asia Pacific (Mumbai) ap-south-1',
                            'ap-southeast-1' => 'Asia Pacific (Singapore) ap-southeast-1',
                            'us-east-1' => 'US East (N. Virginia) us-east-1',
                            'eu-west-1' => 'Europe (Ireland) eu-west-1',
                        ])
                        ->default('ap-south-1')->selectablePlaceholder(false),
                ]),

            Section::make('Sender')
                ->icon('heroicon-o-user')
                ->columns(2)
                ->visible(fn (Get $get): bool => $get('mail_provider') !== 'log')
                ->schema([
                    TextInput::make('mail_from_address')->label('From address')->email()->placeholder('no-reply@darshansaathi.com')
                        ->helperText('Most providers require this to be the mailbox you sign in with, or an address on a domain verified with them.'),
                    TextInput::make('mail_from_name')->label('From name')->placeholder(config('brand.name')),
                ]),

            Section::make('What is sent by email')
                ->icon('heroicon-o-information-circle')
                ->collapsible()
                ->schema([
                    UnorderedList::make([
                        Text::make('Devotees: a six-digit code when they tap "Forgot password?" in the app. It expires after the minutes set under App → Sign-in methods.'),
                        Text::make('Admin and temple staff: a reset link from "Forgot password?" on the /admin and /temple sign-in pages.'),
                        Text::make('The test email from the button above.'),
                    ]),
                    Text::make('Until a provider is set up nothing reaches anyone: codes and links are written to the server log instead.'),
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
                        Notification::make()->title('Email is not set up')->body('Choose a provider, fill it in and save first.')->warning()->send();

                        return;
                    }

                    try {
                        Mail::raw('This is a test from '.config('brand.name').'. Outgoing email works, so password reset codes and links will arrive.', function ($m) use ($data): void {
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
