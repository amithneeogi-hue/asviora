<?php
/**
 * Spreadsheet reader — CSV, TSV and XLSX, with no external library.
 *
 * XLSX is a zip of XML files, so PHP can read one on its own: pull the
 * shared string table out of `xl/sharedStrings.xml`, walk the cells in
 * `xl/worksheets/sheetN.xml`, and put the values back in the right
 * columns using each cell's `r` reference (A1, B1, ...). That last part
 * matters — Excel leaves empty cells out of the file entirely, so a row
 * with a blank Option C would otherwise shift every later column left.
 *
 * Everything returns the same shape: an array of rows, each row an array
 * of trimmed strings.
 */

/** Column letters (A, B, ... AA) to a zero-based index. */
function sheet_col_index(string $ref): int
{
    if (!preg_match('/^([A-Z]+)/i', $ref, $m)) {
        return 0;
    }
    $letters = strtoupper($m[1]);
    $n = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return max(0, $n - 1);
}

/** Read a CSV / TSV file into rows. Handles a UTF-8 BOM and guesses the delimiter. */
function sheet_read_csv(string $path, ?string $delim = null): array
{
    $fh = fopen($path, 'r');
    if (!$fh) {
        return [];
    }

    // sniff the delimiter from the header line
    if ($delim === null) {
        $first = (string) fgets($fh);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $best  = ',';
        $bestN = 0;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($first, $d);
            if ($n > $bestN) {
                $bestN = $n;
                $best  = $d;
            }
        }
        $delim = $best;
        rewind($fh);
    }

    // The escape character is passed as '' on purpose: that is the
    // RFC-4180 behaviour (a backslash is just a character), and PHP 8.4
    // deprecates the old default.
    $rows  = [];
    $first = true;
    while (($cells = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
        if ($cells === [null] || $cells === false) {
            continue;
        }
        if ($first) {
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $cells[0]);
            $first    = false;
        }
        $rows[] = array_map(static fn($c) => trim((string) $c), $cells);
    }
    fclose($fh);

    return $rows;
}

/** The shared string table of an open xlsx. */
function sheet_shared_strings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false || $xml === '') {
        return [];
    }

    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($xml);
    libxml_use_internal_errors($prev);

    if (!$doc) {
        return [];
    }

    $out = [];
    foreach ($doc->si as $si) {
        // a string can be split into several runs (<r><t>..</t></r>)
        $text = '';
        if (isset($si->t)) {
            $text = (string) $si->t;
        }
        if (isset($si->r)) {
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
        }
        $out[] = $text;
    }
    return $out;
}

/** Read the first worksheet of an xlsx into rows. */
function sheet_read_xlsx(string $path): array
{
    if (!class_exists('ZipArchive')) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    $shared = sheet_shared_strings($zip);

    // find the first worksheet — usually sheet1.xml, but not always
    $sheetXml = false;
    foreach (['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet01.xml'] as $cand) {
        $sheetXml = $zip->getFromName($cand);
        if ($sheetXml !== false) {
            break;
        }
    }
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')) {
                $sheetXml = $zip->getFromName($name);
                break;
            }
        }
    }
    $zip->close();

    if ($sheetXml === false || $sheetXml === '') {
        return [];
    }

    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($sheetXml);
    libxml_use_internal_errors($prev);

    if (!$doc || !isset($doc->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($doc->sheetData->row as $row) {
        $cells = [];
        $max   = -1;

        foreach ($row->c as $c) {
            $ref  = (string) ($c['r'] ?? '');
            $type = (string) ($c['t'] ?? '');
            $idx  = $ref !== '' ? sheet_col_index($ref) : $max + 1;

            if ($type === 's') {                       // shared string
                $v     = (int) $c->v;
                $value = $shared[$v] ?? '';
            } elseif ($type === 'inlineStr') {          // inline string
                $value = '';
                if (isset($c->is->t)) {
                    $value = (string) $c->is->t;
                }
                if (isset($c->is->r)) {
                    foreach ($c->is->r as $run) {
                        $value .= (string) $run->t;
                    }
                }
            } elseif ($type === 'b') {                  // boolean
                $value = ((string) $c->v) === '1' ? 'TRUE' : 'FALSE';
            } else {                                     // number / date / formula result
                $value = isset($c->v) ? (string) $c->v : '';
            }

            $cells[$idx] = trim($value);
            $max = max($max, $idx);
        }

        if ($max < 0) {
            $rows[] = [];
            continue;
        }

        // fill the gaps Excel left out so column positions stay true
        $flat = [];
        for ($i = 0; $i <= $max; $i++) {
            $flat[] = $cells[$i] ?? '';
        }
        $rows[] = $flat;
    }

    return $rows;
}

/**
 * Read any supported spreadsheet.
 * Returns ['rows' => array, 'error' => string].
 */
function sheet_read(string $path, string $filename = ''): array
{
    if (!is_readable($path)) {
        return ['rows' => [], 'error' => 'The uploaded file could not be read.'];
    }

    $ext = strtolower(pathinfo($filename !== '' ? $filename : $path, PATHINFO_EXTENSION));

    switch ($ext) {
        case 'xlsx':
        case 'xlsm':
            if (!class_exists('ZipArchive')) {
                return ['rows' => [],
                        'error' => 'PHP\'s zip extension is switched off, so .xlsx cannot be read. '
                                 . 'Save the sheet as CSV and upload that instead, or enable '
                                 . 'extension=zip in php.ini.'];
            }
            $rows = sheet_read_xlsx($path);
            return $rows
                ? ['rows' => $rows, 'error' => '']
                : ['rows' => [], 'error' => 'That .xlsx could not be parsed. Try re-saving it, or upload a CSV.'];

        case 'xls':
            return ['rows' => [],
                    'error' => 'The old .xls format is not supported. Save as .xlsx or .csv and upload again.'];

        case 'csv':
        case 'txt':
            return ['rows' => sheet_read_csv($path), 'error' => ''];

        case 'tsv':
            return ['rows' => sheet_read_csv($path, "\t"), 'error' => ''];

        default:
            // no extension we know — try CSV, it costs nothing
            $rows = sheet_read_csv($path);
            return $rows
                ? ['rows' => $rows, 'error' => '']
                : ['rows' => [], 'error' => 'Unsupported file type. Upload .xlsx, .csv or .tsv.'];
    }
}

/**
 * Match the header row to the fields we want, so the columns can be in any
 * order and the labels can vary ("Correct Answer", "Answer", "correct").
 *
 * $aliases: field => list of accepted header labels (lowercased, no spaces).
 * Returns field => column index for the ones it found.
 */
function sheet_map_headers(array $header, array $aliases): array
{
    $norm = [];
    foreach ($header as $i => $h) {
        $key = preg_replace('/[^a-z0-9]/', '', strtolower((string) $h));
        if ($key !== '') {
            $norm[$key] = $i;
        }
    }

    $map = [];
    foreach ($aliases as $field => $names) {
        foreach ($names as $n) {
            $k = preg_replace('/[^a-z0-9]/', '', strtolower($n));
            if (isset($norm[$k])) {
                $map[$field] = $norm[$k];
                break;
            }
        }
    }
    return $map;
}
