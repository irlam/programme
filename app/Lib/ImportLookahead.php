<?php
declare(strict_types=1);

namespace App\Lib;

use DateInterval;
use DateTimeImmutable;

class ImportLookahead
{
    /** Quick weekday count (Mon–Fri, inclusive) */
    public static function wd(string $a, string $b): int {
        $A = new DateTimeImmutable($a);
        $B = new DateTimeImmutable($b);
        if ($B < $A) { [$A,$B] = [$B,$A]; }
        $d=0; for ($cur=$A; $cur <= $B; $cur=$cur->add(new DateInterval('P1D'))) {
            $dow = (int)$cur->format('N'); if ($dow <= 5) $d++;
        }
        return max(1,$d);
    }

    /** Convert Excel ARGB -> #RRGGBB */
    private static function argbToHex(?string $argb): ?string {
        if (!$argb) return null;
        $h = strtoupper(ltrim($argb, '#'));
        // ARGB => strip alpha
        if (strlen($h) === 8) $h = substr($h, 2);
        if (strlen($h) !== 6) return null;
        if ($h === 'FFFFFF' || $h === '000000') return null; // ignore white/black
        return '#'.$h;
    }

    /** Is a cell visually "filled" (solid fill or text inside the grid)? */
    private static function isActiveCell($cell): bool {
        $f = $cell->getFill();
        if ($f && $f->getFillType() === 'solid') {
            $col = $f->getStartColor()?->getARGB() ?: $f->getFillType();
            $hex = self::argbToHex($cell->getFill()->getStartColor()?->getARGB());
            if ($hex) return true;
        }
        $v = $cell->getValue();
        if ($v !== null && trim((string)$v) !== '') return true;
        return false;
    }

    /** True if cell looks like a section header (bold or strong fill in the left band) */
    private static function isSectionHeader($cell): bool {
        $v = trim((string)$cell->getValue());
        if ($v === '') return false;
        $font = $cell->getStyle()->getFont();
        if ($font && ($font->getBold() || $font->getSize() >= 12)) return true;
        $fill = $cell->getFill();
        if ($fill && $fill->getFillType()==='solid') {
            $hex = self::argbToHex($fill->getStartColor()?->getARGB());
            if ($hex) return true;
        }
        // heuristic keywords
        if (preg_match('~\b(cluster|level|fit\s*out)\b~i', $v)) return true;
        return false;
    }

    /** Most frequent contractor-like token (3–6 uppercase letters) in a run of cells */
    private static function contractorFromRun(array $cells): ?string {
        $cnt = [];
        foreach ($cells as $cell) {
            $val = strtoupper(trim((string)$cell->getValue()));
            if ($val && preg_match_all('~\b[A-Z]{3,6}\b~', $val, $m)) {
                foreach ($m[0] as $tok) { $cnt[$tok] = ($cnt[$tok] ?? 0) + 1; }
            }
        }
        arsort($cnt);
        $top = array_key_first($cnt);
        return $top ?: null;
    }

    /** Dominant colour in a run */
    private static function colourFromRun(array $cells): ?string {
        $cnt = [];
        foreach ($cells as $cell) {
            $hex = self::argbToHex($cell->getFill()?->getStartColor()?->getARGB());
            if ($hex) $cnt[$hex] = ($cnt[$hex] ?? 0) + 1;
        }
        arsort($cnt);
        return array_key_first($cnt) ?: null;
    }

    /**
     * Parse a lookahead grid workbook (PhpSpreadsheet Worksheet).
     * Returns: [ 'ok'=>true, 'mode'=>'lookahead', 'base_date'=>..., 'tasks'=>[...], ... ]
     */
    public static function parseWorksheet(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $ws): array
    {
        // 1) find date band row (we inserted a hidden ISO date row)
        $maxRow = min($ws->getHighestDataRow(), 120);
        $maxCol = $ws->getHighestColumn();
        $maxColN = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($maxCol);

        $dateRow = null; $dateCols = [];
        for ($r=1; $r <= $maxRow; $r++) {
            $hits = [];
            for ($c=1; $c <= $maxColN; $c++) {
                $v = $ws->getCellByColumnAndRow($c,$r)->getValue();
                if ($v instanceof \DateTimeInterface) $hits[] = $c;
            }
            if (count($hits) >= 6) { $dateRow = $r; $dateCols = $hits; break; }
        }
        if (!$dateRow) return ['ok'=>false,'error'=>'Could not detect the date band (need a row of true dates).'];

        $dateMap = [];
        foreach ($dateCols as $c) {
            $v = $ws->getCellByColumnAndRow($c,$dateRow)->getValue();
            if ($v instanceof \DateTimeInterface) $dateMap[$c] = $v->format('Y-m-d');
        }
        ksort($dateMap);
        $firstDateCol = min(array_keys($dateMap));

        // 2) walk rows: maintain current SECTION from bold/heading rows on the left
        $tasks = [];
        $currentSection = '';
        $activityHeaderFound = false;

        // find “Activity” header column to help activity pick-up
        $activityCol = null;
        for ($c=1; $c < $firstDateCol; $c++) {
            $label = trim((string)$ws->getCellByColumnAndRow($c,1)->getValue());
            if (preg_match('~^activity~i', $label)) { $activityCol = $c; break; }
        }
        if (!$activityCol) $activityCol = 1;

        for ($r=$dateRow+1; $r <= $maxRow; $r++) {

            // left band text
            $leftVals = [];
            for ($c=1; $c < $firstDateCol; $c++) {
                $txt = trim((string)$ws->getCellByColumnAndRow($c,$r)->getValue());
                if ($txt !== '') $leftVals[$c] = $txt;
            }

            // section header?
            if (!empty($leftVals)) {
                $firstCell = $ws->getCellByColumnAndRow(array_key_first($leftVals), $r);
                if (self::isSectionHeader($firstCell)) {
                    // adopt as current section, but do not create a task
                    $currentSection = $firstCell->getValue();
                    continue;
                }
            }

            // activity name = the last non-empty left text (or the “Activity” column)
            $activity = '';
            if (!empty($leftVals)) {
                $activity = end($leftVals);
            } else {
                $activity = trim((string)$ws->getCellByColumnAndRow($activityCol,$r)->getValue());
            }
            if ($activity === '' ) continue; // ignore empty rows

            // scan runs across date columns
            $runStart = null; $prevCol = null; $activePrev = false; $cellsInRun = [];

            $close = function() use (&$runStart,&$prevCol,&$cellsInRun,&$tasks,$currentSection,$activity,$dateMap) {
                if ($runStart===null || $prevCol===null) { $cellsInRun=[]; return; }
                $s = $dateMap[$runStart] ?? null;
                $f = $dateMap[$prevCol]   ?? null;
                if (!$s || !$f) { $cellsInRun=[]; return; }

                $contractor = self::contractorFromRun($cellsInRun);
                $colour     = self::colourFromRun($cellsInRun);

                $tasks[] = [
                    'section'        => ($currentSection !== '' ? $currentSection : 'Imported Lookahead'),
                    'name'           => $activity,
                    'contractor'     => $contractor,
                    'colour'         => $colour,
                    'start'          => $s,
                    'finish'         => $f,
                    'duration_days'  => self::wd($s,$f),
                ];
                $cellsInRun=[];
            };

            foreach (array_keys($dateMap) as $c) {
                $cell = $ws->getCellByColumnAndRow($c,$r);
                $isActive = self::isActiveCell($cell);
                if ($isActive && !$activePrev) { $runStart = $c; $cellsInRun = [$cell]; }
                elseif ($isActive && $activePrev) { $cellsInRun[] = $cell; }
                elseif (!$isActive && $activePrev) { $close(); $runStart=null; }
                $activePrev = $isActive; $prevCol = $c;
            }
            if ($activePrev && $runStart!==null) $close();
        }

        return [
            'ok' => true,
            'mode' => 'lookahead',
            'base_date' => reset($dateMap) ?: null,
            'tasks' => $tasks,
            'activity_col' => $activityCol,
        ];
    }
}
