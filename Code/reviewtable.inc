<?php
// reviewtable.inc -- HotCRP helper class for table of all reviews
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

// reviewer information
function reviewTable($prow, $rrows, $crows, $rrow, $mode) {
    global $Conf, $Me, $rf, $forceShow, $linkExtra, $reviewTypeName;
    if (!$rf)
	$rf = reviewForm();

    $subrev = array();
    $nonsubrev = array();
    $foundRrow = $foundMyReview = $notShown = 0;
    $conflictType = $Me->actConflictType($prow);
    $hideUnviewable = ($conflictType > 0 && !$forceShow);
    if (!$Me->isPC && !$Conf->setting("extrev_view"))
	$hideUnviewable = true;
    $admin = $Me->allowAdminister($prow);
    $effAssistant = $admin && !$hideUnviewable;
    $anyScores = false;
    $anyColors = false;
    $colorizer = null;
    $nNumeric = $rf->numNumericScores($prow, $Me);
    $xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";

    // actual rows
    foreach ($rrows as $rr) {
	$highlight = ($rrow && $rr->reviewId == $rrow->reviewId);
	$foundRrow += $highlight;
        if ($Me->ownReview($rr))
	    $foundMyReview++;
	$canView = $Me->canViewReview($prow, $rr, null);

	// skip unsubmitted reviews
	if (!$canView && $hideUnviewable) {
	    if ($rr->reviewNeedsSubmit == 1 && $rr->reviewModified)
		$notShown++;
	    continue;
	}

	$t = "";
	$tclass = ($rrow && $highlight ? "hilite" : "");

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
	if ($rrow && $rrow->reviewId == $rr->reviewId) {
	    if ($Me->contactId == $rr->contactId && !$rr->reviewSubmitted)
		$id = "Your $id";
	    $t .= "<td><a href='" . hoturl("review", "r=$rlink$linkExtra") . "' class='q'><b>$id</b></a></td>";
	} else if (!$canView)
	    $t .= "<td>$id</td>";
	else if ($rrow || $rr->reviewModified <= 0)
	    $t .= "<td><a href='" . hoturl("review", "r=$rlink$linkExtra") . "'>$id</a></td>";
	else if ($mode == "assign")
	    $t .= "<td><a href='" . hoturl("review", "r=$rlink$linkExtra") . "'>$id</a></td>";
	else
	    $t .= "<td><a href='#review$rlink'>$id</a></td>";

	// primary/secondary glyph
	if ($conflictType > 0 && !$effAssistant)
	    $x = "";
	else if ($rr->reviewType > 0) {
	    $x = $Conf->cacheableImage("_.gif", $reviewTypeName[$rr->reviewType], $reviewTypeName[$rr->reviewType] . " review", "ass$rr->reviewType");
	    if ($rr->reviewRound > 0) {
		if (($rround = defval($Conf->settings["rounds"], $rr->reviewRound)))
		    $x .= "&nbsp;<span class='revround' title='Review round'>" . htmlspecialchars($rround) . "</span>";
		else
		    $x .= "&nbsp;<span class='revround' title='Review round'>?$rr->reviewRound</span>";
	    }
	} else
	    $x = "";

	// reviewer identity
	$showtoken = $rr->reviewToken && $Me->canReview($prow, $rr);
	if (!$Me->canViewReviewerIdentity($prow, $rr, null)) {
	    $t .= ($x ? "<td>$x</td>" : "<td class='empty'></td>");
	    if ($mode == "assign")
		$t .= "<td class='empty'></td>";
	} else {
	    if (!$showtoken || !preg_match('/^anonymous\d*$/', $rr->email))
		$n = Text::name_html($rr->firstName, $rr->lastName,
				     $mode == "assign" ? null : $rr->email);
	    else
		$n = "[Token " . encodeToken((int) $rr->reviewToken) . "]";
	    $t .= "<td>" . $n . ($x ? " $x" : "");
	    if ($admin && $rr->email != $Me->email)
		$t .= " <a href=\"" . selfHref(array("viewContact" => $rr->email)) . "\">" . $Conf->cacheableImage("viewas.png", "[Act as]", "Act as " . Text::name_html($rr)) . "</a>";
	    $t .= "</td>";
	    if ($mode == "assign")
		$t .= "<td>" . Text::user_html(null, null, $rr->email) . "</td>";
	    if (isset($rr->contactTags) && $rr->contactTags && $Me->isPC) {
		if (!$colorizer)
		    $colorizer = new Tagger;
		if (($color = $colorizer->color_classes($rr->contactTags)))
		    $tclass = $color;
	    }
	}

	// review type
	if ($mode != "assign")
	    /* nothing */;
	else if (($conflictType > 0 && !$effAssistant) || $rr->reviewType != REVIEW_EXTERNAL)
	    $t .= "<td class='empty'></td>";
	else {
	    $t .= "<td><small>Requested by " . Text::user_html($rr->reqFirstName, $rr->reqLastName, $rr->reqEmail);
	    if ($rr->reviewModified <= 0
		&& $rr->reviewType == REVIEW_EXTERNAL
		&& ($rr->requestedBy == $Me->contactId || $effAssistant))
		$t .= " (<a href=\"" . hoturl_post("assign", "p=$prow->paperId&amp;retract=$rr->reviewId$linkExtra") . "\" title='Retract this review request'><strong>Retract</strong></a>)";
	    $t .= "</small></td>";
	}

	// scores or retract request
	if ($mode == "assign")
	    $t .= "<td class='empty'></td>";
	else if ($mode != "edit" && $mode != "re")
	    $t .= $rf->webNumericScoresRow($rr, $prow, $Me, $anyScores);

	$t .= "<td></td>";

	// affix
	if (!$rr->reviewSubmitted)
	    $nonsubrev[] = array($tclass, $t);
	else
	    $subrev[] = array($tclass, $t);
	$anyColors = $anyColors || ($tclass != "");
    }

    // headers
    $numericHeaders = "";
    if ($anyScores)
	$numericHeaders = "<td class='empty' colspan='2'></td>" . $rf->webNumericScoresHeader($prow, $Me);

    // unfinished review notification
    $notetxt = "";
    if ($conflictType >= CONFLICT_AUTHOR && !$effAssistant && $notShown
	&& $Me->canViewReview($prow, null, null)) {
	$qualifier = (count($subrev) + count($nonsubrev) ? " additional" : "");
	if ($notShown == 1)
	    $t = "1$qualifier review remains outstanding.";
	else
	    $t = "$notShown$qualifier reviews remain outstanding.";
	$t .= "<br /><span class='hint'>You will be emailed if$qualifier reviews are submitted or existing reviews are changed.</span>";
	$notetxt = "<div class='revnotes'>" . $t . "</div>";
    }

    // completion
    if (count($nonsubrev) + count($subrev)) {
	$t = "<table class='reviewers'>\n";
	$trstart = ($anyColors ? "<td class='empty' style='padding-right:7px'></td>" : "");
	if ($numericHeaders)
	    $t .= "<tr>" . $trstart . $numericHeaders . "</tr>\n";
	foreach ($subrev as $r)
	    $t .= "<tr" . ($r[0] ? " class='$r[0]'>" : ">") . $trstart . $r[1] . "</tr>\n";
	foreach ($nonsubrev as $r)
	    $t .= "<tr" . ($r[0] ? " class='$r[0]'>" : ">") . $trstart . $r[1] . "</tr>\n";
	return $t . "</table>\n" . $notetxt;
    } else
	return $notetxt;
}


// links below review table
function reviewLinks($prow, $rrows, $crows, $rrow, $mode, &$allreviewslink) {
    global $Conf, $Me, $forceShow, $linkExtra;

    $conflictType = $Me->actConflictType($prow);
    $actingConflict = ($conflictType > 0 && !$forceShow);
    $admin = $Me->allowAdminister($prow);
    $effAssistant = $Me->canAdminister($prow) && !$actingConflict;
    $xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";

    $nvisible = 0;
    $myrr = null;
    if ($rrows)
	foreach ($rrows as $rr) {
	    if ($Me->canViewReview($prow, $rr, null))
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
	    if ($Me->canViewComment($prow, $cr, null)) {
		$cids[] = $cr->commentId;
		$n = "<a class='nowrap' href='#comment$cr->commentId'>";
		if ($Me->canViewCommentIdentity($prow, $cr, null))
		    $n .= Text::abbrevname_html($cr);
		else
		    $n .= "anonymous";
		if ($cr->commentType & COMMENTTYPE_RESPONSE)
                    $n .= ($cr->commentType & COMMENTTYPE_DRAFT
                           ? " (Response in progress)" : " (Response)");
		$cnames[] = $n . "</a>";
	    }
	if (count($cids) > 0)
	    $pret = "<div class='revnotes'><a href='#comment$cids[0]'><strong>" . plural(count($cids), "Comment") . "</strong></a> by " . join(", ", $cnames) . "</div>";
    }

    $t = "";

    // see all reviews
    $allreviewslink = false;
    if (($nvisible > 1 || ($nvisible > 0 && !$myrr))
	&& ($mode != "r" || $rrow)) {
	$allreviewslink = true;
	$x = "<a href='" . hoturl("review", "p=$prow->paperId&amp;m=r$linkExtra") . "' class='xx'>"
	    . $Conf->cacheableImage("view24.png", "[All reviews]", null, "dlimg") . "&nbsp;<u>All reviews</u></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // edit paper
    if ($mode != "pe" && $prow->conflictType >= CONFLICT_AUTHOR
	&& !$Me->canAdminister($prow)) {
	$x = "<a href='" . hoturl("paper", "p=$prow->paperId&amp;m=pe$linkExtra") . "' class='xx'>"
	    . $Conf->cacheableImage("edit24.png", "[Edit paper]", null, "dlimg") . "&nbsp;<u><strong>Edit paper</strong></u></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // edit review
    if ($mode == "re" || ($mode == "assign" && $t != ""))
	/* no link */;
    else if ($myrr && $rrow != $myrr) {
	$myrlink = unparseReviewOrdinal($myrr);
	$a = "<a href='" . hoturl("review", "r=$myrlink$linkExtra") . "' class='xx'>";
	if ($Me->canReview($prow, $myrr))
	    $x = $a . $Conf->cacheableImage("review24.png", "[Edit review]", null, "dlimg") . "&nbsp;<u><b>Edit your review</b></u></a>";
	else
	    $x = $a . $Conf->cacheableImage("review24.png", "[Your review]", null, "dlimg") . "&nbsp;<u><b>Your review</b></u></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    } else if (!$myrr && !$rrow && $Me->canReview($prow, null)) {
	$x = "<a href='" . hoturl("review", "p=$prow->paperId&amp;m=re$linkExtra") . "' class='xx'>"
	    . $Conf->cacheableImage("review24.png", "[Write review]", null, "dlimg") . "&nbsp;<u><b>Write review</b></u></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // review assignments
    if ($mode != "assign"
	&& ($prow->reviewType >= REVIEW_SECONDARY || $effAssistant)) {
	$x = "<a href='" . hoturl("assign", "p=$prow->paperId$linkExtra") . "' class='xx'>"
	    . $Conf->cacheableImage("assign24.png", "[Assign]", null, "dlimg") . "&nbsp;<u>" . ($admin ? "Assign reviews" : "External reviews") . "</u></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // new comment
    if (!$allreviewslink && $mode != "assign" && $mode != "contact"
	&& $Me->canComment($prow, null)) {
	$x = "<a href=\"" . selfHref(array("c" => "new")) . "#commentnew\" onclick='return open_new_comment(1)' class='xx'>"
	    . $Conf->cacheableImage("comment24.png", "[Add comment]", null, "dlimg") . "&nbsp;<u>Add comment</u></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // new response
    if ($mode != "assign" && $Conf->timeAuthorRespond()
	&& ($prow->conflictType >= CONFLICT_AUTHOR || $Me->allowAdminister($prow))) {
	$cid = array("response", "response", "Add");
	if ($crows)
	    foreach ($crows as $cr)
		if ($cr->commentType & COMMENTTYPE_RESPONSE)
		    $cid = array($cr->commentId, "comment$cr->commentId", "Edit");
	if ($rrow || $conflictType < CONFLICT_AUTHOR)
	    $a = "<a href='" . hoturl("paper", "p=$prow->paperId&amp;c=$cid[0]#$cid[1]") . "' class='xx'>";
	else
	    $a = "<a href=\"#$cid[1]\" class='xx'>";
	$x = $a . $Conf->cacheableImage("comment24.png", "[$cid[2] response]", null, "dlimg") . "&nbsp;<u>";
	if ($conflictType >= CONFLICT_AUTHOR)
	    $x .= "<strong>$cid[2] response</strong></u></a>";
	else
	    $x .= "$cid[2] response</u></a>";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    // override conflict
    if ($admin && !$effAssistant) {
	$x = "<a href=\"" . selfHref(array("forceShow" => 1)) . "\" class='xx'>"
	    . $Conf->cacheableImage("override24.png", "[Override]", null, "dlimg") . "&nbsp;<u>Override conflict</u></a> to show reviewers and allow editing";
	$t .= ($t == "" ? "" : $xsep) . $x;
    }

    return $pret . $t;
}
