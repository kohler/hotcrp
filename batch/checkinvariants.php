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
    /** @var bool */
    public $quiet;
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
    public $limit;
    /** @var int */
    private $width = 47;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->verbose = isset($arg["verbose"]);
        $this->quiet = !$this->verbose && isset($arg["quiet"]);
        $this->fix = $arg["fix"] ?? [];
        $this->level = $arg["level"] ?? 0;
        $this->limit = $arg["limit"] ?? 1;
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
        if (!$this->quiet) {
            $fix = $this->color ? " \x1b[01;36mFIX\x1b[m\n" : " FIX\n";
            fwrite(STDERR,
                str_pad("{$this->conf->dbname}: {$report} ", $this->width, ".")
                . $fix);
        }
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
        $ic = (new ConfInvariants($this->conf))
            ->set_level($this->level)
            ->set_limit($this->limit);
        $ncheck = 0;
        $ro = new ReflectionObject($ic);
        $color = $this->color = $this->color ?? posix_isatty(STDERR);
        $ic->set_color($this->color);
        if ($this->verbose || $this->quiet) {
            $ic->buffer_messages();
        }
        $dbname = $this->conf->dbname;
        $mpfx = "";

        $all = [];
        $match = [];
        foreach ($ro->getMethods() as $m) {
            if ($m->name === "check_all") {
                continue;
            }
            if (str_starts_with($m->name, "check_")) {
                $all[] = $m;
            } else if (!$this->regex || !str_starts_with($m->name, "alias_")) {
                continue;
            }
            if ($this->regex && preg_match($this->regex, $m->name)) {
                $match[] = $m;
            }
        }

        foreach ($this->regex ? $match : $all as $m) {
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
            }

            $ic->{$m->name}();

            if ($this->verbose) {
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
            } else if ($this->quiet) {
                $ic->take_buffered_messages();
            }
        }
        if ($this->regex && $ncheck === 0) {
            if (!$this->quiet) {
                fwrite(STDERR, "No matching invariants\n");
            }
            return 1;
        }
        if ($this->list) {
            return 0;
        }

        if ($ic->has_problem("no_papersub") && $this->want_fix("summary")) {
            $this->report_fix("`no_papersub` summary setting");
            $this->conf->update_papersub_setting(0);
            $ic->resolve_problem("no_papersub");
        }
        if ($ic->has_problem("paperacc") && $this->want_fix("summary")) {
            $this->report_fix("`paperacc` summary setting");
            $this->conf->update_paperacc_setting(0);
            $ic->resolve_problem("paperacc");
        }
        if ($ic->has_problem("rev_tokens") && $this->want_fix("summary")) {
            $this->report_fix("`rev_tokens` summary setting");
            $this->conf->update_rev_tokens_setting(0);
            $ic->resolve_problem("rev_tokens");
        }
        if ($ic->has_problem("paperlead") && $this->want_fix("summary")) {
            $this->report_fix("`paperlead` summary setting");
            $this->conf->update_paperlead_setting(0);
            $ic->resolve_problem("paperlead");
        }
        if ($ic->has_problem("papermanager") && $this->want_fix("summary")) {
            $this->report_fix("`papermanager` summary setting");
            $this->conf->update_papermanager_setting(0);
            $ic->resolve_problem("papermanager");
        }
        if ($ic->has_problem("metareviews") && $this->want_fix("summary")) {
            $this->report_fix("`metareviews` summary setting");
            $this->conf->update_metareviews_setting(0);
            $ic->resolve_problem("metareviews");
        }
        if ($ic->has_problem("autosearch") && $this->want_fix("autosearch")) {
            $this->report_fix("automatic tags");
            $this->conf->update_automatic_tags();
            $ic->resolve_problem("autosearch");
        }
        if (($ic->has_problem("inactive") || $ic->has_problem("noninactive"))
            && $this->want_fix("inactive")) {
            $this->report_fix("inactive documents");
            $this->fix_inactive_documents();
            $ic->resolve_problem("inactive");
            $ic->resolve_problem("noninactive");
        }
        if ($ic->has_problem("paper_denormalization") && $this->want_fix("document-match")) {
            $this->report_fix("document match");
            $this->fix_document_match();
            $ic->resolve_problem("paper_denormalization");
        }
        if ($ic->has_problem("user_whitespace") && $this->want_fix("whitespace")) {
            $this->report_fix("whitespace");
            $this->fix_whitespace();
            $ic->resolve_problem("user_whitespace");
        }
        if ($ic->has_problem("reviewNeedsSubmit") && $this->want_fix("reviews")) {
            $this->report_fix("reviewNeedsSubmit");
            $this->fix_reviewNeedsSubmit();
            $ic->resolve_problem("reviewNeedsSubmit");
        }
        if ($ic->has_problem("roles") && $this->want_fix("roles")) {
            $this->report_fix("roles");
            $this->fix_roles();
            $ic->resolve_problem("roles");
        }
        if ($ic->has_problem("cdbRoles") && $this->want_fix("cdbroles")) {
            $this->report_fix("cdbroles");
            $this->fix_cdbroles();
            $ic->resolve_problem("cdbRoles");
        }
        if ($ic->has_problem("author_conflicts") && $this->want_fix("authors")) {
            $this->report_fix("author_conflicts");
            $this->fix_author_conflicts();
            $ic->resolve_problem("author_conflicts");
        }
        if ($this->want_fix("unnamed_authors")) {
            $this->report_fix("unnamed_authors");
            $this->fix_unnamed_authors();
        }
        return $ic->ok() ? 0 : 1;
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

    /** @suppress PhanAccessReadOnlyProperty */
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

    private function fix_author_conflicts() {
        // fix CONFLICT_AUTHOR
        $paus = ConfInvariants::author_lcemail_map($this->conf);

        $caus = [];
        $result = $this->conf->qe("select email, group_concat(paperId)
            from ContactInfo join PaperConflict using (contactId)
            where (conflictType&?)!=0
            group by ContactInfo.contactId",
            CONFLICT_AUTHOR);
        while (($row = $result->fetch_row())) {
            $lemail = strtolower($row[0]);
            $caus[$lemail] = explode(",", $row[1]);
            if (!isset($paus[$lemail])) {
                $paus[$lemail] = [];
            }
        }
        $result->close();

        $stager = Dbl::make_multi_qe_stager($this->conf->dblink);
        foreach ($paus as $lemail => $ppids) {
            $cpids = $caus[$lemail] ?? [];
            $d1 = array_diff($ppids, $cpids);
            if (empty($d1) && count($ppids) === count($cpids)) {
                continue;
            }
            $u = $this->conf->ensure_user_by_email($lemail);
            foreach ($d1 as $pid) {
                $stager("insert into PaperConflict set paperId=?, contactId=?, conflictType=? on duplicate key update conflictType=conflictType|?",
                    $pid, $u->contactId, CONFLICT_AUTHOR, CONFLICT_AUTHOR);
            }
            foreach (array_diff($cpids, $ppids) as $pid) {
                $stager("update PaperConflict set conflictType=(conflictType&~?)|? where paperId=? and contactId=?",
                    CONFLICT_AUTHOR, CONFLICT_CONTACTAUTHOR, $pid, $u->contactId);
            }
        }
        $stager(null);

        // fix CONFLICT_CONTACTAUTHOR: add to primary
        $deluids = [];
        $ins = [];
        $result = $this->conf->qe("select contactId, primaryContactId, group_concat(paperId)
            from ContactInfo join PaperConflict using (contactId)
            where primaryContactId>0 and (conflictType&?)!=0
            group by ContactInfo.contactId",
            CONFLICT_CONTACTAUTHOR);
        while (($row = $result->fetch_row())) {
            $puid = (int) $row[1];
            foreach (explode(",", $row[2]) as $pid) {
                $ins[] = [(int) $pid, $puid, CONFLICT_CONTACTAUTHOR];
            }
        }
        $result->close();

        if (!empty($ins)) {
            $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType)
                values ?v
                on duplicate key update conflictType=conflictType|?",
                $ins, CONFLICT_CONTACTAUTHOR);
        }
    }

    private function fix_unnamed_authors() {
        $result = $this->conf->qe("select " . $this->conf->user_query_fields(0) . " from ContactInfo where firstName='' and lastName='' and affiliation='' and exists (select * from PaperConflict pc where pc.contactId=ContactInfo.contactId and (pc.conflictType&" . CONFLICT_AUTHOR . ")!=0)");
        while (($user = Contact::fetch($result, $this->conf))) {
            if (($cdbu = $user->cdb_user())) {
                $user->set_prop("firstName", $cdbu->firstName, 2);
                $user->set_prop("lastName", $cdbu->lastName, 2);
                $user->set_prop("affiliation", $cdbu->affiliation, 2);
            }
            foreach ($user->authored_papers() as $prow) {
                if (($au = $prow->author_by_email($user->email))) {
                    $user->set_prop("firstName", $au->firstName, 2);
                    $user->set_prop("lastName", $au->lastName, 2);
                    $user->set_prop("affiliation", $au->affiliation, 2);
                }
            }
            if ($user->prop_changed()) {
                fwrite(STDERR, ". {$user->email}: {$user->firstName} {$user->lastName} ({$user->affiliation})\n");
                $user->save_prop();
                $user->export_prop(1);
            }
        }
        $result->close();
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
            "quiet,q,silent Do not print error messages",
            "level:,l: {n} Set invariant level [0]",
            "limit: =N {n} Limit to N errors per type [1]",
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
