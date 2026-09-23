<?php

namespace App\Filament\Resources\Temples\Schemas;

use App\Enums\TempleStatus;
use App\Enums\VerificationStatus;
use App\Models\Temple;
use App\Models\District;
use App\Filament\Schemas\MantraFields;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use App\Support\FormState;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class TempleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::identitySection(),
                self::coverImageSection(),
                self::locationSection(),
                self::contentSection(),
                self::mantraSection(),
                self::rulesSection(),
                self::facilitiesSection(),
                self::contactSection(),
                self::trustSection(),
                self::publishingSection(),
            ])
            ->columns(1);
    }

    protected static function identitySection(): Section
    {
        return Section::make('Identity')
            ->description('The temple name as it is commonly known, plus the local and alternate names devotees actually search for.')
            ->icon('heroicon-o-building-library')
            ->columns(2)
            ->schema([
                TextInput::make('name')
                    ->label('Temple name')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                        // Keep the slug in step while drafting, but never
                        // silently rewrite the URL of a published temple.
                        if (FormState::enum(TempleStatus::class, $get('status')) !== TempleStatus::Published) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),

                TextInput::make('slug')
                    ->label('URL slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->helperText('Used in the public web address. Changing it on a published temple breaks existing links.')
                    ->disabled(fn (): bool => ! Auth::user()?->isSuperAdmin())
                    ->dehydrated(),

                Select::make('deity_id')
                    ->label('Primary deity')
                    ->relationship('deity', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),

                Select::make('categories')
                    ->label('Pilgrimage circuits and categories')
                    ->relationship('categories', 'name')
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->helperText('For example Jyotirlinga, Shakti Peetha or Char Dham.'),

                Repeater::make('aliases')
                    ->label('Alternate and local names')
                    ->relationship()
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        Select::make('locale')
                            ->label('Language')
                            ->options(self::locales())
                            ->default('en')
                            ->required()
                            ->native(false),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add another name')
                    ->helperText('A devotee searching "Tirupati" should still find Sri Venkateswara Swamy Temple.')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The one photo a devotee sees first.
     *
     * Temples already have a photo gallery, but it lives in a relation
     * manager that only exists after the record is saved — so a new temple
     * could be created, published and listed with no image at all, and the
     * person creating it had no way to tell. This writes the primary photo
     * directly, using the same table the gallery does rather than a second
     * column that could disagree with it.
     */
    protected static function coverImageSection(): Section
    {
        return Section::make('Cover image')
            ->description('Shown on search results, the app home screen and this temple\'s own page. More photos go in the gallery below once the temple is saved.')
            ->icon('heroicon-o-photo')
            ->columns(2)
            ->schema([
                FileUpload::make('cover_image')
                    ->label('Cover photo')
                    ->image()
                    ->imageEditor()
                    ->disk(fn (): string => config('filesystems.media'))
                    ->directory('temples/covers')
                    ->visibility('public')
                    ->maxSize(12288)
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->helperText('Landscape works best: it is cropped to a wide card in the app.')
                    // Not a column on temples: the page strips these two out
                    // and writes them to the primary temple_photos row
                    // instead. See SyncsCoverPhoto.
                    ->columnSpanFull(),

                TextInput::make('cover_image_credit')
                    ->label('Credit')
                    ->maxLength(255)
                    ->helperText('Who the photograph is by. Required for anything we did not take ourselves.')
                    ->columnSpanFull(),
            ]);
    }

    /**
     * The verse a devotee sees when they open this temple.
     *
     * Blank is the normal case and is fine: the temple falls back to its
     * deity's mantra and recording rather than rendering a heading with
     * nothing under it.
     */
    protected static function mantraSection(): Section
    {
        return Section::make('Mantra')
            ->description('Only where this temple has its own. Tirumala has the Suprabhatam; most temples use their deity\'s, which is filled in on the deity record and used here automatically.')
            ->icon('heroicon-o-musical-note')
            ->columns(1)
            ->collapsed()
            ->schema(MantraFields::components(Temple::class, withMeaning: false));
    }

    protected static function locationSection(): Section
    {
        return Section::make('Location')
            ->description('Where the temple is, and the coordinates that power the map and "temples near me".')
            ->icon('heroicon-o-map-pin')
            ->columns(2)
            ->schema([
                Select::make('state_id')
                    ->label('State / Union Territory')
                    ->relationship('state', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('district_id', null)),

                Select::make('district_id')
                    ->label('District')
                    ->options(fn (Get $get): array => $get('state_id')
                        ? District::where('state_id', $get('state_id'))->orderBy('name')->pluck('name', 'id')->all()
                        : [])
                    ->searchable()
                    ->native(false)
                    ->disabled(fn (Get $get): bool => ! $get('state_id'))
                    ->helperText('Choose a state first.'),

                TextInput::make('city')
                    ->label('City / town / village')
                    ->maxLength(255),

                TextInput::make('pincode')
                    ->label('PIN code')
                    ->maxLength(10)
                    ->rule('regex:/^[1-9][0-9]{5}$/')
                    ->helperText('Six digits, not starting with zero.'),

                Textarea::make('address')
                    ->label('Full address')
                    ->rows(3)
                    ->columnSpanFull(),

                TextInput::make('latitude')
                    ->numeric()
                    ->minValue(-90)
                    ->maxValue(90)
                    ->step('0.0000001')
                    ->helperText('Decimal degrees, e.g. 17.3850000.')
                    // Coordinates are only meaningful as a pair, so neither is
                    // accepted without the other.
                    ->requiredWith('longitude'),

                TextInput::make('longitude')
                    ->numeric()
                    ->minValue(-180)
                    ->maxValue(180)
                    ->step('0.0000001')
                    ->helperText('Decimal degrees, e.g. 78.4867000.')
                    ->requiredWith('latitude'),
            ]);
    }

    protected static function contentSection(): Section
    {
        return Section::make('Description and significance')
            ->description('What a devotee should know before visiting.')
            ->icon('heroicon-o-book-open')
            ->columns(2)
            ->collapsed()
            ->schema([
                Textarea::make('short_description')
                    ->label('Short description')
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText('One or two sentences, shown in search results and list cards.')
                    ->columnSpanFull(),

                Textarea::make('history')
                    ->label('History')
                    ->rows(8)
                    ->columnSpanFull(),

                Textarea::make('significance')
                    ->label('Religious significance')
                    ->rows(8)
                    ->columnSpanFull(),

                TextInput::make('architecture_style')
                    ->label('Architecture style')
                    ->maxLength(255)
                    ->helperText('For example Dravidian, Nagara, Hoysala, Kalinga.'),

                TextInput::make('built_period')
                    ->label('Built period')
                    ->maxLength(255)
                    ->helperText('Free text, because many temples are dated only by century or era.'),
            ]);
    }

    protected static function rulesSection(): Section
    {
        return Section::make('Visitor rules')
            ->description('Only rules the temple actually publishes. A guessed dress code can turn a devotee away at the gate.')
            ->icon('heroicon-o-clipboard-document-check')
            ->columns(2)
            ->collapsed()
            ->schema([
                Textarea::make('dress_code')
                    ->rows(2)
                    ->columnSpanFull()
                    ->placeholder('e.g. Traditional dress required for men: dhoti or pyjama with upper cloth'),

                TextInput::make('photography_policy')
                    ->label('Photography')
                    ->maxLength(255)
                    ->placeholder('e.g. Not permitted inside the sanctum'),

                TextInput::make('mobile_policy')
                    ->label('Mobile phones')
                    ->maxLength(255)
                    ->placeholder('e.g. Must be deposited at the cloakroom'),

                TextInput::make('footwear_policy')
                    ->label('Footwear')
                    ->maxLength(255)
                    ->placeholder('e.g. To be left at the designated stand'),

                Textarea::make('entry_rules')
                    ->label('Entry rules')
                    ->rows(3)
                    ->columnSpanFull(),

                Textarea::make('queue_information')
                    ->label('Queue information')
                    ->rows(3)
                    ->columnSpanFull()
                    ->placeholder('e.g. Free darshan queue, special entry darshan, senior citizen queue'),
            ]);
    }

    protected static function facilitiesSection(): Section
    {
        return Section::make('Facilities')
            ->description('Tick only what has been confirmed. An unverified claim of wheelchair access is worse than no claim at all.')
            ->icon('heroicon-o-building-office')
            ->collapsed()
            ->schema([
                CheckboxList::make('facilities')
                    ->label('Available facilities')
                    ->relationship('facilities', 'name')
                    ->columns(3)
                    ->searchable()
                    ->bulkToggleable(),
            ]);
    }

    protected static function contactSection(): Section
    {
        return Section::make('Official contact')
            ->description('Only details published by the temple or a government source.')
            ->icon('heroicon-o-phone')
            ->columns(2)
            ->collapsed()
            ->schema([
                TextInput::make('official_website')
                    ->label('Official website')
                    ->url()
                    ->maxLength(255)
                    ->prefixIcon('heroicon-o-globe-alt'),

                TextInput::make('contact_phone')
                    ->label('Phone')
                    ->tel()
                    ->maxLength(40),

                TextInput::make('contact_email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255),
            ]);
    }

    protected static function trustSection(): Section
    {
        return Section::make('Trust and provenance')
            ->description('Where this information came from, and how far it can be trusted. Official, verified and community content must never be blurred together.')
            ->icon('heroicon-o-shield-check')
            ->columns(2)
            ->schema([
                Select::make('verification_status')
                    ->label('Verification level')
                    ->options(VerificationStatus::class)
                    ->default(VerificationStatus::Unverified)
                    ->required()
                    ->native(false)
                    ->live()
                    ->helperText(fn (Get $get): string => FormState::enum(VerificationStatus::class, $get('verification_status'))?->description() ?? ''),

                DatePicker::make('last_verified_at')
                    ->label('Last verified on')
                    ->maxDate(now())
                    ->helperText('Timings and fees drift. Records not checked for a year are flagged as stale.'),

                TextInput::make('source_name')
                    ->label('Source name')
                    ->maxLength(255)
                    ->placeholder('e.g. TTD official website, ASI listing')
                    // Claiming "verified" or "official" without naming a source
                    // is exactly the sloppiness the plan warns against.
                    ->required(fn (Get $get): bool => FormState::enum(VerificationStatus::class, $get('verification_status'))?->requiresSource() ?? false),

                TextInput::make('source_url')
                    ->label('Source URL')
                    ->url()
                    ->maxLength(255)
                    ->required(fn (Get $get): bool => FormState::enum(VerificationStatus::class, $get('verification_status'))?->requiresSource() ?? false),
            ]);
    }

    protected static function publishingSection(): Section
    {
        return Section::make('Publishing')
            ->icon('heroicon-o-check-badge')
            ->columns(2)
            ->schema([
                Select::make('status')
                    ->label('Status')
                    ->options(function (): array {
                        // Editors move records to review; only a super admin
                        // decides what actually goes live.
                        $canPublish = Auth::user()?->canPublish() ?? true;

                        return collect(TempleStatus::cases())
                            ->reject(fn (TempleStatus $case) => $case === TempleStatus::Published && ! $canPublish)
                            ->mapWithKeys(fn (TempleStatus $case) => [$case->value => $case->getLabel()])
                            ->all();
                    })
                    ->default(TempleStatus::Draft)
                    ->required()
                    ->native(false)
                    ->helperText(fn (): string => Auth::user()?->canPublish()
                        ? 'Only published temples appear in the app.'
                        : 'Set to In Review when ready. A super admin publishes.'),

                Toggle::make('is_featured')
                    ->label('Famous temple')
                    ->helperText('Shown first in the app\'s popular temples. A curation choice, not a trust level.')
                    ->default(false),
            ]);
    }


    /** @return array<string, string> */
    protected static function locales(): array
    {
        return [
            'en' => 'English',
            'te' => 'Telugu',
            'hi' => 'Hindi',
            'ta' => 'Tamil',
            'kn' => 'Kannada',
            'ml' => 'Malayalam',
            'mr' => 'Marathi',
            'bn' => 'Bengali',
            'sa' => 'Sanskrit',
        ];
    }
}
