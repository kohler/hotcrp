<?php
// reviewtable.inc -- HotCRP helper class for table of all reviews
// HotCRP is Copyright (c) 2006-2008 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// reviewer information
function reviewTable($prow, $rrows, $crows, $rrow, $mode) {
    global $Conf, $ConfSiteSuffix, $Me, $rf, $forceShow,
	$linkExtra, $reviewTypeName;
    
    $subrev = array();
    $nonsubrev = array();
    $foundRrow = $foundMyReview = $notShown = 0;
    $actingConflict = ($prow->conflictType > 0 && !$forceShow);
    $effAssistant = $Me->privChair && !$actingConflict;
    $anyScores = false;
    $nNumeric = $rf->numNumericScores($prow, $Me, $Conf);
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

	if ($mode == "edit" || $mode == "re")
	    continue;
	else if ($rrow && $highlight)
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
	$rlink = unparseReviewOrdinal($rr);
	if ($rrow && $rrow->reviewId == $rr->reviewId)
	    $t .= "<td><a href='review$ConfSiteSuffix?r=$rlink$linkExtra' class='q'><b>$id</b></a></td>";
	else if (!$canView)
	    $t .= "<td>$id</td>";
	else if ($rrow || $rr->reviewModified <= 0)
	    $t .= "<td><a href='review$ConfSiteSuffix?r=$rlink$linkExtra'>$id</a></td>";
	else if ($mode == "assign")
	    $t .= "<td><a href='review$ConfSiteSuffix?r=$rlink$linkExtra'>$id</a></td>";
	else
	    $t .= "<td><a href='#review$rlink'>$id</a></td>";

	// primary/secondary glyph
	if ($prow->conflictType > 0 && !$effAssistant)
	    $x = "";
	else if ($rr->reviewType > 0) {
	    $x = $Conf->cacheableImage("ass$rr->reviewType.gif", $reviewTypeName[$rr->reviewType], $reviewTypeName[$rr->reviewType] . " review");
	    if ($rr->reviewRound > 0) {
		if (($rround = defval($Conf->settings["rounds"], $rr->reviewRound)))
		    $x .= "&nbsp;<span class='revround' title='Review round'>" . htmlspecialchars($rround) . "</span>";
		else
		    $x .= "&nbsp;<span class='revround' title='Review round'>?$rr->reviewRound</span>";
	    }
	} else
	    $x = "";

	// reviewer identity
	$showtoken = $rr->reviewToken && $Me->canReview($prow, $rr, $Conf);
	if (!$Me->canViewReviewerIdentity($prow, $rr, $Conf)) {
	    $t .= ($x ? "<td>$x</td>" : "<td class='empty'></td>");
	    if ($mode == "assign")
		$t .= "<td class='empty'></td>";
	} else {
	    if (!$showtoken || !preg_match('/^anonymous\d*$/', $rr->email))
		$n = contactHtml($rr->firstName, $rr->lastName);
	    else
		$n = "[Token " . encodeToken($rr->reviewToken) . "]";
	    $t .= "<td>" . $n . ($x ? " $x" : "");
	    if ($Me->privChair && $rr->email != $Me->email)
		$t .= " <a href=\"" . htmlspecialchars(selfHref(array("viewContact" => $rr->email))) . "\">" . $Conf->cacheableImage("viewas.png", "[Act as]", "Act as " . contactHtml($rr->firstName, $rr->lastName)) . "</a>";
	    $t .= "</td>";
	    if ($mode == "assign")
		$t .= "<td>" . contactHtml(null, null, $rr->email) . "</td>";
	}

	// review type
	if ($mode != "assign")
	    /* nothing */;
	else if (($prow->conflictType > 0 && !$effAssistant) || $rr->reviewType != REVIEW_EXTERNAL)
	    $t .= "<td class='empty'></td>";
	else {
	    $t .= "<td><small>Requested by " . contactHtml($rr->reqFirstName, $rr->reqLastName);
	    if ($rr->reviewModified <= 0
		&& $rr->reviewType == REVIEW_EXTERNAL
		&& ($rr->requestedBy == $Me->contactId || $effAssistant))
		$t .= " (<a href=\"assign$ConfSiteSuffix?p=$prow->paperId&amp;retract=$rr->reviewId$linkExtra\" title='Retract this review request'><strong>Retract</strong></a>)";
	    $t .= "</small></td>";
	}

	// scores or retract request
	if ($mode == "assign")
	    $t .= "<td class='empty'></td>";
	else if ($mode != "edit" && $mode != "re")
	    $t .= $rf->webNumericScoresRow($rr, $prow, $Me, $Conf, $anyScores);

	$t .= "<td></td></tr>\n";

	// affix
	if (!$rr->reviewSubmitted)
	    $nonsubrev[] = $t;
	else
	    $subrev[] = $t;
    }

    // headers
    $numericHeaders = "";
    if ($anyScores)
	$numericHeaders = "    <tr><td class='empty' colspan='2'></td>" . $rf->webNumericScoresHeader($prow, $Me, $Conf) . "</tr>\n";
    
    // unfinished review notification
    $notetxt = "";
    if ($prow->conflictType >= CONFLICT_AUTHOR && !$effAssistant && $notShown
	&& $Me->canViewReview($prow, null, $Conf)) {
	$qualifier = (count($subrev) + count($nonsubrev) ? " additional" : "");
	if ($notShown == 1)
	    $t = "1$qualifier review remains outstanding.";
	else
	    $t = "$notShown$qualifier reviews remain outstanding.";
	$t .= "<br /><span class='hint'>You will be emailed if$qualifier reviews are submitted or existing reviews are changed.</span>";
	$notetxt = "<div class='revnotes'>" . $t . "</div>";
    }

    // completion
    if (count($nonsubrev) + count($subrev))
	return "<table class='reviewers'>\n"
	    . $numericHeaders . join("", $subrev) . join("", $nonsubrev)
	    . "  </table>\n" . $notetxt;
    else
	return $notetxt;
}


// links below review table
function reviewLinks($prow, $rrows, $crows, $rrow, $mode) {
    global $Conf, $ConfSiteSuffix, $Me, $forceShow, $linkExtra;

    $actingConflict = ($prow->conflictType > 0 && !$forceShow);
    $effAssistant = $Me->actChair($prow) && !$actingConflict;
    $xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";
    
    $nvisible = 0;
    $myrr = null;
    if ($rrows)
	foreach ($rrows as $rr) {
	    if ($Me->canViewReview($prow, $rr, $Conf, $whyNot))
		$nvisible++;
	    if ($rr->contactId == $Me->contactId
		|| (!$myrr && $Me->ownReview($rr)))
		$myrr = $rr;
	}

    // comments
    $pret = "";
    if ($crows && count($crows) > 0 && !$rrow) {
	$cids = array();
	$cnames = array();
	foreach ($crows as $cr)
	    if ($Me->canViewComment($prow, $cr, $Conf)) {
		$cids[] = $cr->commentId;
		$n = "<a class='nowrap' href='#comment$cr->commentId'>";
		if ($Me->canViewCommentIdentity($prow, $cr, $Conf))
		    $n .= htmlspecialchars(abbreviateName($cr->firstName, $cr->lastName));
		else
		    $n .= "anonymous";
		if ($cr->forAuthors > 1 && $cr->forReviewers > 0)
		    $n .= " (Response)";
		else if ($cr->forAuthors > 1)
		    $n .= " (Response in progress)";
		$cnames[] = $n . "</a>";
	    }
	if (count($cids) > 0)
	    $pret = "<div class='revnotes'><a href='#comment$cids[0]'><strong>" . plural(count($cids), "Comment") . "</strong></a> by " . join(", ", $cnames) . "</div>";
    }

    $t = "";

    // see all reviews
    if (($nvisible > 1 || ($nvisible > 0 && !$myrr))
	&& ($mode != "r" || $rrow)) {
	$a = "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;m=r$linkExtra'>";
	$x = $a . $Conf->cacheableImage("allreviews24.png", "[All reviews]", null, "dlimg") . "</a>&nbsp;" . $a . "All reviews</a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // edit paper
    if ($mode != "pe" && $prow->conflictType >= CONFLICT_AUTHOR) {
	$a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId&amp;m=pe$linkExtra'>";
	$x = $a . $Conf->cacheableImage("newreview24.png", "[Edit paper]", null, "dlimg") . "</a>&nbsp;" . $a . "<strong>Edit paper</strong></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // edit review
    if ($mode == "re" || ($mode == "assign" && $t != ""))
	/* no link */;
    else if ($myrr && $rrow != $myrr) {
	$myrlink = unparseReviewOrdinal($myrr);
	$a = "<a href='review$ConfSiteSuffix?r=$myrlink$linkExtra'>";
	if ($Me->canReview($prow, $myrr, $Conf))
	    $x = $a . $Conf->cacheableImage("newreview24.png", "[Edit review]", null, "dlimg") . "</a>&nbsp;" . $a . "<b>Edit your review</b></a>";
	else
	    $x = $a . $Conf->cacheableImage("newreview24.png", "[Your review]", null, "dlimg") . "</a>&nbsp;" . $a . "<b>Your review</b></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    } else if (!$myrr && !$rrow && $Me->canReview($prow, null, $Conf)) {
	$a = "<a href='review$ConfSiteSuffix?p=$prow->paperId&amp;m=re$linkExtra'>";
	$x = $a . $Conf->cacheableImage("newreview24.png", "[Write review]", null, "dlimg") . "</a>&nbsp;" . $a . "<b>Write review</b></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // review assignments
    if ($mode != "assign"
	&& ($prow->reviewType >= REVIEW_SECONDARY || $effAssistant)) {
	$a = "<a href='assign$ConfSiteSuffix?p=$prow->paperId$linkExtra'>";
	$x = $a . $Conf->cacheableImage("assign24.png", "[Assign]", null, "dlimg") . "</a>&nbsp;" . $a . ($Me->privChair ? "Manage assignments" : "Manage external reviews") . "</a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // new comment
    if ($mode != "assign" && $Me->canComment($prow, null, $Conf)) {
	$a = "<a href=\"#commentnew\" onclick='fold(\"addcomment\", 0);return 1'>";
	$x = $a . $Conf->cacheableImage("comment24.png", "[Add comment]", null, "dlimg") . "</a>&nbsp;" . $a . "Add comment</a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // new response
    if ($mode != "assign" && $Conf->timeAuthorRespond()
	&& ($prow->conflictType >= CONFLICT_AUTHOR || $Me->actChair($prow, true))) {
	$cid = array("response", "response", "Add");
	if ($crows)
	    foreach ($crows as $cr)
		if ($cr->forAuthors > 1)
		    $cid = array($cr->commentId, "comment$cr->commentId", "Edit");
	if ($rrow || $prow->conflictType < CONFLICT_AUTHOR)
	    $a = "<a href='paper$ConfSiteSuffix?p=$prow->paperId&amp;c=$cid[0]#$cid[1]'>";
	else
	    $a = "<a href=\"#$cid[1]\">";
	$x = $a . $Conf->cacheableImage("comment24.png", "[$cid[2] response]", null, "dlimg") . "</a>&nbsp;" . $a;
	if ($prow->conflictType >= CONFLICT_AUTHOR)
	    $x .= "<strong>$cid[2] response</strong></a>";
	else
	    $x .= "$cid[2] response</a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // override conflict
    if ($Me->privChair && !$effAssistant) {
	$a = "<a href=\"" . htmlspecialchars(selfhref(array("forceShow" => 1))) . "\">";
	$x = $a . $Conf->cacheableImage("override24.png", "[Override]", null, "dlimg") . "</a>&nbsp;" . $a . "Override conflict</a> to show reviewers and allow editing";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    return $pret . $t;
}
