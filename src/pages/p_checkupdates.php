<?php
// pages/checkupdates.php -- HotCRP update checker helper
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class CheckUpdates_Page {
    static function go(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;

        if ($user->privChair
            && $qreq->valid_post()
            && isset($qreq->ignore)
            && ctype_alnum($qreq->ignore)
            && strlen($qreq->ignore) <= 128) {
            $when = time() + 86400 * 2;
            $conf->qe("insert into Settings (name, value) values (?, ?) on duplicate key update value=?", "ignoreupdate_" . $qreq->ignore, $when, $when);
        }

        $ml = [];
        $status = 0;
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
                $s = $update["errid"] ?? null;
                if (is_int($s)) {
                    $s = "{$s}";
                }
                if (is_string($s)
                    && ctype_alnum($s)
                    && strlen($s) <= 128) {
                    $errid = $s;
                } else {
                    $errid = null;
                }
                if ($errid !== null
                    && ($conf->setting("ignoreupdate_{$errid}") ?? 0) > time()) {
                    $ok = false;
                }
                if (!$ok) {
                    continue;
                }
                $status = 1;
                if (is_int(($ustatus = $update["status"] ?? null))
                    && ($ustatus === 1 || $ustatus === 2 || $ustatus === -1)) {
                    $status = $ustatus;
                }
                $m = "<5><strong>WARNING: Upgrade your HotCRP installation</strong>";
                $ml[] = new MessageItem($status, null, $m);
                if (is_string(($message = $update["message"] ?? null))) {
                    $ml[] = MessageItem::inform("<5>" . CleanHTML::basic_clean($message));
                }
                $notes = [];
                if (is_string(($hash = $update["to"] ?? null))
                    && ctype_xdigit($hash)
                    && strlen($hash) >= 7) {
                    $notes[] = "First unaffected commit: {$hash}";
                }
                $cvel = $update["cve+"] ?? $update["cve"] ?? null;
                if (is_string($cvel)) {
                    $cvel = [$cvel];
                }
                $xp = [];
                foreach (is_string_list($cvel) ? $cvel : [] as $cve) {
                    if (preg_match('/\ACVE-[-0-9]+\z/', $cve)) {
                        $xp[] = "<a href=\"https://www.cve.org/CVERecord?id={$cve}\">{$cve}</a>";
                    }
                }
                if (!empty($xp)) {
                    $notes[] = join(", ", $xp);
                }
                $ghsal = $update["ghsa+"] ?? $update["ghsa"] ?? null;
                if (is_string($ghsal)) {
                    $ghsal = [$ghsal];
                }
                $xp = [];
                foreach (is_string_list($ghsal) ? $ghsal : [] as $ghsa) {
                    if (preg_match('/\AGHSA-[-a-z0-9]+\z/', $ghsa)) {
                        $xp[] = "<a href=\"https://github.com/advisories/{$ghsa}\">" . (empty($xp) ? "GitHub " : "") . $ghsa . "</a>";
                    }
                }
                if (!empty($xp)) {
                    $notes[] = join(", ", $xp);
                }

                if ($errid !== null) {
                    $notes[] = "<button type=\"button\" class=\"link ui js-check-version-ignore\" data-errid=\"{$errid}\">Ignore for two days</button>";
                }
                if (!empty($notes)) {
                    $ml[] = MessageItem::inform("<5>" . join(' <span class="barsep">·</span> ', $notes));
                }
            }
        }

        json_exit(empty($ml) ? ["ok" => true] : ["ok" => true, "message_list" => $ml]);
    }
}
