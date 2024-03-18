<?php
// cap_job.php -- HotCRP batch job capability management
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Job_Capability {
    /** @param string $batch_class
     * @param list<string> $argv
     * @return TokenInfo */
    static function make(Contact $user, $batch_class, $argv = []) {
        return (new TokenInfo($user->conf, TokenInfo::JOB))
            ->set_user($user)
            ->set_token_pattern("hcj_[24]")
            ->set_invalid_after(86400)
            ->set_expires_after(86400)
            ->set_input(["batch_class" => $batch_class, "argv" => $argv]);
    }

    /** @param string $salt
     * @param ?string $batch_class
     * @param bool $allow_inactive
     * @return TokenInfo */
    static function find(Conf $conf, $salt, $batch_class = null, $allow_inactive = false) {
        if (($salt === false || $salt === "e")
            && !($salt = getenv("HOTCRP_JOB"))) {
            throw new CommandLineException("HOTCRP_JOB not set");
        }
        if ($salt !== null && strpos($salt, "_") === false) {
            $salt = "hcj_{$salt}";
        }
        $tok = TokenInfo::find($salt, $conf);
        if (!$tok
            || !self::validate($tok, $batch_class)
            || (!$allow_inactive && !$tok->is_active())) {
            throw new CommandLineException("Invalid job token `{$salt}`");
        }
        return $tok;
    }

    /** @param ?string $batch_class
     * @return bool */
    static function validate(TokenInfo $tok, $batch_class) {
        return $tok->capabilityType === TokenInfo::JOB
            && is_string(($bc = $tok->input("batch_class")))
            && ($batch_class === null || $batch_class === $bc);
    }

    /** @param string $salt
     * @param ?string $command
     * @return TokenInfo */
    static function claim(Conf $conf, $salt, $command = null) {
        $tok = self::find($conf, $salt, $command);
        while (true) {
            if ($tok->data !== null) {
                throw new CommandLineException("Job `{$salt}` has already started");
            }
            $new_data = '{"status":"run"}';
            $result = Dbl::qe($conf->dblink,
                "update Capability set `data`=? where salt=? and `data` is null",
                $new_data, $tok->salt);
            if ($result->affected_rows > 0) {
                $tok->assign_data($new_data);
                return $tok;
            }
            $tok->load_data();
        }
    }

    /** @param string|callable():string $redirect_uri
     * @return 'forked'|'detached'|'done' */
    static function run_live(TokenInfo $tok, ?Qrequest $qreq = null, $redirect_uri = null) {
        assert(self::validate($tok, null));
        $batch_class = $tok->input("batch_class");

        $status = "done";
        $detacher = function () use (&$status, $qreq, $redirect_uri) {
            if (PHP_SAPI === "fpm-fcgi" && $status === "done") {
                $status = "detached";
                if ($redirect_uri) {
                    $u = is_string($redirect_uri) ? $redirect_uri : call_user_func($redirect_uri);
                    header("Location: {$u}");
                }
                if ($qreq) {
                    $qreq->qsession()->commit();
                }
                fastcgi_finish_request();
            }
        };
        putenv("HOTCRP_JOB={$tok->salt}");

        try {
            $argv = [$batch_class];
            if (($confid = $tok->conf->opt("confid"))) {
                // The `-n` option is not normally needed: the batch class
                // calls initialize_conf, which does nothing as Conf::$main
                // is already initialized. But we should include it anyway
                // for consistency.
                $argv[] = "-n{$confid}";
            }
            array_push($argv, ...$tok->input("argv"));
            $x = call_user_func("{$batch_class}_Batch::make_args", $argv, $detacher);
            $x->run();
        } catch (CommandLineException $ex) {
        }

        putenv("HOTCRP_JOB=");
        return $status;
    }

    /** @return int */
    static function run_background(TokenInfo $tok) {
        assert(self::validate($tok, null));
        $batch_class = $tok->input("batch_class");
        $f = strtolower($batch_class) . "_batch.php";
        $paths = SiteLoader::expand_includes(SiteLoader::$root, $f, ["autoload" => true]);
        if (count($paths) !== 1
            || ($s = file_get_contents($paths[0], false, null, 0, 1024)) === false
            || !preg_match('/\A[^\n]*\/\*\{hotcrp\s*([^\n]*?)\}\*\//', $s, $m)
            || !preg_match("/(?:\\A|\\s){$batch_class}_Batch(?:\\s|\\z)/", $m[1])) {
            return -1;
        }

        $cmd = [];
        $cmd[] = self::shell_quote_light($tok->conf->opt("phpCommand") ?? "php");
        $cmd[] = self::shell_quote_light($paths[0]);
        if (($confid = $tok->conf->opt("confid"))) {
            $cmd[] = self::shell_quote_light("-n{$confid}");
        }
        foreach ($tok->input("argv") as $w) {
            $cmd[] = self::shell_quote_light($w);
        }

        $env = getenv();
        $env["HOTCRP_JOB"] = $tok->salt;
        $env["HOTCRP_EXEC_MODE"] = "background";

        $redirect = PHP_VERSION_ID >= 70400 ? ["redirect", 1] : ["file", "/dev/null", "a"];
        $p = proc_open(join(" ", $cmd),
            [["file", "/dev/null", "r"], ["file", "/dev/null", "a"], $redirect],
            $pipes,
            SiteLoader::$root,
            $env);
        return proc_close($p);
    }

    static function shell_quote_light($word) {
        if (preg_match('/\A[-_.,:+\/a-zA-Z0-9][-_.,:=+\/a-zA-Z0-9~]*\z/', $word)) {
            return $word;
        } else {
            return escapeshellarg($word);
        }
    }
}
