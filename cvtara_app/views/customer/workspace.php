<?php
/* ── derived vars ── */
$is_premium   = is_premium($user);
$plan_label   = $is_premium ? strtoupper(e($user['plan'] ?: 'Paid')) : 'Free';
$credits_rem  = (int) $credits['remaining_balance'];
$credits_used = (int) $credits['used_count'];
$credits_max  = max($credits_rem + $credits_used, 1);
$resume_count = count($resumes);
$app_count    = count($apps);

/* progress steps */
$has_scan    = $credits_used > 0 || $resume_count > 0;   /* approximation */
$has_resume  = $resume_count > 0;
$has_preview = $has_resume;
$has_dl      = $is_premium && $has_resume;

/* pipeline buckets */
$pipeline = ['saved' => [], 'applied' => [], 'interview' => [], 'offer' => []];
foreach ($apps as $a) {
    $stage = $a['stage'] ?? 'saved';
    if (!isset($pipeline[$stage])) $stage = 'saved';
    $pipeline[$stage][] = $a;
}

/* credit ring pct */
$ring_pct = $credits_max > 0 ? round($credits_rem / $credits_max * 100) : 100;
$ring_deg = round($ring_pct / 100 * 360);
?>
<style>
/* ── Hero ── */
.ws-hero{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:18px;background:linear-gradient(120deg,#1e1b4b 0%,#312e81 55%,#4338ca 100%);color:#fff;border-radius:16px;padding:28px 32px;margin-bottom:24px}
.ws-hero-left h2{font-size:22px;font-weight:700;margin:0 0 6px;color:#fff}
.ws-hero-left p{font-size:13px;color:#c7d2fe;margin:0}
.plan-badge{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;padding:3px 10px;border-radius:99px;letter-spacing:.05em;margin-bottom:10px}
.plan-badge.paid{background:#22c55e;color:#fff}
.plan-badge.free{background:#fbbf24;color:#1c1917}
.credits-ring{width:80px;height:80px;flex-shrink:0;position:relative}
.credits-ring svg{width:80px;height:80px;transform:rotate(-90deg)}
.credits-ring circle{fill:none;stroke-width:7}
.credits-ring .ring-bg{stroke:rgba(255,255,255,.2)}
.credits-ring .ring-fg{stroke:#a5f3fc;stroke-linecap:round;transition:stroke-dashoffset .5s}
.credits-ring .ring-text{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:#fff;font-size:18px;font-weight:700;line-height:1}
.credits-ring .ring-text small{font-size:8px;color:#bae6fd;font-weight:400;letter-spacing:.04em}

/* ── Progress tracker ── */
.ws-progress{display:flex;align-items:flex-start;gap:0;margin-bottom:24px;background:#fff;border:1px solid #e8eaf0;border-radius:12px;padding:16px 20px;overflow-x:auto}
.ws-step{display:flex;flex-direction:column;align-items:center;flex:1;min-width:80px;position:relative}
.ws-step:not(:last-child)::after{content:'';position:absolute;top:16px;left:calc(50% + 16px);right:calc(-50% + 16px);height:2px;background:#e5e7eb;z-index:0}
.ws-step.done::after{background:#6366f1}
.ws-step .step-dot{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #e5e7eb;background:#f9fafb;color:#9ca3af;font-weight:700;z-index:1;position:relative}
.ws-step.done .step-dot{background:#6366f1;border-color:#6366f1;color:#fff}
.ws-step.active .step-dot{background:#fff;border-color:#6366f1;color:#6366f1;box-shadow:0 0 0 3px rgba(99,102,241,.2)}
.ws-step .step-label{font-size:10px;font-weight:600;margin-top:6px;color:#9ca3af;text-align:center;text-transform:uppercase;letter-spacing:.03em}
.ws-step.done .step-label,.ws-step.active .step-label{color:#6366f1}

/* ── Metric cards ── */
.ws-metric-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin-bottom:22px}
.ws-metric-card{background:#fff;border:1px solid #e8eaf0;border-radius:10px;padding:14px 16px;text-align:center}
.ws-metric-card .wm-icon{font-size:20px;margin-bottom:4px}
.ws-metric-card .wm-val{font-size:22px;font-weight:700;color:#1f2937}
.ws-metric-card .wm-label{font-size:11px;color:#9ca3af;font-weight:500;margin-top:2px}

/* ── Quick action grid ── */
.ws-quick-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;margin-bottom:24px}
.ws-quick-card{display:flex;flex-direction:column;align-items:flex-start;gap:6px;background:#fff;border:1px solid #e8eaf0;border-radius:12px;padding:16px;text-decoration:none;color:#1f2937;transition:box-shadow .15s,border-color .15s,transform .15s;cursor:pointer}
.ws-quick-card:hover{border-color:#a5b4fc;box-shadow:0 4px 12px rgba(99,102,241,.15);transform:translateY(-1px)}
.ws-quick-card .qc-icon{font-size:24px;line-height:1}
.ws-quick-card .qc-title{font-size:13px;font-weight:600}
.ws-quick-card .qc-desc{font-size:11px;color:#9ca3af;line-height:1.4}
.ws-quick-card.highlight{border-color:#6366f1;background:linear-gradient(135deg,#ede9fe,#f5f3ff)}

/* ── Upgrade banner ── */
.ws-upgrade-banner{background:linear-gradient(120deg,#1e1b4b,#4338ca);color:#fff;border-radius:14px;padding:24px 28px;margin-bottom:24px;display:flex;flex-wrap:wrap;align-items:center;gap:20px}
.ws-upgrade-banner h3{font-size:17px;font-weight:700;margin:0 0 6px;color:#fff}
.ws-upgrade-banner p{font-size:13px;color:#c7d2fe;margin:0 0 12px}
.ws-upgrade-banner ul{margin:0 0 14px;padding-left:18px;color:#c7d2fe;font-size:12px;line-height:1.8}
.ws-upgrade-banner ul li::marker{color:#a5f3fc}
.upgrade-cta{display:inline-block;background:#f59e0b;color:#1c1917;font-weight:700;font-size:13px;padding:9px 20px;border-radius:8px;text-decoration:none;flex-shrink:0}
.upgrade-cta:hover{background:#fbbf24}

/* ── Pipeline board ── */
.ws-pipeline{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:24px}
.pipeline-col{background:#f8fafc;border:1px solid #e8eaf0;border-radius:10px;padding:12px}
.pipeline-col-head{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:8px;display:flex;align-items:center;justify-content:space-between}
.pipeline-badge{background:#e5e7eb;color:#374151;border-radius:99px;font-size:10px;padding:1px 6px}
.pipeline-col.stage-interview .pipeline-col-head,.pipeline-col.stage-interview .pipeline-badge{color:#1d4ed8;background:#dbeafe}
.pipeline-col.stage-offer .pipeline-col-head,.pipeline-col.stage-offer .pipeline-badge{color:#15803d;background:#dcfce7}
.pipeline-item{background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:7px 9px;margin-bottom:6px;font-size:12px;color:#374151}
.pipeline-item .pi-role{font-weight:600;display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pipeline-item .pi-co{color:#9ca3af;font-size:10px}
.pipeline-empty{color:#d1d5db;font-size:11px;text-align:center;padding:8px 0}

/* ── Recent docs ── */
.doc-list{list-style:none;margin:0;padding:0}
.doc-list li{display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid #f3f4f6;gap:8px}
.doc-list li:last-child{border-bottom:none}
.doc-list .doc-name{font-size:13px;font-weight:500;color:#1f2937;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
.doc-list .doc-edit{font-size:11px;color:#6366f1;text-decoration:none;white-space:nowrap;flex-shrink:0}
.doc-list .doc-edit:hover{text-decoration:underline}

/* ── Panels layout ── */
.ws-panels{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
@media(max-width:700px){.ws-panels{grid-template-columns:1fr}}
</style>

<!-- HERO -->
<section class="ws-hero">
  <div class="ws-hero-left">
    <div class="plan-badge <?= $is_premium ? 'paid' : 'free' ?>">
      <?= $is_premium ? '✅ PAID' : '⚡ FREE' ?>
    </div>
    <h2>Hello, <?= e($user['name']) ?>.</h2>
    <p>
      <?php if ($is_premium): ?>
        Your plan is active. Build, tailor &amp; download watermark-free resumes.
      <?php else: ?>
        Free preview — scan &amp; see your score. Upgrade to build &amp; export.
      <?php endif; ?>
    </p>
  </div>
  <div style="text-align:center">
    <?php
      $r = 33; $circ = round(2 * M_PI * $r, 2);
      $offset = round($circ * (1 - $ring_pct / 100), 2);
    ?>
    <div class="credits-ring">
      <svg viewBox="0 0 80 80">
        <circle class="ring-bg" cx="40" cy="40" r="<?= $r ?>" />
        <circle class="ring-fg" cx="40" cy="40" r="<?= $r ?>"
          stroke-dasharray="<?= $circ ?>"
          stroke-dashoffset="<?= $offset ?>" />
      </svg>
      <div class="ring-text">
        <span><?= $credits_rem ?></span>
        <small>CREDITS</small>
      </div>
    </div>
    <div style="font-size:11px;color:#bae6fd;margin-top:4px"><?= $credits_used ?> used</div>
  </div>
</section>

<!-- PROGRESS TRACKER -->
<?php
  $steps = [
    ['label'=>'Scan resume', 'done'=>$has_scan, 'icon'=>'🔍'],
    ['label'=>'Build resume','done'=>$has_resume,'icon'=>'📝'],
    ['label'=>'Preview',     'done'=>$has_preview,'icon'=>'👁'],
    ['label'=>'Download',    'done'=>$has_dl,  'icon'=>'⬇'],
  ];
  $first_active = -1;
  foreach ($steps as $i => $s) { if (!$s['done'] && $first_active === -1) $first_active = $i; }
?>
<div class="ws-progress">
  <?php foreach ($steps as $i => $s):
    $done   = $s['done'];
    $active = (!$done && $i === $first_active);
    $cls    = $done ? 'done' : ($active ? 'active' : '');
  ?>
  <div class="ws-step <?= $cls ?>">
    <div class="step-dot"><?= $done ? '✓' : ($active ? ($i+1) : ($i+1)) ?></div>
    <span class="step-label"><?= e($s['label']) ?></span>
  </div>
  <?php endforeach; ?>
</div>

<!-- METRIC CARDS -->
<div class="ws-metric-grid">
  <div class="ws-metric-card">
    <div class="wm-icon">📄</div>
    <div class="wm-val"><?= $resume_count ?></div>
    <div class="wm-label">Resumes</div>
  </div>
  <div class="ws-metric-card">
    <div class="wm-icon">📬</div>
    <div class="wm-val"><?= $app_count ?></div>
    <div class="wm-label">Applications</div>
  </div>
  <div class="ws-metric-card">
    <div class="wm-icon">⚡</div>
    <div class="wm-val"><?= $credits_used ?></div>
    <div class="wm-label">Credits used</div>
  </div>
  <div class="ws-metric-card">
    <div class="wm-icon"><?= $is_premium ? '✅' : '🆓' ?></div>
    <div class="wm-val" style="font-size:14px;padding-top:4px"><?= $plan_label ?></div>
    <div class="wm-label">Plan</div>
  </div>
</div>

<!-- UPGRADE BANNER (free only) -->
<?php if (!$is_premium): ?>
<div class="ws-upgrade-banner">
  <div style="flex:1;min-width:200px">
    <h3>Unlock the full ATS toolkit</h3>
    <p>Free users see scores. Paid users build, tailor &amp; download.</p>
    <ul>
      <li>Watermark-free PDF export</li>
      <li>JD-matched resume wording</li>
      <li>Cover letter generator</li>
      <li>Unlimited applications tracker</li>
    </ul>
  </div>
  <a class="upgrade-cta" href="/checkout">Upgrade now →</a>
</div>
<?php endif; ?>

<!-- QUICK ACTIONS -->
<h3 style="font-size:14px;font-weight:700;color:#374151;margin:0 0 10px">Quick actions</h3>
<div class="ws-quick-grid">
  <a class="ws-quick-card highlight" href="/workspace/resume">
    <span class="qc-icon">➕</span>
    <span class="qc-title">New Resume</span>
    <span class="qc-desc">Start from scratch or a template</span>
  </a>
  <a class="ws-quick-card" href="/workspace/match">
    <span class="qc-icon">🔍</span>
    <span class="qc-title">Scan a Job</span>
    <span class="qc-desc">ATS compliance check</span>
  </a>
  <a class="ws-quick-card" href="/workspace/resume?templates=1">
    <span class="qc-icon">🎨</span>
    <span class="qc-title">View Templates</span>
    <span class="qc-desc">Browse ATS-safe designs</span>
  </a>
  <a class="ws-quick-card" href="/workspace/applications">
    <span class="qc-icon">📋</span>
    <span class="qc-title">My Applications</span>
    <span class="qc-desc">Track your job pipeline</span>
  </a>
  <?php if ($is_premium): ?>
  <a class="ws-quick-card" href="/workspace/resume?cover=1">
    <span class="qc-icon">✉</span>
    <span class="qc-title">Cover Letters</span>
    <span class="qc-desc">JD-matched, tailored</span>
  </a>
  <?php else: ?>
  <a class="ws-quick-card" href="/checkout" style="border-color:#fbbf24;background:#fffbeb">
    <span class="qc-icon">⬆</span>
    <span class="qc-title">Upgrade Plan</span>
    <span class="qc-desc">Remove watermarks and unlock paid tools</span>
  </a>
  <?php endif; ?>
</div>

<!-- RECENT DOCS + PIPELINE -->
<div class="ws-panels">
  <section class="panel panel-pad" style="margin-bottom:0">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <h3 style="margin:0;font-size:14px">Recent documents</h3>
      <a href="/workspace/resume" class="button secondary small">+ New</a>
    </div>
    <?php if ($resumes): ?>
    <ul class="doc-list">
      <?php foreach ($resumes as $r): ?>
      <li>
        <span class="doc-name" title="<?= e($r['title']) ?>"><?= e($r['title']) ?></span>
        <a class="doc-edit" href="/workspace/resume?id=<?= (int) $r['id'] ?>">Edit →</a>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php else: ?>
    <div style="text-align:center;padding:20px 0;color:#9ca3af;font-size:13px">
      <div style="font-size:32px;margin-bottom:8px">📝</div>
      <div>No resumes yet.</div>
      <a href="/workspace/resume" class="button primary" style="margin-top:12px;display:inline-block">Create your first resume</a>
    </div>
    <?php endif; ?>
  </section>

  <section class="panel panel-pad" style="margin-bottom:0">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <h3 style="margin:0;font-size:14px">Application pipeline</h3>
      <a href="/workspace/applications" class="button secondary small">View all</a>
    </div>
    <div class="ws-pipeline" style="grid-template-columns:1fr 1fr">
      <?php
        $stage_labels = ['saved'=>'Saved','applied'=>'Applied','interview'=>'Interview','offer'=>'Offer'];
        $stage_classes = ['saved'=>'','applied'=>'','interview'=>'stage-interview','offer'=>'stage-offer'];
      ?>
      <?php foreach ($pipeline as $stage => $items): ?>
      <div class="pipeline-col <?= $stage_classes[$stage] ?>">
        <div class="pipeline-col-head">
          <?= $stage_labels[$stage] ?>
          <span class="pipeline-badge"><?= count($items) ?></span>
        </div>
        <?php if ($items): ?>
          <?php foreach (array_slice($items,0,3) as $a): ?>
          <div class="pipeline-item">
            <span class="pi-role"><?= e($a['role'] ?? '—') ?></span>
            <span class="pi-co"><?= e($a['company'] ?? '') ?></span>
          </div>
          <?php endforeach; ?>
          <?php if (count($items) > 3): ?>
          <div class="pipeline-empty">+<?= count($items)-3 ?> more</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="pipeline-empty">None</div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>
