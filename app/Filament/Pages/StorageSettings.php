<?php

namespace App\Filament\Pages;

use App\Support\MediaStorage;
use App\Support\StorageHealth;
use App\Support\UploadRules;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;

/**
 * Where uploaded files go, and whether they can be seen.
 *
 * "The images do not show" has half a dozen causes that look identical from
 * any other screen: the public/storage link was never created, it was lost by
 * a redeploy, the host forbids symlinks, the disk is set to Spaces with no
 * credentials, the credentials are wrong, or the files are simply not there.
 * Each is checked separately here and reported separately, because the only
 * alternative is working through them by hand on a live server.
 *
 * The reachability check is the one that matters most: everything else
 * inspects configuration, and configuration that looks right is exactly the
 * state somebody is stuck in when they come to this page.
 */
class StorageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 3;

    protected static ?string $title = 'Storage & Uploads';

    protected static ?string $navigationLabel = 'Storage';

    protected static ?string $slug = 'storage';

    protected string $view = 'filament.pages.storage-settings';

    /** Filled by the "Check it now" action rather than on every page load. */
    public ?array $probe = null;

    public ?array $reachability = null;

    /** The result of the Spaces connection test, when one has been run. */
    public ?array $connection = null;

    /**
     * Backing store for the form's state.
     *
     * Load-bearing despite looking unused: Filament writes the schema's state
     * here, and save() reads it back through getState(). Removing it makes
     * every saved value silently empty. Same trap as ManageSettings.
     *
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'media_disk' => MediaStorage::selectedDisk(),
            ...MediaStorage::formValues(),
            // Never filled from storage. Blank means "leave the stored one
            // alone"; the placeholder says whether there is one.
            'spaces_secret' => null,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Where new uploads go')
                    ->description('Changing this decides where the next upload is written. Files already uploaded stay exactly where they are and keep working — each one records its own disk — so this is reversible.')
                    ->icon('heroicon-o-server-stack')
                    ->schema([
                        Radio::make('media_disk')
                            ->hiddenLabel()
                            ->options([
                                MediaStorage::LOCAL_DISK => 'This server',
                                MediaStorage::SPACES_DISK => 'DigitalOcean Spaces',
                            ])
                            ->descriptions([
                                MediaStorage::LOCAL_DISK => 'Simple, and nothing else to pay for. Limited by the hosting plan\'s disk quota, and every image is served by this one server.',
                                MediaStorage::SPACES_DISK => 'Object storage with a CDN in front. Worth it once there are thousands of temple photos, or when the plan\'s quota starts to bite.',
                            ])
                            ->required()
                            ->live(),
                    ]),

                Section::make('DigitalOcean Spaces')
                    ->description('From the Spaces page in your DigitalOcean control panel. Nothing is saved until the connection has been proven, so a wrong value here cannot break uploads.')
                    ->icon('heroicon-o-cloud')
                    ->columns(2)
                    // Shown while Spaces is selected, so the credentials can
                    // be filled in and tested in the same act as switching.
                    ->visible(fn (Get $get): bool => $get('media_disk') === MediaStorage::SPACES_DISK)
                    ->schema([
                        TextInput::make('spaces_key')
                            ->label('Access key')
                            ->maxLength(255)
                            ->autocomplete(false)
                            ->helperText('The shorter of the two. Safe to show.'),

                        TextInput::make('spaces_secret')
                            ->label('Secret key')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->autocomplete('new-password')
                            ->placeholder(MediaStorage::hasSecret() ? 'Stored — leave blank to keep it' : 'Required')
                            ->helperText(MediaStorage::hasSecret()
                                ? 'A secret is on file. It is stored encrypted and is never shown again, here or anywhere else. Type a new one only to replace it.'
                                : 'Stored encrypted, and never shown again once saved.'),

                        TextInput::make('spaces_bucket')
                            ->label('Bucket name')
                            ->maxLength(120)
                            ->placeholder('temple-media'),

                        TextInput::make('spaces_region')
                            ->label('Region')
                            ->maxLength(20)
                            ->placeholder('blr1')
                            ->helperText('The short code in the endpoint, e.g. blr1 for Bangalore.'),

                        TextInput::make('spaces_endpoint')
                            ->label('Endpoint')
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://blr1.digitaloceanspaces.com'),

                        TextInput::make('spaces_cdn_endpoint')
                            ->label('CDN endpoint')
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://temple-media.blr1.cdn.digitaloceanspaces.com')
                            ->helperText('Where images are delivered from. Leave blank to serve them from the bucket itself, which works but skips the edge cache.'),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return array<int, Action> */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Save')
                ->submit('save'),

            /*
             * Testing without saving.
             *
             * Worth its own button: typing six values and finding out whether
             * they are right only by committing them is how somebody ends up
             * with a site that cannot accept an upload.
             */
            Action::make('test')
                ->label('Test the connection')
                ->color('gray')
                ->icon('heroicon-o-signal')
                ->visible(fn (): bool => ($this->data['media_disk'] ?? null) === MediaStorage::SPACES_DISK)
                ->action(function (): void {
                    $this->connection = MediaStorage::test(
                        MediaStorage::candidateFrom($this->form->getState()),
                    );

                    Notification::make()
                        ->title($this->connection['ok'] ? 'Spaces is reachable' : 'Could not use these credentials')
                        ->body($this->connection['message'])
                        ->status($this->connection['ok'] ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }

    public function save(): void
    {
        $result = MediaStorage::save($this->form->getState());

        /*
         * A refusal stays on the page, a success does not.
         *
         * The toast fades after a few seconds, and the reason a switch was
         * refused is the one thing somebody needs to keep reading while they
         * correct the field it names.
         */
        $this->connection = $result['ok'] ? null : $result;

        Notification::make()
            ->title($result['ok'] ? 'Saved' : 'Not saved')
            ->body($result['message'])
            ->status($result['ok'] ? 'success' : 'danger')
            // A refusal is the whole point of the message, so it stays up
            // until it is dismissed rather than fading after four seconds.
            ->persistent(! $result['ok'])
            ->send();
    }

    public function getSubheading(): ?string
    {
        return StorageHealth::mediaIsServable()
            ? 'Uploads are going to '.$this->diskLabel().' and should be visible.'
            : 'Something is wrong: uploaded files will not be visible. See below.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label('Check it now')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->action(function (): void {
                    $this->probe = StorageHealth::probeDisk();
                    $this->reachability = $this->checkReachability();

                    Notification::make()
                        ->title($this->probe['ok'] ? 'Storage is working' : 'Storage is not working')
                        ->body($this->probe['message'])
                        ->status($this->probe['ok'] ? 'success' : 'danger')
                        ->send();
                }),

            /*
             * Repairing the link from here rather than over SSH.
             *
             * This is the fix for the commonest cause, and needing a terminal
             * for it is why sites sit with blank images for days.
             */
            Action::make('repair_link')
                ->label('Create the storage link')
                ->icon('heroicon-o-link')
                ->color('warning')
                ->visible(fn (): bool => StorageHealth::isLocal() && ! StorageHealth::linkIsCorrect())
                ->requiresConfirmation()
                ->modalDescription('Creates public/storage, pointing at storage/app/public. Safe to run more than once.')
                ->action(function (): void {
                    if (! StorageHealth::symlinksAreAllowed()) {
                        Notification::make()
                            ->title('This host does not allow symlinks')
                            ->body('Files are still served — the application falls back to serving them itself, which is slower but works.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        Artisan::call('storage:link');

                        Notification::make()
                            ->title(StorageHealth::linkIsCorrect() ? 'Link created' : 'Could not create the link')
                            ->body(StorageHealth::linkIsCorrect()
                                ? 'Uploaded files are served directly by the web server again.'
                                : 'The command ran but the link is still not right. Files are still served by the application as a fallback.')
                            ->status(StorageHealth::linkIsCorrect() ? 'success' : 'warning')
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Could not create the link')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    // --- What the view renders ---

    public function diskLabel(): string
    {
        return StorageHealth::isLocal()
            ? 'this server'
            : 'DigitalOcean Spaces';
    }

    public function checks(): array
    {
        $local = StorageHealth::isLocal();

        return array_values(array_filter([
            [
                'label' => 'Where uploads go',
                'ok' => true,
                'value' => $local
                    ? 'This server — '.StorageHealth::targetPath()
                    : 'DigitalOcean Spaces ('.config('filesystems.disks.spaces.bucket').')',
                'note' => $local
                    ? 'Change it above. Files already here stay here and keep working — each row records its own disk.'
                    : 'New uploads go to Spaces and are served from its CDN. Anything uploaded before the switch is still on this server and still served from it.',
            ],

            $local ? [
                'label' => 'public/storage link',
                'ok' => StorageHealth::linkIsCorrect(),
                'value' => match (true) {
                    StorageHealth::linkIsCorrect() => 'Correct',
                    StorageHealth::linkExists() => 'Exists but points somewhere else',
                    default => 'Missing',
                },
                'note' => StorageHealth::linkIsCorrect()
                    ? 'The web server serves files directly, which is the fast path.'
                    : 'Without it the application serves files itself — slower, but they do still appear. Use "Create the storage link" above.',
            ] : null,

            $local ? [
                'label' => 'Symlinks allowed by this host',
                'ok' => StorageHealth::symlinksAreAllowed(),
                'value' => StorageHealth::symlinksAreAllowed() ? 'Yes' : 'No — symlink() is disabled',
                'note' => StorageHealth::symlinksAreAllowed()
                    ? ''
                    : 'Some shared plans disable it. Nothing to fix: the application serves the files itself instead.',
            ] : null,

            $local ? [
                'label' => 'Upload folder writable',
                'ok' => StorageHealth::targetIsWritable(),
                'value' => StorageHealth::targetIsWritable() ? 'Yes' : 'No',
                'note' => StorageHealth::targetIsWritable()
                    ? ''
                    : 'New uploads will fail. The folder needs to be writable by the web server user.',
            ] : null,

            ! $local ? [
                'label' => 'Spaces credentials',
                'ok' => StorageHealth::spacesIsConfigured(),
                'value' => StorageHealth::spacesIsConfigured()
                    ? 'All filled in'
                    : 'Missing: '.collect(StorageHealth::spacesConfiguration())
                        ->reject(fn (bool $set): bool => $set)
                        ->keys()
                        ->implode(', '),
                'note' => 'Set in .env as DO_SPACES_KEY, DO_SPACES_SECRET, DO_SPACES_BUCKET, DO_SPACES_ENDPOINT and DO_SPACES_CDN_ENDPOINT. Secrets are never shown here.',
            ] : null,

            /*
             * The limit nobody looks at.
             *
             * A form set to 50 MB will validate a 50 MB file quite happily and
             * the upload will still fail, before any of this application runs,
             * with an empty $_FILES and nothing useful in the log — because
             * PHP's own upload_max_filesize is 2 MB, which is what shared
             * plans ship. This is the one check that explains an upload that
             * "just does nothing".
             */
            [
                'label' => 'Largest file the server accepts',
                'ok' => $this->serverLimitCoversEverything(),
                'value' => StorageHealth::phpUploadLimitKb() === null
                    ? 'No limit set'
                    : UploadRules::readableSize(StorageHealth::phpUploadLimitKb()),
                'note' => $this->serverLimitCoversEverything()
                    ? 'Comfortably above everything the forms allow.'
                    : 'Smaller than some uploads below, which will fail silently. Raise upload_max_filesize and post_max_size — on Hostinger, in hPanel under PHP Configuration, or in a .user.ini file at the site root.',
            ],

            [
                'label' => 'Space used',
                'ok' => true,
                'value' => StorageHealth::formatBytes(StorageHealth::usedBytes()),
                'note' => $local
                    ? 'Counts only uploaded media. A shared plan has a modest quota, which is what Spaces is for.'
                    : 'Measured by DigitalOcean rather than here.',
            ],
        ]));
    }

    public function uploadRules(): array
    {
        $serverLimit = StorageHealth::phpUploadLimitKb();

        return collect(UploadRules::all())
            ->map(function (array $rule) use ($serverLimit): array {
                // What the form allows is not what will actually go through if
                // PHP stops it first, and saying 50 MB where 2 MB is the truth
                // is how somebody spends an afternoon on a failing upload.
                $capped = $serverLimit !== null && $serverLimit < $rule['max_kb'];

                return [
                    'label' => $rule['label'],
                    'where' => $rule['where'],
                    'types' => UploadRules::readableTypes($rule['types']),
                    'max' => UploadRules::readableSize($capped ? $serverLimit : $rule['max_kb']),
                    'capped' => $capped,
                    'capped_from' => $capped ? UploadRules::readableSize($rule['max_kb']) : null,
                    'note' => $rule['note'],
                ];
            })
            ->values()
            ->all();
    }

    /** True when PHP will pass through the largest upload any form allows. */
    protected function serverLimitCoversEverything(): bool
    {
        $serverLimit = StorageHealth::phpUploadLimitKb();

        if ($serverLimit === null) {
            return true;
        }

        return $serverLimit >= collect(UploadRules::all())->max('max_kb');
    }

    /**
     * Fetches a real stored file over HTTP, the way a browser would.
     *
     * The only check here that proves anything end to end. Everything else
     * reads configuration, and configuration that looks correct is the exact
     * situation somebody is in when they arrive at this page wondering why
     * nothing renders.
     */
    protected function checkReachability(): array
    {
        $url = StorageHealth::sampleUrl();

        if ($url === null) {
            return [
                'ok' => null,
                'url' => null,
                'message' => 'No uploaded images yet, so there is nothing to test with. Upload one and check again.',
            ];
        }

        // Root-relative URLs are resolved against this site; a Spaces URL is
        // already absolute.
        $absolute = str_starts_with($url, 'http') ? $url : rtrim(request()->getSchemeAndHttpHost(), '/').$url;

        try {
            $response = Http::timeout(8)->withoutVerifying()->get($absolute);

            return [
                'ok' => $response->successful(),
                'url' => $absolute,
                'message' => $response->successful()
                    ? 'Fetched it: HTTP '.$response->status().', '.strtoupper((string) $response->header('Content-Type')).'.'
                    : 'The server answered HTTP '.$response->status().' for this file.',
            ];
        } catch (ConnectionException) {
            /*
             * Not an answer about storage.
             *
             * This request is the site fetching itself, so a server that can
             * only handle one request at a time — php artisan serve, or a
             * plan down to its last PHP worker — deadlocks on it and times
             * out. Reporting that as a storage fault would send somebody
             * fixing the one thing that is working.
             */
            return [
                'ok' => null,
                'url' => $absolute,
                'message' => 'The site could not reach itself, which usually means it was busy rather than that the file is missing. Open the link below in a new tab — that answers the same question.',
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'url' => $absolute,
                'message' => 'Could not fetch it: '.$e->getMessage(),
            ];
        }
    }

    /** Storage is a super-admin concern; an editor cannot change any of it. */
    public static function canAccess(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }
}
