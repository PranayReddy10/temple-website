<x-filament-panels::page>
    @include('filament.temple.partials.styles')
    @include('filament.temple.partials.temple-picker')

    <div @class(['ds-banner', 'ds-banner--' . $status])>
        <x-filament::icon :icon="match ($status) { 'approved' => 'heroicon-o-check-badge', 'pending' => 'heroicon-o-clock', 'rejected' => 'heroicon-o-exclamation-triangle', default => 'heroicon-o-banknotes' }" class="ds-banner__icon" />
        <div>
            <strong>{{ $statusLabel }}</strong>
            @if ($status === 'rejected' && $account?->rejection_reason)
                <p>Why: {{ $account->rejection_reason }}. Correct it below and send again.</p>
            @elseif ($status === 'approved')
                <p>Devotees can book paid sevas, buy event tickets and give to the online hundi.</p>
            @elseif ($status === 'pending')
                <p>Our team is checking the bank details and documents. Payments start once they approve them.</p>
            @else
                <p>Before devotees can pay this temple in the app, add the bank account and the owner's documents, then send them for approval.</p>
            @endif
            @unless ($owner)
                <p class="ds-muted">Only the temple's owner can change these. Ask them, or ask our team (Support) to make you the owner.</p>
            @endunless
        </div>
    </div>

    {{ $this->bankForm }}

    {{ $this->kycForm }}

    @if ($history->isNotEmpty())
        <x-filament::section heading="History" collapsible collapsed>
            <table class="ds-rows">
                <tbody>
                    @foreach ($history as $event)
                        <tr>
                            <td style="white-space: nowrap">{{ $event->created_at?->timezone(\App\Support\DevotionalClock::timezone())->format('d M Y, g:i A') }}</td>
                            <td><strong>{{ $event->label() }}</strong>@if ($event->reason)<div class="ds-muted">{{ $event->reason }}</div>@endif</td>
                            <td class="ds-muted">{{ $event->user === null ? "" : ($event->user->isTempleAdmin() ? $event->user->name : "Our team") }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif
</x-filament-panels::page>
