<?php
// checkupdates.php -- HotCRP update checker helper
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
header("Content-Type: " . ($Qreq->text ? "text/plain" : "application/json"));

if ($Me->privChair && $Qreq->post_ok() && isset($Qreq->ignore)) {
    $when = time() + 86400 * 2;
    $Conf->qe("insert into Settings (name, value) values (?, ?) on duplicate key update value=?", "ignoreupdate_" . $Qreq->ignore, $when, $when);
}

$messages = array();
if ($Me->privChair
    && isset($Qreq->data)
    && ($data = json_decode($Qreq->data, true))
    && isset($data["updates"])
    && is_array($data["updates"])) {
    foreach ($data["updates"] as $update) {
        $ok = true;
        if (isset($update["opt"]) && is_array($update["opt"]))
            foreach ($update["opt"] as $k => $v) {
                $kk = ($k[0] == "-" ? substr($k, 1) : $k);
                $test = $Conf->opt($kk, null) == $v;
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
            $m = "<div class='msg msg-error'";
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
                    $m .= ' <span class="barsep">·</span> '
                        . '<a class="ui js-check-version-ignore" href="" data-version-id="' . $errid . '">Ignore for two days</a>';
                $m .= "</div>";
            }
            $messages[] = $m . "</div>\n";
            $_SESSION["updatecheck"] = 0;
        }
    }
}

json_exit($messages ? ["ok" => true] : ["ok" => true, "messages" => join("", $messages)]);
