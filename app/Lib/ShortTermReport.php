<?php
declare(strict_types=1);
namespace App\Lib;

/** A paginated, print-safe view of the selected working-day window. */
final class ShortTermReport extends \FPDF
{
    private array $dates;
    private string $project;
    private string $group;
    private array $widths = [100.0, 12.0, 22.0, 22.0, 117.0];
    private float $bottom = 194.0;
    private float $lineHeight = 4.2;

    public function __construct(string $project, array $dates, string $group)
    {
        parent::__construct('L', 'mm', 'A4');
        $this->project = self::encode($project);
        $this->dates = array_values($dates);
        $this->group = $group === 'contractor' ? 'Contractor' : 'Section / location';
        $this->SetMargins(12, 12, 12);
        $this->SetAutoPageBreak(false);
        $this->AliasNbPages();
        $this->SetTitle('Short-term Programme');
    }

    public static function encode(string $value): string
    {
        $value = str_replace(["\u{2013}", "\u{2014}", "\u{2192}"], ['-', '-', '->'], $value);
        $out = @iconv('UTF-8', 'windows-1252//TRANSLIT', $value);
        return $out !== false ? $out : preg_replace('/[^\x20-\x7E]/', '?', $value);
    }

    private static function uk(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('d/m/Y');
    }

    /** Word-wrap at the actual font width, including words longer than a column. */
    private function lines(string $text, float $width): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') return [''];
        $lines = []; $line = '';
        foreach (explode(' ', $text) as $word) {
            if ($line !== '' && $this->GetStringWidth($line . ' ' . $word) > $width) {
                $lines[] = $line; $line = '';
            }
            while ($this->GetStringWidth($word) > $width) {
                $take = 1;
                while ($take < strlen($word) && $this->GetStringWidth(substr($word, 0, $take + 1)) <= $width) $take++;
                if ($line !== '') { $lines[] = $line; $line = ''; }
                $lines[] = substr($word, 0, $take);
                $word = substr($word, $take);
            }
            $line = $line === '' ? $word : $line . ' ' . $word;
        }
        if ($line !== '') $lines[] = $line;
        return $lines ?: [''];
    }

    public function Header()
    {
        $this->SetTextColor(17, 24, 39);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(0, 7, 'Short-term Programme', 0, 1);
        $this->SetFont('Arial', '', 9);
        foreach ($this->lines($this->project, 269) as $line) $this->Cell(0, 4.5, $line, 0, 1);
        $this->Cell(0, 5, 'Window: ' . self::uk($this->dates[0]) . ' - ' . self::uk(end($this->dates)) . '  |  ' . count($this->dates) . ' working days', 0, 1);
        $this->SetFont('Arial', '', 7.5);
        $this->Cell(0, 4.5, 'Edge triangles: activity continues outside this window. Timeline uses working days.', 0, 1);
        $this->Ln(2);
        $this->SetFont('Arial', 'B', 9);
        $this->SetFillColor(17, 24, 39); $this->SetTextColor(255, 255, 255);
        $this->SetDrawColor(31, 41, 55);
        foreach (['Task', 'Ops', 'Start', 'Finish', 'Timeline'] as $i => $label) $this->Cell($this->widths[$i], 7, $label, 1, 0, 'L', true);
        $this->Ln();
        $x = 12 + array_sum(array_slice($this->widths, 0, 4));
        $y = $this->GetY(); $w = $this->widths[4];
        $this->SetFont('Arial', '', 7); $this->SetTextColor(51, 65, 85);
        $this->SetFillColor(241, 245, 249); $this->Rect($x, $y, $w, 8, 'F');
        $lastRight = $x - 1;
        $cell = $w / count($this->dates);
        foreach ($this->dates as $i => $date) {
            if ($i !== 0 && substr($date, 0, 7) === substr($this->dates[$i - 1], 0, 7)
                && (new \DateTimeImmutable($date))->format('N') !== '1') continue;
            $label = (new \DateTimeImmutable($date))->format('d/m');
            $left = $x + $i * $cell + 0.5;
            $labelW = $this->GetStringWidth($label);
            if ($left < $lastRight + 2 || $left + $labelW > $x + $w - 0.5) continue;
            $this->Text($left, $y + 5, $label); $lastRight = $left + $labelW;
        }
        $this->SetY($y + 8);
    }

    public function Footer()
    {
        $this->SetY(-11); $this->SetFont('Arial', '', 8);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(0, 5, 'Programme  |  Page ' . $this->PageNo() . ' / {nb}', 0, 0, 'R');
    }

    private function section(string $name, bool $continued = false): void
    {
        $this->SetFont('Arial', 'B', 9);
        $lines = $this->lines(self::encode($this->group . ': ' . $name) . ($continued ? ' (continued)' : ''), 269);
        foreach ($lines as $line) {
            if ($this->GetY() + 6 > $this->bottom) $this->AddPage();
            $this->SetFont('Arial', 'B', 9); $this->SetFillColor(226, 232, 240);
            $this->SetTextColor(30, 64, 95); $this->SetDrawColor(203, 213, 225);
            $this->Cell(273, 6, $line, 1, 1, 'L', true);
        }
    }

    private function timeline(array $task, float $x, float $y, float $h): void
    {
        $w = $this->widths[4]; $cell = $w / count($this->dates);
        $this->SetFillColor(248, 250, 252); $this->Rect($x, $y, $w, $h, 'F');
        $this->SetDrawColor(226, 232, 240);
        foreach ($this->dates as $i => $date) {
            if ($cell >= 2.8 || $i === 0 || (new \DateTimeImmutable($date))->format('N') === '1') {
                $xx = $x + $i * $cell; $this->Line($xx, $y, $xx, $y + $h);
            }
        }
        // Select visible working dates rather than requiring endpoints to be working days.
        $visible = [];
        foreach ($this->dates as $i => $date) if ($date >= $task['start_date'] && $date <= $task['finish_date']) $visible[] = $i;
        if ($visible) {
            $left = $x + $visible[0] * $cell;
            $right = min($x + $w, $x + (end($visible) + 1) * $cell);
            $pad = min(0.3, ($right - $left) / 5);
            $colour = ltrim((string)($task['colour'] ?? ''), '#');
            if (preg_match('/^[0-9a-f]{3}$/i', $colour)) $colour = $colour[0].$colour[0].$colour[1].$colour[1].$colour[2].$colour[2];
            if (!preg_match('/^[0-9a-f]{6}$/i', $colour)) $colour = '60a5fa';
            $this->SetFillColor(hexdec(substr($colour, 0, 2)), hexdec(substr($colour, 2, 2)), hexdec(substr($colour, 4, 2)));
            $barH = min(4.2, $h - 2); $barY = $y + ($h - $barH) / 2;
            $this->Rect($left + $pad, $barY, $right - $left - 2 * $pad, $barH, 'F');
            $this->SetDrawColor(30, 64, 95);
            $mid = $y + $h / 2;
            if ($task['start_date'] < $this->dates[0]) {
                $this->Line($x + 2, $mid - 1.5, $x + 0.4, $mid);
                $this->Line($x + 0.4, $mid, $x + 2, $mid + 1.5);
            }
            if ($task['finish_date'] > end($this->dates)) {
                $this->Line($x + $w - 2, $mid - 1.5, $x + $w - 0.4, $mid);
                $this->Line($x + $w - 0.4, $mid, $x + $w - 2, $mid + 1.5);
            }
        }
        $this->SetDrawColor(203, 213, 225); $this->Rect($x, $y, $w, $h);
    }

    public function render(array $bucketed): void
    {
        $this->AddPage();
        if (!$bucketed) {
            $this->SetFont('Arial', '', 10); $this->SetTextColor(17, 24, 39);
            $this->Cell(0, 9, 'No activities in this date window.', 0, 1); return;
        }
        foreach ($bucketed as $name => $tasks) {
            $this->SetFont('Arial', 'B', 9);
            $sectionH = 6 * count($this->lines(self::encode($this->group . ': ' . $name), 269));
            if ($this->GetY() + min($sectionH, 60) + 12 > $this->bottom) $this->AddPage();
            $this->section((string)$name);
            foreach ($tasks as $task) {
                $this->SetFont('Arial', '', 9);
                $cells = [$this->lines(self::encode((string)$task['name']), $this->widths[0] - 4),
                    $this->lines((string)(int)$task['operatives'], $this->widths[1] - 4),
                    [self::uk($task['start_date'])], [self::uk($task['finish_date'])]];
                $offset = 0; $count = max(array_map('count', $cells));
                do {
                    $capacity = (int)floor(($this->bottom - $this->GetY() - 3) / $this->lineHeight);
                    if ($capacity < 1 || ($offset === 0 && $count <= 20 && $capacity < $count)) {
                        $this->AddPage(); $this->section((string)$name, true);
                        $capacity = (int)floor(($this->bottom - $this->GetY() - 3) / $this->lineHeight);
                    }
                    $take = min($count - $offset, max(1, $capacity));
                    $h = max(7.2, $take * $this->lineHeight + 3);
                    $x = 12.0; $y = $this->GetY();
                    $this->SetFont('Arial', '', 9); $this->SetTextColor(17, 24, 39);
                    $this->SetDrawColor(203, 213, 225);
                    foreach ($cells as $i => $lines) {
                        $this->Rect($x, $y, $this->widths[$i], $h);
                        // Repeat dates/operatives when an unusually long task spans pages.
                        $part = $i === 0 ? array_slice($lines, $offset, $take) : array_slice($lines, 0, $take);
                        foreach ($part as $j => $line) {
                            $textX = $i === 0 ? $x + 2 : $x + ($this->widths[$i] - $this->GetStringWidth($line)) / 2;
                            $this->Text($textX, $y + 4.3 + $j * $this->lineHeight, $line);
                        }
                        $x += $this->widths[$i];
                    }
                    $this->timeline($task, $x, $y, $h);
                    $this->SetXY(12, $y + $h); $offset += $take;
                } while ($offset < $count);
            }
        }
    }
}
