<?php
// confactions.php -- HotCRP conference actions
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ConfActions {
    /** @var Conf */
    private $conf;
    /** @var bool */
    private $cdb;
    /** @var Contact */
    private $sec;
    /** @var ?Contact */
    private $pri;
    /** @var ?Contact */
    private $actor;

    static function execute_settings(Conf $conf) {
        // Goal: Execute the actions stored in `confactions`, in order.
        // Requires a locking strategy so that other loads don't execute
        // confactions out of order.
        $actions = $conf->setting_data("confactions") ?? "";
        $myid = bin2hex(random_bytes(16));

        while (true) {
            list($aj, $rest) = self::first_action($conf, $actions, $myid);

            // special action: complete
            if ($aj->action === "done") {
                if (self::store_actions($conf, "", $actions)) {
                    return;
                }
                continue;
            }

            // special action: wait for lock
            if ($aj->action === "lock") {
                if ($aj->t < Conf::$now - 10) {
                    // 10 seconds waiting for a lock? locker is stuck
                    error_log("{$conf->dbname}: Stuck confaction");
                    self::store_actions($conf, $rest, $actions);
                    continue;
                }
                // otherwise, wait 0.1s and try again
                usleep(100000);
                Conf::set_current_time();
                $actions = self::load_actions($conf);
                continue;
            }

            // otherwise, claim lock and execute action
            $lockj = "\x1e" . json_encode_db([
                "action" => "lock", "id" => $myid, "t" => Conf::$now
            ]) . "\n";
            if (!self::store_actions($conf, $lockj . $rest, $actions)) {
                continue;
            }

            // perform action
            if ($aj->action === "link") {
                self::link($conf, $aj);
            }
        }
    }

    static private function first_action(Conf $conf, $actions, $myid) {
        if ($actions === "") {
            return [(object) ["action" => "done"], ""];
        }

        $start = str_starts_with($actions, "\x1e") ? 1 : 0;
        $rs = strpos($actions, "\x1e" /* RS */, $start);
        $rs = $rs !== false ? $rs : strlen($actions);
        $nl = strpos($actions, "\n", str_starts_with($actions, "\n") ? 1 : 0);
        $nl = $nl !== false ? $nl + 1 : strlen($actions);
        $endpos = min($rs, $nl);

        $first = substr($actions, $start, $endpos - $start);
        $rest = substr($actions, $endpos);

        // ignore truncated or invalid action
        if (!str_ends_with($first, "\n")
            || !($aj = json_decode($first))
            || !is_object($aj)
            || !is_string($aj->action ?? null)) {
            error_log("{$conf->dbname}: Invalid confactions " . substr($actions, 0, 100));
            return self::first_action($conf, $rest, $myid);
        }

        // skip our own lock to find next action
        if ($aj->action === "lock" && $aj->id === $myid) {
            return self::first_action($conf, $rest, $myid);
        }

        // otherwise, use current action
        return [$aj, $rest];
    }

    static private function store_actions(Conf $conf, $new_actions, &$actions) {
        if ($new_actions === "") {
            $result = $conf->qe("delete from Settings where name='confactions' and data=?", $actions);
        } else {
            $result = $conf->qe("update Settings set value=?, data=? where name='confactions' and data=?", Conf::$now, $new_actions, $actions);
        }
        if ($result->affected_rows) {
            $actions = $new_actions;
            return true;
        }
        $actions = self::load_actions($conf);
        return false;
    }

    static private function load_actions(Conf $conf) {
        return $conf->fetch_value("select data from Settings where name='confactions'") ?? "";
    }

    static function link(Conf $conf, $aj) {
        $srcemail = $aj->u ?? null;
        $dstemail = $aj->email ?? null;
        if (!is_string($srcemail) || !is_string($dstemail ?? "")) {
            return;
        }
        $srcuser = $conf->user_by_email($srcemail);
        $dstuser = $dstemail !== null
            ? ($conf->user_by_email($dstemail) ?? $conf->cdb_user_by_email($dstemail))
            : null;
        if (!$srcuser || (isset($dstemail) && !$dstuser)) {
            return;
        }
        (new ContactPrimary($conf->root_user()))->link($srcuser, $dstuser);
    }
}
