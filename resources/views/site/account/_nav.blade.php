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
        .ticket { display: grid; grid-template-columns: 1fr 260px; background: #fffdf9; border: 1px solid var(--line); border-radius: 20px; overflow: hidden; box-shadow: 0 6px 24px rgba(62,39,35,.08); }
        .ticket .t-main { padding: 22px 24px; border-top: 6px solid var(--kumkum); }
        .ticket .t-kind { display: flex; justify-content: space-between; align-items: center; gap: 8px; text-transform: uppercase; letter-spacing: .06em; font-size: .78rem; color: var(--muted); font-weight: 700; }
        .ticket .t-title { margin: 10px 0 2px; font-size: 1.6rem; }
        .ticket .t-temple { margin: 0 0 14px; color: var(--muted); }
        .ticket dl { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 12px 18px; margin: 0; }
        .ticket dt { font-size: .75rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
        .ticket dd { margin: 2px 0 0; font-weight: 600; }
        .ticket .t-stub { background: #f8efe0; border-left: 2px dashed var(--line); padding: 20px; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 6px; position: relative; }
        .ticket .t-stub::before, .ticket .t-stub::after { content: ""; position: absolute; left: -13px; width: 24px; height: 24px; border-radius: 50%; background: var(--sandal); }
        .ticket .t-stub::before { top: -12px; } .ticket .t-stub::after { bottom: -12px; }
        .ticket .qr { width: 190px; max-width: 100%; background: #fff; border-radius: 12px; padding: 6px; }
        .ticket .t-ref { font-family: ui-monospace, monospace; font-size: 1.1rem; color: var(--kumkum); letter-spacing: .05em; }
        .ticket small { color: var(--muted); }
        @media (max-width: 640px) { .ticket dl { grid-template-columns: repeat(2, minmax(0, 1fr)); } .ticket-share { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); } .ticket-share > *, .ticket-share form button { width: 100%; } .ticket { grid-template-columns: 1fr; } .ticket .t-stub { border-left: 0; border-top: 2px dashed var(--line); } .ticket .t-stub::before, .ticket .t-stub::after { display: none; } }
        @media print { header.top, footer.bottom, .acct nav, .ticket-share, .crumbs, .flash { display: none !important; } .acct { display: block; } }
        .ticket-share { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }
        .btn.wa { background: #25D366; border-color: #25D366; color: #fff; }
        .yatras { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 12px; }
        .yatra-card { display: grid; gap: 4px; text-decoration: none; color: var(--deep); }
        .yatra-card:hover { border-color: var(--saffron); }
        .yatra-card small { color: var(--muted); }
        .yatra-card .pill { justify-self: start; }
        .pill.planning { background: #fff1d6; color: #7a4b00; } .pill.in_progress { background: #dcebf7; color: #1b4a72; } .pill.completed { background: #e2f1dc; color: #23511a; } .pill.abandoned { background: #eee; color: #666; }
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
        .stats .card b { display: block; font-family: Georgia, serif; font-size: 1.6rem; color: var(--kumkum); }
    </style>
@endpush
<nav aria-label="My account">
    <a href="{{ $u::url('account') }}" @class(['on' => request()->is('account')])>🏠 {{ __('Overview') }}</a>
    <a href="{{ $u::url('account/bookings') }}" @class(['on' => request()->is('account/bookings*', 'account/tickets*')])>🎟️ {{ __('Bookings & tickets') }}</a>
    <a href="{{ $u::url('account/yatras') }}" @class(['on' => request()->is('account/yatras*')])>🧭 {{ __('Yatra planner') }}</a>
    <a href="{{ $u::url('account/donations') }}" @class(['on' => request()->is('account/donations')])>🪔 {{ __('Offerings') }}</a>
    <a href="{{ $u::url('account/saved') }}" @class(['on' => request()->is('account/saved')])>❤️ {{ __('Saved temples') }}</a>
    <a href="{{ $u::url('account/passport') }}" @class(['on' => request()->is('account/passport')])>📿 {{ __('Temple passport') }}</a>
    <a href="{{ $u::url('account/profile') }}" @class(['on' => request()->is('account/profile')])>👤 {{ __('Profile') }}</a>
    <form method="post" action="{{ $u::url('logout') }}">@csrf<button type="submit">↪ {{ __('Sign out') }}</button></form>
</nav>
