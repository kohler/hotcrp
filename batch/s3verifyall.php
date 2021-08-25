<?php
require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = getopt("hn:c:Vm:", ["help", "name:", "count:", "verbose", "match:"]);
if (isset($arg["c"]) && !isset($arg["count"])) {
    $arg["count"] = $arg["c"];
}
if (isset($arg["V"]) && !isset($arg["verbose"])) {
    $arg["verbose"] = $arg["V"];
}
if (isset($arg["m"]) && !isset($arg["match"])) {
    $arg["match"] = $arg["m"];
}
if (isset($arg["h"]) || isset($arg["help"])) {
    fwrite(STDOUT, "Usage: php batch/s3verifyall.php [-c COUNT] [-V] [-m MATCH]\n");
    exit(0);
}

require_once(SiteLoader::find("src/init.php"));

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
    $docmatch = new DocumentHashMatcher($match);
    if (preg_match('{\A(?:|sha\d-)\z}', $docmatch->algo_pfx_preg))
        $match_algos = [$docmatch->algo_pfx_preg];
    if ($docmatch->fixed_hash)
        $match_pfx = substr($docmatch->fixed_hash, 0, 2);
    if ($docmatch->has_hash_preg)
        $match_re = '{/' . $docmatch->algo_pfx_preg . $docmatch->hash_preg . '[^/]*\z}';
}
$algo_key_re_map = [
    "" => '{/([0-9a-f]{40})(?:\.[^/]*|)\z}',
    "sha2-" => '{/(sha2-[0-9a-f]{64})(?:\.[^/]*|)\z}'
];

$s3doc = $Conf->s3_docstore();

$algo_pos = -1;
$algo_pfx = $last_key = $continuation_token = null;
$args = ["max-keys" => 100];
$doc = new DocumentInfo([], $Conf);
$xml = null;
$xmlpos = 0;
$key_re = '/.*/';
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
            || $continuation_token === false) {
            $next_algo = true;
        }
        if ($next_algo) {
            ++$algo_pos;
            if ($algo_pos >= count($match_algos)) {
                break;
            }
            $algo_pfx = $match_algos[$algo_pos];
            $key_re = $algo_key_re_map[$algo_pfx];
            $continuation_token = null;
        }

        if ($continuation_token !== null) {
            $content = $s3doc->ls("doc/", ["max-keys" => 500, "continuation-token" => $continuation_token]);
        } else {
            $content = $s3doc->ls("doc/" . $match_pfx, ["max-keys" => 500]);
        }

        $xml = new SimpleXMLElement($content);
        $xmlpos = 0;
        if (!isset($xml->Contents) || $xmlpos >= count($xml->Contents)) {
            break;
        }
        $continuation_token = false;
        if (isset($xml->IsTruncated) && (string) $xml->IsTruncated === "true") {
            $continuation_token = (string) $xml->NextContinuationToken;
        }
    }

    $node = $xml->Contents[$xmlpos];
    ++$xmlpos;

    $last_key = (string) $node->Key;
    if ((!$match_re || preg_match($match_re, $last_key))
        && preg_match($key_re, $last_key, $m)
        && ($khash = Filer::hash_as_binary($m[1])) !== false) {
        if ($verbose) {
            fwrite(STDOUT, "$last_key: ");
        }
        $content = $s3doc->get($last_key);
        $doc->set_content($content);
        $chash = $doc->content_binary_hash($khash);
        if ($chash !== $khash) {
            if (!$verbose) {
                fwrite(STDOUT, "$last_key: ");
            }
            fwrite(STDOUT, "bad checksum " . Filer::hash_as_text($chash) . " (" . Filer::hash_as_text($khash) . ")\n");
        } else if ($verbose) {
            fwrite(STDOUT, "ok\n");
        }
    }
}
