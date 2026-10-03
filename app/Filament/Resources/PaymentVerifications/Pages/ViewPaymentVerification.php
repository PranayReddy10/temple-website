<?php

namespace App\Filament\Resources\PaymentVerifications\Pages;

use App\Filament\Resources\PaymentVerifications\PaymentVerificationResource;
use App\Http\Controllers\KycDocumentController;
use App\Models\TemplePayoutAccount;
use App\Models\TemplePayoutVerificationEvent;
use App\Models\TempleUser;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Everything needed to decide, on one page: the person's photo beside their
 * Aadhaar, the proof that names the temple, and where the money would go.
 */
class ViewPaymentVerification extends ViewRecord
{
    protected static string $resource = PaymentVerificationResource::class;

    public function getHeading(): string
    {
        return 'Payments for '.($this->record->temple?->name ?? 'temple');
    }

    public function getSubheading(): ?string
    {
        /** @var TemplePayoutAccount $a */
        $a = $this->record;

        return PaymentVerificationResource::statusLabel($a->kycStatus())
            .($a->kyc_submitted_at ? ' · sent '.$a->kyc_submitted_at->format('d M Y, g:i A') : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            PaymentVerificationResource::approveAction(),
            PaymentVerificationResource::rejectAction(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Decision')
                ->columnSpanFull()
                ->icon('heroicon-o-scale')
                ->columns(3)
                ->schema([
                    TextEntry::make('status')->label('Status')->badge()
                        ->state(fn (TemplePayoutAccount $record): string => PaymentVerificationResource::statusLabel($record->kycStatus()))
                        ->color(fn (TemplePayoutAccount $record): string => match ($record->kycStatus()) {
                            'approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'gray',
                        }),
                    TextEntry::make('approved')->label('Approved by')
                        ->state(fn (TemplePayoutAccount $record): ?string => $record->verified_at ? ($record->verifier?->name ?? 'Staff').' · '.$record->verified_at->format('d M Y, g:i A') : null)
                        ->placeholder('—'),
                    TextEntry::make('rejection_reason')->label('Reason refused (the owner sees this)')->placeholder('—'),
                ]),

            Section::make('Check these match')
                ->columnSpanFull()
                ->description('The selfie and the Aadhaar photo are the same person; the name on the Aadhaar matches the bank account holder or the trust; the proof names this temple.')
                ->icon('heroicon-o-identification')
                ->columns(3)
                ->schema([
                    self::document('person_photo', 'Selfie (taken in the app)'),
                    self::document('aadhaar_front', 'Aadhaar — front'),
                    self::document('aadhaar_back', 'Aadhaar — back'),
                ]),

            Section::make('Person')
                ->columnSpanFull()
                ->icon('heroicon-o-user')
                ->columns(3)
                ->schema([
                    TextEntry::make('kyc_name')->label('Name on Aadhaar')->placeholder('Not sent')->weight('medium'),
                    TextEntry::make('aadhaar_full')->label('Aadhaar number')
                        ->state(fn (TemplePayoutAccount $record): ?string => filled($record->aadhaar_number) ? trim(chunk_split((string) $record->aadhaar_number, 4, ' ')) : null)
                        ->placeholder('Not sent')
                        ->copyable(),
                    TextEntry::make('sender')->label('Sent from the account')
                        ->state(fn (TemplePayoutAccount $record): ?string => $record->updater
                            ? collect([$record->updater->name, $record->updater->phone, $record->updater->email])->filter()->implode(' · ')
                            : null)
                        ->placeholder('—'),
                    TextEntry::make('claim')->label('Their request to manage the temple')
                        ->state(fn (TemplePayoutAccount $record): ?string => ($claim = self::claim($record))
                            ? ucfirst((string) $claim->role).' · '.($claim->isApproved() ? 'approved' : $claim->status()).' · '.$claim->claimLocationSummary()
                            : 'No request found for this account')
                        ->columnSpan(2),
                    TextEntry::make('claim_note')->label('What they wrote then')
                        ->state(fn (TemplePayoutAccount $record): ?string => self::claim($record)?->claim_note)
                        ->placeholder('—'),
                ]),

            Section::make('Proof of the temple')
                ->columnSpanFull()
                ->icon('heroicon-o-document-check')
                ->columns(3)
                ->schema([
                    TextEntry::make('temple_proof_kind')->label('What it is')
                        ->formatStateUsing(fn (?string $state): string => TemplePayoutAccount::PROOF_KINDS[$state] ?? '—')
                        ->placeholder('Not sent'),
                    self::document('temple_proof', 'Document')->columnSpan(2),
                ]),

            Section::make('Where the money goes')
                ->columnSpanFull()
                ->icon('heroicon-o-building-library')
                ->columns(3)
                ->schema([
                    TextEntry::make('account_name')->label('Account holder')->placeholder('—')->weight('medium'),
                    TextEntry::make('account_number')->label('Account number')->placeholder('—')->copyable(),
                    TextEntry::make('ifsc')->label('IFSC')->placeholder('—'),
                    TextEntry::make('bank_name')->label('Bank and branch')->placeholder('—'),
                    TextEntry::make('upi_id')->label('UPI ID')->placeholder('—'),
                ]),

            Section::make('History')
                ->columnSpanFull()
                ->icon('heroicon-o-clock')
                ->description('Every time documents were sent, approved or rejected, newest first. Each step keeps what was on file then: open the documents of a rejected attempt to compare with the latest.')
                ->schema([
                    RepeatableEntry::make('verificationEvents')
                        ->hiddenLabel()
                        ->placeholder('No history yet.')
                        ->columns(4)
                        ->schema([
                            TextEntry::make('event')->label('What happened')->badge()
                                ->formatStateUsing(fn (TemplePayoutVerificationEvent $record): string => $record->label())
                                ->color(fn (string $state): string => match ($state) {
                                    'approved' => 'success', 'rejected', 'approval_removed' => 'danger', 'bank_changed' => 'warning', default => 'gray',
                                }),
                            TextEntry::make('created_at')->label('When')->dateTime('d M Y, g:i A'),
                            TextEntry::make('user.name')->label('By')->placeholder('—'),
                            TextEntry::make('sent_as')->label('On file then')
                                ->state(fn (TemplePayoutVerificationEvent $record): string => collect([
                                    $record->snapshot['kyc_name'] ?? null,
                                    filled($record->snapshot['aadhaar_last4'] ?? null) ? 'Aadhaar XXXX '.$record->snapshot['aadhaar_last4'] : null,
                                    $record->snapshot['bank'] ?? null,
                                ])->filter()->implode(' · ') ?: '—'),
                            TextEntry::make('reason')->label('Reason')->placeholder('—')->columnSpanFull()
                                ->visible(fn (TemplePayoutVerificationEvent $record): bool => filled($record->reason))
                                ->color(fn (TemplePayoutVerificationEvent $record): string => $record->event === 'rejected' ? 'danger' : 'gray')
                                ->weight('medium'),
                            ...collect(['person_photo' => 'Selfie', 'aadhaar_front' => 'Aadhaar front', 'aadhaar_back' => 'Aadhaar back', 'temple_proof' => 'Temple proof'])
                                ->map(fn (string $label, string $key): TextEntry => TextEntry::make('doc_'.$key)
                                    ->label($label)
                                    ->state(fn (TemplePayoutVerificationEvent $record): ?string => filled($record->documentPath($key)) ? 'Open' : null)
                                    ->placeholder('—')
                                    ->icon('heroicon-o-arrow-top-right-on-square')
                                    ->color('primary')
                                    ->url(fn (TemplePayoutVerificationEvent $record): ?string => filled($record->documentPath($key))
                                        ? KycDocumentController::url($record->account, $key, $record->getKey())
                                        : null, shouldOpenInNewTab: true)
                                    ->visible(fn (TemplePayoutVerificationEvent $record): bool => in_array($record->event, ['submitted', 'rejected'], true)))
                                ->values()
                                ->all(),
                        ]),
                ]),

            Section::make('Temple')
                ->columnSpanFull()
                ->icon('heroicon-o-building-office')
                ->columns(3)
                ->collapsed()
                ->schema([
                    TextEntry::make('temple.name')->label('Name'),
                    TextEntry::make('temple_place')->label('Place')
                        ->state(fn (TemplePayoutAccount $record): string => collect([$record->temple?->address, $record->temple?->city, $record->temple?->district?->name, $record->temple?->state?->name])->filter()->implode(', ') ?: '—'),
                    TextEntry::make('temple.contact_phone')->label('Temple phone')->placeholder('—'),
                ]),
        ]);
    }

    /** A document shown in place: the picture itself, or a link for a PDF. Click to open it full size. */
    protected static function document(string $key, string $label): Component
    {
        $column = TemplePayoutAccount::DOCUMENTS[$key][0];
        $isPdf = fn (TemplePayoutAccount $record): bool => str_ends_with(strtolower((string) $record->{$column}), '.pdf');
        $url = fn (TemplePayoutAccount $record): ?string => filled($record->{$column}) ? KycDocumentController::url($record, $key) : null;

        return Group::make([
            ImageEntry::make($key.'_image')
                ->label($label)
                ->state($url)
                ->url($url, shouldOpenInNewTab: true)
                ->imageHeight(240)
                ->extraImgAttributes(['style' => 'width: 100%; max-width: 100%; height: 240px; object-fit: contain; background: rgba(0,0,0,.04); border-radius: 10px;'])
                ->checkFileExistence(false)
                ->placeholder('Not sent')
                ->helperText('Click to open full size.')
                ->visible(fn (TemplePayoutAccount $record): bool => ! $isPdf($record)),
            TextEntry::make($key.'_pdf')
                ->label($label)
                ->state('Open the PDF')
                ->icon('heroicon-o-document-text')
                ->color('primary')
                ->url($url, shouldOpenInNewTab: true)
                ->visible($isPdf),
        ]);
    }

    protected static function claim(TemplePayoutAccount $record): ?TempleUser
    {
        return $record->updated_by === null ? null : TempleUser::query()
            ->where('temple_id', $record->temple_id)
            ->where('user_id', $record->updated_by)
            ->first();
    }
}
