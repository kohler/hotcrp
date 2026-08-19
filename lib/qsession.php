<?php
// qsession.php -- HotCRP session handling; default is empty
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Qsession {
    /** @var ?string
     * @readonly */
    public $sid;
    /** @var bool */
    protected $sopen = false;
    /** @var bool */
    private $scommitted = false;
    /** @var 0|1|2 */
    private $opentype = 0;
    /** @var array<string,mixed> */
    protected $sv = [];

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
        if ($this->opentype !== 1 && $this->sopen) {
            return;
        }
        if ($this->opentype === 2 && $this->sid !== null) {
            $this->start($this->sid);
            return;
        }
        if (headers_sent($hsfn, $hsln)
            && Navigation::$test_mode <= 0) {
            error_log("{$hsfn}:{$hsln}: headers sent: " . debug_string_backtrace());
        }

        // start session named in cookie
        $sn = session_name();
        $cookie_sid = $_COOKIE[$sn] ?? null;
        if (!$this->sopen) {
            $this->start($cookie_sid);
            if (!$this->sopen) {
                $this->assign_fail();
                return;
            }
        }

        // reset session while reopened, empty [session fixation], or deleted
        if ($this->sid === $cookie_sid
            && !$this->check_reopen()) {
            $this->assign_fail();
            return;
        }

        // maybe update session format
        if (($this->get("v") ?? 0) < 2) {
            if (empty($this->all())) {
                $this->set("v", 2);
            } else {
                UpdateSession::run($this);
            }
        }
        if ($this->get("u") || $this->sid !== $cookie_sid) {
            $this->refresh();
        }
    }

    /** @return bool */
    private function check_reopen() {
        $tries = 0;
        while (true) {
            $curv = $this->all();
            if (($this->opentype !== 1 || $tries > 0)
                && (!empty($curv) || $tries > 0)
                && !isset($curv["deletedat"])) {
                return true;
            }
            ++$tries;

            $nsid = null;
            if (isset($curv["deletedat"])) {
                $transfer = false;
                if ($curv["deletedat"] >= Conf::$now - 30
                    && isset($curv["new_sid"])
                    && is_string($curv["new_sid"])
                    && $tries < 10) {
                    $nsid = $curv["new_sid"];
                }
            } else {
                $transfer = !empty($curv);
            }

            $nsid = $nsid ?? $this->new_sid();
            if ($transfer) {
                $this->set("deletedat", Conf::$now);
                $this->set("new_sid", $nsid);
            }
            $this->commit();

            $this->start($nsid);
            if (!$this->sopen) {
                return false;
            }

            if ($transfer) {
                // `unset` should be a no-op, because we never transfer data
                // from a deleted session:
                unset($curv["deletedat"], $curv["new_sid"]);
                foreach ($curv as $k => $v) {
                    $this->set($k, $v);
                }
            }
        }
    }


    /** @param ?string $sid */
    protected function start($sid) {
    }

    /** @param ?string $sid
     * @suppress PhanAccessReadOnlyProperty */
    protected function set_start_sid($sid) {
        assert(!$this->sopen || $sid === $this->sid);
        if ($sid !== null && $sid !== "") {
            $this->sid = $sid;
            $this->sopen = true;
        } else {
            $this->sid = null;
            $this->sv = [];
        }
    }

    /** @param ?string $sid
     * @param array<string,mixed> $sv
     * @suppress PhanAccessReadOnlyProperty */
    protected function assign_open($sid, $sv) {
        assert(!$this->sopen || $sid === $this->sid);
        $this->sid = $sid;
        $this->sopen = true;
        $this->scommitted = false;
        $this->sv = $sv;
    }

    /** @suppress PhanAccessReadOnlyProperty */
    protected function assign_fail() {
        $this->sid = null;
        $this->sopen = $this->scommitted = false;
        $this->sv = [];
    }

    protected function assign_commit() {
        assert($this->sopen);
        $this->sopen = false;
        $this->scommitted = true;
    }

    /** @return bool
     * @deprecated */
    function is_open() {
        return $this->sopen;
    }

    /** @return bool */
    function is_readable() {
        return $this->sopen || $this->scommitted;
    }

    /** @return bool */
    function is_writable() {
        return $this->sopen;
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
        return $this->sv;
    }

    /** @return void */
    function clear() {
        assert($this->sopen);
        $this->sv = [];
    }

    /** @param string $key
     * @return bool */
    function has($key) {
        return isset($this->sv[$key]);
    }

    /** @param string $key
     * @return mixed */
    function get($key) {
        return $this->sv[$key] ?? null;
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
        return isset($this->sv[$key1][$key2]);
    }

    /** @param string $key1
     * @param string $key2
     * @return mixed */
    function get2($key1, $key2) {
        return $this->sv[$key1][$key2] ?? null;
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
