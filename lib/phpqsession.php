<?php
// phpqsession.php -- HotCRP session handler wrapping PHP sessions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class PHPQsession extends Qsession {
    function start($sid) {
        if ($sid !== null
            && strlen($sid) >= 20
            && strlen($sid) <= 128
            && (ctype_alnum($sid) || preg_match('/\A[-,0-9A-Za-z]+\z/', $sid))) {
            session_id($sid);
        }
        session_start();
        $sid = session_id();
        if ($sid !== "" && $sid !== false) {
            $this->assign_open($sid, $_SESSION);
        }
    }

    function new_sid() {
        return session_create_id();
    }

    function commit() {
        if ($this->sopen) {
            session_commit();
            $this->assign_commit();
        }
    }

    function clear() {
        assert($this->sopen);
        // NB Modification methods must double-assign: PHP serializes session
        // data from $_SESSION
        $this->sv = $_SESSION = [];
    }

    function set($key, $value) {
        assert($this->sopen);
        $this->sv[$key] = $_SESSION[$key] = $value;
    }

    function unset($key) {
        assert($this->sopen);
        unset($this->sv[$key], $_SESSION[$key]);
    }

    function set2($key1, $key2, $value) {
        assert($this->sopen);
        $this->sv[$key1][$key2] = $_SESSION[$key1][$key2] = $value;
    }

    function unset2($key1, $key2) {
        assert($this->sopen);
        if (isset($this->sv[$key1])) {
            unset($this->sv[$key1][$key2], $_SESSION[$key1][$key2]);
            if (empty($this->sv[$key1])) {
                unset($this->sv[$key1], $_SESSION[$key1]);
            }
        }
    }
}
