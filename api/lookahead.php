<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

use App\Config\DB;

header('Content-Type: application/json');

function ok($data) { echo json_encode($data); exit; }
function bad($msg, $code=400) { http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg]); exit; }

$pdo = DB::pdo();

// Parse query params
$block = trim($_GET['block'] ?? '');
$floor = isset($_GET['floor']) ? trim($_GET['floor']) : null;
$from  = trim($_GET['from'] ?? '');
$weeks = max(1, min(52, (int)($_GET['weeks'] ?? 4)));

// Default from date to today if not provided
if (!$from) {
    $from = date('Y-m-d');
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    bad('Invalid from date format. Use YYYY-MM-DD');
}

// Calculate to date
try {
    $fromDate = new DateTimeImmutable($from);
    $toDate = $fromDate->add(new DateInterval('P' . ($weeks * 7) . 'D'));
    $to = $toDate->format('Y-m-d');
} catch (Exception $e) {
    bad('Invalid date: ' . $e->getMessage());
}

// Build days array
$days = [];
$currentDate = $fromDate;
$endDate = $toDate;
while ($currentDate < $endDate) {
    $days[] = [
        'date' => $currentDate->format('Y-m-d'),
        'day_of_week' => $currentDate->format('D'),
        'day' => (int)$currentDate->format('j'),
        'month' => $currentDate->format('M'),
        'month_num' => (int)$currentDate->format('n'),
        'year' => (int)$currentDate->format('Y')
    ];
    $currentDate = $currentDate->add(new DateInterval('P1D'));
}

// Query tasks overlapping with the date range
$sql = "SELECT t.id, t.name AS description, t.start_date, t.finish_date, 
               t.duration_days, t.percent_complete, t.zone,
               c.name AS subcontractor, c.colour,
               a.block, a.floor, a.unit, a.type AS area
        FROM tasks t
        LEFT JOIN contractors c ON c.id = t.contractor_id
        LEFT JOIN apartments a ON a.id = t.apartment_id
        WHERE t.project_id = 1
          AND t.start_date IS NOT NULL
          AND t.finish_date IS NOT NULL
          AND t.start_date <= ?
          AND t.finish_date >= ?";

$params = [$to, $from];

// Filter by block if provided
if ($block !== '') {
    $sql .= " AND (a.block = ? OR a.block LIKE ?)";
    $params[] = $block;
    $params[] = '%' . $block . '%';
}

// Filter by floor if provided
if ($floor !== null && $floor !== '') {
    $sql .= " AND (a.floor = ? OR a.floor LIKE ?)";
    $params[] = $floor;
    $params[] = '%' . $floor . '%';
}

$sql .= " ORDER BY a.block, a.floor, a.unit, t.start_date, t.id";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Build activities array
$activities = [];
$sequence = 1;
foreach ($rows as $row) {
    // Determine status based on completion and dates
    $status = 'planned';
    $today = date('Y-m-d');
    $critical = false;
    $delayed = false;
    
    if ($row['percent_complete'] >= 100) {
        $status = 'complete';
    } elseif ($row['start_date'] <= $today && $row['finish_date'] >= $today) {
        $status = 'in_progress';
    } elseif ($row['finish_date'] < $today && $row['percent_complete'] < 100) {
        $status = 'delayed';
        $delayed = true;
    }
    
    // Check alerts_json for critical flag (if it exists)
    // For now, mark as critical if delayed
    if ($delayed) {
        $critical = true;
    }
    
    $activities[] = [
        'id' => (int)$row['id'],
        'sequence' => $sequence++,
        'code' => null, // Not in current schema
        'block' => $row['block'] ?? '',
        'floor' => $row['floor'] ?? '',
        'area' => $row['area'] ?? $row['zone'] ?? null,
        'description' => $row['description'] ?? '',
        'subcontractor' => $row['subcontractor'] ?? '',
        'start_date' => $row['start_date'],
        'end_date' => $row['finish_date'],
        'duration_days' => (int)$row['duration_days'],
        'status' => $status,
        'critical' => $critical,
        'delayed' => $delayed,
        'colour' => $row['colour'] ?? null
    ];
}

// Build response
$response = [
    'block' => $block,
    'floor' => $floor,
    'from' => $from,
    'to' => $to,
    'weeks' => $weeks,
    'generated' => (new DateTime())->format('c'),
    'timezone' => date_default_timezone_get(),
    'days' => $days,
    'activities' => $activities
];

ok($response);
