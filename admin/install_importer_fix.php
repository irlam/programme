<?php
declare(strict_types=1);

/**
 * One-off installer for the Lookahead importer.
 * Upload to /admin/install_importer_fix.php, open it in the browser, then delete it.
 */

$root = dirname(__DIR__); // /httpdocs
$files = [];

function put($path, $contents) {
  $dir = dirname($path);
  if (!is_dir($dir)) { mkdir($dir, 0775, true); }
  $ok = file_put_contents($path, $contents);
  return [$ok !== false, $ok === false ? error_get_last()['message'] ?? 'write failed' : 'wrote '.strlen($contents).' bytes'];
}

$importLookahead = <<<'PHP'
<?php
declare(strict_types=1);

namespace App\Lib;

use DateInterval;
use DateTimeImmutable;
use RuntimeException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlsDate;

final class ImportLookahead
{
    public static function parseXlsx(string $path, array $opts = []): array
    {
        // ensure PhpSpreadsheet autoloader is available
        $root = dirname(__DIR__, 2); // /httpdocs
        $autoload = $root . '/libs/autoload-phpss.php';
        if (is_file($autoload)) { require_once $autoload; }

        $ignoreWeekends = !empty($opts['ignore_weekends']);
        $splitSpans     = !empty($opts['split_spans']);

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false); // keep styles (we read fill colours)
        $ss = $reader->load($path);
        $ws = $ss->getSheet(0);

        $maxRow = $ws->getHighestRow();
        $maxColIdx = Coordinate::columnIndexFromString($ws->getHighestColumn());

        // 1) Guess base year from any early date-like cell
        $baseYear = (int)date('Y');
        for ($r = 1; $r <= min($maxRow, 12); $r++) {
            for ($c = 1; $c <= min($maxColIdx, 10); $c++) {
                $cell = $ws->getCellByColumnAndRow($c, $r);
                $v = $cell->getValue();
                if (is_numeric($v) && XlsDate::isDateTime($cell)) {
                    $d = XlsDate::excelToDateTimeObject((float)$v);
                    $baseYear = (int)$d->format('Y'); break 2;
                }
                if (is_string($v)) {
                    $d = self::parseLooseDate($v, $baseYear);
                    if ($d) { $baseYear = (int)$d->format('Y'); break 2; }
                }
            }
        }

        // 2) Find the date band row (most date-like cells on a single row)
        $bestRow = null; $bestCols = [];
        for ($r = 1; $r <= min($maxRow, 15); $r++) {
            $cols = [];
            for ($c = 1; $c <= $maxColIdx; $c++) {
                $cell = $ws->getCellByColumnAndRow($c, $r);
                $v = $cell->getValue();
                $date = null;
                if (is_numeric($v) && XlsDate::isDateTime($cell))       $date = XlsDate::excelToDateTimeObject((float)$v);
                elseif (is_string($v))                                  $date = self::parseLooseDate($v, $baseYear);
                if ($date) $cols[$c] = $date;
            }
            if (count($cols) >= count($bestCols)) { $bestCols = $cols; $bestRow = $r; }
        }
        if (!$bestRow || count($bestCols) < 10) {
            throw new RuntimeException('Could not detect the date band (need a row of daily dates).');
        }

        ksort($bestCols);
        $dateCols = [];            // colIndex => 'Y-m-d'
        $weekdayColsFill = [];     // colIndex => header fill rgb (ignore weekend band shade)
        foreach ($bestCols as $colIdx => $dt) {
            $dateCols[$colIdx] = $dt->format('Y-m-d');
            $weekdayColsFill[$colIdx] = strtoupper(self::rgbFromStyle($ws->getStyleByColumnAndRow($colIdx, $bestRow)) ?? '');
        }
        $dateRow = $bestRow;

        // 3) Identify meta columns (Section/Activity/Contractor/Notes)
        [$colSection, $colActivity, $colContractor, $colNotes] = self::detectMetaColumns($ws, $dateRow);

        // 4) Precompute working dates (if ignoring weekends)
        $workDates = [];
        foreach ($dateCols as $colIdx => $dstr) {
            $dow = (int)(new DateTimeImmutable($dstr))->format('N');
            if ($ignoreWeekends && $dow >= 6) continue;
            $workDates[] = $dstr;
        }

        // 5) Build tasks row by row
        $tasks = [];
        for ($r = $dateRow + 1; $r <= $maxRow; $r++) {
            $name       = trim((string)($ws->getCellByColumnAndRow($colActivity,   $r)->getValue() ?? ''));
            $section    = trim((string)($ws->getCellByColumnAndRow($colSection,    $r)->getValue() ?? ''));
            $contractor = trim((string)($ws->getCellByColumnAndRow($colContractor, $r)->getValue() ?? ''));
            $notes      = trim((string)($ws->getCellByColumnAndRow($colNotes,      $r)->getValue() ?? ''));

            $rowHasMark = false;
            $onDays = [];     // 'Y-m-d' => true
            $rowColours = [];

            foreach ($dateCols as $c => $dstr) {
                $cell  = $ws->getCellByColumnAndRow($c, $r);
                $text  = trim((string)$cell->getValue());
                $style = $ws->getStyleByColumnAndRow($c, $r);
                $rgb   = strtoupper(self::rgbFromStyle($style) ?? '');
                $hdr   = $weekdayColsFill[$c] ?? '';
                $hasFill = $rgb !== '' && $rgb !== 'FFFFFFFF' && $rgb !== '00000000' && $rgb !== $hdr;

                $isOn = ($text !== '' || $hasFill);
                if ($isOn) $rowHasMark = true;

                $dow = (int)(new DateTimeImmutable($dstr))->format('N');
                if ($isOn && (!$ignoreWeekends || $dow <= 5)) {
                    $onDays[$dstr] = true;
                    if ($hasFill) $rowColours[] = $rgb;
                }
            }

            if (!$name && !$rowHasMark) continue;
            if (!$name && $rowHasMark)  $name = 'Untitled activity';

            // Build spans across working days
            $spans = [];
            $curStart = null; $curEnd = null;
            foreach ($workDates as $d) {
                if (!empty($onDays[$d])) {
                    if ($curStart === null) $curStart = $d;
                    $curEnd = $d;
                } else {
                    if ($curStart !== null) { $spans[] = [$curStart, $curEnd]; $curStart = $curEnd = null; }
                }
            }
            if ($curStart !== null) $spans[] = [$curStart, $curEnd];
            if (!$spans) continue;

            $colourHex = self::colourFromSamples($rowColours);
            $i = 0;
            foreach ($spans as [$s, $f]) {
                $i++;
                $tname = $name;
                if ($splitSpans && count($spans) > 1) $tname .= " (Part {$i})";
                $tasks[] = [
                    'name'          => $tname,
                    'contractor'    => $contractor,
                    'section'       => $section,
                    'notes'         => $notes,
                    'start'         => $s,
                    'finish'        => $f,
                    'duration_days' => self::diffWorkingDays($s, $f),
                    'colour'        => $colourHex,
                ];
            }
        }

        $firstDate = reset($dateCols) ?: null;

        return [
            'ok'             => true,
            'mode'           => 'lookahead',
            'base_date'      => $firstDate,
            'activity_col'   => $colActivity,
            'contractor_col' => $colContractor,
            'section_col'    => $colSection,
            'tasks'          => $tasks,
        ];
    }

    private static function parseLooseDate(string $s, int $baseYear): ?\DateTimeImmutable
    {
        $t = trim($s);
        if ($t === '') return null;
        $t = preg_replace('~^\s*(?:W/C|WC|Wk Comm|Week Commencing)\s*[:\-]?\s*~i', '', $t);

        foreach (['!Y-m-d','!d/m/Y','!d/m/y','!d/m','!d.m.Y','!d.m.y','!d.m','!d-M-Y','!d-M-y','!d-M'] as $fmt) {
            $d = \DateTimeImmutable::createFromFormat($fmt, $t);
            if ($d) {
                if (in_array($fmt, ['!d/m','!d.m','!d-M'], true)) {
                    return $d->setDate($baseYear, (int)$d->format('m'), (int)$d->format('d'));
                }
                return $d;
            }
        }
        return null;
    }

    private static function detectMetaColumns($ws, int $dateRow): array
    {
        $scanRows = [];
        for ($r = max(1, $dateRow - 3); $r < $dateRow; $r++) $scanRows[] = $r;
        $maxColIdx = Coordinate::columnIndexFromString($ws->getHighestColumn());
        $score = ['section'=>[], 'activity'=>[], 'contractor'=>[], 'notes'=>[]];

        $syn = [
            'section'    => ['section','area','location','apartment','apartment/zone','block','unit','flat','core','zone'],
            'activity'   => ['activity','task','description','scope'],
            'contractor' => ['contractor','trade','contractor/trade','subbie','subcontractor'],
            'notes'      => ['notes','note','comment','comments','remarks'],
        ];

        foreach ($scanRows as $r) {
            for ($c = 1; $c <= min($maxColIdx, 12); $c++) {
                $val = strtolower(trim((string)$ws->getCellByColumnAndRow($c, $r)->getValue()));
                if ($val === '') continue;
                foreach ($syn as $k => $words) {
                    foreach ($words as $w) {
                        if (strpos($val, $w) !== false) {
                            $score[$k][$c] = ($score[$k][$c] ?? 0) + 1;
                        }
                    }
                }
            }
        }

        $pick = function(array $bucket, int $fallback) {
            if (!$bucket) return $fallback;
            arsort($bucket);
            return (int)array_key_first($bucket);
        };

        return [
            $pick($score['section'],    1), // A
            $pick($score['activity'],   2), // B
            $pick($score['contractor'], 3), // C
            $pick($score['notes'],      4), // D
        ];
    }

    private static function rgbFromStyle($style): ?string
    {
        try {
            $fill = $style->getFill();
            if (!$fill) return null;
            $c = $fill->getStartColor();
            if (!$c) return null;
            $argb = strtoupper($c->getARGB());
            if (strlen($argb) === 8) return substr($argb, 2);
            $rgb = strtoupper($c->getRGB());
            return $rgb ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function colourFromSamples(array $samples): ?string
    {
        if (!$samples) return null;
        $counts = [];
        foreach ($samples as $s) {
            if (!$s) continue;
            $counts[$s] = ($counts[$s] ?? 0) + 1;
        }
        arsort($counts);
        return array_key_first($counts) ?: null;
    }

    /** Inclusive working days Mon–Fri */
    private static function diffWorkingDays(string $start, string $finish): int
    {
        $a = new DateTimeImmutable($start);
        $b = new DateTimeImmutable($finish);
        if ($b < $a) return 1;
        $d = 0; for ($cur = $a; $cur <= $b; $cur = $cur->add(new DateInterval('P1D'))) {
            $dow = (int)$cur->format('N'); if ($dow <= 5) $d++;
        }
        return max(1, $d);
    }
}
PHP;

$previewPhp = <<<'PHP'
<?php
declare(strict_types=1);
require __DIR__ . '/../_bootstrap.php';

use App\Lib\ImportLookahead;

$debug = isset($_GET['debug']) && $_GET['debug'] == '1';
if ($debug) { @ini_set('display_errors','1'); error_reporting(E_ALL); }
header('Content-Type: application/json; charset=utf-8');

try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'POST only']); exit;
  }

  if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException('Upload failed');
  }

  // Ensure PhpSpreadsheet autoloader is available for this endpoint
  $root = dirname(__DIR__, 2); // /httpdocs
  $autoload = $root . '/libs/autoload-phpss.php';
  if (is_file($autoload)) { require_once $autoload; }

  $tmp  = $_FILES['file']['tmp_name'];
  $name = $_FILES['file']['name'];
  $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

  $opts = [
    'ignore_weekends'   => ($_POST['ignore_weekends'] ?? '') === '1',
    'split_spans'       => ($_POST['split_spans'] ?? '') === '1',
    'auto_fs'           => ($_POST['auto_fs'] ?? '') === '1',
    'color_contractors' => ($_POST['color_contractors'] ?? '') === '1',
  ];

  if (in_array($ext, ['xlsx','xlsm','xls'], true)) {
    if (!class_exists(ImportLookahead::class)) {
      throw new RuntimeException('ImportLookahead class not found (place at /app/Lib/ImportLookahead.php)');
    }
    $out = ImportLookahead::parseXlsx($tmp, $opts);
    $out['opts'] = $opts;
    echo json_encode($out); exit;
  }

  if ($ext === 'csv') {
    $fh = fopen($tmp, 'r');
    if (!$fh) throw new RuntimeException('Cannot read CSV');
    $hdr = fgetcsv($fh, 0, ',');
    $map = array_flip(array_map(fn($x)=>strtolower(trim((string)$x)), $hdr ?: []));
    $need = ['section','activity','contractor','start','finish'];
    foreach ($need as $k) if (!isset($map[$k])) throw new RuntimeException("CSV missing column: {$k}");
    $tasks = [];
    while (($row = fgetcsv($fh, 0, ',')) !== false) {
      $sec = trim((string)($row[$map['section']] ?? ''));
      $act = trim((string)($row[$map['activity']] ?? ''));
      $con = trim((string)($row[$map['contractor']] ?? ''));
      $st  = trim((string)($row[$map['start']] ?? ''));
      $fi  = trim((string)($row[$map['finish']] ?? ''));
      $no  = isset($map['notes']) ? trim((string)($row[$map['notes']])) : '';
      if ($act === '') continue;
      $tasks[] = ['name'=>$act,'contractor'=>$con,'section'=>$sec,'notes'=>$no,'start'=>$st,'finish'=>$fi];
    }
    fclose($fh);
    echo json_encode(['ok'=>true,'mode'=>'csv','tasks'=>$tasks,'opts'=>$opts]); exit;
  }

  throw new RuntimeException('Unsupported file type');
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
PHP;

$diag = <<<'PHP'
<?php
declare(strict_types=1);
$root = dirname(__DIR__);
header('Content-Type: text/plain; charset=utf-8');
echo "Importer Self-Test\n";
echo "Docroot: $root\n\n";

function line($k,$v){ echo str_pad($k,28).": $v\n"; }

$autoload = $root . '/libs/autoload-phpss.php';
line('autoload-phpss.php', is_file($autoload) ? 'found' : 'MISSING');
if (is_file($autoload)) {
  require_once $autoload;
  line('PhpSpreadsheet class', class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class) ? 'OK' : 'FAIL');
}

$importer = $root . '/app/Lib/ImportLookahead.php';
line('ImportLookahead.php', is_file($importer) ? 'found' : 'MISSING');
if (is_file($importer)) {
  require_once $importer;
  line('App\\Lib\\ImportLookahead', class_exists(\App\Lib\ImportLookahead::class) ? 'OK' : 'FAIL');
}

$preview = $root . '/api/import/preview.php';
line('api/import/preview.php', is_file($preview) ? 'found' : 'MISSING');

echo "\nDone.\n";
PHP;

// write files
list($ok1,$msg1) = put($root.'/app/Lib/ImportLookahead.php', $importLookahead);
$files[] = ['/app/Lib/ImportLookahead.php', $ok1, $msg1];

list($ok2,$msg2) = put($root.'/api/import/preview.php', $previewPhp);
$files[] = ['/api/import/preview.php', $ok2, $msg2];

list($ok3,$msg3) = put($root.'/admin/import_diag.php', $diag);
$files[] = ['/admin/import_diag.php', $ok3, $msg3];

echo "<h2>Importer Fix • Installer</h2>";
echo "<p>Root: {$root}</p>";
echo "<table border='1' cellpadding='6' cellspacing='0'>";
echo "<tr><th>File</th><th>Status</th><th>Details</th></tr>";
foreach ($files as [$p,$ok,$m]) {
  echo "<tr><td><code>{$p}</code></td><td>".($ok?'OK':'FAIL')."</td><td>".htmlspecialchars($m)."</td></tr>";
}
echo "</table>";
echo "<p>Next: <a href='/admin/import_diag.php' target='_blank'>Run self-test</a>. Then hard refresh the Import page and try again.</p>";
echo "<p><strong>Security:</strong> delete this installer when finished.</p>";
