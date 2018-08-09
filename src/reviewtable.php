<?php
// reviewtable.php -- HotCRP helper class for table of all reviews
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

function _review_table_actas($rr) {
    global $Me;
    if (!get($rr, "contactId") || $rr->contactId == $Me->contactId)
        return "";
    return ' <a href="' . selfHref(array("actas" => $rr->email)) . '">'
        . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($rr)))
        . "</a>";
}

function _retract_review_request_form(PaperInfo $prow, ReviewInfo $rr) {
    return '<small>'
        . Ht::form(hoturl_post("assign", "p=$prow->paperId"))
        . '<div class="inline">'
        . Ht::hidden("retract", $rr->email)
        . Ht::submit("Retract review", array("title" => "Retract this review request", "style" => "font-size:smaller"))
        . '</div></form></small>';
}

// reviewer information
function reviewTable(PaperInfo $prow, $rrows, $crows, $rrow, $mode, $proposals = null) {
    global $Me;
    $conf = $prow->conf;
    $subrev = array();
    $nonsubrev = array();
    $foundRrow = $foundMyReview = $notShown = 0;
    $cflttype = $Me->view_conflict_type($prow);
    $allow_admin = $Me->allow_administer($prow);
    $admin = $Me->can_administer($prow);
    $hideUnviewable = ($cflttype > 0 && !$admin)
        || (!$Me->act_pc($prow) && !$conf->setting("extrev_view"));
    $show_colors = $Me->can_view_reviewer_tags($prow);
    $show_ratings = $Me->can_view_review_ratings($prow);
    $tagger = $show_colors ? new Tagger($Me) : null;
    $xsep = ' <span class="barsep">·</span> ';
    $want_scores = $mode !== "assign" && $mode !== "edit" && $mode !== "re";
    $want_requested_by = false;
    $want_retract = false;
    $score_header = array_map(function ($x) { return ""; }, $conf->review_form()->forder);

    // actual rows
    foreach ($rrows as $rr) {
        $highlight = ($rrow && $rr->reviewId == $rrow->reviewId);
        $foundRrow += $highlight;
        $want_my_scores = $want_scores;
        if ($Me->is_owned_review($rr) && $mode === "re") {
            $want_my_scores = true;
            $foundMyReview++;
        }
        $canView = $Me->can_view_review($prow, $rr);

        // skip unsubmitted reviews
        if (!$canView && $hideUnviewable) {
            if ($rr->reviewNeedsSubmit == 1 && $rr->reviewModified)
                $notShown++;
            continue;
        }

        $t = "";
        $tclass = ($rrow && $highlight ? "reviewers-highlight" : "");

        // review ID
        $id = "Review";
        if ($rr->reviewOrdinal)
            $id .= " #" . $prow->paperId . unparseReviewOrdinal($rr->reviewOrdinal);
        else if ($rr->reviewSubmitted)
            /* OK */;
        else if ($rr->reviewType == REVIEW_SECONDARY && $rr->reviewNeedsSubmit <= 0)
            $id .= " (delegated)";
        else if ($rr->reviewModified > 1 && $rr->timeApprovalRequested > 0)
            $id .= " (awaiting approval)";
        else if ($rr->reviewModified > 1)
            $id .= " (in progress)";
        else if ($rr->reviewModified > 0)
            $id .= " (accepted)";
        else
            $id .= " (not started)";
        $rlink = unparseReviewOrdinal($rr);
        $t .= '<td class="rl nw">';
        if ($rrow && $rrow->reviewId == $rr->reviewId) {
            if ($Me->contactId == $rr->contactId && !$rr->reviewSubmitted)
                $id = "Your $id";
            $t .= '<a href="' . hoturl("review", "p=$prow->paperId&r=$rlink") . '" class="q"><b>' . $id . '</b></a>';
        } else if (!$canView
                   || ($rr->reviewModified <= 1 && !$Me->can_review($prow, $rr))) {
            $t .= $id;
        } else if ($rrow
                   || $rr->reviewModified <= 1
                   || (($mode === "re" || $mode === "assign")
                       && $Me->can_review($prow, $rr))) {
            $t .= '<a href="' . hoturl("review", "p=$prow->paperId&r=$rlink") . '">' . $id . '</a>';
        } else if (Navigation::page() !== "paper") {
            $t .= '<a href="' . hoturl("paper", "p=$prow->paperId#r$rlink") . '">' . $id . '</a>';
        } else {
            $t .= '<a href="#r' . $rlink . '">' . $id . '</a>';
            if ($show_ratings
                && $Me->can_view_review_ratings($prow, $rr)
                && ($ratings = $rr->ratings())) {
                $all = 0;
                foreach ($ratings as $r)
                    $all |= $r;
                if ($all & 126)
                    $t .= " &#x2691;";
                else if ($all & 1)
                    $t .= " &#x2690;";
            }
        }
        $t .= '</td>';

        // primary/secondary glyph
        if ($cflttype > 0 && !$admin)
            $rtype = "";
        else if ($rr->reviewType > 0) {
            $rtype = review_type_icon($rr->reviewType, $rr->reviewNeedsSubmit != 0);
            if ($rr->reviewRound > 0 && $Me->can_view_review_round($prow, $rr))
                $rtype .= '&nbsp;<span class="revround" title="Review round">'
                    . htmlspecialchars($conf->round_name($rr->reviewRound))
                    . "</span>";
        } else
            $rtype = "";

        // reviewer identity
        $showtoken = $rr->reviewToken && $Me->can_review($prow, $rr);
        if (!$Me->can_view_review_identity($prow, $rr)) {
            $t .= ($rtype ? '<td class="rl">' . $rtype . '</td>' : '<td></td>');
        } else {
            if (!$showtoken || !Contact::is_anonymous_email($rr->email))
                $n = $Me->name_html_for($rr);
            else
                $n = "[Token " . encode_token((int) $rr->reviewToken) . "]";
            if ($allow_admin)
                $n .= _review_table_actas($rr);
            $t .= '<td class="rl"><span class="taghl">' . $n . '</span>'
                . ($rtype ? " $rtype" : "") . "</td>";
            if ($show_colors
                && ($p = $conf->pc_member_by_id($rr->contactId))
                && ($color = $p->viewable_color_classes($Me)))
                $tclass .= ($tclass ? " " : "") . $color;
        }

        // requester
        if ($mode === "assign") {
            if ($rr->reviewType < REVIEW_SECONDARY
                && !$showtoken
                && $rr->requestedBy
                && $rr->requestedBy != $rr->contactId
                && $Me->can_view_review_requester($prow, $rr)) {
                $t .= '<td class="rl" style="font-size:smaller">';
                if ($rr->requestedBy == $Me->contactId)
                    $t .= "you";
                else
                    $t .= $Me->reviewer_html_for($rr->requestedBy);
                $t .= '</td>';
                $want_requested_by = true;

                if ($rr->reviewModified <= 0
                    && ($rr->requestedBy == $Me->contactId || $admin))
                    $t .= '<td class="rl">' . _retract_review_request_form($prow, $rr) . '</td>';
            } else
                $t .= '<td></td>';
        }

        // scores
        $scores = array();
        if ($want_my_scores && $canView) {
            $view_score = $Me->view_score_bound($prow, $rr);
            foreach ($conf->review_form()->forder as $fid => $f)
                if ($f->has_options && $f->view_score > $view_score
                    && (!$f->round_mask || $f->is_round_visible($rr))
                    && isset($rr->$fid) && $rr->$fid) {
                    if ($score_header[$fid] === "")
                        $score_header[$fid] = '<th class="revscore">' . $f->web_abbreviation() . "</th>";
                    $scores[$fid] = '<td class="revscore need-tooltip" data-rf="' . $f->uid() . '" data-tooltip-info="rf-score">'
                        . $f->unparse_value($rr->$fid, ReviewField::VALUE_SC)
                        . '</td>';
                }
        }

        // affix
        if (!$rr->reviewSubmitted)
            $nonsubrev[] = array($tclass, $t, $scores);
        else
            $subrev[] = array($tclass, $t, $scores);
    }

    // proposed review rows
    if ($proposals)
        foreach ($proposals as $rr) {
            $t = "";

            // review ID
            $t = '<td class="rl">Proposed review</td>';

            // reviewer identity
            $t .= '<td class="rl">' . Text::user_html($rr);
            if ($allow_admin)
                $t .= _review_table_actas($rr);
            $t .= "</td>";

            // requester
            if ($cflttype <= 0 || $admin) {
                $t .= '<td class="rl" style="font-size:smaller">';
                if ($rr->requestedBy) {
                    if ($rr->requestedBy == $Me->contactId)
                        $t .= "you";
                    else
                        $t .= $Me->reviewer_html_for($rr->requestedBy);
                }
                $t .= '</td>';
                $want_requested_by = true;
            }

            $t .= '<td class="rl">';
            if ($admin) {
                $t .= '<small>'
                    . Ht::form(hoturl_post("assign", "p=$prow->paperId"))
                    . Ht::hidden("firstName", $rr->firstName)
                    . Ht::hidden("lastName", $rr->lastName)
                    . Ht::hidden("email", $rr->email)
                    . Ht::hidden("affiliation", $rr->affiliation)
                    . Ht::hidden("reason", $rr->reason);
                if ($rr->reviewRound !== null) {
                    if ($rr->reviewRound == 0)
                        $rname = "unnamed";
                    else
                        $rname = $conf->round_name($rr->reviewRound);
                    if ($rname)
                        $t .= Ht::hidden("round", $rname);
                }
                $apptext = "Approve review";
                if (Ht::control_class("need-override-requestreview-" . $rr->email)) {
                    $t .= Ht::hidden("override", 1);
                    $apptext = "Override conflict and approve review";
                }
                $t .= Ht::submit("approvereview", $apptext, array("style" => "font-size:smaller"))
                    . ' '
                    . Ht::submit("denyreview", "Deny request", array("style" => "font-size:smaller"))
                    . '</form>';
            } else if ($Me->contactId && $rr->requestedBy === $Me->contactId)
                $t .= _retract_review_request_form($prow, $rr);
            $t .= '</td>';

            // affix
            $nonsubrev[] = array("", $t);
        }

    // unfinished review notification
    $notetxt = "";
    if ($cflttype >= CONFLICT_AUTHOR && !$admin && $notShown
        && $Me->can_view_review($prow, null)) {
        if ($notShown == 1)
            $t = "1 review remains outstanding.";
        else
            $t = "$notShown reviews remain outstanding.";
        $t .= '<br /><span class="hint">You will be emailed if new reviews are submitted or existing reviews are changed.</span>';
        $notetxt = '<div class="revnotes">' . $t . "</div>";
    }

    // completion
    if (count($nonsubrev) + count($subrev)) {
        if ($want_requested_by)
            array_unshift($score_header, '<th class="rl">Requester</th>');
        $score_header_text = join("", $score_header);
        $t = "<div class=\"reviewersdiv\"><table class=\"reviewers";
        if ($score_header_text)
            $t .= " has-scores";
        $t .= "\">\n";
        $nscores = 0;
        if ($score_header_text) {
            foreach ($score_header as $x)
                $nscores += $x !== "" ? 1 : 0;
            $t .= '<tr><td colspan="2"></td>';
            if ($mode === "assign" && !$want_requested_by)
                $t .= '<td></td>';
            $t .= $score_header_text . "</tr>\n";
        }
        foreach (array_merge($subrev, $nonsubrev) as $r) {
            $t .= '<tr class="rl' . ($r[0] ? " $r[0]" : "") . '">' . $r[1];
            if (get($r, 2)) {
                foreach ($score_header as $fid => $header_needed)
                    if ($header_needed !== "") {
                        $x = get($r[2], $fid);
                        $t .= $x ? : "<td class=\"revscore rs_$fid\"></td>";
                    }
            } else if ($nscores > 0)
                $t .= '<td colspan="' . $nscores . '"></td>';
            $t .= "</tr>\n";
        }
        return $t . "</table></div>\n" . $notetxt;
    } else
        return $notetxt;
}


// links below review table
function reviewLinks(PaperInfo $prow, $rrows, $crows, $rrow, $mode, &$allreviewslink) {
    global $Me;
    $conf = $prow->conf;
    $cflttype = $Me->view_conflict_type($prow);
    $allow_admin = $Me->allow_administer($prow);
    $any_comments = false;
    $admin = $Me->can_administer($prow);
    $xsep = ' <span class="barsep">·</span> ';

    $nvisible = 0;
    $myrr = null;
    if ($rrows)
        foreach ($rrows as $rr) {
            if ($Me->can_view_review($prow, $rr))
                $nvisible++;
            if ($rr->contactId == $Me->contactId
                || (!$myrr && $Me->is_my_review($rr)))
                $myrr = $rr;
        }

    // comments
    $pret = "";
    if ($crows && !empty($crows) && !$rrow && $mode !== "edit") {
        $tagger = new Tagger($Me);
        $viewable_crows = array_filter($crows, function ($cr) use ($Me) { return $Me->can_view_comment($cr->prow, $cr); });
        $cxs = CommentInfo::group_by_identity($viewable_crows, $Me, true);
        if (!empty($cxs)) {
            $count = array_reduce($cxs, function ($n, $cx) { return $n + $cx[1]; }, 0);
            $cnames = array_map(function ($cx) use ($Me, $conf) {
                $cid = CommentInfo::unparse_html_id($cx[0], $conf);
                $tclass = "cmtlink";
                if (($tags = $cx[0]->viewable_tags($Me))
                    && ($color = $cx[0]->conf->tags()->color_classes($tags)))
                    $tclass .= " $color taghh";
                return "<span class=\"nb\"><a class=\"{$tclass}\" href=\"#{$cid}\">"
                    . $cx[0]->unparse_user_html($Me, null)
                    . "</a>"
                    . ($cx[1] > 1 ? " ({$cx[1]})" : "")
                    . $cx[2] . "</span>";
            }, $cxs);
            $first_cid = CommentInfo::unparse_html_id($cxs[0][0], $conf);
            $pret = '<div class="revnotes"><a href="#' . $first_cid . '"><strong>'
                . plural($count, "Comment") . '</strong></a>: '
                . join(" ", $cnames) . '</div>';
            $any_comments = true;
        }
    }

    $t = [];
    $dlimgjs = ["class" => "dlimg", "width" => 24, "height" => 24];

    // see all reviews
    $allreviewslink = false;
    if (($nvisible > 1 || ($nvisible > 0 && !$myrr))
        && ($mode !== "p" || $rrow)) {
        $allreviewslink = true;
        $t[] = '<a href="' . hoturl("paper", "p=$prow->paperId") . '" class="xx revlink">'
            . Ht::img("view48.png", "[All reviews]", $dlimgjs) . "&nbsp;<u>All reviews</u></a>";
    }

    // edit paper
    if ($mode !== "edit"
        && $prow->has_author($Me)
        && !$Me->can_administer($prow)) {
        $t[] = '<a href="' . hoturl("paper", "p=$prow->paperId&amp;m=edit") . '" class="xx revlink">'
            . Ht::img("edit48.png", "[Edit]", $dlimgjs) . "&nbsp;<u><strong>Edit submission</strong></u></a>";
    }

    // edit review
    if ($mode === "re" || ($mode === "assign" && $t !== "") || !$prow)
        /* no link */;
    else if ($myrr && $rrow != $myrr) {
        $myrlink = unparseReviewOrdinal($myrr);
        $a = '<a href="' . hoturl("review", "p=$prow->paperId&r=$myrlink") . '" class="xx revlink">';
        if ($Me->can_review($prow, $myrr))
            $x = $a . Ht::img("review48.png", "[Edit review]", $dlimgjs) . "&nbsp;<u><b>Edit your review</b></u></a>";
        else
            $x = $a . Ht::img("review48.png", "[Your review]", $dlimgjs) . "&nbsp;<u><b>Your review</b></u></a>";
        $t[] = $x;
    } else if (!$myrr && !$rrow && $Me->can_review($prow, null)) {
        $t[] = '<a href="' . hoturl("review", "p=$prow->paperId&amp;m=re") . '" class="xx revlink">'
            . Ht::img("review48.png", "[Write review]", $dlimgjs) . "&nbsp;<u><b>Write review</b></u></a>";
    }

    // review assignments
    if ($mode !== "assign" && $mode !== "edit"
        && $Me->can_request_review($prow, true)) {
        $t[] = '<a href="' . hoturl("assign", "p=$prow->paperId") . '" class="xx revlink">'
            . Ht::img("assign48.png", "[Assign]", $dlimgjs) . "&nbsp;<u>" . ($admin ? "Assign reviews" : "External reviews") . "</u></a>";
    }

    // new comment
    $nocmt = preg_match('/\A(?:assign|contact|edit|re)\z/', $mode);
    if (!$allreviewslink && !$nocmt && $Me->can_comment($prow, null)) {
        $t[] = '<a class="ui js-edit-comment xx revlink" href="#cnew">'
            . Ht::img("comment48.png", "[Add comment]", $dlimgjs) . "&nbsp;<u>Add comment</u></a>";
        $any_comments = true;
    }

    // new response
    if (!$nocmt
        && ($prow->has_author($Me) || $allow_admin)
        && $conf->any_response_open) {
        foreach ($conf->resp_rounds() as $rrd) {
            $cr = null;
            foreach ($crows ? : [] as $crow)
                if (($crow->commentType & COMMENTTYPE_RESPONSE)
                    && $crow->commentRound == $rrd->number)
                    $cr = $crow;
            $cr = $cr ? : CommentInfo::make_response_template($rrd->number, $prow);
            if ($Me->can_respond($prow, $cr)) {
                $cid = $conf->resp_round_text($rrd->number) . "response";
                $what = "Add";
                if ($cr->commentId)
                    $what = $cr->commentType & COMMENTTYPE_DRAFT ? "Edit draft" : "Edit";
                $t[] = '<a class="ui js-edit-comment xx revlink" href="#' . $cid . '">'
                    . Ht::img("comment48.png", "[$what response]", $dlimgjs) . "&nbsp;"
                    . ($cflttype >= CONFLICT_AUTHOR ? '<u style="font-weight:bold">' : '<u>')
                    . $what . ($rrd->name == "1" ? "" : " $rrd->name") . ' response</u></a>';
                $any_comments = true;
            }
        }
    }

    // override conflict
    if ($allow_admin && !$admin) {
        $t[] = '<span class="revlink"><a href="' . selfHref(array("forceShow" => 1)) . '" class="xx">'
            . Ht::img("override24.png", "[Override]", "dlimg") . "&nbsp;<u>Override conflict</u></a> to show reviewers and allow editing</span>";
    } else if ($Me->privChair && !$allow_admin) {
        $x = '<span class="revlink">You can’t override your conflict because this submission has an administrator.</span>';
    }

    if ($any_comments)
        CommentInfo::echo_script($prow);

    $t = empty($t) ? "" : '<p class="sd">' . join("", $t) . '</p>';
    if ($prow->has_author($Me))
        $t = '<p class="sd">' . $conf->_('You are an <span class="author">author</span> of this submission.') . '</p>' . $t;
    else if ($prow->has_conflict($Me))
        $t = '<p class="sd">' . $conf->_('You have a <span class="conflict">conflict</span> with this submission.') . '</p>' . $t;
    return $pret . $t;
}
