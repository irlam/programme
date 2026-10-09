<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/Lib/fpdf/fpdf.php';
require dirname(__DIR__) . '/app/Lib/ShortTermReport.php';

use App\Lib\ShortTermReport;

function checkBounds(ShortTermReport $pdf): void {
    $pdf->SetCompression(false);
    $data = $pdf->Output('S');
    preg_match_all('/(-?[0-9.]+) (-?[0-9.]+) (-?[0-9.]+) (-?[0-9.]+) re/', $data, $rects, PREG_SET_ORDER);
    if (!$rects) throw new RuntimeException('No report rectangles found');
    foreach ($rects as $r) {
        $left = (float)$r[1]; $right = $left + (float)$r[3];
        if ($left < 33.9 || $right > 808.0) throw new RuntimeException('Rectangle escaped landscape A4 margins');
    }
}

// Test only report rendering: no live database, session or project mutation.
$output = $argv[1] ?? sys_get_temp_dir() . '/programme-shortterm-report-tests';
if (!is_dir($output)) mkdir($output, 0700, true);
$tasks = [];
for ($i = 0; $i < 71; $i++) {
    $tasks[] = ['name' => $i === 4 ? str_repeat('ExtraordinarilyLongUnbrokenActivityName', 45)
        : 'Installation of New Electrical Containment and Controls Wiring Following Window Installation ' . ($i + 1),
        'operatives' => 1, 'start_date' => '2026-10-01', 'finish_date' => '2027-04-01', 'colour' => '#60a5fa'];
}
foreach ([14, 42, 84] as $days) {
    $dates = []; $d = new DateTimeImmutable('2026-11-02');
    while (count($dates) < $days) {
        if ((int)$d->format('N') <= 5) $dates[] = $d->format('Y-m-d');
        $d = $d->modify('+1 day');
    }
    $pdf = new ShortTermReport('Programme layout regression', $dates, 'apartment');
    $pdf->render(['Construction Activities / First Floor Refurbishment / Mechanical and Electrical Installation' => $tasks]);
    if ($pdf->PageNo() < 2) throw new RuntimeException('Expected multiple pages');
    checkBounds($pdf);
    $pdf->Output('F', $output . '/window-' . $days . '.pdf');
}
$dates = ['2026-11-06', '2026-11-09', '2026-11-10'];
$edgeTasks = [
    ['name' => 'Weekend endpoint', 'operatives' => 1, 'start_date' => '2026-11-07', 'finish_date' => '2026-11-10'],
    ['name' => 'Same-day milestone', 'operatives' => 1, 'start_date' => '2026-11-09', 'finish_date' => '2026-11-09'],
    ['name' => 'No visible working day', 'operatives' => 1, 'start_date' => '2026-11-07', 'finish_date' => '2026-11-08'],
];
$pdf = new ShortTermReport('Edge dates', $dates, 'contractor');
$pdf->render(['A long contractor name that wraps safely inside the full section heading' => $edgeTasks]);
checkBounds($pdf);
$data = $pdf->Output('S');
// Only the weekend-to-Tuesday activity and same-day milestone have visible working dates.
if (substr_count($data, '0.376 0.647 0.980 rg') !== 2) throw new RuntimeException('Weekend endpoints or same-day bar were lost');
$pdf->Output('F', $output . '/edge-dates.pdf');
$pdf = new ShortTermReport('Empty window', $dates, 'apartment');
$pdf->render([]); $pdf->Output('F', $output . '/empty.pdf');
echo "PASS: short-term report generation, 14/42/84-day windows, long names, multi-page rows, sections, weekend dates, same-day activities and empty window\n";
