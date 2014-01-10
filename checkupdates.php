<?php
// checkupdates.php -- HotCRP update checker helper
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
if (isset($_REQUEST["text"]) && $_REQUEST["text"])
    header("Content-Type: text/plain");
else
    header("Content-Type: application/json");

if ($Me->privChair && isset($_REQUEST["ignore"])) {
    $when = time() + 86400 * 2;
    $Conf->qe("insert into Settings (name, value) values ('ignoreupdate_" . sqlq($_REQUEST["ignore"]) . "', $when) on duplicate key update value=$when");
}

$messages = array();
if ($Me->privChair && isset($_REQUEST["data"])
    && ($data = json_decode($_REQUEST["data"], true))
    && isset($data["updates"]) && is_array($data["updates"])) {
    foreach ($data["updates"] as $update) {
	$ok = true;
	if (isset($update["opt"]) && is_array($update["opt"]))
	    foreach ($update["opt"] as $k => $v) {
		$kk = ($k[0] == "-" ? substr($k, 1) : $k);
		$test = defval($Opt, $kk, null) == $v;
		$ok = $ok && ($k[0] == "-" ? !$test : $test);
	    }
	if (isset($update["settings"]) && is_array($update["settings"]))
	    foreach ($update["settings"] as $k => $v) {
		if (preg_match('/\A([!<>]?)(-?\d+|now)\z/', $v, $m)) {
		    $setting = $Conf->setting($k, 0);
		    if ($m[2] == "now")
			$m[2] = time();
		    if ($m[1] == "!")
			$test = $setting != +$m[2];
		    else if ($m[1] == ">")
			$test = $setting > +$m[2];
		    else if ($m[1] == "<")
			$test = $setting < +$m[2];
		    else
			$test = $setting == +$m[2];
		    $ok = $ok && $test;
		}
	    }
        $errid = isset($update["errid"]) && ctype_alnum("" . $update["errid"]) ? $update["errid"] : false;
        if ($errid && $Conf->setting("ignoreupdate_$errid", 0) > time())
            $ok = false;
	if ($ok) {
            $m = "<div class='xmerror'";
            if ($errid)
                $m .= " id='softwareupdate_$errid'";
            $m .= " style='font-size:smaller'><div class='dod'><strong>WARNING: Upgrade your HotCRP installation.</strong>";
	    if (isset($update["vulnid"]) && is_numeric($update["vulnid"]))
		$m .= " (HotCRP-Vulnerability-" . $update["vulnid"] . ")";
	    $m .= "</div>";
	    if (isset($update["message"]) && is_string($update["message"]))
		$m .= "<div class='bigid'>" . CleanHTML::clean($update["message"], $error) . "</div>";
	    if (isset($update["to"]) && is_string($update["to"])) {
		$m .= "<div class='bigid'>First unaffected commit: " . htmlspecialchars($update["to"]);
                if ($errid)
                    $m .= " &nbsp;<span class='barsep'>|</span>&nbsp; "
                        . "<a href='#' onclick='return check_version.ignore(\"$errid\")'>Ignore for two days</a>";
                $m .= "</div>";
            }
	    $messages[] = $m . "</div>\n";
	    $Me->_updatecheck = 0;
	}
    }
}

if (!count($messages))
    echo "{\"ok\":true}\n";
else {
    $j = array("ok" => true, "messages" => join("", $messages));
    echo json_encode($j);
}
