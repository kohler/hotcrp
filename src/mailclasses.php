<?php
// mailclasses.php -- HotCRP mail tool
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class MailRecipients {

    private $contact;
    private $type;
    private $sel;
    private $papersel;
    public $newrev_since = 0;
    public $error = false;

    function __construct($contact, $type, $papersel, $newrev_since) {
        global $Conf;
        $this->contact = $contact;

        $this->sel = array();
        if ($contact->privChair) {
            $this->sel["au"] = "All contact authors";
            $this->sel["s"] = "Contact authors of submitted papers";
            $this->sel["unsub"] = "Contact authors of unsubmitted papers";

            // map "somedec:no"/"somedec:yes" to real decisions
            $result = Dbl::qe("select outcome, count(*) from Paper where timeSubmitted>0 group by outcome");
            $dec_pcount = edb_map($result);
            $dec_tcount = array();
            foreach ($dec_pcount as $dnum => $dcount)
                @($dec_tcount[$dnum > 0 ? 1 : ($dnum < 0 ? -1 : 0)] += $dcount);
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

            foreach ($Conf->decision_map() as $dnum => $dname) {
                $k = "dec:$dname";
                if ($dnum && (@$dec_pcount[$dnum] > 0 || $type == $k))
                    $this->sel[$k] = "Contact authors of " . htmlspecialchars($dname) . " papers";
            }
            if (@$dec_pcount[0] > 0 || $type == "dec:none")
                $this->sel["dec:none"] = "Contact authors of undecided papers";
            if ($type == "dec:any")
                $this->sel["dec:any"] = "Contact authors of decided papers";
            if (@$dec_tcount[1] > 0 || $type == "dec:yes")
                $this->sel["dec:yes"] = "Contact authors of accept-class papers";
            if (@$dec_tcount[-1] > 0 || $type == "dec:no")
                $this->sel["dec:no"] = "Contact authors of reject-class papers";

            $this->sel["rev"] = "Reviewers";
            $this->sel["crev"] = "Reviewers with complete reviews";
            $this->sel["uncrev"] = "Reviewers with incomplete reviews";
            $this->sel["pcrev"] = "PC reviewers";
            $this->sel["uncpcrev"] = "PC reviewers with incomplete reviews";

            $result = Dbl::qe("select any_newpcrev, any_lead, any_shepherd
	from (select paperId any_newpcrev from PaperReview
		where reviewType>=" . REVIEW_PC . " and reviewSubmitted is null
		and reviewNeedsSubmit!=0 and timeRequested>timeRequestNotified
		limit 1) a
	left join (select paperId any_lead from Paper where timeSubmitted>0 and leadContactId is not null limit 1) b on (true)
	left join (select paperId any_shepherd from Paper where timeSubmitted>0 and shepherdContactId is not null limit 1) c on (true)");
            $row = edb_orow($result);

            if ($row && $row->any_newpcrev)
                $this->sel["newpcrev"] = "PC reviewers with new review assignments";
            $this->sel["extrev"] = "External reviewers";
            $this->sel["uncextrev"] = "External reviewers with incomplete reviews";

            if ($row && $row->any_lead)
                $this->sel["lead"] = "Discussion leads";
            if ($row && $row->any_shepherd)
                $this->sel["shepherd"] = "Shepherds";
        }
        $this->sel["myextrev"] = "Your requested reviewers";
        $this->sel["uncmyextrev"] = "Your requested reviewers with incomplete reviews";
        $this->sel["pc"] = "Program committee";
        foreach (pcTags() as $t)
            if ($t != "pc")
                $this->sel["pc:$t"] = "PC members tagged “{$t}”";
        if ($contact->privChair)
            $this->sel["all"] = "All users";

        if (isset($this->sel[$type]))
            $this->type = $type;
        else if ($type == "myuncextrev" && isset($this->sel["uncmyextrev"]))
            $this->type = "uncmyextrev";
        else
            $this->type = key($this->sel);

        $this->papersel = $papersel;

        if ($this->type == "newpcrev") {
            $t = @trim($newrev_since);
            if ($t == "" || strtoupper($t) == "N/A" || $t == "0")
                $this->newrev_since = 0;
            else if (($this->newrev_since = $Conf->parse_time($t)) !== false)
                /* OK */;
            else {
                $Conf->errorMsg("Invalid date.");
                $this->error = true;
            }
        }
    }

    function selectors() {
        return Ht::select("recipients", $this->sel, $this->type, array("id" => "recipients", "onchange" => "setmailpsel(this)"));
    }

    function unparse() {
        return $this->sel[$this->type];
    }

    function query() {
        global $Conf, $checkReviewNeedsSubmit;
        $contactInfo = "ContactInfo.contactId, firstName, lastName, email, password, roles, (PCMember.contactId is not null) as isPC, contactTags, preferredEmail";
        $paperInfo = "Paper.paperId, Paper.title, Paper.abstract, Paper.authorInformation, Paper.outcome, Paper.blind, Paper.timeSubmitted, Paper.timeWithdrawn, Paper.shepherdContactId, Paper.capVersion, Paper.managerContactId";

        // paper limit
        $where = array();
        if ($this->type != "pc" && substr($this->type, 0, 3) != "pc:"
            && $this->type != "all" && isset($this->papersel))
            $where[] = "Paper.paperId in (" . join(",", $this->papersel) . ")";

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
            foreach ($Conf->decision_map() as $dnum => $dname)
                if (strcasecmp($dname, substr($this->type, 4)) == 0) {
                    $where[] = "Paper.timeSubmitted>0 and Paper.outcome=$dnum";
                    break;
                }
            if (!count($where))
                return false;
        }

        // reviewer limit
        $isreview = false;
        if (preg_match('_\A(new|unc|c|)(pc|ext|myext|)rev\z_', $this->type, $m)) {
            $isreview = true;
            // Submission status
            if ($m[1] == "c")
                $where[] = "PaperReview.reviewSubmitted>0";
            else if ($m[1] == "unc" || $m[1] == "new")
                $where[] = "PaperReview.reviewSubmitted is null and PaperReview.reviewNeedsSubmit!=0";
            if ($m[1] == "new")
                $where[] = "PaperReview.timeRequested>PaperReview.timeRequestNotified";
            if ($this->newrev_since)
                $where[] = "PaperReview.timeRequested>=$this->newrev_since";
            // Withdrawn papers may not count
            if ($m[1] == "unc" || $m[1] == "new")
                $where[] = "Paper.timeSubmitted>0";
            else if ($m[1] == "")
                $where[] = "(Paper.timeSubmitted>0 or PaperReview.reviewSubmitted>0)";
            // Review type
            if ($m[2] == "ext" || $m[2] == "myext")
                $where[] = "PaperReview.reviewType=" . REVIEW_EXTERNAL;
            else if ($m[2] == "pc")
                $where[] = "PaperReview.reviewType>" . REVIEW_EXTERNAL;
            if ($m[2] == "myext")
                $where[] = "PaperReview.requestedBy=" . $this->contact->contactId;
        }

        // build query
        if ($this->type == "all") {
            $q = "select $contactInfo, 0 as conflictType, -1 as paperId from ContactInfo left join PCMember using (contactId)";
            $orderby = "email";
        } else if ($this->type == "pc" || substr($this->type, 0, 3) == "pc:") {
            $q = "select $contactInfo, 0 as conflictType, -1 as paperId from ContactInfo join PCMember using (contactId)";
            $orderby = "email";
            if ($this->type != "pc")
                $where[] = "ContactInfo.contactTags like '% " . sqlq_for_like(substr($this->type, 3)) . " %'";
        } else if ($isreview) {
            $q = "select $contactInfo, 0 as conflictType, $paperInfo, PaperReview.reviewType, PaperReview.reviewType as myReviewType from PaperReview join Paper using (paperId) join ContactInfo using (contactId) left join PCMember on (PCMember.contactId=ContactInfo.contactId)";
            $orderby = "Paper.paperId, email";
        } else if ($this->type == "lead" || $this->type == "shepherd") {
            $q = "select $contactInfo, conflictType, $paperInfo, PaperReview.reviewType, PaperReview.reviewType as myReviewType from Paper join ContactInfo on (ContactInfo.contactId=Paper.${type}ContactId) left join PaperReview on (PaperReview.paperId=Paper.paperId and PaperReview.contactId=ContactInfo.contactId) left join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=ContactInfo.contactId) left join PCMember on (PCMember.contactId=ContactInfo.contactId)";
            $orderby = "Paper.paperId, email";
        } else {
            if (!$Conf->timeAuthorViewReviews(true) && $Conf->timeAuthorViewReviews()) {
                $qa = ", reviewNeedsSubmit";
                $qb = " left join (select contactId, max(reviewNeedsSubmit) as reviewNeedsSubmit from PaperReview group by PaperReview.contactId) as PaperReview using (contactId)";
                $checkReviewNeedsSubmit = true;
            } else
                $qa = $qb = "";
            $q = "select $contactInfo$qa, PaperConflict.conflictType, $paperInfo, 0 as myReviewType from Paper left join PaperConflict using (paperId) join ContactInfo using (contactId)$qb left join PCMember on (PCMember.contactId=ContactInfo.contactId)";
            $where[] = "PaperConflict.conflictType>=" . CONFLICT_AUTHOR;
            $orderby = "Paper.paperId, email";
        }

        $where[] = "email not regexp '^anonymous[0-9]*\$'";
        return $q . " where " . join(" and ", $where) . " order by " . $orderby;
    }

}
