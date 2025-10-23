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
    /** @var bool */
    public $list;
    /** @var int */
    public $level;
    /** @var int */
    private $width = 47;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->verbose = isset($arg["verbose"]);
        $this->fix = $arg["fix"] ?? [];
        $this->level = $arg["level"] ?? 0;
        $this->list = isset($arg["list"]);
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
        return in_array($problem, $this->fix, true)
            || in_array("all", $this->fix, true);
    }

    /** @param string $report */
    function report_fix($report) {
        $fix = $this->color ? " \x1b[01;36mFIX\x1b[m\n" : " FIX\n";
        fwrite(STDERR,
            str_pad("{$this->conf->dbname}: {$report} ", $this->width, ".")
            . $fix);
    }

    /** @return int */
    private function method_level(ReflectionMethod $m) {
        if (PHP_MAJOR_VERSION >= 8) {
            foreach ($m->getAttributes("ConfInvariantLevel") ?? [] as $attr) {
                return $attr->newInstance()->level;
            }
        }
        return 0;
    }

    /** @return int */
    function run() {
        $ic = (new ConfInvariants($this->conf))->set_level($this->level);
        $ncheck = 0;
        $ro = new ReflectionObject($ic);
        $color = $this->color = $this->color ?? posix_isatty(STDERR);
        $ic->set_color($this->color);
        $dbname = $this->conf->dbname;
        foreach ($ro->getMethods() as $m) {
            if (!str_starts_with($m->name, "check_")
                || $m->name === "check_all"
                || ($this->regex && !preg_match($this->regex, $m->name))) {
                continue;
            }
            ++$ncheck;
            $mlevel = $this->method_level($m);
            if ($this->list) {
                $lpfx = $lsfx = "";
                if ($mlevel > 0) {
                    $lsfx = " (level {$mlevel})";
                    if ($mlevel > $this->level && $color) {
                        $lpfx = "\x1b[2m";
                        $lsfx .= "\x1b[m";
                    }
                }
                fwrite(STDOUT, $lpfx . substr($m->name, 6) . "{$lsfx}\n");
                continue;
            }
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
        }
        if ($this->regex && $ncheck === 0) {
            fwrite(STDERR, "No matching invariants\n");
            return 1;
        }
        if ($this->list) {
            return 0;
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
        if ((isset($ic->problems["inactive"]) || isset($ic->problems["noninactive"]))
            && $this->want_fix("inactive")) {
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
        if (isset($ic->problems["reviewNeedsSubmit"]) && $this->want_fix("reviews")) {
            $this->report_fix("reviewNeedsSubmit");
            $this->fix_reviewNeedsSubmit();
        }
        if (isset($ic->problems["roles"]) && $this->want_fix("roles")) {
            $this->report_fix("roles");
            $this->fix_roles();
        }
        if (isset($ic->problems["cdbRoles"]) && $this->want_fix("cdbroles")) {
            $this->report_fix("cdbroles");
            $this->fix_cdbroles();
        }
        if (isset($ic->problems["author_contacts"]) && $this->want_fix("authors")) {
            $this->report_fix("author_contacts");
            $this->fix_author_contacts();
        }
        return 0;
    }

    private function fix_inactive_documents() {
        $this->conf->qe("lock tables PaperStorage write, Paper read, DocumentLink read, PaperOption read");

        $this->conf->qe("update PaperStorage set inactive=1");

        $this->conf->qe("update PaperStorage join Paper on (Paper.paperId=PaperStorage.paperId and (Paper.paperStorageId=PaperStorage.paperStorageId or Paper.finalPaperStorageId=PaperStorage.paperStorageId)) set PaperStorage.inactive=0");

        $this->conf->qe("update PaperStorage join DocumentLink on (DocumentLink.documentId=PaperStorage.paperStorageId) set PaperStorage.inactive=0");

        $oids = [];
        foreach ($this->conf->options()->universal() as $o) {
            if ($o->has_document()) {
                $oids[] = $o->id;
            }
        }
        if (!empty($oids)) {
            $this->conf->qe("update PaperStorage join PaperOption on (PaperOption.paperId=PaperStorage.paperId and PaperOption.optionId?a and PaperOption.value=PaperStorage.paperStorageId) set PaperStorage.inactive=0", $oids);
        }

        $this->conf->qe("unlock tables");
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

    private function fix_reviewNeedsSubmit() {
        // reviewNeedsSubmit is defined correctly for secondary
        $result = $this->conf->qe(ConfInvariants::reviewNeedsSubmit_query(REVIEW_SECONDARY));
        while (($row = $result->fetch_row())) {
            $this->conf->update_review_delegation((int) $row[0], (int) $row[2], 0);
        }
        $result->close();

        // reviewNeedsSubmit is defined correctly for others
        $this->conf->qe("update PaperReview
            set reviewNeedsSubmit=if(reviewSubmitted or (reviewType=" . REVIEW_EXTERNAL . " and timeApprovalRequested<0),0,1)
            where reviewType>0 and reviewType!=" . REVIEW_SECONDARY);
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

    private function fix_roles() {
        $this->conf->qe("update ContactInfo set roles=roles&? where (roles&~?)!=0",
            Contact::ROLE_DBMASK, Contact::ROLE_DBMASK);
    }

    private function fix_cdbroles() {
        $confid = $this->conf->cdb_confid();
        $result = Dbl::qe($this->conf->contactdb(), "select contactDbId, email, roles from ContactInfo join Roles using (contactDbId) where confid=?", $confid);
        $cdbr = [];
        while (($row = $result->fetch_row())) {
            $cdbr[strtolower($row[1])] = [(int) $row[0], (int) $row[2]];
        }
        $result->close();

        $fixl = $insc = $delc = $insce = [];
        foreach (ConfInvariants::generate_cdb_roles($this->conf) as $err) {
            if ($err->cdbRoles !== $err->computed_roles) {
                $fixl[$err->computed_roles][] = $err->contactId;
            }
            $x = $cdbr[strtolower($err->email)] ?? [0, 0];
            if ($x[1] !== $err->computed_roles) {
                if ($x[0] === 0) {
                    $insce[strtolower($err->email)] = $err->computed_roles;
                } else if ($err->computed_roles > 0) {
                    $insc[] = [$x[0], $confid, $err->computed_roles];
                } else {
                    $delc[] = $x[0];
                }
            }
        }

        foreach ($fixl as $rolevalue => $cids) {
            $this->conf->qe("update ContactInfo set cdbRoles=? where contactId?a", $rolevalue, $cids);
        }
        if (!empty($insce)) {
            $result = Dbl::qe($this->conf->contactdb(), "select email, contactDbId from ContactInfo where email?a", array_keys($insce));
            while (($row = $result->fetch_row())) {
                $insc[] = [(int) $row[1], $confid, $insce[strtolower($row[0])]];
            }
            $result->close();
        }
        if (!empty($insc)) {
            Dbl::qe($this->conf->contactdb(), "insert into Roles (contactDbId, confid, roles) values ?v ?U on duplicate key update roles=?U(roles)", $insc);
        }
        if (!empty($delc)) {
            Dbl::qe($this->conf->contactdb(), "delete from Roles where contactDbId?a and confid=?", $delc, $confid);
        }
    }

    private function fix_author_contacts() {
        $authors = ConfInvariants::author_lcemail_map($this->conf);

        $result = $this->conf->qe("select email from ContactInfo");
        while (($row = $result->fetch_row())) {
            unset($authors[strtolower($row[0])]);
        }
        $result->close();

        $confs = [];
        foreach ($authors as $email => $pids) {
            $u = $this->conf->ensure_user_by_email($email);
            foreach ($pids as $pid) {
                $confs[] = [$pid, $u->contactId, CONFLICT_AUTHOR];
            }
        }

        $this->conf->qe("insert into PaperConflict values ?v on duplicate key update conflictType=PaperConflict.conflictType|?", $confs, CONFLICT_AUTHOR);
    }

    static function list_fixes() {
        return prefix_word_wrap("", "PROBLEMs for `--fix` include summary, autosearch, inactive, document-match, whitespace, reviews, roles, and cdbroles.", 0);
    }

    /** @return CheckInvariants_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config:,c: !",
            "help,h !",
            "verbose,V Be verbose",
            "level:,l: {n} Set invariant level [0]",
            "list",
            "fix-autosearch ! Repair any incorrect autosearch tags",
            "fix-inactive ! Repair any inappropriately inactive documents",
            "fix[] =PROBLEM Repair PROBLEM",
            "color",
            "no-color !",
            "pad-prefix !"
        )->helpopt("help")
         ->description("Check invariants in a HotCRP database.
Usage: php batch/checkinvariants.php [-n CONFID] [--fix=WHAT] [INVARIANT...]\n")
         ->helpcallback("CheckInvariants_Batch::list_fixes")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new CheckInvariants_Batch($conf, $arg);
    }
}
