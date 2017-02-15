<?php
// reviewtable.php -- HotCRP helper class for table of all reviews
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function _review_table_actas($rr) {
    global $Me;
    if (!get($rr, "contactId") || $rr->contactId == $Me->contactId)
        return "";
    return ' <a href="' . selfHref(array("actas" => $rr->email)) . '">'
        . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($rr)))
        . "</a>";
}

function _review_table_round_selector(PaperInfo $prow, $rr) {
    $sel = $prow->conf->round_selector_options($rr->reviewRound);
    if (count($sel) <= 1) {
        if (get($sel, "unnamed") || count($sel) == 0)
            return "";
        reset($sel);
        return '&nbsp;<span class="revround" title="Review round">'
            . htmlspecialchars($prow->conf->round_name($rr->reviewRound))
            . "</span>";
    }
    return '&nbsp;'
        . '<form onsubmit="return false"><div class="inline">'
        . Ht::select("round", $sel, $prow->conf->round_name($rr->reviewRound) ? : "unnamed",
                     array("onchange" => "save_review_round(this)", "title" => "Set review round", "data-reviewid" => $rr->reviewId))
        . '</div></form>';
}

function _retract_review_request_form(PaperInfo $prow, $rr) {
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
    $tagger = $show_colors ? new Tagger($Me) : null;
    $xsep = ' <span class="barsep">·</span> ';
    $want_scores = $mode !== "assign" && $mode !== "edit" && $mode !== "re";
    $want_requested_by = false;
    $want_retract = false;
    $pcm = pcMembers();
    $score_header = array();

    // actual rows
    foreach ($rrows as $rr) {
        $highlight = ($rrow && $rr->reviewId == $rrow->reviewId);
        $foundRrow += $highlight;
        $want_my_scores = $want_scores;
        if ($Me->is_my_review($rr) && $mode === "re") {
            $want_my_scores = true;
            $foundMyReview++;
        }
        $canView = $Me->can_view_review($prow, $rr, null);

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
            $id .= " #" . $prow->paperId . unparseReviewOrdinal($rr->reviewOrdinal);
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
        $t .= '<td class="nw">';
        if ($rrow && $rrow->reviewId == $rr->reviewId) {
            if ($Me->contactId == $rr->contactId && !$rr->reviewSubmitted)
                $id = "Your $id";
            $t .= '<a href="' . hoturl("review", "p=$prow->paperId&r=$rlink") . '" class="q"><b>' . $id . '</b></a>';
        } else if (!$canView)
            $t .= $id;
        else if ($rrow || $rr->reviewModified <= 0
                 || (($mode === "re" || $mode === "assign")
                     && $Me->can_review($prow, $rr)))
            $t .= '<a href="' . hoturl("review", "p=$prow->paperId&r=$rlink") . '">' . $id . '</a>';
        else if (Navigation::page() !== "paper")
            $t .= '<a href="' . hoturl("paper", "p=$prow->paperId#r$rlink") . '">' . $id . '</a>';
        else
            $t .= '<a href="#r' . $rlink . '">' . $id . '</a>';
        $t .= '</td>';

        // primary/secondary glyph
        if ($cflttype > 0 && !$admin)
            $rtype = "";
        else if ($rr->reviewType > 0) {
            $rtype = review_type_icon($rr->reviewType);
            if ($admin && $mode === "assign")
                $rtype .= _review_table_round_selector($prow, $rr);
            else if ($rr->reviewRound > 0 && $Me->can_view_review_round($prow, $rr))
                $rtype .= '&nbsp;<span class="revround" title="Review round">'
                    . htmlspecialchars($conf->round_name($rr->reviewRound))
                    . "</span>";
        } else
            $rtype = "";

        // reviewer identity
        $showtoken = $rr->reviewToken && $Me->can_review($prow, $rr);
        if (!$Me->can_view_review_identity($prow, $rr, null)) {
            $t .= ($rtype ? "<td>$rtype</td>" : '<td class="empty"></td>');
        } else {
            if (!$showtoken || !Contact::is_anonymous_email($rr->email))
                $n = $Me->name_html_for($rr);
            else
                $n = "[Token " . encode_token((int) $rr->reviewToken) . "]";
            if ($allow_admin)
                $n .= _review_table_actas($rr);
            $t .= '<td class="rl"><span class="taghl">' . $n . '</span>'
                . ($rtype ? " $rtype" : "") . "</td>";
            if ($show_colors && (get($rr, "contactRoles") || get($rr, "contactTags"))) {
                $tags = Contact::roles_all_contact_tags(get($rr, "contactRoles"), get($rr, "contactTags"));
                $tags = Tagger::strip_nonviewable($tags, $Me);
                if ($tags && ($color = $conf->tags()->color_classes($tags)))
                    $tclass = $color;
            }
        }

        // requester
        if ($mode === "assign") {
            if (($cflttype <= 0 || $admin)
                && $rr->reviewType == REVIEW_EXTERNAL
                && !$showtoken) {
                $t .= '<td style="font-size:smaller">';
                if ($rr->requestedBy == $Me->contactId)
                    $t .= "you";
                else if (($u = get($pcm, $rr->requestedBy)))
                    $t .= $Me->reviewer_html_for($rr->requestedBy);
                else
                    $t .= Text::user_html([$rr->reqFirstName, $rr->reqLastName, $rr->reqEmail]);
                $t .= '</td>';
                $want_requested_by = true;
            } else
                $t .= '<td class="empty"></td>';
        }

        // actions
        if ($mode === "assign"
            && ($cflttype <= 0 || $admin)
            && $rr->reviewType == REVIEW_EXTERNAL
            && $rr->reviewModified <= 0
            && ($rr->requestedBy == $Me->contactId || $admin))
            $t .= '<td>' . _retract_review_request_form($prow, $rr) . '</td>';

        // scores
        $scores = array();
        if ($want_my_scores && $canView) {
            $view_score = $Me->view_score_bound($prow, $rr);
            $rf = $conf->review_form();
            foreach ($rf->forder as $fid => $f) {
                if (!$f->has_options || $f->view_score <= $view_score
                    || ($f->round_mask && !$f->is_round_visible($rr)))
                    /* do nothing */;
                else if ($rr->$fid) {
                    if (!get($score_header, $fid))
                        $score_header[$fid] = "<th>" . $f->web_abbreviation() . "</th>";
                    $scores[$fid] = '<td class="revscore" data-rf="' . $f->uid() . '">'
                        . $f->unparse_value($rr->$fid, ReviewField::VALUE_SC)
                        . '</td>';
                } else if (get($score_header, $fid) === null)
                    $score_header[$fid] = "";
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
            $t = "<td>Proposed review</td>";

            // reviewer identity
            $t .= "<td>" . Text::user_html($rr);
            if ($allow_admin)
                $t .= _review_table_actas($rr);
            $t .= "</td>";

            // requester
            if ($cflttype <= 0 || $admin) {
                $t .= '<td style="font-size:smaller">';
                if ($rr->requestedBy == $Me->contactId)
                    $t .= "you";
                else if (($u = get($pcm, $rr->requestedBy)))
                    $t .= $Me->reviewer_html_for($rr->requestedBy);
                else
                    $t .= Text::user_html([$rr->reqFirstName, $rr->reqLastName, $rr->reqEmail]);
                $t .= '</td>';
                $want_requested_by = true;
            }

            $t .= '<td>';
            if ($admin) {
                $t .= '<small>'
                    . Ht::form(hoturl_post("assign", "p=$prow->paperId"))
                    . '<div class="inline">'
                    . Ht::hidden("name", $rr->name)
                    . Ht::hidden("email", $rr->email)
                    . Ht::hidden("reason", $rr->reason);
                if ($rr->reviewRound !== null) {
                    if ($rr->reviewRound == 0)
                        $rname = "unnamed";
                    else
                        $rname = $conf->round_name($rr->reviewRound);
                    if ($rname)
                        $t .= Ht::hidden("round", $rname);
                }
                $t .= Ht::submit("add", "Approve review", array("style" => "font-size:smaller"))
                    . ' '
                    . Ht::submit("deny", "Deny request", array("style" => "font-size:smaller"))
                    . '</div></form>';
            }
            else if ($rr->reqEmail === $Me->email)
                $t .= _retract_review_request_form($prow, $rr);
            $t .= '</td>';

            // affix
            $nonsubrev[] = array("", $t);
        }

    // unfinished review notification
    $notetxt = "";
    if ($cflttype >= CONFLICT_AUTHOR && !$admin && $notShown
        && $Me->can_view_review($prow, null, null)) {
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
            array_unshift($score_header, '<th class="revsl">Requester</th>');
        $score_header_text = join("", $score_header);
        $t = "<div class=\"reviewersdiv\"><table class=\"reviewers";
        if ($score_header_text)
            $t .= " reviewers_scores";
        $t .= "\">\n";
        if ($score_header_text) {
            $t .= '<tr><td class="empty" colspan="2"></td>';
            if ($mode === "assign")
                $t .= '<td class="empty"></td>';
            $t .= $score_header_text . "</tr>\n";
        }
        foreach (array_merge($subrev, $nonsubrev) as $r) {
            $t .= '<tr class="rl' . ($r[0] ? " $r[0]" : "") . '">' . $r[1];
            if (get($r, 2)) {
                foreach ($score_header as $fid => $header_needed)
                    if ($header_needed) {
                        $x = get($r[2], $fid);
                        $t .= $x ? : "<td class=\"revscore rs_$fid\"></td>";
                    }
            } else if (count($score_header))
                $t .= '<td colspan="' . count($score_header) . '"></td>';
            $t .= "</tr>\n";
        }
        if ($score_header_text)
            Ht::stash_script("review_form.score_tooltips($(\"table.reviewers_scores\"))", "score_tooltips");
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
            if ($Me->can_view_review($prow, $rr, null))
                $nvisible++;
            if ($rr->contactId == $Me->contactId
                || (!$myrr && $Me->is_my_review($rr)))
                $myrr = $rr;
        }

    // comments
    $pret = "";
    if ($crows && !empty($crows) && !$rrow && $mode !== "edit") {
        $tagger = new Tagger($Me);
        $viewable_crows = array_filter($crows, function ($cr) use ($Me) { return $Me->can_view_comment($cr->prow, $cr, null); });
        $cxs = CommentInfo::group_by_identity($viewable_crows, $Me, true);
        if (!empty($cxs)) {
            $count = array_reduce($cxs, function ($n, $cx) { return $n + $cx[1]; }, 0);
            $cnames = array_map(function ($cx) use ($Me) {
                $cid = CommentInfo::unparse_html_id($cx[0]);
                $tclass = "cmtlink";
                if (($tags = $cx[0]->viewable_tags($Me, null))
                    && ($color = $cx[0]->conf->tags()->color_classes($tags))) {
                    if (TagInfo::classes_have_colors($color))
                        $tclass .= " tagcolorspan";
                    $tclass .= " $color taghl";
                }
                return "<span class=\"nb\"><a class=\"{$tclass}\" href=\"#{$cid}\">"
                    . $cx[0]->unparse_user_html($Me, null)
                    . "</a>"
                    . ($cx[1] > 1 ? " ({$cx[1]})" : "")
                    . $cx[2] . "</span>";
            }, $cxs);
            $first_cid = CommentInfo::unparse_html_id($cxs[0][0]);
            $pret = '<div class="revnotes"><a href="#' . $first_cid . '"><strong>'
                . plural($count, "Comment") . '</strong></a>: '
                . join(" ", $cnames) . '</div>';
            $any_comments = true;
        }
    }

    $t = "";
    $dlimgjs = ["class" => "dlimg", "width" => 24, "height" => 24];

    // see all reviews
    $allreviewslink = false;
    if (($nvisible > 1 || ($nvisible > 0 && !$myrr))
        && ($mode !== "p" || $rrow)) {
        $allreviewslink = true;
        $x = '<a href="' . hoturl("paper", "p=$prow->paperId") . '" class="xx">'
            . Ht::img("view48.png", "[All reviews]", $dlimgjs) . "&nbsp;<u>All reviews</u></a>";
        $t .= ($t === "" ? "" : $xsep) . $x;
    }

    // edit paper
    if ($mode !== "edit" && $prow->conflictType >= CONFLICT_AUTHOR
        && !$Me->can_administer($prow)) {
        $x = '<a href="' . hoturl("paper", "p=$prow->paperId&amp;m=edit") . '" class="xx">'
            . Ht::img("edit48.png", "[Edit]", $dlimgjs) . "&nbsp;<u><strong>Edit submission</strong></u></a>";
        $t .= ($t === "" ? "" : $xsep) . $x;
    }

    // edit review
    if ($mode === "re" || ($mode === "assign" && $t !== "") || !$prow)
        /* no link */;
    else if ($myrr && $rrow != $myrr) {
        $myrlink = unparseReviewOrdinal($myrr);
        $a = '<a href="' . hoturl("review", "p=$prow->paperId&r=$myrlink") . '" class="xx">';
        if ($Me->can_review($prow, $myrr))
            $x = $a . Ht::img("review48.png", "[Edit review]", $dlimgjs) . "&nbsp;<u><b>Edit your review</b></u></a>";
        else
            $x = $a . Ht::img("review48.png", "[Your review]", $dlimgjs) . "&nbsp;<u><b>Your review</b></u></a>";
        $t .= ($t === "" ? "" : $xsep) . $x;
    } else if (!$myrr && !$rrow && $Me->can_review($prow, null)) {
        $x = '<a href="' . hoturl("review", "p=$prow->paperId&amp;m=re") . '" class="xx">'
            . Ht::img("review48.png", "[Write review]", $dlimgjs) . "&nbsp;<u><b>Write review</b></u></a>";
        $t .= ($t === "" ? "" : $xsep) . $x;
    }

    // review assignments
    if ($mode !== "assign" && $mode !== "edit"
        && $Me->can_request_review($prow, true)) {
        $x = '<a href="' . hoturl("assign", "p=$prow->paperId") . '" class="xx">'
            . Ht::img("assign48.png", "[Assign]", $dlimgjs) . "&nbsp;<u>" . ($admin ? "Assign reviews" : "External reviews") . "</u></a>";
        $t .= ($t === "" ? "" : $xsep) . $x;
    }

    // new comment
    $nocmt = preg_match('/\A(?:assign|contact|edit|re)\z/', $mode);
    if (!$allreviewslink && !$nocmt && $Me->can_comment($prow, null)) {
        $x = '<a href="#cnew" onclick="return papercomment.edit_new()" class="xx">'
            . Ht::img("comment48.png", "[Add comment]", $dlimgjs) . "&nbsp;<u>Add comment</u></a>";
        $t .= ($t === "" ? "" : $xsep) . $x;
        $any_comments = true;
    }

    // new response
    if (!$nocmt
        && ($prow->conflictType >= CONFLICT_AUTHOR || $allow_admin)
        && ($rrounds = $conf->time_author_respond()))
        foreach ($rrounds as $i => $rname) {
            $cid = ($i ? $rname : "") . "response";
            $what = "Add";
            if ($crows)
                foreach ($crows as $cr)
                    if (($cr->commentType & COMMENTTYPE_RESPONSE) && $cr->commentRound == $i) {
                        $what = "Edit";
                        if ($cr->commentType & COMMENTTYPE_DRAFT)
                            $what = "Edit draft";
                    }
            $x = '<a href="#' . $cid . '" onclick=\'return papercomment.edit_response(' . json_encode($rname) . ')\' class="xx">'
                . Ht::img("comment48.png", "[$what response]", $dlimgjs) . "&nbsp;"
                . ($cflttype >= CONFLICT_AUTHOR ? '<u style="font-weight:bold">' : '<u>')
                . $what . ($i ? " $rname" : "") . ' response</u></a>';
            $t .= ($t === "" ? "" : $xsep) . $x;
            $any_comments = true;
        }

    // override conflict
    if ($allow_admin && !$admin) {
        $x = '<a href="' . selfHref(array("forceShow" => 1)) . '" class="xx">'
            . Ht::img("override24.png", "[Override]", "dlimg") . "&nbsp;<u>Override conflict</u></a> to show reviewers and allow editing";
        $t .= ($t === "" ? "" : $xsep) . $x;
    } else if ($Me->privChair && !$allow_admin) {
        $x = "You can’t override your conflict because this submission has an administrator.";
        $t .= ($t === "" ? "" : $xsep) . $x;
    }

    if ($any_comments)
        CommentInfo::echo_script($prow);

    if ($prow->has_author($Me))
        $t = '<p class="xd">' . $conf->_('You are an <span class="author">author</span> of this submission.') . '</p>' . $t;
    else if ($prow->has_conflict($Me))
        $t = '<p class="xd">' . $conf->_('You have a <span class="conflict">conflict</span> with this submission.') . '</p>' . $t;
    return $pret . $t;
}
