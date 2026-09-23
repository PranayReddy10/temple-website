<?php

namespace App\Filament\Concerns;

use App\Http\Resources\V1\PublicPassportResource;
use App\Models\Devotee;
use App\Support\PassportQr;
use Livewire\Attributes\Locked;

/**
 * Scan a devotee's passport code and show what it holds.
 *
 * Only the code is kept on the page, never the devotee's id. Livewire state
 * comes back from the browser on every request, and an id there could be
 * edited into someone else's; a code has to have been shown to you.
 */
trait ScansDevoteePassports
{
    public string $code = '';

    #[Locked]
    public ?string $scannedCode = null;

    public ?string $error = null;

    public function scan(?string $scanned = null): void
    {
        if ($scanned !== null) {
            $this->code = $scanned;
        }

        $this->error = null;
        $this->scannedCode = null;

        if (trim($this->code) === '') {
            $this->error = 'Scan a passport code or paste what it says.';

            return;
        }

        $token = PassportQr::parse($this->code);

        if ($token === null) {
            $this->error = 'That is not a devotee passport code. Temple check-in codes are verified under Verify QR code.';

            return;
        }

        if (Devotee::findByPassportCode($token) === null) {
            $this->error = 'No passport matches this code. The devotee may have reset it; ask them to show it again.';

            return;
        }

        $this->scannedCode = $token;
        $this->code = '';
    }

    public function clearScan(): void
    {
        $this->scannedCode = null;
        $this->error = null;
        $this->code = '';
    }

    /** The devotee behind the scanned code, looked up afresh each time. */
    protected function scannedDevotee(): ?Devotee
    {
        return $this->scannedCode === null ? null : Devotee::findByPassportCode($this->scannedCode);
    }

    /** @return array<string, mixed>|null */
    protected function passportData(): ?array
    {
        $devotee = $this->scannedDevotee();

        return $devotee === null ? null : (new PublicPassportResource($devotee->load('homeState:id,name')))->resolve(request());
    }
}
