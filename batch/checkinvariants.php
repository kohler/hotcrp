<?php
// checkinvariants.php -- HotCRP batch invariant checking script
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    define("HOTCRP_NOINIT", 1);
    require_once(dirname(__DIR__) . "/src/init.php");
    CheckInvariants_Batch::make_args($argv)->run();
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

    function run() {
        $ic = new ConfInvariants($this->conf);
        $ic->exec_all();
        if (isset($ic->problems["autosearch"]) && $this->fix_autosearch) {
            $this->conf->update_automatic_tags();
        }
    }

    /** @return Assign_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n:",
            "config:,c:",
            "fix-autosearch"
        )->parse($argv);

        if (isset($arg["help"]) || count($arg["_"]) > 0) {
            fwrite(STDOUT, "Usage: php batch/checkinvariants.php [-n CONFID] [--fix-autosearch]\n");
            exit(isset($arg["help"]) ? 0 : 1);
        }

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new CheckInvariants_Batch($conf, $arg);
    }
}
