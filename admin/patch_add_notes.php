<?php
declare(strict_types=1);

/*
  Admin DB Patch: add tasks.notes column if missing

  Path: /admin/patch_add_notes.php
  Usage: open https://your-domain/admin/patch_add_notes.php
*/

require __DIR__ . '/../api/_bootstrap.php';

use App\Config\DB;

function hasColumn(PDO $pdo, string $table, string $column): bool {
  $sql = "SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME = ?
             AND COLUMN_NAME = ?";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $column]);
  return (bool)$st->fetchColumn();
}

$pdo = DB::pdo();
$messages = [];

try {
  if (!hasColumn($pdo, 'tasks', 'notes')) {
    $pdo->exec("ALTER TABLE tasks ADD COLUMN notes TEXT NULL");
    $messages[] = ['OK', 'Added tasks.notes (TEXT NULL)'];
  } else {
    $messages[] = ['OK', 'tasks.notes already exists'];
  }

  // Optional: visibility check for other columns the importer uses
  foreach (['zone','operatives','duration_days','start_date','finish_date','contractor_id','apartment_id','project_id','name'] as $col) {
    $messages[] = [hasColumn($pdo,'tasks',$col) ? 'OK' : 'WARN', "tasks.$col " . (hasColumn($pdo,'tasks',$col) ? 'present' : 'MISSING')];
  }

} catch (Throwable $e) {
  http_response_code(500);
  $messages[] = ['FAIL', $e->getMessage()];
}

?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>DB Patch • Add tasks.notes</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0}
  header{padding:14px 16px;background:#111827;border-bottom:1px solid #1f2937;display:flex;gap:10px;align-items:center}
  main{max-width:900px;margin:0 auto;padding:16px}
  table{width:100%;border-collapse:collapse}
  th,td{padding:8px;border-bottom:1px solid #1f2937;font-size:14px}
  .ok{color:#22c55e}.warn{color:#fbbf24}.fail{color:#ef4444}
  a{color:#93c5fd;text-decoration:none}
  .pill{background:#1f2937;border:1px solid #334155;padding:6px 10px;border-radius:999px;font-size:12px}
</style>
</head>
<body>
<header>
  <a class="pill" href="/admin/">← Admin</a>
  <strong>DB Patch • Add tasks.notes</strong>
</header>
<main>
  <p>This patch ensures the column <code>tasks.notes</code> exists so the importer can insert data without errors.</p>
  <table>
    <thead><tr><th>Status</th><th>Detail</th></tr></thead>
    <tbody>
      <?php foreach ($messages as [$status,$text]): ?>
        <tr>
          <td class="<?= strtolower($status) ?>"><?= htmlspecialchars($status) ?></td>
          <td><?= htmlspecialchars($text) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <p style="margin-top:16px">
    Next: <a href="/admin/import.html">return to Import</a> and run the import again.
  </p>
  <p class="pill" style="display:inline-block;margin-top:10px">Security tip: delete this file when finished.</p>
</main>
</body>
</html>
