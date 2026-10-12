<style>
/* ── Users page ── */
.usr-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:16px}
.usr-search{display:flex;gap:6px;flex:1;min-width:200px}
.usr-search input[type=search]{flex:1;padding:7px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;color:#1f2937;background:#fff}
.usr-search input[type=search]:focus{outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15)}
.usr-filter-tabs{display:flex;gap:4px;flex-wrap:wrap}
.usr-filter-tabs button{padding:5px 13px;border-radius:99px;font-size:12px;font-weight:600;border:1px solid #e2e8f0;background:#fff;color:#6b7280;cursor:pointer;transition:all .15s}
.usr-filter-tabs button.active,.usr-filter-tabs button:hover{background:#6366f1;border-color:#6366f1;color:#fff}
.usr-count{font-size:12px;color:#9ca3af;margin-left:auto;white-space:nowrap;align-self:center}

.admin-table tr.row-paid{background:#f0fdf4}
.admin-table tr.row-paid td:first-child{border-left:3px solid #22c55e}
.admin-table td,.admin-table th{vertical-align:middle;padding:9px 11px}

.tier-badge{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.tier-badge.fresher{background:#dbeafe;color:#1d4ed8}
.tier-badge.professional{background:#ede9fe;color:#6d28d9}
.tier-badge.power{background:#fef3c7;color:#92400e}

.plan-pill{display:inline-block;padding:2px 8px;border-radius:99px;font-size:10px;font-weight:700}
.plan-pill.paid{background:#dcfce7;color:#15803d}
.plan-pill.free{background:#f3f4f6;color:#6b7280}

.usr-table-wrap{overflow-x:auto;margin-bottom:18px}
.usr-pagination{display:flex;align-items:center;justify-content:space-between;font-size:12px;color:#9ca3af;padding:8px 0;border-top:1px solid #f3f4f6}
.usr-pagination .pg-info{font-size:12px;color:#6b7280}
.usr-pagination .pg-controls{display:flex;gap:6px}
.usr-pagination .pg-controls button{padding:4px 10px;border-radius:6px;font-size:12px;border:1px solid #e2e8f0;background:#fff;color:#374151;cursor:pointer}
.usr-pagination .pg-controls button:disabled{opacity:.4;cursor:default}

.no-users{text-align:center;padding:32px;color:#9ca3af;font-size:13px}
.no-users .no-icon{font-size:36px;margin-bottom:8px}
</style>

<div class="usr-toolbar">
  <!-- Search form -->
  <form class="usr-search" method="get" action="/admin/users">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search by name or email…" autocomplete="off">
    <button class="button primary small">Search</button>
    <?php if ($q !== ''): ?>
    <a href="/admin/users" class="button secondary small">Clear</a>
    <?php endif; ?>
  </form>

  <!-- Client-side filter tabs -->
  <div class="usr-filter-tabs">
    <button class="active" data-filter="all">All</button>
    <button data-filter="free">Free</button>
    <button data-filter="paid">Paid</button>
    <button data-filter="fresher">Fresher</button>
    <button data-filter="experienced">Experienced</button>
  </div>

  <span class="usr-count" id="usr-count"><?= count($users) ?> user<?= count($users) !== 1 ? 's' : '' ?></span>
</div>

<div class="usr-table-wrap">
  <table class="admin-table" id="usr-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Email</th>
        <th>Tier</th>
        <th>Plan</th>
        <th>Expires</th>
        <th>Scans</th>
        <th>Joined</th>
        <th style="width:70px">Action</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
      $paid = !empty($u['is_premium']);
      $tier = strtolower($u['experience_tier'] ?? 'fresher');
      $tier_class = in_array($tier, ['professional','power']) ? $tier : 'fresher';
      $scan_count = 0;
      try {
        $scan_count = (int) \App\Support\DB::value(
          'SELECT COUNT(*) FROM ats_scans WHERE user_id = ?', [$u['id']]
        );
      } catch (\Throwable $ignored) {}
      $joined = substr((string) ($u['created_at'] ?? ''), 0, 10);
      $expires = $u['plan_expires_at'] ? substr((string) $u['plan_expires_at'], 0, 10) : '';
    ?>
    <tr class="<?= $paid ? 'row-paid' : '' ?>"
        data-plan="<?= $paid ? 'paid' : 'free' ?>"
        data-tier="<?= e($tier) ?>"
        data-name="<?= strtolower(e($u['name'])) ?>"
        data-email="<?= strtolower(e($u['email'])) ?>">
      <td>
        <span style="font-weight:600;color:#1f2937"><?= e($u['name']) ?></span>
      </td>
      <td style="font-size:12px;color:#4b5563;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <?= e($u['email']) ?>
      </td>
      <td>
        <span class="tier-badge <?= $tier_class ?>">
          <?= ucfirst(e($u['experience_tier'] ?? 'fresher')) ?>
        </span>
      </td>
      <td>
        <span class="plan-pill <?= $paid ? 'paid' : 'free' ?>">
          <?= $paid ? '✓ ' . strtoupper(e($u['plan'] ?: 'Paid')) : 'Free' ?>
        </span>
      </td>
      <td style="font-size:11px;color:#9ca3af;white-space:nowrap">
        <?= $expires ?: ($paid ? '∞' : '—') ?>
      </td>
      <td style="text-align:center;font-size:13px;font-weight:600;color:<?= $scan_count > 0 ? '#6366f1' : '#d1d5db' ?>">
        <?= $scan_count ?>
      </td>
      <td style="font-size:11px;color:#9ca3af;white-space:nowrap"><?= e($joined) ?></td>
      <td>
        <a href="/admin/users/<?= (int) $u['id'] ?>" class="button secondary small" style="white-space:nowrap">View</a>
      </td>
    </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?>
    <tr><td colspan="8" class="no-users"><div class="no-icon">👤</div>No users found.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
</div>

<div class="usr-pagination">
  <span class="pg-info" id="pg-info">Showing <span id="pg-shown"><?= count($users) ?></span> of <?= count($users) ?></span>
  <div class="pg-controls">
    <button disabled>← Prev</button>
    <button disabled>Next →</button>
  </div>
</div>

<script>
(function () {
  var rows      = Array.from(document.querySelectorAll('#usr-table tbody tr[data-plan]'));
  var tabs      = document.querySelectorAll('.usr-filter-tabs button');
  var countEl   = document.getElementById('usr-count');
  var pgShownEl = document.getElementById('pg-shown');
  var currentFilter = 'all';

  /* Live search – filter in-memory */
  var searchInput = document.querySelector('.usr-search input[type=search]');
  if (searchInput) {
    searchInput.addEventListener('input', applyFilter);
  }

  tabs.forEach(function (btn) {
    btn.addEventListener('click', function () {
      tabs.forEach(function (b) { b.classList.remove('active'); });
      btn.classList.add('active');
      currentFilter = btn.dataset.filter;
      applyFilter();
    });
  });

  function applyFilter() {
    var q = searchInput ? searchInput.value.trim().toLowerCase() : '';
    var visible = 0;
    rows.forEach(function (row) {
      var planMatch = currentFilter === 'all'
        || (currentFilter === 'paid' && row.dataset.plan === 'paid')
        || (currentFilter === 'free' && row.dataset.plan === 'free')
        || (currentFilter === 'fresher' && row.dataset.tier === 'fresher')
        || (currentFilter === 'experienced' && (row.dataset.tier === 'professional' || row.dataset.tier === 'power'));
      var textMatch = q === ''
        || row.dataset.name.includes(q)
        || row.dataset.email.includes(q);
      var show = planMatch && textMatch;
      row.style.display = show ? '' : 'none';
      if (show) visible++;
    });
    if (countEl) countEl.textContent = visible + ' user' + (visible !== 1 ? 's' : '');
    if (pgShownEl) pgShownEl.textContent = visible;
  }
})();
</script>
