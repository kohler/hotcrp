<?php

// reviewer information
function reviewTable($prow, $rrows, $rrow, $mode) {
    global $Conf, $ConfSiteBase, $Me, $rf, $forceShow, $reviewTableFolder,
	$reviewTypeName;
    
    $subrev = array();
    $nonsubrev = array();
    $foundRrow = $foundMyReview = $notShown = $nRetractable = 0;
    $actingConflict = ($prow->conflict > 0 && !$forceShow);
    $effAssistant = $Me->amAssistant() && !$actingConflict;
    $anyScores = false;
    $nNumeric = $rf->numNumericScores($prow, $Me, $Conf);
    $reviewTableFolder = false;
    
    // actual rows
    foreach ($rrows as $rr) {
	$highlight = ($rrow && $rr->reviewId == $rrow->reviewId);
	$foundRrow += $highlight;
	$foundMyReview += ($rr->contactId == $Me->contactId);
	$canView = $Me->canViewReview($prow, $rr, $Conf, $whyNot);

	// skip unsubmitted reviews
	if (!$canView && $actingConflict) {
	    if ($rr->reviewNeedsSubmit == 1)
		$notShown++;
	    continue;
	}

	$t = "    <tr>";

	// dingbat
	if ($rrow || $mode == "edit")
	    $t .= "<td class='highlight'>" . ($highlight ? "<b>&#187;</b>" : "") . "</td>";

	// review ID
	$id = "Review";
	if ($rr->reviewSubmitted > 0)
	    $id .= "&nbsp;#" . $prow->paperId . unparseReviewOrdinal($rr->reviewOrdinal);
	else if ($rr->reviewType == REVIEW_SECONDARY && $rr->reviewNeedsSubmit == 0)
	    $id .= "&nbsp;(delegated)";
	else if ($rr->reviewModified > 0)
	    $id .= "&nbsp;(in&nbsp;progress)";
	else
	    $id .= "&nbsp;(not&nbsp;started)";
	if ($rrow && $rrow->reviewId == $rr->reviewId)
	    $t .= "<td><b>$id</b></td>";
	else if (!$canView)
	    $t .= "<td>$id</td>";
	else
	    $t .= "<td><a href='${ConfSiteBase}review.php?reviewId=$rr->reviewId$forceShow'>$id</a></td>";

	// primary/secondary glyph
	if ($prow->conflict > 0 && !$effAssistant)
	    $x = "";
	else if ($rr->reviewType > 0)
	    $x = "<img src='${ConfSiteBase}images/ass" . $rr->reviewType . ".png' alt='" . $reviewTypeName[$rr->reviewType] . "' />";
	else
	    $x = "";

	// reviewer identity
	if (!$Me->canViewReviewerIdentity($prow, $rr, $Conf))
	    $t .= ($x ? "<td>$x</td>" : "<td class='empty'></td>");
	else if ($mode == "assign")
	    $t .= "<td><a href=\"mailto:" . htmlspecialchars($rr->email) . "\">" . contactHtml($rr) . "</a></td>";
	else
	    $t .= "<td><a href=\"mailto:" . htmlspecialchars($rr->email) . "\">" . contactHtml($rr->firstName, $rr->lastName) . "</a>" . ($x ? " $x" : "") . "</td>";

	// review type
	if ($mode != "assign")
	    /* nothing */;
	else if ($prow->conflict > 0 && !$effAssistant)
	    $t .= "<td class='empty'></td>";
	else {
	    $t .= "<td>" . $reviewTypeName[$rr->reviewType];
	    if ($rr->reviewType == REVIEW_REQUESTED)
		$t .= "<br /><small>by " . contactHtml($rr->reqFirstName, $rr->reqLastName, $rr->reqEmail) . "</small>";
	    $t .= "</td>";
	}

	// scores or retract request
	if ($rr->reviewModified <= 0
	    && $rr->reviewType == REVIEW_REQUESTED
	    && ($rr->requestedBy == $Me->contactId || $effAssistant)) {
	    if ($mode == "assign") {
		$t .= "<td><a href=\"${ConfSiteBase}assign.php?paperId=$prow->paperId&amp;retract=$rr->reviewId$forceShow\">Retract review request</a></td>";
	    } else
		$nRetractable++;
	} else if ($mode == "assign")
	    $t .= "<td class='empty'></td>";
	else
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
	$nonsubrev[] = "    <tr><td class='highlight'>&#187;</td><td><b>Enter&nbsp;review</b></td><td>" . contactHtml($Me->firstName, $Me->lastName) . "</td><td class='empty'></td></tr>\n";
    else if ($mode == "edit" && !$foundMyReview && $Me->canReview($prow, null, $Conf))
	$nonsubrev[] = "    <tr><td></td><td><a href='review.php?paperId=$prow->paperId&amp;mode=edit$forceShow'>Enter&nbsp;review</a></td><td>" . contactHtml($Me) . "</td></tr>\n";

    // headers
    $numericHeaders = "";
    if ($anyScores) {
	$t = ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "");
	$numericHeaders = "    <tr>$t<td class='empty' colspan='2'></td>" . $rf->webNumericScoresHeader($prow, $Me, $Conf) . "</tr>\n";
    }
    
    $t = '';
    $hilite = ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "");
    // see all reviews
    if (($mode != "edit" && $rrow && $Me->canViewReview($prow, null, $Conf))
	|| ($mode == "edit" && $Me->canViewReview($prow, null, $Conf)))
	$t .= "<a href='review.php?paperId=$prow->paperId&amp;mode=view$forceShow'>All reviews on one page</a>";
    // review assignments
    if ($mode != "assign" && ($prow->reviewType >= REVIEW_SECONDARY || $effAssistant)) {
	if ($Me->amAssistant())
	    $x = "Assign or request reviewers";
	else
	    $x = "Request an external review" . ($nRetractable ? " or retract outstanding review requests" : "");
	$t .= ($t == "" ? "" : " &nbsp;|&nbsp; ") . "<a href=\"${ConfSiteBase}assign.php?paperId=$prow->paperId" . ($forceShow ? "&amp;forceShow=1" : "") . "\">$x</a>";
    }
    // extra action row
    if ($t)
	$nonsubrev[] = "    <tr>$hilite<td colspan='"
	    . ($anyScores ? 4 + $nNumeric : 4) . "'>" . $t . "</td></tr>\n";
    
    // unfinished review notification
    $notes = array();
    if ($prow->author > 0 && !$effAssistant && $notShown
	&& $Me->canViewReview($prow, null, $Conf)) {
	$qualifier = (count($subrev) + count($nonsubrev) ? " additional" : "");
	if ($notShown == 1)
	    $t = "1$qualifier review remains outstanding.  ";
	else
	    $t = "$notShown$qualifier reviews remain outstanding.  ";
	$t .= "You will be emailed when$qualifier reviews are submitted and if any existing reviews are changed.";
	$notes[] = $t;
    }

    // forceShow
    if ($Me->amAssistant() && !$effAssistant)
	$notes[] = "<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\"><b>Override your conflict</b> to show reviewer identities and allow editing</a>";

    if (count($notes))
	$nonsubrev[] = "    <tr>$hilite<td class='revnotes' colspan='" . ($anyScores ? 4 + $nNumeric : 4) . "'>" . join("<br />\n", $notes) . "</td></tr>\n";

    // completion
    if (count($nonsubrev) || count($subrev))
	return "<table class='reviewers'>\n"
	    . $numericHeaders . join("", $subrev) . join("", $nonsubrev)
	    . "  </table>\n";
    else
	return "";
}
