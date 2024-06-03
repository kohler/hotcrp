<?php
// batchprocess.php -- HotCRP code for running batch processes
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class BatchProcess {
    /** @param Throwable $ex
     * @suppress PhanUndeclaredProperty */
    static function exception_handler($ex) {
        global $argv;
        $s = $ex->getMessage();
        if (defined("HOTCRP_TESTHARNESS") || $ex instanceof Error) {
            $s = $ex->getFile() . ":" . $ex->getLine() . ": " . $s;
        }
        if ($s !== "" && strpos($s, ":") === false) {
            $script = $argv[0] ?? "";
            if (($slash = strrpos($script, "/")) !== false) {
                if (($slash === 5 && str_starts_with($script, "batch"))
                    || ($slash > 5 && substr_compare($script, "/batch", $slash - 6, 6) === 0)) {
                    $slash -= 6;
                }
                $script = substr($script, $slash + 1);
            }
            if ($script !== "") {
                $s = "{$script}: {$s}";
            }
        }
        if ($s !== "" && substr($s, -1) !== "\n") {
            $s = "{$s}\n";
        }
        $exitStatus = 3;
        if (property_exists($ex, "exitStatus") && is_int($ex->exitStatus)) {
            $exitStatus = $ex->exitStatus;
        }
        if (property_exists($ex, "getopt")
            && $ex->getopt instanceof Getopt
            && $exitStatus !== 0) {
            $s .= $ex->getopt->short_usage();
        }
        if (property_exists($ex, "context") && is_array($ex->context)) {
            foreach ($ex->context as $c) {
                $i = 0;
                while ($i !== strlen($c) && $c[$i] === " ") {
                    ++$i;
                }
                $s .= prefix_word_wrap(str_repeat(" ", $i + 2), trim($c), 2);
            }
        }
        if (defined("HOTCRP_TESTHARNESS") || $ex instanceof Error) {
            $s .= debug_string_backtrace($ex) . "\n";
        }
        fwrite(STDERR, $s);
        exit($exitStatus);
    }

    /** @return bool */
    static function daemonize() {
        if (!function_exists("pcntl_fork")) {
            return false;
        }
        if (($f = pcntl_fork()) < 0) {
            return false;
        } else if ($f > 0) {
            exit(0);
        }
        if (function_exists("posix_setsid")) {
            if (posix_setsid() < 0) {
                error_log("posix_setsid error: " . posix_strerror(posix_get_last_error()));
            }
        }
        return true;
    }
}
