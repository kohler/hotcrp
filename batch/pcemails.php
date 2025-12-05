<?php
// pcemails.php -- HotCRP batch script to output PC emails
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(PCEmails_Batch::make_args($argv)->run());
}

class PCEmails_Batch {
    /** @var Conf */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @return int */
    function run() {
        $x = [];
        foreach ($this->conf->pc_members() as $u) {
            $x[] = $u->email . "\n";
        }
        fwrite(STDOUT, join("", $x));
        return 0;
    }

    static function make_args($argv) {
        $go = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !"
        )->helpopt("help")
         ->description("Print PC emails to standard output")
         ->maxarg(0);
        $arg = $go->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new PCEmails_Batch($conf);
    }
}
