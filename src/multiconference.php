<?php
// multiconference.php -- HotCRP multiconference installations
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function set_multiconference() {
    global $ConfSiteBase, $ConfMulticonf, $Opt;

    if (!@$ConfMulticonf) {
        $url = explode("/", $_SERVER["PHP_SELF"]);
        $npop = strlen($ConfSiteBase) / 3;
        if ($url[count($url) - 1] == "")
            $npop++;
        if ($npop + 2 > count($url))
            return;
        $ConfMulticonf = $url[count($url) - $npop - 2];

        if (!preg_match(',\A[-a-zA-Z0-9._]+\z,', $ConfMulticonf)
            || $ConfMulticonf[0] == ".")
            $ConfMulticonf = "__invalid__";
    }

    foreach (array("dbName", "dbUser", "dbPassword", "dsn",
                   "sessionName", "downloadPrefix", "conferenceSite",
                   "paperSite") as $k)
	if (isset($Opt[$k]))
            $Opt[$k] = preg_replace(',\*|\$\{confname\}|\$confname\b,', $ConfMulticonf, $Opt[$k]);

    if (!@$Opt["dbName"] && !@$Opt["dsn"])
	$Opt["dbName"] = $ConfMulticonf;
}
set_multiconference();
