<?php
// cap_job.php -- HotCRP batch job capability management
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Job_Capability {
    /** @param string $command
     * @param ?list<string> $argv */
    static function make(Contact $user, $command, $argv = null) {
        return (new TokenInfo($user->conf, TokenInfo::JOB))
            ->set_user($user)
            ->set_token_pattern("hcj_[24]")
            ->set_invalid_after(86400)
            ->set_expires_after(86400)
            ->set_input(["command" => $command, "argv" => $argv]);
    }

    /** @param string $salt
     * @param Conf $conf
     * @param ?string $command
     * @param bool $allow_inactive
     * @return TokenInfo */
    static function find($salt, Conf $conf, $command = null, $allow_inactive = false) {
        if ($salt !== null && strpos($salt, "_") === false) {
            $salt = "hcj_{$salt}";
        }
        $tok = TokenInfo::find($salt, $conf);
        if (!$tok
            || !self::validate_token($tok, $command)
            || (!$allow_inactive && !$tok->is_active())) {
            throw new CommandLineException("Invalid job token `{$salt}`");
        }
        return $tok;
    }

    /** @param ?string $command
     * @return bool */
    static function validate_token(TokenInfo $tok, $command) {
        return $tok->capabilityType === TokenInfo::JOB
            && ($command === null || $tok->input("command") === $command);
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
                $new_data, $salt);
            if ($result->affected_rows > 0) {
                $tok->assign_data($new_data);
                return $tok;
            }
            $tok->load_data();
        }
    }
}
