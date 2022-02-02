<?php
// checkinvariants.php -- HotCRP batch invariant checking script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

require_once(dirname(__DIR__) . "/src/siteloader.php");

$arg = Getopt::rest($argv, "hn:", array("help", "name:", "fix-autosearch"));
if (isset($arg["h"]) || isset($arg["help"])
    || count($arg["_"]) > 0) {
    fwrite(STDOUT, "Usage: php batch/checkinvariants.php [-n CONFID] [--fix-autosearch]\n");
    exit(0);
}

require_once(SiteLoader::find("src/init.php"));

$ic = new ConfInvariants($Conf);
$ic->exec_all();

if (isset($ic->problems["autosearch"]) && isset($arg["fix-autosearch"])) {
    $Conf->update_automatic_tags();
}
