<?php
// mailclasses.php -- HotCRP mail tool
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class MailRecipients {
    private $conf;
    private $contact;
    private $type;
    private $sel = [];
    private $selflags = [];
    private $papersel;
    public $newrev_since = 0;
    public $error = false;

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

    function __construct($contact, $type, $papersel, $newrev_since) {
        global $Now;
        $this->conf = $contact->conf;
        $this->contact = $contact;
        assert(!!$contact->isPC);
        $any_pcrev = $any_extrev = 0;
        $any_newpcrev = $any_lead = $any_shepherd = 0;

        if ($contact->is_manager()) {
            $hide = !$this->conf->has_any_submitted();
            $this->defsel("s", "Contact authors of submitted papers", $hide ? self::F_HIDE : 0);
            $this->defsel("unsub", "Contact authors of unsubmitted papers");
            $this->defsel("au", "All contact authors");

            // map "somedec:no"/"somedec:yes" to real decisions
            $result = $this->conf->qe("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
            $dec_pcount = edb_map($result);
            $dec_tcount = array(0 => 0, 1 => 0, -1 => 0);
            foreach ($dec_pcount as $dnum => $dcount)
                $dec_tcount[$dnum > 0 ? 1 : ($dnum < 0 ? -1 : 0)] += $dcount;
            if ($type == "somedec:no" || $type == "somedec:yes") {
                $dmaxcount = -1;
                foreach ($dec_pcount as $dnum => $dcount)
                    if (($type[8] == "n" ? $dnum < 0 : $dnum > 0)
                        && $dcount > $dmaxcount
                        && ($dname = $this->conf->decision_name($dnum))) {
                        $type = "dec:$dname";
                        $dmaxcount = $dcount;
                    }
            }

            $this->defsel("bydec_group", "Contact authors by decision", self::F_GROUP);
            foreach ($this->conf->decision_map() as $dnum => $dname)
                if ($dnum) {
                    $k = "dec:$dname";
                    $hide = !get($dec_pcount, $dnum);
                    $this->defsel("dec:$dname", "Contact authors of " . htmlspecialchars($dname) . " papers", $hide ? self::F_HIDE : 0);
                }
            $this->defsel("dec:yes", "Contact authors of accept-class papers", $dec_tcount[1] == 0 ? self::F_HIDE : 0);
            $this->defsel("dec:no", "Contact authors of reject-class papers", $dec_tcount[-1] == 0 ? self::F_HIDE : 0);
            $this->defsel("dec:none", "Contact authors of undecided papers", $dec_tcount[0] == 0 || ($dec_tcount[1] == 0 && $dec_tcount[-1] == 0) ? self::F_HIDE : 0);
            $this->defsel("dec:any", "Contact authors of decided papers", self::F_HIDE);
            $this->defsel("bydec_group_end", null, self::F_GROUP);

            $this->defsel("rev_group", "Reviewers", self::F_GROUP);

            // XXX this exposes information about PC review assignments
            // for conflicted papers to the chair; not worth worrying about
            if (!$contact->privChair) {
                $pids = [];
                $result = $this->conf->qe("select paperId from Paper where managerContactId=?", $contact->contactId);
                while ($result && ($row = edb_row($result)))
                    $pids[] = (int) $row[0];
                Dbl::free($result);
                $pidw = empty($pids) ? "false" : "paperId in (" . join(",", $pids) . ")";
            } else
                $pidw = "true";
            $row = $this->conf->fetch_first_row("select
                exists (select * from PaperReview where reviewType>=" . REVIEW_PC . " and $pidw),
                exists (select * from PaperReview where reviewType<" . REVIEW_PC . "  and $pidw),
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

        $hide = !$this->contact->is_requester();
        $this->defsel("myextrev", "Your requested reviewers", self::F_ANYPC | ($hide ? self::F_HIDE : 0));
        $this->defsel("uncmyextrev", "Your requested reviewers with incomplete reviews", self::F_ANYPC | ($hide ? self::F_HIDE : 0));

        if ($contact->is_manager()) {
            $this->defsel("lead", "Discussion leads", $any_lead ? 0 : self::F_HIDE);
            $this->defsel("shepherd", "Shepherds", $any_shepherd ? 0 : self::F_HIDE);
        }

        $this->defsel("pc_group", "Program committee", self::F_GROUP);
        $selcount = count($this->sel);
        $this->defsel("pc", "Program committee", self::F_ANYPC | self::F_NOPAPERS);
        foreach ($this->conf->pc_tags() as $t)
            if ($t != "pc")
                $this->defsel("pc:$t", "#$t program committee", self::F_ANYPC | self::F_NOPAPERS);
        if (count($this->sel) == $selcount + 1)
            unset($this->sel["pc_group"]);
        else
            $this->defsel("pc_group_end", null, self::F_GROUP);

        if ($contact->privChair)
            $this->defsel("all", "All users", self::F_NOPAPERS);

        if (isset($this->sel[$type])
            && !($this->selflags[$type] & self::F_GROUP))
            $this->type = $type;
        else if ($type == "myuncextrev" && isset($this->sel["uncmyextrev"]))
            $this->type = "uncmyextrev";
        else
            $this->type = key($this->sel);

        $this->papersel = $papersel;

        if ($this->type == "newpcrev") {
            $t = trim((string) $newrev_since);
            if (preg_match(',\A(?:|n/a|\(?all\)?|0)\z,i', $t))
                $this->newrev_since = 0;
            else if (($this->newrev_since = $this->conf->parse_time($t)) !== false) {
                if ($this->newrev_since > $Now)
                    $this->conf->warnMsg("That time is in the future.");
            } else {
                Conf::msg_error("Invalid date.");
                $this->error = true;
            }
        }
    }

    function selectors() {
        $sel = [];
        $last = null;
        foreach ($this->sel as $n => $d) {
            $flags = $this->selflags[$n];
            if ($flags & self::F_GROUP) {
                if ($d !== null)
                    $sel[$n] = ["optgroup", $d];
                else if ($last !== null
                         && ($this->selflags[$last] & self::F_GROUP))
                    unset($sel[$last]);
                else
                    $sel[$n] = ["optgroup"];
            } else if (!($flags & self::F_HIDE) || $n == $this->type) {
                if (is_string($d))
                    $d = ["label" => $d];
                $k = null;
                if ($flags & self::F_NOPAPERS)
                    $k[] = "mail-want-no-papers";
                if ($flags & self::F_SINCE)
                    $k[] = "mail-want-since";
                if (!empty($k))
                    $d["class"] = join(" ", $k);
                $sel[$n] = $d;
            } else
                continue;
            $last = $n;
        }
        return Ht::select("recipients", $sel, $this->type, ["id" => "recipients"]);
    }

    function unparse() {
        $t = $this->sel[$this->type];
        if ($this->type == "newpcrev" && $this->newrev_since)
            $t .= " since " . htmlspecialchars($this->conf->parseableTime($this->newrev_since, false));
        return $t;
    }

    function need_papers() {
        return $this->type !== "pc" && substr($this->type, 0, 3) !== "pc:"
            && $this->type !== "all";
    }

    function combination_type($paper_sensitive) {
        if (preg_match('/\A(?:pc|pc:.*|(?:|unc|new)pcrev|lead|shepherd)\z/', $this->type))
            return 2;
        else if ($paper_sensitive)
            return 1;
        else
            return 0;
    }

    function query($paper_sensitive) {
        $cols = array();
        $where = array("email not regexp '^anonymous[0-9]*\$'");
        $joins = array("ContactInfo");

        // paper limit
        if ($this->need_papers() && isset($this->papersel))
            $where[] = "Paper.paperId in (" . join(",", $this->papersel) . ")";

        // paper type limit
        if ($this->type == "s")
            $where[] = "Paper.timeSubmitted>0";
        else if ($this->type == "unsub")
            $where[] = "Paper.timeSubmitted<=0 and Paper.timeWithdrawn<=0";
        else if ($this->type == "dec:any")
            $where[] = "Paper.timeSubmitted>0 and Paper.outcome!=0";
        else if ($this->type == "dec:none")
            $where[] = "Paper.timeSubmitted>0 and Paper.outcome=0";
        else if ($this->type == "dec:yes")
            $where[] = "Paper.timeSubmitted>0 and Paper.outcome>0";
        else if ($this->type == "dec:no")
            $where[] = "Paper.timeSubmitted>0 and Paper.outcome<0";
        else if (substr($this->type, 0, 4) == "dec:") {
            $nw = count($where);
            foreach ($this->conf->decision_map() as $dnum => $dname)
                if (strcasecmp($dname, substr($this->type, 4)) == 0) {
                    $where[] = "Paper.timeSubmitted>0 and Paper.outcome=$dnum";
                    break;
                }
            if (count($where) == $nw)
                return false;
        }

        // additional manager limit
        if (!$this->contact->privChair
            && !($this->selflags[$this->type] & self::F_ANYPC))
            $where[] = "Paper.managerContactId=" . $this->contact->contactId;

        // reviewer limit
        if (!preg_match('_\A(new|unc|c|allc|)(pc|ext|myext|)rev\z_',
                        $this->type, $revmatch))
            $revmatch = false;

        // build query
        if ($this->type == "all") {
            $needpaper = $needconflict = $needreview = false;
        } else if ($this->type == "pc" || substr($this->type, 0, 3) == "pc:") {
            $needpaper = $needconflict = $needreview = false;
            $where[] = "(ContactInfo.roles&" . Contact::ROLE_PC . ")!=0";
            if ($this->type != "pc")
                $where[] = "ContactInfo.contactTags like " . Dbl::utf8ci("'% " . sqlq_for_like(substr($this->type, 3)) . "#%'");
        } else if ($revmatch) {
            $needpaper = $needreview = true;
            $needconflict = false;
            $joins[] = "join Paper";
            $joins[] = "join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=ContactInfo.contactId)";
            $where[] = "Paper.paperId=PaperReview.paperId";
        } else if ($this->type == "lead" || $this->type == "shepherd") {
            $needpaper = $needconflict = $needreview = true;
            $joins[] = "join Paper on (Paper.{$this->type}ContactId=ContactInfo.contactId)";
            $joins[] = "left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=ContactInfo.contactId)";
        } else {
            $needpaper = $needconflict = true;
            $needreview = false;
            if ($this->conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE) {
                $cols[] = "(coalesce(allr.contactId,0)!=0) has_review";
                $cols[] = "coalesce(allr.has_outstanding_review,0) has_outstanding_review";
                $joins[] = "left join (select contactId, max(if(reviewNeedsSubmit!=0 and timeSubmitted>0,1,0)) has_outstanding_review from PaperReview join Paper on (Paper.paperId=PaperReview.paperId) group by PaperReview.contactId) as allr using (contactId)";
            }
            $joins[] = "join Paper";
            $where[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
            if ($this->conf->au_seerev == Conf::AUSEEREV_TAGS) {
                $joins[] = "left join (select paperId, group_concat(' ', tag, '#', tagIndex order by tag separator '') as paperTags from PaperTag group by paperId) as PaperTags on (PaperTags.paperId=Paper.paperId)";
                $cols[] = "PaperTags.paperTags";
            }
        }

        // reviewer match
        if ($revmatch) {
            // Submission status
            if ($revmatch[1] == "c")
                $where[] = "PaperReview.reviewSubmitted>0";
            else if ($revmatch[1] == "unc" || $revmatch[1] == "new")
                $where[] = "PaperReview.reviewSubmitted is null and PaperReview.reviewNeedsSubmit!=0 and Paper.timeSubmitted>0";
            if ($revmatch[1] == "new")
                $where[] = "PaperReview.timeRequested>PaperReview.timeRequestNotified";
            if ($revmatch[1] == "allc") {
                $joins[] = "left join (select contactId, max(if(reviewNeedsSubmit!=0 and timeSubmitted>0,1,0)) anyReviewNeedsSubmit from PaperReview join Paper on (Paper.paperId=PaperReview.paperId) group by contactId) AllReviews on (AllReviews.contactId=ContactInfo.contactId)";
                $where[] = "AllReviews.anyReviewNeedsSubmit=0";
            }
            if ($this->newrev_since)
                $where[] = "PaperReview.timeRequested>=$this->newrev_since";
            // Withdrawn papers may not count
            if ($revmatch[1] == "")
                $where[] = "(Paper.timeSubmitted>0 or PaperReview.reviewSubmitted>0)";
            // Review type
            if ($revmatch[2] == "ext" || $revmatch[2] == "myext")
                $where[] = "PaperReview.reviewType=" . REVIEW_EXTERNAL;
            else if ($revmatch[2] == "pc")
                $where[] = "PaperReview.reviewType>" . REVIEW_EXTERNAL;
            if ($revmatch[2] == "myext")
                $where[] = "PaperReview.requestedBy=" . $this->contact->contactId;
        }

        // query construction
        $q = "select ContactInfo.contactId, firstName, lastName, email,
            password, roles, contactTags, preferredEmail, "
            . ($needconflict ? "PaperConflict.conflictType" : "0 as conflictType");
        if ($needpaper)
            $q .= ", Paper.paperId, Paper.title, Paper.abstract,
                Paper.authorInformation, Paper.outcome, Paper.blind,
                Paper.timeSubmitted, Paper.timeWithdrawn,
                Paper.shepherdContactId, Paper.capVersion,
                Paper.managerContactId";
        else
            $q .= ", -1 as paperId";
        if ($needreview) {
            if (!$revmatch || $this->type === "rev")
                $q .= ", " . PaperInfo::my_review_permissions_sql("PaperReview.") . " myReviewPermissions";
            else
                $q .= ", (select " . PaperInfo::my_review_permissions_sql() . " from PaperReview where PaperReview.paperId=Paper.paperId and PaperReview.contactId=ContactInfo.contactId group by paperId) myReviewPermissions";
        } else
            $q .= ", '' myReviewPermissions";
        if ($needconflict)
            $joins[] = "left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=ContactInfo.contactId)";
        $q .= "\nfrom " . join("\n", $joins) . "\nwhere "
            . join("\n    and ", $where) . "\ngroup by ContactInfo.contactId";
        if ($needpaper)
            $q .= ", Paper.paperId";
        $q .= "\norder by ";
        if (!$needpaper)
            $q .= "email";
        else if ($paper_sensitive)
            $q .= "Paper.paperId, email";
        else
            $q .= "email, Paper.paperId";
        return $q;
    }
}
