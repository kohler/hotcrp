<?php
// fieldchangeset.php -- HotCRP helper for `status:unchanged`/`status:changed`
// Copyright (c) 2023 Eddie Kohler; see LICENSE.

class FieldChangeSet {
    /** @var array<string,1|2|3> */
    public $_m = [];

    const ABSENT = 0;
    const UNCHANGED = 1;
    const CHANGED = 2;

    /** @param ?string $s
     * @return $this */
    function mark_unchanged($s) {
        $this->apply($s, self::UNCHANGED);
        return $this;
    }

    /** @param ?string $s
     * @return $this */
    function mark_changed($s) {
        $this->apply($s, self::CHANGED);
        return $this;
    }

    /** @param string $src
     * @param string $dst
     * @return $this */
    function mark_synonym($src, $dst) {
        $this->_m[$src] = $this->_m[$dst] =
            ($this->_m[$src] ?? 0) | ($this->_m[$dst] ?? 0);
        return $this;
    }

    /** @param ?string $s
     * @param 1|2 $bit */
    private function apply($s, $bit) {
        foreach (explode(" ", $s ?? "") as $word) {
            if ($word !== "") {
                $this->_m[$word] = ($this->_m[$word] ?? 0) | $bit;
                if (($colon = strpos($word, ":")) !== false) {
                    $px = substr($word, 0, $colon);
                    $this->_m[$px] = ($this->_m[$px] ?? 0) | $bit;
                }
            }
        }
    }

    /** @param string $key
     * @return 0|1|2|3 */
    function test($key) {
        return $this->_m[$key] ?? 0;
    }
}
