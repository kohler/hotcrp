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
    public $verbose;
    /** @var bool */
    public $fix_autosearch;
    /** @var bool */
    public $fix_inactive;
    /** @var ?string */
    public $regex;
    /** @var ?bool */
    public $color;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->verbose = isset($arg["verbose"]);
        $this->fix_autosearch = isset($arg["fix-autosearch"]);
        $this->fix_inactive = isset($arg["fix-inactive"]);
        if (!empty($arg["_"])) {
            $r = [];
            foreach ($arg["_"] as $m) {
                $r[] = glob_to_regex($m);
            }
            $this->regex = "/" . join("|", $r) . "/";
        }
        if (isset($arg["color"])) {
            $this->color = true;
        } else if (isset($arg["no-color"])) {
            $this->color = false;
        }
    }

    /** @return int */
    function run() {
        $ic = new ConfInvariants($this->conf);
        $ncheck = 0;
        $ro = new ReflectionObject($ic);
        $color = $this->color ?? posix_isatty(STDERR);
        foreach ($ro->getMethods() as $m) {
            if (str_starts_with($m->name, "check_")
                && $m->name !== "check_all"
                && (!$this->regex || preg_match($this->regex, $m->name))) {
                if ($this->verbose) {
                    if ($color) {
                        fwrite(STDERR, "{$this->conf->dbname}: \x1b[01;36mRUN\x1b[m  {$m->name}");
                    } else {
                        fwrite(STDERR, "{$this->conf->dbname}: {$m->name} ");
                    }
                    $ic->buffer_messages();
                    $ic->{$m->name}();
                    $msgs = $ic->take_buffered_messages();
                    if ($color && $msgs !== "") {
                        fwrite(STDERR, "\r{$this->conf->dbname}: \x1b[01;31mFAIL\x1b[m {$m->name}\n{$msgs}");
                    } else if ($color) {
                        fwrite(STDERR, "\r{$this->conf->dbname}:  \x1b[01;32mOK\x1b[m  {$m->name}\n");
                    } else if ($msgs !== "") {
                        fwrite(STDERR, "fail\n{$msgs}");
                    } else {
                        fwrite(STDERR, "ok\n");
                    }
                } else {
                    $ic->{$m->name}();
                }
                ++$ncheck;
            }
        }
        if ($this->regex && $ncheck === 0) {
            fwrite(STDERR, "No matching invariants\n");
            return 1;
        }

        if (isset($ic->problems["autosearch"]) && $this->fix_autosearch) {
            $this->conf->update_automatic_tags();
        }
        if (isset($ic->problems["inactive"]) && $this->fix_inactive) {
            $this->fix_inactive_documents();
        }
        return 0;
    }

    private function fix_inactive_documents() {
        $this->conf->qe("update PaperStorage s join Paper p on (p.paperId=s.paperId and (p.paperStorageId=s.paperStorageId or p.finalPaperStorageId=s.paperStorageId)) set s.inactive=0");

        $this->conf->qe("update PaperStorage s join DocumentLink l on (l.documentId=s.paperStorageId) set s.inactive=0");

        $oids = [];
        foreach ($this->conf->options()->universal() as $o) {
            if ($o->has_document()) {
                $oids[] = $o->id;
            }
        }
        if (!empty($oids)) {
            $this->conf->qe("update PaperStorage s join PaperOption o on (o.paperId=s.paperId and o.optionId?a and o.value=s.paperStorageId) set s.inactive=0", $oids);
        }
    }

    /** @return CheckInvariants_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config:,c: !",
            "help,h !",
            "verbose,V Be verbose",
            "fix-autosearch Repair any incorrect autosearch tags",
            "fix-inactive Repair any inappropriately inactive documents",
            "color",
            "no-color !"
        )->helpopt("help")
         ->description("Check invariants in a HotCRP database.
Usage: php batch/checkinvariants.php [-n CONFID] [--fix-autosearch] [INVARIANT...]\n")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new CheckInvariants_Batch($conf, $arg);
    }
}
