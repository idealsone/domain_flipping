/* Sends browser JavaScript errors to the owner's Error Log (no personal data, query strings are never sent). */
(() => {
  'use strict';
  let sent = 0; const seen = new Set();
  const token = () => (document.querySelector('meta[name=csrf-token]') || {}).content || '';
  function send(message, source, line) {
    if (sent >= 8 || /ResizeObserver loop/i.test(message)) return;
    try { if (source && new URL(source, location.href).origin !== location.origin) return; } catch (e) { return; }
    const key = message + '|' + source + '|' + line; if (seen.has(key)) return; seen.add(key); sent++;
    try { fetch('/client-error', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token() }, body: JSON.stringify({ message: String(message).slice(0, 900), source: source || '', line: line || 0, url: location.pathname }) }).catch(() => {}); } catch (e) { /* ignore */ }
  }
  addEventListener('error', e => send(e.message || 'Script error', e.filename, e.lineno));
  addEventListener('unhandledrejection', e => send('Unhandled promise rejection: ' + ((e.reason && e.reason.message) || e.reason), '', 0));
})();
