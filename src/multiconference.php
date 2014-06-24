<?php
// multiconference.php -- HotCRP multiconference installations
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

global $Opt;

function set_multiconference() {
    global $ConfMulticonf, $Opt;

    if (!@$ConfMulticonf && PHP_SAPI == "cli") {
        $cliopt = getopt("n:", array("name:"));
        if (@$cliopt["n"])
            $ConfMulticonf = $cliopt["n"];
        else if (@$cliopt["name"])
            $ConfMulticonf = $cliopt["name"];
    } else if (!@$ConfMulticonf) {
        $base = Navigation::site_absolute(true);
        if (($multis = @$Opt["multiconferenceAnalyzer"])) {
            foreach (is_array($multis) ? $multis : array($multis) as $multi) {
                list($match, $replace) = explode(" ", $multi);
                if (preg_match("`\\A$match`", $base, $m)) {
                    $ConfMulticonf = $replace;
                    for ($i = 1; $i < count($m); ++$i)
                        $ConfMulticonf = str_replace("\$$i", $m[$i], $ConfMulticonf);
                    break;
                }
            }
        } else if (preg_match(',/([^/]+)/\z,', $base, $m))
            $ConfMulticonf = $m[1];
    }

    if (!@$ConfMulticonf)
        $ConfMulticonf = "__nonexistent__";
    else if (!preg_match(',\A[-a-zA-Z0-9._]+\z,', $ConfMulticonf)
             || $ConfMulticonf[0] == ".")
        $ConfMulticonf = "__invalid__";

    foreach (array("dbName", "dbUser", "dbPassword", "dsn",
                   "sessionName", "downloadPrefix", "conferenceSite",
                   "paperSite", "defaultPaperSite",
                   "contactName", "contactEmail",
                   "emailFrom", "emailSender", "emailCc", "emailReplyTo") as $k)
        if (isset($Opt[$k]) && is_string($Opt[$k]))
            $Opt[$k] = preg_replace(',\*|\$\{conf(?:id|name)\}|\$conf(?:id|name)\b,', $ConfMulticonf, $Opt[$k]);

    if (!@$Opt["dbName"] && !@$Opt["dsn"])
        $Opt["dbName"] = $ConfMulticonf;
}

if (@$Opt["multiconference"])
    set_multiconference();

function multiconference_fail($tried_db) {
    global $Conf, $ConfMulticonf, $Me, $Opt;

    $errors = array();
    if (@$Opt["maintenance"])
        $errors[] = "The site is down for maintenance. " . (is_string($Opt["maintenance"]) ? $Opt["maintenance"] : "Please check back later.");
    else if (@$Opt["multiconference"] && $ConfMulticonf === "__nonexistent__")
        $errors[] = "You haven’t specified a conference and this is a multiconference installation.";
    else if (@$Opt["multiconference"])
        $errors[] = "The “${ConfMulticonf}” conference does not exist. Check your URL to make sure you spelled it correctly.";
    else if (!@$Opt["loaded"])
        $errors[] = "HotCRP has been installed, but not yet configured. You must run `lib/createdb.sh` to create a database for your conference. See `README.md` for further guidance.";
    else
        $errors[] = "HotCRP was unable to load. A system administrator must fix this problem.";
    if (@$Opt["maintenance"])
        /* do nothing */;
    else if ($tried_db && (!@$Opt["multiconference"] || !@$Opt["include"] || !@$Opt["missing"]))
        $errors[] = "Error: Unable to connect to database " . Conference::sanitize_dsn($Opt["dsn"]);
    else if (!@$Opt["loaded"] && defined("HOTCRP_OPTIONS"))
        $errors[] = "Error: Unable to load options file `" . HOTCRP_OPTIONS . "`";
    else if (!@$Opt["loaded"])
        $errors[] = "Error: Unable to load options file";
    else if (@$Opt["missing"])
        $errors[] = "Error: Unable to load options from " . commajoin($Opt["missing"]);
    if ($tried_db && defined("HOTCRP_TESTHARNESS"))
        $errors[] = "You may need to run `lib/createdb.sh -c test/testoptions.php` to create the database.\n";

    if (PHP_SAPI == "cli") {
        fwrite(STDERR, join("\n", $errors) . "\n");
        exit(1);
    } else if (@$_REQUEST["ajax"]) {
        header("Content-Type: " . (@$_REQUEST["jsontext"] ? "text/plain" : "application/json"));
        if (@$Opt["maintenance"])
            echo "{\"error\":\"maintenance\"}\n";
        else
            echo "{\"error\":\"unconfigured installation\"}\n";
    } else {
        if (!$Conf)
            $Conf = new Conference(false);
        if ($Opt["shortName"] == "__invalid__")
            $Opt["shortName"] = "HotCRP";
        $Me = null;
        header("HTTP/1.1 404 Not Found");
        $Conf->header("HotCRP Error", "", false);
        foreach ($errors as $i => &$e)
            $e = ($i ? "<div class=\"hint\">" : "<p>") . htmlspecialchars($e) . ($i ? "</div>" : "</p>");
        echo join("", $errors);
        $Conf->footer();
    }
    exit;
}
