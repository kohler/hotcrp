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

    /** Detach this process from the session that started it.
     *
     * Called when `$HOTCRP_BATCHMODE` is `background`, i.e. when
     * `Job_Token::run_child` started this process to run a job in the
     * background. `batch/hotcrp-daemonize` has already forked and closed the
     * caller's file descriptors, and where `setsid(1)` exists it has started a
     * new session too; this completes the job elsewhere, such as on macOS.
     * @return bool */
    static function detach() {
        if (!function_exists("posix_setsid")
            || !function_exists("posix_getsid")
            || !function_exists("posix_getpgrp")) {
            return false;
        }
        $pid = posix_getpid();
        if (posix_getsid($pid) === $pid) {
            return true;
        }
        if (posix_getpgrp() === $pid) {
            // setsid would fail with EPERM; this process was probably started
            // from an interactive shell, which puts each job in its own group
            return false;
        }
        $sid = posix_setsid();
        if (!is_int($sid) || $sid < 0) {
            error_log("posix_setsid error: " . posix_strerror(posix_get_last_error()));
            return false;
        }
        return true;
    }
}
