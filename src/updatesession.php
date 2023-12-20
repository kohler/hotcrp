<?php
// updatesession.php -- HotCRP session cleaner functions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

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

    /** @param Qrequest $qreq
     * @param string $email
     * @param bool $add
     * @return int */
    static function user_change($qreq, $email, $add) {
        $us = Contact::session_users($qreq);
        $empty = null;
        $ui = 0;
        while ($ui !== count($us)) {
            if ($us[$ui] === "") {
                $empty = $empty ?? $ui;
            } else if (strcasecmp($us[$ui], $email) === 0) {
                break;
            }
            ++$ui;
        }
        if ($add) {
            if ($ui === count($us) && $empty !== null) {
                $ui = $empty;
            }
            $us[$ui] = $email;
        } else if ($ui !== count($us)) {
            $us[$ui] = "";
        }
        while (!empty($us) && $us[count($us) - 1] === "") {
            array_pop($us);
        }
        if (count($us) > 1) {
            $qreq->set_gsession("us", $us);
        } else {
            $qreq->unset_gsession("us");
        }
        if (empty($us)) {
            $qreq->unset_gsession("u");
        } else {
            $i = 0;
            while ($us[$i] === "") {
                ++$i;
            }
            $qreq->set_gsession("u", $us[$i]);
        }

        // clear out usec entries
        $qreq->unset_gsession("uts");
        if (!$add) {
            $usec = [];
            foreach ($qreq->gsession("usec") ?? [] as $e) {
                if (($e["u"] ?? 0) !== $ui)
                    $usec[] = $e;
            }
            $qreq->set_gsession("usec", $usec);
        }

        return $add ? $ui : -1;
    }

    /** @param string $email
     * @param 0|1|2 $type
     * @param 0|1 $reason
     * @param int $bound
     * @return bool */
    static function usec_query(Qrequest $qreq, $email, $type, $reason, $bound = 0) {
        $uindex = Contact::session_index_by_email($qreq, $email);
        $usec = $qreq->gsession("usec") ?? [];
        $success = false;
        foreach ($qreq->gsession("usec") ?? [] as $e) {
            if ((isset($e["e"])
                 ? strcasecmp($e["e"], $email) === 0
                 : ($e["u"] ?? 0) === $uindex)
                && ($e["t"] ?? 0) === $type
                && ($e["r"] ?? 0) === $reason
                && $e["a"] >= $bound) {
                $success = !($e["x"] ?? false);
            }
        }
        return $success;
    }

    /** @param string $email
     * @param 0|1|2 $type
     * @param 0|1 $reason
     * @param bool $success */
    static function usec_add(Qrequest $qreq, $email, $type, $reason, $success) {
        $uindex = Contact::session_index_by_email($qreq, $email);
        $old_usec = $qreq->gsession("usec") ?? [];
        $nold_usec = count($old_usec);

        $usec = [];
        foreach ($old_usec as $i => $e) {
            if ($uindex >= 0
                && isset($e["e"])
                && strcasecmp($e["e"], $email) === 0) {
                unset($e["e"]);
                if ($uindex > 0) {
                    $e["u"] = $uindex;
                }
            }
            if ((($e["r"] ?? 0) === 1
                 && $e["a"] < Conf::$now - 86400)
                || ($success
                    && (isset($e["e"])
                        ? strcasecmp($e["e"], $email) === 0
                        : ($e["u"] ?? 0) === $uindex)
                    && ($e["t"] ?? 0) === $type
                    && ($e["r"] ?? 0) === $reason)
                || ($nold_usec > 150
                    && ($e["x"] ?? false)
                    && $e["a"] < Conf::$now - 900)) {
                continue;
            }
            $usec[] = $e;
        }

        $x = [];
        if ($uindex < 0) {
            $x["e"] = $email;
        } else if ($uindex > 0) {
            $x["u"] = $uindex;
        }
        if ($type !== 0) {
            $x["t"] = $type;
        }
        if ($reason !== 0) {
            $x["r"] = $reason;
        }
        if (!$success) {
            $x["x"] = true;
        }
        $x["a"] = Conf::$now;
        $usec[] = $x;
        $qreq->set_gsession("usec", $usec);
    }

    /** @param string $email
     * @param list<array{0|1|2,bool}> $useclist
     * @param 0|1 $reason */
    static function usec_add_list(Qrequest $qreq, $email, $useclist, $reason) {
        foreach ($useclist as $elt) {
            self::usec_add($qreq, $email, $elt[0], $reason, $elt[1]);
        }
    }
}
