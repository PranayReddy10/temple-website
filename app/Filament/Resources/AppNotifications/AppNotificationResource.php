<?php

namespace App\Filament\Resources\AppNotifications;

use App\Filament\Resources\AppNotifications\Pages\ManageAppNotifications;
use App\Models\AppNotification;
use App\Models\Devotee;
use App\Models\State;
use App\Models\Temple;
use App\Support\Push\NotificationSender;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Compose, schedule and send app notifications.
 *
 * Sending puts the message in every matching inbox in the app, and pushes it
 * to phones when push is set up (App → Push setup).
 */
class AppNotificationResource extends Resource
{
    protected static ?string $model = AppNotification::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell';

    protected static string|\UnitEnum|null $navigationGroup = 'App';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Notifications';

    protected static ?string $modelLabel = 'notification';

    protected static ?string $slug = 'app/notifications';

    public static function canAccess(): bool
    {
        return Auth::user()?->role?->isStaff() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message')->columns(1)->schema([
                TextInput::make('title')->required()->maxLength(65)->helperText('Up to 65 characters shows in full on most phones.'),
                Textarea::make('body')->required()->rows(3)->maxLength(240),
                TextInput::make('image_url')->label('Image link (optional)')->url()->maxLength(255)
                    ->helperText('A public https image, shown large on Android and in the inbox.'),
            ]),
            Section::make('When tapped')->columns(2)->schema([
                Select::make('link_type')->label('Opens')->options(AppNotification::LINKS)->default('none')->live()->native(false),
                Select::make('link_value')->label('Temple')->searchable()
                    ->visible(fn (Get $get) => $get('link_type') === 'temple')
                    ->getSearchResultsUsing(fn (string $search) => Temple::query()->published()->where('name', 'like', "%{$search}%")->limit(30)->pluck('name', 'slug')->all())
                    ->getOptionLabelUsing(fn ($value) => Temple::query()->where('slug', $value)->value('name')),
                Select::make('link_value')->label('Weekday')->key('link_day')
                    ->visible(fn (Get $get) => $get('link_type') === 'day')
                    ->options(['0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday']),
                Select::make('link_value')->label('Screen')->key('link_screen')
                    ->visible(fn (Get $get) => $get('link_type') === 'screen')
                    ->options(AppNotification::SCREENS),
                TextInput::make('link_value')->label('Link')->key('link_url')->url()
                    ->visible(fn (Get $get) => $get('link_type') === 'url'),
            ]),
            Section::make('Who receives it')->columns(2)->schema([
                Select::make('audience')
                    ->options(collect(AppNotification::AUDIENCES)->except(array_keys(AppNotification::REMINDER_AUDIENCES))->all())
                    ->default('all')->required()->live()->native(false)
                    ->helperText('Festival and event reminders to followers are sent automatically the evening before (Settings → Push).'),
                Select::make('platform')->options(['android' => 'Android', 'ios' => 'iPhone / iPad'])
                    ->visible(fn (Get $get) => $get('audience') === 'platform')->required(fn (Get $get) => $get('audience') === 'platform'),
                Select::make('audience_id')->label('Temple')->searchable()->key('aud_temple')
                    ->visible(fn (Get $get) => $get('audience') === 'temple')->required(fn (Get $get) => $get('audience') === 'temple')
                    ->getSearchResultsUsing(fn (string $search) => Temple::query()->where('name', 'like', "%{$search}%")->limit(30)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Temple::query()->whereKey($value)->value('name')),
                Select::make('audience_id')->label('Home state')->key('aud_state')
                    ->visible(fn (Get $get) => $get('audience') === 'state')->required(fn (Get $get) => $get('audience') === 'state')
                    ->options(fn () => State::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                Select::make('audience_id')->label('Devotee')->searchable()->key('aud_devotee')
                    ->visible(fn (Get $get) => $get('audience') === 'devotee')->required(fn (Get $get) => $get('audience') === 'devotee')
                    ->getSearchResultsUsing(fn (string $search) => Devotee::query()->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))->limit(30)->pluck('name', 'id')->all())
                    ->getOptionLabelUsing(fn ($value) => Devotee::query()->whereKey($value)->value('name')),
                DateTimePicker::make('scheduled_at')->label('Send later (optional)')->seconds(false)->minDate(now()->subMinute())
                    ->helperText('Leave empty and use "Send now". A scheduled message goes out within a minute of its time (needs the scheduler cron).'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->weight('medium')->description(fn (AppNotification $r) => str($r->body)->limit(80))->wrap(),
                TextColumn::make('audience')->formatStateUsing(fn (string $state) => AppNotification::AUDIENCES[$state] ?? $state)->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'sent' => 'success', 'scheduled' => 'info', 'failed' => 'danger', default => 'gray' }),
                TextColumn::make('when')->label('When')->state(fn (AppNotification $r) => $r->sent_at ?? $r->scheduled_at)->dateTime('d M Y, H:i')->placeholder('—'),
                TextColumn::make('reads_count')->counts('reads')->label('Read')->alignEnd(),
                TextColumn::make('last_error')->label('Push error')->color('danger')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([SelectFilter::make('status')->options(['draft' => 'Draft', 'scheduled' => 'Scheduled', 'sent' => 'Sent'])])
            ->recordActions([
                Action::make('send')->label('Send now')->icon('heroicon-o-paper-airplane')->color('success')
                    ->visible(fn (AppNotification $r) => $r->status !== 'sent')
                    ->requiresConfirmation()
                    ->modalDescription(fn (AppNotification $r) => 'Goes to: '.(AppNotification::AUDIENCES[$r->audience] ?? $r->audience).'. This cannot be recalled.')
                    ->action(function (AppNotification $r): void {
                        $sent = app(NotificationSender::class)->send($r);
                        Notification::make()
                            ->title($sent->last_error === null ? 'Sent' : 'In the inbox, but push failed')
                            ->body($sent->last_error)
                            ->color($sent->last_error === null ? 'success' : 'warning')
                            ->send();
                    }),
                EditAction::make()->visible(fn (AppNotification $r) => $r->status !== 'sent')
                    ->mutateDataUsing(fn (array $data): array => static::prepare($data)),
                DeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No notifications yet')
            ->emptyStateDescription('Festival reminders, new temples, app news: write one and send it now or schedule it.');
    }

    /** @param  array<string, mixed>  $data */
    public static function prepare(array $data): array
    {
        $data['status'] = filled($data['scheduled_at'] ?? null) ? 'scheduled' : 'draft';

        if (($data['audience'] ?? 'all') !== 'platform') {
            $data['platform'] = null;
        }

        if (in_array($data['audience'] ?? 'all', ['all', 'platform'], true)) {
            $data['audience_id'] = null;
        }

        if (($data['link_type'] ?? 'none') === 'none') {
            $data['link_value'] = null;
        }

        return $data;
    }

    public static function getPages(): array
    {
        return ['index' => ManageAppNotifications::route('/')];
    }
}
