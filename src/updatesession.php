<?php
// updatesession.php -- HotCRP session cleaner functions
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class UpdateSession {
    /** @param Qsession $qs */
    static function run($qs) {
        if (($qs->get("v") ?? 0) < 1) {
            $keys = array_keys($qs->all());
            foreach ($keys as $key) {
                if (substr_compare($key, "mysql://", 0, 8) === 0
                    && ($slash = strrpos($key, "/")) !== false) {
                    $qs->set(urldecode(substr($key, $slash + 1)), $qs->get($key));
                    $qs->unset($key);
                } else if ($key === "login_bounce") {
                    $login_bounce = $qs->get($key);
                    if (is_array($login_bounce)
                        && is_string($login_bounce[0])
                        && str_starts_with($login_bounce[0] ?? "", "mysql://")
                        && ($slash = strrpos($login_bounce[0], "/")) !== false) {
                        $login_bounce[0] = urldecode(substr($login_bounce[0], $slash + 1));
                        $qs->set("login_bounce", $login_bounce);
                    }
                }
            }
            $qs->set("v", 1);
        }

        if ($qs->get("v") === 1) {
            $keys = array_keys($qs->all());
            foreach ($keys as $key) {
                if ($key === "") {
                    $qs->unset($key);
                    continue;
                }
                $v = $qs->get($key);
                if (!is_array($v)) {
                    continue;
                }
                if (isset($v["contactdb_roles"])) {
                    unset($v["contactdb_roles"]);
                    if (empty($v)) {
                        $qs->unset($key);
                        continue;
                    }
                    $qs->unset2($key, "contactdb_roles");
                }
                if ($key !== "login_bounce"
                    && $key !== "us"
                    && $key !== "addrs"
                    && $key[0] !== "@"
                    && !$qs->has("@{$key}")) {
                    $qs->set("@{$key}", $v);
                    $qs->unset($key);
                }
            }
            $qs->set("v", 2);
        }

        if (!$qs->has("u") && $qs->has("trueuser")) {
            $qs->set("u", $qs->get("trueuser")->email);
            $qs->unset("trueuser");
        }
    }

    /** @param Qsession $qs
     * @param string $actions */
    static function apply_actions($qs, $actions) {
        foreach (explode("\x1e" /* RS */, $actions) as $action) {
            if ($action === "") {
                continue;
            }
            $aj = json_decode($action);
            if (!is_object($aj)) {
                continue;
            }
            if (($aj->action ?? null) === "signout"
                && is_string($aj->email ?? null)) {
                UserSecurityEvent::session_user_remove($qs, $aj->email);
            }
        }
    }
}
