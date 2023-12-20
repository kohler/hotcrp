<?php
// qsession.php -- HotCRP session handling; default is empty
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Qsession {
    /** @var ?string
     * @readonly */
    public $sid;
    /** @var bool */
    protected $sopen = false;
    /** @var 0|1|2 */
    protected $opentype = 0;

    function maybe_open() {
        if (!$this->sopen && isset($_COOKIE[session_name()])) {
            $this->open();
        }
        return $this->sopen;
    }

    function open() {
        $this->opentype = 0;
        $this->handle_open();
    }

    function open_new_sid() {
        $this->opentype = 1;
        $this->handle_open();
    }

    function reopen() {
        $this->opentype = 2;
        $this->handle_open();
    }

    function handle_open() {
        if ($this->sopen && $this->opentype !== 1) {
            return;
        }
        if ($this->sid !== null && $this->opentype === 2) {
            $sid = $this->start($this->sid);
            assert($sid === $this->sid);
            return;
        }
        if (headers_sent($hsfn, $hsln)) {
            error_log("{$hsfn}:{$hsln}: headers sent: " . debug_string_backtrace());
        }

        // start session named in cookie
        $sn = session_name();
        $cookie_sid = $_COOKIE[$sn] ?? null;
        if (!$this->sopen) {
            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->sid = $this->start($cookie_sid);
            if ($this->sid === null) {
                return;
            }
            $this->sopen = true;
        }

        // if reopened, empty [session fixation], or old, reset session
        $curv = $this->all();
        if (empty($curv)
            || ($curv["deletedat"] ?? Conf::$now) < Conf::$now - 30) {
            $this->opentype = 1;
        }
        if ($this->sid === $cookie_sid && $this->opentype === 1) {
            $nsid = $this->new_sid();
            if (!isset($curv["deletedat"])) {
                $this->set("deletedat", Conf::$now);
            }
            $this->commit();

            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->sid = $this->start($nsid);
            if ($this->sid === null) {
                return;
            }
            $this->sopen = true;
            foreach ($curv as $k => $v) {
                $this->set($k, $v);
            }
        }

        // maybe update session format
        if (empty($this->all())) {
            $this->set("v", 2);
        } else {
            if (($this->get("v") ?? 0) < 2) {
                UpdateSession::run($this);
            }
        }
        if ($this->get("u") || $cookie_sid !== $this->sid) {
            $this->refresh();
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
    function refresh() {
        $params = session_get_cookie_params();
        if ($params["lifetime"] > 0) {
            $params["expires"] = Conf::$now + $params["lifetime"];
        }
        unset($params["lifetime"]);
        hotcrp_setcookie(session_name(), $this->sid, $params);
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
