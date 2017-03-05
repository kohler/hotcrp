<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");

$arg = getopt("hn:c:Vm:", ["help", "name:", "count:", "verbose", "match:"]);
if (isset($arg["c"]) && !isset($arg["count"]))
    $arg["count"] = $arg["c"];
if (isset($arg["V"]) && !isset($arg["verbose"]))
    $arg["verbose"] = $arg["V"];
if (isset($arg["m"]) && !isset($arg["match"]))
    $arg["match"] = $arg["m"];
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/s3check.php [-c COUNT] [-V] [-m MATCH]\n");
    exit(0);
}
$count = isset($arg["count"]) ? intval($arg["count"]) : null;
$verbose = isset($arg["verbose"]);
$match = isset($arg["match"]) ? $arg["match"] : "";

if (!$Conf->setting_data("s3_bucket")) {
    fwrite(STDERR, "* S3 is not configured for this conference\n");
    exit(1);
}

$match_algos = ["", "sha2-"];
$match_re = $match_pfx = "";
if ($match != "") {
    $match = strtolower($match);
    if (!preg_match('{\A(?:sha[123]-?)?(?:[0-9a-f*]|\[\^?[-0-9a-f]+\])*\z}', $match)) {
        fwrite(STDERR, "* bad `--match`, expected `[sha[123]-][0-9a-f*]*`\n");
        exit(1);
    }
    $match_algo = null;
    if (preg_match('{\Asha([123])-?(.*)\z}', $match, $m)) {
        if ($m[1] === "1")
            $match_algos = [""];
        else
            $match_algos = ["sha" . $m[1] . "-"];
        $match = $m[2];
    }
    if (preg_match('{\A([0-9a-f]+)}', $match, $m))
        $match_pfx = substr($m[1], 0, 2);
    if ($match != "")
        $match_re = '{/(?:sha[123]-)?' . str_replace("*", "[0-9a-f]*", $match) . '[^/]*\z}';
}
$algo_key_re_map = [
    "" => '{/([0-9a-f]{40})(?:\.[^/]*|)\z}',
    "sha2-" => '{/(sha2-[0-9a-f]{64})(?:\.[^/]*|)\z}'
];

$s3doc = $Conf->s3_docstore();

$algo_pos = -1;
$algo_pfx = $last_key = $continuation_token = null;
$args = ["max-keys" => 100];
$doc = new DocumentInfo;
$xml = null;
$xmlpos = 0;
while (true) {
    if ($count !== null) {
        if ($count === 0)
            break;
        --$count;
    }

    if ($xml === null || $xmlpos >= count($xml->Contents)) {
        // depends on all non-empty algo_pfx being >`f`
        $next_algo = false;
        if ($last_key === null
            || ($match_pfx != "" && strcmp($last_key, $algo_pfx . $match_pfx) > 0)
            || ($algo_pfx == "" && strcmp($last_key, "f") > 0)
            || $continuation_token === false)
            $next_algo = true;
        if ($next_algo) {
            ++$algo_pos;
            if ($algo_pos >= count($match_algos))
                break;
            $algo_pfx = $match_algos[$algo_pos];
            $key_re = $algo_key_re_map[$algo_pfx];
            $continuation_token = null;
        }

        if ($continuation_token !== null)
            $content = $s3doc->ls("doc/", ["max-keys" => 500, "continuation-token" => $continuation_token]);
        else
            $content = $s3doc->ls("doc/" . $match_pfx, ["max-keys" => 500]);

        $xml = new SimpleXMLElement($content);
        $xmlpos = 0;
        if (!isset($xml->Contents) || $xmlpos >= count($xml->Contents))
            break;
        $continuation_token = false;
        if (isset($xml->IsTruncated) && (string) $xml->IsTruncated === "true")
            $continuation_token = (string) $xml->NextContinuationToken;
    }

    $node = $xml->Contents[$xmlpos];
    ++$xmlpos;

    $last_key = (string) $node->Key;
    if ((!$match_re || preg_match($match_re, $last_key))
        && preg_match($key_re, $last_key, $m)
        && ($khash = Filer::hash_as_binary($m[1])) !== false) {
        if ($verbose)
            fwrite(STDOUT, "$last_key: ");
        $content = $s3doc->load($last_key);
        $doc->set_content($content);
        $chash = $doc->content_binary_hash($khash);
        if ($chash !== $khash) {
            if (!$verbose)
                fwrite(STDOUT, "$last_key: ");
            fwrite(STDOUT, "bad checksum " . Filer::hash_as_text($chash) . " (" . Filer::hash_as_text($khash) . ")\n");
        } else if ($verbose)
            fwrite(STDOUT, "ok\n");
    }
}
