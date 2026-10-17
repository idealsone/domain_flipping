<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>{{['owner'=>'Owner sign in','login'=>'Sign in','register'=>'Create your account','forgot-password'=>'Reset your password','reset-password'=>'Choose a new password'][$mode]}} | CVTara</title><link rel="stylesheet" href="/styles.css"><link rel="stylesheet" href="/cvtara-ui.css"><meta name="theme-color" content="#1e1b4b"><script>(function(){try{var v=localStorage.getItem("cvtara-theme"),d=v?v==="dark":matchMedia("(prefers-color-scheme: dark)").matches;if(d){document.documentElement.classList.add("dark");document.documentElement.style.colorScheme="dark"}document.documentElement.setAttribute("data-theme",d?"dark":"light")}catch(e){}})()</script>
<style>
/* Sign-in and sign-up use the same type, colours and buttons as the home page */
body{min-height:100vh;margin:0;background:radial-gradient(1100px 520px at 85% -10%,rgba(79,70,229,.16),transparent 60%),radial-gradient(900px 420px at -10% 10%,rgba(21,87,210,.13),transparent 60%),var(--bg,#f8fafc);color:var(--ink,#0f172a)}
.au{min-height:100vh;display:grid;grid-template-columns:minmax(380px,.9fr) 1.1fr}
.au-side{position:relative;overflow:hidden;background:linear-gradient(150deg,#1e1b4b,#312e81 60%,#3730a3);color:#fff;padding:44px clamp(32px,5vw,72px);display:flex;flex-direction:column;justify-content:center;gap:26px}
.au-side:before{content:"";position:absolute;right:-120px;top:-120px;width:360px;height:360px;border-radius:50%;background:radial-gradient(circle,rgba(129,140,248,.35),transparent 70%)}
.au-side>*{position:relative}
.au-side .brand{display:inline-flex;align-items:center;gap:10px;color:#fff;text-decoration:none}.au-side .brand b{color:#fff;font-size:22px;letter-spacing:-.01em}
.au-logo{display:inline-block;background:#fff;border-radius:14px;padding:8px 16px;box-shadow:0 10px 30px rgba(0,0,0,.25)}.au-logo img{display:block;width:150px;height:auto;max-height:46px;object-fit:contain}
.au-eyebrow{font-size:12px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#a5b4fc}
.au-side h2{margin:0;font-size:clamp(28px,3vw,42px);line-height:1.08;font-weight:850;letter-spacing:-.03em}
.au-side p.au-lead{margin:0;max-width:470px;font-size:16.5px;line-height:1.6;color:#e0e7ff}
.au-steps{list-style:none;margin:2px 0 0;padding:0;display:grid;gap:14px;counter-reset:a}
.au-steps li{display:grid;grid-template-columns:34px 1fr;gap:12px;align-items:start;counter-increment:a}
.au-steps li:before{content:counter(a);display:grid;place-items:center;width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);font-weight:800}
.au-steps b{display:block;font-size:16px}.au-steps span{font-size:14px;color:#c7d2fe}
.au-doc{display:flex;gap:14px;align-items:center;margin-top:4px}
.au-sheet{width:116px;border-radius:10px;background:#fff;padding:12px 11px;display:grid;gap:6px;box-shadow:0 18px 40px rgba(0,0,0,.35);transform:rotate(-3deg)}
.au-sheet i{display:block;height:6px;border-radius:3px;background:#dbe3f3}.au-sheet i:first-child{width:56%;height:9px;background:#1557d2}.au-sheet i.s{width:78%}.au-sheet i.m{width:92%}
.au-sheet.b{transform:rotate(3deg);margin-left:-34px}.au-sheet.b i:first-child{background:#6d4cd6}
.au-badge{display:grid;gap:2px;padding:10px 14px;border-radius:14px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.25)}
.au-badge b{font-size:26px;line-height:1;color:#86efac}.au-badge span{font-size:12px;color:#e0e7ff}
.au-trust{display:flex;gap:8px 18px;flex-wrap:wrap;font-size:13.5px;font-weight:600;color:#e0e7ff;margin:0;padding:0;list-style:none}
.au-trust li:before{content:"✓ ";color:#86efac;font-weight:800}
.au-main{display:grid;place-items:center;padding:28px clamp(16px,4vw,48px)}
.au-card{width:min(var(--w,460px),100%);background:var(--paper,#fff);border-radius:22px;padding:clamp(24px,3vw,36px);box-shadow:0 30px 80px rgba(15,23,42,.12);border:1px solid var(--line,#e2e8f0)}
.au-wide{--w:640px}
.au-card h1{margin:0 0 4px;font-size:clamp(26px,2.4vw,32px);letter-spacing:-.025em;font-weight:850;color:var(--ink,#0f172a)}
.au-card .sub{margin:0 0 18px;color:var(--muted,#64748b);font-size:15px}
.au-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}
.au-grid .full{grid-column:1/-1}
.au-card label{display:grid;gap:6px;margin:0 0 14px;font-weight:700;font-size:13px;color:var(--ink,#334155)}
.au-card label small{display:inline;font-weight:500;color:var(--muted,#64748b);font-size:12px}
.au-card input:not([type=checkbox]),.au-card select{width:100%;height:46px;padding:0 13px;border:1.5px solid var(--line,#d6deea);border-radius:11px;box-sizing:border-box;font:inherit;font-weight:500;background:var(--paper,#fff);color:var(--ink,#0f172a);transition:border-color .15s,box-shadow .15s}
.au-card input:focus,.au-card select:focus{outline:none;border-color:#1557d2;box-shadow:0 0 0 3px rgba(21,87,210,.16)}
.au-pw{position:relative}.au-pw input{padding-right:64px!important}
.au-pw button{position:absolute;right:6px;top:50%;transform:translateY(-50%);height:34px;padding:0 10px;border:0;border-radius:8px;background:transparent;color:#1557d2;font:inherit;font-weight:700;font-size:13px;cursor:pointer}
.au-pw button:hover{background:rgba(21,87,210,.08)}
.au-card .terms{display:flex;gap:10px;align-items:flex-start;font-weight:500;font-size:13.5px;color:var(--muted,#475569);margin:2px 0 4px}
.au-card .terms input{margin-top:3px;width:17px;height:17px;accent-color:#1557d2}
.au-go{width:100%;height:50px;border:0;border-radius:12px;background:linear-gradient(135deg,#1557d2,#4f46e5);color:#fff;font:inherit;font-weight:800;font-size:16px;cursor:pointer;margin-top:8px;box-shadow:0 10px 24px rgba(21,87,210,.28);transition:transform .15s,box-shadow .15s}
.au-go:hover{transform:translateY(-1px);box-shadow:0 14px 28px rgba(21,87,210,.34)}
.au-card a{color:#1557d2;font-weight:700;text-decoration:none}.au-card a:hover{text-decoration:underline}
.au-links{margin:18px 0 0;display:flex;justify-content:space-between;gap:10px 14px;flex-wrap:wrap;font-size:14px;color:var(--muted,#64748b)}
.au-note{margin:14px 0 0;font-size:12.5px;color:var(--muted,#64748b);text-align:center}
.error{color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:10px 12px;border-radius:10px;margin:0 0 12px;font-size:14px}
.ok{color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;padding:10px 12px;border-radius:10px;margin:0 0 12px;font-size:14px}
html.dark body{background:#0f1117;color:#f1f5f9}
html.dark .au-card{background:#1a1f2e;border-color:#2d3748;box-shadow:0 30px 80px rgba(0,0,0,.5)}
html.dark .au-card h1,html.dark .au-card label{color:#f1f5f9}html.dark .au-card .sub,html.dark .au-card .terms,html.dark .au-links,html.dark .au-note,html.dark .au-card label small{color:#94a3b8}
html.dark .au-card input:not([type=checkbox]),html.dark .au-card select{background:#0f1117;color:#f1f5f9;border-color:#2d3748}
html.dark .au-card a,html.dark .au-pw button{color:#8fb2ff}
html.dark .error{background:#3b1219;border-color:#7f1d1d;color:#fca5a5}html.dark .ok{background:#0f2e1c;border-color:#166534;color:#86efac}
.au-theme{position:fixed;top:14px;right:14px;z-index:5;width:40px;height:40px;border-radius:50%;border:1px solid #cbd5e1;background:rgba(255,255,255,.92);cursor:pointer;font-size:18px;line-height:1}
html.dark .au-theme{background:#1a1f2e;border-color:#2d3748;color:#f1f5f9}
@media(max-width:1100px){.au{grid-template-columns:minmax(320px,.8fr) 1.2fr}.au-doc{display:none}}
@media(max-width:900px){.au{grid-template-columns:1fr}.au-side{padding:22px 22px 20px;gap:10px}.au-side h2{font-size:24px}.au-side p.au-lead,.au-steps,.au-doc,.au-trust,.au-eyebrow{display:none}.au-main{padding:18px 14px 28px;align-items:start}.au-wide{--w:560px}}
@media(max-width:560px){.au-grid{grid-template-columns:1fr}.au-card{border-radius:18px;padding:22px 18px}.au-links{flex-direction:column}}
</style><link rel="icon" href="/favicon.ico?v=2" sizes="48x48"><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=2"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=2"><link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2"><link rel="manifest" href="/site.webmanifest"></head><body>
@php($isReg=$mode==='register')
<div class="au"><aside class="au-side"><a class="brand" href="/" aria-label="CVTara home"><span class="au-logo"><img src="/assets/brand/cvtara-logo.webp" width="174" height="54" alt="CVTara"></span></a>
<span class="au-eyebrow">{{$isReg?'Free to start':'Welcome'}}</span>
<h2>{{$isReg?'Get a resume built to be read.':'Pick up where you left off.'}}</h2>
<p class="au-lead">{{$isReg?'Upload the resume you have or start from a design recruiters trust. CVTara scores it, shows what each job is missing and writes the matching cover letter.':'Your resumes, cover letters and applications are saved and waiting in your workspace.'}}</p>
<ol class="au-steps"><li><div><b>Upload or choose a design</b><span>Word, PDF or text, or start from a template.</span></div></li><li><div><b>See your ATS-friendly score</b><span>And exactly what to fix for each job.</span></div></li><li><div><b>Tailor, download, track</b><span>Resume and cover letter per job, with reminders.</span></div></li></ol>
<div class="au-doc" aria-hidden="true"><div class="au-sheet"><i></i><i class="m"></i><i class="s"></i><i class="m"></i><i class="s"></i></div><div class="au-sheet b"><i></i><i class="m"></i><i class="s"></i><i class="m"></i></div><div class="au-badge"><b>89%</b><span>ATS-friendly · illustrative</span></div></div>
<ul class="au-trust"><li>Free to start, no card</li><li>Pay once, no auto-renewal</li><li>Download or delete your data anytime</li></ul></aside>
<main class="au-main"><div class="au-card {{$isReg?'au-wide':''}}">
<h1>{{['owner'=>'Owner sign in','login'=>'Welcome back','register'=>'Create your account','forgot-password'=>'Reset your password','reset-password'=>'Choose a new password'][$mode]}}</h1>
<p class="sub">{{['owner'=>'Sign in to the owner console.','login'=>'Sign in to your CVTara workspace.','register'=>'It takes under a minute. No card needed.','forgot-password'=>'We’ll send a reset link to your email.','reset-password'=>'Use at least 12 characters.'][$mode]}}</p>
@if(session('status'))<p class="ok" role="status">{{session('status')}}</p>@endif
@foreach($errors->all() as $error)<p class="error" role="alert">{{$error}}</p>@endforeach
<form method="post" action="{{ $action ?? '/'.$mode }}">@csrf
@if(request('next'))<input type="hidden" name="next" value="{{ request('next') }}">@endif
<div class="{{$isReg?'au-grid':''}}">
@if($isReg)<label>Full name<input name="name" autocomplete="name" required maxlength="120" value="{{old('name')}}" placeholder="As on your resume"></label>@endif
<label @if($isReg) @endif>Email<input type="email" name="email" autocomplete="email" required value="{{old('email',$email)}}" placeholder="you@example.com"></label>
@if($mode!=='forgot-password')<label><span>Password @if($mode!=='login')<small>· min 12 characters</small>@endif</span><span class="au-pw"><input type="password" name="password" id="auPw" autocomplete="{{$mode==='login'?'current-password':'new-password'}}" required {{$mode==='login'?'':'minlength=12'}} maxlength="200"><button type="button" data-show="auPw" aria-label="Show password">Show</button></span></label>@endif
@if(in_array($mode,['register','reset-password']))<label>Confirm password<span class="au-pw"><input type="password" name="password_confirmation" id="auPw2" autocomplete="new-password" required><button type="button" data-show="auPw2" aria-label="Show password">Show</button></span></label>@endif
@if($mode==='reset-password')<input type="hidden" name="token" value="{{$token}}">@endif
@if($isReg)<label>I am a<select name="experienceLevel"><option value="fresher" @selected(old('experienceLevel')==='fresher')>Fresher / recent graduate</option><option value="experienced" @selected(old('experienceLevel')==='experienced')>Experienced professional</option></select></label><label>Target role<input name="profession" required maxlength="120" value="{{old('profession')}}" placeholder="e.g. Data Analyst"></label><label class="full">Work category<select name="category">@foreach(['Technology','HR & Recruitment','Sales & Marketing','Finance','Operations','Education','Healthcare','Design','Other'] as $x)<option @selected(old('category')===$x)>{{$x}}</option>@endforeach</select></label><label class="terms full"><input type="checkbox" name="terms" value="1" required> <span>I accept the <a href="/terms.html">terms</a> and have read the <a href="/privacy.html">privacy notice</a>.</span></label>@endif
</div>
<button class="au-go">{{['owner'=>'Sign in','login'=>'Sign in','register'=>'Create my account','forgot-password'=>'Send reset link','reset-password'=>'Save password'][$mode]}}</button></form>
@if($isReg)<p class="au-note">All payments are final and non-refundable. Build, preview and run ATS checks free first.</p>@endif
<p class="au-links" @if($mode==='owner') hidden @endif>@if($mode==='register')<span>Already have an account? <a href="/login">Sign in</a></span>@elseif($mode==='login')<span>New to CVTara? <a href="/register">Create an account</a></span><a href="/forgot-password">Forgot password?</a>@else<a href="/login">Back to sign in</a>@endif</p>
</div></main></div><button type="button" class="au-theme" id="auTheme" aria-label="Switch between light and night mode"></button><script>(function(){var b=document.getElementById("auTheme"),r=document.documentElement;function paint(){b.textContent=r.classList.contains("dark")?"☀":"☾"}b.onclick=function(){var d=!r.classList.contains("dark");r.classList.toggle("dark",d);r.style.colorScheme=d?"dark":"light";r.setAttribute("data-theme",d?"dark":"light");try{localStorage.setItem("cvtara-theme",d?"dark":"light")}catch(e){}paint()};paint();document.querySelectorAll("[data-show]").forEach(function(t){t.onclick=function(){var i=document.getElementById(t.dataset.show),s=i.type==="password";i.type=s?"text":"password";t.textContent=s?"Hide":"Show";t.setAttribute("aria-label",s?"Hide password":"Show password")}})})()</script></body></html>
