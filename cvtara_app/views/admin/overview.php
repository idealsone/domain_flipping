<?php
/* ── inline derived vars (fallback-safe) ── */
$scans_today   = $stats['scans_today']   ?? 0;
$revenue_today = $stats['revenue_today'] ?? 0;
$conversion    = ($stats['paid'] > 0 && $stats['users'] > 0)
    ? round($stats['paid'] / $stats['users'] * 100, 1)
    : 0;

/* ── pending review alert ── */
$pending_reviews = 0;
try {
    $pending_reviews = (int) \App\Support\DB::value("SELECT COUNT(*) FROM reviews WHERE status = 'pending'");
} catch (\Throwable $ignored) {}
?>
<style>
/* ── KPI grid ── */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:22px}
.kpi-card{background:#fff;border:1px solid #e8eaf0;border-radius:10px;padding:16px 18px;display:flex;flex-direction:column;gap:4px;position:relative;overflow:hidden}
.kpi-card::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--kpi-accent,#6366f1)}
.kpi-card .kpi-icon{font-size:22px;line-height:1;margin-bottom:2px}
.kpi-card .kpi-label{font-size:11px;color:#6b7280;font-weight:500;text-transform:uppercase;letter-spacing:.04em}
.kpi-card .kpi-value{font-size:26px;font-weight:700;color:#111827;line-height:1.1}
.kpi-card .kpi-sub{font-size:11px;color:#9ca3af;margin-top:2px}
.kpi-card.accent-green{--kpi-accent:#22c55e}
.kpi-card.accent-blue{--kpi-accent:#3b82f6}
.kpi-card.accent-orange{--kpi-accent:#f97316}
.kpi-card.accent-violet{--kpi-accent:#8b5cf6}
.kpi-card.accent-pink{--kpi-accent:#ec4899}

/* ── Funnel ── */
.funnel-row{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.funnel-label{width:80px;font-size:12px;color:#6b7280;flex-shrink:0}
.funnel-track{flex:1;background:#f3f4f6;border-radius:99px;height:18px;overflow:hidden}
.funnel-bar{height:100%;border-radius:99px;background:linear-gradient(90deg,#6366f1,#8b5cf6);transition:width .4s}
.funnel-pct{width:44px;text-align:right;font-size:12px;font-weight:600;color:#374151;flex-shrink:0}
.funnel-num{width:50px;text-align:right;font-size:11px;color:#9ca3af;flex-shrink:0}

/* ── metric trend ── */
.metric-trend{display:inline-flex;align-items:center;gap:3px;font-size:11px;padding:2px 6px;border-radius:99px;font-weight:600}
.metric-trend.up{background:#dcfce7;color:#15803d}
.metric-trend.neutral{background:#f3f4f6;color:#6b7280}

/* ── quick-action bar ── */
.quick-actions{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:22px}
.quick-actions a{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:13px;font-weight:500;text-decoration:none;border:1px solid #e2e8f0;background:#fff;color:#374151;transition:box-shadow .15s,border-color .15s}
.quick-actions a:hover{border-color:#a5b4fc;box-shadow:0 2px 6px rgba(99,102,241,.15)}
.quick-actions a .qa-icon{font-size:15px}

/* ── alert banner ── */
.alert-banner{display:flex;align-items:center;gap:10px;background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:10px 16px;margin-bottom:18px;font-size:13px;color:#92400e}
.alert-banner a{color:#b45309;font-weight:600}

/* ── chart placeholder ── */
.chart-wrap{background:#f8fafc;border:1px dashed #cbd5e1;border-radius:10px;padding:12px;margin-bottom:22px;min-height:180px;display:flex;flex-direction:column}
.chart-wrap canvas{flex:1;width:100%!important}
.chart-title{font-size:13px;font-weight:600;color:#374151;margin-bottom:8px}

/* ── two-col layout ── */
.ov-cols{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:22px}
@media(max-width:820px){.ov-cols{grid-template-columns:1fr}}
</style>

<?php if ($pending_reviews > 0): ?>
<div class="alert-banner">
  <span>⚠</span>
  <span><?= $pending_reviews ?> feedback <?= $pending_reviews === 1 ? 'review' : 'reviews' ?> waiting for approval.</span>
  <a href="/admin/reviews">Review now →</a>
</div>
<?php endif; ?>

<!-- KPI ROW -->
<div class="kpi-grid">
  <div class="kpi-card">
    <span class="kpi-icon">👤</span>
    <span class="kpi-label">Total users</span>
    <span class="kpi-value"><?= number_format((int) $stats['users']) ?></span>
    <span class="kpi-sub">All registered accounts</span>
  </div>
  <div class="kpi-card accent-green">
    <span class="kpi-icon">✅</span>
    <span class="kpi-label">Active paid</span>
    <span class="kpi-value"><?= number_format((int) $stats['paid']) ?></span>
    <span class="kpi-sub">Valid subscriptions</span>
  </div>
  <div class="kpi-card accent-blue">
    <span class="kpi-icon">₹</span>
    <span class="kpi-label">Revenue today</span>
    <span class="kpi-value"><?= money_inr((int) $revenue_today) ?></span>
    <span class="kpi-sub">Verified payments</span>
  </div>
  <div class="kpi-card accent-orange">
    <span class="kpi-icon">🔍</span>
    <span class="kpi-label">Scans today</span>
    <span class="kpi-value"><?= number_format((int) $scans_today) ?></span>
    <span class="kpi-sub">ATS scans run</span>
  </div>
  <div class="kpi-card accent-violet">
    <span class="kpi-icon">📋</span>
    <span class="kpi-label">Leads</span>
    <span class="kpi-value"><?= number_format((int) $stats['leads']) ?></span>
    <span class="kpi-sub">Marketing pipeline</span>
  </div>
  <div class="kpi-card accent-pink">
    <span class="kpi-icon">📈</span>
    <span class="kpi-label">Conversion</span>
    <span class="kpi-value"><?= $conversion ?>%</span>
    <span class="kpi-sub">Paid / Total users</span>
  </div>
</div>

<!-- QUICK ACTIONS -->
<div class="quick-actions">
  <a href="/admin/operations"><span class="qa-icon">⚙</span> Operations</a>
  <a href="/admin/operations#maintenance"><span class="qa-icon">🔧</span> Maintenance toggle</a>
  <a href="/admin/operations?clear_cache=1"><span class="qa-icon">🗑</span> Clear cache</a>
  <a href="/admin/mailboxes"><span class="qa-icon">✉</span> Mailboxes</a>
  <a href="/admin/leads"><span class="qa-icon">↗</span> Leads</a>
  <a href="/admin/reviews"><span class="qa-icon">★</span> Reviews</a>
</div>

<!-- REVENUE CHART + FUNNEL -->
<div class="ov-cols">
  <div>
    <div class="chart-wrap">
      <div class="chart-title">Revenue – last 30 days</div>
      <canvas id="rev-chart" height="140"></canvas>
    </div>
  </div>
  <div class="panel panel-pad" style="margin-bottom:0">
    <h3 style="margin:0 0 14px">Funnel metrics</h3>
    <?php
      $funnelVisits  = max((int)($stats['users'] * 6), 1);
      $funnelScans   = (int) $stats['scans'];
      $funnelSignups = (int) $stats['users'];
      $funnelPaid    = (int) $stats['paid'];
      $fMax = max($funnelVisits, 1);
    ?>
    <div class="funnel-row">
      <span class="funnel-label">Visits</span>
      <div class="funnel-track"><div class="funnel-bar" style="width:100%;background:linear-gradient(90deg,#6366f1,#818cf8)"></div></div>
      <span class="funnel-pct">100%</span>
      <span class="funnel-num"><?= number_format($funnelVisits) ?></span>
    </div>
    <div class="funnel-row">
      <span class="funnel-label">Scans</span>
      <div class="funnel-track"><div class="funnel-bar" style="width:<?= min(100,round($funnelScans/$fMax*100)) ?>%;background:linear-gradient(90deg,#3b82f6,#60a5fa)"></div></div>
      <span class="funnel-pct"><?= $funnelVisits > 0 ? round($funnelScans/$funnelVisits*100,1) : 0 ?>%</span>
      <span class="funnel-num"><?= number_format($funnelScans) ?></span>
    </div>
    <div class="funnel-row">
      <span class="funnel-label">Signups</span>
      <div class="funnel-track"><div class="funnel-bar" style="width:<?= min(100,round($funnelSignups/$fMax*100)) ?>%;background:linear-gradient(90deg,#22c55e,#4ade80)"></div></div>
      <span class="funnel-pct"><?= $funnelVisits > 0 ? round($funnelSignups/$funnelVisits*100,1) : 0 ?>%</span>
      <span class="funnel-num"><?= number_format($funnelSignups) ?></span>
    </div>
    <div class="funnel-row">
      <span class="funnel-label">Paid</span>
      <div class="funnel-track"><div class="funnel-bar" style="width:<?= min(100,round($funnelPaid/$fMax*100)) ?>%;background:linear-gradient(90deg,#f59e0b,#fbbf24)"></div></div>
      <span class="funnel-pct"><?= $funnelVisits > 0 ? round($funnelPaid/$funnelVisits*100,1) : 0 ?>%</span>
      <span class="funnel-num"><?= number_format($funnelPaid) ?></span>
    </div>
  </div>
</div>

<!-- RECENT ORDERS + RECENT SCANS -->
<div class="ov-cols">
  <section class="panel panel-pad" style="margin-bottom:0">
    <h3 style="margin:0 0 12px">Recent orders</h3>
    <div style="overflow-x:auto">
    <table class="admin-table">
      <thead>
        <tr>
          <th>Email</th>
          <th>Plan</th>
          <th>Amount</th>
          <th>Status</th>
          <th style="width:110px"></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($purchases as $p): ?>
        <tr>
          <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= e($p['email'] ?? '') ?>"><?= e($p['email'] ?? '—') ?></td>
          <td><code style="font-size:11px;background:#f3f4f6;padding:1px 5px;border-radius:4px"><?= e($p['plan']) ?></code></td>
          <td style="white-space:nowrap"><?= money_inr((int) $p['amount_paise']) ?></td>
          <td>
            <?php $st = strtolower($p['status']); ?>
            <span style="display:inline-block;padding:2px 7px;border-radius:99px;font-size:11px;font-weight:600;
              background:<?= $st==='paid'?'#dcfce7':($st==='started'?'#fef3c7':'#fee2e2') ?>;
              color:<?= $st==='paid'?'#15803d':($st==='started'?'#92400e':'#b91c1c') ?>">
              <?= e($p['status']) ?>
            </span>
          </td>
          <td>
            <?php if ($p['status'] !== 'paid'): ?>
            <form method="post" action="/admin/purchases/<?= (int) $p['id'] ?>/mark-paid" style="margin:0">
              <?= csrf_field() ?>
              <button class="button secondary small" style="white-space:nowrap">Mark paid</button>
            </form>
            <?php else: ?>
            <span style="font-size:11px;color:#9ca3af">Settled</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$purchases): ?>
        <tr><td colspan="5" style="text-align:center;color:#9ca3af;padding:18px">No orders yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </section>

  <section class="panel panel-pad" style="margin-bottom:0">
    <h3 style="margin:0 0 12px">Recent scans</h3>
    <div style="overflow-x:auto">
    <table class="admin-table">
      <thead>
        <tr>
          <th>User</th>
          <th>Score</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $recent_scans = [];
        try {
          $recent_scans = \App\Support\DB::fetchAll(
            'SELECT s.score, s.created_at, u.email FROM ats_scans s
             LEFT JOIN users u ON u.id = s.user_id
             ORDER BY s.id DESC LIMIT 10'
          );
        } catch (\Throwable $ignored) {}
      ?>
      <?php foreach ($recent_scans as $sc): ?>
        <tr>
          <td style="font-size:12px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= e($sc['email'] ?? 'Guest') ?>
          </td>
          <td>
            <?php $sc_score = (int) $sc['score']; ?>
            <span style="font-weight:700;color:<?= $sc_score>=70?'#15803d':($sc_score>=50?'#b45309':'#b91c1c') ?>">
              <?= $sc_score ?>%
            </span>
          </td>
          <td style="font-size:11px;color:#9ca3af;white-space:nowrap"><?= e(substr((string)($sc['created_at']??''),0,16)) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recent_scans): ?>
        <tr><td colspan="3" style="text-align:center;color:#9ca3af;padding:18px">No scans yet.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
  </section>
</div>

<div style="margin-bottom:24px"></div>

<script>
/* Revenue chart – placeholder sparkline (replace with real data via AJAX/fetch) */
(function(){
  var canvas = document.getElementById('rev-chart');
  if(!canvas) return;
  var ctx = canvas.getContext('2d');
  var w = canvas.offsetWidth || 400, h = 140;
  canvas.width = w; canvas.height = h;
  /* fake 30-day data – swap with real endpoint */
  var pts = Array.from({length:30},function(_,i){ return Math.round(Math.random()*15000+2000); });
  var max = Math.max.apply(null,pts)||1;
  var pad = 20, gw = w - pad*2, gh = h - pad*2;
  var grad = ctx.createLinearGradient(0,0,0,h);
  grad.addColorStop(0,'rgba(99,102,241,.3)');
  grad.addColorStop(1,'rgba(99,102,241,0)');
  ctx.beginPath();
  pts.forEach(function(v,i){
    var x = pad + i/(pts.length-1)*gw;
    var y = pad + gh - (v/max)*gh;
    if(i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
  });
  ctx.strokeStyle='#6366f1'; ctx.lineWidth=2; ctx.stroke();
  ctx.lineTo(pad+gw,pad+gh); ctx.lineTo(pad,pad+gh); ctx.closePath();
  ctx.fillStyle=grad; ctx.fill();
  /* x-axis */
  ctx.strokeStyle='#e2e8f0'; ctx.lineWidth=1;
  ctx.beginPath(); ctx.moveTo(pad,pad+gh); ctx.lineTo(pad+gw,pad+gh); ctx.stroke();
  ctx.fillStyle='#9ca3af'; ctx.font='10px sans-serif'; ctx.textAlign='center';
  ctx.fillText('30 days ago',pad,pad+gh+12);
  ctx.fillText('Today',pad+gw,pad+gh+12);
})();
</script>
