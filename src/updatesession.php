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

    /** @param Qrequest $qreq
     * @param string $email
     * @param bool $add
     * @return int
     * @deprecated */
    static function user_change($qreq, $email, $add) {
        if ($add) {
            return UserSecurityEvent::session_user_add($qreq->qsession(), $email);
        } else {
            UserSecurityEvent::session_user_remove($qreq->qsession(), $email);
            return -1;
        }
    }

    /** @param string $email
     * @param 0|1|2 $type
     * @param 0|1 $reason
     * @param int $bound
     * @return bool
     * @deprecated */
    static function usec_query(Qrequest $qreq, $email, $type, $reason, $bound = 0) {
        $success = false;
        foreach (UserSecurityEvent::session_list_by_email($qreq->qsession(), $email) as $use) {
            if ($use->type === $type
                && $use->reason === $reason
                && $use->timestamp >= $bound)
                $success = $use->success;
        }
        return $success;
    }

    /** @param string $email
     * @param 0|1|2 $type - 0 password, 2 MFA
     * @param 0|1 $reason - 0 login, 1 confirmation
     * @param bool $success
     * @deprecated */
    static function usec_add(Qrequest $qreq, $email, $type, $reason, $success) {
        UserSecurityEvent::make($email, $type, $reason)
            ->set_success($success)
            ->store($qreq->qsession());
    }

    /** @param string $email
     * @param list<array{0|1|2,bool}> $useclist
     * @param 0|1 $reason
     * @deprecated */
    static function usec_add_list(Qrequest $qreq, $email, $useclist, $reason) {
        foreach ($useclist as $elt) {
            self::usec_add($qreq, $email, $elt[0], $reason, $elt[1]);
        }
    }
}
