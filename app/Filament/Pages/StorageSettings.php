<?php

namespace App\Filament\Pages;

use App\Support\StorageHealth;
use App\Support\UploadRules;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
                    ? 'Set MEDIA_DISK=spaces in .env to move new uploads to object storage. Files already here stay here — each row records its own disk.'
                    : 'New uploads go to Spaces and are served from its CDN.',
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
