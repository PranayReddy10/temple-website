<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Facades\Filament;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;

/**
 * "My profile" for staff and for a temple's own team.
 *
 * The dashboard used to carry a greeting card whose only real content was a
 * sign-out button — a whole panel-width widget for something the user menu
 * already does. This is what that space is worth instead: the account's own
 * page, where the name, sign-in email and password are changed, with the
 * things a person actually needs to check about their account — which role
 * they hold, which panel it signs into, and which temples it covers — stated
 * above the form rather than left to be guessed.
 *
 * One page serves both panels; Filament registers it per panel, and the
 * summary reads the signed-in account, so the temple portal shows a temple
 * admin's temples and the editorial panel shows a staff member's remit.
 */
class Profile extends BaseEditProfile
{
    protected static ?string $title = 'My profile';

    public static function getLabel(): string
    {
        return 'My profile';
    }

    public function getHeading(): string
    {
        return 'My profile';
    }

    public function getSubheading(): ?string
    {
        return 'Your sign-in details. Changing the email or password asks for the current one first.';
    }

    /**
     * The summary goes above the form: it is context for the edit, not part
     * of it. Nothing here is editable, because none of it is the account
     * holder's to change — a role or a temple claim is granted by staff.
     */
    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getAccountSummaryComponent(),
                $this->getFormContentComponent(),
                ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
            ]);
    }

    public function getAccountSummaryComponent(): Component
    {
        $user = $this->getUser();

        return Section::make('Account')
            ->description('Granted by a super admin. Ask one if something here is wrong.')
            ->schema([
                View::make('filament.pages.profile-summary')
                    ->viewData([
                        'user' => $user,
                        'role' => $user instanceof User ? $user->role : null,
                        'temples' => $user instanceof User && $user->isTempleAdmin()
                            ? $user->temples()->orderBy('name')->pluck('temples.name')->all()
                            : [],
                        'panelName' => Filament::getCurrentOrDefaultPanel()?->getId() === 'temple'
                            ? 'Temple Portal'
                            : 'Admin Panel',
                    ]),
            ]);
    }
}
