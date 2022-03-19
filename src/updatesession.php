<?php
// updatesession.php -- HotCRP session cleaner functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class UpdateSession {
    static function run() {
        if (($_SESSION["v"] ?? 0) < 1) {
            $keys = array_keys($_SESSION);
            foreach ($keys as $key) {
                if (substr_compare($key, "mysql://", 0, 8) === 0
                    && ($slash = strrpos($key, "/")) !== false) {
                    $_SESSION[urldecode(substr($key, $slash + 1))] = $_SESSION[$key];
                    unset($_SESSION[$key]);
                } else if ($key === "login_bounce"
                           && is_array($_SESSION[$key])
                           && is_string($_SESSION[$key][0] ?? null)
                           && substr_compare($_SESSION[$key][0] ?? "", "mysql://", 0, 8) === 0
                           && ($slash = strrpos($_SESSION[$key][0], "/")) !== false) {
                    $_SESSION[$key][0] = urldecode(substr($_SESSION[$key][0], $slash + 1));
                }
            }
            $_SESSION["v"] = 1;
        }

        if (($_SESSION["v"] ?? 0) < 2) {
            $keys = array_keys($_SESSION);
            foreach ($keys as $key) {
                if ($key === "") {
                    unset($_SESSION[$key]);
                    continue;
                }
                if (is_array($_SESSION[$key])
                    && isset($_SESSION[$key]["contactdb_roles"])) {
                    unset($_SESSION[$key]["contactdb_roles"]);
                    if (empty($_SESSION[$key])) {
                        unset($_SESSION[$key]);
                        continue;
                    }
                }
                if (is_array($_SESSION[$key])
                    && $key !== "login_bounce"
                    && $key !== "us"
                    && $key !== "addrs"
                    && $key[0] !== "@"
                    && !isset($_SESSION["@{$key}"])) {
                    $_SESSION["@{$key}"] = $_SESSION[$key];
                    unset($_SESSION[$key]);
                }
            }
            $_SESSION["v"] = 2;
        }
    }
}
