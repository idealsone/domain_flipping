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
  var SHARED = { '/api/public-content': 1, '/api/business-public': 1, '/api/public-config': 1 }, shared = {};
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
      // Public page data is read by several scripts on one page; fetch it once and hand each caller its own copy.
      if (url.origin === location.origin && method === 'GET' && SHARED[url.pathname] && !(init && init.signal)) {
        var key = url.pathname + url.search;
        if (!shared[key]) {
          shared[key] = original.call(this, input, init).then(function (r) { if (!r.ok) delete shared[key]; return r; }, function (e) { delete shared[key]; throw e; });
        }
        return shared[key].then(function (r) { return r.clone(); });
      }
    } catch (e) { /* fall through to the normal request */ }
    return original.apply(this, arguments);
  };
})();
