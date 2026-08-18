<?php
// account.php -- HotCRP maintenance script for changing accounts
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Account_Batch::make_args($argv)->run());
}

class Account_Batch {
    /** @var Conf */
    public $conf;
    /** @var string */
    private $subcommand;
    /** @var ?string */
    private $email;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->subcommand = $arg["_subcommand"];
        if (!empty($arg["_"])) {
            $this->email = $arg["_"][0];
        }
        if (isset($arg["user"]) && isset($this->email)) {
            throw new CommandLineException("Redundant `--user`");
        } else if (isset($arg["user"])) {
            $this->email = $arg["user"];
        }
    }

    /** @return Contact */
    private function user() {
        if (!isset($this->email)) {
            throw new CommandLineException("User required");
        }
        $u = $this->conf->user_by_email($this->email)
            ?? $this->conf->cdb_user_by_email($this->email);
        if (!$u) {
            throw new CommandLineException("User not found");
        }
        return $u;
    }

    /** @return int */
    function run() {
        if ($this->subcommand === "resetpassword") {
            return $this->run_resetpassword();
        }
        throw new CommandLineException("Unknown subcommand");
    }

    /** @return int */
    private function run_resetpassword() {
        $u = $this->user();
        if ($this->conf->external_login()) {
            throw new CommandLineException("Passwords are not used with external login");
        }
        $u->change_password(" reset");
        fwrite(STDOUT, "{$u->email}: password reset\n");
        return 0;
    }

    /** @param list<string> $argv
     * @return Account_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "user:,u: =EMAIL Set user"
        )->description("Make targeted changes to a HotCRP account.
Usage: php batch/account.php [-n CONFID|--config CONFIG] resetpassword EMAIL")
         ->helpopt("help")
         ->interleave(true)
         ->subcommand(true,
                      "resetpassword Reset password, requiring user to change it")
         ->maxarg(1)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Account_Batch($conf, $arg);
    }
}
