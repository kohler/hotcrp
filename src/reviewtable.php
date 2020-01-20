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
    $subrev = [];
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
    $score_header = array_map(function ($x) { return ""; },
                              $conf->review_form()->forder);
    $last_pc_reviewer = -1;

    // actual rows
    foreach ($rrows as $rr) {
        $want_my_scores = $want_scores;
        if ($user->is_owned_review($rr) && $mode === "re") {
            $want_my_scores = true;
        }
        $canView = $user->can_view_review($prow, $rr);

        // skip unsubmitted reviews;
        // assign page lists actionable reviews separately
        if (!$canView && $hideUnviewable) {
            $last_pc_reviewer = -1;
            continue;
        }

        $tclass = $rrow && $rr->reviewId == $rrow->reviewId ? "reviewers-highlight" : "";
        $isdelegate = $rr->is_subreview() && $rr->requestedBy == $last_pc_reviewer;
        if (!$rr->reviewSubmitted
            && !$rr->reviewOrdinal
            && $isdelegate) {
            $tclass .= ($tclass ? " " : "") . "rldraft";
        }
        if ($rr->reviewType >= REVIEW_PC) {
            $last_pc_reviewer = +$rr->contactId;
        }

        // review ID
        $id = $rr->is_subreview() ? "Subreview" : "Review";
        if ($rr->reviewOrdinal) {
            $id .= " #" . $rr->unparse_ordinal();
        }
        if (!$rr->reviewSubmitted
            && ($rr->timeApprovalRequested >= 0 || !$rr->is_subreview())) {
            $d = $rr->status_description();
            if ($d === "draft")
                $id = "Draft " . $id;
            else
                $id .= " (" . $d . ")";
        }
        $rlink = $rr->unparse_ordinal();

        $t = '<td class="rl nw">';
        if ($rrow && $rrow->reviewId == $rr->reviewId) {
            if ($user->contactId == $rr->contactId && !$rr->reviewSubmitted)
                $id = "Your $id";
            $t .= '<a href="' . $conf->hoturl("review", "p=$prow->paperId&r=$rlink") . '" class="q"><b>' . $id . '</b></a>';
        } else if (!$canView
                   || ($rr->reviewModified <= 1 && !$user->can_review($prow, $rr))) {
            $t .= $id;
        } else if ($rrow
                   || $rr->reviewModified <= 1
                   || (($mode === "re" || $mode === "assign")
                       && $user->can_review($prow, $rr))) {
            $t .= '<a href="' . $conf->hoturl("review", "p=$prow->paperId&r=$rlink") . '">' . $id . '</a>';
        } else if (Navigation::page() !== "paper") {
            $t .= '<a href="' . $conf->hoturl("paper", "p=$prow->paperId#r$rlink") . '">' . $id . '</a>';
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
        $rtype = "";
        if (($cflttype <= 0 || $admin) && $rr->reviewType > 0) {
            $rtype = $rr->type_icon();
            if ($rr->reviewRound > 0
                && $user->can_view_review_round($prow, $rr)) {
                $rtype .= '&nbsp;<span class="revround" title="Review round">'
                    . htmlspecialchars($conf->round_name($rr->reviewRound))
                    . "</span>";
            }
        }

        // reviewer identity
        $showtoken = $rr->reviewToken && $user->can_review($prow, $rr);
        if (!$user->can_view_review_identity($prow, $rr)) {
            $t .= ($rtype ? '<td class="rl">' . $rtype . '</td>' : '<td></td>');
        } else {
            if (!$showtoken || !Contact::is_anonymous_email($rr->email)) {
                $n = $user->reviewer_html_for($rr);
            } else {
                $n = "[Token " . encode_token((int) $rr->reviewToken) . "]";
            }
            if ($allow_actas) {
                $n .= _review_table_actas($user, $rr);
            }
            $t .= '<td class="rl"><span class="taghl" title="'
                . $rr->email . '">' . $n . '</span>'
                . ($rtype ? " $rtype" : "") . "</td>";
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
            } else {
                $t .= '<td></td>';
            }
        }

        // scores
        $scores = [];
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
        $subrev[] = [$tclass, $t, $scores];
    }

    // completion
    if (!empty($subrev)) {
        if ($want_requested_by)
            array_unshift($score_header, '<th class="rl"></th>');
        $score_header_text = join("", $score_header);
        $t = "<div class=\"reviewersdiv\"><table class=\"reviewers";
        if ($score_header_text)
            $t .= " has-scores";
        $t .= "\">";
        $nscores = 0;
        if ($score_header_text) {
            foreach ($score_header as $x) {
                $nscores += $x !== "" ? 1 : 0;
            }
            $t .= '<thead><tr><th colspan="2"></th>';
            if ($mode === "assign" && !$want_requested_by) {
                $t .= '<th></th>';
            }
            $t .= $score_header_text . "</tr></thead>";
        }
        $t .= '<tbody>';
        foreach ($subrev as $r) {
            $t .= '<tr class="rl' . ($r[0] ? " $r[0]" : "") . '">' . $r[1];
            if (get($r, 2)) {
                foreach ($score_header as $fid => $header_needed)
                    if ($header_needed !== "") {
                        $x = get($r[2], $fid);
                        $t .= $x ? : "<td class=\"revscore rs_$fid\"></td>";
                    }
            } else if ($nscores > 0) {
                $t .= '<td colspan="' . $nscores . '"></td>';
            }
            $t .= "</tr>";
        }
        return $t . "</tbody></table></div>\n";
    } else {
        return "";
    }
}
