<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>CVTara — Preview</title><link rel="stylesheet" href="/styles.css"><link rel="stylesheet" href="/cvtara-ui.css"><link rel="icon" href="/favicon.ico?v=2" sizes="48x48"><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=2"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=2"><link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2"><link rel="manifest" href="/site.webmanifest"></head>
<body class="pv">
<div class="pv-bar"><b>CVTara</b><small>v3.3 preview</small>
<button class="pv-tab active" data-pv="home" data-src="/">🌐 Website home</button>
<button class="pv-tab" data-pv="customer" data-src="/workspace.html">👤 Customer dashboard</button>
<button class="pv-tab" data-pv="admin" data-src="/admin.html">🛠 Admin dashboard</button>
<span class="pv-spacer"></span>
<button class="pv-mini" data-w="100%" title="Desktop">🖥</button><button class="pv-mini" data-w="820px" title="Tablet">▭</button><button class="pv-mini" data-w="390px" title="Phone">📱</button>
<button class="pv-mini" id="pvReload">↻ Reload</button><button class="pv-mini" id="pvDark">◐ Dark</button><a href="/" id="pvOpen" target="_blank" rel="noopener">Open ↗</a>
<form method="post" action="/logout" target="pvframe" style="display:inline">@csrf<button class="pv-mini">Sign out</button></form></div>
@if($demo)
<div class="pv-info">Demo accounts (preview only) —
<form method="post" action="/login" target="pvframe" style="display:inline" data-go="customer">@csrf<input type="hidden" name="email" value="{{$demo['customer']}}"><input type="hidden" name="password" value="{{$demo['password']}}"><button class="pv-mini">Sign in as demo customer</button></form>
<form method="post" action="/login" target="pvframe" style="display:inline" data-go="admin">@csrf<input type="hidden" name="email" value="{{$demo['owner']}}"><input type="hidden" name="password" value="{{$demo['password']}}"><button class="pv-mini">Sign in as owner</button></form>
· customer <code>{{$demo['customer']}}</code> · owner <code>{{$demo['owner']}}</code> · password <code>{{$demo['password']}}</code> — everything inside the frame is the real, working site: sign up, log in, build resumes, buy plans, manage pricing.</div>
@endif
<div style="flex:1;display:flex;justify-content:center;background:#1e293b;min-height:0"><iframe class="pv-frame" id="pvframe" name="pvframe" src="/" title="CVTara preview" style="max-width:100%;width:100%;transition:width .25s"></iframe></div>
<script>
const f=document.getElementById('pvframe'),tabs=[...document.querySelectorAll('.pv-tab')];
function go(k){const t=tabs.find(x=>x.dataset.pv===k)||tabs[0];tabs.forEach(x=>x.classList.toggle('active',x===t));f.src=t.dataset.src;document.getElementById('pvOpen').href=t.dataset.src;history.replaceState(null,'','#'+t.dataset.pv)}
tabs.forEach(t=>t.onclick=()=>go(t.dataset.pv));
document.querySelectorAll('[data-w]').forEach(b=>b.onclick=()=>f.style.width=b.dataset.w);
document.getElementById('pvReload').onclick=()=>f.contentWindow.location.reload();
document.getElementById('pvDark').onclick=()=>{try{f.contentDocument.body.classList.toggle('dark')}catch{}};
document.querySelectorAll('form[data-go]').forEach(fm=>fm.addEventListener('submit',()=>{const k=fm.dataset.go;tabs.forEach(x=>x.classList.toggle('active',x.dataset.pv===k));history.replaceState(null,'','#'+k)}));
go((location.hash||'#home').slice(1));
</script></body></html>
