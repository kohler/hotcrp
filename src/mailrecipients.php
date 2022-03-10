<?php
// mailrecipients.php -- HotCRP mail tool
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class MailRecipients extends MessageSet {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var string */
    private $type;
    /** @var ?list<int> */
    private $paper_ids;
    /** @var int */
    public $newrev_since = 0;
    /** @var ?array<int,int> */
    private $_dcounts;
    /** @var ?array{bool,bool,bool} */
    private $_has_dt;
    /** @var array<string,string> */
    private $sel = [];
    /** @var array<string,int> */
    private $selflags = [];

    const F_ANYPC = 1;
    const F_GROUP = 2;
    const F_HIDE = 4;
    const F_NOPAPERS = 8;
    const F_SINCE = 16;

    private function defsel($name, $description, $flags = 0) {
        assert(!isset($this->sel[$name]));
        $this->sel[$name] = $description;
        $this->selflags[$name] = $flags;
    }

    /** @param Contact $user */
    function __construct($user) {
        assert(!!$user->isPC);
        $this->conf = $user->conf;
        $this->user = $user;
        $this->set_ignore_duplicates(true);
    }

    /** @return bool */
    function has_paper_ids() {
        return $this->paper_ids !== null;
    }

    /** @return list<int> */
    function paper_ids() {
        return $this->paper_ids ?? [];
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
                $this->error_at("newrev_since", "Invalid date.");
            } else {
                $this->newrev_since = $t;
                if ($t > Conf::$now) {
                    $this->warning_at("newrev_since", "That time is in the future.");
                }
            }
        } else {
            $this->newrev_since = null;
        }
        return $this;
    }

    private function dcounts() {
        if ($this->_dcounts === null) {
            if ($this->user->allow_administer_all()) {
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
    }

    /** @param ?string $t
     * @return ?string */
    function canonical_recipients($t) {
        if ($t === "somedec:yes" || $t === "somedec:no") {
            $this->dcounts();
            $wantyes = $t === "somedec:yes";
            $dmaxcount = 0;
            $dmaxname = "";
            foreach ($this->_dcounts as $outcome => $n) {
                if ($n > 0
                    && ($wantyes ? $outcome > 0 : $outcome < 0)
                    && ($dname = $this->conf->decision_name($outcome))) {
                    if ($n > $dmaxcount
                        || ($n === $dmaxcount && $this->conf->collator()->compare($dname, $dmaxname) < 0)) {
                        $dmaxcount = $n;
                        $dmaxname = $dname;
                    }
                }
            }
            if ($dmaxcount > 0) {
                return "dec:{$dmaxname}";
            } else {
                return substr($t, 4);
            }
        } else if ($t === "myuncextrev") {
            return "uncmyextrev";
        } else {
            return $t ?? "";
        }
    }

    /** @param ?string $type
     * @return $this */
    function set_recipients($type) {
        $user = $this->user;
        $this->type = $this->canonical_recipients($type);

        assert(!!$user->isPC);
        $any_pcrev = $any_extrev = 0;
        $any_newpcrev = $any_lead = $any_shepherd = 0;

        if ($user->is_manager()) {
            $hide = !$this->conf->has_any_submitted();
            $this->defsel("s", "Contact authors of submitted papers", $hide ? self::F_HIDE : 0);
            $this->defsel("unsub", "Contact authors of unsubmitted papers");
            $this->defsel("au", "All contact authors");

            $this->dcounts();
            $this->defsel("bydec_group", "Contact authors by decision", self::F_GROUP);
            foreach ($this->conf->decision_map() as $dnum => $dname) {
                if ($dnum) {
                    $hide = ($this->_dcounts[$dnum] ?? 0) === 0;
                    $this->defsel("dec:$dname", "Contact authors of " . htmlspecialchars($dname) . " papers", $hide ? self::F_HIDE : 0);
                }
            }
            $this->defsel("dec:yes", "Contact authors of accept-class papers", $this->_has_dt[2] ? 0 : self::F_HIDE);
            $this->defsel("dec:no", "Contact authors of reject-class papers", $this->_has_dt[0] ? 0 : self::F_HIDE);
            $this->defsel("dec:none", "Contact authors of undecided papers", $this->_has_dt[1] && ($this->_has_dt[0] || $this->_has_dt[2]) ? 0 : self::F_HIDE);
            $this->defsel("dec:any", "Contact authors of decided papers", self::F_HIDE);
            $this->defsel("bydec_group_end", null, self::F_GROUP);

            $this->defsel("rev_group", "Reviewers", self::F_GROUP);

            // XXX this exposes information about PC review assignments
            // for conflicted papers to the chair; not worth worrying about
            if (!$user->privChair) {
                $pids = [];
                $result = $this->conf->qe("select paperId from Paper where managerContactId=?", $user->contactId);
                while (($row = $result->fetch_row())) {
                    $pids[] = (int) $row[0];
                }
                Dbl::free($result);
                $pidw = empty($pids) ? "false" : "paperId in (" . join(",", $pids) . ")";
            } else {
                $pidw = "true";
            }
            $row = $this->conf->fetch_first_row("select
                exists (select * from PaperReview where reviewType>=" . REVIEW_PC . " and $pidw),
                exists (select * from PaperReview where reviewType>0 and reviewType<" . REVIEW_PC . "  and $pidw),
                exists (select * from PaperReview where reviewType>=" . REVIEW_PC . " and reviewSubmitted is null and reviewNeedsSubmit!=0 and timeRequested>timeRequestNotified and $pidw),
                exists (select * from Paper where timeSubmitted>0 and leadContactId!=0 and $pidw),
                exists (select * from Paper where timeSubmitted>0 and shepherdContactId!=0 and $pidw)");
            list($any_pcrev, $any_extrev, $any_newpcrev, $any_lead, $any_shepherd) = $row;

            $hide = $any_pcrev || $any_extrev ? 0 : self::F_HIDE;
            $this->defsel("rev", "Reviewers", $hide);
            $this->defsel("crev", "Reviewers with complete reviews", $hide);
            $this->defsel("uncrev", "Reviewers with incomplete reviews", $hide);
            $this->defsel("allcrev", "Reviewers with no incomplete reviews", $hide);

            $hide = $any_pcrev ? 0 : self::F_HIDE;
            $this->defsel("pcrev", "PC reviewers", $hide);
            $this->defsel("uncpcrev", "PC reviewers with incomplete reviews", $hide);
            $this->defsel("newpcrev", "PC reviewers with new review assignments", ($any_newpcrev && $any_pcrev ? 0 : self::F_HIDE) | self::F_SINCE);

            $hide = $any_extrev ? 0 : self::F_HIDE;
            $this->defsel("extrev", "External reviewers", $hide);
            $this->defsel("uncextrev", "External reviewers with incomplete reviews", $hide);
            $this->defsel("rev_group_end", null, self::F_GROUP);
        }

        $hide = !$this->user->is_requester();
        $this->defsel("myextrev", "Your requested reviewers", self::F_ANYPC | ($hide ? self::F_HIDE : 0));
        $this->defsel("uncmyextrev", "Your requested reviewers with incomplete reviews", self::F_ANYPC | ($hide ? self::F_HIDE : 0));

        if ($user->is_manager()) {
            $this->defsel("lead", "Discussion leads", $any_lead ? 0 : self::F_HIDE);
            $this->defsel("shepherd", "Shepherds", $any_shepherd ? 0 : self::F_HIDE);
        }

        $this->defsel("pc_group", "Program committee", self::F_GROUP);
        $selcount = count($this->sel);
        $this->defsel("pc", "Program committee", self::F_ANYPC | self::F_NOPAPERS);
        foreach ($this->conf->viewable_user_tags($this->user) as $t) {
            if ($t !== "pc")
                $this->defsel("pc:$t", "#$t program committee", self::F_ANYPC | self::F_NOPAPERS);
        }
        if (count($this->sel) == $selcount + 1) {
            unset($this->sel["pc_group"]);
        } else {
            $this->defsel("pc_group_end", null, self::F_GROUP);
        }

        if ($user->privChair) {
            $this->defsel("all", "Active users", self::F_NOPAPERS);
        }

        if (isset($this->sel[$type])
            && !($this->selflags[$type] & self::F_GROUP)) {
            $this->type = $type;
        } else {
            $this->type = key($this->sel);
            if ($type !== null && $type !== "") {
                $this->error_at("to", "Invalid recipients.");
            }
        }

        return $this;
    }

    function selectors() {
        $sel = [];
        $last = null;
        foreach ($this->sel as $n => $d) {
            $flags = $this->selflags[$n];
            if ($flags & self::F_GROUP) {
                if ($d !== null) {
                    $sel[$n] = ["optgroup", $d];
                } else if ($last !== null
                           && ($this->selflags[$last] & self::F_GROUP)) {
                    unset($sel[$last]);
                } else {
                    $sel[$n] = ["optgroup"];
                }
            } else if (!($flags & self::F_HIDE) || $n == $this->type) {
                if (is_string($d)) {
                    $d = ["label" => $d];
                }
                $k = [];
                if ($flags & self::F_NOPAPERS) {
                    $k[] = "mail-want-no-papers";
                }
                if ($flags & self::F_SINCE) {
                    $k[] = "mail-want-since";
                }
                if (!empty($k)) {
                    $d["class"] = join(" ", $k);
                }
                $sel[$n] = $d;
            } else {
                continue;
            }
            $last = $n;
        }
        return Ht::select("to", $sel, $this->type, ["id" => "to", "class" => "uich js-mail-recipients"]);
    }

    /** @return string */
    function unparse() {
        $t = $this->sel[$this->type];
        if ($this->type == "newpcrev" && $this->newrev_since) {
            $t .= " since " . htmlspecialchars($this->conf->parseableTime($this->newrev_since, false));
        }
        return $t;
    }

    /** @return bool */
    function is_authors() {
        return in_array($this->type, ["s", "unsub", "au"])
            || str_starts_with($this->type, "dec:");
    }

    /** @return bool */
    function need_papers() {
        return $this->type !== "pc"
            && substr($this->type, 0, 3) !== "pc:"
            && $this->type !== "all";
    }

    /** @param bool $paper_sensitive
     * @return int */
    function combination_type($paper_sensitive) {
        if (preg_match('/\A(?:pc|pc:.*|(?:|unc|new)pcrev|lead|shepherd)\z/', $this->type)) {
            return 2;
        } else if ($this->is_authors() || $paper_sensitive) {
            return 1;
        } else {
            return 0;
        }
    }

    /** @return ?PaperInfoSet */
    function paper_set() {
        $options = ["allConflictType" => true];

        // basic limit
        if ($this->type === "au") {
            // all authors, no paper restriction
        } else if ($this->type === "s") {
            $options["finalized"] = true;
        } else if ($this->type === "unsub") {
            $options["unsub"] = $options["active"] = true;
        } else if ($this->type === "dec:any") {
            $options["finalized"] = $options["decided"] = true;
        } else if ($this->type === "dec:none") {
            $options["finalized"] = $options["undecided"] = true;
        } else if ($this->type === "dec:yes") {
            $options["finalized"] = $options["accepted"] = true;
        } else if ($this->type === "dec:no") {
            $options["finalized"] = $options["rejected"] = true;
        } else if (substr($this->type, 0, 4) === "dec:") {
            $options["finalized"] = true;
            $options["where"] = "false";
            foreach ($this->conf->decision_map() as $dnum => $dname) {
                if (strcasecmp($dname, substr($this->type, 4)) === 0) {
                    $options["where"] = "Paper.outcome={$dnum}";
                    break;
                }
            }
        } else if ($this->type === "lead") {
            $options["anyLead"] = $options["reviewSignatures"] = true;
        } else if ($this->type === "shepherd") {
            $options["anyShepherd"] = $options["reviewSignatures"] = true;
        } else if (str_ends_with($this->type, "rev")) {
            $options["reviewSignatures"] = true;
        } else {
            assert(!$this->need_papers());
            return null;
        }

        // additional manager limit
        $paper_ids = $this->paper_ids;
        if (!$this->user->privChair
            && !($this->selflags[$this->type] & self::F_ANYPC)) {
            if ($this->conf->check_any_admin_tracks($this->user)) {
                $ps = new PaperSearch($this->user, ["q" => "", "t" => "admin"]);
                if ($paper_ids === null) {
                    $paper_ids = $ps->paper_ids();
                } else {
                    $paper_ids = array_values(array_intersect($paper_ids, $ps->paper_ids()));
                }
            } else {
                $options["myManaged"] = true;
            }
        }
        if ($paper_ids !== null) {
            $options["paperId"] = $paper_ids;
        }

        // load paper set
        return $this->conf->paper_set($options, $this->user);
    }

    /** @param ?PaperInfoSet $paper_set
     * @param bool $paper_sensitive
     * @return string|false */
    function query($paper_set, $paper_sensitive) {
        $cols = [];
        $where = ["not disabled"];
        $joins = ["ContactInfo"];

        // reviewer limit
        if (!preg_match('/\A(new|unc|c|allc|)(pc|ext|myext|)rev\z/',
                        $this->type, $revmatch)) {
            $revmatch = false;
        }

        // build query
        if ($this->type === "all") {
            $needpaper = false;
            $where[] = "(ContactInfo.roles!=0 or lastLogin>0 or exists (select * from PaperConflict where contactId=ContactInfo.contactId) or exists (select * from PaperReview where contactId=ContactInfo.contactId and reviewType>0))";
        } else if ($this->type === "pc" || substr($this->type, 0, 3) === "pc:") {
            $needpaper = false;
            $where[] = "(ContactInfo.roles&" . Contact::ROLE_PC . ")!=0";
            if ($this->type != "pc") {
                $x = sqlq(Dbl::escape_like(substr($this->type, 3)));
                $where[] = "ContactInfo.contactTags like " . Dbl::utf8ci("'% {$x}#%'");
            }
        } else if ($revmatch) {
            $needpaper = true;
            $joins[] = "join Paper";
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=ContactInfo.contactId and PaperReview.reviewType>0)";
            $where[] = "Paper.paperId=PaperReview.paperId";
        } else if ($this->type === "lead" || $this->type === "shepherd") {
            $needpaper = true;
            $joins[] = "join Paper on (Paper.{$this->type}ContactId=ContactInfo.contactId)";
        } else {
            $needpaper = true;
            $joins[] = "join Paper";
            $joins[] = "join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=ContactInfo.contactId)";
            $where[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
        }

        assert(!!$paper_set === $needpaper);
        if ($paper_set) {
            $where[] = "Paper.paperId" . sql_in_int_list($paper_set->paper_ids());
        }

        // reviewer match
        if ($revmatch) {
            // Submission status
            if ($revmatch[1] === "c") {
                $where[] = "PaperReview.reviewSubmitted>0";
            } else if ($revmatch[1] === "unc" || $revmatch[1] === "new") {
                $where[] = "PaperReview.reviewSubmitted is null and PaperReview.reviewNeedsSubmit!=0 and Paper.timeSubmitted>0";
            }
            if ($revmatch[1] === "new") {
                $where[] = "PaperReview.timeRequested>PaperReview.timeRequestNotified";
                if ($this->newrev_since) {
                    $where[] = "PaperReview.timeRequested>=$this->newrev_since";
                }
            }
            if ($revmatch[1] === "allc") {
                $joins[] = "left join (select contactId, max(if(reviewNeedsSubmit!=0 and timeSubmitted>0,1,0)) anyReviewNeedsSubmit from PaperReview join Paper on (Paper.paperId=PaperReview.paperId) group by contactId) AllReviews on (AllReviews.contactId=ContactInfo.contactId)";
                $where[] = "AllReviews.anyReviewNeedsSubmit=0";
            }
            // Withdrawn papers may not count
            if ($revmatch[1] === "") {
                $where[] = "(Paper.timeSubmitted>0 or PaperReview.reviewSubmitted>0)";
            }
            // Review type
            if ($revmatch[2] === "myext") {
                $where[] = "PaperReview.reviewType=" . REVIEW_EXTERNAL;
                $where[] = "PaperReview.requestedBy=" . $this->user->contactId;
            } else if ($revmatch[2] === "ext") {
                $where[] = "PaperReview.reviewType=" . REVIEW_EXTERNAL;
            } else if ($revmatch[2] === "pc") {
                $where[] = "PaperReview.reviewType>" . REVIEW_EXTERNAL;
            }
        }

        // query construction
        $q = "select ContactInfo.contactId, firstName, lastName, affiliation,
            email, roles, contactTags, disabled, primaryContactId, 3 _slice,
            password, preferredEmail, "
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
