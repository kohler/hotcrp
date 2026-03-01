<?php
// mailrecipients.php -- HotCRP mail tool
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class MailRecipientClass {
    /** @var string */
    public $name;
    /** @var ?string */
    public $description;
    /** @var ?string */
    public $limit;
    /** @var int */
    public $flags;
    /** @var string */
    public $default_message;

    /** @param string $name
     * @param ?string $description
     * @param ?string $limit
     * @param int $flags
     * @param ?string $default_message */
    function __construct($name, $description, $limit, $flags, $default_message) {
        $this->name = $name;
        $this->description = $description;
        $this->limit = $limit;
        $this->flags = $flags;
        $this->default_message = $default_message;
    }
}

class MailRecipients extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var ?string */
    private $recipt_default_message;
    /** @var list<MailRecipientClass> */
    private $recipts = [];
    /** @var MailRecipientClass */
    private $rect;
    /** @var ?SearchTerm */
    private $search;
    /** @var ?list<int> */
    private $paper_ids;
    /** @var int */
    public $newrev_since = 0;
    /** @var ?array<int,int> */
    private $_dcounts;
    /** @var ?array{bool,bool,bool} */
    private $_has_dt;
    /** @var ?PaperInfoSet */
    private $_paper_set;

    const F_ANYPC = 0x1;
    const F_GROUP = 0x2;
    const F_HIDE = 0x4;
    const F_NOPAPERS = 0x8;
    const F_ALLCOMPLETEREV = 0x10;
    const F_REV = 0x20;
    const F_REV_COMPLETE = 0x40;
    const F_REV_INCOMPLETE = 0x80;
    const F_REV_NONACCEPTED = 0x100;
    const F_REV_SINCE = 0x200;
    const F_REV_EXT = 0x400;
    const F_REV_PC = 0x800;
    const F_REV_MYREQ = 0x1000;

    const FM_REV = 0x30;
    const FM_REV_SPECIFIC = 0xD0;

    /** @param Contact $user */
    function __construct($user) {
        assert(!!$user->isPC);
        $this->conf = $user->conf;
        $this->user = $user;
        $this->set_ignore_duplicates(true);
        $this->enumerate_recipients();
        $this->rect = $this->recipts[0];
    }

    /** @return \Generator<MessageItem> */
    function decorated_message_list() {
        foreach ($this->message_list() as $mi) {
            yield Mailer::decorated_message($mi);
        }
    }

    private function dcounts() {
        if ($this->_dcounts !== null) {
            return;
        }
        if ($this->user->allow_admin_all()) {
            $result = $this->conf->qe("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
        } else if ($this->user->is_manager()) {
            $psearch = new PaperSearch($this->user, ["q" => "", "t" => "alladmin"]);
            $result = $this->conf->qe("select outcome, count(*) from Paper where timeSubmitted>0 and paperId?a group by outcome", $psearch->paper_ids());
        } else {
            $result = null;
        }
        $this->_dcounts = [];
        $this->_has_dt = [false, false, false];
        while ($result && ($row = $result->fetch_row())) {
            $d = (int) $row[0];
            $this->_dcounts[$d] = (int) $row[1];
            $dt = $d < 0 ? 0 : ($d === 0 ? 1 : 2);
            $this->_has_dt[$dt] = true;
        }
        Dbl::free($result);
    }

    /** @param ?string $t
     * @return ?string */
    function canonical_recipients($t) {
        if ($t === "somedec:yes" || $t === "somedec:no") {
            $this->dcounts();
            $category = $t === "somedec:yes" ? DecisionInfo::CAT_YES : DecisionInfo::CM_NO;
            $dmaxcount = 0;
            $dmaxname = "";
            foreach ($this->conf->decision_set() as $dinfo) {
                if (($dinfo->category & $category) !== 0
                    && ($dcount = $this->_dcounts[$dinfo->id] ?? 0) > $dmaxcount) {
                    $dmaxcount = $dcount;
                    $dmaxname = $dinfo->name;
                }
            }
            if ($dmaxcount > 0) {
                return "dec:{$dmaxname}";
            } else {
                return substr($t, 4);
            }
        } else if ($t === "myuncextrev") {
            return "uncmyextrev";
        }
        return $t ?? "";
    }

    /** @return list<string> */
    function default_messages() {
        $dm = [];
        foreach ($this->recipts as $rec) {
            if ($rec->default_message
                && !in_array($rec->default_message, $dm, true))
                $dm[] = $rec->default_message;
        }
        return $dm;
    }

    /** @return string */
    function current_default_message() {
        return $this->rect->default_message;
    }

    /** @param string $name
     * @param ?string $description
     * @param ?string $limit
     * @param int $flags */
    private function add_recpt($name, $description, $limit = null, $flags = 0) {
        $this->recipts[] = new MailRecipientClass($name, $description, $limit, $flags, $this->recipt_default_message);
    }

    /** @param string $name
     * @param ?string $description */
    private function add_recpt_group($name, $description) {
        $this->add_recpt($name, $description, null, self::F_GROUP);
    }

    private function enumerate_recipients() {
        $user = $this->user;
        assert(!!$user->isPC);

        if ($user->is_manager()) {
            $this->recipt_default_message = "authors";
            $hide = !$this->conf->has_any_submitted();
            $this->add_recpt("s", "Contact authors of submitted papers", "s", $hide ? self::F_HIDE : 0);
            $this->add_recpt("active", "Contact authors of active papers", "active");
            $this->add_recpt("unsub", "Contact authors of unsubmitted papers", "unsub", self::F_HIDE);
            $this->add_recpt("au", "All contact authors", "all");

            $this->dcounts();
            $this->add_recpt_group("bydec_group", "Contact authors by decision");
            foreach ($this->conf->decision_set() as $dec) {
                if ($dec->id !== 0) {
                    $hide = ($this->_dcounts[$dec->id] ?? 0) === 0;
                    $this->add_recpt("dec:{$dec->name}", "Contact authors of " . $dec->name_as(5) . " papers", "dec:{$dec->name}", $hide ? self::F_HIDE : 0);
                }
            }
            $this->add_recpt("dec:yes", "Contact authors of accept-class papers", "dec:yes", $this->_has_dt[2] ? 0 : self::F_HIDE);
            $this->add_recpt("dec:no", "Contact authors of reject-class papers", "dec:no", $this->_has_dt[0] ? 0 : self::F_HIDE);
            $this->add_recpt("dec:none", "Contact authors of undecided papers", "dec:none", $this->_has_dt[1] && ($this->_has_dt[0] || $this->_has_dt[2]) ? 0 : self::F_HIDE);
            $this->add_recpt("dec:any", "Contact authors of decided papers", "dec:any", self::F_HIDE);
            $this->add_recpt_group("bydec_group_end", null);

            $this->recipt_default_message = "reviewers";
            $this->add_recpt_group("rev_group", "Reviewers");

            // XXX this exposes information about PC review assignments
            // for conflicted papers to the chair; not worth worrying about
            if ($user->privChair) {
                $pidw = "true";
            } else {
                $managing = (new PaperSearch($this->user, ["q" => "", "limit" => "actadmin"]))->paper_ids();
                $pidw = empty($managing) ? "false" : "paperId in (" . join(",", $managing) . ")";
            }
            $row = $this->conf->fetch_first_row("select
                exists (select * from PaperReview where reviewType>=" . REVIEW_PC . " and {$pidw}),
                exists (select * from PaperReview where reviewType>0 and reviewType<" . REVIEW_PC . "  and {$pidw}),
                exists (select * from PaperReview where reviewType>=" . REVIEW_PC . " and reviewSubmitted is null and reviewNeedsSubmit!=0 and timeRequested>timeRequestNotified and {$pidw}),
                exists (select * from Paper where timeSubmitted>0 and leadContactId!=0 and {$pidw}),
                exists (select * from Paper where timeSubmitted>0 and shepherdContactId!=0 and {$pidw})");
            list($any_pcrev, $any_extrev, $any_newpcrev, $any_lead, $any_shepherd) = $row;

            $hflag = $any_pcrev || $any_extrev ? 0 : self::F_HIDE;
            $this->add_recpt("rev", "Reviewers", "s", $hflag | self::F_REV);
            $this->add_recpt("crev", "Reviewers with complete reviews", "s", $hflag | self::F_REV | self::F_REV_COMPLETE);
            $this->add_recpt("uncrev", "Reviewers with incomplete reviews", "s", $hflag | self::F_REV | self::F_REV_INCOMPLETE);
            $this->add_recpt("allcrev", "Reviewers with no incomplete reviews", "s", $hflag | self::F_ALLCOMPLETEREV);

            $hflag = ($any_pcrev ? 0 : self::F_HIDE) | self::F_REV | self::F_REV_PC;
            $this->add_recpt("pcrev", "PC reviewers", "s", $hflag);
            $this->add_recpt("uncpcrev", "PC reviewers with incomplete reviews", "s", $hflag | self::F_REV_INCOMPLETE);
            $this->add_recpt("newpcrev", "PC reviewers with new review assignments", "s", $hflag | ($any_newpcrev ? 0 : self::F_HIDE) | self::F_REV_INCOMPLETE | self::F_REV_SINCE);

            $hflag = ($any_extrev ? 0 : self::F_HIDE) | self::F_REV | self::F_REV_EXT;
            $this->add_recpt("extrev", "External reviewers", "s", $hflag);
            $this->add_recpt("uncextrev", "External reviewers with incomplete reviews", "s", $hflag | self::F_REV_INCOMPLETE);
            $this->add_recpt("extrev-not-accepted", "External reviewers with outstanding requests", "s", $hflag | self::F_REV_NONACCEPTED);
            $this->add_recpt_group("rev_group_end", null);
        } else {
            $any_lead = $any_shepherd = 0;
        }

        $this->recipt_default_message = "reviewers";
        $hide = !$this->user->is_requester();
        $this->add_recpt("myextrev", "Your requested reviewers", "req", self::F_ANYPC | ($hide ? self::F_HIDE : 0) | self::F_REV | self::F_REV_EXT | self::F_REV_MYREQ);
        $this->add_recpt("uncmyextrev", "Your requested reviewers with incomplete reviews", "req", self::F_ANYPC | ($hide ? self::F_HIDE : 0) | self::F_REV_INCOMPLETE | self::F_REV_EXT | self::F_REV_MYREQ);

        if ($user->is_manager()) {
            $this->add_recpt("lead", "Discussion leads", "s", $any_lead ? 0 : self::F_HIDE);
            $this->add_recpt("shepherd", "Shepherds", "s", $any_shepherd ? 0 : self::F_HIDE);
        }

        // PC
        $this->recipt_default_message = "pc";
        $tags = [];
        foreach ($this->conf->viewable_user_tags($this->user) as $t) {
            if ($t !== "pc")
                $tags[] = $t;
        }
        if (empty($tags)) {
            $this->add_recpt("pc", "Program committee", null, self::F_ANYPC | self::F_NOPAPERS);
        } else {
            $this->add_recpt_group("pc_group", "Program committee");
            $this->add_recpt("pc", "Program committee", null, self::F_ANYPC | self::F_NOPAPERS);
            foreach ($tags as $t) {
                $this->add_recpt("pc:{$t}", "#{$t} program committee", null, self::F_ANYPC | self::F_NOPAPERS);
            }
            $this->add_recpt_group("pc_group_end", null);
        }

        if ($user->privChair) {
            $this->recipt_default_message = null;
            $this->add_recpt("all", "Active users", null, self::F_NOPAPERS);
        }
    }

    /** @param string $type
     * @return ?string */
    function recipient_description($type) {
        foreach ($this->recipts as $rec) {
            if ($rec->name === $type)
                return $rec->description;
        }
        return null;
    }

    /** @return string */
    function current_fold_classes(Qrequest $qreq) {
        return "fold8" . (!!$qreq->plimit ? "o" : "c")
            . " fold9" . ($this->rect->flags & self::F_NOPAPERS ? "c" : "o")
            . " fold10" . ($this->rect->flags & self::F_REV_SINCE ? "o" : "c");
    }

    /** @return $this */
    function set_search(?PaperSearch $srch) {
        if ($srch
            && ($this->rect->flags & self::F_TESTREVIEW) !== 0
            && $srch->main_term()->about() !== SearchTerm::ABOUT_PAPER) {
            $this->search = $srch->main_term();
        } else {
            $this->search = null;
        }
        return $this;
    }

    /** @param ?list<int> $paper_ids
     * @return $this */
    function set_paper_ids($paper_ids) {
        $this->paper_ids = $paper_ids;
        return $this;
    }

    /** @param ?string $newrev_since
     * @return $this */
    function set_newrev_since($newrev_since) {
        $newrev_since = trim($newrev_since ?? "");
        if ($newrev_since !== ""
            && !preg_match('/\A(?:|n\/a|\(?all\)?|0)\z/i', $newrev_since)) {
            $t = $this->conf->parse_time($newrev_since);
            if ($t === false) {
                $this->error_at("newrev_since", "<0>Invalid date");
            } else {
                $this->newrev_since = $t;
                if ($t > Conf::$now) {
                    $this->warning_at("newrev_since", "<0>That time is in the future");
                }
            }
        } else {
            $this->newrev_since = null;
        }
        return $this;
    }

    /** @param ?string $type
     * @return $this */
    function set_recipients($type) {
        $this->_paper_set = null;
        $type = $this->canonical_recipients($type);
        foreach ($this->recipts as $i => $rec) {
            if ($rec->name === $type && ($rec->flags & self::F_GROUP) === 0) {
                $this->rect = $rec;
                return $this;
            }
        }
        $this->rect = $this->recipts[0];
        if (($type ?? "") !== "") {
            $this->error_at("to", "<0>Invalid recipients");
        }
        return $this;
    }

    /** @return bool */
    function has_paper_ids() {
        return $this->paper_ids !== null;
    }

    /** @return list<int> */
    function paper_ids() {
        return $this->paper_ids ?? [];
    }

    /** @param string $id
     * @return string */
    function recipient_selector_html($id) {
        $sel = [];
        $last = null;
        $lastflags = 0;
        foreach ($this->recipts as $rec) {
            if (($rec->flags & self::F_GROUP) !== 0) {
                if ($rec->description !== null) {
                    $sel[$rec->name] = ["optgroup", $rec->description];
                } else if (($lastflags & self::F_GROUP) !== 0) {
                    unset($sel[$last]);
                } else {
                    $sel[$rec->name] = ["optgroup"];
                }
            } else {
                if (($rec->flags & self::F_HIDE) !== 0
                    && $rec !== $this->rect) {
                    continue;
                }
                $d = [];
                if (isset($rec->description)) {
                    $d["label"] = $rec->description;
                }
                $d["class"] = Ht::add_tokens(
                    $rec->flags & self::F_NOPAPERS ? "mail-want-no-papers" : "",
                    $rec->flags & self::F_REV_SINCE ? "mail-want-since" : ""
                );
                $d["data-default-message"] = $rec->default_message;
                if (isset($rec->limit)) {
                    $d["data-default-limit"] = $rec->limit;
                }
                $sel[$rec->name] = $d;
            }
            $last = $rec->name;
            $lastflags = $rec->flags;
        }
        return Ht::select($id, $sel, $this->rect->name, ["id" => $id, "class" => "uich js-mail-recipients"]);
    }

    /** @return bool */
    function is_authors() {
        return in_array($this->rect->name, ["s", "unsub", "active", "au"], true)
            || str_starts_with($this->rect->name, "dec:");
    }

    /** @return bool */
    function need_papers() {
        return ($this->rect->flags & self::F_NOPAPERS) === 0;
    }

    /** @param bool $paper_sensitive
     * @return int */
    function combination_type($paper_sensitive) {
        if (preg_match('/\A(?:pc|pc:.*|(?:|unc|new)pcrev|lead|shepherd)\z/', $this->rect->name)) {
            return 2;
        } else if ($this->is_authors() || $paper_sensitive) {
            return 1;
        }
        return 0;
    }

    /** @return string */
    function unparse() {
        $t = $this->rect->description;
        if ($this->rect->name === "newpcrev" && $this->newrev_since) {
            $t .= " since " . htmlspecialchars($this->conf->parseableTime($this->newrev_since, false));
        }
        return $t;
    }

    /** @return ?PaperInfoSet */
    function paper_set($force = false) {
        if (!$force && !$this->need_papers()) {
            return null;
        } else if ($this->_paper_set !== null) {
            return $this->_paper_set;
        }

        $options = ["allConflictType" => true];

        // basic limit
        $t = $this->rect->name;
        if ($t === "au") {
            // all authors, no paper restriction
        } else if ($t === "s") {
            $options["finalized"] = true;
            $options["decision"] = ["standard"];
        } else if ($t === "active") {
            $options["active"] = true;
        } else if ($t === "unsub") {
            $options["unsub"] = true;
            $options["active"] = true;
        } else if (str_starts_with($t, "dec:")) {
            $options["finalized"] = true;
            $options["decision"] = [substr($t, 4)];
        } else if ($t === "lead") {
            $options["anyLead"] = $options["reviewSignatures"] = true;
        } else if ($t === "shepherd") {
            $options["anyShepherd"] = $options["reviewSignatures"] = true;
        } else {
            $options["reviewSignatures"] = true;
            $options["decision"] = ["standard"]; // skip desk rejects (???)
        }

        // additional manager limit
        $need_filter = false;
        if (!$this->user->privChair
            && ($this->rect->flags & self::F_ANYPC) === 0) {
            if (!$this->user->is_track_manager()) {
                $options["myManaged"] = true;
            } else if (($mtt = $this->user->managed_track_tags()) !== null) {
                $tsm = (new TagSearchMatcher($this->user))->add_tag_list($mtt);
                $options["where"] = $tsm->exists_sqlexpr("Paper") . " or managerContactId={$this->user->contactId}";
            } else {
                $need_filter = true;
            }
        }

        if (!($this->rect->flags & self::F_NOPAPERS)
            && $this->paper_ids !== null) {
            $options["paperId"] = $this->paper_ids;
        }

        // load paper set
        $this->_paper_set = $this->conf->paper_set($options, $this->user);
        if ($need_filter) {
            $this->_paper_set->apply_filter(function ($p) {
                return $this->user->allow_admin($p);
            });
        }
        return $this->_paper_set;
    }

    /** @param int $pid
     * @return ?PaperInfo */
    function paper($pid) {
        if ($pid <= 0) {
            return null;
        }
        return $this->paper_set(true)->get($pid);
    }

    /** @param PaperInfo $prow
     * @param Contact $user
     * @return bool */
    function test_paper($prow, $user) {
        if (!$this->search) {
            return true;
        }
        foreach ($prow->reviews_by_user($user) as $rrow) {
            if ($this->user->can_view_review_identity($prow, $rrow)
                && $this->search->test($prow, $rrow))
                return true;
        }
        return false;
    }

    /** @param PaperInfo $prow
     * @param ReviewInfo $rrow
     * @return bool */
    function test_for_assignment_keyword($prow, $rrow) {
        if ($rrow->is_ghost()
            || !$this->user->can_view_review_identity($prow, $rrow)) {
            return false;
        }
        $rf = $this->rect->flags;
        if (($rf & self::F_REV) === 0) {
            return true;
        }
        if ((($rf & self::F_REV_COMPLETE)
             && ($rrow->reviewSubmitted ?? 0) <= 0)
            || (($rf & self::F_REV_INCOMPLETE)
                && ($rrow->reviewSubmitted !== null
                    || $rrow->reviewNeedsSubmit == 0
                    || $prow->timeSubmitted <= 0))
            || (($rf & self::F_REV_SINCE)
                && ($rrow->timeRequested <= $rrow->timeRequestNotified
                    || ($this->newrev_since
                        && $rrow->timeRequested < $this->newrev_since)))
            || (($rf & self::FM_REV_SPECIFIC) === 0
                && $prow->timeSubmitted <= 0
                && ($rrow->reviewSubmitted ?? 0) <= 0)
            || (($rf & self::F_REV_EXT)
                && $rrow->reviewType !== REVIEW_EXTERNAL)
            || (($rf & self::F_REV_MYREQ)
                && $rrow->requestedBy !== $this->user->contactId)
            || (($rf & self::F_REV_PC)
                && $rrow->reviewType <= REVIEW_PC)
            || (($rf & self::F_REV_NONACCEPTED)
                && $rrow->reviewModified > 0)) {
            return false;
        }
        return true;
    }

    /** @param bool $paper_sensitive
     * @return string|false */
    function query($paper_sensitive) {
        $cols = [];
        $where = ["(cflags&" . Contact::CFM_DISABLEMENT . ")=0"];
        $joins = ["ContactInfo"];
        $t = $this->rect->name;
        $rf = $this->rect->flags;

        // build query
        if ($t === "all") {
            $needpaper = false;
            $where[] = "(ContactInfo.roles!=0 or lastLogin>0 or exists (select * from PaperConflict where contactId=ContactInfo.contactId) or exists (select * from PaperReview where contactId=ContactInfo.contactId and reviewType>0))";
        } else if ($t === "pc" || str_starts_with($t, "pc:")) {
            $needpaper = false;
            $where[] = "(ContactInfo.roles&" . Contact::ROLE_PC . ")!=0";
            if ($t != "pc") {
                $x = sqlq(Dbl::escape_like(substr($t, 3)));
                $where[] = "ContactInfo.contactTags like " . Dbl::utf8ci("'% {$x}#%'");
            }
        } else if ($rf & self::FM_REV) {
            $needpaper = true;
            $joins[] = "join Paper";
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=ContactInfo.contactId and PaperReview.reviewType>0)";
            $where[] = "Paper.paperId=PaperReview.paperId";
        } else if ($t === "lead" || $t === "shepherd") {
            $needpaper = true;
            $joins[] = "join Paper on (Paper.{$t}ContactId=ContactInfo.contactId)";
        } else {
            $needpaper = true;
            $joins[] = "join Paper";
            $joins[] = "join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=ContactInfo.contactId)";
            $where[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
        }

        $paper_set = $this->paper_set();
        assert(!!$paper_set === $needpaper);
        if ($needpaper && $paper_set) {
            $where[] = "Paper.paperId" . sql_in_int_list($paper_set->paper_ids());
        }

        // reviewer match
        if ($rf & self::FM_REV) {
            // Submission status
            if ($rf & self::F_REV_COMPLETE) {
                $where[] = "PaperReview.reviewSubmitted>0";
            } else if ($rf & self::F_REV_INCOMPLETE) {
                $where[] = "PaperReview.reviewSubmitted is null and PaperReview.reviewNeedsSubmit!=0 and Paper.timeSubmitted>0";
            } else {
                $where[] = "(PaperReview.rflags&" . ReviewInfo::RF_LIVE . ")!=0";
            }
            if ($rf & self::F_REV_SINCE) {
                $where[] = "PaperReview.timeRequested>PaperReview.timeRequestNotified";
                if ($this->newrev_since) {
                    $where[] = "PaperReview.timeRequested>={$this->newrev_since}";
                }
            }
            if ($rf & self::F_ALLCOMPLETEREV) {
                $joins[] = "left join (select contactId, max(if(reviewNeedsSubmit!=0 and timeSubmitted>0,1,0)) anyReviewNeedsSubmit from PaperReview join Paper on (Paper.paperId=PaperReview.paperId) group by contactId) AllReviews on (AllReviews.contactId=ContactInfo.contactId)";
                $where[] = "AllReviews.anyReviewNeedsSubmit=0";
            }
            // Not accepted
            if ($rf & self::F_REV_NONACCEPTED) {
                $where[] = "PaperReview.reviewModified=0";
            }
            // Withdrawn papers may not count
            if (($rf & self::FM_REV_SPECIFIC) === 0) {
                $where[] = "(Paper.timeSubmitted>0 or PaperReview.reviewSubmitted>0)";
            }
            // Review type
            if ($rf & self::F_REV_EXT) {
                $where[] = "PaperReview.reviewType=" . REVIEW_EXTERNAL;
            } else if ($rf & self::F_REV_PC) {
                $where[] = "PaperReview.reviewType>" . REVIEW_EXTERNAL;
            }
            if ($rf & self::F_REV_MYREQ) {
                $where[] = "PaperReview.requestedBy=" . $this->user->contactId;
            }
        }

        // query construction
        $q = "select " . $this->conf->user_query_fields(Contact::SLICE_MINIMAL - Contact::SLICEBIT_PASSWORD, "ContactInfo.")
            . ", preferredEmail, "
            . ($needpaper ? "Paper.paperId" : "-1") . " paperId
            from " . join("\n", $joins)
            . "\nwhere " . join("\n    and ", $where)
            . "\ngroup by ContactInfo.contactId" . ($needpaper ? ", Paper.paperId" : "")
            . "\norder by ";
        if (!$needpaper) {
            $q .= "email";
        } else if ($this->is_authors() || $paper_sensitive) {
            $q .= "Paper.paperId, email";
        } else {
            $q .= "email, Paper.paperId";
        }
        return $q;
    }
}
