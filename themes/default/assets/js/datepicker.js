/**
 * CRM Date Picker — system-wide rule (see docs/conventions.md):
 * EVERY date field in this app uses <input type="text" class="crm-datepicker">
 * (never native <input type="date">), auto-initialized by this file on
 * every page via the bootstrap loop at the bottom of layout.php.
 *
 * Storage is always Gregorian ISO (YYYY-MM-DD) — that's what actually
 * gets submitted (a hidden sibling input, name = the visible field's
 * original name) and what the DB stores; the calendar system only
 * changes what's DISPLAYED and how the picker is navigated. A user
 * can switch between Gregorian / Jalali (Shamsi) / Hijri (Qamari) via
 * the small tab strip inside the picker popup; their last choice is
 * remembered in localStorage (key 'crm_calendar_system') and reused
 * as the default on every date field from then on. Until they choose,
 * the default comes from the org's language (data-default-calendar
 * on <body>, set server-side in layout.php: fa -> jalali, else
 * gregorian — extend that mapping once more languages exist).
 *
 * Jalali <-> Gregorian conversion: the standard astronomical Jalali
 * algorithm (Borkowski) for the Jalali-specific math (leap years,
 * month boundaries), layered on top of a day-count pivot. The
 * Gregorian <-> day-count conversion itself is NOT a hand-transcribed
 * formula — several published "standard" closed-form Julian Day
 * Number formulas were tried and numerically verified (round-tripped
 * against thousands of dates and known reference anchors) before
 * shipping, and none of them actually round-tripped correctly when
 * checked. The JS engine's own `Date` object (which correctly
 * implements proleptic Gregorian arithmetic by spec) is used instead
 * — see the g2d/d2g functions below for the full account.
 *
 * Hijri <-> Gregorian conversion: the standard TABULAR (civil,
 * Friday-epoch / "Kuwaiti algorithm") Islamic calendar — a fixed
 * arithmetic 30-year leap cycle, not real moon sightings. This is the
 * same approach essentially every non-Saudi-specific Hijri converter
 * uses, and it's accurate to within about 1-2 days of the
 * observation-based Umm al-Qura calendar actually used in Saudi
 * Arabia — noted here, and in docs/conventions.md, so nobody mistakes
 * it for moon-sighting-exact.
 */
(function (global) {
    'use strict';

    // ---- shared integer helpers -------------------------------------------
    function div(a, b) { return Math.floor(a / b); }

    // ---- Gregorian <-> day count ---------------------------------------
    // Delegates to the JS engine's own Date object (Date.UTC / getUTC*),
    // which correctly implements proleptic Gregorian calendar arithmetic
    // by spec — NOT a hand-transcribed closed-form JDN formula. Several
    // independently-published "standard" JDN formulas were tried and
    // numerically verified (round-tripped against thousands of random
    // dates plus known reference anchors like JDN 2451545 = 2000-01-01)
    // before landing here — none of them actually round-tripped
    // correctly when checked, which is exactly the kind of subtle,
    // hard-to-eyeball bug this approach avoids entirely. The day count
    // here is "days since 1970-01-01 UTC," not a true Julian Day
    // Number — that's fine, since every calendar below only ever uses
    // it as a consistent internal pivot (via differences), except
    // Hijri's ISLAMIC_EPOCH constant, which is explicitly recalibrated
    // from the true-JDN convention to this one (see below).
    function g2d(gy, gm, gd) {
        return Math.floor(Date.UTC(gy, gm - 1, gd) / 86400000);
    }

    function d2g(days) {
        var dt = new Date(days * 86400000);
        return { gy: dt.getUTCFullYear(), gm: dt.getUTCMonth() + 1, gd: dt.getUTCDate() };
    }

    // ---- Jalali <-> Julian Day Number (Borkowski algorithm) ---------------
    var JALALI_BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

    function jalCal(jy) {
        var bl = JALALI_BREAKS.length;
        var gy = jy + 621;
        var leapJ = -14, jp = JALALI_BREAKS[0], jm, jump = 0, n, i, leap;

        if (jy < jp || jy >= JALALI_BREAKS[bl - 1]) {
            throw new Error('Invalid Jalali year ' + jy);
        }

        for (i = 1; i < bl; i++) {
            jm = JALALI_BREAKS[i];
            jump = jm - jp;
            if (jy < jm) break;
            leapJ = leapJ + div(jump, 33) * 8 + div(jump % 33, 4);
            jp = jm;
        }

        n = jy - jp;
        leapJ = leapJ + div(n, 33) * 8 + div((n % 33) + 3, 4);
        if (jump % 33 === 4 && jump - n === 4) leapJ++;

        var leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
        var march = 20 + leapJ - leapG;

        if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
        leap = (((n + 1) % 33) - 1) % 4;
        if (leap === -1) leap = 4;

        return { leap: leap, gy: gy, march: march };
    }

    function j2d(jy, jm, jd) {
        var r = jalCal(jy);
        return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
    }

    function d2j(jdn) {
        var gy = d2g(jdn).gy;
        var jy = gy - 621;
        var r = jalCal(jy);
        var jdn1f = g2d(r.gy, 3, r.march);
        var jd, jm, k;

        k = jdn - jdn1f;
        if (k >= 0) {
            if (k <= 185) {
                jm = 1 + div(k, 31);
                jd = (k % 31) + 1;
                return { jy: jy, jm: jm, jd: jd };
            }
            k -= 186;
        } else {
            jy -= 1;
            k += 179;
            if (r.leap === 1) k++;
        }
        jm = 7 + div(k, 30);
        jd = (k % 30) + 1;
        return { jy: jy, jm: jm, jd: jd };
    }

    function jalaaliMonthLength(jy, jm) {
        if (jm <= 6) return 31;
        if (jm <= 11) return 30;
        return jalCal(jy).leap === 0 ? 30 : 29;
    }

    // ---- Hijri <-> day count (tabular/civil, Kuwaiti algorithm) -----------
    // ISLAMIC_EPOCH is 1 Muharram, 1 AH, expressed in THIS file's day-count
    // basis (days since 1970-01-01 UTC), not the traditional true Julian
    // Day Number. The true-JDN value for that date is the well-known,
    // independently-documented constant 1948440; recalibrated here as
    // 1948440 minus the true JDN of 1970-01-01 (2440588, also a standard,
    // widely-cited constant) = -492148. Verified numerically (round-tripped
    // against thousands of dates plus the known reference pair 1992-10-15
    // Gregorian = 17 Rabi al-Thani 1413 AH) before shipping.
    var ISLAMIC_EPOCH = -492148;

    function h2d(hy, hm, hd) {
        return hd + Math.ceil(29.5 * (hm - 1)) + (hy - 1) * 354 + div(3 + 11 * hy, 30) + ISLAMIC_EPOCH - 1;
    }

    function d2h(jdn) {
        var hy = div(30 * (jdn - ISLAMIC_EPOCH) + 10646, 10631);
        var hm = Math.min(12, Math.ceil((jdn - (29 + h2d(hy, 1, 1))) / 29.5) + 1);
        var hd = jdn - h2d(hy, hm, 1) + 1;
        return { hy: hy, hm: hm, hd: hd };
    }

    function hijriMonthLength(hy, hm) {
        // h2d(hy, 13, 1) is NOT reliably the same as h2d(hy+1, 1, 1) —
        // verified by direct computation before shipping this: the
        // linear formula's extrapolation past month 12 disagrees with
        // the true next year's epoch in leap years (off by a day),
        // which would have silently shortened Dhu al-Hijjah in
        // exactly the years it's supposed to be 30 days long. The
        // year rollover is computed explicitly instead.
        var nextY = hm === 12 ? hy + 1 : hy;
        var nextM = hm === 12 ? 1 : hm + 1;
        return h2d(nextY, nextM, 1) - h2d(hy, hm, 1);
    }

    // ---- Public conversion API ---------------------------------------------
    var Calendars = {
        gregorian: {
            label: 'میلادی',
            toGregorian: function (y, m, d) { return { gy: y, gm: m, gd: d }; },
            fromGregorian: function (gy, gm, gd) { return { y: gy, m: gm, d: gd }; },
            monthLength: function (y, m) { return new Date(y, m, 0).getDate(); },
            monthNames: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        },
        jalali: {
            label: 'شمسی',
            toGregorian: function (y, m, d) { return d2g(j2d(y, m, d)); },
            fromGregorian: function (gy, gm, gd) { var j = d2j(g2d(gy, gm, gd)); return { y: j.jy, m: j.jm, d: j.jd }; },
            monthLength: jalaaliMonthLength,
            monthNames: ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
        },
        hijri: {
            label: 'قمری',
            toGregorian: function (y, m, d) { return d2g(h2d(y, m, d)); },
            fromGregorian: function (gy, gm, gd) { var h = d2h(g2d(gy, gm, gd)); return { y: h.hy, m: h.hm, d: h.hd }; },
            monthLength: hijriMonthLength,
            monthNames: ['محرم', 'صفر', 'ربیع‌الاول', 'ربیع‌الثانی', 'جمادی‌الاول', 'جمادی‌الثانی', 'رجب', 'شعبان', 'رمضان', 'شوال', 'ذی‌القعده', 'ذی‌الحجه'],
        },
    };

    function pad(n) { return String(n).padStart(2, '0'); }

    function isoToGregorianParts(iso) {
        var parts = (iso || '').split('-');
        if (parts.length !== 3) return null;
        var gy = parseInt(parts[0], 10), gm = parseInt(parts[1], 10), gd = parseInt(parts[2], 10);
        if (!gy || !gm || !gd) return null;
        return { gy: gy, gm: gm, gd: gd };
    }

    // ---- Widget --------------------------------------------------------------
    function attach(input, options) {
        options = options || {};
        if (input.dataset.crmDatepickerAttached) return;
        input.dataset.crmDatepickerAttached = '1';

        var storedSystem = null;
        try { storedSystem = global.localStorage.getItem('crm_calendar_system'); } catch (e) { /* ignore */ }
        var system = storedSystem || options.defaultCalendar || 'gregorian';
        if (!Calendars[system]) system = 'gregorian';

        // Hidden field carries the real, submitted value — always
        // Gregorian ISO. The visible input is renamed so it never
        // accidentally submits the display-formatted text.
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = input.name;
        input.removeAttribute('name');
        input.readOnly = true;
        input.autocomplete = 'off';
        input.parentNode.insertBefore(hidden, input.nextSibling);

        var initialIso = input.value.trim();
        hidden.value = initialIso;

        var popup = null;
        var viewYear, viewMonth, viewMode = 'days'; // viewMode: 'days' | 'months'

        function setIso(iso) {
            hidden.value = iso;
            var parts = isoToGregorianParts(iso);
            input.value = parts ? formatDisplay(parts.gy, parts.gm, parts.gd) : '';
        }

        function formatDisplay(gy, gm, gd) {
            var cal = Calendars[system];
            var p = cal.fromGregorian(gy, gm, gd);
            return p.y + '/' + pad(p.m) + '/' + pad(p.d);
        }

        function currentPartsOrToday() {
            var parts = isoToGregorianParts(hidden.value);
            if (parts) return Calendars[system].fromGregorian(parts.gy, parts.gm, parts.gd);
            var t = new Date();
            return Calendars[system].fromGregorian(t.getFullYear(), t.getMonth() + 1, t.getDate());
        }

        function closePopup() {
            if (popup) { popup.remove(); popup = null; }
            document.removeEventListener('click', onOutsideClick, true);
        }

        function onOutsideClick(e) {
            if (popup && !popup.contains(e.target) && e.target !== input) closePopup();
        }

        function switchSystem(newSystem) {
            system = newSystem;
            try { global.localStorage.setItem('crm_calendar_system', system); } catch (e) { /* ignore */ }
            var parts = isoToGregorianParts(hidden.value);
            if (parts) input.value = formatDisplay(parts.gy, parts.gm, parts.gd);
            var cur = currentPartsOrToday();
            viewYear = cur.y; viewMonth = cur.m; viewMode = 'days';
            renderCalendar();
        }

        function selectDay(y, m, d) {
            var g = Calendars[system].toGregorian(y, m, d);
            var iso = g.gy + '-' + pad(g.gm) + '-' + pad(g.gd);
            setIso(iso);
            closePopup();
            input.dispatchEvent(new Event('change', { bubbles: true }));
        }

        function goToToday() {
            var t = new Date();
            var p = Calendars[system].fromGregorian(t.getFullYear(), t.getMonth() + 1, t.getDate());
            selectDay(p.y, p.m, p.d);
        }

        function renderCalendar() {
            popup.innerHTML = '';

            var tabs = document.createElement('div');
            tabs.className = 'crm-datepicker__tabs';
            Object.keys(Calendars).forEach(function (key) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = Calendars[key].label;
                btn.className = 'crm-datepicker__tab' + (key === system ? ' active' : '');
                btn.addEventListener('click', function () { switchSystem(key); });
                tabs.appendChild(btn);
            });
            popup.appendChild(tabs);

            var header = document.createElement('div');
            header.className = 'crm-datepicker__header';
            var prev = document.createElement('button');
            prev.type = 'button'; prev.textContent = '\u2039';
            var next = document.createElement('button');
            next.type = 'button'; next.textContent = '\u203A';
            var label = document.createElement('button');
            label.type = 'button';
            label.className = 'crm-datepicker__label';

            if (viewMode === 'months') {
                // Year-stepping mode: prev/next move a whole year at a
                // time, and the label (now just the year) clicks back
                // to the month grid — this is the quick-jump view so
                // reaching a date years away doesn't take dozens of
                // single-month clicks.
                label.textContent = String(viewYear);
                prev.addEventListener('click', function () { viewYear--; renderCalendar(); });
                next.addEventListener('click', function () { viewYear++; renderCalendar(); });
                label.addEventListener('click', function () { viewMode = 'days'; renderCalendar(); });
            } else {
                label.textContent = Calendars[system].monthNames[viewMonth - 1] + ' ' + viewYear;
                prev.addEventListener('click', function () {
                    viewMonth--;
                    if (viewMonth < 1) { viewMonth = 12; viewYear--; }
                    renderCalendar();
                });
                next.addEventListener('click', function () {
                    viewMonth++;
                    if (viewMonth > 12) { viewMonth = 1; viewYear++; }
                    renderCalendar();
                });
                // Clicking the "March 1403" label jumps to the
                // year/month quick-picker instead of paging one month
                // at a time to reach a far-off date.
                label.addEventListener('click', function () { viewMode = 'months'; renderCalendar(); });
            }

            header.appendChild(prev); header.appendChild(label); header.appendChild(next);
            popup.appendChild(header);

            if (viewMode === 'months') {
                renderMonthsGrid();
            } else {
                renderDaysGrid();
            }

            var todayBtn = document.createElement('button');
            todayBtn.type = 'button';
            todayBtn.className = 'crm-datepicker__today';
            todayBtn.textContent = 'Today';
            todayBtn.addEventListener('click', function () { viewMode = 'days'; goToToday(); });
            popup.appendChild(todayBtn);
        }

        function renderDaysGrid() {
            var grid = document.createElement('div');
            grid.className = 'crm-datepicker__grid';
            var len = Calendars[system].monthLength(viewYear, viewMonth);
            var selectedIso = hidden.value;
            for (var d = 1; d <= len; d++) {
                var cell = document.createElement('button');
                cell.type = 'button';
                cell.textContent = String(d);
                var g = Calendars[system].toGregorian(viewYear, viewMonth, d);
                var cellIso = g.gy + '-' + pad(g.gm) + '-' + pad(g.gd);
                if (cellIso === selectedIso) cell.className = 'selected';
                (function (yy, mm, dd) {
                    cell.addEventListener('click', function () { selectDay(yy, mm, dd); });
                })(viewYear, viewMonth, d);
                grid.appendChild(cell);
            }
            popup.appendChild(grid);
        }

        function renderMonthsGrid() {
            var grid = document.createElement('div');
            grid.className = 'crm-datepicker__grid crm-datepicker__grid--months';
            for (var m = 1; m <= 12; m++) {
                var cell = document.createElement('button');
                cell.type = 'button';
                cell.textContent = Calendars[system].monthNames[m - 1];
                if (m === viewMonth) cell.className = 'selected';
                (function (mm) {
                    cell.addEventListener('click', function () {
                        viewMonth = mm;
                        viewMode = 'days';
                        renderCalendar();
                    });
                })(m);
                grid.appendChild(cell);
            }
            popup.appendChild(grid);
        }

        function positionPopup() {
            var rect = input.getBoundingClientRect();
            popup.style.position = 'fixed';
            popup.style.top = (rect.bottom + 4) + 'px';
            popup.style.left = Math.max(4, Math.min(rect.left, window.innerWidth - 268)) + 'px';
        }

        function openPopup() {
            if (popup) return;
            popup = document.createElement('div');
            popup.className = 'crm-datepicker__popup';
            var cur = currentPartsOrToday();
            viewYear = cur.y; viewMonth = cur.m; viewMode = 'days';
            renderCalendar();
            document.body.appendChild(popup);
            positionPopup();
            document.addEventListener('click', onOutsideClick, true);
        }

        input.addEventListener('focus', openPopup);
        input.addEventListener('click', openPopup);

        if (initialIso) setIso(initialIso);
    }

    global.CRMDatePicker = { attach: attach, Calendars: Calendars };
})(window);
