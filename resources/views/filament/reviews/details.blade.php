{{-- One account of a visit in full, for reading before deciding. --}}
@php($dims = \App\Models\TempleReview::DIMENSIONS)
<div style="display:grid;gap:1rem">
    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <x-filament::badge :color="$review->status->getColor()" :icon="$review->status->getIcon()">{{ $review->status->getLabel() }}</x-filament::badge>
        <span style="font-size:.85rem;opacity:.75">Visited {{ $review->visited_on?->format('d M Y') }} · written {{ $review->created_at?->diffForHumans() }}</span>
        @if ($review->moderator)
            <span style="font-size:.85rem;opacity:.75">· decided by {{ $review->moderator->name }}</span>
        @endif
    </div>

    <dl style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.6rem;margin:0">
        @foreach ($dims as $key => $meta)
            <div style="border:1px solid rgba(120,113,108,.25);border-radius:12px;padding:.6rem .75rem">
                <dt style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;opacity:.65">{{ $meta['label'] }}</dt>
                <dd style="margin:.15rem 0 0;font-size:1.1rem;font-weight:700">{{ $review->{$key} === null ? '—' : $review->{$key}.' / 5' }}</dd>
                <dd style="margin:0;font-size:.7rem;opacity:.6">{{ $review->{$key} === null ? 'Not rated' : ($review->{$key} <= 2 ? $meta['low'] : ($review->{$key} >= 4 ? $meta['high'] : 'Fair')) }}</dd>
            </div>
        @endforeach
        @if ($review->wait_minutes !== null)
            <div style="border:1px solid rgba(120,113,108,.25);border-radius:12px;padding:.6rem .75rem">
                <dt style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;opacity:.65">Waited</dt>
                <dd style="margin:.15rem 0 0;font-size:1.1rem;font-weight:700">{{ $review->wait_minutes }} min</dd>
            </div>
        @endif
    </dl>

    @if ($review->body)
        <blockquote style="margin:0;padding:.75rem 1rem;border-left:3px solid rgba(180,83,9,.6);background:rgba(180,83,9,.06);border-radius:0 12px 12px 0;white-space:pre-wrap;font-size:.95rem;line-height:1.5">{{ $review->body }}</blockquote>
    @else
        <p style="font-size:.85rem;opacity:.7;margin:0">Ratings only; nothing written.</p>
    @endif

    @if ($review->moderation_note)
        <p style="font-size:.85rem;margin:0"><strong>Reason given to the devotee:</strong> {{ $review->moderation_note }}</p>
    @endif

    @if ($review->temple_reply)
        <div style="border:1px solid rgba(5,150,105,.35);background:rgba(5,150,105,.06);border-radius:12px;padding:.75rem 1rem">
            <p style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;opacity:.65;margin:0 0 .25rem">The temple replied{{ $review->replier ? ' · '.$review->replier->name : '' }} · {{ $review->temple_replied_at?->format('d M Y') }}</p>
            <p style="margin:0;white-space:pre-wrap;font-size:.9rem">{{ $review->temple_reply }}</p>
        </div>
    @endif

    <p style="font-size:.8rem;opacity:.65;margin:0">Account: {{ $review->devotee?->name }} ({{ $review->devotee?->email ?? $review->devotee?->phone ?? 'no contact' }}). In the app the name shows as “{{ \App\Http\Resources\V1\ReviewResource::shortName($review->devotee?->name) }}”.</p>
</div>
