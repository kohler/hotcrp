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
	$foundMyReview += ($rr->contactId == $Me->contactId);
	$canView = $Me->canViewReview($prow, $rr, $Conf, $whyNot);

	// skip unsubmitted reviews
	if (!$canView && $actingConflict) {
	    if ($rr->reviewNeedsSubmit == 1 && $rr->reviewModified)
		$notShown++;
	    continue;
	}

	$t = "    <tr>";

	// dingbat
	if ($rrow || $mode == "edit")
	    $t .= "<td class='highlight'>" . ($highlight ? "<b>&#187;</b>" : "") . "</td>";

	// review ID
	$id = "Review";
	if ($rr->reviewSubmitted)
	    $id .= "&nbsp;#" . $prow->paperId . unparseReviewOrdinal($rr->reviewOrdinal);
	else if ($rr->reviewType == REVIEW_SECONDARY && $rr->reviewNeedsSubmit == 0)
	    $id .= "&nbsp;(delegated)";
	else if ($rr->reviewModified > 0)
	    $id .= "&nbsp;(in&nbsp;progress)";
	else
	    $id .= "&nbsp;(not&nbsp;started)";
	$rlink = unparseReviewOrdinal($rr);
	if ($rrow && $rrow->reviewId == $rr->reviewId)
	    $t .= "<td><a href='${ConfSiteBase}review$ConfSiteSuffix?r=$rlink' class='q'><b>$id</b></a></td>";
	else if (!$canView)
	    $t .= "<td>$id</td>";
	else
	    $t .= "<td><a href='${ConfSiteBase}review$ConfSiteSuffix?r=$rlink'>$id</a></td>";

	// primary/secondary glyph
	if ($prow->conflictType > 0 && !$effAssistant)
	    $x = "";
	else if ($rr->reviewType > 0)
	    $x = $Conf->cacheableImage("ass$rr->reviewType.png", $reviewTypeName[$rr->reviewType]);
	else
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
    $hilite = ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "");
    
    // edit your own review
    if ($mode == "edit" && !$rrow)
	$nonsubrev[] = "    <tr><td class='highlight'>&#187;</td><td><b>Enter&nbsp;review</b></td><td>" . contactHtml($Me->firstName, $Me->lastName) . "</td><td class='empty'></td></tr>\n";
    else if (!$foundMyReview && $Me->canReview($prow, null, $Conf)) {
	$x = "    <tr>$hilite<td colspan='2'><a href='review$ConfSiteSuffix?p=$prow->paperId&amp;mode=edit$linkExtra'>Enter&nbsp;your&nbsp;own&nbsp;review</a></td></tr>\n";
	$nonsubrev[] = $x;
    }

    // headers
    $numericHeaders = "";
    if ($anyScores) {
	$t = ($mode == "edit" || $rrow ? "<td class='highlight'></td>" : "");
	$numericHeaders = "    <tr>$t<td class='empty' colspan='2'></td>" . $rf->webNumericScoresHeader($prow, $Me, $Conf) . "</tr>\n";
    }
    
    // unfinished review notification
    $notes = array();
    if ($prow->conflictType >= CONFLICT_AUTHOR && !$effAssistant && $notShown
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
    if ($Me->privChair && !$effAssistant)
	$notes[] = "<a href=\"" . htmlspecialchars(selfHref(array("forceShow" => 1))) . "\"><strong>Override your conflict</strong> to show reviewer identities and allow editing</a>";

    // see all reviews
    $t = "";
    if (count($nonsubrev) + count($subrev) == 0)
	/* no reviews to view */;
    else if (($mode != "edit" && $rrow && $Me->canViewReview($prow, null, $Conf))
	     || ($mode == "edit" && $Me->canViewReview($prow, null, $Conf)))
	$t .= "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;mode=view$linkExtra'>All reviews</a>";
    else if ($mode == "view" && !$rrow && $Me->canViewReview($prow, null, $Conf))
	$t .= "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;mode=view&amp;text=1$linkExtra'>Text version of the reviews</a>";
    // review assignments
    if ($mode != "assign" && ($prow->reviewType >= REVIEW_SECONDARY || $effAssistant)) {
	if ($Me->privChair)
	    $x = "Assign or request reviewers";
	else
	    $x = "Request an external review" . ($nRetractable ? " or retract outstanding review requests" : "");
	$t .= ($t == "" ? "" : $xsep) . "<a href=\"${ConfSiteBase}assign$ConfSiteSuffix?p=$prow->paperId$linkExtra\">$x</a>";
    }
    if ($t)
	$nonsubrev[] = "    <tr>$hilite<td colspan='" . (2 + $nNumeric) . "'><div class='revnotes'>$t</div></td></tr>\n";

    // collect notes
    if (count($notes)) {
	$class = (count($nonsubrev) || count($subrev) ? "revnotes" : "");
	$notetxt = "<div class='$class'>" . join("<br />\n", $notes) . "</div>\n";
    } else
	$notetxt = "";

    // completion
    if (count($nonsubrev) || count($subrev))
	return "<table class='reviewers'>\n"
	    . $numericHeaders . join("", $subrev) . join("", $nonsubrev)
	    . "  </table>\n" . $notetxt;
    else
	return $notetxt;
}
