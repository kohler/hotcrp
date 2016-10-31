<?php

$x = [];
foreach (json_decode(stream_get_contents(STDIN)) as $j)
    if (isset($j->aliases) && isset($j->emoji))
        foreach ($j->aliases as $a) {
            assert(!isset($x[$a]));
            $x[$a] = $j->emoji;
        }
ksort($x, SORT_STRING);
fwrite(STDOUT, "{\n");
$n = count($x);
foreach ($x as $a => $e) {
    --$n;
    fwrite(STDOUT, json_encode((string) $a) . ":" . json_encode($e, JSON_UNESCAPED_UNICODE) . ($n ? ",\n" : "\n"));
}
fwrite(STDOUT, "}\n");
