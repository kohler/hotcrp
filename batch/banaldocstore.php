<?php
// banaldocstore.php -- HotCRP maintenance script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

$arg = getopt("hn:c:Vm:dqo:", ["help", "name:", "count:", "verbose", "match:", "max-usage:", "quiet", "silent", "output"]);
foreach (["c" => "count", "V" => "verbose", "m" => "match",
          "o" => "output", "q" => "quiet"] as $s => $l) {
    if (isset($arg[$s]) && !isset($arg[$l]))
        $arg[$l] = $arg[$s];
}
if (isset($arg["silent"])) {
    $arg["quiet"] = false;
}
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/cleandocstore.php [-c COUNT] [-V] [-m MATCH]\n"
                 . "           [-d|--dry-run] [-o OUTPUTDIR]\n");
    exit(0);
}
if (isset($arg["count"]) && !ctype_digit($arg["count"])) {
    fwrite(STDERR, "batch/cleandocstore.php: `-c` expects integer\n");
    exit(1);
}

require_once(dirname(__DIR__) . "/src/init.php");

$dp = $Conf->docstore();
if (!$dp) {
    fwrite(STDERR, "batch/cleandocstore.php: Conference doesn't use docstore\n");
    exit(1);
}
preg_match('{\A((?:/[^/%]*(?=/|\z))+)}', $dp, $m);
$usage_directory = $m[1];

$count = isset($arg["count"]) ? intval($arg["count"]) : 10;
$verbose = isset($arg["verbose"]);

$dmatcher = new DocumentHashMatcher($arg["match"] ?? null);
$dmatcher->set_extension(".pdf");
$fparts = new DocumentFileTree($dp, $dmatcher);
$cf = new CheckFormat($Conf);

while ($count > 0) {
    $fm = null;
    for ($i = 0; !$fm && $i < 10; ++$i) {
        $fm = $fparts->first_match();
        if (!$fm->is_complete()) {
            $fparts->hide($fm);
            $fm = null;
        }
    }
    if (!$fm) {
        fwrite(STDERR, "Can't find anything to banal.\n");
        break;
    }
    $fparts->hide($fm);

    $cf->clear();
    $bj = $cf->run_banal($fm->fname);
    if (is_object($bj)) {
        $a = ["filename" => $fm->fname] + (array) $bj;
        unset($a["at"]);
    } else {
        $a = ["filename" => $fm->fname, "error" => $cf->banal_stderr];
    }
    $c = json_encode($a, JSON_PRETTY_PRINT) . "\n";
    $c = preg_replace_callback('<\[([ ,\n\d]+)\]>', function ($m) {
        return "[" . simplify_whitespace($m[1]) . "]";
    }, $c);
    $c = preg_replace_callback('<([\{\[,])\n {12,}>', function ($m) {
        return $m[1] . ($m[1] === "," ? " " : "");
    }, $c);
    $c = preg_replace('<\n {8,}([\}\]])>', '$1', $c);
    fwrite(STDOUT, $c);
    --$count;
}
