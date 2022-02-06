<?php
// pages/checkupdates.php -- HotCRP update checker helper
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class CheckUpdates_Page {
    static function go(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;

        if ($user->privChair
            && $qreq->valid_token()
            && !$qreq->is_head()
            && isset($qreq->ignore)) {
            $when = time() + 86400 * 2;
            $conf->qe("insert into Settings (name, value) values (?, ?) on duplicate key update value=?", "ignoreupdate_" . $qreq->ignore, $when, $when);
        }

        $messages = [];
        if ($user->privChair
            && isset($qreq->data)
            && ($data = json_decode($qreq->data, true))
            && isset($data["updates"])
            && is_array($data["updates"])) {
            foreach ($data["updates"] as $update) {
                $ok = true;
                if (isset($update["opt"]) && is_array($update["opt"])) {
                    foreach ($update["opt"] as $k => $v) {
                        $kk = ($k[0] == "-" ? substr($k, 1) : $k);
                        $test = $conf->opt($kk) == $v;
                        $ok = $ok && ($k[0] == "-" ? !$test : $test);
                    }
                }
                if (isset($update["settings"]) && is_array($update["settings"])) {
                    foreach ($update["settings"] as $k => $v) {
                        if (preg_match('/\A([!<>]?)(-?\d+|now)\z/', $v, $m)) {
                            $setting = $conf->setting($k) ?? 0;
                            if ($m[2] == "now") {
                                $m[2] = time();
                            }
                            if ($m[1] == "!") {
                                $test = $setting != +$m[2];
                            } else if ($m[1] == ">") {
                                $test = $setting > +$m[2];
                            } else if ($m[1] == "<") {
                                $test = $setting < +$m[2];
                            } else {
                                $test = $setting == +$m[2];
                            }
                            $ok = $ok && $test;
                        }
                    }
                }
                $errid = isset($update["errid"]) && ctype_alnum("" . $update["errid"]) ? $update["errid"] : false;
                if ($errid && ($conf->setting("ignoreupdate_$errid") ?? 0) > time()) {
                    $ok = false;
                }
                if ($ok) {
                    $m = "<div class=\"msg msg-error\"";
                    if ($errid) {
                        $m .= " id=\"softwareupdate_$errid\"";
                    }
                    $m .= " style=\"font-size:smaller\"><div class=\"dod\"><strong>WARNING: Upgrade your HotCRP installation.</strong>";
                    if (isset($update["vulnid"]) && is_numeric($update["vulnid"])) {
                        $m .= " (HotCRP-Vulnerability-" . $update["vulnid"] . ")";
                    }
                    $m .= "</div>";
                    if (isset($update["message"]) && is_string($update["message"])) {
                        $m .= "<div class=\"bigid\">" . CleanHTML::basic_clean($update["message"]) . "</div>";
                    }
                    if (isset($update["to"]) && is_string($update["to"])) {
                        $m .= "<div class=\"bigid\">First unaffected commit: " . htmlspecialchars($update["to"]);
                        if ($errid) {
                            $m .= ' <span class="barsep">·</span> '
                                . '<a class="ui js-check-version-ignore" href="" data-version-id="' . $errid . '">Ignore for two days</a>';
                        }
                        $m .= "</div>";
                    }
                    $messages[] = $m . "</div>\n";
                    $_SESSION["updatecheck"] = 0;
                }
            }
        }

        json_exit($messages ? ["ok" => true] : ["ok" => true, "messages" => join("", $messages)]);
    }
}
