/* Keeps a broken page from trapping a visitor: public pages fall back to the home page, working pages show a calm recovery bar. */
(() => {
  'use strict';
  const path = location.pathname.replace(/\/+$/, '') || '/';
  const working = /^\/(workspace|checkout|admin)(\.html)?$/.test(path);
  const now = () => Date.now();
  const store = { get(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }, set(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* storage blocked */ } } };
  let hits = [];
  function ownError(file) { try { return !file || new URL(file, location.href).origin === location.origin; } catch (e) { return false; } }
  function bar() {
    if (document.getElementById('cvCrash')) return;
    const d = document.createElement('div'); d.id = 'cvCrash'; d.setAttribute('role', 'alert');
    d.style.cssText = 'position:fixed;left:50%;bottom:18px;transform:translateX(-50%);z-index:2147483000;max-width:92vw;background:#0f172a;color:#fff;padding:12px 16px;border-radius:12px;font:14px system-ui,sans-serif;box-shadow:0 10px 30px rgba(0,0,0,.35);display:flex;gap:12px;align-items:center;flex-wrap:wrap';
    d.innerHTML = '<span>Something went wrong on this page. Your work is saved automatically.</span><button type="button" style="border:0;border-radius:8px;padding:7px 12px;font-weight:700;cursor:pointer" data-r>Reload</button><a href="/" style="color:#9bbcff;font-weight:700">Home</a>';
    d.querySelector('[data-r]').onclick = () => location.reload();
    (document.body || document.documentElement).append(d);
  }
  function home() {
    if (path === '/' || now() - Number(store.get('cv-crash-home') || 0) < 300000) return false;
    store.set('cv-crash-home', String(now())); location.replace('/'); return true;
  }
  function fault(file) {
    if (!ownError(file)) return;
    hits = hits.filter(t => now() - t < 10000); hits.push(now());
    if (hits.length < 3) return;
    if (working) bar(); else home();
  }
  addEventListener('error', e => { if (e.target && e.target !== window) return; fault(e.filename); });
  addEventListener('unhandledrejection', () => fault(''));
  addEventListener('load', () => setTimeout(() => {
    if (working || path === '/') return;
    const text = (document.body && document.body.innerText || '').trim();
    if (text.length < 40) home();
  }, 4000));
})();
// Push notifications were retired (3.25.1). A browser that still has the old OneSignal worker, or a stale cached page,
// could show the old "allow notifications" prompt: remove any such worker on every page.
try {
  if ('serviceWorker' in navigator) navigator.serviceWorker.getRegistrations().then(rs => rs.forEach(r => {
    const u = String((r.active || r.waiting || r.installing || {}).scriptURL || '');
    if (/onesignal/i.test(u)) r.unregister();
  })).catch(() => {});
} catch (_) {}

// "On this page" lists and other in-page links (href="#section") must stay on this page. Most pages carry <base href="/">,
// which made the browser resolve "#section" against the site root and open the home page instead.
try {
  document.addEventListener('click', (e) => {
    if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    const a = e.target && e.target.closest ? e.target.closest('a[href^="#"]') : null;
    if (!a || a.target === '_blank' || !document.querySelector('base[href]')) return;
    const hash = a.getAttribute('href');
    if (!hash || hash === '#') return;
    e.preventDefault();
    if (location.hash === hash) {
      let el = null;
      try { el = document.getElementById(decodeURIComponent(hash.slice(1))); } catch (_) {}
      if (el) el.scrollIntoView({ block: 'start' });
    } else {
      location.hash = hash; // changes only the part after #, so the page stays and scrolls to the section
    }
  });
} catch (_) {}
