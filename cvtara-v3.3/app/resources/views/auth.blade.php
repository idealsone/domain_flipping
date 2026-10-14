<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>{{['login'=>'Sign in','register'=>'Create your account','forgot-password'=>'Reset your password','reset-password'=>'Choose a new password'][$mode]}} | CVTara</title><link rel="stylesheet" href="/styles.css"><link rel="stylesheet" href="/cvtara-ui.css"><link rel="icon" href="/favicon.ico" sizes="any"><link rel="icon" type="image/svg+xml" href="/assets/brand/cvtara-mark.svg"><link rel="apple-touch-icon" href="/apple-touch-icon.png"><meta name="theme-color" content="#1557d2"><script>(function(){try{var v=localStorage.getItem("cvtara-theme"),d=v?v==="dark":matchMedia("(prefers-color-scheme: dark)").matches;if(d){document.documentElement.classList.add("dark");document.documentElement.style.colorScheme="dark"}document.documentElement.setAttribute("data-theme",d?"dark":"light")}catch(e){}})()</script>
<style>
body{min-height:100vh;background:linear-gradient(135deg,#eef4ff,#f3f0ff)}
.au{min-height:100vh;display:grid;grid-template-columns:1.05fr .95fr}
.au-side{background:linear-gradient(150deg,#1347b0,#1557d2 55%,#4f46e5);color:#fff;padding:48px 56px;display:flex;flex-direction:column;justify-content:center;gap:22px}
.au-side h2{font-size:clamp(28px,3vw,40px);margin:0;line-height:1.1;font-weight:850}
.au-side p{margin:0;opacity:.9;font-size:17px;max-width:460px}
.au-side ul{list-style:none;padding:0;margin:6px 0 0;display:grid;gap:12px}
.au-side li{display:flex;gap:10px;font-weight:600}
.au-side li:before{content:"✓";display:grid;place-items:center;width:22px;height:22px;border-radius:50%;background:rgba(255,255,255,.2);font-size:12px;flex:none}
.au-side .brand,.au-side .brand b{color:#fff}
.au-main{display:grid;place-items:center;padding:32px 20px}
.au-card{width:min(460px,100%);background:#fff;border-radius:22px;padding:34px;box-shadow:0 30px 80px rgba(15,23,42,.14);border:1px solid #e2e8f0}
.au-card h1{margin:0 0 6px;font-size:28px;color:#0f172a}
.au-card .sub{margin:0 0 20px;color:#64748b}
.au-card label{display:grid;gap:6px;margin:13px 0;font-weight:700;font-size:13px;color:#334155}
.au-card input:not([type=checkbox]),.au-card select{width:100%;padding:12px 13px;border:1.5px solid #d6deea;border-radius:11px;box-sizing:border-box;font:inherit;background:#fff;color:#0f172a}
.au-card input:focus,.au-card select:focus{outline:none;border-color:#1557d2;box-shadow:0 0 0 3px rgba(21,87,210,.15)}
.au-card .terms{display:flex;gap:10px;align-items:flex-start;font-weight:500;color:#475569}
.au-card .terms input{margin-top:3px}
.au-card button.au-go{width:100%;padding:14px;border:0;border-radius:12px;background:linear-gradient(135deg,#1557d2,#4f46e5);color:#fff;font:inherit;font-weight:800;cursor:pointer;margin-top:8px;box-shadow:0 10px 24px rgba(21,87,210,.28)}
.au-card a{color:#1557d2;font-weight:700}
.au-links{margin:18px 0 0;display:flex;gap:14px;flex-wrap:wrap;font-size:14px}
.error{color:#b91c1c;background:#fef2f2;border:1px solid #fecaca;padding:10px 12px;border-radius:10px;margin:0 0 10px;font-size:14px}
.ok{color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;padding:10px 12px;border-radius:10px;margin:0 0 10px;font-size:14px}
html.dark body{background:#0f1117;color:#f1f5f9}
html.dark .au-side{background:linear-gradient(150deg,#0b1f55,#13307a 55%,#2b2a8c)}
html.dark .au-card{background:#1a1f2e;border-color:#2d3748;box-shadow:0 30px 80px rgba(0,0,0,.5)}
html.dark .au-card h1{color:#f1f5f9}html.dark .au-card .sub,html.dark .au-card .terms{color:#94a3b8}html.dark .au-card label{color:#cbd5e1}
html.dark .au-card input:not([type=checkbox]),html.dark .au-card select{background:#0f1117;color:#f1f5f9;border-color:#2d3748}
html.dark .au-card a{color:#8fb2ff}
html.dark .error{background:#3b1219;border-color:#7f1d1d;color:#fca5a5}html.dark .ok{background:#0f2e1c;border-color:#166534;color:#86efac}
.au-theme{position:fixed;top:14px;right:14px;z-index:5;width:40px;height:40px;border-radius:50%;border:1px solid #cbd5e1;background:rgba(255,255,255,.9);cursor:pointer;font-size:18px;line-height:1}
html.dark .au-theme{background:#1a1f2e;border-color:#2d3748;color:#f1f5f9}
@media(max-width:860px){.au{grid-template-columns:1fr}.au-side{padding:28px 24px}.au-side ul,.au-side p{display:none}}
</style></head><body>
<div class="au"><aside class="au-side"><a class="brand" href="/" aria-label="CVTara home"><span class="brand-mark"><img src="/assets/brand/cvtara-mark.svg" width="39" height="39" alt="" decoding="async"></span><span><b style="font-size:20px">CVTara</b></span></a>
<h2>One primary resume.<br>A tailored version for every job.</h2><p>Build your ATS-friendly master resume once, then create job-description-friendly versions and cover letters in minutes.</p>
<ul><li>Create an account in under a minute</li><li>Premium ATS-friendly templates</li><li>Tailored resume + cover letter per application</li><li>Your data stays yours — download or delete anytime</li></ul></aside>
<main class="au-main"><div class="au-card">
<h1>{{['login'=>'Welcome back','register'=>'Create your account','forgot-password'=>'Reset your password','reset-password'=>'Choose a new password'][$mode]}}</h1>
<p class="sub">{{['login'=>'Sign in to your CVTara workspace.','register'=>'Start building your primary resume today.','forgot-password'=>'We’ll send a reset link to your email.','reset-password'=>'Use at least 12 characters.'][$mode]}}</p>
@if(session('status'))<p class="ok" role="status">{{session('status')}}</p>@endif
@foreach($errors->all() as $error)<p class="error" role="alert">{{$error}}</p>@endforeach
<form method="post" action="/{{ $mode }}">@csrf
@if(request('next'))<input type="hidden" name="next" value="{{ request('next') }}">@endif
@if($mode==='register')<label>Full name<input name="name" autocomplete="name" required maxlength="120" value="{{old('name')}}"></label>@endif
<label>Email<input type="email" name="email" autocomplete="email" required value="{{old('email',$email)}}"></label>
@if($mode!=='forgot-password')<label>Password<input type="password" name="password" autocomplete="{{$mode==='login'?'current-password':'new-password'}}" required {{$mode==='login'?'':'minlength=12'}} maxlength="200"></label>@endif
@if(in_array($mode,['register','reset-password']))<label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required></label>@endif
@if($mode==='reset-password')<input type="hidden" name="token" value="{{$token}}">@endif
@if($mode==='register')<label>I am a<select name="experienceLevel"><option value="fresher" @selected(old('experienceLevel')==='fresher')>Fresher / recent graduate</option><option value="experienced" @selected(old('experienceLevel')==='experienced')>Experienced professional</option></select></label><label>Current profession / target role<input name="profession" required maxlength="120" value="{{old('profession')}}"></label><label>Work category<select name="category">@foreach(['Technology','HR & Recruitment','Sales & Marketing','Finance','Operations','Education','Healthcare','Design','Other'] as $x)<option @selected(old('category')===$x)>{{$x}}</option>@endforeach</select></label><label class="terms"><input type="checkbox" name="terms" value="1" required> <span>I accept the <a href="/terms.html">terms</a> and have read the <a href="/privacy.html">privacy notice</a>.</span></label>@endif
<button class="au-go">{{['login'=>'Sign in','register'=>'Create my account','forgot-password'=>'Send reset link','reset-password'=>'Save password'][$mode]}}</button></form>
<p class="au-links">@if($mode!=='login')<a href="/login">Sign in</a>@endif @if($mode!=='register')<a href="/register">Create account</a>@endif @if($mode!=='forgot-password')<a href="/forgot-password">Forgot password?</a>@endif</p>
</div></main></div><button type="button" class="au-theme" id="auTheme" aria-label="Switch between light and night mode"></button><script>(function(){var b=document.getElementById("auTheme"),r=document.documentElement;function paint(){b.textContent=r.classList.contains("dark")?"☀":"☾"}b.onclick=function(){var d=!r.classList.contains("dark");r.classList.toggle("dark",d);r.style.colorScheme=d?"dark":"light";r.setAttribute("data-theme",d?"dark":"light");try{localStorage.setItem("cvtara-theme",d?"dark":"light")}catch(e){}paint()};paint()})()</script></body></html>
