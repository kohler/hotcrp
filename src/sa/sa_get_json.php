<?php
// sa/sa_get_json.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetJsonPapers_SearchAction extends SearchAction {
    private $iszip;
    private $zipdoc;
    public function __construct($iszip) {
        $this->iszip = $iszip;
    }
    public function document_callback($dj, $prow, $dtype, $drow) {
        if ($drow->docclass->load($drow)) {
            $dj->content_file = HotCRPDocument::filename($drow);
            $this->zipdoc->add_as($drow, $dj->content_file);
        }
    }
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [1090 + $this->iszip, $this->subname, "Paper information", $this->iszip ? "JSON with attachments" : "JSON"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $result = $user->paper_result(["paperId" => $ssel->selection(), "topics" => true, "options" => true]);
        $pj = [];
        $ps = new PaperStatus($user->conf, $user, ["forceShow" => true, "hide_docids" => true]);
        if ($this->iszip) {
            $this->zipdoc = new ZipDocument($user->conf->download_prefix . "data.zip");
            $ps->add_document_callback([$this, "document_callback"]);
        }
        while (($prow = PaperInfo::fetch($result, $user)))
            if ($user->can_administer($prow, true))
                $pj[$prow->paperId] = $ps->paper_json($prow);
            else {
                $pj[$prow->paperId] = (object) ["pid" => $prow->paperId, "error" => "You don't have permission to administer this paper."];
                if ($this->iszip)
                    $this->zipdoc->warnings[] = "#$prow->paperId: You don't have permission to administer this paper.";
            }
        $pj = array_values($ssel->reorder($pj));
        if (count($pj) == 1) {
            $pj = $pj[0];
            $pj_filename = $user->conf->download_prefix . "paper" . $ssel->selection_at(0) . "-data.json";
        } else
            $pj_filename = $user->conf->download_prefix . "data.json";
        if ($this->iszip) {
            $this->zipdoc->add(json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", $pj_filename);
            $this->zipdoc->download();
        } else {
            header("Content-Type: application/json");
            header("Content-Disposition: attachment; filename=" . mime_quote_string($pj_filename));
            echo json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        exit;
    }
}

class GetJsonReviews_SearchAction extends SearchAction {
    # tailored for Review Quality Collector. Don't remove parts.
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function list_actions(Contact $user, $qreq, PaperList $pl, &$actions) {
        $actions[] = [3070, $this->subname, "Reviews", "Reviews (JSON)"];
    }
    function run(Contact $user, $qreq, $ssel) {
        $conf = $user->conf;
        $condition = "paperId in (" . implode(",", $ssel->selection()) . ")";
        $results = [];
        $results["hotcrp_version"] = HOTCRP_VERSION;
        #----- get papers:
        $result = $user->paper_result(["paperId" => $ssel->selection(), "topics" => true, "options" => true]);
        $papers = [];
        $ps = new PaperStatus($user->conf, $user, ["forceShow" => true, "hide_docids" => true]);
        while (($prow = PaperInfo::fetch($result, $user)))
            if ($user->can_administer($prow, true))
                $papers[$prow->paperId] = $ps->paper_json($prow);
            else
                $papers[$prow->paperId] = (object) ["pid" => $prow->paperId, "error" => "You don’t have permission to administer this paper."];
        $results["papers"] = array_values($ssel->reorder($papers));
        //----- get reviewers:
        $thefields = "contactId, firstName, lastName, email, " .
                     "country, roles, contactTags";
        $query = "select $thefields from contactinfo where contactId in (" .
            "select distinct contactId from PaperReview where $condition)";
        $contacts = $conf->qe_raw($query);
        $results["contacts"] = $contacts->fetch_all(MYSQLI_ASSOC);
        //----- get review form descriptor:
        $results["reviewform"] = $conf->review_form_json();
        //----- get reviews:
        $thefields = "paperId, reviewId, contactId, reviewType, " .
            "reviewSubmitted, reviewModified, reviewOrdinal, " .
            "overAllMerit, reviewerQualification, novelty, technicalMerit, " .
            "interestToCommunity, longevity, grammar, likelyPresentation, " .
            "suitableForShort, paperSummary, commentsToAuthor, commentsToPC, " .
            "commentsToAddress, weaknessOfPaper, strengthOfPaper, " .
            "potential, fixability, textField7, textField8, " .
            "reviewFormat";
        $reviews = $conf->qe_raw("select $thefields from PaperReview where $condition");
        $results["reviews"] = $reviews->fetch_all(MYSQLI_ASSOC);
        //----- export:
        header("Content-Type: application/json");
        #header("Content-Disposition: attachment; filename=" . "review-data.json");
        echo json_encode($results, JSON_PRETTY_PRINT | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_HEX_QUOT) . "\n";
        exit;
    }
}

SearchAction::register("get", "json", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetJsonPapers_SearchAction(false));
SearchAction::register("get", "jsonattach", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetJsonPapers_SearchAction(true));
SearchAction::register("get", "jsonreviews", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetJsonReviews_SearchAction());
