<?php
// memoryqsession.php -- HotCRP session handler for ephemeral per-request sessions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class MemoryQsession extends Qsession {
    /** @param ?string $sid
     * @param array<string,mixed> $sv */
    function __construct($sid = null, $sv = []) {
        $this->assign_open($sid ?? "sess_" . base64_encode(random_bytes(15)), $sv);
    }

    /** @suppress PhanAccessReadOnlyProperty */
    function open_new_sid() {
        // test fixture
        $this->sid = "sess_" . base64_encode(random_bytes(15));
    }

    function set($key, $value) {
        assert($this->sopen);
        $this->sv[$key] = $value;
    }

    function unset($key) {
        assert($this->sopen);
        unset($this->sv[$key]);
    }

    function set2($key1, $key2, $value) {
        assert($this->sopen);
        $this->sv[$key1][$key2] = $value;
    }

    function unset2($key1, $key2) {
        assert($this->sopen);
        if (isset($this->sv[$key1])) {
            unset($this->sv[$key1][$key2]);
            if (empty($this->sv[$key1])) {
                unset($this->sv[$key1]);
            }
        }
    }
}
