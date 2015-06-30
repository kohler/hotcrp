<?php
$ConfSitePATH = preg_replace(',/batch/[^/]+,', '', __FILE__);
require_once("$ConfSitePATH/src/init.php");
require_once("$ConfSitePATH/lib/getopt.php");

$arg = getopt_rest($argv, "hn:q", array("help", "name:"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 0) {
    fwrite(STDOUT, "Usage: php batch/checkinvariants.php [-n CONFID]\n");
    exit(0);
}

$Conf->check_invariants();
