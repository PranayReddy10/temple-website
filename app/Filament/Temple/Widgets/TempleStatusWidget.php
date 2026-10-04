<?php

namespace App\Filament\Temple\Widgets;

use App\Enums\TempleStatus;
use App\Filament\Temple\Pages\BankDetails;
use App\Filament\Temple\Resources\MyTemples\MyTempleResource;
use App\Filament\Temple\TemplePortal;
use App\Filament\Temple\Widgets\Concerns\ForCurrentTemple;
use App\Support\Finance\Settlements;
use App\Support\Seo;
use Filament\Widgets\Widget;

/** The temple itself, and whether devotees can pay it: the first thing a team sees. */
class TempleStatusWidget extends Widget
{
    use ForCurrentTemple;

    protected static ?int $sort = 1;

    // First on the page: shown with it, not after it.
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.temple.widgets.temple-status';

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $temple = $this->temple()?->loadMissing(['primaryPhoto', 'payoutAccount', 'deity:id,name']);
        if ($temple === null) {
            return ['temple' => null];
        }
        $account = $temple->payoutAccount;
        $status = $account?->kycStatus() ?? 'missing';

        return [
            'temple' => $temple,
            'cover' => $temple->primaryPhoto?->thumbnailUrl(),
            'published' => $temple->status === TempleStatus::Published,
            'statusLabel' => $temple->status?->getLabel() ?? '',
            'payments' => $status,
            'paymentsLabel' => $account?->kycStatusLabel() ?? 'Details or documents missing',
            'rejection' => $status === 'rejected' ? $account?->rejection_reason : null,
            'hundi' => (bool) $temple->accepts_donations,
            'feePercent' => app(Settlements::class)->feePercentFor($temple),
            'donationFeePercent' => app(Settlements::class)->donationFeePercent(),
            'owner' => TemplePortal::isOwner($temple),
            'editUrl' => MyTempleResource::getUrl('edit', ['record' => $temple]),
            'bankUrl' => BankDetails::getUrl(),
            'publicUrl' => $temple->status === TempleStatus::Published ? Seo::url('temples/'.$temple->slug) : null,
        ];
    }
}
