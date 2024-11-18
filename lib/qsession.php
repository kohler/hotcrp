<?php
// qsession.php -- HotCRP session handling; default is empty
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Qsession {
    /** @var ?string
     * @readonly */
    public $sid;
    /** @var bool */
    protected $sopen = false;
    /** @var 0|1|2 */
    private $opentype = 0;

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

        // reset session while reopened, empty [session fixation], or deleted
        if ($this->sid === $cookie_sid) {
            $this->check_reopen();
            if ($this->sid === null) {
                return;
            }
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

    private function check_reopen() {
        $tries = 0;
        while (true) {
            $curv = $this->all();
            if (($this->opentype !== 1 || $tries > 0)
                && (!empty($curv) || $tries > 0)
                && !isset($curv["deletedat"])) {
                return;
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

            /** @phan-suppress-next-line PhanAccessReadOnlyProperty */
            $this->sid = $this->start($nsid);
            if ($this->sid === null) {
                return;
            }
            $this->sopen = true;

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


    /** @param ?string $sid
     * @return ?string */
    protected function start($sid) {
        return null;
    }

    /** @return bool */
    function is_open() {
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
