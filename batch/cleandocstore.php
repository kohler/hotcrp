<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");

class Cleaner {
    static public $hash_prefix = null;
    static public $hash_preg = null;
    static public $extension = null;
    static public $dirinfo = [];
}

$arg = getopt("hn:c:Vm:", array("help", "name:", "count:", "verbose", "match:"));
if (isset($arg["c"]) && !isset($arg["count"]))
    $arg["count"] = $arg["c"];
if (isset($arg["V"]) && !isset($arg["verbose"]))
    $arg["verbose"] = $arg["V"];
if (isset($arg["m"]) && !isset($arg["match"]))
    $arg["match"] = $arg["m"];
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/cleandocstore.php [-c COUNT] [-V] [-m MATCH]\n");
    exit(0);
} else if (isset($arg["count"]) && !ctype_digit($arg["count"])) {
    fwrite(STDERR, "Usage: php batch/cleandocstore.php [-c COUNT] [-V] [-m MATCH]\n");
    exit(1);
}

if (!($dp = $Conf->docstore())) {
   fwrite(STDERR, "php batch/cleandocstore.php: Conference doesn't use docstore\n");
   exit(1);
}
$count = isset($arg["count"]) ? intval($arg["count"]) : 10;
$verbose = isset($arg["verbose"]);
if (isset($arg["match"])) {
    $dot = strpos($arg["match"], ".");
    if ($dot !== false)
        Cleaner::$extension = substr($arg["match"], $dot);
    $t = substr($arg["match"], 0, $dot === false ? strlen($arg["match"]) : $dot);
    if (($star = strpos($t, "*")) === false)
        Cleaner::$hash_prefix = $t;
    else
        Cleaner::$hash_prefix = substr($t, 0, $star);
    Cleaner::$hash_preg = str_replace('\\*', '[0-9a-f]*', preg_quote($t));
}

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


function populate_dirinfo($bdir, $entrypat) {
    $preg = "";
    $entrypat = preg_quote($entrypat);
    while ($entrypat !== ""
           && preg_match('{\A(.*?)%(\d*[hx%])(.*)\z}', $entrypat, $m)) {
        $preg .= $m[1];
        if (str_ends_with($m[2], "x")) {
            if (Cleaner::$extension)
                $preg .= preg_quote(Cleaner::$extension);
            else
                $preg .= ".*";
        } else if (str_ends_with($m[2], "h")) {
            $prefix_len = intval($m[2]) ? : 40;
            if (!Cleaner::$hash_preg)
                $preg .= "[0-9a-f]{" . $prefix_len . "}";
            else if ($prefix_len == 40)
                $preg .= Cleaner::$hash_preg;
            else {
                $l = min(strlen(Cleaner::$hash_prefix), $prefix_len);
                $preg .= substr(Cleaner::$hash_prefix, 0, $l);
                if ($l < $prefix_len)
                    $preg .= "[0-9a-f]{" . ($prefix_len - $l) . "}";
            }
        } else
            $preg .= "%";
        $entrypat = $m[3];
    }
    $preg = "{" . $preg . $entrypat . "}";
    $di = [];
    foreach (scandir($bdir, SCANDIR_SORT_NONE) as $x)
        if ($x !== "." && $x !== ".." && preg_match($preg, "/$x"))
            $di[] = "/$x";
    Cleaner::$dirinfo[$bdir] = $di;
    return $di;
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
            } else if (preg_match('{\A(\.(?:avi|bin|bz2|csv|docx?|gif|gz|html|jpg|json|mp4|pdf|png|pptx?|ps|tar|tex|txt|xlsx?|zip))(.*)}', $try, $m)) {
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
        error_log("fail $build, have `$try`, expected `$match`");
        return false;
    }
    $hash = $xhash;
    $extension = $xext;
    return $build . $try;
}

function try_random_match($fparts) {
    $hash = "";
    $extension = null;
    $bdir = "";
    for ($i = 0; $i < count($fparts); ++$i)
        if ($i % 2 == 0)
            $bdir .= $fparts[$i];
        else {
            $di = get(Cleaner::$dirinfo, $bdir);
            if ($di === null)
                $di = populate_dirinfo($bdir, $fparts[$i]);
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
                array_splice(Cleaner::$dirinfo[$bdir], $start, 1);
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
