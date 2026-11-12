/* Retired in 3.51.0 (V7 WS-07): the separate writing-help pop-up is now part of Tara chat ("writing_help"), so customers
   have one assistant. This file stays only so a cached page that still loads it does nothing harmful: an old call to
   open it opens Tara chat instead. */
(() => {
  'use strict';
  window.CVTaraTara = { open: () => (window.CVTaraAssist ? window.CVTaraAssist() : (location.href = '/workspace.html')) };
})();
