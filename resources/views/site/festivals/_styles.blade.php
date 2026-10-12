@push('head')
    <style>
        .fgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
        .fcard { display: flex; gap: 14px; align-items: flex-start; text-decoration: none; color: var(--deep); }
        a.fcard:hover { border-color: var(--saffron); }
        .fdate { flex: none; width: 60px; text-align: center; background: var(--kumkum); color: #fff; border-radius: 12px; padding: 6px 0; line-height: 1.1; }
        .fdate b { display: block; font-size: 1.45rem; }
        .fdate small { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; }
        .fdate.vrat { background: #7a4b00; }
        .fdate.past { background: #b9ab9c; }
        .fcard p { margin: 3px 0 0; font-size: .86rem; color: var(--muted); }
        .ftable { width: 100%; border-collapse: collapse; background: #fffdf9; border: 1px solid var(--line); border-radius: 14px; overflow: hidden; }
        .ftable th, .ftable td { text-align: left; padding: 10px 14px; border-bottom: 1px solid var(--line); font-size: .93rem; vertical-align: top; }
        .ftable th { background: #f5ead6; font-weight: 700; }
        .ftable tr.next td { background: #fff4dc; font-weight: 600; }
        .ftable tr.past td { color: var(--muted); }
        .tag { font-size: .7rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #f5ead6; margin-left: 6px; white-space: nowrap; }
        .tag.major { background: var(--saffron); color: #fff; }
        .answer { background: #fffdf9; border: 1px solid var(--line); border-left: 5px solid var(--saffron); border-radius: 14px; padding: 16px 18px; font-size: 1.08rem; }
        .answer b { color: var(--kumkum); }
        .chips { display: flex; flex-wrap: wrap; gap: 8px; }
        .chips a { background: #fffdf9; border: 1px solid var(--line); border-radius: 999px; padding: 6px 14px; text-decoration: none; color: var(--deep); font-size: .9rem; }
        .chips a:hover { border-color: var(--saffron); }
        .tgrid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
        .tgrid a { text-decoration: none; color: var(--deep); }
        .tgrid small { display: block; color: var(--muted); }
        details.faq { background: #fffdf9; border: 1px solid var(--line); border-radius: 12px; padding: 10px 14px; margin-bottom: 8px; }
        details.faq summary { cursor: pointer; font-weight: 600; }
        .monthnav { display: flex; justify-content: space-between; gap: 10px; margin-top: 20px; }
    </style>
@endpush
