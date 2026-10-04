<?php

namespace App\Filament\Temple\Pages;

use App\Filament\Temple\Concerns\PicksTemple;
use App\Filament\Temple\TemplePortal;
use App\Models\TemplePayoutAccount;
use App\Support\Finance\PayoutAccounts;
use App\Support\UploadRules;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Where the temple is paid, and the owner's proof that it may take money:
 * the trust app's Finance → Verification. Everyone on the team sees the
 * status; only the temple's approved owner changes anything, and every
 * change is checked again by our team before money follows it.
 */
class BankDetails extends Page
{
    use PicksTemple;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|UnitEnum|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Bank & verification';

    protected static ?string $title = 'Bank details & verification';

    protected static ?string $slug = 'bank-details';

    protected string $view = 'filament.temple.pages.bank-details';

    /** @var array<string, mixed> */
    public ?array $bank = [];

    /** @var array<string, mixed> */
    public ?array $kyc = [];

    public static function canAccess(): bool
    {
        return Auth::user()?->isTempleAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->fillForms();
    }

    protected function templeChanged(): void
    {
        $this->fillForms();
    }

    private function fillForms(): void
    {
        $account = $this->account();
        $this->bankForm->fill([
            'account_name' => $account?->account_name,
            'account_number' => null,
            'ifsc' => $account?->ifsc,
            'bank_name' => $account?->bank_name,
            'upi_id' => $account?->upi_id,
        ]);
        $this->kycForm->fill([
            'kyc_name' => $account?->kyc_name,
            'aadhaar_number' => null,
            'temple_proof_kind' => $account?->temple_proof_kind,
        ]);
    }

    public function account(): ?TemplePayoutAccount
    {
        return $this->temple()->payoutAccount()->first();
    }

    public function isOwner(): bool
    {
        return TemplePortal::isOwner($this->temple());
    }

    public function bankForm(Schema $schema): Schema
    {
        $account = $this->account();

        return $schema
            ->statePath('bank')
            ->disabled(fn (): bool => ! $this->isOwner())
            ->components([
                Section::make('1. Bank account')
                    ->description('Settlements are paid here. A bank account, a UPI id, or both.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('account_name')->label('Account holder name')->maxLength(120)
                            ->requiredWith('account_number'),
                        TextInput::make('account_number')->label('Account number')
                            ->placeholder($account?->maskedAccountNumber() ? 'On file: '.$account->maskedAccountNumber().' (leave blank to keep)' : 'Digits only')
                            ->regex('/^[0-9]{6,20}$/')
                            ->validationMessages(['regex' => 'Enter the account number as digits only.'])
                            ->password()->revealable()->autocomplete('off'),
                        TextInput::make('ifsc')->label('IFSC')->maxLength(11)
                            ->regex('/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/')
                            ->validationMessages(['regex' => 'An IFSC is 11 characters, like SBIN0001234.'])
                            ->requiredWith('account_number'),
                        TextInput::make('bank_name')->label('Bank and branch')->maxLength(120),
                        TextInput::make('upi_id')->label('UPI id')->placeholder('name@bank')->maxLength(120)
                            ->regex('/^[A-Za-z0-9.\-_]{2,256}@[A-Za-z]{2,64}$/')
                            ->validationMessages(['regex' => 'A UPI id looks like name@bank.']),
                    ])
                    ->footerActions([
                        Action::make('saveBank')->label('Save bank details')
                            ->visible(fn (): bool => $this->isOwner())
                            ->action('saveBank'),
                    ]),
            ]);
    }

    public function kycForm(Schema $schema): Schema
    {
        $account = $this->account();
        $has = fn (string $column): bool => filled($account?->{$column});
        $doc = fn (string $field, string $label, bool $image = false) => FileUpload::make($field)
            ->label($label.($has(TemplePayoutAccount::DOCUMENTS[$field][0]) ? ' — on file, upload only to replace' : ''))
            ->required(! $has(TemplePayoutAccount::DOCUMENTS[$field][0]))
            // Kept as an upload here and stored privately on saving.
            ->storeFiles(false)
            ->acceptedFileTypes(UploadRules::typesFor($image ? 'temple_photo' : 'kyc_document'))
            ->maxSize(UploadRules::maxKbFor('kyc_document'))
            ->helperText(UploadRules::summary($image ? 'temple_photo' : 'kyc_document'));

        return $schema
            ->statePath('kyc')
            ->disabled(fn (): bool => ! $this->isOwner())
            ->components([
                Section::make('2. The owner\'s identity')
                    ->description('The person who represents the temple, as on their Aadhaar card.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('kyc_name')->label('Name as on Aadhaar')->minLength(3)->maxLength(120)->required(! $has('kyc_name')),
                        TextInput::make('aadhaar_number')->label('Aadhaar number')
                            ->placeholder($account?->maskedAadhaar() ? 'On file: '.$account->maskedAadhaar().' (leave blank to keep)' : '2345 6789 0123')
                            ->required(! $has('aadhaar_number'))
                            ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                                if (filled($value) && ! preg_match('/^[2-9][0-9]{11}$/', preg_replace('/\s+/', '', (string) $value))) {
                                    $fail('An Aadhaar number is 12 digits.');
                                }
                            })
                            ->autocomplete('off'),
                        $doc('aadhaar_front', 'Aadhaar card (front)'),
                        $doc('aadhaar_back', 'Aadhaar card (back)'),
                        $doc('person_photo', 'Photo of the person', image: true),
                    ]),
                Section::make('3. Proof of the temple')
                    ->description('A document showing this person may represent the temple.')
                    ->schema([
                        Select::make('temple_proof_kind')->label('What the document is')
                            ->options(TemplePayoutAccount::PROOF_KINDS)->required(! $has('temple_proof_kind')),
                        $doc('temple_proof', 'The document'),
                    ])
                    ->footerActions([
                        Action::make('sendKyc')
                            ->label(fn (): string => filled($this->account()?->kyc_submitted_at) ? 'Send again for approval' : 'Send for approval')
                            ->visible(fn (): bool => $this->isOwner())
                            ->requiresConfirmation()
                            ->modalDescription('Our team checks the documents with the bank details. Until they approve them, devotees cannot pay this temple in the app.')
                            ->action('sendKyc'),
                    ]),
            ]);
    }

    public function saveBank(): void
    {
        abort_unless($this->isOwner(), 403);
        $data = $this->bankForm->getState();
        app(PayoutAccounts::class)->updateBank($this->temple(), $data, Auth::user());
        $this->fillForms();

        Notification::make()->title('Bank details saved')
            ->body($this->account()?->isVerified() ? null : 'Our team checks them before settlements are paid there.')
            ->success()->send();
    }

    public function sendKyc(): void
    {
        abort_unless($this->isOwner(), 403);
        $data = $this->kycForm->getState();
        $files = collect(array_keys(TemplePayoutAccount::DOCUMENTS))
            ->mapWithKeys(fn (string $field): array => [$field => is_array($data[$field] ?? null) ? collect($data[$field])->first() : ($data[$field] ?? null)])
            ->filter(fn ($file): bool => $file instanceof UploadedFile)
            ->all();

        app(PayoutAccounts::class)->submitKyc($this->temple(), $data, $files, Auth::user());
        $this->fillForms();

        Notification::make()->title('Sent for approval')->body('We will let you know here once our team has checked them.')->success()->send();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $account = $this->account();

        return [
            'account' => $account,
            'status' => $account?->kycStatus() ?? 'missing',
            'statusLabel' => $account?->kycStatusLabel() ?? 'Details or documents missing',
            'owner' => $this->isOwner(),
            'history' => $account?->verificationEvents()->with('user:id,name')->limit(10)->get() ?? collect(),
        ];
    }
}
