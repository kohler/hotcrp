<?php
// checkinvariants.php -- HotCRP batch invariant checking script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(CheckInvariants_Batch::make_args($argv)->run());
}

class CheckInvariants_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $fix_autosearch;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->fix_autosearch = isset($arg["fix-autosearch"]);
    }

    /** @return int */
    function run() {
        $ic = new ConfInvariants($this->conf);
        $ic->exec_all();
        if (isset($ic->problems["autosearch"]) && $this->fix_autosearch) {
            $this->conf->update_automatic_tags();
        }
        return 0;
    }

    /** @return CheckInvariants_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config:,c: !",
            "help,h !",
            "fix-autosearch Repair any incorrect autosearch tags"
        )->helpopt("help")
         ->description("Check invariants in a HotCRP database.
Usage: php batch/checkinvariants.php [-n CONFID] [--fix-autosearch]\n")
         ->maxarg(0)
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new CheckInvariants_Batch($conf, $arg);
    }
}
