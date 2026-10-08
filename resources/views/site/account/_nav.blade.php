@php($u = \App\Support\Seo::class)
@push('head')
    <style>
        .acct { display: grid; grid-template-columns: 220px 1fr; gap: 24px; margin-top: 16px; }
        .acct nav { display: flex; flex-direction: column; gap: 4px; }
        .acct nav a { padding: 9px 12px; border-radius: 10px; text-decoration: none; color: var(--deep); }
        .acct nav a.on, .acct nav a:hover { background: #fffdf9; border: 1px solid var(--line); padding: 8px 11px; }
        .acct nav form button { font: inherit; background: none; border: 0; color: var(--muted); padding: 9px 12px; cursor: pointer; text-align: left; }
        @media (max-width: 760px) { .acct { grid-template-columns: 1fr; } .acct nav { flex-direction: row; overflow-x: auto; white-space: nowrap; padding-bottom: 4px; } }
        .list { display: grid; gap: 10px; }
        .item { display: flex; justify-content: space-between; gap: 12px; align-items: center; text-decoration: none; color: var(--deep); }
        .item small { color: var(--muted); display: block; }
        .pill { font-size: .75rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; white-space: nowrap; background: #f1e7d6; }
        .pill.confirmed, .pill.paid { background: #e2f1dc; color: #23511a; }
        .pill.verified { background: #dcebf7; color: #1b4a72; }
        .pill.pending_payment { background: #fff1d6; color: #7a4b00; }
        .pill.cancelled, .pill.expired, .pill.failed, .pill.refunded { background: #eee; color: #666; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
        .stats .card b { display: block; font-family: Georgia, serif; font-size: 1.6rem; color: var(--kumkum); }
    </style>
@endpush
<nav aria-label="My account">
    <a href="{{ $u::url('account') }}" @class(['on' => request()->is('account')])>🏠 {{ __('Overview') }}</a>
    <a href="{{ $u::url('account/bookings') }}" @class(['on' => request()->is('account/bookings*', 'account/tickets*')])>🎟️ {{ __('Bookings & tickets') }}</a>
    <a href="{{ $u::url('account/donations') }}" @class(['on' => request()->is('account/donations')])>🪔 {{ __('Offerings') }}</a>
    <a href="{{ $u::url('account/saved') }}" @class(['on' => request()->is('account/saved')])>❤️ {{ __('Saved temples') }}</a>
    <a href="{{ $u::url('account/passport') }}" @class(['on' => request()->is('account/passport')])>📿 {{ __('Temple passport') }}</a>
    <a href="{{ $u::url('account/profile') }}" @class(['on' => request()->is('account/profile')])>👤 {{ __('Profile') }}</a>
    <form method="post" action="{{ $u::url('logout') }}">@csrf<button type="submit">↪ {{ __('Sign out') }}</button></form>
</nav>
