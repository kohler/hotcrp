<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");

$arg = getopt("hn:c:V", array("help", "name:", "count:", "verbose"));
if (isset($arg["c"]) && !isset($arg["count"]))
    $arg["count"] = $arg["c"];
if (isset($arg["V"]) && !isset($arg["verbose"]))
    $arg["verbose"] = $arg["V"];
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/cleandocstore.php [-c COUNT]\n");
    exit(0);
} else if (isset($arg["count"]) && !ctype_digit($arg["count"])) {
    fwrite(STDERR, "Usage: php batch/cleandocstore.php [-c COUNT]\n");
    exit(1);
}

if (!($dp = $Conf->docstore())) {
   fwrite(STDERR, "php batch/cleandocstore.php: Conference doesn't use docstore\n");
   exit(1);
}
$count = isset($arg["count"]) ? intval($arg["count"]) : 10;
$verbose = isset($arg["verbose"]);

assert($dp[1][0] === "/");
$fparts = [];
foreach (preg_split("{/+}", $dp[1]) as $fdir)
    if ($fdir !== "") {
        if (preg_match('/%\d*[%hx]/', $fdir)) {
            if (count($fparts) % 2 == 0)
                $fparts[] = "";
            $fparts[] = "/$fdir";
        } else if (count($fparts) % 2 == 0)
            $fparts[] = "/$fdir";
        else
            $fparts[count($fparts) - 1] .= "/$fdir";
    }


$dirinfo = [];

function populate_dirinfo($bdir, $entrypat) {
    global $dirinfo;
    $matcher = preg_replace('/%\d*[hx]/', '.*', preg_quote($entrypat));
    $matcher = "{" . str_replace('%%', '%', $matcher) . "}";
    foreach (scandir($bdir, SCANDIR_SORT_NONE) as $x)
        if ($x !== "." && $x !== ".." && preg_match($matcher, "/$x"))
            $dirinfo[$bdir][] = "/$x";
}

function try_part_match($try, $match, &$hash, &$extension) {
    $xhash = $hash;
    $xext = $extension;
    $build = "";
    while (preg_match('{\A(.*?)%(\d*)([%hx])(.*)\z}', $match, $m)) {
        if ($m[1] !== "") {
            if (substr($try, 0, strlen($m[1])) !== $m[1])
                return false;
            $build .= $m[1];
            $try = substr($try, strlen($m[1]));
        }

        $match = $m[4];
        if ($m[3] === "%") {
            if (substr($try, 0, 1) !== "%")
                return false;
            $build .= "%";
            $try = substr($try, 1);
        } else if ($m[3] === "x") {
            if ($xext !== null) {
                if (substr($try, 0, strlen($xext)) != $xext)
                    return false;
                $build .= $xext;
                $try = substr($try, strlen($xext));
            } else if (preg_match('{\A(\.(?:txt|pdf|ps|ppt|pptx|mp4|avi|json|jpg|png))(.*)}', $try, $m)) {
                $xext = $m[1];
                $build .= $m[1];
                $try = $m[2];
            } else
                $xext = "";
        } else {
            $n = $m[2] ? : 40;
            if (strlen($try) < $n
                || substr($try, 0, min(strlen($xhash), $n)) !== (string) substr($xhash, 0, $n)
                || !preg_match('{\A[0-9a-f]+\z}', substr($try, 0, $n)))
                return false;
            if ($n > strlen($xhash))
                $xhash = substr($try, 0, $n);
            $build .= substr($try, 0, $n);
            $try = substr($try, $n);
        }
    }
    if ((string) $try !== $match) {
        error_log("fail $build " . json_encode([$try, $match]));
        return false;
    }
    $hash = $xhash;
    $extension = $xext;
    return $build . $try;
}

function try_random_match($fparts) {
    global $dirinfo;
    $hash = "";
    $extension = null;
    $bdir = "";
    for ($i = 0; $i < count($fparts); ++$i)
        if ($i % 2 == 0)
            $bdir .= $fparts[$i];
        else {
            if (!isset($dirinfo[$bdir]))
                populate_dirinfo($bdir, $fparts[$i]);
            $di = $dirinfo[$bdir];
            if (empty($di))
                return false;
            $ndi = count($di);
            $start = mt_rand(0, $ndi - 1);
            $build = false;
            for ($tries = 0; $tries < $ndi; $start = ($start + 1) % $ndi, ++$tries)
                if (($build = try_part_match($di[$start], $fparts[$i], $hash, $extension)))
                    break;
            // remove last part from list
            if ($i == count($fparts) - 1)
                array_splice($dirinfo[$bdir], $start, 1);
            $bdir .= $build;
        }
    if (strlen($hash) != 40)
        return false;
    return [$hash, $extension, $bdir];
}


$hotcrpdoc = new HotCRPDocument($Conf, DTYPE_SUBMISSION);
$ndone = $nsuccess = 0;

while ($count > 0) {
    $x = null;
    for ($i = 0; $i < 10000 && !$x; ++$i)
        $x = try_random_match($fparts);
    if (!$x) {
        fwrite(STDERR, "Can't find anything to delete.\n");
        break;
    }
    $doc = new DocumentInfo(["sha1" => $x[0],
                             "mimetype" => Mimetype::type($x[1])]);
    $content = @file_get_contents($x[2]);
    $ok = false;
    if ($content === false)
        fwrite(STDERR, "$x[2]: is unreadable\n");
    else if (sha1($content) !== $x[0])
        fwrite(STDERR, "$x[2]: incorrect SHA-1 sum\n");
    else if ($hotcrpdoc->s3_check($doc)) {
        if (unlink($x[2])) {
            if ($verbose)
                fwrite(STDOUT, "$x[2]: removed\n");
            $ok = true;
        } else
            fwrite(STDERR, "$x[2]: cannot remove\n");
    } else
        fwrite(STDERR, "$x[2]: not on S3\n");
    --$count;
    ++$ndone;
    $nsuccess += $ok ? 1 : 0;
}

exit($nsuccess && $nsuccess == $ndone ? 0 : 1);
