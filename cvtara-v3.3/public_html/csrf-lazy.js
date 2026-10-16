/* Pages that are served ready-made (no session yet) carry an empty security token. This asks for one the first time a request that
   changes something is sent, so visitors who only read pages never create a session. Signed-in pages already carry a token. */
(function () {
  'use strict';
  var meta = function () { return document.querySelector('meta[name=csrf-token]'); };
  var pending = null;
  function token() {
    var m = meta();
    if (m && m.content) return Promise.resolve(m.content);
    if (!pending) {
      pending = window.fetch('/api/csrf', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { var el = meta(); if (el) el.content = j.token; pending = null; return j.token; })
        .catch(function (e) { pending = null; throw e; });
    }
    return pending;
  }
  window.CVTaraCSRF = token;
  var original = window.fetch;
  if (!original) return;
  window.fetch = function (input, init) {
    try {
      var url = new URL(typeof input === 'string' ? input : input.url, location.href);
      var method = String((init && init.method) || (typeof input !== 'string' && input.method) || 'GET').toUpperCase();
      var m = meta();
      if (url.origin === location.origin && method !== 'GET' && method !== 'HEAD' && m && !m.content && url.pathname !== '/api/csrf') {
        var self = this, args = arguments;
        return token().then(function (t) {
          var next = Object.assign({}, init || {});
          var headers = new Headers(next.headers || (typeof input !== 'string' ? input.headers : undefined));
          headers.set('X-CSRF-TOKEN', t);
          next.headers = headers;
          return original.call(self, input, next);
        });
      }
    } catch (e) { /* fall through to the normal request */ }
    return original.apply(this, arguments);
  };
})();
