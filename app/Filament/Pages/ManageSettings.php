<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * Site-wide settings, editable without a deploy.
 *
 * Values are seeded from .env and overridden here. Clearing a field falls back
 * to the environment rather than blanking the value, so an accidental empty
 * brand name cannot take the site's name away.
 */
class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Settings';

    protected string $view = 'filament.pages.manage-settings';

    /**
     * Backing store for the form's state.
     *
     * Load-bearing despite looking unused: Filament writes the schema's state
     * into this property, and save() reads it back through getState(). The
     * rendered input names are prefixed with the schema name rather than this
     * path, which makes it look redundant — removing it silently makes every
     * saved value empty.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    /**
     * Each setting: key => [type, config fallback].
     */
    protected const DEFINITIONS = [
        'brand_name' => ['string', 'brand.name'],
        'brand_tagline' => ['string', 'brand.tagline'],
        'support_email' => ['string', 'brand.support_email'],
        'contact_phone' => ['string', null],
        'default_locale' => ['string', 'app.locale'],
        'community_submissions_enabled' => ['boolean', null],
        'temple_self_publish_enabled' => ['boolean', null],
        'maintenance_notice' => ['string', null],
    ];

    /** Only a super admin may change site-wide configuration. */
    public static function canAccess(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }

    public function mount(): void
    {
        $values = [];

        foreach (self::DEFINITIONS as $key => [$type, $configKey]) {
            $stored = Setting::get($key);

            $values[$key] = match (true) {
                $stored !== null => $stored,
                $type === 'boolean' => false,
                $configKey !== null => config($configKey),
                default => null,
            };
        }

        $this->form->fill($values);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Branding')
                    ->description('The product name is not final. Changing it here updates the admin panel, emails and API responses immediately.')
                    ->icon('heroicon-o-sparkles')
                    ->columns(2)
                    ->schema([
                        TextInput::make('brand_name')
                            ->label('Product name')
                            ->maxLength(120)
                            ->placeholder(config('brand.name'))
                            ->helperText('Leave blank to use the value from .env ('.config('brand.name').').'),

                        TextInput::make('brand_tagline')
                            ->label('Tagline')
                            ->maxLength(200)
                            ->placeholder(config('brand.tagline')),
                    ]),

                Section::make('Contact')
                    ->icon('heroicon-o-envelope')
                    ->columns(2)
                    ->schema([
                        TextInput::make('support_email')
                            ->label('Support email')
                            ->email()
                            ->maxLength(255),

                        TextInput::make('contact_phone')
                            ->label('Contact phone')
                            ->tel()
                            ->maxLength(40),
                    ]),

                Section::make('Language')
                    ->icon('heroicon-o-language')
                    ->schema([
                        Select::make('default_locale')
                            ->label('Default language')
                            ->options([
                                'en' => 'English',
                                'te' => 'Telugu',
                                'hi' => 'Hindi',
                            ])
                            ->native(false)
                            ->helperText('Used when a request does not ask for a specific language.'),
                    ]),

                Section::make('Features')
                    ->description('Switches that change behaviour across the platform. Each one is off until the feature it controls has shipped.')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->schema([
                        Toggle::make('community_submissions_enabled')
                            ->label('Accept community submissions')
                            ->helperText('Lets devotees suggest temples and corrections. Everything submitted goes to a moderation queue.'),

                        Toggle::make('temple_self_publish_enabled')
                            ->label('Verified temples may publish without review')
                            ->helperText('When off, every temple-published event waits for staff review, whatever the temple\'s verification level.'),
                    ]),

                Section::make('Maintenance notice')
                    ->icon('heroicon-o-megaphone')
                    ->schema([
                        Textarea::make('maintenance_notice')
                            ->label('Notice shown in the app')
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('Leave blank for no notice. Shown to devotees at the top of the home screen.'),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return array<int, Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save settings')
                ->submit('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (self::DEFINITIONS as $key => [$type, $configKey]) {
            $value = $state[$key] ?? null;

            if ($type === 'boolean') {
                Setting::set($key, $value ? '1' : '0', 'boolean');

                continue;
            }

            // An empty string is stored as null so the config fallback applies,
            // rather than blanking the value everywhere it is read.
            Setting::set($key, filled($value) ? $value : null, $type);
        }

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
