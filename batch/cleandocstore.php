<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");

class Cleaner {
    static public $algo_preg = "(?:sha1|sha256)";
    static public $algo_pfx_preg = "(?:|sha2-)";
    static public $fixed_hash = "";
    static public $hash_preg = "(?:[0-9a-f]{40}|[0-9a-f]{64})";
    static public $extension = null;
    static public $extension_preg = ".*";
    static public $dirinfo = [];

    static function set_match($match) {
        $dot = strpos($match, ".");
        if ($dot !== false) {
            self::$extension = substr($match, $dot);
            self::$extension_preg = preg_quote(self::$extension);
            $match = substr($match, 0, $dot);
        }

        $match = strtolower($match);
        if (!preg_match('{\A(?:sha[123]-?)?(?:[0-9a-f*]|\[\^?[-0-9a-f]+\])*\z}', $match)) {
            fwrite(STDERR, "* bad `--match`, expected `[sha[123]-][0-9a-f*]*`\n");
            exit(1);
        }
        $match_algo = "(?:|sha2-)";
        if (preg_match('{\Asha([12])-?(.*)\z}', $match, $m)) {
            if ($m[1] === "1") {
                self::$fixed_algo = "sha1";
                self::$fixed_algo_pfx = $match_algo = "";
            } else {
                self::$fixed_algo = "sha256";
                self::$fixed_algo_pfx = $match_algo = "sha2-";
            }
            $match = $m[2];
        }
        if (preg_match('{\A([0-9a-f]+)}', $match, $m))
            self::$fixed_hash = $m[1];
        if ($match != "")
            self::$hash_preg = str_replace("*", "[0-9a-f]*", $match) . "[0-9a-f]*";
    }
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
if (isset($arg["match"]))
    Cleaner::set_match($arg["match"]);

class Fparts {
    public $components = [];
    public $pregs = [];
    public $n;

    public $algo;
    public $hash;
    public $extension;

    function __construct($dp) {
        assert($dp[0] === "/");
        foreach (preg_split("{/+}", $dp) as $fdir)
            if ($fdir !== "") {
                if (preg_match('/%\d*[%hxHjaA]/', $fdir)) {
                    if (count($this->components) % 2 == 0)
                        $this->components[] = "";
                    $this->components[] = "/$fdir";
                } else if (count($this->components) % 2 == 0)
                    $this->components[] = "/$fdir";
                else
                    $this->components[count($this->components) - 1] .= "/$fdir";
            }

        foreach ($this->components as $fp)
            $this->pregs[] = self::make_preg($fp);

        $this->n = count($this->components);
    }
    static function make_preg($entrypat) {
        $preg = "";
        $entrypat = preg_quote($entrypat);
        while ($entrypat !== ""
               && preg_match('{\A(.*?)%(\d*)([%hxHjaA])(.*)\z}', $entrypat, $m)) {
            $preg .= $m[1];
            list($fwidth, $fn, $entrypat) = [$m[2], $m[3], $m[4]];
            if ($fn === "%")
                $preg .= "%";
            else if ($fn === "x")
                $preg .= Cleaner::$extension_preg;
            else if ($fn === "a")
                $preg .= Cleaner::$algo_preg;
            else if ($fn === "A")
                $preg .= Cleaner::$algo_pfx_preg;
            else if ($fn === "j") {
                $l = min(strlen(Cleaner::$fixed_hash), 3);
                $preg .= substr(Cleaner::$fixed_hash, 0, $l);
                for (; $l < 3; ++$l)
                    $preg .= "[0-9a-f]";
                $preg .= "?";
            } else {
                if ($fn === "h")
                    $preg .= Cleaner::$algo_pfx_preg;
                if ($fwidth === "")
                    $preg .= Cleaner::$hash_preg;
                else {
                    $fwidth = intval($fwidth);
                    $l = min(strlen(Cleaner::$fixed_hash), $fwidth);
                    $preg .= substr(Cleaner::$fixed_hash, 0, $l);
                    if ($l < $fwidth)
                        $preg .= "[0-9a-f]{" . ($fwidth - $l) . "}";
                }
            }
        }
        return "{" . $preg . $entrypat . "}";
    }

    function clear() {
        $this->algo = null;
        $this->hash = "";
        $this->extension = null;
    }
    function match_component($text, $i) {
        $match = $this->components[$i];
        $xalgo = $this->algo;
        $xhash = $this->hash;
        $xext = $this->extension;

        $build = "";
        while (preg_match('{\A(.*?)%(\d*)([%hxHjaA])(.*)\z}', $match, $m)) {
            if ($m[1] !== "") {
                if (substr($text, 0, strlen($m[1])) !== $m[1])
                    return false;
                $build .= $m[1];
                $text = substr($text, strlen($m[1]));
            }

            list($fwidth, $fn, $match) = [$m[2], $m[3], $m[4]];
            if ($fn === "%") {
                if (substr($text, 0, 1) !== "%")
                    return false;
                $build .= "%";
                $text = substr($text, 1);
            } else if ($fn === "x") {
                if ($xext !== null) {
                    if (substr($text, 0, strlen($xext)) != $xext)
                        return false;
                    $build .= $xext;
                    $text = substr($text, strlen($xext));
                } else if (preg_match('{\A(\.(?:avi|bin|bz2|csv|docx?|gif|gz|html|jpg|json|mp4|pdf|png|pptx?|ps|tar|tex|txt|xlsx?|zip))}', $text, $m)) {
                    $xext = $m[1];
                    $build .= $m[1];
                    $text = substr($text, strlen($m[1]));
                } else
                    $xext = "";
            } else if ($fn === "j") {
                $l = min(strlen($xhash), 2);
                if (substr($text, 0, $l) !== (string) substr($xhash, 0, $l))
                    return false;
                if (preg_match('{\A([0-9a-f]{2,3})}', $text, $mm)) {
                    if (strlen($mm[1]) > strlen($xhash))
                        $xhash = $mm[1];
                    if (strlen($mm[1]) == 2 && $xalgo === null)
                        $xalgo = "";
                    // XXX don't track that algo *cannot* be SHA-1
                    if (strlen($mm[1]) == 2 ? $xalgo !== "" : $xalgo === "")
                        return false;
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else
                    return false;
            } else if ($fn === "a") {
                if (preg_match('{\A(sha1|sha256)}', $text, $mm)) {
                    $malgo = $mm[1] === "sha1" ? "" : "sha2-";
                    if ($xalgo === null)
                        $xalgo = $malgo;
                    if ($xalgo !== $malgo)
                        return false;
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else
                    return false;
            } else {
                if ($fn === "A" || $fn === "h") {
                    if ($xalgo !== null) {
                        if ($xalgo !== (string) substr($text, 0, strlen($xalgo)))
                            return false;
                    } else if (preg_match('{\A((?:sha2-)?)}', $text, $mm))
                        $xalgo = $mm[1];
                    else
                        return false;
                    $build .= $xalgo;
                    $text = substr($text, strlen($xalgo));
                    if ($fn === "A")
                        continue;
                }
                if (substr($text, 0, strlen($xhash)) !== $xhash)
                    return false;
                if ($fwidth === "") {
                    if ($xalgo === "")
                        $fwidth = "40";
                    else if ($xalgo === "sha2-")
                        $fwidth = "64";
                    else
                        $fwidth = "40,64";
                }
                if (preg_match('{\A([0-9a-f]{' . $fwidth . '})}', $text, $mm)) {
                    if (strlen($mm[1]) > strlen($xhash))
                        $xhash = $mm[1];
                    $build .= $mm[1];
                    $text = substr($text, strlen($mm[1]));
                } else
                    return false;
            }
        }
        if ((string) $text !== $match) {
            error_log("fail $build, have `$text`, expected `$match`");
            return false;
        }
        $this->algo = $xalgo;
        $this->hash = $xhash;
        $this->extension = $xext;
        return $build . $text;
    }
    function match_complete() {
        return $this->algo !== null
            && strlen($this->hash) === ($this->algo === "" ? 40 : 64);
    }
}

$fparts = new Fparts($dp);


function populate_dirinfo($bdir, $preg) {
    $di = [];
    foreach (scandir($bdir, SCANDIR_SORT_NONE) as $x)
        if ($x !== "." && $x !== ".." && preg_match($preg, "/$x"))
            $di[] = "/$x";
    Cleaner::$dirinfo[$bdir] = $di;
    return $di;
}

function try_random_match(Fparts $fparts) {
    $fparts->clear();
    $bdir = "";
    for ($i = 0; $i < $fparts->n; ++$i)
        if ($i % 2 == 0)
            $bdir .= $fparts->components[$i];
        else {
            $di = get(Cleaner::$dirinfo, $bdir);
            if ($di === null)
                $di = populate_dirinfo($bdir, $fparts->pregs[$i]);
            if (empty($di))
                return false;
            $ndi = count($di);
            $start = mt_rand(0, $ndi - 1);
            $build = false;
            for ($tries = 0; $tries < $ndi; $start = ($start + 1) % $ndi, ++$tries) {
                if (($build = $fparts->match_component($di[$start], $i)))
                    break;
            }
            // remove last part from list
            if ($i == $fparts->n - 1)
                array_splice(Cleaner::$dirinfo[$bdir], $start, 1);
            $bdir .= $build;
        }
    if (!$fparts->match_complete())
        return false;
    return [$fparts->algo . $fparts->hash, $fparts->extension, $bdir];
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
    $hashalg = $doc->hash_algorithm();
    $ok = false;
    if ($hashalg === false)
        fwrite(STDERR, "$x[2]: unknown hash\n");
    else if (($chash = hash_file($hashalg, $x[2], true)) === false)
        fwrite(STDERR, "$x[2]: is unreadable\n");
    else if ($chash !== $doc->binary_hash_data())
        fwrite(STDERR, "$x[2]: incorrect hash\n");
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
