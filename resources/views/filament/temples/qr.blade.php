{{-- The check-in code for one temple, ready to print for its gate. --}}
<div style="text-align:center">
    <div style="max-width:280px;margin:0 auto;background:#fff;padding:12px;border-radius:12px">
        {!! \App\Support\TempleQr::svg($temple) !!}
    </div>
    <p style="margin-top:12px;font-weight:600">{{ $temple->name }}</p>
    <p style="font-size:.85rem;opacity:.75;margin-top:4px">
        Print this and display it at the entrance. Devotees scan it from the temple's page in the app
        to collect a <strong>verified</strong> stamp. A phone camera opening it shows whether it is genuine.
    </p>
    <p style="font-size:.75rem;opacity:.6;margin-top:8px;word-break:break-all">{{ \App\Support\TempleQr::url($temple) }}</p>
</div>
