<?php

// reviewer information
function reviewTable($prow, $rrows, $rrow, $mode) {
    global $Conf, $ConfSiteBase, $Me, $rf, $forceShow;
    
    $subrev = array();
    $nonsubrev = array();
    $foundRrow = $foundMyReview = $foundNonsub = $nRetractable = 0;
    $actingConflict = ($prow->conflict > 0 && !$forceShow);
    $effAssistant = $Me->amAssistant() && !$actingConflict;
    $anyScores = false;
    $nNumeric = $rf->numNumericScores($prow, $Me, $Conf);
    
    // actual rows
    foreach ($rrows as $rr) {
	$highlight = ($rrow && $rr->reviewId == $rrow->reviewId);
	$foundRrow += $highlight;
	$foundMyReview += ($rr->contactId == $Me->contactId);
	$foundNonsub += ($rr->reviewSubmitted <= 0);
	$canView = $Me->canViewReview($prow, $rr, $Conf);

	// skip unsubmitted reviews
	if (!$canView && $actingConflict)
	    continue;

	$t = "    <tr>";

	// dingbat
	if ($rrow || $mode == "edit")
	    $t .= "<td class='highlight'>" . ($highlight ? "<b>&#187;</b>" : "") . "</td>";

	// review ID
	$id = "Review";
	if ($rr->reviewSubmitted > 0)
	    $id .= "&nbsp;#" . $prow->paperId . unparseReviewOrdinal($rr->reviewOrdinal);
	if ($rrow && $rrow->reviewId == $rr->reviewId)
	    $t .= "<td><b>$id</b></td>";
	else if (!$canView)
	    $t .= "<td>$id</td>";
	else
	    $t .= "<td><a href='review.php?reviewId=$rr->reviewId$forceShow'>$id</a></td>";

	// reviewer identity
	if (!$Me->canViewReviewerIdentity($prow, $rr, $Conf))
	    $t .= "<td class='empty'></td>";
	else if ($rr->contactId == $Me->contactId)
	    $t .= "<td>You</td>";
	else
	    $t .= "<td>" . contactText($rr) . "</td>";

	// review type
	if ($prow->author > 0 || $prow->conflict > 0)
	    $t .= "<td class='empty'></td>";
	else if ($rr->reviewType == REVIEW_PRIMARY)
	    $t .= "<td>Primary</td>";
	else if ($rr->reviewType == REVIEW_SECONDARY)
	    $t .= "<td>Secondary</td>";
	else if ($rr->reviewType == REVIEW_REQUESTED)
	    $t .= "<td>Requested<br /><small>by " . ($rr->reqContactId == $Me->contactId ? "you" : contactText($rr->reqFirstName, $rr->reqLastName, $rr->reqEmail)) . "</small></td>";

	// status
	if ($rr->reviewModified <= 0) {
	    $t .= "<td colspan='" . (2 + $nNumeric) . "'>Not started";
	    
	    if ($rr->reviewType == REVIEW_REQUESTED
		&& ($rr->requestedBy == $Me->contactId || $effAssistant)) {
		if ($mode == "req")
		    $t .= " <a class='button_small' href=\"${ConfSiteBase}reqreview.php?paperId=$prow->paperId&amp;retract=$rr->reviewId$forceShow\">Retract review request</a>";
		else
		    $nRetractable++;
	    }
	    
	    $t .= "</td>";
	} else if ($rr->reviewSubmitted <= 0)
	    $t .= "<td>In progress</td>";
	else
	    $t .= "<td class='empty'></td>";

	// scores
	$t .= $rf->webNumericScoresRow($rr, $prow, $Me, $Conf, $anyScores);

	$t .= "<td></td></tr>\n";

	// affix
	if ($rr->reviewSubmitted <= 0)
	    $nonsubrev[] = $t;
	else
	    $subrev[] = $t;
    }

    // bottom links
    // edit your own review
    if ($mode == "edit" && !$rrow)
	$nonsubrev[] = "    <tr><td class='highlight'>&#187;</td><td><b>Enter&nbsp;review</b></td><td>You</td><td class='empty'></td><td>Not started</td></tr>\n";
    else if ($mode == "edit" && !$foundMyReview && $Me->canReview($prow, null, $Conf))
	$nonsubrev[] = "    <tr><td></td><td><a href='review.php?paperId=$prow->paperId&amp;mode=edit$forceShow'>Enter&nbsp;review</a></td><td>You</td><td class='empty'></td><td>Not started</td></tr>\n";

    // headers
    $numericHeaders = "";
    if ($anyScores) {
	$t = ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "");
	$numericHeaders = "    <tr>$t<td class='empty' colspan='4'></td>" . $rf->webNumericScoresHeader($prow, $Me, $Conf) . "</tr>\n";
    }
    
    // see all reviews
    if (($mode != "edit" && $rrow && $Me->canViewReview($prow, null, $Conf))
	|| ($mode == "edit" && $Me->canViewReview($prow, null, $Conf))) {
	$t = "    <tr>" . ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "") . "<td colspan='";
	$t .= ($anyScores ? 5 + $nNumeric : 5);
	$t .= "'><a href='review.php?paperId=$prow->paperId&amp;mode=view$forceShow'>View all reviews on one page</a></td></tr>\n";
	$nonsubrev[] = $t;
    }
    
    // unfinished review notification
    $notes = array();
    if ($prow->author > 0 && !$forceShow && $foundNonsub) {
	if ($foundNonsub == 1)
	    $t = "1 additional review was requested, but has not been submitted yet.  ";
	else
	    $t = "$foundNonsub additional reviews were requested, but have not been submitted yet.  ";
	$t .= "You will be emailed when additional reviews are submitted and if any existing reviews are changed.";
	$notes[] = $t;
    }

    // forceShow
    if ($Me->amAssistant() && !$effAssistant)
	$notes[] = "<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\"><b>Override your conflict</b> to show reviewer identities and allow editing</a>";

    // review requests
    if ($mode != "req" && ($prow->reviewType >= REVIEW_SECONDARY || $effAssistant)) {
	$t = ($nRetractable ? " or retract outstanding review requests" : "");
	$notes[] = "<a href=\"${ConfSiteBase}reqreview.php?paperId=$prow->paperId\">Ask someone else to review this paper$t</a>";
    }

    if (count($notes)) {
	$t = ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "");
	$nonsubrev[] = "    <tr>$t<td class='revnotes' colspan='" . ($anyScores ? 5 + $nNumeric : 5) . "'>" . join("<br />\n", $notes) . "</td></tr>\n";
    }

    // completion
    if (count($nonsubrev) || count($subrev))
	return "<table class='reviewers'>\n" . $numericHeaders
		     . join("", $subrev) . join("", $nonsubrev)
		     . "  </table>\n";
    else
	return "";
}
