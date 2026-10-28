<?php
/**
 * SPREADSHEET READER
 * ===================================================================
 * Reading .xlsx with nothing installed: an .xlsx file is a zip of XML
 * parts, so ZipArchive + SimpleXML is all it takes. .csv and .tsv are
 * read directly. Used by the bulk question import, and by anything else
 * that needs to swallow a sheet.
 */

// ===================================================================
// .xlsx reader
// ===================================================================

/** Column reference ("BC7") -> zero based column index. */
function xlsx_col(string $ref): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    foreach (str_split($letters) as $c) {
        $n = $n * 26 + (ord($c) - 64);
    }
    return max(0, $n - 1);
}

/**
 * Every sheet name in the workbook, in order, with its XML part.
 * @return array<int, array{name:string, path:string}>
 */
function xlsx_sheets(ZipArchive $zip): array
{
    $book = @simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
    $rels = @simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
    if (!$book) {
        return [];
    }

    $map = [];
    if ($rels) {
        foreach ($rels->Relationship as $r) {
            $map[(string) $r['Id']] = 'xl/' . ltrim((string) $r['Target'], '/');
        }
    }

    $out = [];
    $i   = 1;
    foreach ($book->sheets->sheet as $s) {
        $rid  = (string) $s->attributes('r', true)->id;
        $path = $map[$rid] ?? ('xl/worksheets/sheet' . $i . '.xml');
        $out[] = ['name' => (string) $s['name'], 'path' => $path];
        $i++;
    }
    return $out;
}

/**
 * Read one sheet into a plain array of rows.
 * @return array<int, array<int, string>>
 */
function xlsx_rows(string $file, int $sheetIndex = 0, int $maxRows = 20000): array
{
    if (!class_exists('ZipArchive')) {
        return [];
    }
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        return [];
    }

    // shared strings
    $shared = [];
    $ssXml  = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = @simplexml_load_string($ssXml);
        if ($ss) {
            foreach ($ss->si as $si) {
                $txt = '';
                if (isset($si->t)) {
                    $txt = (string) $si->t;
                } else {
                    foreach ($si->r as $r) {
                        $txt .= (string) $r->t;
                    }
                }
                $shared[] = $txt;
            }
        }
    }

    $sheets = xlsx_sheets($zip);
    $path   = $sheets[$sheetIndex]['path'] ?? 'xl/worksheets/sheet1.xml';
    $xml    = $zip->getFromName($path);
    $zip->close();

    if ($xml === false) {
        return [];
    }
    $sheet = @simplexml_load_string($xml);
    if (!$sheet) {
        return [];
    }

    $rows = [];
    $n    = 0;
    foreach ($sheet->sheetData->row as $row) {
        $line = [];
        foreach ($row->c as $c) {
            $idx  = xlsx_col((string) $c['r']);
            $type = (string) $c['t'];
            if ($type === 's') {
                $val = $shared[(int) $c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string) ($c->is->t ?? '');
            } else {
                $val = (string) ($c->v ?? '');
            }
            $line[$idx] = trim($val);
        }
        if ($line) {
            ksort($line);
            $rows[] = $line;
        }
        if (++$n >= $maxRows) {
            break;
        }
    }
    return $rows;
}

/** Read a csv / tsv into the same shape. */
function csv_rows(string $file, int $maxRows = 20000): array
{
    $rows = [];
    $fh   = fopen($file, 'r');
    if (!$fh) {
        return [];
    }
    $first = fgets($fh);
    rewind($fh);
    $sep = (substr_count((string) $first, "\t") > substr_count((string) $first, ',')) ? "\t" : ',';

    while (($r = fgetcsv($fh, 0, $sep)) !== false) {
        if (count($r) === 1 && trim((string) $r[0]) === '') {
            continue;
        }
        $rows[] = array_map(static fn($v) => trim((string) $v), $r);
        if (count($rows) >= $maxRows) {
            break;
        }
    }
    fclose($fh);

    // strip a UTF-8 BOM off the first cell
    if ($rows && isset($rows[0][0])) {
        $rows[0][0] = preg_replace('/^\xEF\xBB\xBF/', '', $rows[0][0]);
    }
    return $rows;
}

// ===================================================================
// Header matching
// ===================================================================

/**
 * Work out which column is which from the header row.
 * @return array<string,int>
 */
function map_headers(array $head): array
{
    $want = [
        'qcode'    => ['question id', 'questionid', 'qid', 'code', 'id'],
        'category' => ['category', 'topic', 'subject'],
        'level'    => ['difficulty', 'level'],
        'question' => ['question', 'question text'],
        'a'        => ['option a', 'optiona', 'a', 'opt a'],
        'b'        => ['option b', 'optionb', 'b', 'opt b'],
        'c'        => ['option c', 'optionc', 'c', 'opt c'],
        'd'        => ['option d', 'optiond', 'd', 'opt d'],
        'correct'  => ['correct answer', 'correct', 'answer', 'ans'],
        'explain'  => ['explanation', 'reason', 'note'],
        'time'     => ['time limit (sec)', 'time limit', 'time', 'seconds'],
        'marks'    => ['marks', 'mark', 'points', 'score'],
    ];

    $map = [];
    foreach ($head as $i => $label) {
        $l = strtolower(trim(preg_replace('/\s+/', ' ', (string) $label)));
        if ($l === '') {
            continue;
        }
        foreach ($want as $key => $names) {
            if (isset($map[$key])) {
                continue;
            }
            if (in_array($l, $names, true)) {
                $map[$key] = (int) $i;
                break;
            }
        }
    }
    return $map;
}

/** easy / mid / hard from whatever the sheet says. */
function norm_level(string $v, string $fallback = 'easy'): string
{
    $v = strtolower(trim($v));
    if ($v === '') {
        return $fallback;
    }
    if (str_starts_with($v, 'e')) return 'easy';
    if (str_starts_with($v, 'h')) return 'hard';
    if (str_starts_with($v, 'm')) return 'mid';   // medium / mid
    if ($v === '1') return 'easy';
    if ($v === '2') return 'mid';
    if ($v === '3') return 'hard';
    return $fallback;
}

/** 1..4 from "B", "2", or the answer text itself. */
function norm_correct(string $raw, array $options): int
{
    $v = trim($raw);
    if ($v === '') {
        return 0;
    }
    if (preg_match('/^[a-dA-D]$/', $v)) {
        return ord(strtoupper($v)) - 64;
    }
    if (preg_match('/^[1-4]$/', $v)) {
        return (int) $v;
    }
    // option text — compare loosely
    $needle = strtolower(preg_replace('/\s+/', ' ', $v));
    foreach ($options as $i => $opt) {
        if (strtolower(preg_replace('/\s+/', ' ', (string) $opt)) === $needle) {
            return $i + 1;
        }
    }

    // Still nothing: a sheet often writes a longer form of the option
    // ("Jawaharlal Nehru" against the option "Nehru"). Accept that only when
    // exactly one option matches, so an ambiguous row is still reported.
    $hits = [];
    foreach ($options as $i => $opt) {
        $o = strtolower(trim(preg_replace('/\s+/', ' ', (string) $opt)));
        if ($o === '' || mb_strlen($o) < 3) {
            continue;
        }
        if (str_contains($needle, $o) || str_contains($o, $needle)) {
            $hits[] = $i + 1;
        }
    }
    return count($hits) === 1 ? $hits[0] : 0;
}
