<?php
declare(strict_types=1);
require __DIR__ . '/../../BB/src/Sanitiser.php';
$s = new Battleboard\Sanitiser(Battleboard\Sanitiser::loadSchema(__DIR__ . '/../../BB/config/schema.json'));
$cases = json_decode(file_get_contents('cases.json'), true, 512);
$fail = 0; $out = [];
foreach ($cases as $i => $c) {
    try { $r = $s->sanitise($c); $r2 = $s->sanitise($r['data']); if ($r2['warnings']) { echo "not idempotent $i: ".json_encode($r2['warnings'])."\n"; $fail++; } $out[] = $r['data']; }
    catch (Throwable $e) { echo "case $i: ".get_class($e).": ".$e->getMessage()." line ".$e->getLine()."\n"; $fail++; }
}
file_put_contents('php_out.json', json_encode($out));
echo "php cases: ".count($cases)." failures: $fail\n";
