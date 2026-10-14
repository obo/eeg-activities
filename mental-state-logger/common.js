// Shared by all pages: event logging (every interaction -> event-logger.php) and the live device status image.
'use strict';

const MSL = {
  page: document.body.dataset.page || location.pathname.split('/').pop() || 'index.php',

  log(event, detail = '') {
    const fd = new FormData();
    fd.append('event', event);
    fd.append('detail', detail);
    fd.append('page', MSL.page);
    fd.append('client_ms', String(Date.now()));
    // sendBeacon survives page unloads (form submits, navigation); fetch keepalive as a fallback
    if (!(navigator.sendBeacon && navigator.sendBeacon('event-logger.php', fd))) {
      fetch('event-logger.php', {method: 'POST', body: fd, keepalive: true}).catch(() => {});
    }
  },

  label(el) {
    return (el.dataset.name || el.name || el.id || el.textContent || '').trim().slice(0, 80);
  },
};

// page load / reload
(() => {
  const nav = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
  MSL.log(nav && nav.type === 'reload' ? 'reload' : 'load', nav ? nav.type : '');
})();

// clicks on any button (toggles report their new state themselves, see index.php)
document.addEventListener('click', (e) => {
  const b = e.target.closest('button, a, input[type=submit], input[type=button], option');
  if (b && !b.classList.contains('toggle')) MSL.log('click', MSL.label(b));
}, true);

// every key typed into any field, with the key (passwords included: they are not secret here)
document.addEventListener('keydown', (e) => {
  const t = e.target;
  if (t.matches && t.matches('input, textarea, select')) MSL.log('key', `${MSL.label(t)}: ${e.key}`);
}, true);

// value changes of selects / number fields etc.
document.addEventListener('change', (e) => {
  const t = e.target;
  if (t.matches && t.matches('select, input[type=number], input[type=checkbox], input[type=radio]')) {
    MSL.log('change', `${MSL.label(t)} = ${t.value}`);
  }
}, true);

window.addEventListener('pagehide', () => MSL.log('leave'));

// Live status image: poll the static file devices/status-image-<device>.png at up to 5 fps. The browser keeps
// the HTTP connection alive and revalidates (If-None-Match / If-Modified-Since); the server answers 304 when the
// feeder has not written a new frame, so an unchanged image costs a few hundred bytes.
MSL.status = {
  period: 200,          // ms -> 5 fps
  etag: null,
  url: null,
  timer: null,
  lost: false,
  staleAfter: Number((document.getElementById('status') || {dataset: {}}).dataset.staleAfter || 3),

  setLost(lost) {
    const box = document.getElementById('status');
    if (box) box.classList.toggle('lost', lost);
    if (lost !== this.lost) MSL.log('status', lost ? 'connection lost' : 'connection ok');
    this.lost = lost;
  },

  setDevice(dev) {
    const img = document.getElementById('status-image');
    if (!img) return;
    img.dataset.device = dev;
    this.etag = null;
    if (!dev) { img.src = 'devices/no-device.png'; }
    this.url = dev ? `devices/status-image-${dev}.png` : null;
  },

  async tick() {
    const t0 = performance.now();
    const img = document.getElementById('status-image');
    if (img && this.url && !document.hidden) {
      try {
        const r = await fetch(this.url, {cache: 'no-cache'});
        const tag = r.headers.get('ETag') || r.headers.get('Last-Modified');
        if (r.ok && (tag === null || tag !== this.etag)) {
          const blob = await r.blob();
          const old = img.src;
          img.src = URL.createObjectURL(blob);
          if (old.startsWith('blob:')) URL.revokeObjectURL(old);
          this.etag = tag;
        }
        // lost: no image on the server, or the feeder has not sent a new frame for a while (both times are
        // the server's, so the browser's clock does not matter)
        const age = (Date.parse(r.headers.get('Date')) - Date.parse(r.headers.get('Last-Modified'))) / 1000;
        this.setLost(!r.ok || age > this.staleAfter);
      } catch (err) {
        this.setLost(true);   // the server is not reachable
      }
    } else if (!this.url) {
      this.setLost(false);
    }
    this.timer = setTimeout(() => this.tick(), Math.max(0, this.period - (performance.now() - t0)));
  },
};
(() => {
  const img = document.getElementById('status-image');
  if (img) { MSL.status.setDevice(img.dataset.device || ''); MSL.status.tick(); }
})();

// interface language: reload the page with ?lang=xx (handled server-side, remembered in the session + cookie)
(() => {
  const sel = document.getElementById('ui-lang');
  if (!sel) return;
  sel.addEventListener('change', () => {
    MSL.log('ui-language', sel.value);
    const u = new URL(location.href);
    u.searchParams.set('lang', sel.value);
    location.href = u.toString();
  });
})();
