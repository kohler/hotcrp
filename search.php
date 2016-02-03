<?php
// search.php -- HotCRP paper search page
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/papersearch.php");
if ($Me->is_empty())
    $Me->escape();
$getaction = "";
if (isset($_REQUEST["get"]))
    $getaction = $_REQUEST["get"];
else if (isset($_REQUEST["getgo"]) && isset($_REQUEST["getaction"]))
    $getaction = $_REQUEST["getaction"];

// choose a sensible default action (if someone presses enter on a form element)
if (isset($_REQUEST["default"]) && defval($_REQUEST, "defaultact"))
    $_REQUEST[$_REQUEST["defaultact"]] = true;
else if (isset($_REQUEST["default"]))
    $_REQUEST["download"] = true;

// paper group
$tOpt = PaperSearch::search_types($Me);
if (count($tOpt) == 0) {
    $Conf->header("Search", "search", actionBar());
    Conf::msg_error("You are not allowed to search for papers.");
    exit;
}
if (isset($_REQUEST["t"]) && !isset($tOpt[$_REQUEST["t"]])) {
    Conf::msg_error("You aren’t allowed to search that paper collection.");
    unset($_REQUEST["t"]);
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($tOpt);

function _startsWith($haystack, $needle) {
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
}

function _endsWith($haystack, $needle) {
    $length = strlen($needle);
    return $length == 0 ? true : (substr($haystack, -$length) === $needle);
}

function _escapeMySqlSearchValue($value) {
    $value = str_replace("\\", "\\\\", $value);
    $value = str_replace("%", "\\%", $value);
    $value = str_replace("\"", "\\\"", $value);
    $value = str_replace(" ", "+", $value);
    return $value;
}

// search canonicalization
if (isset($_REQUEST["q"]))
    $_REQUEST["q"] = trim($_REQUEST["q"]);
if (isset($_REQUEST["q"]) && trim($_REQUEST["q"]) == "(All)")
    $_REQUEST["q"] = "";
if ((isset($_REQUEST["qa"]) || isset($_REQUEST["qo"]) || isset($_REQUEST["qx"]))
    && !isset($_REQUEST["q"])) {
    $_REQUEST["qa"] = defval($_REQUEST, "qa", "");
    $query_all = $_REQUEST["qa"];

    // Add all additional options.
    $delim = " and ";
    foreach ($_REQUEST as $key => $value) {
        if (_startsWith($key, "qao_") && !_endsWith($key, "_op")) {
            $optName = substr($key, 4);
            $optOp = $_REQUEST[$key . "_op"];
            switch ($optOp) {
                case 'IS_NUM':
                    $query_all .= $delim . $optName . ":" . _escapeMySqlSearchValue($value);
                    break;
                case 'IS_AT_LEAST':
                    $query_all .= $delim . $optName . ":>=" . _escapeMySqlSearchValue($value);
                    break;
                case 'IS_LESS_THAN':
                    $query_all .= $delim . $optName . ":<" . _escapeMySqlSearchValue($value);
                    break;
                case 'IS':
                    $query_all .= $delim . $optName . ":'" . _escapeMySqlSearchValue($value) . "'";
                    break;
                case 'STARTS_WITH':
                    $query_all .= $delim . $optName . ":'" . _escapeMySqlSearchValue($value) . "%'";
                    break;
                case 'ENDS_WITH':
                    $query_all .= $delim . $optName . ":'%" . _escapeMySqlSearchValue($value) . "'";
                    break;
                case 'CONTAINS':
                    $query_all .= $delim . $optName . ":'%" . _escapeMySqlSearchValue($value) . "%'";
                    break;
                case 'CHECKBOX':
                    $query_all .= $delim . $optName . ":'" . _escapeMySqlSearchValue($value) . "'";
                    break;
            }
        }
    }
    $_REQUEST["q"] = PaperSearch::canonical_query($query_all, defval($_REQUEST, "qo"), defval($_REQUEST, "qx"));
} else {
    unset($_REQUEST["qa"]);
    unset($_REQUEST["qo"]);
    unset($_REQUEST["qx"]);
}


// paper selection
if (!SearchActions::any()) {
    SearchActions::parse_requested_selection($Me);
    SearchActions::clear_requested_selection();
}

function cleanAjaxResponse(&$response, $type) {
    foreach (SearchActions::selection() as $pid)
        if (!isset($response[$type . $pid]))
            $response[$type . $pid] = "";
}


// download selected papers
if (($getaction == "paper" || $getaction == "final"
     || substr($getaction, 0, 4) == "opt-")
    && SearchActions::any()
    && ($dt = HotCRPDocument::parse_dtype($getaction)) !== null) {
    $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection())));
    $downloads = array();
    while (($row = PaperInfo::fetch($result, $Me))) {
        if (($whyNot = $Me->perm_view_paper($row, true)))
            Conf::msg_error(whyNotText($whyNot, "view"));
        else
            $downloads[] = $row->paperId;
    }

    session_write_close();      // to allow concurrent clicks
    if ($Conf->downloadPaper($downloads, true, $dt))
        exit;
}


function topic_ids_to_text($tids, $tmap, $tomap) {
    $tx = array();
    foreach (explode(",", $tids) as $tid)
        if (($tname = @$tmap[$tid]))
            $tx[$tomap[$tid]] = $tname;
    ksort($tx);
    return join(", ", $tx);
}


// download selected abstracts
if ($getaction == "abstract" && SearchActions::any() && defval($_REQUEST, "ajax")) {
    $Search = new PaperSearch($Me, $_REQUEST);
    $pl = new PaperList($Search);
    $response = $pl->ajaxColumn("abstract");
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
} else if ($getaction == "abstract" && SearchActions::any()) {
    $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "topics" => 1)));
    $texts = array();
    list($tmap, $tomap) = array($Conf->topic_map(), $Conf->topic_order_map());
    while ($prow = PaperInfo::fetch($result, $Me)) {
        if (($whyNot = $Me->perm_view_paper($prow)))
            Conf::msg_error(whyNotText($whyNot, "view"));
        else {
            $text = "===========================================================================\n";
            $n = "Paper #" . $prow->paperId . ": ";
            $l = max(14, (int) ((75.5 - strlen($prow->title) - strlen($n)) / 2) + strlen($n));
            $text .= prefix_word_wrap($n, $prow->title, $l);
            $text .= "---------------------------------------------------------------------------\n";
            $l = strlen($text);
            if ($Me->can_view_authors($prow, $_REQUEST["t"] == "a"))
                $text .= prefix_word_wrap("Authors: ", $prow->pretty_text_author_list(), 14);
            if ($prow->topicIds != "") {
                $tt = topic_ids_to_text($prow->topicIds, $tmap, $tomap);
                $text .= prefix_word_wrap("Topics: ", $tt, 14);
            }
            if ($l != strlen($text))
                $text .= "---------------------------------------------------------------------------\n";
            $text .= rtrim($prow->abstract) . "\n\n";
            defappend($texts[$prow->paperId], $text);
            $rfSuffix = (count($texts) == 1 ? $prow->paperId : "s");
        }
    }

    if (count($texts)) {
        downloadText(join("", SearchActions::reorder($texts)), "abstract$rfSuffix");
        exit;
    }
}


// other field-based Ajax downloads: tags, collaborators, ...
if ($getaction && ($fdef = PaperColumn::lookup($getaction))
    && $fdef->foldable && defval($_REQUEST, "ajax")) {
    if ($getaction == "authors") {
        $full = defval($_REQUEST, "aufull", 0);
        displayOptionsSet("pldisplay", "aufull", $full);
    }
    $Search = new PaperSearch($Me, $_REQUEST);
    $pl = new PaperList($Search);
    $response = $pl->ajaxColumn($getaction);
    $response["ok"] = (count($response) > 0);
    $Conf->ajaxExit($response);
}


function whyNotToText($e) {
    $e = preg_replace('|\(?<a.*?</a>\)?\s*\z|i', "", $e);
    return preg_replace('|<.*?>|', "", $e);
}

function downloadReviews(&$texts, &$errors) {
    global $getaction, $Opt, $Conf;

    $texts = SearchActions::reorder($texts);
    if (count($texts) == 0) {
        if (count($errors) == 0)
            Conf::msg_error("No papers selected.");
        else
            Conf::msg_error(join("<br />\n", array_keys($errors)) . "<br />Nothing to download.");
        return;
    }

    $getforms = ($getaction == "revform" || $getaction == "revformz");
    $gettext = ($getaction == "rev" || $getaction == "revform");

    $warnings = array();
    $nerrors = 0;
    foreach ($errors as $ee => $iserror) {
        $warnings[] = whyNotToText($ee);
        if ($iserror)
            $nerrors++;
    }
    if ($nerrors)
        array_unshift($warnings, "Some " . ($getforms ? "review forms" : "reviews") . " are missing:");

    if ($getforms && (count($texts) == 1 || !$gettext))
        $rfname = "review";
    else
        $rfname = "reviews";
    if (count($texts) == 1 && $gettext)
        $rfname .= key($texts);

    if ($getforms)
        $header = ReviewForm::textFormHeader(count($texts) > 1 && $gettext);
    else
        $header = "";

    if ($gettext) {
        $text = $header;
        if (count($warnings) && $getforms) {
            foreach ($warnings as $w)
                $text .= prefix_word_wrap("==-== ", whyNotToText($w), "==-== ");
            $text .= "\n";
        } else if (count($warnings))
            $text .= join("\n", $warnings) . "\n\n";
        $text .= join("", $texts);
        downloadText($text, $rfname);
        exit;
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


// download review form for selected papers
// (or blank form if no papers selected)
if (($getaction == "revform" || $getaction == "revformz")
    && !SearchActions::any()) {
    $rf = ReviewForm::get();
    $text = $rf->textFormHeader("blank") . $rf->textForm(null, null, $Me, null) . "\n";
    downloadText($text, "review");
    exit;
} else if ($getaction == "revform" || $getaction == "revformz") {
    $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "myReviewsOpt" => 1)));

    $texts = array();
    $errors = array();
    $rf = ReviewForm::get();
    while (($row = PaperInfo::fetch($result, $Me))) {
        $whyNot = $Me->perm_review($row, null);
        if ($whyNot && !isset($whyNot["deadline"])
            && !isset($whyNot["reviewNotAssigned"]))
            $errors[whyNotText($whyNot, "review")] = true;
        else {
            if ($whyNot) {
                $t = whyNotText($whyNot, "review");
                $errors[$t] = false;
                if (!isset($whyNot["deadline"]))
                    defappend($texts[$row->paperId], prefix_word_wrap("==-== ", strtoupper(whyNotToText($t)) . "\n\n", "==-== "));
            }
            defappend($texts[$row->paperId], $rf->textForm($row, $row, $Me, null) . "\n");
        }
    }

    downloadReviews($texts, $errors);
}


// download all reviews for selected papers
if (($getaction == "rev" || $getaction == "revz") && SearchActions::any()) {
    $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "allReviews" => 1, "reviewerName" => 1)));

    $texts = array();
    $errors = array();
    if ($Me->privChair)
        $_REQUEST["forceShow"] = 1;
    $rf = ReviewForm::get();
    while (($row = PaperInfo::fetch($result, $Me))) {
        if (($whyNot = $Me->perm_view_review($row, null, null)))
            $errors[whyNotText($whyNot, "view review")] = true;
        else if ($row->reviewSubmitted)
            defappend($texts[$row->paperId], $rf->pretty_text($row, $row, $Me) . "\n");
    }

    $crows = $Conf->comment_rows($Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "allComments" => 1, "reviewerName" => 1)), $Me);
    foreach ($crows as $row)
        if ($Me->can_view_comment($row, $row, null)) {
            $crow = new CommentInfo($row, $row);
            defappend($texts[$row->paperId], $crow->unparse_text($Me) . "\n");
        }

    downloadReviews($texts, $errors);
}


// set tags for selected papers
function tagaction() {
    global $Conf, $Me, $Error;

    $errors = array();
    $papers = SearchActions::selection();

    $act = $_REQUEST["tagtype"];
    $tagreq = trim(str_replace(",", " ", (string) $_REQUEST["tag"]));
    $tags = preg_split(';\s+;', $tagreq);

    if ($act == "da") {
        $otags = $tags;
        foreach ($otags as $t)
            $tags[] = "all~" . preg_replace(',\A.*~([^~]+)\z', '$1', $t);
        $act = "d";
    } else if ($act == "sor")
        shuffle($papers);

    $x = array("action,paper,tag\n");
    if ($act == "s" || $act == "so" || $act == "sos" || $act == "sor")
        foreach ($tags as $t)
            $x[] = "cleartag,all," . TagInfo::base($t) . "\n";
    if ($act == "s" || $act == "a")
        $action = "tag";
    else if ($act == "d")
        $action = "cleartag";
    else if ($act == "so" || $act == "sor" || $act == "ao")
        $action = "nexttag";
    else if ($act == "sos" || $act == "aos")
        $action = "seqnexttag";
    else
        $action = null;

    $assignset = new AssignmentSet($Me, $Me->privChair);
    if (count($papers) && $action) {
        foreach ($papers as $p) {
            foreach ($tags as $t)
                $x[] = "$action,$p,$t\n";
        }
        $assignset->parse(join("", $x));
    } else if (count($papers) && $act == "cr" && $Me->privChair) {
        $source_tag = trim(defval($_REQUEST, "tagcr_source", ""));
        if ($source_tag == "")
            $source_tag = (substr($tagreq, 0, 2) == "~~" ? substr($tagreq, 2) : $tagreq);
        $tagger = new Tagger;
        if ($tagger->check($tagreq, Tagger::NOPRIVATE | Tagger::NOVALUE)
            && $tagger->check($source_tag, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
            $r = new PaperRank($source_tag, $tagreq, $papers,
                               defval($_REQUEST, "tagcr_gapless"),
                               "Search", "search");
            $r->run(defval($_REQUEST, "tagcr_method"));
            $r->apply($assignset);
            $assignset->finish();
            if ($_REQUEST["q"] === "")
                $_REQUEST["q"] = "order:$tagreq";
        } else
            $assignset->error($tagger->error_html);
    }
    if (($errors = join("<br>", $assignset->errors_html()))) {
        if ($assignset->has_assigners()) {
            $Conf->warnMsg("Some tag assignments were ignored:<br>$errors");
            $assignset->clear_errors();
        } else
            Conf::msg_error($errors);
    }
    $success = $assignset->execute();

    if (!$Conf->headerPrinted && defval($_REQUEST, "ajax"))
        $Conf->ajaxExit(array("ok" => $success));
    else if (!$Conf->headerPrinted && $success) {
        if (!$errors)
            $Conf->confirmMsg("Tags saved.");
        $args = array();
        foreach (array("tag", "tagtype", "tagact", "tagcr_method", "tagcr_source", "tagcr_gapless") as $arg)
            if (isset($_REQUEST[$arg]))
                $args[$arg] = $_REQUEST[$arg];
        redirectSelf($args);
    }
}
if (isset($_REQUEST["tagact"]) && $Me->isPC && SearchActions::any()
    && isset($_REQUEST["tag"]) && check_post())
    tagaction();
else if (isset($_REQUEST["tagact"]) && defval($_REQUEST, "ajax"))
    $Conf->ajaxExit(array("ok" => false, "error" => "Malformed request"));


// download votes
if ($getaction == "votes" && SearchActions::any() && defval($_REQUEST, "tag")
    && $Me->isPC) {
    $tagger = new Tagger;
    if (($tag = $tagger->check($_REQUEST["tag"], Tagger::NOVALUE | Tagger::NOCHAIR))) {
        $showtag = trim($_REQUEST["tag"]); // no "23~" prefix
        $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "tagIndex" => $tag)));
        $texts = array();
        while (($row = PaperInfo::fetch($result, $Me)))
            if ($Me->can_view_tags($row, true))
                arrayappend($texts[$row->paperId], array($showtag, (float) $row->tagIndex, $row->paperId, $row->title));
        downloadCSV(SearchActions::reorder($texts), array("tag", "votes", "paper", "title"), "votes");
        exit;
    } else
        Conf::msg_error($tagger->error_html);
}


// download rank
$settingrank = ($Conf->setting("tag_rank") && defval($_REQUEST, "tag") == "~" . $Conf->setting_data("tag_rank"));
if ($getaction == "rank" && SearchActions::any() && defval($_REQUEST, "tag")
    && ($Me->isPC || ($Me->is_reviewer() && $settingrank))) {
    $tagger = new Tagger;
    if (($tag = $tagger->check($_REQUEST["tag"], Tagger::NOVALUE | Tagger::NOCHAIR))) {
        $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "tagIndex" => $tag, "order" => "order by tagIndex, PaperReview.overAllMerit desc, Paper.paperId")));
        $real = "";
        $null = "\n";
        while (($row = PaperInfo::fetch($result, $Me)))
            if ($Me->can_change_tag($row, $tag, null, 1)) {
                if ($row->tagIndex === null)
                    $null .= "X\t$row->paperId\t$row->title\n";
                else if ($real === "" || $lastIndex == $row->tagIndex - 1)
                    $real .= "\t$row->paperId\t$row->title\n";
                else if ($lastIndex == $row->tagIndex)
                    $real .= "=\t$row->paperId\t$row->title\n";
                else
                    $real .= str_pad("", min($row->tagIndex - $lastIndex, 5), ">") . "\t$row->paperId\t$row->title\n";
                $lastIndex = $row->tagIndex;
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
            . "Tag: " . trim($_REQUEST["tag"]) . "\n"
            . "\n"
            . $real . $null;
        downloadText($text, "rank");
        exit;
    } else
        Conf::msg_error($tagger->error_html);
}


// download text author information for selected papers
if ($getaction == "authors" && SearchActions::any()
    && ($Me->privChair || ($Me->isPC && !$Conf->subBlindAlways()))) {
    // first fetch contacts if chair
    $contactline = array();
    if ($Me->privChair) {
        $result = Dbl::qe_raw("select Paper.paperId, title, firstName, lastName, email, affiliation from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ") join ContactInfo on (ContactInfo.contactId=PaperConflict.contactId) where Paper.paperId" . SearchActions::sql_predicate());
        while (($row = edb_orow($result))) {
            $key = $row->paperId . " " . $row->email;
            if ($row->firstName && $row->lastName)
                $a = $row->firstName . " " . $row->lastName;
            else
                $a = $row->firstName . $row->lastName;
            $contactline[$key] = array($row->paperId, $row->title, $a, $row->email, $row->affiliation, "contact_only");
        }
    }

    $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection())));
    $texts = array();
    while (($prow = PaperInfo::fetch($result, $Me))) {
        if (!$Me->can_view_authors($prow, true))
            continue;
        foreach ($prow->author_list() as $au) {
            $line = array($prow->paperId, $prow->title, $au->name(), $au->email, $au->affiliation);

            if ($Me->privChair) {
                $key = $au->email ? $prow->paperId . " " . $au->email : "XXX";
                if (isset($contactline[$key])) {
                    unset($contactline[$key]);
                    $line[] = "contact_author";
                } else
                    $line[] = "author";
            }

            arrayappend($texts[$prow->paperId], $line);
        }
    }

    // If chair, append the remaining non-author contacts
    if ($Me->privChair)
        foreach ($contactline as $key => $line) {
            $paperId = (int) $key;
            arrayappend($texts[$paperId], $line);
        }

    $header = array("paper", "title", "name", "email", "affiliation");
    if ($Me->privChair)
        $header[] = "type";
    downloadCSV(SearchActions::reorder($texts), $header, "authors");
    exit;
}


// download text PC conflict information for selected papers
if ($getaction == "pcconf" && SearchActions::any() && $Me->privChair) {
    $result = Dbl::qe_raw("select Paper.paperId, title, group_concat(concat(PaperConflict.contactId, ':', conflictType) separator ' ')
                from Paper
                left join PaperConflict on (PaperConflict.paperId=Paper.paperId)
                where Paper.paperId" . SearchActions::sql_predicate() . "
                group by Paper.paperId");

    $pcme = array();
    foreach (pcMembers() as $pc)
        $pcme[$pc->contactId] = $pc->email;
    asort($pcme);

    $allConflictTypes = Conflict::$type_descriptions;
    $allConflictTypes[CONFLICT_CHAIRMARK] = "Chair-confirmed";
    $allConflictTypes[CONFLICT_AUTHOR] = "Author";
    $allConflictTypes[CONFLICT_CONTACTAUTHOR] = "Contact";

    if ($result) {
        $texts = array();
        while (($row = edb_row($result))) {
            $x = " " . $row[2];
            foreach ($pcme as $pcid => $pcemail) {
                $pcid = " $pcid:";
                if (($p = strpos($x, $pcid)) !== false) {
                    $ctype = (int) substr($x, $p + strlen($pcid));
                    $ctype = defval($allConflictTypes, $ctype, "Conflict $ctype");
                    arrayappend($texts[$row[0]], array($row[0], $row[1], $pcemail, $ctype));
                }
            }
        }
        downloadCSV(SearchActions::reorder($texts),
                    array("paper", "title", "PC email", "conflict type"),
                    "pcconflicts");
        exit;
    }
}


// download text lead or shepherd information for selected papers
if (($getaction == "lead" || $getaction == "shepherd")
    && SearchActions::any() && $Me->isPC) {
    $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "reviewerName" => $getaction)));
    $shep = $getaction == "shepherd";
    if ($result) {
        $texts = array();
        while (($row = PaperInfo::fetch($result, $Me)))
            if ($row->reviewEmail
                && (($shep && $Me->can_view_shepherd($row, true))
                    || (!$shep && $Me->can_view_lead($row, true))))
                arrayappend($texts[$row->paperId],
                            array($row->paperId, $row->title, $row->reviewEmail, trim("$row->reviewFirstName $row->reviewLastName")));
        downloadCSV(SearchActions::reorder($texts), array("paper", "title", "${getaction}email", "${getaction}name"), "${getaction}s");
        exit;
    }
}


// download text contact author information, with email, for selected papers
if ($getaction == "contact" && $Me->privChair && SearchActions::any()) {
    // Note that this is chair only
    $result = Dbl::qe_raw("select Paper.paperId, title, firstName, lastName, email
	from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")
	join ContactInfo on (ContactInfo.contactId=PaperConflict.contactId)
	where Paper.paperId" . SearchActions::sql_predicate() . " order by Paper.paperId");
    if ($result) {
        $texts = array();
        while (($row = edb_row($result))) {
            $a = ($row[3] && $row[2] ? "$row[3], $row[2]" : "$row[3]$row[2]");
            arrayappend($texts[$row[0]], array($row[0], $row[1], $a, $row[4]));
        }
        downloadCSV(SearchActions::reorder($texts), array("paper", "title", "name", "email"), "contacts");
        exit;
    }
}


// download current assignments
if ($getaction == "pcassignments" && $Me->is_manager() && SearchActions::any()) {
    // Note that this is chair only
    $pcm = pcMembers();
    $round_list = $Conf->round_list();
    $reviewnames = array(REVIEW_PC => "pcreview", REVIEW_SECONDARY => "secondary", REVIEW_PRIMARY => "primary");
    $any_round = false;
    $texts = array();
    $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "assignments" => 1)));
    while (($prow = PaperInfo::fetch($result, $Me)))
        if (!$Me->allow_administer($prow)) {
            $texts[] = array();
            $texts[] = array("paper" => $prow->paperId,
                             "action" => "none",
                             "title" => "You cannot override your conflict with this paper");
        } else if ($prow->assignmentContactIds) {
            $texts[] = array();
            $texts[] = array("paper" => $prow->paperId,
                             "action" => "clearreview",
                             "email" => "#pc",
                             "round" => "any",
                             "title" => $prow->title);
            $cids = explode(",", $prow->assignmentContactIds);
            $rtypes = explode(",", $prow->assignmentReviewTypes);
            $rrounds = explode(",", $prow->assignmentReviewRounds);
            for ($i = 0; $i < count($cids); ++$i)
                if (($pc = @$pcm[$cids[$i]]) && $rtypes[$i] >= REVIEW_PC) {
                    $round = (int) $rrounds[$i];
                    $round_name = $round ? $round_list[$round] : "none";
                    $any_round = $any_round || $round != 0;
                    $texts[] = array("paper" => $prow->paperId,
                                     "action" => $reviewnames[$rtypes[$i]],
                                     "email" => $pc->email,
                                     "round" => $round_name);
                }
        }
    $header = array("paper", "action", "email");
    if ($any_round)
        $header[] = "round";
    $header[] = "title";
    downloadCSV($texts, $header, "pcassignments", array("selection" => $header));
    exit;
}


// download scores and, maybe, anonymity for selected papers
if ($getaction == "scores" && $Me->isPC && SearchActions::any()) {
    $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "allReviewScores" => 1, "reviewerName" => 1)));

    // compose scores; NB chair is always forceShow
    $errors = array();
    $texts = $any_scores = array();
    $any_decision = $any_reviewer_identity = false;
    $rf = ReviewForm::get();
    while (($row = PaperInfo::fetch($result, $Me))) {
        if (!$row->reviewSubmitted)
            /* skip */;
        else if (($whyNot = $Me->perm_view_review($row, null, true)))
            $errors[] = whyNotText($whyNot, "view review") . "<br />";
        else {
            $a = array("paper" => $row->paperId, "title" => $row->title, "blind" => $row->blind);
            if ($row->outcome && $Me->can_view_decision($row, true))
                $a["decision"] = $any_decision = $Conf->decision_name($row->outcome);
            $view_bound = $Me->view_score_bound($row, $row, true);
            $this_scores = false;
            foreach ($rf->forder as $field => $f)
                if ($f->view_score > $view_bound && $f->has_options
                    && ($row->$field || $f->allow_empty)) {
                    $a[$f->abbreviation] = $f->unparse_value($row->$field);
                    $any_scores[$f->abbreviation] = $this_scores = true;
                }
            if ($Me->can_view_review_identity($row, $row, true)) {
                $any_reviewer_identity = true;
                $a["revieweremail"] = $row->reviewEmail;
                $a["reviewername"] = trim($row->reviewFirstName . " " . $row->reviewLastName);
            }
            if ($this_scores)
                arrayappend($texts[$row->paperId], $a);
        }
    }

    if (count($texts)) {
        $header = array("paper", "title");
        if ($Conf->subBlindOptional())
            $header[] = "blind";
        if ($any_decision)
            $header[] = "decision";
        $header = array_merge($header, array_keys($any_scores));
        if ($any_reviewer_identity)
            array_push($header, "revieweremail", "reviewername");
        downloadCSV(SearchActions::reorder($texts), $header, "scores", ["selection" => true]);
        exit;
    } else
        Conf::msg_error(join("", $errors) . "No papers selected.");
}


// download preferences for selected papers
function downloadRevpref($extended) {
    global $Conf, $Me, $Opt;
    // maybe download preferences for someone else
    $Rev = $Me;
    if (($rev = cvtint(@$_REQUEST["reviewer"])) > 0 && $Me->privChair) {
        if (!($Rev = Contact::find_by_id($rev)))
            return Conf::msg_error("No such reviewer");
    }
    $q = $Conf->paperQuery($Rev, array("paperId" => SearchActions::selection(), "topics" => 1, "reviewerPreference" => 1));
    $result = Dbl::qe_raw($q);
    $texts = array();
    list($tmap, $tomap) = array($Conf->topic_map(), $Conf->topic_order_map());
    while ($prow = PaperInfo::fetch($result, $Rev)) {
        $t = $prow->paperId;
        if ($prow->conflictType > 0)
            $t .= ",conflict";
        else
            $t .= "," . unparse_preference($prow);
        $t .= "," . $prow->title . "\n";
        if ($extended) {
            if ($Rev->can_view_authors($prow, false))
                $t .= prefix_word_wrap("#  Authors: ", $prow->pretty_text_author_list(), "#           ");
            $t .= prefix_word_wrap("# Abstract: ", rtrim($prow->abstract), "#           ");
            if ($prow->topicIds != "") {
                $tt = topic_ids_to_text($prow->topicIds, $tmap, $tomap);
                $t .= prefix_word_wrap("#   Topics: ", $tt, "#           ");
            }
            $t .= "\n";
        }
        defappend($texts[$prow->paperId], $t);
    }

    if (count($texts)) {
        $header = "paper,preference,title\n";
        downloadText($header . join("", SearchActions::reorder($texts)), "revprefs");
        exit;
    }
}
if (($getaction == "revpref" || $getaction == "revprefx")
    && $Me->isPC && SearchActions::any())
    downloadRevpref($getaction == "revprefx");


// download all preferences for selected papers
function downloadAllRevpref() {
    global $Conf, $Me, $Opt;
    // maybe download preferences for someone else
    $q = $Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "allReviewerPreference" => 1, "allConflictType" => 1, "topics" => 1));
    $result = Dbl::qe_raw($q);
    $texts = array();
    $pcm = pcMembers();
    $has_conflict = $has_expertise = $has_topic_score = false;
    while (($prow = PaperInfo::fetch($result, $Me))) {
        $out = array();
        $prefs = $prow->reviewer_preferences();
        $conflicts = $prow->conflicts();
        foreach ($pcm as $cid => $p) {
            $pref = @$prefs[$cid] ? : array();
            $conf = @$conflicts[$cid];
            $tv = $prow->topicIds ? $prow->topic_interest_score($p) : 0;
            if ($conf)
                $pref = $tv = "";
            if ($pref || $conf || $tv) {
                $texts[$prow->paperId][] = array("paper" => $prow->paperId, "title" => $prow->title, "name" => Text::name_text($p), "email" => $p->email,
                            "preference" => @$pref[0] ? : "",
                            "expertise" => unparse_expertise(@$pref[1]),
                            "topic_score" => $tv ? : "",
                            "conflict" => ($conf ? "conflict" : ""));
                $has_conflict = $has_conflict || $conf;
                $has_expertise = $has_expertise || @$pref[1] !== null;
                $has_topic_score = $has_topic_score || $tv;
            }
        }
    }

    if (count($texts)) {
        $headers = array("paper", "title", "name", "email", "preference");
        if ($has_expertise)
            $headers[] = "expertise";
        if ($has_topic_score)
            $headers[] = "topic_score";
        if ($has_conflict)
            $headers[] = "conflict";
        downloadCSV(SearchActions::reorder($texts), $headers, "allprefs", ["selection" => true]);
        exit;
    }
}
if ($getaction == "allrevpref" && $Me->privChair && SearchActions::any())
    downloadAllRevpref();


// download topics for selected papers
if ($getaction == "topics" && SearchActions::any()) {
    $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection(), "topics" => 1)));

    $texts = array();
    $tmap = $Conf->topic_map();
    $tomap = $Conf->topic_order_map();

    while (($row = PaperInfo::fetch($result, $Me))) {
        if (!$Me->can_view_paper($row))
            continue;
        $out = array();
        $topicIds = ($row->topicIds == "" ? "x" : $row->topicIds);
        foreach (explode(",", $topicIds) as $tid) {
            if ($tid === "")
                continue;
            else if ($tid === "x")
                list($order, $name) = array(99999, "<none>");
            else
                list($order, $name) = array($tomap[$tid], $tmap[$tid]);
            $out[$order] = array($row->paperId, $row->title, $name);
        }
        ksort($out);
        arrayappend($texts[$row->paperId], $out);
    }

    if (count($texts)) {
        downloadCSV(SearchActions::reorder($texts), array("paper", "title", "topic"), "topics");
        exit;
    } else
        Conf::msg_error(join("", $errors) . "No papers selected.");
}


// download format checker reports for selected papers
if ($getaction == "checkformat" && $Me->privChair && SearchActions::any()) {
    $result = Dbl::qe_raw("select paperId, title, mimetype from Paper where paperId" . SearchActions::sql_predicate() . " order by paperId");
    $format = $Conf->setting_data("sub_banal", "");

    // generate output gradually since this takes so long
    downloadText(false, "formatcheck", false);
    echo "#paper\tformat\tpages\ttitle\n";

    // compose report
    $texts = array();
    while ($row = edb_row($result))
        $texts[$row[0]] = $row;
    foreach (SearchActions::reorder($texts) as $row) {
        if ($row[2] == "application/pdf") {
            $cf = new CheckFormat;
            if ($cf->analyzePaper($row[0], false, $format)) {
                $fchk = array();
                foreach (CheckFormat::$error_types as $en => $etxt)
                    if ($cf->errors & $en)
                        $fchk[] = $etxt;
                $fchk = (count($fchk) ? join(",", $fchk) : "ok");
                $pp = $cf->pages;
            } else {
                $fchk = "error";
                $pp = "?";
            }
        } else {
            $fchk = "notpdf";
            $pp = "?";
        }
        echo $row[0], "\t", $fchk, "\t", $pp, "\t", $row[1], "\n";
        ob_flush();
        flush();
    }

    exit;
}


// download ACM CMS information for selected papers
if ($getaction == "acmcms" && SearchActions::any() && $Me->privChair) {
    $idq = "p.paperId" . SearchActions::sql_predicate();

    // analyze paper page counts
    $pagecount = array();
    $result = Dbl::qe_raw("select p.paperId, p.finalPaperStorageId, ps.infoJson from Paper p join PaperStorage ps on (ps.paperStorageId=if(p.finalPaperStorageId=0,p.paperStorageId,p.finalPaperStorageId)) where $idq");
    while (($row = edb_row($result)))
        if ($row[2] && ($j = json_decode($row[2])) && isset($j->npages))
            $pagecount[$row[0]] = $j->npages;
        else {
            $cf = new CheckFormat;
            if ($cf->analyzePaper($row[0], !!$row[1]))
                $pagecount[$row[0]] = $cf->pages;
        }

    // generate report
    $result = Dbl::qe_raw("select paperId, title, authorInformation from Paper p where $idq");
    $texts = array();
    while (($row = PaperInfo::fetch($result, $Me))) {
        $papertype = "Full Paper";
        if (isset($pagecount[$row->paperId]) && $pagecount[$row->paperId] < 5)
            $papertype = "Short Paper";
        $aun = $aue = [];
        foreach ($row->author_list() as $au) {
            $aun[] = ($au->name() ? : "Unknown")
                . ":" . ($au->affiliation ? : "Unaffiliated");
            $aue[] = $au->email ? : "unknown@example.com";
        }
        $texts[$row->paperId] = [
            "papertype" => $papertype, "title" => $row->title,
            "authors" => join(";", $aun), "leademail" => (string) @$aue[0],
            "emails" => join(";", array_splice($aue, 1))
        ];
    }
    $texts = SearchActions::reorder($texts);
    downloadCSV(SearchActions::reorder($texts), false, "acmcms",
                ["selection" => ["papertype", "title", "authors", "leademail", "emails"],
                 "always_quote" => true]);
    exit;
}


// download status JSON for selected papers
if ($getaction == "json" && SearchActions::any() && $Me->privChair) {
    $pj = array();
    $ps = new PaperStatus($Me, ["forceShow" => true]);
    foreach (SearchActions::selection() as $pid)
        if (($j = $ps->load($pid)))
            $pj[] = $j;
    if (count($pj) == 1)
        $pj = $pj[0];
    header("Content-Type: application/json");
    header("Content-Disposition: attachment; filename=" . mime_quote_string($Opt["downloadPrefix"] . (is_array($pj) ? "" : "paper" . SearchActions::selection_at(0) . "-") . "data.json"));
    echo json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}


// download status JSON plus documents for selected papers
function jsonattach_document($dj, $prow, $dtype, $drow) {
    global $jsonattach_zip;
    if ($drow->docclass->load($drow)) {
        $dj->content_file = HotCRPDocument::filename($drow);
        $jsonattach_zip->add_as($drow, $dj->content_file);
    }
}

if ($getaction == "jsonattach" && SearchActions::any() && $Me->privChair) {
    global $jsonattach_zip;
    $jsonattach_zip = new ZipDocument($Opt["downloadPrefix"] . "data.zip");
    $pj = array();
    $ps = new PaperStatus($Me, ["forceShow" => true]);
    $ps->add_document_callback("jsonattach_document");
    foreach (SearchActions::selection() as $pid)
        if (($j = $ps->load($pid)))
            $pj[] = $j;
        else
            $jsonattach_zip->warnings[] = "#$pid: No such paper";
    if (count($pj) == 1)
        $pj = $pj[0];
    $jsonattach_zip->add(json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
                         $Opt["downloadPrefix"] . (is_array($pj) ? "" : "paper" . SearchActions::selection_at(0) . "-") . "data.json");
    $result = $jsonattach_zip->download();
    exit;
}


// set outcome for selected papers
function search_set_decisions() {
    global $Conf, $Me;
    $o = cvtint(@$_REQUEST["decision"]);
    $decision_map = $Conf->decision_map();
    if (!isset($decision_map[$o]))
        return Conf::msg_error("Bad decision value.");
    $result = Dbl::qe_raw($Conf->paperQuery($Me, array("paperId" => SearchActions::selection())));
    $success = $fails = array();
    while (($prow = PaperInfo::fetch($result, $Me)))
        if ($Me->can_set_decision($prow))
            $success[] = $prow->paperId;
        else
            $fails[] = "#" . $prow->paperId;
    if (count($fails))
        Conf::msg_error("You cannot set paper decisions for " . pluralx($fails, "paper") . " " . commajoin($fails) . ".");
    if (count($success)) {
        Dbl::qe("update Paper set outcome=$o where paperId ?a", $success);
        $Conf->update_paperacc_setting($o > 0);
        redirectSelf(array("atab" => "decide", "decision" => $o));
    }
    $_REQUEST["atab"] = "decide";
}
if (isset($_REQUEST["setdecision"]) && defval($_REQUEST, "decision", "") != ""
    && SearchActions::any() && check_post())
    search_set_decisions();


// "Assign"
if (isset($_REQUEST["setassign"]) && defval($_REQUEST, "marktype", "") != ""
    && SearchActions::any() && check_post()) {
    $mt = $_REQUEST["marktype"];
    $mpc = defval($_REQUEST, "markpc", "0");
    $pc = ($mpc == "0" ? null : Contact::find_by_email($mpc));

    if (!$Me->privChair)
        Conf::msg_error("Only PC chairs can set assignments and conflicts.");
    else if ($mt == "auto") {
        $t = (in_array($_REQUEST["t"], array("acc", "s")) ? $_REQUEST["t"] : "all");
        $q = join("+", SearchActions::selection());
        go(hoturl("autoassign", "pap=$q&t=$t&q=$q"));
    } else if ($mt == "lead" || $mt == "shepherd") {
        if ($Me->assign_paper_pc(SearchActions::selection(), $mt, $pc))
            $Conf->confirmMsg(ucfirst(pluralx(SearchActions::selection(), $mt)) . " set.");
        else if ($OK)
            $Conf->confirmMsg("No changes.");
    } else if (!$pc)
        Conf::msg_error("“" . htmlspecialchars($mpc) . "” is not a PC member.");
    else if ($mt == "conflict" || $mt == "unconflict") {
        if ($mt == "conflict") {
            Dbl::qe("insert into PaperConflict (paperId, contactId, conflictType) (select paperId, ?, ? from Paper where paperId" . SearchActions::sql_predicate() . ") on duplicate key update conflictType=greatest(conflictType, values(conflictType))", $pc->contactId, CONFLICT_CHAIRMARK);
            $Me->log_activity("Mark conflicts with $mpc", SearchActions::selection());
        } else {
            Dbl::qe("delete from PaperConflict where PaperConflict.conflictType<? and contactId=? and (paperId" . SearchActions::sql_predicate() . ")", CONFLICT_AUTHOR, $pc->contactId);
            $Me->log_activity("Remove conflicts with $mpc", SearchActions::selection());
        }
    } else if (substr($mt, 0, 6) == "assign"
               && ($asstype = substr($mt, 6))
               && isset(ReviewForm::$revtype_names[$asstype])) {
        Dbl::qe_raw("lock tables PaperConflict write, PaperReview write, PaperReviewRefused write, Paper write, ActionLog write, Settings write");
        $result = Dbl::qe_raw("select Paper.paperId, reviewId, reviewType, reviewModified, conflictType from Paper left join PaperReview on (Paper.paperId=PaperReview.paperId and PaperReview.contactId=" . $pc->contactId . ") left join PaperConflict on (Paper.paperId=PaperConflict.paperId and PaperConflict.contactId=" . $pc->contactId .") where Paper.paperId" . SearchActions::sql_predicate());
        $conflicts = array();
        $assigned = array();
        $nworked = 0;
        while (($row = PaperInfo::fetch($result, $Me))) {
            if ($asstype && $row->conflictType > 0)
                $conflicts[] = $row->paperId;
            else if ($asstype && $row->reviewType >= REVIEW_PC && $asstype != $row->reviewType)
                $assigned[] = $row->paperId;
            else {
                $Me->assign_review($row->paperId, $pc->contactId, $asstype);
                $nworked++;
            }
        }
        if (count($conflicts))
            Conf::msg_error("Some papers were not assigned because of conflicts (" . join(", ", $conflicts) . ").  If these conflicts are in error, remove them and try to assign again.");
        if (count($assigned))
            Conf::msg_error("Some papers were not assigned because the PC member already had an assignment (" . join(", ", $assigned) . ").");
        if ($nworked)
            $Conf->confirmMsg(($asstype == 0 ? "Unassigned reviews." : "Assigned reviews."));
        Dbl::qe_raw("unlock tables");
        $Conf->update_rev_tokens_setting(false);
    }
}


// send mail
if (isset($_REQUEST["sendmail"]) && SearchActions::any()) {
    if ($Me->privChair) {
        $r = (in_array($_REQUEST["recipients"], array("au", "rev")) ? $_REQUEST["recipients"] : "all");
        if (SearchActions::selection_equals_search(new PaperSearch($Me, $_REQUEST)))
            $x = "q=" . urlencode($_REQUEST["q"]) . "&plimit=1";
        else
            $x = "p=" . join("+", SearchActions::selection());
        go(hoturl("mail", $x . "&t=" . urlencode($_REQUEST["t"]) . "&recipients=$r"));
    } else
        Conf::msg_error("Only the PC chairs can send mail.");
}


// set fields to view
if (isset($_REQUEST["redisplay"])) {
    $pld = " ";
    foreach ($_REQUEST as $k => $v)
        if (substr($k, 0, 4) == "show" && $v)
            $pld .= substr($k, 4) . " ";
    $Conf->save_session("pldisplay", $pld);
}
displayOptionsSet("pldisplay");
if (defval($_REQUEST, "scoresort") == "M")
    $_REQUEST["scoresort"] = "C";
if (isset($_REQUEST["scoresort"])
    && isset(ListSorter::$score_sorts[$_REQUEST["scoresort"]]))
    $Conf->save_session("scoresort", $_REQUEST["scoresort"]);
if (!$Conf->session("scoresort"))
    $Conf->save_session("scoresort", ListSorter::default_score_sort());
if (isset($_REQUEST["redisplay"]))
    redirectSelf(array("tab" => "display"));


// save display options
if (isset($_REQUEST["savedisplayoptions"]) && $Me->privChair) {
    if ($Conf->session("pldisplay") !== " overAllMerit ") {
        $pldisplay = explode(" ", trim($Conf->session("pldisplay")));
        sort($pldisplay);
        $pldisplay = " " . simplify_whitespace(join(" ", $pldisplay)) . " ";
        $Conf->save_session("pldisplay", $pldisplay);
        Dbl::qe_raw("insert into Settings (name, value, data) values ('pldisplay_default', 1, '" . sqlq($pldisplay) . "') on duplicate key update data=values(data)");
    } else
        Dbl::qe_raw("delete from Settings where name='pldisplay_default'");
    if ($Conf->session("scoresort") != "C")
        Dbl::qe_raw("insert into Settings (name, value, data) values ('scoresort_default', 1, '" . sqlq($Conf->session("scoresort")) . "') on duplicate key update data=values(data)");
    else
        Dbl::qe_raw("delete from Settings where name='scoresort_default'");
    if ($OK && defval($_REQUEST, "ajax"))
        $Conf->ajaxExit(array("ok" => 1));
    else if ($OK)
        $Conf->confirmMsg("Display options saved.");
}


// save formula
function formulas_with_new() {
    $formulas = FormulaPaperColumn::$list;
    $formulas["n"] = (object) array("formulaId" => "n", "name" => "",
                                    "expression" => "", "createdBy" => 0);
    return $formulas;
}

function saveformulas() {
    global $Conf, $Me, $OK;

    // parse names and expressions
    $ok = true;
    $changes = array();
    $names = array();

    foreach (formulas_with_new() as $fdef) {
        $name = simplify_whitespace(defval($_REQUEST, "name_$fdef->formulaId", $fdef->name));
        $expr = simplify_whitespace(defval($_REQUEST, "expression_$fdef->formulaId", $fdef->expression));

        if ($name != "" && $expr != "") {
            if (isset($names[$name]))
                $ok = Conf::msg_error("You have two formulas named “" . htmlspecialchars($name) . "”.  Please change one of the names.");
            $names[$name] = true;
        }

        if ($name == $fdef->name && $expr == $fdef->expression)
            /* do nothing */;
        else if (!$Me->privChair && $fdef->createdBy < 0)
            $ok = Conf::msg_error("You can’t change formula “" . htmlspecialchars($fdef->name) . "” because it was created by an administrator.");
        else if (($name == "" || $expr == "") && $fdef->formulaId != "n")
            $changes[] = "delete from Formula where formulaId=$fdef->formulaId";
        else if ($name == "")
            $ok = Conf::msg_error("Please enter a name for your new formula.");
        else if ($expr == "")
            $ok = Conf::msg_error("Please enter a definition for your new formula.");
        else {
            $formula = new Formula($expr);
            if (!$formula->check())
                $ok = Conf::msg_error($formula->error_html());
            else {
                $exprViewScore = $formula->view_score($Me);
                if ($exprViewScore <= $Me->permissive_view_score_bound())
                    $ok = Conf::msg_error("The expression “" . htmlspecialchars($expr) . "” refers to paper properties that you aren’t allowed to view. Please define a different expression.");
                else if ($fdef->formulaId == "n") {
                    $changes[] = "insert into Formula (name, heading, headingTitle, expression, createdBy, timeModified) values ('" . sqlq($name) . "', '', '', '" . sqlq($expr) . "', " . ($Me->privChair ? -$Me->contactId : $Me->contactId) . ", " . time() . ")";
                    if (!$Conf->setting("formulas"))
                        $changes[] = "insert into Settings (name, value) values ('formulas', 1) on duplicate key update value=1";
                } else
                    $changes[] = "update Formula set name='" . sqlq($name) . "', expression='" . sqlq($expr) . "', timeModified=" . time() . " where formulaId=$fdef->formulaId";
            }
        }
    }

    $_REQUEST["tab"] = "formulas";
    if ($ok) {
        foreach ($changes as $change)
            Dbl::qe_raw($change);
        if ($OK) {
            $Conf->confirmMsg("Formulas saved.");
            redirectSelf();
        }
    }
}

if (isset($_REQUEST["saveformulas"]) && $Me->isPC && check_post())
    saveformulas();


// save formula
function savesearch() {
    global $Conf, $Me, $OK;

    $name = simplify_whitespace(defval($_REQUEST, "ssname", ""));
    $tagger = new Tagger;
    if (!$tagger->check($name, Tagger::NOPRIVATE | Tagger::NOCHAIR | Tagger::NOVALUE)) {
        if ($name == "")
            return Conf::msg_error("Saved search name missing.");
        else
            return Conf::msg_error("“" . htmlspecialchars($name) . "” contains characters not allowed in saved search names.  Stick to letters, numbers, and simple punctuation.");
    }

    // support directly recursive definition (to e.g. change display options)
    if (($t = $Conf->setting_data("ss:$name")) && ($t = json_decode($t))) {
        if (isset($_REQUEST["q"]) && $_REQUEST["q"] == "ss:$name")
            $_REQUEST["q"] = (isset($t->q) ? $t->q : "");
        if (isset($t->owner) && !$Me->privChair && $t->owner != $Me->contactId)
            return Conf::msg_error("You don’t have permission to change “ss:" . htmlspecialchars($name) . "”.");
    }

    $arr = array();
    foreach (array("q", "qt", "t", "sort") as $k)
        if (isset($_REQUEST[$k]))
            $arr[$k] = $_REQUEST[$k];
    if ($Me->privChair)
        $arr["owner"] = "chair";
    else
        $arr["owner"] = $Me->contactId;

    // clean display settings
    if ($Conf->session("pldisplay")) {
        $acceptable = array("abstract" => 1, "topics" => 1, "tags" => 1,
                            "rownum" => 1, "reviewers" => 1, "searchopts" => 1,
                            "pcconf" => 1, "lead" => 1, "shepherd" => 1);
        if (!$Conf->subBlindAlways() || $Me->privChair)
            $acceptable["au"] = $acceptable["aufull"] = $acceptable["collab"] = 1;
        if ($Me->privChair && !$Conf->subBlindNever())
            $acceptable["anonau"] = 1;
        foreach (ReviewForm::all_fields() as $f)
            $acceptable[$f->id] = 1;
        foreach (FormulaPaperColumn::$list as $x)
            $acceptable["formula" . $x->formulaId] = 1;
        $display = array();
        foreach (preg_split('/\s+/', $Conf->session("pldisplay")) as $x)
            if (isset($acceptable[$x]))
                $display[$x] = true;
        ksort($display);
        $arr["display"] = trim(join(" ", array_keys($display)));
    }

    if (isset($_REQUEST["deletesearch"])) {
        Dbl::qe_raw("delete from Settings where name='ss:" . sqlq($name) . "'");
        redirectSelf();
    } else {
        Dbl::qe_raw("insert into Settings (name, value, data) values ('ss:" . sqlq($name) . "', " . $Me->contactId . ", '" . sqlq(json_encode($arr)) . "') on duplicate key update value=values(value), data=values(data)");
        redirectSelf(array("q" => "ss:" . $name, "qa" => null, "qo" => null, "qx" => null));
    }
}

if ((isset($_REQUEST["savesearch"]) || isset($_REQUEST["deletesearch"]))
    && $Me->isPC && check_post()) {
    savesearch();
    $_REQUEST["tab"] = "ss";
}


// exit early if Ajax
if (defval($_REQUEST, "ajax"))
    $Conf->ajaxExit(array("response" => ""));


// set display options, including forceShow if chair
$pldisplay = $Conf->session("pldisplay");
if ($Me->privChair) {
    if (strpos($pldisplay, " force ") !== false)
        $_REQUEST["forceShow"] = 1;
    else
        unset($_REQUEST["forceShow"]);
}


// search
$Conf->header("Search", "search", actionBar());
$Conf->echoScript(); // need the JS right away
$Search = new PaperSearch($Me, $_REQUEST);
if (isset($_REQUEST["q"])) {
    $pl = new PaperList($Search, ["sort" => true, "list" => true, "row_id_pattern" => "p#", "display" => defval($_REQUEST, "display")], make_qreq());
    if (check_post())
        $pl->papersel = SearchActions::selection_map();
    $pl_text = $pl->table_html($Search->limitName, [
            "class" => "pltable_full", "table_id" => "foldpl",
            "attributes" => ["data-fold-session" => 'pldisplay.$']
        ]);
    $pldisplay = $pl->display;
    unset($_REQUEST["atab"], $_GET["atab"], $_POST["atab"]);
} else
    $pl = null;


// set up the search form
if (isset($_REQUEST["redisplay"]))
    $activetab = 3;
else if (isset($_REQUEST["qa"]) || defval($_REQUEST, "qt", "n") != "n")
    $activetab = 2;
else
    $activetab = 1;
$tabs = array("display" => 3, "advanced" => 2, "basic" => 1, "normal" => 1,
              "ss" => 4);
$searchform_formulas = "c";
if (isset($tabs[defval($_REQUEST, "tab", "x")]))
    $activetab = $tabs[$_REQUEST["tab"]];
else if (defval($_REQUEST, "tab", "x") == "formulas") {
    $activetab = 3;
    $searchform_formulas = "o";
}
if ($activetab == 3 && (!$pl || $pl->count == 0))
    $activetab = 1;

$tselect = PaperSearch::searchTypeSelector($tOpt, $_REQUEST["t"], 1);


// SEARCH FORMS

// Prepare more display options
$displayOptions = array();
$display_options_extra = "";

function display_option_checked($type) {
    global $pl, $pldisplay;
    if ($pl)
        return !$pl->is_folded($type);
    else
        return defval($_REQUEST, "show$type") || strpos($pldisplay, " $type ") !== false;
}

function displayOptionCheckbox($type, $column, $title, $opt = array()) {
    global $displayOptions;
    $checked = display_option_checked($type);
    $loadresult = "";

    if (!isset($opt["onchange"])) {
        $opt["onchange"] = "plinfo('$type',this)";
        $loadresult = "<div id='${type}loadformresult'></div>";
    } else
        $loadresult = "<div></div>";
    $opt["class"] = "cbx";

    $text = Ht::checkbox("show$type", 1, $checked, $opt)
        . "&nbsp;" . Ht::label($title) . $loadresult;
    $displayOptions[] = (object) array("type" => $type, "text" => $text,
                "checked" => $checked, "column" => $column,
                "indent" => defval($opt, "indent"));
}

function displayOptionText($text, $column, $opt = array()) {
    global $displayOptions;
    $displayOptions[] = (object) array("text" => $text,
                "column" => $column, "indent" => defval($opt, "indent"));
}

// Create checkboxes

if ($pl) {
    $viewAcceptedAuthors =
        $Me->is_reviewer() && $Conf->timeReviewerViewAcceptedAuthors();
    $viewAllAuthors = ($_REQUEST["t"] == "a"
                       || ($_REQUEST["t"] == "acc" && $viewAcceptedAuthors)
                       || $Conf->subBlindNever());

    displayOptionText("<strong>Show:</strong>", 1);

    // Authors group
    if (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors) {
        displayOptionCheckbox("au", 1, "Authors", array("id" => "showau"));
        if ($Me->privChair && $viewAllAuthors)
            $display_options_extra .=
                Ht::checkbox("showanonau", 1, display_option_checked("au"),
                             array("id" => "showau_hidden",
                                   "onchange" => "plinfo('anonau',this)",
                                   "style" => "display:none"));
    } else if ($Me->privChair && $Conf->subBlindAlways()) {
        displayOptionCheckbox("anonau", 1, "Authors (deblinded)", array("id" => "showau", "disabled" => (!$pl || !$pl->any->anonau)));
        $display_options_extra .=
            Ht::checkbox("showau", 1, display_option_checked("anonau"),
                         array("id" => "showau_hidden",
                               "onchange" => "plinfo('au',this)",
                               "style" => "display:none"));
    }
    if (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors || $Me->privChair)
        displayOptionCheckbox("aufull", 1, "Full author info", array("id" => "showaufull", "indent" => true));
    if ($Me->privChair && !$Conf->subBlindNever()
        && (!$Conf->subBlindAlways() || $viewAcceptedAuthors || $viewAllAuthors))
        displayOptionCheckbox("anonau", 1, "Deblinded authors", array("disabled" => (!$pl || !$pl->any->anonau), "indent" => true));
    if ($pl->any->collab)
        displayOptionCheckbox("collab", 1, "Collaborators", array("indent" => true));

    // Search result options
    global $Opt;
    if (!empty($Opt["summaryTemplate"]))
        displayOptionCheckbox("searchopts", 1, "Searched paper options");

    // Abstract group
    if ($pl->any->abstract)
        displayOptionCheckbox("abstract", 1, "Abstracts");
    if ($pl->any->topics)
        displayOptionCheckbox("topics", 1, "Topics");

    // Tags group
    if ($Me->isPC && $pl->any->tags) {
        $opt = array("disabled" => ($_REQUEST["t"] == "a" && !$Me->privChair));
        displayOptionCheckbox("tags", 1, "Tags", $opt);
        if ($Me->privChair) {
            $opt["indent"] = true;
            foreach (TagInfo::defined_tags() as $t)
                if ($t->vote || $t->approval || $t->rank)
                    displayOptionCheckbox("tagrep_" . preg_replace('/\W+/', '_', $t->tag), 1, "#~" . $t->tag . " tags", $opt);
        }
    }

    // Row numbers
    if (isset($pl->any->sel))
        displayOptionCheckbox("rownum", 1, "Row numbers", array("onchange" => "fold('pl',!this.checked,'rownum')"));

    // Reviewers group
    if ($Me->can_view_some_review_identity(true))
        displayOptionCheckbox("reviewers", 2, "Reviewers");
    if ($Me->privChair) {
        displayOptionCheckbox("allrevpref", 2, "Review preferences");
        displayOptionCheckbox("pcconf", 2, "PC conflicts");
    }
    if ($Me->isPC && $pl->any->lead)
        displayOptionCheckbox("lead", 2, "Discussion leads");
    if ($Me->isPC && $pl->any->shepherd)
        displayOptionCheckbox("shepherd", 2, "Shepherds");

    // Scores group
    if ($pl->scoresOk == "present") {
        $rf = ReviewForm::get();
        if ($Me->is_reviewer() && $_REQUEST["t"] != "a")
            $revViewScore = $Me->permissive_view_score_bound();
        else
            $revViewScore = VIEWSCORE_AUTHOR - 1;
        $n = count($displayOptions);
        $nchecked = 0;
        foreach ($rf->forder as $f)
            if ($f->view_score > $revViewScore && $f->has_options) {
                if (count($displayOptions) == $n)
                    displayOptionText("<strong>Scores:</strong>", 3);
                displayOptionCheckbox($f->id, 3, $f->name_html);
                if ($displayOptions[count($displayOptions) - 1]->checked)
                    ++$nchecked;
            }
        if (count($displayOptions) > $n) {
            $onchange = "highlightUpdate(\"redisplay\")";
            if ($Me->privChair)
                $onchange .= ";plinfo.extra()";
            displayOptionText("<div style='padding-top:1ex'>Sort by: &nbsp;"
                              . Ht::select("scoresort", ListSorter::$score_sorts, $Conf->session("scoresort"), array("onchange" => $onchange, "id" => "scoresort", "style" => "font-size: 100%"))
                . "<a class='help' href='" . hoturl("help", "t=scoresort") . "' target='_blank' title='Learn more'>?</a></div>", 3);
        }
    }

    // Formulas group
    if (count(FormulaPaperColumn::$list)) {
        displayOptionText("<strong>Formulas:</strong>", 4);
        foreach (FormulaPaperColumn::$list as $formula)
            displayOptionCheckbox("formula" . $formula->formulaId, 4, htmlspecialchars($formula->name));
    }
}


echo "<table id='searchform' class='tablinks$activetab fold3$searchform_formulas'>
<tr><td><div class='tlx'><div class='tld1'>";

// Basic search
echo Ht::form_div(hoturl("search"), array("method" => "get")),
    Ht::entry("q", defval($_REQUEST, "q", ""),
              array("id" => "searchform1_d", "size" => 40, "tabindex" => 1,
                    "style" => "width:30em", "class" => "hotcrp_searchbox")),
    " &nbsp;in &nbsp;$tselect &nbsp;\n",
    Ht::submit("Search"),
    "</div></form>";

echo "</div><div class='tld2'>";

// Advanced search
echo Ht::form_div(hoturl("search"), array("method" => "get")),
    "<table><tr>
  <td class='lxcaption'>Search these papers</td>
  <td class='lentry'>$tselect</td>
</tr>
<tr>
  <td class='lxcaption'>Using these fields</td>
  <td class='lentry'>";
$qtOpt = array("ti" => "Title",
               "ab" => "Abstract");
if ($Me->privChair || $Conf->subBlindNever()) {
    $qtOpt["au"] = "Authors";
    $qtOpt["n"] = "Title, abstract, and authors";
} else if ($Conf->subBlindAlways() && $Me->is_reviewer() && $Conf->timeReviewerViewAcceptedAuthors()) {
    $qtOpt["au"] = "Accepted authors";
    $qtOpt["n"] = "Title and abstract, and accepted authors";
} else if (!$Conf->subBlindAlways()) {
    $qtOpt["au"] = "Non-blind authors";
    $qtOpt["n"] = "Title and abstract, and non-blind authors";
} else
    $qtOpt["n"] = "Title and abstract";
if ($Me->privChair)
    $qtOpt["ac"] = "Authors and collaborators";
if ($Me->isPC) {
    $qtOpt["re"] = "Reviewers";
    $qtOpt["tag"] = "Tags";
}
if (!isset($qtOpt[defval($_REQUEST, "qt", "")]))
    $_REQUEST["qt"] = "n";
echo Ht::select("qt", $qtOpt, $_REQUEST["qt"], array("tabindex" => 1)),
    "</td>
</tr>
<tr><td><div class='g'></div></td></tr>
<tr>
  <td class='lxcaption'>With <b>all</b> the words</td>
  <td class='lentry'><input id='searchform2_d' type='text' size='40' style='width:30em' name='qa' value=\"", htmlspecialchars(defval($_REQUEST, "qa", defval($_REQUEST, "q", ""))), "\" tabindex='1' /><span class='sep'></span></td>
  <td rowspan='3'>", Ht::submit("Search", array("tabindex" => 2)), "</td>
</tr><tr>
  <td class='lxcaption'>With <b>any</b> of the words</td>
  <td class='lentry'><input type='text' size='40' name='qo' style='width:30em' value=\"", htmlspecialchars(defval($_REQUEST, "qo", "")), "\" tabindex='1' /></td>
</tr><tr>
  <td class='lxcaption'><b>Without</b> the words</td>
  <td class='lentry'><input type='text' size='40' name='qx' style='width:30em' value=\"", htmlspecialchars(defval($_REQUEST, "qx", "")), "\" tabindex='1' /></td>
</tr>";
if ($opt = PaperOption::option_list()) {
    echo "<tr>
<td class='lxcaption'>Additional options</td>
<td class='lentry' id='lentry_adtl_ops'>
<input type='button' id='lentry_adtl_ops_add' onclick='search_addOption();' value='+ Add option' />";
        
    // From settings.php.
    function option_search_term($oname) {
        $owords = preg_split(',[^a-z_0-9]+,', strtolower(trim($oname)));
        for ($i = 0; $i < count($owords); ++$i) {
            $attempt = join("-", array_slice($owords, 0, $i + 1));
            if (count(PaperOption::search($attempt)) == 1)
                return $attempt;
        }
        return simplify_whitespace($oname);
    }

    // Add initial options. This script is actually pretty hacky :(, but it handles numeric,
    // checkbox, and text fields. Also, this code is probably riddled with SQL injection exploits.
    $filtered_opt = array_filter($opt, function($o) {
        $o->search_term = option_search_term($o->name);
        return $o->type == "numeric" || $o->type == "checkbox" || $o->type == "text";
    });
    echo "<script type='text/javascript'>
        var options = " . json_encode(array_values($filtered_opt)) . ";
        
        var clearOptions = function(select) {
            for (var i = select.options.length - 1; i >= 0; i--) {
                select.remove(i);
            }
        };

        var appendOptionToSelect = function(value, textContent, select) {
            var el = document.createElement('option');
            el.value = value;
            el.textContent = textContent;
            select.appendChild(el);
        };

        window.search_addOption = function(selectedValue, operator, type, value) {
            var optionDiv = document.createElement('div');

            // Add options.
            var selectOpt = document.createElement('select');
            for (var i = 0; i < options.length; i++) {
                appendOptionToSelect(options[i].search_term, options[i].description || options[i].name, selectOpt);
            }
            selectOpt.value = selectedValue;
            selectOpt.style.width = '11em';
            selectOpt.style['margin-right'] = '0.5em';
            optionDiv.appendChild(selectOpt);
            
            // Add operators.
            var selectOp = document.createElement('select');
            selectOp.style.width = '7em';
            selectOp.style['margin-right'] = '0.5em';
            optionDiv.appendChild(selectOp);

            // Add value box.
            var valueOpt = document.createElement('input');
            valueOpt.style.width = '8em';
            valueOpt.style['margin-right'] = '2.5em';

            // Add hidden box (for default value for checkboxes).
            var valueOptHidden = document.createElement('input');
            valueOptHidden.type = 'hidden';
            valueOptHidden.value = 'no';
            valueOptHidden.disabled = true;

            selectOpt.onchange = (function(_opt, _op, _value, _valueHidden) {return function() {
                _value.name = 'qao_' + _opt.value;
                _valueHidden.name = _value.name;
                _op.name = 'qao_' + _opt.value + '_op';
                
                var type = options.filter(function(o) {return o.search_term == _opt.value;})[0].type;
                switch (type) {
                    case 'numeric':
                        _value.type = 'number';
                        _value.value = '0';
                        _valueHidden.disabled = true;
                        _op.style.display = '';
                        clearOptions(_op);
                        appendOptionToSelect('IS_NUM', 'is', _op);
                        appendOptionToSelect('IS_AT_LEAST', 'is at least', _op);
                        appendOptionToSelect('IS_LESS_THAN', 'is less than', _op);
                        break;
                    case 'checkbox':
                        _value.type = 'checkbox';
                        _value.checked = false;
                        _value.value = 'yes';
                        _valueHidden.disabled = false;
                        _op.style.display = 'none';
                        clearOptions(_op);
                        appendOptionToSelect('CHECKBOX', 'is checked', _op);
                        break;
                    default:
                        _value.type = 'text';
                        _value.value = '';
                        _value.checked = false;
                        _valueHidden.disabled = true;
                        _op.style.display = '';
                        clearOptions(_op);
                        appendOptionToSelect('IS', 'is', _op);
                        appendOptionToSelect('STARTS_WITH', 'starts with', _op);
                        appendOptionToSelect('ENDS_WITH', 'ends with', _op);
                        appendOptionToSelect('CONTAINS', 'contains', _op);
                }
            }})(selectOpt, selectOp, valueOpt, valueOptHidden);
            selectOpt.onchange();
            switch (type) {
                case 'numeric':
                    valueOpt.value = value || '';
                    break;
                case 'checkbox':
                    operator = 'CHECKBOX';
                    valueOpt.checked = value == 'yes';
                    break;
                default:
                    valueOpt.value = value || '';
            };
            selectOp.value = operator;
            optionDiv.appendChild(valueOptHidden);
            optionDiv.appendChild(valueOpt);
            
            // Add remove button.
            var buttonRemove = document.createElement('input');
            buttonRemove.type = 'button';
            buttonRemove.style.width = '2em';
            buttonRemove.value = 'x';
            buttonRemove.onclick = function() {optionDiv.parentElement.removeChild(optionDiv);};
            optionDiv.appendChild(buttonRemove);
            
            // Add the whole thing to the document.
            var container = document.getElementById('lentry_adtl_ops');
            var addButton = document.getElementById('lentry_adtl_ops_add');
            container.insertBefore(optionDiv, addButton);
        };";

    // Add initial values based on passed-in parameters. Defer execution so that the button
    // is constructed in the DOM.
    foreach ($opt as $o) {
        $oName = option_search_term($o->name);
        $optArg = defval($_REQUEST, "qao_" . $oName);
        $optArgOp = defval($_REQUEST, "qao_" . $oName . "_op");
        if ($optArg && $optArgOp) {
            $optArg = urldecode($optArg);
            $optArgOp = urldecode($optArgOp);
            echo "\nwindow.search_addOption('" . $oName . "', '" . $optArgOp . "', '" . $o->type . "', '" . $optArg . "');";
        }
    }
        
    echo "</script>";
    echo "</td></tr>";
}
echo "<tr>
  <td class='lxcaption'></td>
  <td><span style='font-size: x-small'><a href='", hoturl("help", "t=search"), "'>Search help</a> <span class='barsep'>·</span> <a href='", hoturl("help", "t=keywords"), "'>Search keywords</a></span></td>
</tr></table></div></form>";

echo "</div>";

function echo_request_as_hidden_inputs($specialscore = false) {
    global $pl;
    foreach (array("q", "qa", "qo", "qx", "qt", "t", "sort") as $x)
        if (isset($_REQUEST[$x])
            && ($x != "q" || !isset($_REQUEST["qa"]))
            && ($x != "sort" || !$specialscore || !$pl))
            echo Ht::hidden($x, $_REQUEST[$x]);
    if ($specialscore && $pl)
        echo Ht::hidden("sort", $pl->sortdef(true));
}

// Saved searches
$ss = array();
if ($Me->isPC || $Me->privChair) {
    foreach ($Conf->settingTexts as $k => $v)
        if (substr($k, 0, 3) == "ss:" && ($v = json_decode($v)))
            $ss[substr($k, 3)] = $v;
    if (count($ss) > 0 || $pl) {
        echo "<div class='tld4' style='padding-bottom:1ex'>";
        ksort($ss);
        if (count($ss)) {
            $n = 0;
            foreach ($ss as $sn => $sv) {
                echo "<table id='ssearch$n' class='foldc'><tr><td>",
                    foldbutton("ssearch$n"),
                    "</td><td>";
                $arest = "";
                foreach (array("qt", "t", "sort", "display") as $k)
                    if (isset($sv->$k))
                        $arest .= "&amp;" . $k . "=" . urlencode($sv->$k);
                echo "<a href=\"", hoturl("search", "q=ss%3A" . urlencode($sn) . $arest), "\">", htmlspecialchars($sn), "</a><div class='fx' style='padding-bottom:0.5ex;font-size:smaller'>",
                    "Definition: “<a href=\"", hoturl("search", "q=" . urlencode(defval($sv, "q", "")) . $arest), "\">", htmlspecialchars($sv->q), "</a>”";
                if ($Me->privChair || !defval($sv, "owner") || $sv->owner == $Me->contactId)
                    echo " <span class='barsep'>·</span> ",
                        "<a href=\"", selfHref(array("deletesearch" => 1, "ssname" => $sn, "post" => post_value())), "\">Delete</a>";
                echo "</div></td></tr></table>";
                ++$n;
            }
            echo "<div class='g'></div>\n";
        }
        echo Ht::form_div(hoturl_post("search", "savesearch=1"));
        echo_request_as_hidden_inputs(true);
        echo "<table id='ssearchnew' class='foldc'>",
            "<tr><td>", foldbutton("ssearchnew"), "</td>",
            "<td><a class='q fn' href='#' onclick='return fold(\"ssearchnew\")'>New saved search</a><div class='fx'>",
            "Save ";
        if (defval($_REQUEST, "q"))
            echo "search “", htmlspecialchars($_REQUEST["q"]), "”";
        else
            echo "empty search";
        echo " as:<br />ss:<input type='text' name='ssname' value='' size='20' /> &nbsp;",
            Ht::submit("Save", array("tabindex" => 8)),
            "</div></td></tr></table>",
            "</div></form>";

        echo "</div>";
        $ss = true;
    } else
        $ss = false;
}

// Display options
if ($pl && $pl->count > 0) {
    echo "<div class='tld3' style='padding-bottom:1ex'>";

    echo Ht::form_div(hoturl_post("search", "redisplay=1"), array("id" => "foldredisplay", "class" => "fn3 fold5c"));
    echo_request_as_hidden_inputs();

    echo "<table>";

    $column = 0;
    $cheaders = array();
    $cbodies = array();
    foreach ($displayOptions as $do) {
        if (preg_match('/\A<strong>/', $do->text)
            && !isset($cheaders[$do->column]))
            $cheaders[$do->column] = $do->text;
        else {
            $t = "<tr><td";
            if ($do->indent)
                $t .= " style='padding-left:2em'";
            $t .= ">" . $do->text . "</td></tr>\n";
            defappend($cbodies[$do->column], $t);
        }
    }

    $header = $body = "";
    $ncolumns = 0;
    for ($i = 1; $i < 10; ++$i)
        if (isset($cbodies[$i]) && $cbodies[$i]) {
            $klass = $ncolumns ? "padlb " : "";
            if (isset($cheaders[$i]))
                $header .= "  <td class='${klass}nowrap'>" . $cheaders[$i] . "</td>\n";
            else
                $header .= "  <td></td>\n";
            $body .= "  <td class='${klass}top'><table>" . $cbodies[$i] . "</table></td>\n";
            ++$ncolumns;
        }
    echo "<tr>\n", $header, "</tr><tr>\n", $body, "</tr>";

    // "Redisplay" row
    echo "<tr><td colspan='$ncolumns' style='padding-top:2ex'><table style='margin:0 0 0 auto'><tr>";

    // Conflict display
    if ($Me->privChair)
        echo "<td class='padlb'>",
            Ht::checkbox("showforce", 1, !!defval($_REQUEST, "forceShow"),
                          array("id" => "showforce", "class" => "cbx",
                                "onchange" => "fold('pl',!this.checked,'force');$('#forceShow').val(this.checked?1:0)")),
            "&nbsp;", Ht::label("Override conflicts", "showforce"), "</td>";

    // Formulas link
    if (count(FormulaPaperColumn::$list) || $Me->isPC)
        echo "<td class='padlb'>", Ht::js_button("Edit formulas", "fold('searchform',0,3)"), "</td>";

    echo "<td class='padlb'>";
    // "Set default display"
    if ($Me->privChair) {
        echo Ht::js_button("Make default", "savedisplayoptions()",
                           array("id" => "savedisplayoptionsbutton",
                                 "disabled" => true)), "&nbsp; ";
        $Conf->footerHtml("<form id='savedisplayoptionsform' method='post' action='" . hoturl_post("search", "savedisplayoptions=1") . "' enctype='multipart/form-data' accept-charset='UTF-8'>"
                          . "<div>" . Ht::hidden("scoresort", $Conf->session("scoresort"), array("id" => "scoresortsave")) . "</div></form>");
        $Conf->footerScript("plinfo.extra=function(){\$\$('savedisplayoptionsbutton').disabled=false};");
        // strings might be in different orders, so sort before comparing
        $pld = explode(" ", trim($Conf->setting_data("pldisplay_default", " overAllMerit ")));
        sort($pld);
        if ($Conf->session("pldisplay") != " " . ltrim(join(" ", $pld) . " ")
            || $Conf->session("scoresort") != ListSorter::default_score_sort(true))
            $Conf->footerScript("plinfo.extra()");
    }

    echo Ht::submit("Redisplay", array("id" => "redisplay")), "</td>";

    echo "</tr></table>", $display_options_extra, "</td>";

    // Done
    echo "</tr></table></div></form>";

    // Formulas
    if ($Me->isPC) {
        echo Ht::form_div(hoturl_post("search", "saveformulas=1"), array("class" => "fx3"));
        echo_request_as_hidden_inputs();

        echo "<p style='width:44em;margin-top:0'><strong>Formulas</strong> are calculated
from review statistics.  For example, &ldquo;sum(OveMer)&rdquo;
would display the sum of a paper&rsquo;s Overall merit scores.
<a class='hint' href='", hoturl("help", "t=formulas"), "' target='_blank'>Learn more</a></p>";

        echo "<table id='formuladefinitions'><thead><tr>",
            "<th></th><th class='f-c'>Name</th><th class='f-c'>Definition</th>",
            "</tr></thead><tbody>";
        $any = 0;
        $fs = FormulaPaperColumn::$list;
        $fs["n"] = (object) array("formulaId" => "n", "name" => "", "expression" => "", "createdBy" => 0);
        foreach ($fs as $formulaId => $fdef) {
            $name = defval($_REQUEST, "name_$formulaId", $fdef->name);
            $expression = defval($_REQUEST, "expression_$formulaId", $fdef->expression);
            $disabled = ($Me->privChair || $fdef->createdBy > 0 ? "" : " disabled='disabled'");
            echo "<tr>";
            if ($fdef->formulaId == "n")
                echo "<td class='lmcaption' style='padding:10px 1em 0 0'>New formula</td>";
            else if ($any == 0) {
                echo "<td class='lmcaption' style='padding:0 1em 0 0'>Existing formulas</td>";
                $any = 1;
            } else
                echo "<td></td>";
            echo "<td class='lxcaption'>",
                "<input type='text' style='width:16em' name='name_$formulaId'$disabled tabindex='8' value=\"" . htmlspecialchars($name) . "\" />",
                "</td><td style='padding:2px 0'>",
                "<input type='text' style='width:30em' name='expression_$formulaId'$disabled tabindex='8' value=\"" . htmlspecialchars($expression) . "\" />",
                "</td></tr>\n";
        }
        echo "<tr><td colspan='3' style='padding:1ex 0 0;text-align:right'>",
            Ht::js_button("Cancel", "fold('searchform',1,3)", array("tabindex" => 8)),
            "&nbsp; ", Ht::submit("Save changes", array("style" => "font-weight:bold", "tabindex" => 8)),
            "</td></tr></tbody></table></div></form>\n";
    }

    echo "</div>";
}

echo "</div>";

// Tab selectors
echo "</td></tr>
<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a class='tla' onclick='return crpfocus(\"searchform\", 1)' href=\"", selfHref(array("tab" => "basic")), "\">Search</a></div></td>
  <td><div class='tll2'><a class='tla nowrap' onclick='return crpfocus(\"searchform\", 2)' href=\"", selfHref(array("tab" => "advanced")), "\">Advanced search</a></div></td>\n";
if ($ss)
    echo "  <td><div class='tll4'><a class='tla nowrap' onclick='fold(\"searchform\",1,4);return crpfocus(\"searchform\",4)' href=\"", selfHref(array("tab" => "ss")), "\">Saved searches</a></div></td>\n";
if ($pl && $pl->count > 0)
    echo "  <td><div class='tll3'><a class='tla nowrap' onclick='fold(\"searchform\",1,3);return crpfocus(\"searchform\",3)' href=\"", selfHref(array("tab" => "display")), "\">Display options</a></div></td>\n";
echo "</tr></table></td></tr>\n</table>\n\n";
if ($pl && $pl->count > 0)
    // `echoScript()` not necessary because we've already got the script
    echo "<script>crpfocus(\"searchform\",$activetab,1)</script>";
else
    $Conf->footerScript("crpfocus(\"searchform\",$activetab)");


if ($pl) {
    if (count($Search->warnings) || count($pl->error_html)) {
        echo "<div class='maintabsep'></div>\n";
        $Conf->warnMsg(join("<br />\n", array_merge($Search->warnings, $pl->error_html)));
    }

    echo "<div class='maintabsep'></div>\n\n<div class='pltable_full_ctr'>";

    if (isset($pl->any->sel))
        echo Ht::form_div(selfHref(array("selector" => 1, "post" => post_value())), array("id" => "sel", "onsubmit" => "return paperselCheck.call(this)")),
            Ht::hidden("defaultact", "", array("id" => "defaultact")),
            Ht::hidden("forceShow", (string) @$_REQUEST["forceShow"], array("id" => "forceShow")),
            Ht::hidden_default_submit("default", 1);

    echo $pl_text;
    if ($pl->count == 0 && $_REQUEST["t"] != "s") {
        $a = array();
        foreach (array("q", "qa", "qo", "qx", "qt", "sort", "showtags") as $xa)
            if (isset($_REQUEST[$xa])
                && ($xa != "q" || !isset($_REQUEST["qa"])))
                $a[] = "$xa=" . urlencode($_REQUEST[$xa]);
        reset($tOpt);
        echo " in ", strtolower($tOpt[$_REQUEST["t"]]);
        if (key($tOpt) != $_REQUEST["t"] && $_REQUEST["t"] !== "all")
            echo " (<a href=\"", hoturl("search", join("&amp;", $a)), "\">Repeat search in ", strtolower(current($tOpt)), "</a>)";
    }

    if (isset($pl->any->sel))
        echo "</div></form>";
    echo "</div>\n";
} else
    echo "<div class='g'></div>\n";

$Conf->footer();
