<?php
declare(strict_types=1);

// Mock API endpoint for testing the lookahead view when database is not available
// This returns sample data matching the contract specified in the requirements

header('Content-Type: application/json');

$block = trim($_GET['block'] ?? 'A');
$floor = isset($_GET['floor']) ? trim($_GET['floor']) : null;
$from  = trim($_GET['from'] ?? date('Y-m-d'));
$weeks = max(1, min(52, (int)($_GET['weeks'] ?? 4)));

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid from date format. Use YYYY-MM-DD']);
    exit;
}

// Calculate to date
try {
    $fromDate = new DateTimeImmutable($from);
    $toDate = $fromDate->add(new DateInterval('P' . ($weeks * 7) . 'D'));
    $to = $toDate->format('Y-m-d');
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'Invalid date: ' . $e->getMessage()]);
    exit;
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

// Generate mock activities
$activities = [];
$sequence = 1;

// Sample activities for Block A
$mockActivities = [
    [
        'description' => 'BWH to structural walls',
        'subcontractor' => 'Panacea',
        'offset_days' => 0,
        'duration' => 5,
        'floor' => '1st',
        'colour' => '#60a5fa'
    ],
    [
        'description' => 'Structural walls 1st fix',
        'subcontractor' => 'Edencroft',
        'offset_days' => 5,
        'duration' => 3,
        'floor' => '1st',
        'colour' => '#f87171'
    ],
    [
        'description' => 'SVP/RWP install',
        'subcontractor' => 'GPL',
        'offset_days' => 8,
        'duration' => 2,
        'floor' => '1st',
        'colour' => '#34d399'
    ],
    [
        'description' => '1st fix wire',
        'subcontractor' => 'Edencroft',
        'offset_days' => 10,
        'duration' => 2,
        'floor' => '1st',
        'colour' => '#f87171'
    ],
    [
        'description' => '1st fix plumbing',
        'subcontractor' => 'GPL',
        'offset_days' => 10,
        'duration' => 4,
        'floor' => '1st',
        'colour' => '#34d399'
    ],
    [
        'description' => '2nd side board structural walls',
        'subcontractor' => 'Panacea',
        'offset_days' => 14,
        'duration' => 5,
        'floor' => '1st',
        'colour' => '#60a5fa'
    ],
    [
        'description' => 'MF ceilings',
        'subcontractor' => 'Panacea',
        'offset_days' => 19,
        'duration' => 6,
        'floor' => '1st',
        'colour' => '#60a5fa'
    ],
    [
        'description' => 'Sprinkler 1st fix',
        'subcontractor' => 'Armstrong',
        'offset_days' => 25,
        'duration' => 3,
        'floor' => '1st',
        'colour' => '#a78bfa'
    ],
];

// Add activities for Ground floor as well
$mockActivitiesGround = [
    [
        'description' => 'BWH to structural walls',
        'subcontractor' => 'Panacea',
        'offset_days' => 3,
        'duration' => 5,
        'floor' => 'Ground',
        'colour' => '#60a5fa'
    ],
    [
        'description' => 'Structural walls 1st fix',
        'subcontractor' => 'Edencroft',
        'offset_days' => 8,
        'duration' => 3,
        'floor' => 'Ground',
        'colour' => '#f87171'
    ],
    [
        'description' => '1st fix wire',
        'subcontractor' => 'Edencroft',
        'offset_days' => 13,
        'duration' => 2,
        'floor' => 'Ground',
        'colour' => '#f87171'
    ],
    [
        'description' => 'MF ceilings',
        'subcontractor' => 'Panacea',
        'offset_days' => 22,
        'duration' => 6,
        'floor' => 'Ground',
        'colour' => '#60a5fa'
    ],
];

// Combine activities based on floor filter
$allMockActivities = $mockActivities;
if (!$floor || $floor === '' || strtolower($floor) === 'ground') {
    $allMockActivities = array_merge($allMockActivities, $mockActivitiesGround);
}

// Filter by floor if specified
if ($floor && $floor !== '') {
    $allMockActivities = array_filter($allMockActivities, function($act) use ($floor) {
        return stripos($act['floor'], $floor) !== false;
    });
}

// Convert to API format
foreach ($allMockActivities as $mock) {
    $startDate = $fromDate->add(new DateInterval('P' . $mock['offset_days'] . 'D'));
    $endDate = $startDate->add(new DateInterval('P' . ($mock['duration'] - 1) . 'D'));
    
    $start = $startDate->format('Y-m-d');
    $end = $endDate->format('Y-m-d');
    
    // Determine status
    $today = date('Y-m-d');
    $status = 'planned';
    $critical = false;
    $delayed = false;
    
    if ($end < $today) {
        $status = 'complete';
    } elseif ($start <= $today && $end >= $today) {
        $status = 'in_progress';
    }
    
    $activities[] = [
        'id' => $sequence,
        'sequence' => $sequence,
        'code' => null,
        'block' => $block,
        'floor' => $mock['floor'],
        'area' => null,
        'description' => $mock['description'],
        'subcontractor' => $mock['subcontractor'],
        'start_date' => $start,
        'end_date' => $end,
        'duration_days' => $mock['duration'],
        'status' => $status,
        'critical' => $critical,
        'delayed' => $delayed,
        'colour' => $mock['colour']
    ];
    
    $sequence++;
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

echo json_encode($response, JSON_PRETTY_PRINT);
