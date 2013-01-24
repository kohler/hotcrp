<?php
// checkupdates.php -- HotCRP update checker helper
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("Code/header.inc");
if (isset($_REQUEST["text"]) && $_REQUEST["text"])
    header("Content-Type: text/plain");
else
    header("Content-Type: application/json");

$messages = array();
if ($Me->valid() && $Me->privChair && isset($_REQUEST["data"])
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
	if ($ok) {
	    $m = "<div class='xmerror' style='font-size:smaller'><div class='dod'><strong>WARNING: Upgrade your HotCRP installation.</strong>";
	    if (isset($update["vulnid"]) && is_numeric($update["vulnid"]))
		$m .= " (HotCRP-Vulnerability-" . $update["vulnid"] . ")";
	    $m .= "</div>";
	    if (isset($update["message"]) && is_string($update["message"])) {
		require_once("Code/cleanxhtml.inc");
		$m .= "<div class='bigid'>" . cleanXHTML($update["message"], $error) . "</div>";
	    }
	    if (isset($update["to"]) && is_string($update["to"]))
		$m .= "<div class='bigid'>First unaffected commit: " . htmlspecialchars($update["to"]) . "</div>";
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
