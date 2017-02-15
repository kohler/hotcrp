<?php
// sa/sa_get_rev.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetPcassignments_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [2099, $this->subname, "Review assignments", "PC assignments"];
    }
    function run(Contact $user, $qreq, $ssel) {
        list($header, $items) = SearchAction::pcassignments_csv_data($user, $ssel->selection());
        return new Csv_SearchResult("pcassignments", $header, $items, true);
    }
}

class GetReviewBase_SearchAction extends SearchAction {
    protected $isform;
    protected $iszip;
    public function __construct($isform, $iszip) {
        $this->isform = $isform;
        $this->iszip = $iszip;
    }
    protected function finish(Contact $user, $ssel, $texts, $errors) {
        $texts = $ssel->reorder($texts);
        if (empty($texts)) {
            if (empty($errors))
                Conf::msg_error("No papers selected.");
            else
                Conf::msg_error(join("<br />\n", array_keys($errors)) . "<br />\nNothing to download.");
            return;
        }

        $warnings = array();
        $nerrors = 0;
        foreach ($errors as $ee => $iserror) {
            $warnings[] = whyNotHtmlToText($ee);
            if ($iserror)
                $nerrors++;
        }
        if ($nerrors)
            array_unshift($warnings, "Some " . ($this->isform ? "review forms" : "reviews") . " are missing:");

        if ($this->isform && (count($texts) == 1 || $this->iszip))
            $rfname = "review";
        else
            $rfname = "reviews";
        if (count($texts) == 1 && !$this->iszip)
            $rfname .= key($texts);

        if ($this->isform)
            $header = $user->conf->review_form()->textFormHeader(count($texts) > 1 && !$this->iszip);
        else
            $header = "";

        if (!$this->iszip) {
            $text = $header;
            if (!empty($warnings) && $this->isform) {
                foreach ($warnings as $w)
                    $text .= prefix_word_wrap("==-== ", whyNotHtmlToText($w), "==-== ");
                $text .= "\n";
            } else if (!empty($warnings))
                $text .= join("\n", $warnings) . "\n\n";
            $text .= join("", $texts);
            downloadText($text, $rfname);
        } else {
            $zip = new ZipDocument($user->conf->download_prefix . "reviews.zip");
            $zip->warnings = $warnings;
            foreach ($texts as $pid => $text)
                $zip->add($header . $text, $user->conf->download_prefix . $rfname . $pid . ".txt");
            $result = $zip->download();
            if (!$result->error)
                exit;
        }
    }
}

class GetReviewForm_SearchAction extends GetReviewBase_SearchAction {
    public function __construct($iszip) {
        parent::__construct(true, $iszip);
    }
    function allow(Contact $user) {
        return $user->is_reviewer();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [2000 + $this->iszip, $this->subname, "Review assignments", "Review forms" . ($this->iszip ? " (zip)" : "")];
    }
    function run(Contact $user, $qreq, $ssel) {
        $rf = $user->conf->review_form();
        if ($ssel->is_empty()) {
            // blank form
            $text = $rf->textFormHeader("blank") . $rf->textForm(null, null, $user, null) . "\n";
            downloadText($text, "review");
            return;
        }

        $result = $user->paper_result(["paperId" => $ssel->selection(), "myReviewsOpt" => 1]);
        $texts = array();
        $errors = array();
        while (($row = PaperInfo::fetch($result, $user))) {
            $whyNot = $user->perm_review($row, null);
            if ($whyNot && !isset($whyNot["deadline"])
                && !isset($whyNot["reviewNotAssigned"]))
                $errors[whyNotText($whyNot, "review")] = true;
            else {
                if ($whyNot) {
                    $t = whyNotText($whyNot, "review");
                    $errors[$t] = false;
                    if (!isset($whyNot["deadline"]))
                        defappend($texts[$row->paperId], prefix_word_wrap("==-== ", strtoupper(whyNotHtmlToText($t)) . "\n\n", "==-== "));
                }
                $rrow = $row->reviewContactId ? $row : null;
                defappend($texts[$row->paperId], $rf->textForm($row, $rrow, $user, null) . "\n");
            }
        }

        $this->finish($user, $ssel, $texts, $errors);
    }
}

class GetReviews_SearchAction extends GetReviewBase_SearchAction {
    public function __construct($iszip) {
        parent::__construct(false, $iszip);
    }
    function allow(Contact $user) {
        return $user->can_view_some_review();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [3060 + $this->iszip, $this->subname, "Reviews", "Reviews" . ($this->iszip ? " (zip)" : "")];
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(["paperId" => $ssel->selection()]);
        $user->set_forceShow(true);
        $errors = [];
        $rowset = new PaperInfoSet;
        while (($row = PaperInfo::fetch($result, $user)))
            if (($whyNot = $user->perm_view_paper($row)))
                $errors["#$row->paperId: " . whyNotText($whyNot, "view")] = true;
            else
                $rowset->add($row);

        $result = $user->paper_result(["paperId" => $ssel->selection(), "allReviews" => 1, "reviewerName" => 1]);
        $texts = [];
        $rf = $user->conf->review_form();
        while (($row = PaperInfo::fetch($result, $user))) {
            if (!$rowset->get($row->paperId))
                /* skip */;
            else if (($whyNot = $user->perm_view_review($row, null, null)))
                $errors["#$row->paperId: " . whyNotText($whyNot, "view review")] = true;
            else if ($row->reviewSubmitted)
                defappend($texts[$row->paperId], $rf->pretty_text($row, $row, $user) . "\n");
        }

        foreach ($rowset->all() as $prow) {
            foreach ($prow->viewable_comments($user, null) as $crow)
                defappend($texts[$prow->paperId], $crow->unparse_text($user) . "\n");
        }

        uksort($errors, function ($a, $b) { return strnatcmp($a, $b); });
        $this->finish($user, $ssel, $texts, $errors);
    }
}

class GetScores_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->can_view_some_review();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [3070, $this->subname, "Reviews", "Scores"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(["paperId" => $ssel->selection(), "allReviewScores" => 1, "reviewerName" => 1]);

        // compose scores; NB chair is always forceShow
        $errors = array();
        $texts = $any_scores = array();
        $any_decision = $any_reviewer_identity = false;
        $rf = $user->conf->review_form();
        $bad_pid = -1;
        while (($row = PaperInfo::fetch($result, $user))) {
            if (!$row->reviewSubmitted || $row->paperId == $bad_pid)
                /* skip */;
            else if (($whyNot = $user->perm_view_review($row, null, true))) {
                $errors[] = whyNotText($whyNot, "view reviews for") . "<br />";
                $bad_pid = $row->paperId;
            } else {
                $a = array("paper" => $row->paperId, "title" => $row->title);
                if ($row->outcome && $user->can_view_decision($row, true))
                    $a["decision"] = $any_decision = $user->conf->decision_name($row->outcome);
                $view_bound = $user->view_score_bound($row, $row, true);
                $this_scores = false;
                foreach ($rf->forder as $field => $f)
                    if ($f->view_score > $view_bound && $f->has_options
                        && ($row->$field || $f->allow_empty)) {
                        $a[$f->abbreviation()] = $f->unparse_value($row->$field);
                        $any_scores[$f->abbreviation()] = $this_scores = true;
                    }
                if ($user->can_view_review_identity($row, $row, true)) {
                    $any_reviewer_identity = true;
                    $a["email"] = $row->reviewEmail;
                    $a["reviewername"] = trim($row->reviewFirstName . " " . $row->reviewLastName);
                }
                if ($this_scores)
                    arrayappend($texts[$row->paperId], $a);
            }
        }

        if (count($texts)) {
            $header = array("paper", "title");
            if ($any_decision)
                $header[] = "decision";
            if ($any_reviewer_identity)
                array_push($header, "reviewername", "email");
            $header = array_merge($header, array_keys($any_scores));
            return new Csv_SearchResult("scores", $header, $ssel->reorder($texts), true);
        } else {
            if (!count($errors))
                $errors[] = "No papers selected.";
            Conf::msg_error(join("", $errors));
        }
    }
}

class GetVotes_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->isPC;
    }
    function run(Contact $user, $qreq, $ssel) {
        $tagger = new Tagger($user);
        if (($tag = $tagger->check($qreq->tag, Tagger::NOVALUE | Tagger::NOCHAIR))) {
            $showtag = trim($qreq->tag); // no "23~" prefix
            $result = $user->paper_result(["paperId" => $ssel->selection(), "tagIndex" => $tag]);
            $texts = array();
            while (($prow = PaperInfo::fetch($result, $user)))
                if ($user->can_view_tags($prow, true))
                    arrayappend($texts[$prow->paperId], array($showtag, (float) $prow->tagIndex, $prow->paperId, $prow->title));
            return new Csv_SearchResult("votes", ["tag", "votes", "paper", "title"], $ssel->reorder($texts));
        } else
            Conf::msg_error($tagger->error_html);
    }
}

class GetRank_SearchAction extends SearchAction {
    function allow(Contact $user) {
        return $user->conf->setting("tag_rank") && $user->is_reviewer();
    }
    function run(Contact $user, $qreq, $ssel) {
        $settingrank = $user->conf->setting("tag_rank") && $qreq->tag == "~" . $user->conf->setting_data("tag_rank");
        if (!$user->isPC && !($user->is_reviewer() && $settingrank))
            return self::EPERM;
        $tagger = new Tagger($user);
        if (($tag = $tagger->check($qreq->tag, Tagger::NOVALUE | Tagger::NOCHAIR))) {
            $result = $user->paper_result(["paperId" => $ssel->selection(), "tagIndex" => $tag, "order" => "order by tagIndex, PaperReview.overAllMerit desc, Paper.paperId"]);
            $real = "";
            $null = "\n";
            while (($prow = PaperInfo::fetch($result, $user)))
                if ($user->can_change_tag($prow, $tag, null, 1)) {
                    if ($prow->tagIndex === null)
                        $null .= "X\t$prow->paperId\t$prow->title\n";
                    else if ($real === "" || $lastIndex == $row->tagIndex - 1)
                        $real .= "\t$prow->paperId\t$prow->title\n";
                    else if ($lastIndex == $row->tagIndex)
                        $real .= "=\t$prow->paperId\t$prow->title\n";
                    else
                        $real .= str_pad("", min($prow->tagIndex - $lastIndex, 5), ">") . "\t$prow->paperId\t$prow->title\n";
                    $lastIndex = $prow->tagIndex;
                }
            $text = "# Edit the rank order by rearranging this file's lines.

# The first line has the highest rank. Lines starting with \"#\" are
# ignored. Unranked papers appear at the end in lines starting with
# \"X\", sorted by overall merit. Create a rank by removing the \"X\"s and
# rearranging the lines. Lines starting with \"=\" mark papers with the
# same rank as the preceding papers. Lines starting with \">>\", \">>>\",
# and so forth indicate rank gaps between papers. When you are done,
# upload the file at\n"
                . "#   " . hoturl_absolute("offline") . "\n\n"
                . "Tag: " . trim($qreq->tag) . "\n"
                . "\n"
                . $real . $null;
            downloadText($text, "rank");
        } else
            Conf::msg_error($tagger->error_html);
    }
}

class GetLead_SearchAction extends SearchAction {
    private $islead;
    public function __construct($islead) {
        $this->islead = $islead;
    }
    function allow(Contact $user) {
        return $user->isPC;
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        if ($user->conf->has_any_lead_or_shepherd())
            $actions[] = [3091 - $this->islead, $this->subname, "Reviews", $this->islead ? "Discussion leads" : "Shepherds"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $type = $this->islead ? "lead" : "shepherd";
        $result = $user->paper_result(["paperId" => $ssel->selection(), "reviewerName" => $type]);
        $texts = array();
        while (($row = PaperInfo::fetch($result, $user)))
            if ($row->reviewEmail
                && ($this->islead ? $user->can_view_lead($row, true) : $user->can_view_shepherd($row, true)))
                arrayappend($texts[$row->paperId], [$row->paperId, $row->title, $row->reviewFirstName, $row->reviewLastName, $row->reviewEmail]);
        return new Csv_SearchResult("{$type}s", ["paper", "title", "first", "last", "{$type}email"], $ssel->reorder($texts));
    }
}


SearchAction::register("get", "pcassignments", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetPcassignments_SearchAction);
SearchAction::register("get", "revform", SiteLoader::API_GET, new GetReviewForm_SearchAction(false));
SearchAction::register("get", "revformz", SiteLoader::API_GET, new GetReviewForm_SearchAction(true));
SearchAction::register("get", "rev", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetReviews_SearchAction(false));
SearchAction::register("get", "revz", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetReviews_SearchAction(true));
SearchAction::register("get", "scores", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetScores_SearchAction);
SearchAction::register("get", "votes", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetVotes_SearchAction);
SearchAction::register("get", "rank", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetRank_SearchAction);
SearchAction::register("get", "lead", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetLead_SearchAction(true));
SearchAction::register("get", "shepherd", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetLead_SearchAction(false));
