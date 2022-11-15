<?php
// qsession.php -- HotCRP session handling; default is empty
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Qsession {
    /** @var ?string */
    public $sid;
    /** @var -1|0|1 */
    protected $sstate = 0;

    function maybe_open() {
        if ($this->sstate === 0 && isset($_COOKIE[session_name()])) {
            $this->open();
        }
        return $this->sstate > 0;
    }

    function reopen() {
        $this->sstate = -1;
        $this->open();
    }

    function open() {
        if (headers_sent($hsfn, $hsln)) {
            error_log("$hsfn:$hsln: headers sent: " . debug_string_backtrace());
        }
        if ($this->sstate > 0) {
            return;
        }
        $reopen = $this->sstate < 0;
        $this->sstate = 0;

        // start session named in cookie
        $sn = session_name();
        $cookie_sid = $_COOKIE[$sn] ?? null;
        $this->sid = $this->start($cookie_sid);
        if ($this->sid === null) {
            return;
        }
        $this->sstate = 1;

        // if reopened, empty [session fixation], or old, reset session
        $curv = $this->all();
        if (empty($curv)
            || ($curv["deletedat"] ?? Conf::$now) < Conf::$now - 30) {
            $reopen = true;
        }
        if ($this->sid === $cookie_sid && $reopen) {
            $nsid = $this->new_sid();
            if (!isset($curv["deletedat"])) {
                $this->set("deletedat", Conf::$now);
            }
            $this->commit();
            $this->sstate = 0;

            if ($cookie_sid !== $nsid) {
                $params = session_get_cookie_params();
                $params["expires"] = Conf::$now + $params["lifetime"];
                unset($params["lifetime"]);
                hotcrp_setcookie($sn, $nsid, $params);
            }
            $this->sid = $this->start($nsid);
            if ($this->sid === null) {
                return;
            }
            $this->sstate = 1;
            foreach ($curv as $k => $v) {
                $this->set($k, $v);
            }
        }

        // maybe update session format
        if (empty($this->all())) {
            $this->set("v", 2);
            $this->set("testsession", [$_SERVER["REMOTE_ADDR"], caller_landmark()]);
        } else {
            if (($this->get("v") ?? 0) < 2) {
                UpdateSession::run($this);
            }
            if (Conf::$main->_session_handler) {
                Conf::$main->_session_handler->refresh_cookie($sn, $this->sid);
            }
        }
    }


    /** @param ?string $sid
     * @return ?string */
    function start($sid) {
        return null;
    }

    /** @return ?string */
    function new_sid() {
        return null;
    }

    /** @return void */
    function commit() {
    }

    /** @return array<string,mixed> */
    function all() {
        return [];
    }

    /** @return void */
    function clear() {
    }

    /** @param string $key
     * @return bool */
    function has($key) {
        return false;
    }

    /** @param string $key
     * @return mixed */
    function get($key) {
        return null;
    }

    /** @param string $key
     * @param mixed $value
     * @return void */
    function set($key, $value) {
    }

    /** @param string $key
     * @return void */
    function unset($key) {
    }

    /** @param string $key1
     * @param string $key2
     * @return bool */
    function has2($key1, $key2) {
        return false;
    }

    /** @param string $key1
     * @param string $key2
     * @return mixed */
    function get2($key1, $key2) {
        return null;
    }

    /** @param string $key1
     * @param string $key2
     * @param mixed $value
     * @return void */
    function set2($key1, $key2, $value) {
    }

    /** @param string $key1
     * @param string $key2
     * @return void */
    function unset2($key1, $key2) {
    }
}
