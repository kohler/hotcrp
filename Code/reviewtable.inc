<?php
// reviewtable.inc -- HotCRP helper class for table of all reviews
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// reviewer information
function reviewTable($prow, $rrows, $rrow, $mode) {
    global $Conf, $ConfSiteBase, $ConfSiteSuffix, $Me, $rf, $forceShow,
	$linkExtra, $reviewTableFolder, $reviewTypeName;
    
    $subrev = array();
    $nonsubrev = array();
    $foundRrow = $foundMyReview = $notShown = $nRetractable = 0;
    $actingConflict = ($prow->conflictType > 0 && !$forceShow);
    $effAssistant = $Me->privChair && !$actingConflict;
    $anyScores = false;
    $nNumeric = $rf->numNumericScores($prow, $Me, $Conf);
    $reviewTableFolder = false;
    $xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
    
    // actual rows
    foreach ($rrows as $rr) {
	$highlight = ($rrow && $rr->reviewId == $rrow->reviewId);
	$foundRrow += $highlight;
	if ($rr->contactId == $Me->contactId
	    || (isset($prow->myReviewId) && $prow->myReviewId == $rr->reviewId))
	    $foundMyReview++;
	$canView = $Me->canViewReview($prow, $rr, $Conf, $whyNot);

	// skip unsubmitted reviews
	if (!$canView && $actingConflict) {
	    if ($rr->reviewNeedsSubmit == 1 && $rr->reviewModified)
		$notShown++;
	    continue;
	}

	if (($rrow || $mode == "edit") && $highlight)
	    $t = "    <tr class='hilite'>";
	else
	    $t = "    <tr>";

	// review ID
	$id = "Review";
	if ($rr->reviewSubmitted)
	    $id .= "&nbsp;#" . $prow->paperId . unparseReviewOrdinal($rr->reviewOrdinal);
	else if ($rr->reviewType == REVIEW_SECONDARY && $rr->reviewNeedsSubmit <= 0)
	    $id .= "&nbsp;(delegated)";
	else if ($rr->reviewModified > 0)
	    $id .= "&nbsp;(in&nbsp;progress)";
	else
	    $id .= "&nbsp;(not&nbsp;started)";
	$rlink = unparseReviewOrdinal($rr) . $linkExtra;
	if ($rrow && $rrow->reviewId == $rr->reviewId)
	    $t .= "<td><a href='${ConfSiteBase}review$ConfSiteSuffix?r=$rlink' class='q'><b>$id</b></a></td>";
	else if (!$canView)
	    $t .= "<td>$id</td>";
	else
	    $t .= "<td><a href='${ConfSiteBase}review$ConfSiteSuffix?r=$rlink'>$id</a></td>";

	// primary/secondary glyph
	if ($prow->conflictType > 0 && !$effAssistant)
	    $x = "";
	else if ($rr->reviewType > 0) {
	    $x = $Conf->cacheableImage("ass$rr->reviewType.gif", $reviewTypeName[$rr->reviewType]);
	    if ($rr->reviewRound > 0) {
		if (($rround = defval($Conf->settings["rounds"], $rr->reviewRound)))
		    $x .= "&nbsp;<span class='revround' title='Review round'>" . htmlspecialchars($rround) . "</span>";
		else
		    $x .= "&nbsp;<span class='revround' title='Review round'>?$rr->reviewRound</span>";
	    }
	} else
	    $x = "";

	// reviewer identity
	if (!$Me->canViewReviewerIdentity($prow, $rr, $Conf))
	    $t .= ($x ? "<td>$x</td>" : "<td class='empty'></td>");
	else if ($mode == "assign") {
	    $t .= "<td>" . contactHtml($rr);
	    if ($Me->privChair && $rr->email != $Me->email)
		$t .= " <a href=\"" . htmlspecialchars(selfHref(array("viewContact" => $rr->email))) . "\">" . $Conf->cacheableImage("viewas.png", "[Act as]", "Act as " . contactHtml($rr->firstName, $rr->lastName)) . "</a>";
	    $t .= "</td>";
	} else
	    $t .= "<td>" . contactHtml($rr->firstName, $rr->lastName) . ($x ? " $x" : "") . "</td>";

	// review type
	if ($mode != "assign")
	    /* nothing */;
	else if ($prow->conflictType > 0 && !$effAssistant)
	    $t .= "<td class='empty'></td>";
	else {
	    $t .= "<td>" . $reviewTypeName[$rr->reviewType];
	    if ($rr->reviewType == REVIEW_EXTERNAL)
		$t .= "<br /><small>Requester: " . contactHtml($rr->reqFirstName, $rr->reqLastName) . "</small>";
	    $t .= "</td>";
	}

	// scores or retract request
	if ($rr->reviewModified <= 0
	    && $rr->reviewType == REVIEW_EXTERNAL
	    && ($rr->requestedBy == $Me->contactId || $effAssistant)) {
	    if ($mode == "assign") {
		$t .= "<td><a href=\"${ConfSiteBase}assign$ConfSiteSuffix?p=$prow->paperId&amp;retract=$rr->reviewId$linkExtra\">Retract review request</a></td>";
	    } else
		$nRetractable++;
	} else if ($mode == "assign")
	    $t .= "<td class='empty'></td>";
	else if ($mode != "edit")
	    $t .= $rf->webNumericScoresRow($rr, $prow, $Me, $Conf, $anyScores);

	$t .= "<td></td></tr>\n";

	// affix
	if (!$rr->reviewSubmitted)
	    $nonsubrev[] = $t;
	else
	    $subrev[] = $t;
    }

    // bottom links
    // edit your own review
    if ($mode == "edit" && !$rrow)
	$nonsubrev[] = "    <tr class='hilite'><td><b>Your&nbsp;review</b></td><td>" . contactHtml($Me->firstName, $Me->lastName) . "</td><td class='empty'></td></tr>\n";

    // headers
    $numericHeaders = "";
    if ($anyScores)
	$numericHeaders = "    <tr><td class='empty' colspan='2'></td>" . $rf->webNumericScoresHeader($prow, $Me, $Conf) . "</tr>\n";
    
    $notes = array();
    $t = "";
    
    // see all reviews
    if (count($nonsubrev) + count($subrev) == 0)
	/* no reviews to view */;
    else if (($mode != "edit" && $rrow && $Me->canViewReview($prow, null, $Conf))
	     || ($mode == "edit" && $Me->canViewReview($prow, null, $Conf))) {
	$a = "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;mode=view$linkExtra'>";
	$x = $a . $Conf->cacheableImage("allreviews24.png", "[All reviews]", null, "dlimg") . "</a>&nbsp;" . $a . "All reviews</a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }
    // new review
    if (!$foundMyReview && $Me->canReview($prow, null, $Conf)
	&& !($mode == "edit" && !$rrow) && $mode != "assign") {
	$a = "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;mode=edit$linkExtra'>";
	$x = $a . $Conf->cacheableImage("newreview24.png", "[New review]", null, "dlimg") . "</a>&nbsp;" . $a . "<b>Add review</b></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    } else if ($foundMyReview && !$rrow && $mode != "assign") {
	$a = "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;mode=edit$linkExtra'>";
	$x = $a . $Conf->cacheableImage("newreview24.png", "[Your review]", null, "dlimg") . "</a>&nbsp;" . $a . "<b>Your review</b></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }
    // review assignments
    if ($mode != "assign" && ($prow->reviewType >= REVIEW_SECONDARY || $effAssistant)) {
	$a = "<a href='assign$ConfSiteSuffix?p=$prow->paperId$linkExtra'>";
	$x = $a . $Conf->cacheableImage("assign24.png", "[Assign]", null, "dlimg") . "</a>&nbsp;" . $a . ($Me->privChair ? "Manage assignments" : "Manage external reviews") . "</a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }
    // text format
    if ($mode == "view" && !$rrow && $Me->canViewReview($prow, null, $Conf)
	&& count($nonsubrev) + count($subrev) > 0) {
	$a = "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;mode=view&amp;text=1$linkExtra'>";
	$x = $a . $Conf->cacheableImage("txt24.png", "[Text]", null, "dlimg") . "</a>&nbsp;" . $a . "Text format</a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }
    // override conflict
    if ($Me->privChair && !$effAssistant) {
	$a = "<a href=\"" . htmlspecialchars(selfhref(array("forceShow" => 1))) . "\">";
	$x = $a . $Conf->cacheableImage("override24.png", "[Override]", null, "dlimg") . "</a>&nbsp;" . $a . "Override conflict</a> to show reviewers and allow editing";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }
    // unfinished review notification
    if ($prow->conflictType >= CONFLICT_AUTHOR && !$effAssistant && $notShown
	&& $Me->canViewReview($prow, null, $Conf)) {
	$qualifier = (count($subrev) + count($nonsubrev) ? " additional" : "");
	if ($notShown == 1)
	    $x = "1$qualifier review remains outstanding.";
	else
	    $x = "$notShown$qualifier reviews remain outstanding.";
	$t .= ($t == "" ? "" : $xsep) . $x;
	$t .= "<br /><span class='hint'>You will be emailed if$qualifier reviews are submitted or existing reviews are changed.</span>";
    }
    if ($t)
	$notes[] = $t;

    // collect notes
    if (count($notes)) {
	$class = (count($nonsubrev) + count($subrev) ? "revnotes" : "");
	$notetxt = "<div class='$class'>" . join("<br />\n", $notes) . "</div>\n";
    } else
	$notetxt = "";

    // completion
    if (count($nonsubrev) + count($subrev))
	return "<table class='reviewers'>\n"
	    . $numericHeaders . join("", $subrev) . join("", $nonsubrev)
	    . "  </table>\n" . $notetxt;
    else
	return $notetxt;
}
