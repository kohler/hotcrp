<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:q", array("help", "name:", "json-reviews"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 0) {
    fwrite(STDOUT, "Usage: php batch/checkinvariants.php [-n CONFID]\n");
    exit(0);
}

$Conf->check_invariants();

if ($Conf->sversion == 174 && isset($arg["json-reviews"])) {
    $result = $Conf->qe("select * from PaperReview");
    while (($rrow = ReviewInfo::fetch($result, $Conf))) {
        $tfields = $rrow->tfields ? json_decode($rrow->tfields) : null;
        foreach (ReviewInfo::$text_field_map as $kin => $kout) {
            $oldv = (string) $rrow->$kin;
            $newv = $tfields ? get($tfields, $kout, "") : "";
            if ($oldv !== $newv)
                error_log("{$Conf->dbname}: #{$rrow->paperId}/{$rrow->reviewId}: $kin ["
                    . UnicodeHelper::utf8_abbreviate($oldv === "" ? "EMPTY" : $oldv, 10)
                    . "] differs from tf/$kout ["
                    . UnicodeHelper::utf8_abbreviate($newv === "" ? "EMPTY" : $newv, 10)
                    . "]");
        }
    }
    Dbl::free($result);
}
