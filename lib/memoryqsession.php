<?php
// memoryqsession.php -- HotCRP session handler for ephemeral per-request sessions
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class MemoryQsession extends Qsession {
    /** @var array<string,mixed> */
    private $a;

    /** @param ?string $sid
     * @param array<string,mixed> $a */
    function __construct($sid = null, $a = []) {
        $this->sid = $sid ?? "sess_" . base64_encode(random_bytes(15));
        $this->sopen = true;
        $this->a = $a;
    }

    function all() {
        return $this->a;
    }

    function clear() {
        assert($this->sopen);
        $this->a = [];
    }

    function has($key) {
        return $this->sopen && isset($this->a[$key]);
    }

    function get($key) {
        return $this->sopen ? $this->a[$key] ?? null : null;
    }

    function set($key, $value) {
        assert($this->sopen);
        $this->a[$key] = $value;
    }

    function unset($key) {
        assert($this->sopen);
        unset($this->a[$key]);
    }

    function has2($key1, $key2) {
        return $this->sopen && isset($this->a[$key1][$key2]);
    }

    function get2($key1, $key2) {
        return $this->sopen ? $this->a[$key1][$key2] ?? null : null;
    }

    function set2($key1, $key2, $value) {
        assert($this->sopen);
        $this->a[$key1][$key2] = $value;
    }

    function unset2($key1, $key2) {
        assert($this->sopen);
        if (isset($this->a[$key1])) {
            unset($this->a[$key1][$key2]);
            if (empty($this->a[$key1])) {
                unset($this->a[$key1]);
            }
        }
    }
}
