<?php
// mailclasses.php -- HotCRP mail tool
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MailRecipients {

    private $contact;
    private $type;
    private $sel = array();
    private $sel_nonmanager = array();
    private $papersel;
    public $newrev_since = 0;
    public $error = false;

    private function defsel($name, $description) {
        assert(!isset($this->sel[$name]));
        $this->sel[$name] = $description;
    }

    private function defsel_nm($name, $description) {
        assert(!isset($this->sel[$name]));
        $this->sel[$name] = $description;
        $this->sel_nonmanager[$name] = true;
    }

    function __construct($contact, $type, $papersel, $newrev_since) {
        global $Conf, $Now;
        $this->contact = $contact;
        assert(!!$contact->isPC);
        $any_newpcrev = $any_lead = $any_shepherd = 0;

        if ($contact->is_manager()) {
            $this->defsel("s", "Contact authors of submitted papers");
            $this->defsel("unsub", "Contact authors of unsubmitted papers");
            $this->defsel("au", "All contact authors");

            // map "somedec:no"/"somedec:yes" to real decisions
            $result = Dbl::qe("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
            $dec_pcount = edb_map($result);
            $dec_tcount = array(0 => 0, 1 => 0, -1 => 0);
            foreach ($dec_pcount as $dnum => $dcount)
                $dec_tcount[$dnum > 0 ? 1 : ($dnum < 0 ? -1 : 0)] += $dcount;
            if ($type == "somedec:no" || $type == "somedec:yes") {
                $dmaxcount = -1;
                foreach ($dec_pcount as $dnum => $dcount)
                    if (($type[8] == "n" ? $dnum < 0 : $dnum > 0)
                        && $dcount > $dmaxcount
                        && ($dname = $Conf->decision_name($dnum))) {
                        $type = "dec:$dname";
                        $dmaxcount = $dcount;
                    }
            }

            $by_dec = array();
            foreach ($Conf->decision_map() as $dnum => $dname) {
                $k = "dec:$dname";
                if ($dnum && (@$dec_pcount[$dnum] > 0 || $type == $k))
                    $by_dec[$k] = "Contact authors of " . htmlspecialchars($dname) . " papers";
            }
            if ($dec_tcount[1] > 0 || $type == "dec:yes")
                $by_dec["dec:yes"] = "Contact authors of accept-class papers";
            if ($dec_tcount[-1] > 0 || $type == "dec:no")
                $by_dec["dec:no"] = "Contact authors of reject-class papers";
            if ($dec_tcount[0] > 0 || $type == "dec:none")
                $by_dec["dec:none"] = "Contact authors of undecided papers";
            if ($type == "dec:any")
                $by_dec["dec:any"] = "Contact authors of decided papers";
            if (count($by_dec)) {
                $this->sel["bydec_group"] = array("optgroup", "Contact authors by decision");
                foreach ($by_dec as $k => $v)
                    $this->defsel($k, $v);
                $this->sel["bydec_group_end"] = array("optgroup");
            }

            $this->sel["rev_group"] = array("optgroup", "Reviewers");
            $this->defsel("rev", "Reviewers");
            $this->defsel("crev", "Reviewers with complete reviews");
            $this->defsel("uncrev", "Reviewers with incomplete reviews");
            $this->defsel("allcrev", "Reviewers with no incomplete reviews");
            $this->defsel("pcrev", "PC reviewers");
            $this->defsel("uncpcrev", "PC reviewers with incomplete reviews");

            // new assignments query
            // XXX this exposes information about PC review assignments
            // for conflicted papers to the chair; not worth worrying about
            $aq = "select PaperReview.paperId any_newpcrev from PaperReview";
            if (!$contact->privChair)
                $aq .= " join Paper on (Paper.paperId=PaperReview.paperId and Paper.managerContactId=" . $contact->contactId . ")";
            $aq .= "\n\twhere reviewType>=" . REVIEW_PC . " and reviewSubmitted is null and reviewNeedsSubmit!=0 and timeRequested>timeRequestNotified limit 1";
            $bcq_manager = "";
            if (!$contact->privChair)
                $bcq_manager = " and managerContactId=" . $contact->contactId;
            $q = "select any_newpcrev, any_lead, any_shepherd
	from ($aq) a
	left join (select paperId any_lead from Paper where timeSubmitted>0 and leadContactId!=0$bcq_manager limit 1) b on (true)
	left join (select paperId any_shepherd from Paper where timeSubmitted>0 and shepherdContactId!=0$bcq_manager limit 1) c on (true)";
            if (($row = Dbl::fetch_first_row($q)))
                list($any_newpcrev, $any_lead, $any_shepherd) = $row;

            $this->defsel("newpcrev", "PC reviewers with new review assignments");
            $this->defsel("extrev", "External reviewers");
            $this->defsel("uncextrev", "External reviewers with incomplete reviews");
            $this->sel["rev_group_end"] = array("optgroup");
        }

        $this->defsel_nm("myextrev", "Your requested reviewers");
        $this->defsel_nm("uncmyextrev", "Your requested reviewers with incomplete reviews");

        $this->sel["pc_group"] = array("optgroup", "Program committee");
        $selcount = count($this->sel);
        if ($contact->is_manager()) {
            if ($any_lead || $type == "lead")
                $this->defsel("lead", "Discussion leads");
            if ($any_shepherd || $type == "shepherd")
                $this->defsel("shepherd", "Shepherds");
        }
        $this->defsel_nm("pc", "Program committee");
        foreach ($Conf->pc_tags() as $t)
            if ($t != "pc")
                $this->defsel_nm("pc:$t", "PC members tagged “{$t}”");
        if (count($this->sel) == $selcount + 1)
            unset($this->sel["pc_group"]);
        else
            $this->sel["pc_group_end"] = array("optgroup");

        if ($contact->privChair)
            $this->defsel("all", "All users");

        if (isset($this->sel[$type]))
            $this->type = $type;
        else if ($type == "myuncextrev" && isset($this->sel["uncmyextrev"]))
            $this->type = "uncmyextrev";
        else
            $this->type = key($this->sel);

        $this->papersel = $papersel;

        if ($this->type == "newpcrev") {
            $t = @trim($newrev_since);
            if (preg_match(',\A(?:|n/a|[(]?all[)]?|0)\z,i', $t))
                $this->newrev_since = 0;
            else if (($this->newrev_since = $Conf->parse_time($t)) !== false) {
                if ($this->newrev_since > $Now)
                    $Conf->warnMsg("That time is in the future.");
            } else {
                Conf::msg_error("Invalid date.");
                $this->error = true;
            }
        }
    }

    function selectors() {
        return Ht::select("recipients", $this->sel, $this->type, array("id" => "recipients", "onchange" => "setmailpsel(this)"));
    }

    function unparse() {
        global $Conf;
        $t = $this->sel[$this->type];
        if ($this->type == "newpcrev" && $this->newrev_since)
            $t .= " since " . htmlspecialchars($Conf->parseableTime($this->newrev_since, false));
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
        global $Conf;
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
            foreach ($Conf->decision_map() as $dnum => $dname)
                if (strcasecmp($dname, substr($this->type, 4)) == 0) {
                    $where[] = "Paper.timeSubmitted>0 and Paper.outcome=$dnum";
                    break;
                }
            if (count($where) == $nw)
                return false;
        }

        // additional manager limit
        if (!isset($this->sel_nonmanager[$this->type])
            && !$this->contact->privChair)
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
            if ($Conf->au_seerev == Conf::AUSEEREV_UNLESSINCOMPLETE) {
                $cols[] = "(coalesce(allr.contactId,0)!=0) has_review";
                $cols[] = "coalesce(allr.has_outstanding_review,0) has_outstanding_review";
                $joins[] = "left join (select contactId, max(if(reviewNeedsSubmit!=0 and timeSubmitted>0,1,0)) has_outstanding_review from PaperReview join Paper on (Paper.paperId=PaperReview.paperId) group by PaperReview.contactId) as allr using (contactId)";
            }
            $joins[] = "join Paper";
            $where[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
            if ($Conf->au_seerev == Conf::AUSEEREV_TAGS) {
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
        if ($needreview)
            $q .= ", PaperReview.reviewType, PaperReview.reviewType as myReviewType";
        if ($needconflict)
            $joins[] = "left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=ContactInfo.contactId)";
        $q .= "\nfrom " . join("\n", $joins) . "\nwhere "
            . join("\n    and ", $where) . "\norder by ";
        if (!$needpaper)
            $q .= "email";
        else if ($paper_sensitive)
            $q .= "Paper.paperId, email";
        else
            $q .= "email, Paper.paperId";
        return $q;
    }
}
