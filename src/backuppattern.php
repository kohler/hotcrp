<?php
// backuppattern.php -- HotCRP database backup helper
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class BackupPattern {
    /** @var string */
    public $pat;
    /** @var ?string */
    public $str;
    /** @var ?string */
    public $dbname;
    /** @var ?string */
    public $confid;
    /** @var ?int */
    public $timestamp;
    /** @var ?int */
    public $yr;
    /** @var ?int */
    public $mo;
    /** @var ?int */
    public $dy;
    /** @var ?int */
    public $hr;
    /** @var ?int */
    public $mn;
    /** @var ?int */
    public $sc;

    /** @param string $pat */
    function __construct($pat) {
        $this->pat = $pat;
    }

    /** @return $this */
    function clear() {
        $this->str = $this->dbname = $this->confid = $this->timestamp = null;
        $this->yr = $this->mo = $this->dy = $this->hr = $this->mn = $this->sc = null;
        return $this;
    }

    /** @param string $m
     * @return bool */
    function match($m) {
        $this->clear();
        $this->str = $m;
        return $this->process();
    }

    /** @param ?string $dbname
     * @param ?string $confid
     * @param ?int $timestamp
     * @return string */
    function expand($dbname = null, $confid = null, $timestamp = null) {
        $this->clear();
        $this->dbname = $dbname;
        $this->confid = $confid;
        $this->timestamp = $timestamp;
        $this->process();
        return $this->str;
    }

    function compute_time() {
        $x = gmdate("YmdHis", $this->timestamp);
        $this->yr = (int) substr($x, 0, 4);
        $this->mo = (int) substr($x, 4, 2);
        $this->dy = (int) substr($x, 6, 2);
        $this->hr = (int) substr($x, 8, 2);
        $this->mn = (int) substr($x, 10, 2);
        $this->sc = (int) substr($x, 12, 2);
    }

    /** @return bool */
    function process() {
        $pat = $this->pat;
        $str = $this->str ?? "";
        $ismake = $this->str === null;
        $ppos = $spos = 0;
        $plen = strlen($pat);
        $slen = strlen($str);

        while (true) {
            $pct = strpos($pat, "%", $ppos);
            $pct = $pct === false ? $plen : $pct;
            if ($ismake) {
                $str .= substr($pat, $ppos, $pct - $ppos);
            } else if (substr($str, $spos, $pct - $ppos) !== substr($pat, $ppos, $pct - $ppos)) {
                return false;
            }
            $spos += $pct - $ppos;
            $ppos = $pct;
            if ($ppos === $plen || $ppos + 1 === $plen) {
                break;
            }
            $ch = $pat[$ppos + 1];
            if ($ch === "{" && substr($pat, $ppos, 9) === "%{dbname}") {
                $want = &$this->dbname;
                $type = [0];
                $ppos += 9;
            } else if ($ch === "{" && substr($pat, $ppos, 9) === "%{confid}") {
                $want = &$this->confid;
                $type = [0];
                $ppos += 9;
            } else if ($ch === "Y") {
                $want = &$this->yr;
                $type = [4, 2020, 9999];
                $ppos += 2;
            } else if ($ch === "m") {
                $want = &$this->mo;
                $type = [2, 1, 12];
                $ppos += 2;
            } else if ($ch === "d") {
                $want = &$this->dy;
                $type = [2, 1, 31];
                $ppos += 2;
            } else if ($ch === "H") {
                $want = &$this->hr;
                $type = [2, 0, 23];
                $ppos += 2;
            } else if ($ch === "i") {
                $want = &$this->mn;
                $type = [2, 0, 59];
                $ppos += 2;
            } else if ($ch === "s") {
                $want = &$this->sc;
                $type = [2, 0, 60];
                $ppos += 2;
            } else if ($ch === "U") {
                $want = &$this->timestamp;
                $type = [-1];
                $ppos += 2;
            } else if ($ch === "%") {
                $want = "%";
                $type = [0];
                $ppos += 1;
            } else {
                return false;
            }
            if ($want === null && $type[0] > 0 && $this->timestamp !== null) {
                $this->compute_time();
            }
            if ($want !== null) {
                if (is_int($want) && $type[0] > 0) {
                    $wants = sprintf("%0{$type[0]}d", $want);
                } else {
                    $wants = (string) $want;
                }
                if ($ismake) {
                    $str .= $wants;
                } else if (substr($str, $spos, strlen($wants)) !== $wants) {
                    return false;
                }
                $spos += strlen($wants);
            } else if ($ismake) {
                break;
            } else {
                if ($type[0] === 0) {
                    if ($ppos === $plen) {
                        $len = $slen - $spos;
                    } else if ($pat[$ppos] !== "%") {
                        $npos = strpos($str, $pat[$ppos], $spos);
                        if ($npos === false) {
                            return false;
                        }
                        $len = $npos - $spos;
                    } else {
                        $len = 0;
                    }
                } else if ($type[0] > 0) {
                    $len = $type[0];
                } else {
                    $len = strspn($str, "0123456789", $spos);
                }
                if ($len === 0 || $spos + $len > $slen) {
                    return false;
                }
                $x = substr($str, $spos, $len);
                if ($type[0] !== 0) {
                    if (!ctype_digit($x)) {
                        return false;
                    }
                    $x = (int) $x;
                    /** @phan-suppress-next-line PhanTypePossiblyInvalidDimOffset */
                    if (($type[0] > 0 && ($x < $type[1] || $x > $type[2]))
                        || ($type[0] === -1 && $x < 1000000000)) {
                        return false;
                    }
                }
                $want = $x;
                $spos += $len;
            }
            unset($want);
        }

        if ($ismake) {
            $this->str = $str;
        }
        if ($this->timestamp === null
            && $this->yr !== null && $this->mo !== null && $this->dy !== null) {
            $this->timestamp = gmmktime($this->hr ?? 0, $this->mn ?? 0, $this->sc ?? 0,
                                        $this->mo, $this->dy, $this->yr);
        }
        return $ppos === $plen && (!$ismake || $spos === $slen);
    }
}
