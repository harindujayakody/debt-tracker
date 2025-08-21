<?php
// Debt Tracker — PHP + SQLite (single-file)
// LKR currency, Debts + Payments, summary cards with progress bars,
// per-person progress in table, Edit modal, and Charts:
// - Remaining by Person (Bar, colored per person)
// - Payments per Month (Doughnut, colored per month)
// Run: php -S localhost:8000

session_start();
if (!isset($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

// --- Database bootstrap ---
function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $dsn = 'sqlite:' . __DIR__ . DIRECTORY_SEPARATOR . 'debt_tracker.sqlite';
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    init_db($pdo);
  }
  return $pdo;
}
function init_db(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS debts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    who TEXT NOT NULL,
    label TEXT,
    amount REAL NOT NULL DEFAULT 0,
    created_at TEXT DEFAULT (datetime('now'))
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_debts_who ON debts(who)");

  $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    who TEXT NOT NULL,
    amount REAL NOT NULL DEFAULT 0,
    paid_at TEXT DEFAULT (date('now')),
    note TEXT
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_payments_who ON payments(who)");
}

// --- Helpers ---
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
function money_lkr($n){ return 'LKR ' . number_format((float)$n, 2, '.', ','); }
function csrf_check(): void {
  if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf'] ?? '')) {
      http_response_code(403); exit('Invalid CSRF token');
    }
  }
}
csrf_check();

// --- Actions ---
$action = $_POST['action'] ?? null;
try {
  if ($action === 'add_debt') {
    $stmt = db()->prepare("INSERT INTO debts (who,label,amount,created_at) VALUES (:who,:label,:amount,:ts)");
    $stmt->execute([
      ':who'=>trim((string)($_POST['who'] ?? '')),
      ':label'=>trim((string)($_POST['label'] ?? '')),
      ':amount'=>max(0, (float)($_POST['amount'] ?? 0)),
      ':ts'=> date('Y-m-d H:i:s'),
    ]);
  }
  if ($action === 'delete_debt') {
    $stmt = db()->prepare("DELETE FROM debts WHERE id=:id");
    $stmt->execute([':id'=>(int)($_POST['id'] ?? 0)]);
  }
  if ($action === 'add_payment') {
    $stmt = db()->prepare("INSERT INTO payments (who,amount,paid_at,note) VALUES (:who,:amount,:paid_at,:note)");
    $stmt->execute([
      ':who'=>trim((string)($_POST['who'] ?? '')),
      ':amount'=>max(0, (float)($_POST['amount'] ?? 0)),
      ':paid_at'=>($_POST['paid_at'] ?? date('Y-m-d')),
      ':note'=>trim((string)($_POST['note'] ?? '')),
    ]);
  }
  if ($action === 'delete_payment') {
    $stmt = db()->prepare("DELETE FROM payments WHERE id=:id");
    $stmt->execute([':id'=>(int)($_POST['id'] ?? 0)]);
  }
  // Rename person across debts & payments
  if ($action === 'rename_person') {
    $old = trim((string)($_POST['old_who'] ?? ''));
    $new = trim((string)($_POST['new_who'] ?? ''));
    if ($old !== '' && $new !== '' && $old !== $new) {
      $u1 = db()->prepare("UPDATE debts SET who=:new WHERE who=:old");
      $u1->execute([':new'=>$new, ':old'=>$old]);
      $u2 = db()->prepare("UPDATE payments SET who=:new WHERE who=:old");
      $u2->execute([':new'=>$new, ':old'=>$old]);
    }
  }
  // Quick add debt for a person from modal
  if ($action === 'add_debt_person') {
    $who = trim((string)($_POST['who'] ?? ''));
    if ($who !== '') {
      $stmt = db()->prepare("INSERT INTO debts (who,label,amount,created_at) VALUES (:who,:label,:amount,:ts)");
      $stmt->execute([
        ':who'=>$who,
        ':label'=>trim((string)($_POST['label'] ?? '')),
        ':amount'=>max(0,(float)($_POST['amount'] ?? 0)),
        ':ts'=> date('Y-m-d H:i:s'),
      ]);
    }
  }
} catch (Throwable $e) {
  echo '<pre style="color:#b00">Error: '.h($e->getMessage()).'</pre>';
}

// --- Queries ---
$debts = db()->query("SELECT * FROM debts ORDER BY created_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
$payments = db()->query("SELECT * FROM payments ORDER BY paid_at DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Totals
$total_debt_sum = (float)db()->query("SELECT IFNULL(SUM(amount),0) FROM debts")->fetchColumn();
$total_paid_sum = (float)db()->query("SELECT IFNULL(SUM(amount),0) FROM payments")->fetchColumn();

// People list
$people = db()->query("SELECT who FROM debts UNION SELECT who FROM payments")->fetchAll(PDO::FETCH_COLUMN);

// Per-person aggregates and remaining
$person_rows = [];
$total_remaining = 0.0;
if ($people) {
  $sumDebtsStmt = db()->prepare("SELECT IFNULL(SUM(amount),0) FROM debts WHERE who=:w");
  $sumPaysStmt  = db()->prepare("SELECT IFNULL(SUM(amount),0) FROM payments WHERE who=:w");
  foreach ($people as $p) {
    $sumDebtsStmt->execute([':w'=>$p]);
    $d = (float)$sumDebtsStmt->fetchColumn();
    $sumPaysStmt->execute([':w'=>$p]);
    $pay = (float)$sumPaysStmt->fetchColumn();
    $remain = max(0, $d - $pay);
    $total_remaining += $remain;
    $person_rows[] = ['who'=>$p, 'debt'=>$d, 'paid'=>$pay, 'remaining'=>$remain];
  }
}

// Chart data
$chart_by_person = array_values(array_filter(array_map(function($r){
  return $r['remaining'] > 0 ? ['who'=>$r['who'], 'remaining'=>$r['remaining']] : null;
}, $person_rows)));

// Payments per month (YYYY-MM => sum)
$timeline_rows = db()->query("SELECT strftime('%Y-%m', paid_at) as ym, SUM(amount) as amt FROM payments GROUP BY ym ORDER BY ym ASC")
  ->fetchAll(PDO::FETCH_ASSOC);
$timeline = [];
foreach ($timeline_rows as $row) { $timeline[$row['ym']] = (float)$row['amt']; }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Debt Tracker — PHP + SQLite (LKR)</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
  <style>html,body{font-family:"Plus Jakarta Sans",Inter,ui-sans-serif,system-ui,Segoe UI,Roboto,Helvetica,Arial}</style>
</head>
<body class="min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 text-slate-900">
<div class="max-w-6xl mx-auto p-4 md:p-8">
  <header class="flex flex-col gap-3 mb-4">
    <div>
      <h1 class="text-2xl md:text-3xl font-bold tracking-tight">Debt Tracker (LKR)</h1>
      <p class="text-sm text-slate-500">Track who you owe, record repayments, and see remaining balances.</p>
    </div>
  </header>

  <!-- Summary Cards with progress -->
  <?php
    $progress_ratio = $total_debt_sum > 0 ? ($total_paid_sum / $total_debt_sum) : 0;
    $progress_pct = max(0, min(100, round($progress_ratio * 100)));
    $remaining_pct = 100 - $progress_pct;
  ?>
  <section class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
    <div class="rounded-2xl border bg-white p-5 shadow-sm relative overflow-hidden">
      <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-indigo-100"></div>
      <div class="text-xs uppercase tracking-wide text-slate-500 mb-1">Total Debt</div>
      <div class="text-3xl font-bold"><?=h(money_lkr($total_debt_sum))?></div>
      <div class="mt-2 text-xs text-slate-500">Sum of all principal owed</div>
      <div class="mt-4">
        <div class="flex items-center justify-between text-xs text-slate-500 mb-1"><span>Paid</span><span><?= $progress_pct ?>%</span></div>
        <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
          <div class="h-2 bg-indigo-500" style="width: <?= $progress_pct ?>%"></div>
        </div>
      </div>
    </div>
    <div class="rounded-2xl border bg-white p-5 shadow-sm relative overflow-hidden">
      <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-emerald-100"></div>
      <div class="text-xs uppercase tracking-wide text-slate-500 mb-1">Total Paid</div>
      <div class="text-3xl font-bold"><?=h(money_lkr($total_paid_sum))?></div>
      <div class="mt-2 text-xs text-slate-500">All repayments so far</div>
      <div class="mt-4">
        <div class="flex items-center justify-between text-xs text-slate-500 mb-1"><span>Progress</span><span><?= $progress_pct ?>%</span></div>
        <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
          <div class="h-2 bg-emerald-500" style="width: <?= $progress_pct ?>%"></div>
        </div>
      </div>
    </div>
    <div class="rounded-2xl border bg-white p-5 shadow-sm relative overflow-hidden">
      <div class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-rose-100"></div>
      <div class="text-xs uppercase tracking-wide text-slate-500 mb-1">Remaining</div>
      <div class="text-3xl font-bold"><?=h(money_lkr($total_remaining))?></div>
      <div class="mt-2 text-xs text-slate-500">What’s left to settle</div>
      <div class="mt-4">
        <div class="flex items-center justify-between text-xs text-slate-500 mb-1"><span>Remaining</span><span><?= $remaining_pct ?>%</span></div>
        <div class="h-2 w-full rounded-full bg-slate-100 overflow-hidden">
          <div class="h-2 bg-rose-500" style="width: <?= $remaining_pct ?>%"></div>
        </div>
      </div>
    </div>
  </section>

  <!-- Add Debt / Add Payment -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="rounded-2xl border bg-white">
      <div class="p-4 border-b"><h2 class="font-semibold">Add Debt</h2></div>
      <div class="p-4">
        <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
          <input type="hidden" name="action" value="add_debt">
          <div>
            <label class="text-sm">For Who</label>
            <input name="who" class="w-full rounded-xl border px-3 py-2 text-sm" placeholder="e.g. Tharindu" required />
          </div>
          <div>
            <label class="text-sm">Label (optional)</label>
            <input name="label" class="w-full rounded-xl border px-3 py-2 text-sm" placeholder="e.g. Loan / Rent" />
          </div>
          <div>
            <label class="text-sm">Amount (LKR)</label>
            <input name="amount" type="number" step="0.01" class="w-full rounded-xl border px-3 py-2 text-sm" placeholder="90000" required />
          </div>
          <div class="md:col-span-2">
            <button class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm hover:bg-slate-50">Add Debt</button>
          </div>
        </form>
      </div>
    </div>

    <div class="rounded-2xl border bg-white">
      <div class="p-4 border-b"><h2 class="font-semibold">Add Payment</h2></div>
      <div class="p-4">
        <form method="post" class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
          <input type="hidden" name="action" value="add_payment">
          <div>
            <label class="text-sm">Who</label>
            <select name="who" class="w-full rounded-xl border px-3 py-2 text-sm" required>
              <?php if (!$people): ?>
                <option value="" disabled selected>Add a debt first</option>
              <?php else: foreach ($people as $p): ?>
                <option value="<?=h($p)?>"><?=h($p)?></option>
              <?php endforeach; endif; ?>
            </select>
          </div>
          <div>
            <label class="text-sm">Amount Paid (LKR)</label>
            <input name="amount" type="number" step="0.01" class="w-full rounded-xl border px-3 py-2 text-sm" placeholder="15000" required />
          </div>
          <div>
            <label class="text-sm">Paid At</label>
            <input name="paid_at" type="date" value="<?=h(date('Y-m-d'))?>" class="w-full rounded-xl border px-3 py-2 text-sm" />
          </div>
          <div>
            <label class="text-sm">Note (optional)</label>
            <input name="note" class="w-full rounded-xl border px-3 py-2 text-sm" placeholder="e.g. Cash transfer" />
          </div>
          <div class="md:col-span-2">
            <button class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm hover:bg-slate-50">Add Payment</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- People Overview -->
  <div class="rounded-2xl border bg-white mt-6">
    <div class="p-4 border-b flex items-center justify-between">
      <h3 class="font-semibold">People You Owe</h3>
    </div>
    <div class="p-4 overflow-auto">
      <?php if (empty($person_rows)): ?>
        <div class="text-center text-slate-500 py-8">No debts yet. Add your first debt above.</div>
      <?php else: ?>
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-slate-500 border-b">
              <th class="py-2 pr-2">Person</th>
              <th class="py-2 pr-2">Total Owed</th>
              <th class="py-2 pr-2">Paid</th>
              <th class="py-2 pr-2">Remaining</th>
              <th class="py-2">Quick Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($person_rows as $r): ?>
            <tr class="border-b">
              <td class="py-2 pr-2 font-medium"><?=h($r['who'])?></td>
              <td class="py-2 pr-2"><?=h(money_lkr($r['debt']))?></td>
              <td class="py-2 pr-2"><?=h(money_lkr($r['paid']))?></td>
              <td class="py-2 pr-2 font-semibold align-top">
                <?php $pct = $r['debt']>0 ? round(($r['paid']/$r['debt'])*100) : 0; ?>
                <?=h(money_lkr($r['remaining']))?>
                <div class="mt-1 h-2 rounded-full bg-slate-100 overflow-hidden w-44">
                  <div class="h-2 bg-emerald-500" style="width: <?=$pct?>%"></div>
                </div>
                <div class="text-[11px] text-slate-500 mt-1"><?=$pct?>% paid</div>
              </td>
              <td class="py-2 align-top">
                <div class="flex flex-wrap items-center gap-2">
                  <form method="post" class="inline-flex items-center gap-2" onsubmit="return this.amount.value>0">
                    <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                    <input type="hidden" name="action" value="add_payment">
                    <input type="hidden" name="who" value="<?=h($r['who'])?>">
                    <input type="number" name="amount" step="0.01" class="w-28 rounded-xl border px-2 py-1" placeholder="Amount" />
                    <button class="px-2 py-1 border rounded-lg">Pay</button>
                  </form>
                  <a class="px-3 py-1 border rounded-lg" href="?edit_person=<?=urlencode($r['who'])?>">Edit</a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Raw entries -->
  <div class="space-y-6 mt-6">
    <div class="rounded-2xl border bg-white">
      <div class="p-4 border-b flex items-center justify-between"><h3 class="font-semibold">All Debts</h3></div>
      <div class="p-4">
        <?php if (empty($debts)): ?>
          <div class="text-center text-slate-500 py-8">No debt entries.</div>
        <?php else: ?>
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-slate-500 border-b">
                <th class="py-2 pr-2">Person</th>
                <th class="py-2 pr-2">Label</th>
                <th class="py-2 pr-2">Amount</th>
                <th class="py-2 pr-2">Created</th>
                <th class="py-2">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($debts as $d): ?>
              <tr class="border-b">
                <td class="py-2 pr-2"><?=h($d['who'])?></td>
                <td class="py-2 pr-2"><?=h($d['label'])?></td>
                <td class="py-2 pr-2"><?=h(money_lkr($d['amount']))?></td>
                <td class="py-2 pr-2"><?=h(substr($d['created_at'],0,10))?></td>
                <td class="py-2">
                  <form method="post" class="inline" onsubmit="return confirm('Delete this debt entry?')">
                    <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                    <input type="hidden" name="action" value="delete_debt">
                    <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                    <button class="px-2 py-1 border rounded-lg">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

    <div class="rounded-2xl border bg-white">
      <div class="p-4 border-b flex items-center justify-between"><h3 class="font-semibold">Payments</h3></div>
      <div class="p-4">
        <?php if (empty($payments)): ?>
          <div class="text-center text-slate-500 py-8">No payments recorded.</div>
        <?php else: ?>
          <table class="w-full text-sm">
            <thead>
              <tr class="text-left text-slate-500 border-b">
                <th class="py-2 pr-2">Person</th>
                <th class="py-2 pr-2">Amount</th>
                <th class="py-2 pr-2">Paid At</th>
                <th class="py-2 pr-2">Note</th>
                <th class="py-2">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($payments as $p): ?>
              <tr class="border-b">
                <td class="py-2 pr-2"><?=h($p['who'])?></td>
                <td class="py-2 pr-2"><?=h(money_lkr($p['amount']))?></td>
                <td class="py-2 pr-2"><?=h($p['paid_at'])?></td>
                <td class="py-2 pr-2"><?=h($p['note'])?></td>
                <td class="py-2">
                  <form method="post" class="inline" onsubmit="return confirm('Delete this payment?')">
                    <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
                    <input type="hidden" name="action" value="delete_payment">
                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                    <button class="px-2 py-1 border rounded-lg">Delete</button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Charts -->
  <div class="grid grid-cols-1 lg:grid-cols-5 gap-6 mt-6">
    <div class="rounded-2xl border bg-white lg:col-span-3">
      <div class="p-4 border-b"><h3 class="font-semibold">Remaining by Person</h3></div>
      <div class="p-4"><canvas id="remainingBar" height="280"></canvas></div>
    </div>
    <div class="rounded-2xl border bg-white lg:col-span-2">
      <div class="p-4 border-b"><h3 class="font-semibold">Payments per Month</h3></div>
      <div class="p-4"><canvas id="timelineDonut" height="280"></canvas></div>
    </div>
  </div>

  <div class="rounded-2xl border bg-white mt-6">
    <div class="p-4 border-b"><h3 class="font-semibold">Notes</h3></div>
    <div class="p-4 text-sm text-slate-600 space-y-2">
      <p>Example: If you owe <strong>LKR 90,000</strong> to <strong>Tharindu</strong> and record a payment of <strong>LKR 15,000</strong>, the app deducts it from Tharindu’s total and the overall Total Remaining.</p>
      <p>All data is stored locally in <code>debt_tracker.sqlite</code>. Back it up by copying that file.</p>
    </div>
  </div>

  <footer class="text-center text-xs text-slate-500 mt-6">Built with ❤️ using PHP, SQLite, Tailwind, and Chart.js.</footer>
</div>

<?php $edit_person = $_GET['edit_person'] ?? null; if ($edit_person): ?>
<!-- Person Edit Modal -->
<div class="fixed inset-0 z-50 flex items-center justify-center">
  <div class="absolute inset-0 bg-black/40" onclick="window.location='?' "></div>
  <div class="relative z-10 w-[92vw] max-w-xl rounded-2xl border bg-white p-5 shadow-xl">
    <div class="flex items-center justify-between border-b pb-2 mb-3">
      <h3 class="font-semibold">Edit Person — <?=h($edit_person)?></h3>
      <a href="?" class="text-sm text-slate-500">Close</a>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <form method="post" class="space-y-2">
        <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
        <input type="hidden" name="action" value="rename_person">
        <input type="hidden" name="old_who" value="<?=h($edit_person)?>">
        <label class="text-sm">Rename to</label>
        <input name="new_who" class="w-full rounded-xl border px-3 py-2 text-sm" placeholder="New name" required />
        <button class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm hover:bg-slate-50">Save Name</button>
      </form>

      <form method="post" class="space-y-2">
        <input type="hidden" name="csrf" value="<?=h($_SESSION['csrf'])?>">
        <input type="hidden" name="action" value="add_debt_person">
        <input type="hidden" name="who" value="<?=h($edit_person)?>">
        <label class="text-sm">Add Debt (LKR)</label>
        <input name="amount" type="number" step="0.01" class="w-full rounded-xl border px-3 py-2 text-sm" placeholder="Amount" required />
        <input name="label" class="w-full rounded-xl border px-3 py-2 text-sm" placeholder="Label (optional)" />
        <button class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm hover:bg-slate-50">Add</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
  const remaining = <?= json_encode($chart_by_person, JSON_UNESCAPED_SLASHES) ?>;
  const timelineMap = <?= json_encode($timeline, JSON_UNESCAPED_SLASHES) ?>;

  // Convert timeline map to arrays
  const tlLabels = Object.keys(timelineMap);
  const tlData = Object.values(timelineMap).map(Number);

  // Simple color generator (distinct HSLs)
  function genColors(n) {
    const arr = [];
    for (let i = 0; i < n; i++) {
      const hue = Math.round((360 / Math.max(1, n)) * i);
      arr.push(`hsl(${hue} 70% 55%)`);
    }
    return arr;
  }

  // Remaining by Person — Bar with distinct colors
  const barEl = document.getElementById('remainingBar');
  if (barEl) {
    const labels = remaining.map(r => r.who);
    const data = remaining.map(r => Number(r.remaining || 0));
    const colors = genColors(data.length);

    new Chart(barEl, {
      type: 'bar',
      data: { labels, datasets: [{ label: 'Remaining (LKR)', data, backgroundColor: colors, borderWidth: 0, borderRadius: 8 }] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ` ${ctx.formattedValue} LKR` } } },
        scales: { y: { beginAtZero: true } },
      }
    });
  }

  // Payments per Month — Doughnut with distinct colors
  const donutEl = document.getElementById('timelineDonut');
  if (donutEl) {
    const colors = genColors(tlData.length);
    new Chart(donutEl, {
      type: 'doughnut',
      data: { labels: tlLabels, datasets: [{ label: 'Payments (LKR)', data: tlData, backgroundColor: colors }] },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: ctx => `${ctx.label}: ${ctx.formattedValue} LKR` } } }
      }
    });
  }
</script>
</body>
</html>
