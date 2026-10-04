<?php

namespace App\Filament\Schemas;

use App\Enums\PujaKind;
use App\Filament\Support\PaymentsApproval;
use App\Models\TemplePuja;
use App\Models\TemplePujaSlot;
use App\Support\UploadRules;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * The puja and seva form, shared by the temple's own list and the top-level
 * one.
 *
 * Two copies would mean two places for the fee rule to drift, and that rule
 * is the one that matters here: a blank amount means "no published price",
 * which is not the same as free, and a booking link is only official when an
 * editor has said so. A form that got either wrong in one place and right in
 * the other would be worse than one that was wrong everywhere, because
 * nobody would know which screen to believe.
 */
class TemplePujaForm
{
    /**
     * @param  int|null  $templeId  where uploads are filed; null when editing
     *                              outside a temple, where the record knows.
     */
    public static function configure(Schema $schema, ?int $templeId = null): Schema
    {
        return $schema
            ->components([
                Section::make('Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. Abhishekam, Archana, Kalyanotsavam'),

                        Select::make('kind')
                            ->label('Kind')
                            ->options(PujaKind::class)
                            ->default(PujaKind::Puja)
                            ->required()
                            ->native(false)
                            ->helperText('The app groups the list by this. Prasadam ordered in the app is collected at the counter.'),

                        Textarea::make('description')->rows(3)->columnSpanFull(),

                        FileUpload::make('image_path')
                            ->label('Image')
                            ->image()
                            ->disk(fn (): string => config('filesystems.media'))
                            ->directory(fn (?TemplePuja $record): string => 'pujas/'.($templeId ?? $record?->temple_id ?? 'unassigned'))
                            ->visibility('public')
                            ->maxSize(UploadRules::maxKbFor('puja_image'))
                            ->acceptedFileTypes(UploadRules::typesFor('puja_image'))
                            ->helperText('Optional. Shown beside the seva in the app.')
                            ->columnSpanFull(),
                        Textarea::make('includes')
                            ->label('What is included')
                            ->rows(2)
                            ->columnSpanFull(),
                        Textarea::make('eligibility')
                            ->rows(2)
                            ->helperText('Any restriction the temple publishes on who may participate.')
                            ->columnSpanFull(),
                    ]),

                Section::make('When')
                    ->columns(3)
                    ->schema([
                        TimePicker::make('starts_at')->label('Start time')->seconds(false),
                        TextInput::make('duration_minutes')
                            ->label('Duration (minutes)')
                            ->numeric()
                            ->minValue(1),
                        TextInput::make('schedule_note')
                            ->label('Schedule note')
                            ->maxLength(255)
                            ->placeholder('e.g. Daily, Fridays only'),
                    ]),

                Section::make('Fee')
                    ->columns(3)
                    ->description('Record only what the temple actually publishes. Leaving the amount blank means "no published price", which is not the same as free.')
                    ->schema([
                        Toggle::make('is_free')
                            ->label('Free of charge')
                            ->live(),
                        TextInput::make('fee_amount')
                            ->label('Published fee')
                            ->numeric()
                            ->minValue(0)
                            ->prefix('₹')
                            ->disabled(fn (Get $get): bool => (bool) $get('is_free'))
                            ->dehydrateStateUsing(fn ($state, Get $get) => $get('is_free') ? null : $state),
                        TextInput::make('fee_currency')
                            ->default('INR')
                            ->maxLength(3)
                            ->disabled(fn (Get $get): bool => (bool) $get('is_free')),
                    ]),

                Section::make('Booking')
                    ->columns(2)
                    ->description('An unofficial route must never be presented as official.')
                    ->schema([
                        TextInput::make('booking_url')
                            ->label('Booking URL')
                            ->url()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->columnSpanFull(),

                        Toggle::make('booking_is_official')
                            ->label('This is the temple\'s official booking route')
                            ->helperText('Only tick this if the link is operated by the temple or its governing body. Third-party resellers are not official.')
                            ->disabled(fn (Get $get): bool => blank($get('booking_url'))),

                        TextInput::make('booking_note')
                            ->label('Booking note')
                            ->maxLength(255),
                    ]),

                Section::make('Book through the app')
                    ->columns(2)
                    ->description('Optional, per seva. Off, the app shows the seva as information only, with the booking link above if there is one. On, devotees book (and pay the published fee) in the app and show a code at your counter, where you scan it and mark it received. Nothing changes for sevas you leave off.')
                    ->icon('heroicon-o-device-phone-mobile')
                    ->collapsible()
                    ->schema([
                        Toggle::make('app_booking_enabled')
                            ->label('Devotees can book this in the app')
                            ->live()
                            ->columnSpanFull()
                            // Said here as well as enforced in the observer,
                            // so the person switching it on learns why it
                            // will not stay on rather than finding it off.
                            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                if ($value && ! $get('is_free') && blank($get('fee_amount'))) {
                                    $fail('To take bookings in the app, publish the fee above or mark the seva free.');
                                }
                            })
                            // Paid booking takes money: only for an approved temple.
                            ->rule(PaymentsApproval::rule(fn (Get $get, mixed $value): bool => (bool) $value && ! $get('is_free') && filled($get('fee_amount')))),

                        Toggle::make('fee_per_person')
                            ->label('Fee is per person')
                            ->helperText('On: ₹100 × 3 people = ₹300. Off: one fee for the booking however many come.')
                            ->default(true)
                            ->visible(fn (Get $get): bool => (bool) $get('app_booking_enabled')),

                        TextInput::make('max_people_per_booking')
                            ->label('People per booking, at most')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(500)
                            ->default(10)
                            ->visible(fn (Get $get): bool => (bool) $get('app_booking_enabled')),

                        TextInput::make('booking_advance_days')
                            ->label('Book up to (days ahead)')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(365)
                            ->default(30)
                            ->visible(fn (Get $get): bool => (bool) $get('app_booking_enabled')),

                        TextInput::make('booking_capacity_per_day')
                            ->label('Bookings per day, at most')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10000)
                            ->placeholder('No limit')
                            ->helperText('Leave blank for no limit.')
                            ->visible(fn (Get $get): bool => (bool) $get('app_booking_enabled')),

                        Repeater::make('slots')
                            ->label('Time slots')
                            ->relationship()
                            ->helperText('Like show times: devotees pick a date, then a slot, and their booking shows both. Each slot takes up to its number of people a day. Leave empty for a seva with no set time. A booking that is not used on its day expires the next day.')
                            ->schema([
                                TimePicker::make('starts_at')->label('From')->seconds(false)->required(),
                                TimePicker::make('ends_at')->label('To')->seconds(false)->after('starts_at'),
                                TextInput::make('capacity')->label('People')->numeric()->minValue(1)->maxValue(100000)->placeholder('No limit'),
                                Select::make('days')->label('Days')->multiple()->placeholder('Every day')
                                    ->options([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 0 => 'Sun']),
                                Toggle::make('is_active')->label('Open')->default(true)->inline(false),
                            ])
                            ->columns(5)
                            ->orderColumn('sort_order')
                            ->defaultItems(0)
                            ->addActionLabel('Add a time slot')
                            ->itemLabel(fn (array $state): ?string => filled($state['starts_at'] ?? null)
                                ? TemplePujaSlot::window(substr((string) $state['starts_at'], 0, 5), filled($state['ends_at'] ?? null) ? substr((string) $state['ends_at'], 0, 5) : null)
                                    .(filled($state['capacity'] ?? null) ? ' · '.$state['capacity'].' people' : '')
                                : null)
                            ->collapsible()
                            ->hintAction(
                                Action::make('make_slots')
                                    ->label('Make slots')
                                    ->icon('heroicon-o-sparkles')
                                    ->modalHeading('Make time slots')
                                    ->modalDescription('For example 06:00 to 12:00, every 60 minutes, 15 people each, makes 6:00–7:00, 7:00–8:00 … 11:00–12:00. They are added to the list; save the seva to keep them.')
                                    ->schema([
                                        TimePicker::make('from')->seconds(false)->required()->default('06:00'),
                                        TimePicker::make('to')->seconds(false)->required()->default('12:00')->after('from'),
                                        TextInput::make('minutes')->label('Each slot (minutes)')->numeric()->minValue(5)->maxValue(720)->default(60)->required(),
                                        TextInput::make('capacity')->label('People per slot')->numeric()->minValue(1)->placeholder('No limit'),
                                    ])
                                    ->action(function (array $data, Get $get, Set $set): void {
                                        $slots = (array) ($get('slots') ?? []);
                                        $at = Carbon::createFromFormat('H:i', substr($data['from'], 0, 5));
                                        $end = Carbon::createFromFormat('H:i', substr($data['to'], 0, 5));
                                        $step = max(5, (int) $data['minutes']);
                                        for ($i = 0; $i < 48 && $at->copy()->addMinutes($step)->lte($end); $i++) {
                                            $slots['new-'.Str::uuid()] = [
                                                'starts_at' => $at->format('H:i'),
                                                'ends_at' => $at->copy()->addMinutes($step)->format('H:i'),
                                                'capacity' => filled($data['capacity'] ?? null) ? (int) $data['capacity'] : null,
                                                'days' => [],
                                                'is_active' => true,
                                            ];
                                            $at->addMinutes($step);
                                        }
                                        $set('slots', $slots);
                                    }),
                            )
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => (bool) $get('app_booking_enabled')),

                        Textarea::make('booking_instructions')
                            ->label('Instructions for the devotee')
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder('Report at the seva counter 30 minutes before, with this code. Bring a coconut and flowers.')
                            ->helperText('Shown after they book, and in their booking.')
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => (bool) $get('app_booking_enabled')),
                    ]),

                Section::make('Publishing')
                    ->columns(2)
                    ->schema([
                        TextInput::make('sort_order')->numeric()->default(0),
                        Toggle::make('is_published')->label('Published')->default(true),
                    ]),
            ])
            ->columns(1);
    }
}
