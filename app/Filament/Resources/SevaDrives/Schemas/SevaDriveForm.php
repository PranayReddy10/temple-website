<?php

namespace App\Filament\Resources\SevaDrives\Schemas;

use App\Enums\SevaCause;
use App\Enums\SevaDriveStatus;
use App\Models\Devotee;
use App\Models\SevaDrive;
use App\Support\UploadRules;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Creating and correcting a seva drive from the admin.
 *
 * Staff can run a drive themselves — with no devotee behind it, under a name
 * like "Temple trust volunteers" — or raise one on a devotee's behalf, and
 * can correct anything on one a devotee raised.
 */
class SevaDriveForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Who is organising it')
                    ->columns(2)
                    ->schema([
                        Select::make('devotee_id')
                            ->label('Devotee account')
                            ->relationship('organiser', 'name')
                            ->getOptionLabelFromRecordUsing(fn (Devotee $record): string => $record->name.($record->email ? ' · '.$record->email : ''))
                            ->searchable(['name', 'email', 'phone'])
                            ->native(false)
                            ->placeholder('None — run by the team')
                            ->helperText('The devotee who manages it in the app. Leave empty for a drive the team runs.'),

                        TextInput::make('organiser_name')
                            ->label('Shown as organised by')
                            ->maxLength(120)
                            ->placeholder('The devotee\'s name, or the team')
                            ->helperText('Optional. For example "Kolanupaka youth volunteers".'),

                        TextInput::make('contact_phone')
                            ->label('Organiser phone')
                            ->tel()
                            ->maxLength(20)
                            ->helperText('Shown in the app only to volunteers who join.'),
                    ]),

                Section::make('The drive')
                    ->columns(2)
                    ->schema([
                        TextInput::make('title')->required()->minLength(8)->maxLength(120)->columnSpanFull(),

                        Select::make('cause')->options(SevaCause::class)->required()->native(false),

                        Select::make('status')
                            ->options(SevaDriveStatus::class)
                            ->required()
                            ->native(false)
                            ->default(SevaDriveStatus::Approved->value)
                            ->helperText('A drive the team creates can go straight to "Open for volunteers".'),

                        Textarea::make('problem')->label('The place now — what is wrong')->required()->rows(4)->columnSpanFull(),
                        Textarea::make('plan')->label('The plan — what will be done')->required()->rows(4)->columnSpanFull(),
                        Textarea::make('what_to_bring')->label('Volunteers bring')->rows(2)->helperText('Separate with commas.')->columnSpanFull(),
                    ]),

                Section::make('Where')
                    ->columns(2)
                    ->schema([
                        TextInput::make('place_name')->label('Place')->required()->maxLength(191),
                        Select::make('temple_id')
                            ->label('Listed temple')
                            ->relationship('temple', 'name')
                            ->searchable()
                            ->native(false)
                            ->placeholder('Not a listed temple'),
                        TextInput::make('address')->maxLength(255),
                        TextInput::make('city')->label('Village / town / city')->maxLength(80),
                        Select::make('state_id')->label('State')->relationship('state', 'name')->searchable()->preload()->native(false),
                        TextInput::make('meeting_point')->maxLength(255),
                        TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90),
                        TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180),
                    ]),

                Section::make('When')
                    ->description('One day: set the start and, if you like, the time it ends that day. Several days: set the end on a later date.')
                    ->columns(3)
                    ->schema([
                        DateTimePicker::make('starts_at')->label('Starts')->required()->seconds(false),
                        DateTimePicker::make('ends_at')->label('Ends')->after('starts_at')->seconds(false),
                        TextInput::make('volunteers_needed')->label('Volunteers wanted')->numeric()->minValue(1)->maxValue(5000),
                    ]),

                Section::make('Donations')
                    ->description('The UPI ID is shown in the app only once the drive is verified, and never while donations are paused or the drive is marked misleading.')
                    ->columns(2)
                    ->collapsible()
                    ->schema([
                        TextInput::make('upi_id')->label('UPI ID')->maxLength(64)->regex('/^[A-Za-z0-9.\-_]{2,256}@[A-Za-z]{2,64}$/')->placeholder('name@bank'),
                        TextInput::make('upi_name')->label('Name on UPI')->maxLength(80),
                        TextInput::make('donation_goal')->label('Goal (₹)')->numeric()->minValue(100),
                        TextInput::make('donation_purpose')->label('Donations pay for')->maxLength(255),
                        Toggle::make('donations_enabled')->label('Donations allowed')->default(true),
                    ]),

                Section::make('Photographs of the place now')
                    ->visibleOn('create')
                    ->schema([
                        FileUpload::make('before_photos')
                            ->hiddenLabel()
                            ->multiple()
                            ->maxFiles(\App\Models\SevaDriveMedia::MAX_PER_STAGE)
                            ->image()
                            ->disk(fn (): string => config('filesystems.media'))
                            ->directory('seva-drives/admin')
                            ->visibility('public')
                            ->maxSize(UploadRules::maxKbFor('seva_photo'))
                            ->acceptedFileTypes(UploadRules::typesFor('seva_photo'))
                            ->helperText(UploadRules::summary('seva_photo').' More, and after photos, can be added under Photos & videos once it is saved.'),
                    ]),
            ])
            ->columns(1);
    }

    /**
     * Staff may set what devotees may not — status, organiser, donation
     * switch — so the admin writes by assignment, not mass assignment.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fill(SevaDrive $drive, array $data): SevaDrive
    {
        unset($data['before_photos']);

        $drive->forceFill($data);

        return $drive;
    }
}
