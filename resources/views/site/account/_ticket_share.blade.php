{{--
    Download the ticket as an image, and share it: the phone's share sheet
    with the image (WhatsApp, Telegram, email …), or WhatsApp with the
    details and the ticket's link. $t: kind, title, temple, when, slot,
    people, name, amount, reference, url; $qr: the code as SVG.
--}}
<div class="ticket-share">
    <button class="btn primary" type="button" id="t-download">⬇️ {{ __('Download ticket') }}</button>
    <button class="btn" type="button" id="t-share" hidden>📤 {{ __('Share') }}</button>
    <a class="btn wa" id="t-whatsapp" target="_blank" rel="noopener"
       href="https://wa.me/?text={{ rawurlencode('🙏 '.$t['kind'].': '.$t['title'].' at '.$t['temple']."\n📅 ".$t['when'].($t['slot'] ? ', '.$t['slot'] : '')."\n👥 ".$t['people'].' · '.$t['name']."\n🔖 ".$t['reference']."\n".$t['url']) }}">
        <svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" style="vertical-align:-3px"><path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3a.5.5 0 0 0 0-.5l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.7.5-.1 1.5-.6 1.8-1.2s.2-1.1.1-1.2-.2-.1-.4-.2Z"/></svg>
        WhatsApp
    </a>
</div>
<script>
(function () {
    var t = @json($t), svg = @json($qr);
    var file = 'ticket-' + t.reference + '.png';

    function wrap(ctx, text, x, y, max, lh) {
        var words = String(text).split(' '), line = '', lines = 0;
        for (var i = 0; i < words.length; i++) {
            var test = line ? line + ' ' + words[i] : words[i];
            if (ctx.measureText(test).width > max && line) { ctx.fillText(line, x, y); y += lh; line = words[i]; lines++; } else { line = test; }
        }
        ctx.fillText(line, x, y);
        return y + lh;
    }

    // The ticket as a 1080×1640 image: brand band, the details, the code.
    function draw() {
        return new Promise(function (resolve, reject) {
            var img = new Image();
            img.onload = function () {
                var c = document.createElement('canvas'); c.width = 1080; c.height = 1640;
                var x = c.getContext('2d');
                x.fillStyle = '#F5EBDC'; x.fillRect(0, 0, 1080, 1640);
                var g = x.createLinearGradient(0, 0, 1080, 260); g.addColorStop(0, '#9B1B30'); g.addColorStop(1, '#3E2723');
                x.fillStyle = g; x.fillRect(0, 0, 1080, 230);
                x.fillStyle = '#C9A227'; x.fillRect(0, 230, 1080, 10);
                x.fillStyle = '#fff'; x.font = 'bold 58px Georgia, serif'; x.fillText(@json(config('brand.name')), 70, 115);
                x.font = '34px system-ui, sans-serif'; x.fillStyle = '#F7E29C'; x.fillText(t.kind.toUpperCase(), 70, 175);
                x.fillStyle = '#fffdf9'; x.strokeStyle = '#e7dccb'; x.lineWidth = 3;
                x.beginPath(); x.roundRect ? x.roundRect(50, 280, 980, 1280, 36) : x.rect(50, 280, 980, 1280); x.fill(); x.stroke();
                x.fillStyle = '#3E2723'; x.font = 'bold 60px Georgia, serif';
                var y = wrap(x, t.title, 100, 380, 880, 70);
                x.font = '40px system-ui, sans-serif'; x.fillStyle = '#7a6a60';
                y = wrap(x, t.temple, 100, y + 6, 880, 50);
                x.fillStyle = '#3E2723'; x.font = '42px system-ui, sans-serif';
                y += 20; x.fillText(@json(__('Date')) + ':  ' + t.when, 100, y); y += 64;
                if (t.slot) { x.fillText(@json(__('Time')) + ':  ' + t.slot, 100, y); y += 64; }
                x.fillText(@json(__('People')) + ':  ' + t.people + ' · ' + t.name, 100, y); y += 64;
                x.fillText(@json(__('Amount')) + ':  ' + t.amount, 100, y); y += 30;
                if (t.extra) { x.fillStyle = '#7a6a60'; x.font = '36px system-ui, sans-serif'; y += 34; x.fillText(t.extra, 100, y); y += 10; }
                var q = 440, qx = (1080 - q) / 2, qy = Math.max(y + 60, 800);
                x.fillStyle = '#fff'; x.fillRect(qx - 20, qy - 20, q + 40, q + 40);
                x.drawImage(img, qx, qy, q, q);
                x.fillStyle = '#9B1B30'; x.font = 'bold 44px ui-monospace, monospace'; x.textAlign = 'center';
                x.fillText(t.reference, 540, qy + q + 90);
                x.fillStyle = '#7a6a60'; x.font = '30px system-ui, sans-serif';
                x.fillText(@json(__('Show this code at the temple counter')), 540, qy + q + 140);
                x.fillText(@json(parse_url(\App\Support\Seo::url('/'), PHP_URL_HOST)), 540, 1610);
                c.toBlob(function (b) { b ? resolve(b) : reject(); }, 'image/png');
            };
            img.onerror = reject;
            img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
        });
    }

    document.getElementById('t-download').addEventListener('click', function () {
        draw().then(function (blob) {
            var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = file;
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(function () { URL.revokeObjectURL(a.href); }, 4000);
        }).catch(function () { window.print(); });
    });

    // The phone's own share sheet, with the ticket image, where it can take files.
    var share = document.getElementById('t-share');
    if (navigator.canShare && navigator.canShare({ files: [new File([''], file, { type: 'image/png' })] })) {
        share.hidden = false;
        share.addEventListener('click', function () {
            draw().then(function (blob) {
                return navigator.share({ files: [new File([blob], file, { type: 'image/png' })], title: t.title, text: t.title + ' · ' + t.temple + ' · ' + t.when });
            }).catch(function () {});
        });
    }
})();
</script>
