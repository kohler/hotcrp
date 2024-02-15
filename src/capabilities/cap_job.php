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
     * @param Conf $conf
     * @param ?string $batch_class
     * @param bool $allow_inactive
     * @return TokenInfo */
    static function find($salt, Conf $conf, $batch_class = null, $allow_inactive = false) {
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
     * @param Conf $conf
     * @param ?string $command
     * @return TokenInfo */
    static function claim($salt, Conf $conf, $command = null) {
        $tok = self::find($salt, $conf, $command);
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
        $argv = $tok->input("argv");

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
            $x = call_user_func("{$batch_class}_Batch::make_args", $tok->input("argv"), $detacher);
            $x->run();
        } catch (CommandLineException $ex) {
        }

        putenv("HOTCRP_JOB=");
        return $status;
    }
}
