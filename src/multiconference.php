<?php
// multiconference.php -- HotCRP multiconference installations
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $Opt;

function set_multiconference() {
    global $ConfSiteBase, $ConfMulticonf, $Opt;

    if (!@$ConfMulticonf) {
        if (@$Opt["multiconferenceUrl"]
            && ($base = request_absolute_uri_base(true))) {
            list($match, $replace) = explode(" ", $Opt["multiconferenceUrl"]);
            if (preg_match("&\\A$match\\z&", $base, $m)) {
                $ConfMulticonf = $replace;
                for ($i = 1; $i < count($m); ++$i)
                    $ConfMulticonf = str_replace("\$$i", $m[$i], $ConfMulticonf);
            }
        } else {
            $url = explode("/", $_SERVER["PHP_SELF"]);
            $npop = strlen($ConfSiteBase) / 3;
            if ($url[count($url) - 1] == "")
                $npop++;
            if ($npop + 2 > count($url))
                return;
            $ConfMulticonf = $url[count($url) - $npop - 2];
        }

        if (!@$ConfMulticonf
            || !preg_match(',\A[-a-zA-Z0-9._]+\z,', $ConfMulticonf)
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

if (@$Opt["multiconference"])
    set_multiconference();

function multiconference_fail($tried_db) {
    global $Conf, $ConfMulticonf, $Me, $Opt;
    if (@$_REQUEST["ajax"]) {
        header("Content-Type: " . (@$_REQUEST["jsontext"] ? "text/plain" : "application/json"));
        echo "{\"error\":\"unconfigured installation\"}\n";
    } else {
        if (!$Conf)
            $Conf = new Conference(false);
        if ($Opt["shortName"] == "__invalid__")
            $Opt["shortName"] = "HotCRP";
        $Me = null;
        header("HTTP/1.1 404 Not Found");
        $Conf->header("HotCRP Error", "", false);
        if (@$Opt["multiconference"])
            echo "<p>The “" . htmlspecialchars($ConfMulticonf) . "” conference does not exist. Check your URL to make sure you spelled it correctly.</p>";
        else if (!@$Opt["loaded"])
            echo "<p>HotCRP has been installed, but not yet configured. You must run <code>Code/createdb.sh</code> to create a database for your conference. See <code>README.md</code> for further guidance.</p>";
        else
            echo "<p>HotCRP was unable to load. A system administrator must fix this problem.</p>";
        if ($tried_db && (!@$Opt["multiconference"] || !@$Opt["include"] || !@$Opt["missing"]))
            echo "<div class=\"hint\">Error: Unable to connect to database " . Conference::sanitize_dsn($Opt["dsn"]) . "</div>";
        else if (!@$Opt["loaded"])
            echo "<div class=\"hint\">Error: Unable to load options file</div>";
        else if (@$Opt["missing"])
            echo "<div class=\"hint\">Error: Unable to load options from " . htmlspecialchars(commajoin($Opt["missing"])) . "</div>";
        $Conf->footer();
    }
    exit;
}
