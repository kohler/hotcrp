<?php
// cleandocstore.php -- HotCRP maintenance script
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

$arg = getopt("hn:c:Vm:du:q", ["help", "name:", "count:", "verbose", "match:", "dry-run", "max-usage:", "quiet", "silent", "keep-temp"]);
foreach (["c" => "count", "V" => "verbose", "m" => "match", "d" => "dry-run",
          "u" => "max-usage", "q" => "quiet"] as $s => $l) {
    if (isset($arg[$s]) && !isset($arg[$l]))
        $arg[$l] = $arg[$s];
}
if (isset($arg["silent"])) {
    $arg["quiet"] = false;
}
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/cleandocstore.php [-c COUNT] [-V] [-m MATCH]\n"
                 . "           [-d|--dry-run] [-u USAGELIMIT] [--keep-temp]\n");
    exit(0);
}
if (isset($arg["count"]) && !ctype_digit($arg["count"])) {
    fwrite(STDERR, "batch/cleandocstore.php: `-c` expects integer\n");
    exit(1);
}

$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");

$dp = $Conf->docstore();
if (!$dp) {
   fwrite(STDERR, "batch/cleandocstore.php: Conference doesn't use docstore\n");
   exit(1);
}
preg_match('{\A((?:/[^/%]*(?=/|\z))+)}', $dp, $m);
$usage_directory = $m[1];

$count = isset($arg["count"]) ? intval($arg["count"]) : 10;
$verbose = isset($arg["verbose"]);
$dry_run = isset($arg["dry-run"]);
$keep_temp = isset($arg["keep-temp"]);
$usage_threshold = null;
$hash_matcher = new DocumentHashMatcher(get($arg, "match"));

if (isset($arg["max-usage"])) {
    if (!is_numeric($arg["max-usage"])
        || (float) $arg["max-usage"] < 0
        || (float) $arg["max-usage"] > 1) {
        fwrite(STDERR, "batch/cleandocstore.php: `-u` expects fraction between 0 and 1\n");
        exit(1);
    }
    $ts = disk_total_space($usage_directory);
    $fs = disk_free_space($usage_directory);
    if ($ts === false || $fs === false) {
        fwrite(STDERR, "$usage_directory: cannot evaluate free space\n");
        exit(1);
    }
    $want_fs = $ts * (1 - (float) $arg["max-usage"]);
    $usage_threshold = $want_fs - $fs;
    if (!isset($arg["count"])) {
        $count = 5000;
    }
}


function fparts_random_match($fparts) {
    $x = [];
    for ($i = 0; $x ? count($x) < 5 && $i < 10 : $i < 10000; ++$i) {
        $fm = $fparts->random_match();
        if ($fm->is_complete())
            $x[] = $fm;
        else
            $fparts->hide($fm);
    }
    if ($x) {
        usort($x, function ($a, $b) {
            if ($a->atime() !== false && $b->atime() !== false)
                return $a->atime() - $b->atime();
            else
                return $a->atime() ? -1 : 1;
        });
    } else {
        fwrite(STDERR, "Can't find anything to delete.\n");
    }
    return get($x, 0);
}


$ndone = $nsuccess = $bytesremoved = 0;
$tmp_fparts = new DocumentFileTree(Filer::docstore_fixed_prefix($dp) . "tmp/%h%x", $hash_matcher);

while ($count > 0
       && ($usage_threshold === null || $bytesremoved < $usage_threshold)
       && ($fm = fparts_random_match($tmp_fparts))
       && $fm->atime() < $Now - 86400
       && $fm->mtime() < $Now - 86400
       && !$keep_temp) {
    $tmp_fparts->hide($fm);

    $ok = false;
    $size = filesize($fm->fname);
    if ($dry_run) {
        if ($verbose)
            fwrite(STDOUT, "{$fm->fname}: would remove\n");
        $ok = true;
    } else if (unlink($fm->fname)) {
        if ($verbose)
            fwrite(STDOUT, "{$fm->fname}: removed\n");
        $ok = true;
    } else {
        fwrite(STDERR, "{$fm->fname}: cannot remove\n");
    }
    --$count;
    ++$ndone;
    if ($ok) {
        ++$nsuccess;
        $bytesremoved += $size;
    }
}


$fparts = new DocumentFileTree($dp, $hash_matcher);

while ($count > 0
       && ($usage_threshold === null || $bytesremoved < $usage_threshold)
       && ($fm = fparts_random_match($fparts))) {
    $fparts->hide($fm);

    $doc = new DocumentInfo([
        "sha1" => $fm->algohash,
        "mimetype" => Mimetype::type($fm->extension)
    ], $Conf);
    $hashalg = $doc->hash_algorithm();
    $ok = false;
    $size = 0;
    if ($hashalg === false) {
        fwrite(STDERR, "{$fm->fname}: unknown hash\n");
    } else if (($chash = hash_file($hashalg, $fm->fname, true)) === false) {
        fwrite(STDERR, "{$fm->fname}: is unreadable\n");
    } else if ($chash !== $doc->binary_hash_data()) {
        fwrite(STDERR, "{$fm->fname}: incorrect hash\n");
    } else if ($doc->check_s3()) {
        $size = filesize($fm->fname);
        if ($dry_run) {
            if ($verbose)
                fwrite(STDOUT, "{$fm->fname}: would remove\n");
            $ok = true;
        } else if (unlink($fm->fname)) {
            if ($verbose)
                fwrite(STDOUT, "{$fm->fname}: removed\n");
            $ok = true;
        } else {
            fwrite(STDERR, "{$fm->fname}: cannot remove\n");
        }
    } else {
        fwrite(STDERR, "{$fm->fname}: not on S3\n");
    }
    --$count;
    ++$ndone;
    if ($ok) {
        ++$nsuccess;
        $bytesremoved += $size;
    }
}

if ($verbose && $usage_threshold !== null && $bytesremoved >= $usage_threshold) {
    fwrite(STDOUT, $usage_directory . ": free space above threshold\n");
}
if (!isset($arg["quiet"])) {
    fwrite(STDOUT, $usage_directory . ": " . ($dry_run ? "would remove " : "removed ") . plural($nsuccess, "file") . ", " . plural($bytesremoved, "byte") . "\n");
}
exit($nsuccess && $nsuccess == $ndone ? 0 : 1);
