<?php
// checkinvariants.php -- HotCRP batch invariant checking script
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(CheckInvariants_Batch::make_args($argv)->run());
}

class CheckInvariants_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $verbose;
    /** @var list<string> */
    public $fix;
    /** @var ?string */
    public $regex;
    /** @var ?bool */
    public $color;
    /** @var ?bool */
    public $pad_prefix;
    /** @var int */
    private $width = 47;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->verbose = isset($arg["verbose"]);
        $this->fix = $arg["fix"] ?? [];
        if (isset($arg["fix-autosearch"])) {
            $this->fix[] = "autosearch";
        }
        if (isset($arg["fix-inactive"])) {
            $this->fix[] = "inactive";
        }
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
        $this->pad_prefix = isset($arg["pad-prefix"]);
    }

    /** @param string $problem
     * @return bool */
    function want_fix($problem) {
        return in_array($problem, $this->fix) || in_array("all", $this->fix);
    }

    /** @param string $report */
    function report_fix($report) {
        $fix = $this->color ? " \x1b[01;36mFIX\x1b[m\n" : " FIX\n";
        fwrite(STDERR,
            str_pad("{$this->conf->dbname}: {$report} ", $this->width, ".")
            . $fix);
    }

    /** @return int */
    function run() {
        $ic = new ConfInvariants($this->conf);
        $ncheck = 0;
        $ro = new ReflectionObject($ic);
        $color = $this->color = $this->color ?? posix_isatty(STDERR);
        $dbname = $this->conf->dbname;
        foreach ($ro->getMethods() as $m) {
            if (str_starts_with($m->name, "check_")
                && $m->name !== "check_all"
                && (!$this->regex || preg_match($this->regex, $m->name))) {
                if ($this->verbose) {
                    $mpfx = str_pad("{$dbname}: {$m->name} ", $this->width, ".") . " ";
                    if ($color) {
                        fwrite(STDERR, "{$mpfx}\x1b[01;36mRUN\x1b[m");
                    } else {
                        fwrite(STDERR, $mpfx);
                    }
                    $ic->buffer_messages();
                    $ic->{$m->name}();
                    $msgs = $ic->take_buffered_messages();
                    if ($color && $msgs !== "") {
                        $msgs = preg_replace('/^' . preg_quote($this->conf->dbname) . ' invariant violation:/m',
                            "\x1b[0;31m{$this->conf->dbname} invariant violation:\x1b[m", $msgs);
                        fwrite(STDERR, "\r{$mpfx}\x1b[01;31mFAIL\x1b[m\n{$msgs}");
                    } else if ($color) {
                        fwrite(STDERR, "\r{$mpfx}\x1b[01;32m OK\x1b[m\x1b[K\n");
                    } else if ($msgs !== "") {
                        fwrite(STDERR, "FAIL\n{$msgs}");
                    } else {
                        fwrite(STDERR, "OK\n");
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

        if (isset($ic->problems["no_papersub"]) && $this->want_fix("summary")) {
            $this->report_fix("`no_papersub` summary setting");
            $this->conf->update_papersub_setting(0);
        }
        if (isset($ic->problems["paperacc"]) && $this->want_fix("summary")) {
            $this->report_fix("`paperacc` summary setting");
            $this->conf->update_paperacc_setting(0);
        }
        if (isset($ic->problems["rev_tokens"]) && $this->want_fix("summary")) {
            $this->report_fix("`rev_tokens` summary setting");
            $this->conf->update_rev_tokens_setting(0);
        }
        if (isset($ic->problems["paperlead"]) && $this->want_fix("summary")) {
            $this->report_fix("`paperlead` summary setting");
            $this->conf->update_paperlead_setting(0);
        }
        if (isset($ic->problems["papermanager"]) && $this->want_fix("summary")) {
            $this->report_fix("`papermanager` summary setting");
            $this->conf->update_papermanager_setting(0);
        }
        if (isset($ic->problems["metareviews"]) && $this->want_fix("summary")) {
            $this->report_fix("`metareviews` summary setting");
            $this->conf->update_metareviews_setting(0);
        }
        if (isset($ic->problems["autosearch"]) && $this->want_fix("autosearch")) {
            $this->report_fix("automatic tags");
            $this->conf->update_automatic_tags();
        }
        if (isset($ic->problems["inactive"]) && $this->want_fix("inactive")) {
            $this->report_fix("inactive documents");
            $this->fix_inactive_documents();
        }
        if (isset($ic->problems["paper_denormalization"]) && $this->want_fix("document-match")) {
            $this->report_fix("document match");
            $this->fix_document_match();
        }
        if (isset($ic->problems["user_whitespace"]) && $this->want_fix("whitespace")) {
            $this->report_fix("whitespace");
            $this->fix_whitespace();
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

    private function fix_document_match() {
        $result = $this->conf->qe("select * from PaperStorage where paperStorageId>1 and size<0");
        while (($doc = DocumentInfo::fetch($result, $this->conf))) {
            $doc->size();
        }
        $result->close();

        $this->conf->qe("update Paper p join PaperStorage s on (s.paperId=p.paperId and s.paperStorageId=p.paperStorageId) set p.size=s.size where p.size<0 and p.finalPaperStorageId<=1");
        $this->conf->qe("update Paper p join PaperStorage s on (s.paperId=p.paperId and s.paperStorageId=p.finalPaperStorageId) set p.size=s.size where p.size<0 and p.finalPaperStorageId>1");
    }

    private function fix_whitespace() {
        $result = $this->conf->qe("select * from ContactInfo");
        $mq = Dbl::make_multi_qe_stager($this->conf->dblink);
        while (($u = Contact::fetch($result, $this->conf))) {
            $u->firstName = simplify_whitespace(($fn = $u->firstName));
            $u->lastName = simplify_whitespace(($ln = $u->lastName));
            $u->affiliation = simplify_whitespace(($af = $u->affiliation));
            $un = $u->unaccentedName;
            $u->unaccentedName = $u->db_searchable_name();
            if ($fn !== $u->firstName || $ln !== $u->lastName
                || $af !== $u->affiliation || $un !== $u->unaccentedName) {
                $mq("update ContactInfo set firstName=?, lastName=?, affiliation=?, unaccentedName=? where contactId=?", $u->firstName, $u->lastName, $u->affiliation, $u->unaccentedName, $u->contactId);
            }
        }
        $mq(null);
    }

    /** @return CheckInvariants_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config:,c: !",
            "help,h !",
            "verbose,V Be verbose",
            "fix-autosearch ! Repair any incorrect autosearch tags",
            "fix-inactive ! Repair any inappropriately inactive documents",
            "fix[] =PROBLEM Repair PROBLEM [all, autosearch, inactive, setting, document-match, whitespace]",
            "color",
            "no-color !",
            "pad-prefix !"
        )->helpopt("help")
         ->description("Check invariants in a HotCRP database.
Usage: php batch/checkinvariants.php [-n CONFID] [--fix=WHAT] [INVARIANT...]\n")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new CheckInvariants_Batch($conf, $arg);
    }
}
