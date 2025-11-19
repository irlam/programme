<?php
declare(strict_types=1);

/**
 * POST /api/import/preview.php
 * Body: multipart/form-data { file, project, ignore_weekends, split_spans, auto_fs, color_contractors }
 * Returns: { ok: true, mode: "lookahead", tasks: [...], base_date, mapping? } or { ok:false, error }
 *
 * Extras:
 *  - GET ?selftest=1  → JSON with environment checks
 *  - Any request with &debug=1 → include exception message/trace in JSON
 */

require __DIR__ . '/../_bootstrap.php';   // sessions, DB config, etc.

use App\Lib\ImportLookahead;

// ---------- helpers ----------
function json_out(int $status, array $data): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

// ---------- self-test ----------
if (isset($_GET['selftest'])) {
  $checks = [
    'bootstrap'        => true,
    'autoload-phpss'   => is_file(__DIR__ . '/../../libs/autoload-phpss.php'),
    'class_IOFactory'  => class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class),
    'class_Spreadsheet'=> class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class),
    'class_Importer'   => class_exists(\App\Lib\ImportLookahead::class),
  ];
  json_out(200, ['ok'=>!in_array(false,$checks,true), 'checks'=>$checks]);
}

// ---------- debug flag ----------
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

// ---------- POST only ----------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  json_out(405, ['ok'=>false, 'error'=>'POST only']);
}

// Try to load PhpSpreadsheet (manual autoloader)
$autoload = __DIR__ . '/../../libs/autoload-phpss.php';
if (is_file($autoload)) { require_once $autoload; }

// Re-check classes
if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
  json_out(500, ['ok'=>false,'error'=>'PhpSpreadsheet not loaded. Open /admin/phpss_check.php.']);
}

try {
  // ---- upload validations ----
  if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name'])) {
    json_out(400, ['ok'=>false, 'error'=>'No file uploaded.']);
  }
  $err = (int)($_FILES['file']['error'] ?? UPLOAD_ERR_OK);
  if ($err !== UPLOAD_ERR_OK) {
    $map = [
      UPLOAD_ERR_INI_SIZE=>'File too large (php.ini).',
      UPLOAD_ERR_FORM_SIZE=>'File too large (form).',
      UPLOAD_ERR_PARTIAL=>'Upload incomplete.',
      UPLOAD_ERR_NO_FILE=>'No file sent.',
      UPLOAD_ERR_NO_TMP_DIR=>'Missing tmp dir.',
      UPLOAD_ERR_CANT_WRITE=>'Disk write fail.',
      UPLOAD_ERR_EXTENSION=>'Upload blocked by extension.'
    ];
    json_out(400, ['ok'=>false, 'error'=>$map[$err] ?? ('Upload error #'.$err)]);
  }
  $tmp  = $_FILES['file']['tmp_name'];
  $name = (string)($_FILES['file']['name'] ?? 'upload');

  if (!is_uploaded_file($tmp)) {
    json_out(400, ['ok'=>false, 'error'=>'Upload not found on disk.']);
  }

  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if (!in_array($ext, ['xlsx','csv'], true)) {
    json_out(400, ['ok'=>false, 'error'=>'Unsupported type (use .xlsx or .csv).']);
  }

  // ---- parse XLSX (lookahead) or CSV (simple table) ----
  if ($ext === 'xlsx') {
    // Load workbook
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
    $reader->setReadDataOnly(false);   // we need fills/fonts to detect colours/headers
    $ss = $reader->load($tmp);
    $ws = $ss->getActiveSheet();

    // Use the improved lookahead parser
    $out = ImportLookahead::parseWorksheet($ws);

    if (empty($out['ok'])) {
      json_out(422, ['ok'=>false, 'error'=>$out['error'] ?? 'Unsupported layout']);
    }

    // pass through feature flags so commit can use them
    $out['color_contractors'] = !empty($_POST['color_contractors']);
    json_out(200, $out);
  }

  // CSV fallback (Task list: Task, Contractor, Start, Finish, Duration[, Section])
  if ($ext === 'csv') {
    $rows = [];
    if (($fh = fopen($tmp, 'r')) !== false) {
      while (($row = fgetcsv($fh)) !== false) {
        // skip completely empty rows
        if (implode('', array_map('trim', $row)) === '') continue;
        $rows[] = $row;
      }
      fclose($fh);
    }
    if (!$rows) json_out(400, ['ok'=>false,'error'=>'CSV appears empty']);

    // header map
    $hdr = array_map(fn($v)=>strtolower(trim((string)$v)), $rows[0]);
    $idx = [
      'task'       => array_search('task', $hdr, true),
      'activity'   => array_search('activity', $hdr, true),
      'contractor' => array_search('contractor', $hdr, true),
      'start'      => array_search('start', $hdr, true),
      'finish'     => array_search('finish', $hdr, true),
      'duration'   => array_search('duration', $hdr, true),
      'section'    => array_search('section', $hdr, true),
    ];
    // at minimum need task/activity + start/finish
    if (($idx['task']===false && $idx['activity']===false) || $idx['start']===false || $idx['finish']===false) {
      json_out(422, ['ok'=>false,'error'=>'CSV must have headers: Task (or Activity), Start, Finish. Optional: Contractor, Duration, Section.']);
    }

    $tasks=[];
    for ($i=1; $i<count($rows); $i++) {
      $r = $rows[$i];
      $get = function($key) use ($idx,$r) {
        $pos = $idx[$key]; return ($pos===false || !isset($r[$pos])) ? '' : trim((string)$r[$pos]);
      };
      $name = $get('task') ?: $get('activity');
      $start= $get('start'); $finish = $get('finish');
      if ($name==='' || $start==='' || $finish==='') continue;

      $dur = $get('duration');
      $dur = ctype_digit($dur) ? (int)$dur : null;

      $tasks[] = [
        'section'       => $get('section') ?: 'Imported CSV',
        'name'          => $name,
        'contractor'    => $get('contractor'),
        'start'         => $start,
        'finish'        => $finish,
        'duration_days' => $dur,
      ];
    }
    json_out(200, [
      'ok'=>true,
      'mode'=>'tasklist',
      'tasks'=>$tasks,
      'base_date'=>null,
      'mapping'=>['headers'=>$hdr],
    ]);
  }

  // Shouldn’t reach here
  json_out(400, ['ok'=>false,'error'=>'Unsupported file']);

} catch (Throwable $e) {
  $msg = $debug ? ($e->getMessage().' @ '.$e->getFile().':'.$e->getLine()) : 'server error';
  json_out(500, ['ok'=>false, 'error'=>$msg]);
}
