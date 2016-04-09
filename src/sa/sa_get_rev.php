<?php
// sa/sa_get_rev.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetReviewBase_SearchAction extends SearchAction {
    protected $isform;
    protected $iszip;
    public function __construct($isform, $iszip) {
        $this->isform = $isform;
        $this->iszip = $iszip;
    }
    protected function finish($ssel, $texts, $errors) {
        global $Conf, $Opt;
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
            $header = ReviewForm::textFormHeader(count($texts) > 1 && !$this->iszip);
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
            $zip = new ZipDocument($Opt["downloadPrefix"] . "reviews.zip");
            $zip->warnings = $warnings;
            foreach ($texts as $pid => $text)
                $zip->add($header . $text, $Opt["downloadPrefix"] . $rfname . $pid . ".txt");
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
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        $rf = ReviewForm::get();
        if ($ssel->is_empty()) {
            // blank form
            $text = $rf->textFormHeader("blank") . $rf->textForm(null, null, $user, null) . "\n";
            downloadText($text, "review");
            return;
        }

        $result = Dbl::qe_raw($Conf->paperQuery($user, array("paperId" => $ssel->selection(), "myReviewsOpt" => 1)));
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

        $this->finish($ssel, $texts, $errors);
    }
}

class GetReviews_SearchAction extends GetReviewBase_SearchAction {
    public function __construct($iszip) {
        parent::__construct(false, $iszip);
    }
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        $result = Dbl::qe_raw($Conf->paperQuery($user, array("paperId" => $ssel->selection(), "allReviews" => 1, "reviewerName" => 1)));
        $texts = array();
        $errors = array();
        $user->set_forceShow(true);
        $rf = ReviewForm::get();
        while (($row = PaperInfo::fetch($result, $user))) {
            if (($whyNot = $user->perm_view_review($row, null, null)))
                $errors[whyNotText($whyNot, "view review")] = true;
            else if ($row->reviewSubmitted)
                defappend($texts[$row->paperId], $rf->pretty_text($row, $row, $user) . "\n");
        }

        $crows = $Conf->comment_rows($Conf->paperQuery($user, array("paperId" => $ssel->selection(), "allComments" => 1, "reviewerName" => 1)), $user);
        foreach ($crows as $row)
            if ($user->can_view_comment($row, $row, null)) {
                $crow = new CommentInfo($row, $row);
                defappend($texts[$row->paperId], $crow->unparse_text($user) . "\n");
            }

        $this->finish($ssel, $texts, $errors);
    }
}

class GetLead_SearchAction extends SearchAction {
    private $islead;
    public function __construct($islead) {
        $this->islead = $islead;
    }
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        if (!$user->isPC)
            return self::EPERM;
        $type = $this->islead ? "lead" : "shepherd";
        $result = Dbl::qe_raw($Conf->paperQuery($user, array("paperId" => $ssel->selection(), "reviewerName" => $type)));
        $texts = array();
        while (($row = PaperInfo::fetch($result, $user)))
            if ($row->reviewEmail
                && ($this->islead ? $user->can_view_lead($row, true) : $user->can_view_shepherd($row, true)))
                arrayappend($texts[$row->paperId], [$row->paperId, $row->title, $row->reviewFirstName, $row->reviewLastName, $row->reviewEmail]);
        downloadCSV($ssel->reorder($texts), array("paper", "title", "first", "last", "{$type}email"), "{$type}s");
    }
}

SearchActions::register("get", "revform", SiteLoader::API_GET, new GetReviewForm_SearchAction(false));
SearchActions::register("get", "revformz", SiteLoader::API_GET, new GetReviewForm_SearchAction(true));
SearchActions::register("get", "rev", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetReviews_SearchAction(false));
SearchActions::register("get", "revz", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetReviews_SearchAction(true));
SearchActions::register("get", "lead", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetLead_SearchAction(true));
SearchActions::register("get", "shepherd", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetLead_SearchAction(false));
