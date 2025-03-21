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
        $actions = $conf->setting_data("confactions") ?? "";
        while ($actions !== "") {
            $rs = strpos($actions, "\x1e" /* RS */, $actions[0] === "\x1e" ? 1 : 0);
            $rs = $rs === false ? strlen($actions) : $rs;
            $nl = strpos($actions, "\n", $actions[0] === "\n" ? 1 : 0);
            $nl = $nl === false ? strlen($actions) : $nl + 1;
            $endpos = min($rs, $nl);

            $first = substr($actions, 0, $endpos);
            $rest = substr($actions, $endpos);
            if ($rest === "") {
                $result = $conf->qe("delete from Settings where name='confactions' and data=?", $actions);
            } else {
                $result = $conf->qe("update Settings set data=? where name='confactions' and data=?", $rest, $actions);
            }

            if ($result->affected_rows) {
                if (str_ends_with($first, "\n")) { // otherwise database truncated an action
                    self::execute($conf, $first);
                } else {
                    error_log("WARNING: confactions setting truncated");
                }
                $actions = $rest;
            } else {
                $actions = $conf->fetch_value("select data from Settings where name='confactions'") ?? "";
            }
        }
    }

    static function execute(Conf $conf, $actionstr) {
        if (str_starts_with($actionstr, "\x1e")) {
            $actionstr = substr($actionstr, 1);
        }
        if ($actionstr === "") {
            return;
        }
        $aj = json_decode($actionstr);
        if (!is_object($aj) || !isset($aj->action)) {
            return;
        }
        if ($aj->action === "link") {
            self::link($conf, $aj);
        }
    }

    static function link(Conf $conf, $aj) {
        if (!is_string($aj->u ?? null)
            || !($srcuser = $conf->user_by_email($aj->u))) {
            return;
        }
        if (isset($aj->email)) {
            if (!is_string($aj->email)) {
                return;
            }
            $dstuser = $conf->user_by_email($aj->email)
                ?? $conf->cdb_user_by_email($aj->email);
            if (!$dstuser) {
                return;
            }
        } else {
            $dstuser = null;
        }
        (new ContactPrimary($conf->root_user()))->link($srcuser, $dstuser);
    }
}
