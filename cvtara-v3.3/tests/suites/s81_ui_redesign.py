"""3.70.0 smoother UI: quiet Tara, one next step on the dashboard, interactive charts, landing refresh, no layout jumps, a11y."""
import os, re
NAME = "UI redesign (3.70.0)"
PUB = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "public_html")
APP = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..", "app")


def rd(*p):
    return open(os.path.join(*p), encoding="utf8").read()


def run(ctx, t):
    motion, css = rd(PUB, "cv-motion.js"), rd(PUB, "cv-motion.css")
    t.check("shared motion + chart script is small and has no library", len(motion) < 9000 and "import " not in motion and "require(" not in motion, len(motion))
    t.check("motion respects reduced motion", "prefers-reduced-motion" in motion and "prefers-reduced-motion" in css)
    t.check("charts answer hover, keyboard focus and tap", all(k in motion for k in ("pointerover", "focusin", "ArrowRight", "'click'")) and 'tabindex="0"' in motion)
    t.check("the chart SVG class cannot clash with the line path class", 'class="cvc cvc--' in motion)

    tara = rd(PUB, "tara-chat.js")
    t.check("Tara never opens the chat panel by itself", "toggle(true); }; box.append(b)" in tara and "} else toggle(true);" not in tara)
    t.check("the closed Tara panel is out of the Tab order", 'hidden inert>' in tara and "p.inert = !open" in tara)
    t.check("Tara's next step fills the welcome card in place", "getElementById('wsNext')" in tara)
    t.check("on phones the Tara button is a small icon and the page keeps room above it", "body:has(#cvTara){padding-bottom:72px}" in rd(PUB, "tara-chat.css"))

    ws, dash = rd(PUB, "workspace-ui.js"), rd(PUB, "dash-v4.css")
    t.check("the dashboard has one next-step slot and a progress section", 'id="wsNext"' in ws and "wsProgress" in ws and "CVChart.line" in ws)
    t.check("3.78.0: the dashboard blocks show only on the Dashboard, not on ATS Match, the builder or interviews", "#view-dashboard.active{display:flex" in dash and "\n#view-dashboard{display:flex" not in dash)
    t.check("3.78.0: Tara's button shows the CVTara logo, not a letter", 'tara-dot" aria-hidden="true">T<' not in tara and "/assets/brand/cvtara-icon.webp" in tara and os.path.isfile(os.path.join(PUB, "assets/brand/cvtara-icon.webp")))
    t.check("the old roadmap and duplicate next-step boxes are hidden", "#view-dashboard>.application-roadmap" in dash and "#view-dashboard>.tara-nudge" in dash)
    t.check("dashboard keeps room for late blocks", re.search(r"#wsDash\{order:1;min-height:\d+px\}", dash) is not None)
    t.check("reminder cards show one at a time and step aside", "#cvNotes .cv-note:nth-child(n+2){display:none}" in dash)

    promo = rd(PUB, "promo.js")
    t.check("the offer bar floats instead of pushing the page down", "cvp-float" in promo and "document.body.prepend(wrap)" not in promo)
    t.check("checkout keeps room for plan cards while loading", "#coPlans:empty{min-height" in rd(PUB, "cvtara-ui.css"))

    ops = rd(APP, "resources", "console", "ops.js")
    t.check("console overview charts are interactive", "window.CVChart.bars" in ops and "window.CVChart.line" in ops)
    t.check("console traffic chart is interactive", "window.CVChart.bars" in rd(APP, "resources", "console", "advanced.js"))

    anon = ctx.client()
    s, _, home = anon.page("/")
    t.check("home page loads the motion script", s == 200 and "/cv-motion.js" in home, s)
    t.check("home hero has one solid button and short copy", "Start free" in home and "lp-stats" in home and home.count('class="button primary" href="/register"') >= 1)
    t.check("home sections reveal on scroll and the sample score counts up", "data-reveal" in home and 'data-count="72"' in home)
    t.check("home icons are inline SVG, not emoji", "lp-ic" in home and not re.search(r"[👑🎯🎓💼🔄📄🔍🗂️💧]", home))

    cust = ctx.client(); cust.register("ui370@test.local")
    s, _, w = cust.page("/workspace.html")
    t.check("workspace has a skip link to the main content", s == 200 and 'class="ws-skip" href="#wsMain"' in w and 'id="wsMain"' in w)
    b = re.search(r'href="(/assets/bundle/[a-f0-9]+\.css)', w)
    css = cust.page(b.group(1))[2] if b else ""
    t.check("workspace loads the dashboard and motion styles as one combined file", bool(b) and "/* /dash-v4.css */" in css and "/* /cv-motion.css */" in css and "/* /styles.css */" in css and "/cv-motion.js" in w, b and b.group(1))
    own = [l for l in re.findall(r'<link rel="stylesheet" href="(/[^"]+)"', w.split("</head>")[0])]
    t.check("workspace makes one stylesheet request for its own styles (was 23)", len(own) == 1, own)
    s, d = cust.api("GET", "whats-new")
    if s == 200 and isinstance(d, dict):
        txt = str(d)
        t.check("customers hear about the calmer dashboard, without AI wording", "A calmer, quicker dashboard" in txt and not re.search(r"\bAI\b|Claude|Anthropic", txt), s)

    # 3.70.1: room kept for content that loads late
    ui, hv3 = rd(PUB, "cvtara-ui.css"), rd(PUB, "home-v3.css")
    t.check("checkout keeps room for the stacked plan cards on phones and tablets", "@media(max-width:1099px){body[data-page=checkout] #coPlans:empty{min-height:1920px" in ui)
    t.check("home sample rows keep their final height while they load", ".lp-spot:empty{min-height:1209px}" in hv3 and ".lp-letters{min-height:1560px}" in hv3 and ".lp-spot .template-card{content-visibility:visible}" in hv3)

    # 3.70.2
    lazy = rd(PUB, "csrf-lazy.js")
    t.check("account data asked for twice within 2 s shares one request, and any change clears that", "Date.now() - hit.t > 2000" in lazy and "method !== 'HEAD') recent = {};" in lazy)
    t.check("templates and cover-letter pickers keep their room while they load", "#picker>[data-template-picker]{min-height:376px}" in rd(PUB, "cvtara-ui.css"))
    t.check("the console Overview shows one set of business numbers", "#admin-dashboard:has(#kpiPanel)>.ops-kpis.six{display:none}" in rd(PUB, "cvtara-ds.css"))
