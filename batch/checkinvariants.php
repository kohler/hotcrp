<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:q", array("help", "name:", "json-reviews", "fix-json-reviews"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 0) {
    fwrite(STDOUT, "Usage: php batch/checkinvariants.php [-n CONFID]\n");
    exit(0);
}

$Conf->check_invariants();

if ($Conf->sversion == 174 && (isset($arg["json-reviews"]) || isset($arg["fix-json-reviews"]))) {
    $result = $Conf->qe("select * from PaperReview");
    $q = $qv = [];
    while (($rrow = ReviewInfo::fetch($result, $Conf))) {
        $tfields = $rrow->tfields ? json_decode($rrow->tfields) : null;
        $need_fix = $unfixable = false;
        foreach (ReviewInfo::$text_field_map as $kin => $kout) {
            $oldv = (string) $rrow->$kin;
            $newv = $tfields ? get($tfields, $kout, "") : "";
            if ($oldv !== $newv) {
                error_log("{$Conf->dbname}: #{$rrow->paperId}/{$rrow->reviewId}: {$kin} ["
                    . simplify_whitespace(UnicodeHelper::utf8_abbreviate($oldv === "" ? "EMPTY" : $oldv, 20))
                    . "] != tf/{$kout} ["
                    . simplify_whitespace(UnicodeHelper::utf8_abbreviate($newv === "" ? "EMPTY" : $newv, 20))
                    . "]");
                $need_fix = true;
                if ($newv === "") {
                    $tfields = $tfields ? : (object) [];
                    $tfields->$kout = $oldv;
                } else
                    $unfixable = true;
            }
        }
        if ($need_fix && isset($arg["fix-json-reviews"])) {
            if (!$unfixable) {
                $q[] = "update PaperReview set tfields=? where paperId=? and reviewId=? and tfields?e";
                array_push($qv, json_encode_db($tfields), $rrow->paperId, $rrow->reviewId, $rrow->tfields);
            } else
                error_log("{$Conf->dbname}: #{$rrow->paperId}/{$rrow->reviewId}: differences unfixable");
        }
    }
    Dbl::free($result);
    if ($q) {
        $mresult = Dbl::multi_ql_apply($Conf->dblink, join("; ", $q), $qv);
        $mresult->free_all();
    }
}
