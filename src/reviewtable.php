<?php
// reviewtable.php -- HotCRP helper class for table of all reviews
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

function _review_table_actas($rr) {
    global $Me;
    if (!@$rr->contactId || $rr->contactId == $Me->contactId)
        return "";
    return ' <a href="' . selfHref(array("actas" => $rr->email)) . '">'
        . Ht::img("viewas.png", "[Act as]", array("title" => "Act as " . Text::name_text($rr)))
        . "</a>";
}

function _review_table_round_selector($prow, $rr) {
    global $Conf;
    $rlist = $Conf->round_list();
    if (count($rlist) == 1)
        return "";
    if (count($rlist) == 2 && !$Conf->round0_defined())
        return '&nbsp;<span class="revround" title="Review round">'
            . htmlspecialchars($Conf->round_name($rr->reviewRound, true))
            . "</span>";
    $sel = array();
    foreach ($rlist as $rnum => $rname)
        if ($rnum == 0 && $Conf->round0_defined())
            $sel["default"] = "default";
        else if ($rnum && $rname !== ";")
            $sel[$rname] = $rname;
    $crname = $Conf->current_round_name();
    if ($crname && !@$sel[$crname])
        $sel[$crname] = $crname;
    return '&nbsp;'
        . Ht::form(hoturl_post("assign", "p={$prow->paperId}&amp;r={$rr->reviewId}&amp;setround=1"))
        . '<div class="inline">'
        . Ht::select("round", $sel, $rr->reviewRound ? $Conf->round_name($rr->reviewRound) : "default",
                     array("onchange" => "save_review_round(this)", "title" => "Set review round"))
        . '</div></form>';
}

function _retract_review_request_form($prow, $rr) {
    return '<small>'
        . Ht::form(hoturl_post("assign", "p=$prow->paperId"))
        . '<div class="inline">'
        . Ht::hidden("retract", $rr->email)
        . Ht::submit("Retract review", array("title" => "Retract this review request", "style" => "font-size:smaller"))
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
        || (!$Me->act_pc($prow) && !$Conf->setting("extrev_view"));
    $show_colors = $Me->can_view_reviewer_tags($prow);
    $xsep = ' <span class="barsep">·</span> ';
    $want_scores = $mode != "assign" && $mode != "edit" && $mode != "re";
    $want_requested_by = false;
    $want_retract = false;
    $pcm = pcMembers();
    $score_header = array();

    // actual rows
    foreach ($rrows as $rr) {
        $highlight = ($rrow && $rr->reviewId == $rrow->reviewId);
        $foundRrow += $highlight;
        if ($Me->is_my_review($rr))
            $foundMyReview++;
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
                     && $Me->can_review($prow, $rr)))
            $t .= '<td><a href="' . hoturl("review", "r=$rlink") . '">' . $id . '</a></td>';
        else if (Navigation::page() != "paper")
            $t .= '<td><a href="' . hoturl("paper", "p=$prow->paperId#review$rlink") . '">' . $id . '</a></td>';
        else
            $t .= '<td><a href="#review' . $rlink . '">' . $id . '</a></td>';

        // primary/secondary glyph
        if ($conflictType > 0 && !$admin)
            $rtype = "";
        else if ($rr->reviewType > 0) {
            $rtype = review_type_icon($rr->reviewType);
            if ($admin && $mode == "assign")
                $rtype .= _review_table_round_selector($prow, $rr);
            else if ($rr->reviewRound > 0 && $Me->can_view_review_round($prow, $rr))
                $rtype .= '&nbsp;<span class="revround" title="Review round">'
                    . htmlspecialchars($Conf->round_name($rr->reviewRound, true))
                    . "</span>";
        } else
            $rtype = "";

        // reviewer identity
        $showtoken = $rr->reviewToken && $Me->can_review($prow, $rr);
        if (!$Me->can_view_review_identity($prow, $rr, null)) {
            $t .= ($rtype ? "<td>$rtype</td>" : '<td class="empty"></td>');
        } else {
            if (!$showtoken || !Contact::is_anonymous_email($rr->email)) {
                $u = @$pcm[$rr->contactId] ? : $rr;
                if ($mode == "assign")
                    $n = Text::user_html($rr);
                else
                    $n = Text::name_html($rr);
            } else
                $n = "[Token " . encode_token((int) $rr->reviewToken) . "]";
            if ($allow_admin)
                $n .= _review_table_actas($rr);
            $t .= '<td class="rl rl_name">' . $n . ($rtype ? " $rtype" : "") . "</td>";
            if ($show_colors && (@$rr->contactRoles || @$rr->contactTags)) {
                $tags = Contact::roles_all_contact_tags(@$rr->contactRoles, @$rr->contactTags);
                if (($color = TagInfo::color_classes($tags)))
                    $tclass = $color;
            }
        }

        // requester
        if ($mode == "assign") {
            if (($conflictType <= 0 || $admin)
                && $rr->reviewType == REVIEW_EXTERNAL
                && !$showtoken) {
                $t .= '<td style="font-size:smaller">';
                if ($rr->requestedBy == $Me->contactId)
                    $t .= "you";
                else {
                    $u = @$pcm[$rr->requestedBy] ? : array($rr->reqFirstName, $rr->reqLastName, $rr->reqEmail);
                    $t .= Text::user_html($u);
                }
                $t .= '</td>';
                $want_requested_by = true;
            } else
                $t .= '<td class="empty"></td>';
        }

        // actions
        if ($mode == "assign"
            && ($conflictType <= 0 || $admin)
            && $rr->reviewType == REVIEW_EXTERNAL
            && $rr->reviewModified <= 0
            && ($rr->requestedBy == $Me->contactId || $admin))
            $t .= '<td>' . _retract_review_request_form($prow, $rr) . '</td>';

        // scores
        $scores = array();
        if ($want_scores && $canView) {
            $view_score = $Me->view_score_bound($prow, $rr);
            $rf = ReviewForm::get($rr);
            foreach ($rf->forder as $fid => $f) {
                if (!$f->has_options || $f->view_score <= $view_score)
                    /* do nothing */;
                else if ($rr->$fid) {
                    if (!@$score_header[$fid])
                        $score_header[$fid] = "<th>" . $f->web_abbreviation() . "</th>";
                    $scores[$fid] = '<td class="revscore rs_' . $fid . ' hottooltip" hottooltip="' . htmlspecialchars($f->value_description($rr->$fid)) . '" hottooltipdir="l" hottooltipnear=">span">'
                        . $f->unparse_value($rr->$fid, ReviewField::VALUE_SC)
                        . '</td>';
                } else if (@$score_header[$fid] === null)
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
            if ($conflictType <= 0 || $admin) {
                $t .= '<td style="font-size:smaller">';
                if ($rr->requestedBy == $Me->contactId)
                    $t .= "you";
                else {
                    $u = @$pcm[$rr->requestedBy] ? : array($rr->reqFirstName, $rr->reqLastName, $rr->reqEmail);
                    $t .= Text::user_html($u);
                }
                $t .= '</td>';
                $want_requested_by = true;
            }

            $t .= '<td>';
            if ($admin)
                $t .= '<small>'
                    . Ht::form(hoturl_post("assign", "p=$prow->paperId"))
                    . '<div class="inline">'
                    . Ht::hidden("name", $rr->name)
                    . Ht::hidden("email", $rr->email)
                    . Ht::hidden("reason", $rr->reason)
                    . Ht::submit("add", "Approve review", array("style" => "font-size:smaller"))
                    . ' '
                    . Ht::submit("deny", "Deny request", array("style" => "font-size:smaller"))
                    . '</div></form>';
            else if ($rr->reqEmail == $Me->email)
                $t .= _retract_review_request_form($prow, $rr);
            $t .= '</td>';

            // affix
            $nonsubrev[] = array("", $t);
        }

    // unfinished review notification
    $notetxt = "";
    if ($conflictType >= CONFLICT_AUTHOR && !$admin && $notShown
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
        $t = "<table class=\"reviewers\">\n";
        if ($want_requested_by)
            array_unshift($score_header, '<th class="revsl">Requester</th>');
        if (count($score_header))
            $t .= '<tr><td class="empty" colspan="2"></td>'
                . join("", $score_header) . "</tr>\n";
        foreach (array_merge($subrev, $nonsubrev) as $r) {
            $t .= '<tr class="rl' . ($r[0] ? " $r[0]" : "") . '">' . $r[1];
            if (@$r[2]) {
                foreach ($score_header as $fid => $header_needed)
                    if ($header_needed) {
                        $x = @$r[2][$fid];
                        $t .= $x ? : "<td class=\"revscore rs_$fid\"></td>";
                    }
            } else if (count($score_header))
                $t .= '<td colspan="' . count($score_header) . '"></td>';
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
    if ($crows && count($crows) > 0 && !$rrow) {
        $cids = array();
        $cnames = array();
        foreach ($crows as $cr)
            if ($Me->can_view_comment($prow, $cr, null)) {
                $cids[] = $cr->commentId;
                if ($Me->can_view_comment_identity($prow, $cr, null))
                    $n = Text::abbrevname_html($cr->user());
                else
                    $n = "anonymous";
                if ($cr->commentType & COMMENTTYPE_RESPONSE) {
                    $rname = $Conf->resp_round_name($cr->commentRound);
                    $n = ($n === "anonymous" ? "" : " ($n)");
                    if (($cr->commentType & COMMENTTYPE_DRAFT) && $rname != "1")
                        $n = "<i>Draft $rname Response</i>$n";
                    else if ($cr->commentType & COMMENTTYPE_DRAFT)
                        $n = "<i>Draft Response</i>$n";
                    else if ($rname != "1")
                        $n = "<i>$rname Response</i>$n";
                    else
                        $n = "<i>Response</i>$n";
                }
                $tclass = "cmtlink";
                if ($cr->commentTags
                    && ($color = TagInfo::color_classes($cr->commentTags))) {
                    if (TagInfo::classes_have_colors($color))
                        $tclass .= " cmtlinkcolor";
                    $tclass .= " $color";
                }
                $cnames[] = '<a class="' . $tclass . '" href="#comment' . $cr->commentId . '">' . $n . '</a>';
            }
        if (count($cids) > 0)
            $pret = '<div class="revnotes"><a href="#comment' . $cids[0] . '"><strong>' . plural(count($cids), "Comment") . '</strong></a>: <span class="nw">' . join(',</span> <span class="nw">', $cnames) . "</span></div>";
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
        if ($Me->can_review($prow, $myrr))
            $x = $a . Ht::img("review24.png", "[Edit review]", "dlimg") . "&nbsp;<u><b>Edit your review</b></u></a>";
        else
            $x = $a . Ht::img("review24.png", "[Your review]", "dlimg") . "&nbsp;<u><b>Your review</b></u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    } else if (!$myrr && !$rrow && $Me->can_review($prow, null)) {
        $x = '<a href="' . hoturl("review", "p=$prow->paperId&amp;m=re") . '" class="xx">'
            . Ht::img("review24.png", "[Write review]", "dlimg") . "&nbsp;<u><b>Write review</b></u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    }

    // review assignments
    if ($mode != "assign" && $Me->can_request_review($prow, true)) {
        $x = '<a href="' . hoturl("assign", "p=$prow->paperId") . '" class="xx">'
            . Ht::img("assign24.png", "[Assign]", "dlimg") . "&nbsp;<u>" . ($admin ? "Assign reviews" : "External reviews") . "</u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    }

    // new comment
    if (!$allreviewslink && $mode != "assign" && $mode != "contact"
        && $Me->can_comment($prow, null)) {
        $x = "<a href=\"" . selfHref(array("c" => "new")) . '#commentnew" onclick="return open_new_comment(1)" class="xx">'
            . Ht::img("comment24.png", "[Add comment]", "dlimg") . "&nbsp;<u>Add comment</u></a>";
        $t .= ($t == "" ? "" : $xsep) . $x;
    }

    // new response
    if ($mode != "assign"
        && ($prow->conflictType >= CONFLICT_AUTHOR || $allow_admin)
        && ($rrounds = $Conf->time_author_respond()))
        foreach ($rrounds as $i => $rname) {
            $cid = array("newresp_$rname", "Add");
            if ($crows)
                foreach ($crows as $cr)
                    if (($cr->commentType & COMMENTTYPE_RESPONSE) && $cr->commentRound == $i) {
                        $cid = array("comment$cr->commentId", "Edit");
                        if ($cr->commentType & COMMENTTYPE_DRAFT)
                            $cid[1] = "Edit draft";
                    }
            if ($rrow || $conflictType < CONFLICT_AUTHOR)
                $x = '<a href="' . hoturl("paper", "p=$prow->paperId#$cid[0]") . '"';
            else
                $x = '<a href="#' . $cid[0]
                    . '" onclick=\'return papercomment.edit_response('
                    . json_encode($rname) . ')\'';
            $x .= ' class="xx">'
                . Ht::img("comment24.png", "[$cid[1] response]", "dlimg") . "&nbsp;"
                . ($conflictType >= CONFLICT_AUTHOR ? '<u style="font-weight:bold">' : '<u>')
                . $cid[1] . ($i ? " $rname" : "") . ' response</u></a>';
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
