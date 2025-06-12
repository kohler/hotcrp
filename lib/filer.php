<?php
// filer.php -- generic document helper class
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Filer {
    /** @var ?string */
    static private $tempdir;

    /** @return ?string */
    static function tempdir() {
        if (self::$tempdir === null) {
            self::$tempdir = tempdir();
        }
        return self::$tempdir;
    }

    /** @param ?string $tempdir
     * @param string $pattern
     * @param int $max_tries
     * @return ?array{non-empty-string,resource} */
    static function create_tempfile($tempdir, $pattern, $max_tries = 100) {
        assert(preg_match('/\A[-_A-Za-z0-9]+%s(?:\.[A-Za-z0-9]+|)\z/', $pattern));
        $tempdir = $tempdir ?? self::tempdir();
        if ($tempdir === null) {
            return null;
        } else if (!str_ends_with($tempdir, "/")) { // should not happen
            $tempdir .= "/";
        }
        for ($i = 0; $i !== $max_tries; ++$i) {
            try {
                $middle = bin2hex(random_bytes(12));
            } catch (Exception $e) {
                $middle = sprintf("x%09d", mt_rand(0, 999999999));
            }
            $fname = $tempdir . str_replace("%s", $middle, $pattern);
            if (($f = @fopen($fname, "x+b"))) {
                return [$fname, $f];
            }
        }
        return null;
    }

    /** @param resource $f
     * @param string $s
     * @return bool
     *
     * Replace the contents of `$f` with `$s`, returning `true` on success.
     * May call `clean_tempdirs()` to clean /tmp. Assumes that `$f` was
     * just opened. */
    static function write_tempfile($f, $s) {
        return fwrite($f, $s) === strlen($s)
            || (clean_tempdirs()
                && rewind($f)
                && fwrite($f, $s) === strlen($s)
                && ftruncate($f, strlen($s)));
    }
}
