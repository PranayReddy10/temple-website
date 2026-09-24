<?php

namespace App\Filament\Pages\Settings;

use App\Models\Setting;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * A page of settings stored in the `settings` table.
 *
 * Each subclass lists its keys in definitions() and builds its form; loading,
 * saving and secrets are handled here once. A secret is never sent back to the
 * browser: its field is empty with a "saved" hint, and saving an empty secret
 * field keeps the stored value rather than erasing it.
 */
abstract class SettingsPage extends Page
{
    protected string $view = 'filament.pages.manage-settings';

    /** @var array<string, mixed> Filament writes the form state here. */
    public array $data = [];

    /**
     * key => [type, default]. Types: string, boolean, integer, secret, json.
     *
     * @return array<string, array{0: string, 1: mixed}>
     */
    abstract protected static function definitions(): array;

    /** Configuration that decides who is paid, and what the app does: super admins only. */
    public static function canAccess(): bool
    {
        return Auth::user()?->canManageUsers() ?? false;
    }

    public function mount(): void
    {
        $values = [];

        foreach (static::definitions() as $key => [$type, $default]) {
            $values[$key] = $type === 'secret' ? null : (Setting::get($key) ?? $default);
        }

        $this->form->fill($values);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (static::definitions() as $key => [$type, $default]) {
            $value = $state[$key] ?? null;

            match ($type) {
                'boolean' => Setting::set($key, $value ? '1' : '0', 'boolean'),
                'integer' => Setting::set($key, filled($value) ? (string) (int) $value : null, 'integer'),
                // Blank means "unchanged", never "erase": the form cannot show
                // what is stored, so an untouched field must not wipe it.
                'secret' => filled($value) ? Setting::set($key, $value, 'secret') : null,
                'json' => Setting::set($key, $value === null ? null : json_encode($value), 'json'),
                default => Setting::set($key, filled($value) ? $value : null, 'string'),
            };
        }

        Notification::make()->title('Settings saved')->success()->send();
    }

    /** A password-style field for a stored secret. */
    protected static function secretInput(string $key, string $label): TextInput
    {
        $saved = Setting::secret($key) !== null;

        return TextInput::make($key)
            ->label($label)
            ->password()
            ->revealable()
            ->autocomplete('new-password')
            ->placeholder($saved ? '•••••••• saved' : 'Not set')
            ->helperText($saved ? 'Saved and encrypted. Leave blank to keep it; type a new value to replace it.' : 'Stored encrypted.');
    }
}
