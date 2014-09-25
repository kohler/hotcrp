<?php
// reviewtable.php -- HotCRP helper class for table of all reviews
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function _retract_review_request_form($prow, $rr) {
    return '<small>'
        . Ht::form_div(hoturl_post("assign", "p=$prow->paperId"))
        . Ht::hidden("retract", $rr->email)
        . Ht::submit("Retract", array("title" => "Retract this review request", "style" => "font-size:smaller"))
        . '</div></form></small>';
}

// reviewer information
function reviewTable($prow, $rrows, $crows, $rrow, $mode, $proposals = null) {
    global $Conf, $Me;

    $subrev = array();
    $nonsubrev = array();
    $foundRrow = $foundMyReview = $notShown = 0;
    $conflictType = $Me->view_conflict_type($prow);
    $allow_admin = $Me->allow_administer($prow);
    $admin = $Me->can_administer($prow);
    $hideUnviewable = ($conflictType > 0 && !$admin)
        || (!$Me->actPC($prow) && !$Conf->setting("extrev_view"));
    $colorizer = ($Me->actPC($prow) ? new Tagger : null);
    $xsep = ' <span class="barsep">&nbsp;|&nbsp;</span> ';
    $want_scores = $mode != "assign" && $mode != "edit" && $mode != "re";
    $score_header = array();

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
            $t .= '<td><a href="' . hoturl("review", "r=$rlink") . '" class="q"><b>' . $id . '</b></a></td>';
        } else if (!$canView)
            $t .= "<td>$id</td>";
        else if ($rrow || $rr->reviewModified <= 0
                 || (($mode == "re" || $mode == "assign")
                     && $Me->canReview($prow, $rr)))
            $t .= '<td><a href="' . hoturl("review", "r=$rlink") . '">' . $id . '</a></td>';
        else if (Navigation::page() != "paper")
            $t .= '<td><a href="' . hoturl("paper", "p=$prow->paperId#review$rlink") . '">' . $id . '</a></td>';
        else
            $t .= '<td><a href="#review' . $rlink . '">' . $id . '</a></td>';

        // primary/secondary glyph
        if ($conflictType > 0 && !$admin)
            $x = "";
        else if ($rr->reviewType > 0) {
            $x = review_type_icon($rr->reviewType);
            if ($rr->reviewRound > 0)
                $x .= '&nbsp;<span class="revround" title="Review round">'
                    . htmlspecialchars($Conf->round_name($rr->reviewRound, true))
                    . "</span>";
        } else
            $x = "";

        // reviewer identity
        $showtoken = $rr->reviewToken && $Me->canReview($prow, $rr);
        if (!$Me->canViewReviewerIdentity($prow, $rr, null)) {
            $t .= ($x ? "<td>$x</td>" : '<td class="empty"></td>');
        } else {
            if (!$showtoken || !preg_match('/^anonymous\d*$/', $rr->email)) {
                if ($mode == "assign")
                    $n = Text::user_html($rr);
                else
                    $n = Text::name_html($rr);
            } else
                $n = "[Token " . encode_token((int) $rr->reviewToken) . "]";
            $t .= "<td>" . $n . ($x ? " $x" : "");
            if ($allow_admin && $rr->email != $Me->email)
                $t .= " <a href=\"" . selfHref(array("actas" => $rr->email)) . "\">" . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($rr))) . "</a>";
            if ($mode == "assign"
                && ($conflictType <= 0 || $admin)
                && $rr->reviewType == REVIEW_EXTERNAL
                && $rr->reviewModified <= 0
                && ($rr->requestedBy == $Me->contactId || $admin))
                $t .= ' ' . _retract_review_request_form($prow, $rr);
            $t .= "</td>";
            if ($colorizer && (@$rr->contactRoles || @$rr->contactTags)) {
                $tags = Contact::roles_all_contact_tags(@$rr->contactRoles, @$rr->contactTags);
                if (($color = $colorizer->color_classes($tags)))
                    $tclass = $color;
            }
        }

        // requester
        $reqt = "";
        if ($mode == "assign"
            && ($conflictType <= 0 || $admin)
            && $rr->reviewType == REVIEW_EXTERNAL
            && !$showtoken) {
            $reqt = '<td class="empty"></td>'
                . '<td style="font-size:smaller" colspan="2">—'
                . 'requested by ';
            if ($rr->reqEmail == $Me->email)
                $reqt .= 'you';
            else
                $reqt .= Text::user_html($rr->reqFirstName, $rr->reqLastName, $rr->reqEmail);
            $reqt .= '</td>';
        }

        // scores
        $scores = array();
        if ($want_scores && $canView) {
            $view_score = $Me->viewReviewFieldsScore($prow, $rr);
            $rf = ReviewForm::get($rr);
            foreach ($rf->forder as $fid => $f) {
                if (!$f->has_options || $f->view_score <= $view_score)
                    /* do nothing */;
                else if ($rr->$fid) {
                    if (!@$score_header[$fid])
                        $score_header[$fid] = "<th>" . $f->web_abbreviation() . "</th>";
                    $scores[$fid] = '<td class="revscore rs_' . $fid . '">'
                        . $f->unparse_value($rr->$fid, true)
                        . '</td>';
                } else if (@$score_header[$fid] === null)
                    $score_header[$fid] = "";
            }
        }

        // affix
        if (!$rr->reviewSubmitted) {
            $nonsubrev[] = array($tclass, $t, $scores);
            if ($reqt)
                $nonsubrev[] = array($tclass, $reqt);
        } else {
            $subrev[] = array($tclass, $t, $scores);
            if ($reqt)
                $subrev[] = array($tclass, $reqt);
        }
    }

    // proposed review rows
    if ($proposals)
        foreach ($proposals as $rr) {
            $t = "";

            // review ID
            $t = "<td>Proposed review</td>";

            // reviewer identity
            $t .= "<td>" . Text::user_html($rr);
            if ($admin)
                $t .= ' <small>'
                    . Ht::form_div(hoturl_post("assign", "p=$prow->paperId"))
                    . Ht::hidden("name", $rr->name)
                    . Ht::hidden("email", $rr->email)
                    . Ht::hidden("reason", $rr->reason)
                    . Ht::submit("add", "Approve", array("style" => "font-size:smaller"))
                    . ' '
                    . Ht::submit("deny", "Deny", array("style" => "font-size:smaller"))
                    . '</div></form>';
            else if ($rr->reqEmail == $Me->email)
                $t .= " " . _retract_review_request_form($prow, $rr);
            $t .= '</td>';

            // requester
            $reqt = "";
            if ($conflictType <= 0 || $admin) {
                $reqt = '<td class="empty"></td>'
                    . '<td style="font-size:smaller" colspan="2">—'
                    . 'requested by ';
                if ($rr->reqEmail == $Me->email)
                    $reqt .= 'you';
                else
                    $reqt .= Text::user_html($rr->reqFirstName, $rr->reqLastName, $rr->reqEmail);
                $reqt .= '</td>';
            }

            // affix
            $nonsubrev[] = array("", $t);
            if ($reqt)
                $nonsubrev[] = array("", $reqt);
        }

    // unfinished review notification
    $notetxt = "";
    if ($conflictType >= CONFLICT_AUTHOR && !$admin && $notShown
        && $Me->canViewReview($prow, null, null)) {
        if ($notShown == 1)
            $t = "1 review remains outstanding.";
        else
            $t = "$notShown reviews remain outstanding.";
        $t .= '<br /><span class="hint">You will be emailed if new reviews are submitted or existing reviews are changed.</span>';
        $notetxt = '<div class="revnotes">' . $t . "</div>";
    }

    // completion
    if (count($nonsubrev) + count($subrev)) {
        $t = "<table class=\"reviewers\">\n";
        if (count($score_header))
            $t .= '<tr><td class="empty" colspan="2"></td>'
                . join("", $score_header) . "</tr>\n";
        foreach (array_merge($subrev, $nonsubrev) as $r) {
            $t .= '<tr' . ($r[0] ? " class=\"$r[0]\"" : "") . '>' . $r[1];
            if (@$r[2])
                foreach ($score_header as $fid => $header_needed)
                    if ($header_needed) {
                        $x = @$r[2][$fid];
                        $t .= $x ? : "<td class=\"revscore rs_$fid\"></td>";
                    }
            $t .= "</tr>\n";
        }
        return $t . "</table>\n" . $notetxt;
    } else
        return $notetxt;
}


// links below review table
function reviewLinks($prow, $rrows, $crows, $rrow, $mode, &$allreviewslink) {
    global $Conf, $Me;

    $conflictType = $Me->view_conflict_type($prow);
    $allow_admin = $Me->allow_administer($prow);
    $admin = $Me->can_administer($prow);
    $xsep = ' <span class="barsep">&nbsp;|&nbsp;</span> ';

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
                $n = '<a class="nowrap" href="#comment' . $cr->commentId . '">';
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
            $pret = '<div class="revnotes"><a href="#comment' . $cids[0] . '"><strong>' . plural(count($cids), "Comment") . "</strong></a> by " . join(", ", $cnames) . "</div>";
    }

    $t = "";

    // see all reviews
    $allreviewslink = false;
    if (($nvisible > 1 || ($nvisible > 0 && !$myrr))
        && ($mode != "r" || $rrow)) {
        $allreviewslink = true;
        $x = '<a href="' . hoturl("paper", "p=$prow->paperId") . '" class="xx">'
            . Ht::img("view24.png", "[All reviews]", "dlimg") . "&nbsp;<u>All reviews</u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    }

    // edit paper
    if ($mode != "pe" && $prow->conflictType >= CONFLICT_AUTHOR
        && !$Me->can_administer($prow)) {
        $x = '<a href="' . hoturl("paper", "p=$prow->paperId&amp;m=pe") . '" class="xx">'
            . Ht::img("edit24.png", "[Edit paper]", "dlimg") . "&nbsp;<u><strong>Edit paper</strong></u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    }

    // edit review
    if ($mode == "re" || ($mode == "assign" && $t != ""))
        /* no link */;
    else if ($myrr && $rrow != $myrr) {
        $myrlink = unparseReviewOrdinal($myrr);
        $a = '<a href="' . hoturl("review", "r=$myrlink") . '" class="xx">';
        if ($Me->canReview($prow, $myrr))
            $x = $a . Ht::img("review24.png", "[Edit review]", "dlimg") . "&nbsp;<u><b>Edit your review</b></u></a>";
        else
            $x = $a . Ht::img("review24.png", "[Your review]", "dlimg") . "&nbsp;<u><b>Your review</b></u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    } else if (!$myrr && !$rrow && $Me->canReview($prow, null)) {
        $x = '<a href="' . hoturl("review", "p=$prow->paperId&amp;m=re") . '" class="xx">'
            . Ht::img("review24.png", "[Write review]", "dlimg") . "&nbsp;<u><b>Write review</b></u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    }

    // review assignments
    if ($mode != "assign" && $Me->canRequestReview($prow, true)) {
        $x = '<a href="' . hoturl("assign", "p=$prow->paperId") . '" class="xx">'
            . Ht::img("assign24.png", "[Assign]", "dlimg") . "&nbsp;<u>" . ($admin ? "Assign reviews" : "External reviews") . "</u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    }

    // new comment
    if (!$allreviewslink && $mode != "assign" && $mode != "contact"
        && $Me->canComment($prow, null)) {
        $x = "<a href=\"" . selfHref(array("c" => "new")) . '#commentnew" onclick="return open_new_comment(1)" class="xx">'
            . Ht::img("comment24.png", "[Add comment]", "dlimg") . "&nbsp;<u>Add comment</u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    }

    // new response
    if ($mode != "assign" && $Conf->timeAuthorRespond()
        && ($prow->conflictType >= CONFLICT_AUTHOR || $allow_admin)) {
        $cid = array("response", "response", "Add");
        if ($crows)
            foreach ($crows as $cr)
                if ($cr->commentType & COMMENTTYPE_RESPONSE)
                    $cid = array($cr->commentId, "comment$cr->commentId", "Edit");
        if ($rrow || $conflictType < CONFLICT_AUTHOR)
            $a = '<a href="' . hoturl("paper", "p=$prow->paperId&amp;c=$cid[0]#$cid[1]") . '" class="xx"';
        else
            $a = "<a href=\"#$cid[1]\" class=\"xx\"";
        $a .= ' onclick="return papercomment.edit_response()"';
        $x = $a . ">" . Ht::img("comment24.png", "[$cid[2] response]", "dlimg") . "&nbsp;<u>";
        if ($conflictType >= CONFLICT_AUTHOR)
            $x .= "<strong>$cid[2] response</strong></u></a>";
        else
            $x .= "$cid[2] response</u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    }

    // override conflict
    if ($allow_admin && !$admin) {
        $x = '<a href="' . selfHref(array("forceShow" => 1)) . '" class="xx">'
            . Ht::img("override24.png", "[Override]", "dlimg") . "&nbsp;<u>Override conflict</u></a> to show reviewers and allow editing";
        $t .= ($t == "" ? "" : $xsep) . $x;
    } else if ($Me->privChair && !$allow_admin) {
        $x = "You can’t override your conflict because this paper has an administrator.";
        $t .= ($t == "" ? "" : $xsep) . $x;
    }

    return $pret . $t;
}
