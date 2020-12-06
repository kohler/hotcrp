<?php
require_once(preg_replace('/\/batch\/[^\/]+/', '/src/init.php', __FILE__));

$arg = Getopt::rest($argv, "hn:", array("help", "name:", "json-reviews", "fix-json-reviews", "fix-autosearch"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 0) {
    fwrite(STDOUT, "Usage: php batch/checkinvariants.php [-n CONFID] [--fix-autosearch]\n");
    exit(0);
}

$ic = new ConfInvariants($Conf);
$ic->exec_all();

if (isset($ic->problems["autosearch"]) && isset($arg["fix-autosearch"])) {
    $Conf->update_automatic_tags();
}

if ($Conf->sversion == 174 && (isset($arg["json-reviews"]) || isset($arg["fix-json-reviews"]))) {
    $result = $Conf->qe("select * from PaperReview");
    $q = $qv = [];
    while (($rrow = ReviewInfo::fetch($result, null, $Conf))) {
        $tfields = $rrow->tfields ? json_decode($rrow->tfields) : null;
        $need_fix = $unfixable = false;
        foreach (ReviewInfo::$text_field_map as $kin => $kout) {
            $oldv = (string) $rrow->$kin;
            $newv = $tfields ? $tfields->$kout ?? "" : "";
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
                } else {
                    $unfixable = true;
                }
            }
        }
        if ($need_fix && isset($arg["fix-json-reviews"])) {
            if (!$unfixable) {
                $q[] = "update PaperReview set tfields=? where paperId=? and reviewId=? and tfields?e";
                array_push($qv, json_encode_db($tfields), $rrow->paperId, $rrow->reviewId, $rrow->tfields);
            } else {
                error_log("{$Conf->dbname}: #{$rrow->paperId}/{$rrow->reviewId}: differences unfixable");
            }
        }
    }
    Dbl::free($result);
    if ($q) {
        $mresult = Dbl::multi_ql_apply($Conf->dblink, join("; ", $q), $qv);
        $mresult->free_all();
    }
}
