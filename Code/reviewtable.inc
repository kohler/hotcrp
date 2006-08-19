<?php

// reviewer information
function reviewTable($prow, $rrows, $rrow, $mode) {
    global $Conf, $ConfSiteBase, $Me, $rf, $forceShow, $reviewTableFolder;
    
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
	else if ($rr->reviewModified > 0)
	    $id .= "&nbsp;in&nbsp;progress";
	else
	    $id .= "&nbsp;(not&nbsp;started)";
	if ($rrow && $rrow->reviewId == $rr->reviewId)
	    $t .= "<td><b>$id</b></td>";
	else if (!$canView)
	    $t .= "<td>$id</td>";
	else
	    $t .= "<td><a href='review.php?reviewId=$rr->reviewId$forceShow'>$id</a></td>";

	// reviewer identity
	if (!$Me->canViewReviewerIdentity($prow, $rr, $Conf))
	    $t .= "<td class='empty'></td>";
	else
	    $t .= "<td>" . contactHtml($rr) . "</td>";

	// review type
	if ($prow->conflict > 0 && !$effAssistant)
	    $t .= "<td class='empty'></td>";
	else if ($rr->reviewType == REVIEW_PRIMARY)
	    $t .= "<td>Primary</td>";
	else if ($rr->reviewType == REVIEW_SECONDARY)
	    $t .= "<td>Secondary</td>";
	else if ($rr->reviewType == REVIEW_REQUESTED && $rr->reqContactId == $Me->contactId)
	    $t .= "<td>Requested by you</td>";
	else if ($rr->reviewType == REVIEW_REQUESTED) {
	    $class = ($mode == "req" ? "" : " class='extension'");
	    $t .= "<td>Requested<br /><small$class>by " . contactHtml($rr->reqFirstName, $rr->reqLastName, $rr->reqEmail) . "</small></td>";
	    if ($class)
		$reviewTableFolder = true;
	} else
	    $t .= "<td class='empty'></td>";

	// scores or retract request
	if ($rr->reviewModified <= 0
	    && $rr->reviewType == REVIEW_REQUESTED
	    && ($rr->requestedBy == $Me->contactId || $effAssistant)) {
	    if ($mode == "req") {
		$t .= "<td colspan='" . (1 + $nNumeric) . "'>";
		$t .= "<a class='button_small' href=\"${ConfSiteBase}reqreview.php?paperId=$prow->paperId&amp;retract=$rr->reviewId$forceShow\">Retract review request</a></td>";
	    } else
		$nRetractable++;
	} else 
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
	$nonsubrev[] = "    <tr><td class='highlight'>&#187;</td><td><b>Enter&nbsp;review</b></td><td>" . contactHtml($Me) . "</td><td class='empty'></td><td>Not started</td></tr>\n";
    else if ($mode == "edit" && !$foundMyReview && $Me->canReview($prow, null, $Conf))
	$nonsubrev[] = "    <tr><td></td><td><a href='review.php?paperId=$prow->paperId&amp;mode=edit$forceShow'>Enter&nbsp;review</a></td><td>" . contactHtml($Me) . "</td><td class='empty'></td><td>Not started</td></tr>\n";

    // headers
    $numericHeaders = "";
    if ($anyScores) {
	$t = ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "");
	$numericHeaders = "    <tr>$t<td class='empty' colspan='3'></td>" . $rf->webNumericScoresHeader($prow, $Me, $Conf) . "</tr>\n";
    }
    
    // see all reviews
    if (($mode != "edit" && $rrow && $Me->canViewReview($prow, null, $Conf))
	|| ($mode == "edit" && $Me->canViewReview($prow, null, $Conf))) {
	$t = "    <tr>" . ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "") . "<td colspan='";
	$t .= ($anyScores ? 4 + $nNumeric : 4);
	$t .= "'><a href='review.php?paperId=$prow->paperId&amp;mode=view$forceShow'>View all reviews on one page</a></td></tr>\n";
	$nonsubrev[] = $t;
    }
    
    // unfinished review notification
    $notes = array();
    if ($prow->author > 0 && !$effAssistant && $notShown
	&& $Me->canViewReview($prow, null, $Conf)) {
	$qualifier = (count($subrev) + count($nonsubrev) ? " additional" : "");
	if ($notShown == 1)
	    $t = "1$qualifier review was requested, but has not been submitted yet.  ";
	else
	    $t = "$notShown$qualifier reviews were requested, but have not been submitted yet.  ";
	$t .= "You will be emailed when$qualifier reviews are submitted and if any existing reviews are changed.";
	$notes[] = $t;
    }

    // forceShow
    if ($Me->amAssistant() && !$effAssistant)
	$notes[] = "<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\"><b>Override your conflict</b> to show reviewer identities and allow editing</a>";

    // review requests
    if ($mode != "req" && ($prow->reviewType >= REVIEW_SECONDARY || $effAssistant)) {
	if ($Me->amAssistant())
	    $t = "Assign or request reviewers";
	else
	    $t = "Ask someone else to review this paper" . ($nRetractable ? " or retract outstanding review requests" : "");
	$notes[] = "<a href=\"${ConfSiteBase}reqreview.php?paperId=$prow->paperId" . ($forceShow ? "&amp;forceShow=1" : "") . "\">$t</a>";
    }

    // refuse review
    if ($mode != "req" && $rrow && $rrow->reviewType == REVIEW_REQUESTED && $rrow->reviewSubmitted <= 0) {
	$t = "<div id='foldref' class='folded' style='position: relative'><a href=\"javascript:fold('ref', 0)\">I cannot finish this review</a>
  <div class='popupdialog extension'><p>Thank you for telling us that you cannot complete your review.  You may give a few words of explanation if you'd like.</p>
    <form action='${ConfSiteBase}review.php?reviewId=$rrow->reviewId&amp;post=1' method='post' enctype='multipart/form-data'>\n";
	if ($forceShow)
	    $t .= "      <input type='hidden' name='forceShow' value='1' />\n";
	$t .= "      <input class='textlite' type='text' name='reason' value='' size='40' />
      <hr class='smgap' />
      <input class='button' type='submit' name='refuse' value='Refuse review' />
      <button type='button' onclick=\"fold('ref', 1)\">Cancel</button>
    </form>
  </div></div>";
	$notes[] = $t;
    }

    if (count($notes)) {
	$t = ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "");
	$nonsubrev[] = "    <tr>$t<td class='revnotes' colspan='" . ($anyScores ? 4 + $nNumeric : 4) . "'>" . join("<br />\n", $notes) . "</td></tr>\n";
    }

    // completion
    if (count($nonsubrev) || count($subrev))
	return "<table class='reviewers'>\n"
	    . $numericHeaders . join("", $subrev) . join("", $nonsubrev)
	    . "  </table>\n";
    else
	return "";
}
