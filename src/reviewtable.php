<?php
// reviewtable.php -- HotCRP helper class for table of all reviews
// Copyright (c) 2006-2019 Eddie Kohler; see LICENSE.

function _review_table_actas($user, $rr) {
    if (!get($rr, "contactId") || $rr->contactId == $user->contactId)
        return "";
    return ' <a href="' . $user->conf->selfurl(null, ["actas" => $rr->email]) . '">'
        . Ht::img("viewas.png", "[Act as]", ["title" => "Act as " . Text::name_text($rr)])
        . "</a>";
}

// reviewer information
function review_table($user, PaperInfo $prow, $rrows, $rrow, $mode) {
    $conf = $prow->conf;
    $subrev = array();
    $nonsubrev = array();
    $foundRrow = $foundMyReview = $notShown = 0;
    $cflttype = $user->view_conflict_type($prow);
    $allow_actas = $user->privChair && $user->allow_administer($prow);
    $admin = $user->can_administer($prow);
    $hideUnviewable = ($cflttype > 0 && !$admin)
        || (!$user->act_pc($prow) && !$conf->setting("extrev_view"));
    $show_colors = $user->can_view_reviewer_tags($prow);
    $show_ratings = $user->can_view_review_ratings($prow);
    $tagger = $show_colors ? new Tagger($user) : null;
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
        if ($user->is_owned_review($rr) && $mode === "re") {
            $want_my_scores = true;
            $foundMyReview++;
        }
        $canView = $user->can_view_review($prow, $rr);

        // skip unsubmitted reviews
        if (!$canView && $hideUnviewable) {
            if ($rr->reviewNeedsSubmit == 1 && $rr->reviewModified > 0)
                $notShown++;
            continue;
        }
        // assign page lists actionable reviews separately
        if ($rr->reviewModified <= 1
            && $rr->reviewType < REVIEW_PC
            && $mode === "assign"
            && ($admin || $rr->requestedBy == $user->contactId))
            continue;

        $t = "";
        $tclass = ($rrow && $highlight ? "reviewers-highlight" : "");

        // review ID
        $id = "Review";
        if ($rr->reviewOrdinal)
            $id .= " #" . $prow->paperId . unparseReviewOrdinal($rr->reviewOrdinal);
        if (!$rr->reviewSubmitted) {
            if ($rr->reviewType == REVIEW_SECONDARY
                && $rr->reviewNeedsSubmit <= 0
                && $conf->setting("pcrev_editdelegate") < 3) {
                $id .= " (delegated)";
            } else if ($rr->reviewModified > 1) {
                if ($rr->timeApprovalRequested < 0)
                    $id .= " (approved)";
                else if ($rr->timeApprovalRequested > 0)
                    $id .= " (pending approval)";
                else
                    $id .= " (in progress)";
            } else if ($rr->reviewModified > 0) {
                $id .= " (accepted)";
            } else {
                $id .= " (not started)";
            }
        }
        $rlink = unparseReviewOrdinal($rr);
        $t .= '<td class="rl nw">';
        if ($rrow && $rrow->reviewId == $rr->reviewId) {
            if ($user->contactId == $rr->contactId && !$rr->reviewSubmitted)
                $id = "Your $id";
            $t .= '<a href="' . hoturl("review", "p=$prow->paperId&r=$rlink") . '" class="q"><b>' . $id . '</b></a>';
        } else if (!$canView
                   || ($rr->reviewModified <= 1 && !$user->can_review($prow, $rr))) {
            $t .= $id;
        } else if ($rrow
                   || $rr->reviewModified <= 1
                   || (($mode === "re" || $mode === "assign")
                       && $user->can_review($prow, $rr))) {
            $t .= '<a href="' . hoturl("review", "p=$prow->paperId&r=$rlink") . '">' . $id . '</a>';
        } else if (Navigation::page() !== "paper") {
            $t .= '<a href="' . hoturl("paper", "p=$prow->paperId#r$rlink") . '">' . $id . '</a>';
        } else {
            $t .= '<a href="#r' . $rlink . '">' . $id . '</a>';
            if ($show_ratings
                && $user->can_view_review_ratings($prow, $rr)
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
            if ($rr->reviewRound > 0 && $user->can_view_review_round($prow, $rr))
                $rtype .= '&nbsp;<span class="revround" title="Review round">'
                    . htmlspecialchars($conf->round_name($rr->reviewRound))
                    . "</span>";
        } else
            $rtype = "";

        // reviewer identity
        $showtoken = $rr->reviewToken && $user->can_review($prow, $rr);
        if (!$user->can_view_review_identity($prow, $rr)) {
            $t .= ($rtype ? '<td class="rl">' . $rtype . '</td>' : '<td></td>');
        } else {
            if (!$showtoken || !Contact::is_anonymous_email($rr->email))
                $n = $user->name_html_for($rr);
            else
                $n = "[Token " . encode_token((int) $rr->reviewToken) . "]";
            if ($allow_actas)
                $n .= _review_table_actas($user, $rr);
            $t .= '<td class="rl"><span class="taghl">' . $n . '</span>'
                . ($rtype ? " $rtype" : "") . "</td>";
            if ($show_colors
                && ($p = $conf->pc_member_by_id($rr->contactId))
                && ($color = $p->viewable_color_classes($user)))
                $tclass .= ($tclass ? " " : "") . $color;
        }

        // requester
        if ($mode === "assign") {
            if ($rr->reviewType < REVIEW_SECONDARY
                && !$showtoken
                && $rr->requestedBy
                && $rr->requestedBy != $rr->contactId
                && $user->can_view_review_requester($prow, $rr)) {
                $t .= '<td class="rl small">requested by ';
                if ($rr->requestedBy == $user->contactId)
                    $t .= "you";
                else
                    $t .= $user->reviewer_html_for($rr->requestedBy);
                $t .= '</td>';
                $want_requested_by = true;
            } else
                $t .= '<td></td>';
        }

        // scores
        $scores = array();
        if ($want_my_scores && $canView) {
            $view_score = $user->view_score_bound($prow, $rr);
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

    // unfinished review notification
    $notetxt = "";
    if ($cflttype >= CONFLICT_AUTHOR
        && !$admin
        && $notShown
        && $user->can_view_review($prow, null)) {
        if ($notShown == 1)
            $t = "1 review remains outstanding.";
        else
            $t = "$notShown reviews remain outstanding.";
        $t .= '<div class="f-h">You will be emailed if new reviews are submitted or existing reviews are changed.</div>';
        $notetxt = '<div class="revnotes">' . $t . "</div>";
    }

    // completion
    if (count($nonsubrev) + count($subrev)) {
        if ($want_requested_by)
            array_unshift($score_header, '<th class="rl"></th>');
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
