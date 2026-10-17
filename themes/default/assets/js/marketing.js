/**
 * Marketing campaign progress. On a sending campaign's page the
 * progress card carries data-marketing-progress="<process url>" and
 * data-csrf. Every few seconds this posts to that URL — which also
 * sends the next small batch, so a campaign progresses while someone
 * watches it even without cron — and updates the counters. When nothing
 * is left the page reloads to show the final state. Progressive
 * enhancement only: without JS the cron worker does the work.
 */
(function () {
    var card = document.querySelector('[data-marketing-progress]');
    if (!card || !window.fetch) {
        return;
    }

    var url = card.getAttribute('data-marketing-progress');
    var csrf = card.getAttribute('data-csrf') || '';
    var progress = card.querySelector('progress');
    var note = card.querySelector('[data-progress-note]');
    var busy = false;

    function setCount(key, value) {
        var el = card.querySelector('[data-count="' + key + '"]');
        if (el) {
            el.textContent = Number(value || 0).toLocaleString();
        }
    }

    function tick() {
        if (busy || document.hidden) {
            return;
        }
        busy = true;
        var body = new URLSearchParams();
        body.append('_csrf', csrf);

        fetch(url, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (json) {
                busy = false;
                if (!json || !json.data) {
                    return;
                }
                var data = json.data;
                var s = data.counts.status || {};
                var d = data.counts.delivery || {};
                var pending = (s.pending || 0) + (s.processing || 0);
                setCount('pending', pending);
                ['sent', 'failed', 'skipped', 'cancelled'].forEach(function (k) { setCount(k, s[k]); });
                setCount('delivered', d.delivered);
                setCount('unknown', data.counts.unknown);
                setCount('unsupported', data.counts.unsupported);
                if (progress) {
                    progress.max = Math.max(1, data.counts.total || 0);
                    progress.value = (data.counts.total || 0) - pending;
                }
                if (note && data.error) {
                    note.textContent = data.error;
                }
                if (data.status !== 'queued' && data.status !== 'sending' && data.status !== 'preparing') {
                    window.location.reload();
                }
            })
            .catch(function () { busy = false; });
    }

    tick();
    window.setInterval(tick, 4000);
})();
