<?php
/**
 * Throwaway diagnostic — delete this file afterwards.
 * Asks the server to fetch its own spin.php and saves the rendered HTML
 * to __rendered.html so the exact markup can be inspected.
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$url = 'http://127.0.0.1' . ($_SERVER['SERVER_PORT'] != 80 ? ':' . $_SERVER['SERVER_PORT'] : '')
     . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/spin.php';

echo "Fetching : $url\n\n";

$html = false;
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $html = curl_exec($ch);
    if ($html === false) {
        echo "curl error: " . curl_error($ch) . "\n";
    }
    curl_close($ch);
}
if ($html === false || $html === '') {
    $html = @file_get_contents($url);
    if ($html === false) {
        echo "file_get_contents failed too (allow_url_fopen off?)\n";
    }
}

if (!$html) {
    echo "\nCould not fetch the page. Tell Claude this happened.\n";
    exit;
}

$out = __DIR__ . '/__rendered.html';
file_put_contents($out, $html);

echo "Saved    : $out\n";
echo "Length   : " . strlen($html) . " bytes\n\n";

foreach ([
    'spin-stage div'   => '<div class="spin-stage"',
    'data-segments'    => 'data-segments',
    'canvas tag'       => '<canvas',
    'wheel-holder'     => 'wheel-holder',
    'inline <style>'   => '.spin-stage canvas.wheel',
    'drawWheel()'      => 'function drawWheel',
    'PHP error text'   => 'Fatal error',
    'PHP warning text' => 'Warning:',
] as $label => $needle) {
    printf("%-17s : %s\n", $label, str_contains($html, $needle) ? 'present' : 'MISSING');
}

$p = strpos($html, '<div class="spin-stage"');
if ($p !== false) {
    echo "\n---- first 900 chars of the stage block ----\n";
    echo substr($html, $p, 900), "\n";
}
