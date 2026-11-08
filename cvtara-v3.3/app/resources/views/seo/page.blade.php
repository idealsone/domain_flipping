<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="description" content="{{ $p['description'] }}">
<title>{{ $p['title'] }} | CVTara</title>
<link rel="stylesheet" href="/styles.css">
<link rel="stylesheet" href="/connected-ui.css">
<link rel="stylesheet" href="/template-designs.css">
<link rel="stylesheet" href="/premium-products.css">
<meta name="theme-color" content="#2B3A8C"><link rel="stylesheet" href="/brand-polish.css"><link rel="stylesheet" href="/blog-v2.css"><link rel="stylesheet" href="/seo-pages.css"><link rel="stylesheet" href="/template-picker.css"><link rel="icon" href="/favicon.ico?v=2" sizes="48x48"><link rel="icon" type="image/svg+xml" href="/favicon.svg?v=2"><link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png?v=2"><link rel="apple-touch-icon" href="/apple-touch-icon.png?v=2"><link rel="manifest" href="/site.webmanifest"></head>
<body data-page="seo" class="sp-body">
<div data-header></div>
<main class="sp-shell">
<nav class="sp-crumbs" aria-label="Breadcrumb"><ol>@foreach($p['crumbs'] as $i => $c)<li>@if($i < count($p['crumbs']) - 1)<a href="{{ $c[1] }}">{{ $c[0] }}</a>@else<span aria-current="page">{{ $c[0] }}</span>@endif</li>@endforeach</ol></nav>
<header class="sp-hero">
<div class="sp-hero-copy">
<span class="sp-eyebrow">{{ $p['eyebrow'] }}</span>
<h1>{{ $p['h1'] }}</h1>
<p class="sp-lead">{{ $p['lead'] }}</p>
{{-- SEO-CTA:hero start. Product actions for this page come from $p['cta'] (SeoPages::cta); add new buttons here. --}}
<div class="sp-actions" data-seo-cta="hero">@if(!empty($p['picker']))<a class="sp-cta" href="{{ $p['picker'] }}">Use this template</a>@endif<a class="{{ !empty($p['picker']) ? 'sp-secondary' : 'sp-cta' }}" href="{{ $p['cta']['primary'][0] }}">{{ $p['cta']['primary'][1] }}</a><a class="sp-secondary" href="{{ $p['cta']['secondary'][0] }}">{{ $p['cta']['secondary'][1] }}</a></div>
{{-- SEO-CTA:hero end --}}
<p class="sp-trust">Free to build and preview · Pay once, no auto-renewal · Secure UPI and cards · Built by working recruiters</p>
</div>
<img class="sp-hero-img" src="{{ $p['image'][0] }}" alt="{{ $p['image'][1] }}" width="520" height="390" loading="eager" decoding="async" fetchpriority="high">
</header>
<div class="sp-layout">
@php($toc = array_merge(!empty($p['sample']) ? [['sample', 'Sample']] : [], array_map(fn($s) => [$s[0], $s[1]], $p['sections']), !empty($p['faq']) ? [['faq', 'FAQ']] : []))
<aside class="sp-toc" aria-label="On this page"><b>On this page</b>@foreach($toc as $t)<a href="#{{ $t[0] }}">{{ $t[1] }}</a>@endforeach</aside>
<article class="sp-article">
@if(!empty($p['sample']))<section id="sample" class="sp-section"><h2>Sample</h2>{!! $p['sample'] !!}</section>@endif
@foreach($p['sections'] as $s)<section id="{{ $s[0] }}" class="sp-section"><h2>{{ $s[1] }}</h2>{!! $s[2] !!}</section>@endforeach
@if(!empty($p['faq']))<section id="faq" class="sp-section sp-faq"><h2>Frequently asked questions</h2>@foreach($p['faq'] as $q)<details><summary>{{ $q[0] }}</summary><p>{{ $q[1] }}</p></details>@endforeach</section>@endif
{{-- SEO-CTA:final start --}}
<aside class="sp-final" data-seo-cta="final"><div><b>Ready to write yours?</b><p>{{ $p['cta']['note'] }}@if(!str_contains($p['lead'].json_encode($p['sections']), 'ATS (applicant tracking system')) Then check it with an ATS (applicant tracking system, the software many employers use to store, search and filter resumes) scan.@endif</p></div><div class="sp-final-actions"><a class="sp-cta" href="{{ $p['cta']['primary'][0] }}">{{ $p['cta']['primary'][1] }}</a><a class="sp-final-link" href="{{ $p['cta']['pricing'][0] }}">{{ $p['cta']['pricing'][1] }}</a></div></aside>
{{-- SEO-CTA:final end --}}
@foreach($p['related'] as $g)@if(!empty($g[1]))<nav class="sp-related" aria-label="{{ $g[0] }}"><h2>{{ $g[0] }}</h2><ul>@foreach($g[1] as $l)<li><a href="{{ $l[0] }}">{{ $l[1] }}</a></li>@endforeach</ul></nav>@endif @endforeach
</article>
</div>
</main>
<div data-footer></div>
<script src="/data.js"></script><script src="/reference-templates.js"></script>
<script src="/premium-collection.js"></script>
<script src="/template-curation.js" defer></script><script src="/template-picker.js" defer></script><script src="/preview.js"></script>
<script src="/profile-api.js"></script>
<script src="/onesignal-push.js"></script>
<script src="/connected-ui.js"></script>
<script src="/business-ui.js" defer></script></body>
</html>
