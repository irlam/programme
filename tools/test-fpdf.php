<?php
declare(strict_types=1);

// simple loader (same root as other admin pages)
$ROOT = dirname(__DIR__, 1);
$candidates = [
  $ROOT . '/app/Lib/fpdf/fpdf.php',
  $ROOT . '/app/Lib/FPDF.php',
  $ROOT . '/app/lib/fpdf/fpdf.php',
  $ROOT . '/app/lib/FPDF.php',
];
$found = null;
foreach ($candidates as $c){ if (is_file($c)) { $found = $c; break; } }

if (!$found) {
  header('Content-Type: text/plain; charset=utf-8');
  echo "FPDF not found. Tried:\n" . implode("\n", $candidates);
  exit;
}

require_once $found; // defines class FPDF

$pdf = new FPDF('P','mm','A4');
$pdf->AddPage();
$pdf->SetFont('Arial','B',16);
$pdf->Cell(0, 10, 'FPDF is working', 0, 1);
$pdf->SetFont('Arial','',11);
$pdf->Cell(0, 8, 'Path: ' . $found, 0, 1);
header('Content-Type: application/pdf');
$pdf->Output('I');
