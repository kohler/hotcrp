<?php
// cap_job.php -- HotCRP batch job capability management
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Job_Capability extends TokenInfo {
    function __construct(Conf $conf) {
        parent::__construct($conf, TokenInfo::JOB);
    }

    /** @param string $batch_class
     * @param list<string> $argv
     * @return Job_Capability */
    static function make(Contact $user, $batch_class, $argv = []) {
        return (new Job_Capability($user->conf))
            ->set_user($user)
            ->set_token_pattern("hcj_[24]")
            ->set_invalid_in(86400)
            ->set_expires_in(86400)
            ->set_input(["batch_class" => $batch_class, "argv" => $argv]);
    }

    /** @param string $token
     * @return ?string */
    static function canonical_token($token) {
        if ($token === "e") {
            $token = getenv("HOTCRP_JOB");
        }
        if ($token === null || strlen($token) < 24) {
            return null;
        }
        if (strpos($token, "_") === false) {
            $token = "hcj_{$token}";
        }
        return $token;
    }

    /** @param string $token
     * @return ?Job_Capability */
    static function find($token, Conf $conf) {
        if (!($rtoken = self::canonical_token($token))) {
            return null;
        }
        $result = $conf->ql("select * from Capability where salt=? and capabilityType=?",
            $rtoken, TokenInfo::JOB);
        $tok = TokenInfo::fetch($result, $conf, false, "Job_Capability");
        $result->close();
        return $tok;
    }

    /** @param string $batch_class
     * @param list<string> $argv
     * @return ?Job_Capability */
    static function find_active_match(Contact $user, $batch_class, $argv = []) {
        $result = $user->conf->ql("select * from Capability
                where capabilityType=? and salt>='hcj' and salt<'hck'
                and contactId=?
                and (timeInvalid<=0 or timeInvalid>=?)
                and (timeExpires<=0 or timeExpires>=?)
                and inputData=?
                order by timeExpires desc limit 1",
            TokenInfo::JOB,
            $user->contactId > 0 ? $user->contactId : 0,
            Conf::$now, Conf::$now,
            json_encode_db(["batch_class" => $batch_class, "argv" => $argv]));
        $tok = TokenInfo::fetch($result, $user->conf, false, "Job_Capability");
        $result->close();
        return $tok;
    }

    /** @param ?string $batch_class
     * @return bool */
    function is_batch_class($batch_class) {
        return $this->capabilityType === TokenInfo::JOB
            && is_string(($bc = $this->input("batch_class")))
            && ($batch_class === null || $batch_class === $bc);
    }

    /** @param string $salt
     * @param ?string $batch_class
     * @return Job_Capability */
    static function claim($salt, Conf $conf, $batch_class = null) {
        $tok = self::find($salt, $conf);
        if (!$tok || !$tok->is_batch_class($batch_class)) {
            throw new CommandLineException("No such job `{$salt}`");
        }
        while (true) {
            if ($tok->encoded_data() !== null) {
                throw new CommandLineException("Job `{$salt}` has already started");
            }
            $new_data = '{"status":"run"}';
            $result = Dbl::qe($conf->dblink,
                "update Capability set `data`=? where salt=? and `data` is null and dataOverflow is null",
                $new_data, $tok->salt);
            if ($result->affected_rows > 0) {
                $tok->assign_data($new_data);
                return $tok;
            }
            $tok->load_data();
        }
    }

    /** @param bool|string $output
     * @return JsonResult */
    function json_result($output = false) {
        $ok = $this->is_active();
        $answer = ["ok" => $ok] + (array) $this->data();
        $answer["ok"] = $ok;
        if (!isset($answer["status"])) {
            $answer["status"] = "wait";
        }
        if (!isset($answer["update_at"])) {
            $answer["update_at"] = $this->timeUsed ? : $this->timeCreated;
        }
        if ($output && $this->outputData !== null) {
            if ((str_starts_with($this->outputData, "{")
                 || str_starts_with($this->outputData, "["))
                && $output !== "text"
                && ($j = json_decode($this->outputData))) {
                $answer["output"] = $j;
            } else if (is_valid_utf8($this->outputData)) {
                $answer["output"] = $this->outputData;
            } else {
                $answer["output_base64"] = base64_encode($this->outputData);
            }
        }
        return new JsonResult($ok ? 200 : 409, $answer);
    }

    /** @param callable():void $detach_function
     * @return 'forked'|'detached'|'done' */
    function run_live($detach_function = null) {
        $batch_class = $this->input("batch_class");

        $status = "done";
        $detacher = null;
        if (PHP_SAPI === "fpm-fcgi" && $detach_function) {
            $detacher = function () use (&$status, $detach_function) {
                if ($status === "done") {
                    $status = "detached";
                    call_user_func($detach_function);
                    fastcgi_finish_request();
                }
            };
        }
        putenv("HOTCRP_JOB={$this->salt}");

        try {
            $argv = [$batch_class];
            if (($confid = $this->conf->opt("confid"))) {
                // The `-n` option is not normally needed: the batch class
                // calls initialize_conf, which does nothing as Conf::$main
                // is already initialized. But we should include it anyway
                // for consistency.
                $argv[] = "-n{$confid}";
            }
            array_push($argv, ...$this->input("argv"));
            $x = call_user_func("{$batch_class}_Batch::make_args", $argv, $detacher);
            $x->run();
        } catch (CommandLineException $ex) {
        }

        putenv("HOTCRP_JOB=");
        return $status;
    }

    /** @param 'foreground'|'background' $batchmode
     * @return int */
    function run_child($batchmode = "foreground") {
        // Requirements:
        // * `$B = $this->input("batch_class")` is set
        // * The class `{$B}_Batch` can be loaded
        // * The file defining `{$B}_Batch` contains the string
        //   `/*{hotcrp {$B}_Batch}*/` in the first 1024 characters
        $batch_class = $this->input("batch_class");
        if (!$batch_class) {
            return -1;
        }
        $paths = SiteLoader::expand_includes(SiteLoader::$root,
            strtolower($batch_class) . "_batch.php",
            ["autoload" => true]);
        if (count($paths) !== 1
            || ($s = file_get_contents($paths[0], false, null, 0, 1024)) === false
            || !preg_match('/\A[^\n]*\/\*\{hotcrp\s*([^\n]*?)\}\*\//', $s, $m)
            || !preg_match("/(?:\\A|\\s){$batch_class}_Batch(?:\\s|\\z)/", $m[1])) {
            return -1;
        }

        $cmd = [];
        if ($batchmode === "background"
            && ($daemonize = $this->conf->opt("daemonizeCommand"))) {
            $cmd[] = $daemonize;
        }
        $cmd[] = self::shell_quote_light($this->conf->opt("phpCommand") ?? "php");
        $cmd[] = self::shell_quote_light($paths[0]);
        if (($confid = $this->conf->opt("confid"))) {
            $cmd[] = self::shell_quote_light("-n{$confid}");
        }
        foreach ($this->input("argv") as $w) {
            $cmd[] = self::shell_quote_light($w);
        }

        $env = getenv();
        $env["HOTCRP_JOB"] = $this->salt;
        $env["HOTCRP_BATCHMODE"] = $batchmode;

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
        }
        return escapeshellarg($word);
    }
}
