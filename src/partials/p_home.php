<?php
// src/partials/p_home.php -- HotCRP home page partials
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Home_Partial {
    private $_nh2 = 0;
    private $_in_reviews;
    private $_merit_field;
    private $_my_rinfo;
    private $_pc_rinfo;
    private $_tokens_done;

    static function disabled_request(Contact $user, Qrequest $qreq) {
        if (!$user->is_empty() && $user->is_disabled()) {
            $user->conf->header("Account disabled", "home", ["action_bar" => false]);
            $user->conf->msg("Your account on this site has been disabled by a site administrator. Please contact them with questions.", 0);
            $user->conf->footer();
            exit;
        }
    }

    static function profile_redirect_request(Contact $user, Qrequest $qreq) {
        if (!$user->is_empty() && $qreq->postlogin) {
            LoginHelper::check_postlogin($user, $qreq);
        }
        if ($user->has_account_here()
            && $user->session("freshlogin") === true) {
            if (self::need_profile_redirect($user)) {
                $user->save_session("freshlogin", "redirect");
                Navigation::redirect($user->conf->hoturl("profile", "redirect=1"));
            } else {
                $user->save_session("freshlogin", null);
            }
        }
    }

    static function need_profile_redirect(Contact $user) {
        if (!$user->firstName && !$user->lastName) {
            return true;
        } else if ($user->conf->opt("noProfileRedirect")) {
            return false;
        } else {
            return !$user->affiliation
                || ($user->is_pc_member()
                    && !$user->has_review()
                    && (!$user->collaborators()
                        || ($user->conf->has_topics()
                            && !$user->topic_interest_map())));
        }
    }

    function render_head(Contact $user, Qrequest $qreq, $gx) {
        if ($user->is_empty()) {
            $user->conf->header("Sign in", "home");
        } else {
            $user->conf->header("Home", "home");
        }
        if ($qreq->signedout && $user->is_empty()) {
            $user->conf->msg("You have been signed out of the site.", "xconfirm");
        }
        $gx->push_render_cleanup("__footer");
        echo '<noscript><div class="msg msg-error"><strong>This site requires JavaScript.</strong> Your browser does not support JavaScript.<br><a href="https://github.com/kohler/hotcrp/">Report bad compatibility problems</a></div></noscript>', "\n";
        if ($user->privChair) {
            echo '<div id="msg-clock-drift"></div>';
        }
    }

    static function render_sidebar(Contact $user, Qrequest $qreq, $gx) {
        echo '<div class="homeside">';
        $gx->render_group("home/sidebar");
        echo "</div>\n";
    }

    private function render_h2_home($x) {
        ++$this->_nh2;
        return "<h2 class=\"home home-{$this->_nh2}\">" . $x . "</h2>";
    }

    static function render_admin_sidebar(Contact $user, Qrequest $qreq, $gx) {
        echo '<div class="homeinside"><h4>Administration</h4><ul>';
        $gx->render_group("home/sidebar/admin");
        echo '</ul></div>';
    }
    static function render_admin_settings(Contact $user) {
        echo '<li>', Ht::link("Settings", $user->conf->hoturl("settings")), '</li>';
    }
    static function render_admin_users(Contact $user) {
        echo '<li>', Ht::link("Users", $user->conf->hoturl("users", "t=all")), '</li>';
    }
    static function render_admin_assignments(Contact $user) {
        echo '<li>', Ht::link("Assignments", $user->conf->hoturl("autoassign")), '</li>';
    }
    static function render_admin_mail(Contact $user) {
        echo '<li>', Ht::link("Mail", $user->conf->hoturl("mail")), '</li>';
    }
    static function render_admin_log(Contact $user) {
        echo '<li>', Ht::link("Action log", $user->conf->hoturl("log")), '</li>';
    }

    static function render_info_sidebar(Contact $user, Qrequest $qreq, $gx) {
        ob_start();
        $gx->render_group("home/sidebar/info");
        if (($t = ob_get_clean())) {
            echo '<div class="homeinside"><h4>',
                $user->conf->_c("home", "Conference information"),
                '</h4><ul>', $t, '</ul></div>';
        }
    }
    static function render_info_deadline(Contact $user) {
        if ($user->has_reportable_deadline()) {
            echo '<li>', Ht::link("Deadlines", $user->conf->hoturl("deadlines")), '</li>';
        }
    }
    static function render_info_pc(Contact $user) {
        if ($user->can_view_pc()) {
            echo '<li>', Ht::link("Program committee", $user->conf->hoturl("users", "t=pc")), '</li>';
        }
    }
    static function render_info_site(Contact $user) {
        if (($site = $user->conf->opt("conferenceSite"))
            && $site !== $user->conf->opt("paperSite")) {
            echo '<li>', Ht::link("Conference site", $site), '</li>';
        }
    }
    static function render_info_accepted(Contact $user) {
        assert($user->conf->can_all_author_view_decision());
        if ($user->conf->can_all_author_view_decision()) {
            list($n, $nyes) = $user->conf->count_submitted_accepted();
            echo '<li>', $user->conf->_("%d papers accepted out of %d submitted.", $nyes, $n), '</li>';
        }
    }

    function render_message(Contact $user) {
        if (($t = $user->conf->_i("home", false))) {
            $user->conf->msg($t, 0);
        }
    }

    function render_welcome(Contact $user) {
        echo '<div class="homegrp">Welcome to the ', htmlspecialchars($user->conf->full_name()), " submissions site.";
        if (($site = $user->conf->opt("conferenceSite"))
            && $site !== $user->conf->opt("paperSite"))
            echo " For general conference information, see ", Ht::link(htmlspecialchars($site), htmlspecialchars($site)), ".";
        echo '</div>';
    }

    function render_signin(Contact $user, Qrequest $qreq, $gx) {
        if (!$user->has_email() || $qreq->signin) {
            Signin_Partial::render_signin_form($user, $qreq, $gx);
        }
    }

    function render_search(Contact $user, Qrequest $qreq, $gx) {
        $conf = $user->conf;
        if (!$user->privChair
            && ($user->isPC
                ? !$conf->setting("pc_seeall") && !$conf->has_any_submitted()
                : !$user->is_reviewer())) {
            return;
        }

        echo '<div class="homegrp" id="homelist">',
            Ht::form($conf->hoturl("search"), ["method" => "get"]),
            $this->render_h2_home('<a class="qq" href="' . $conf->hoturl("search") . '" id="homesearch-label">Search</a>');

        $tOpt = PaperSearch::search_types($user);
        echo Ht::entry("q", (string) $qreq->q,
                       array("id" => "homeq", "size" => 32, "title" => "Enter paper numbers or search terms",
                             "class" => "papersearch need-suggest", "placeholder" => "(All)",
                             "aria-labelledby" => "homesearch-label")),
            " &nbsp;in&nbsp; ",
            PaperSearch::searchTypeSelector($tOpt, key($tOpt)), "
        &nbsp; ", Ht::submit("Search"),
            "</form></div>\n";
    }

    function render_reviews(Contact $user, Qrequest $qreq, $gx) {
        $conf = $user->conf;
        if (!$user->privChair
            && !($user->is_reviewer() && $conf->has_any_submitted()))
            return;

        $this->_merit_field = null;
        $all_review_fields = $conf->all_review_fields();
        $merit_field = get($all_review_fields, "overAllMerit");
        if ($merit_field && $merit_field->displayed && $merit_field->main_storage)
            $this->_merit_field = $merit_field;

        // Information about my reviews
        $where = array();
        if ($user->contactId)
            $where[] = "PaperReview.contactId=" . $user->contactId;
        if (($tokens = $user->review_tokens()))
            $where[] = "reviewToken in (" . join(",", $tokens) . ")";
        $this->_my_rinfo = null;
        if (!empty($where)) {
            $rinfo = (object) ["num_submitted" => 0, "num_needs_submit" => 0, "unsubmitted_rounds" => [], "scores" => []];
            $mfs = $this->_merit_field ? $this->_merit_field->main_storage : "null";
            $result = $user->conf->qe("select reviewType, reviewSubmitted, reviewNeedsSubmit, timeApprovalRequested, reviewRound, $mfs from PaperReview join Paper using (paperId) where (" . join(" or ", $where) . ") and (reviewSubmitted is not null or timeSubmitted>0)");
            while (($row = $result->fetch_row())) {
                if ($row[1] || $row[3] < 0) {
                    $rinfo->num_submitted += 1;
                    $rinfo->num_needs_submit += 1;
                    if ($row[5] !== null) {
                        $rinfo->scores[] = $row[5];
                    }
                } else if ($row[2]) {
                    $rinfo->num_needs_submit += 1;
                    $rinfo->unsubmitted_rounds[$row[4]] = true;
                }
            }
            Dbl::free($result);
            $rinfo->unsubmitted_rounds = join(",", array_keys($rinfo->unsubmitted_rounds));
            $rinfo->scores = join(",", $rinfo->scores);
            $rinfo->mean_score = ScoreInfo::mean_of($rinfo->scores, true);
            if ($rinfo->num_submitted > 0 || $rinfo->num_needs_submit > 0) {
                $this->_my_rinfo = $rinfo;
            }
        }

        // Information about PC reviews
        $npc = $sumpcSubmit = $npcScore = $sumpcScore = 0;
        if ($user->isPC || $user->privChair) {
            $result = Dbl::qe_raw("select count(reviewId) num_submitted,
        group_concat(overAllMerit) scores
        from ContactInfo
        left join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.reviewSubmitted is not null)
            where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0
        group by ContactInfo.contactId");
            while (($row = $result->fetch_row())) {
                ++$npc;
                if ($row[0]) {
                    $sumpcSubmit += $row[0];
                    ++$npcScore;
                    $sumpcScore += ScoreInfo::mean_of($row[1], true);
                }
            }
            Dbl::free($result);
        }

        echo '<div class="homegrp" id="homerev">';

        // Overview
        echo $this->render_h2_home("Reviews");
        if ($this->_my_rinfo) {
            echo $conf->_("You have submitted %1\$d of <a href=\"%3\$s\">%2\$d reviews</a> with average %4\$s score %5\$s.",
                $this->_my_rinfo->num_submitted, $this->_my_rinfo->num_needs_submit,
                $conf->hoturl("search", "q=&amp;t=r"),
                $this->_merit_field ? $this->_merit_field->name_html : false,
                $this->_merit_field ? $this->_merit_field->unparse_average($this->_my_rinfo->mean_score) : false),
                "<br>\n";
        }
        if (($user->isPC || $user->privChair) && $npc) {
            echo $conf->_("The average PC member has submitted %.1f reviews with average %s score %s.",
                $sumpcSubmit / $npc,
                $this->_merit_field && $npcScore ? $this->_merit_field->name_html : false,
                $this->_merit_field && $npcScore ? $this->_merit_field->unparse_average($sumpcScore / $npcScore) : false);
            if ($user->isPC || $user->privChair) {
                echo "&nbsp; <small class=\"nw\">(<a href=\"", $conf->hoturl("users", "t=pc&amp;score%5B%5D=0"), "\">details</a><span class=\"barsep\">·</span><a href=\"", $conf->hoturl("graph", "g=procrastination"), "\">graphs</a>)</small>";
            }
            echo "<br>\n";
        }
        if ($this->_my_rinfo
            && $this->_my_rinfo->num_submitted < $this->_my_rinfo->num_needs_submit
            && !$conf->time_review_open()) {
            echo ' <em class="deadline">The site is not open for reviewing.</em><br>', "\n";
        } else if ($this->_my_rinfo
                   && $this->_my_rinfo->num_submitted < $this->_my_rinfo->num_needs_submit) {
            $missing_rounds = explode(",", $this->_my_rinfo->unsubmitted_rounds);
            sort($missing_rounds, SORT_NUMERIC);
            foreach ($missing_rounds as $round) {
                if (($rname = $conf->round_name($round))) {
                    if (strlen($rname) == 1) {
                        $rname = "“{$rname}”";
                    }
                    $rname .= " ";
                }
                if ($conf->time_review($round, $user->isPC, false)) {
                    $dn = $conf->review_deadline($round, $user->isPC, false);
                    $d = $conf->printableTimeSetting($dn, "span");
                    if ($d == "N/A") {
                        $d = $conf->printableTimeSetting($conf->review_deadline($round, $user->isPC, true), "span");
                    }
                    if ($d != "N/A") {
                        echo ' <em class="deadline">Please submit your ', $rname, ($this->_my_rinfo->num_needs_submit == 1 ? "review" : "reviews"), " by $d.</em><br>\n";
                    }
                } else if ($conf->time_review($round, $user->isPC, true)) {
                    echo ' <em class="deadline"><strong class="overdue">', $rname, ($rname ? "reviews" : "Reviews"), ' are overdue.</strong> They were requested by ', $conf->printableTimeSetting($conf->review_deadline($round, $user->isPC, false), "span"), ".</em><br>\n";
                } else {
                    echo ' <em class="deadline"><strong class="overdue">The <a href="', $conf->hoturl("deadlines"), '">deadline</a> for submitting ', $rname, "reviews has passed.</strong></em><br>\n";
                }
            }
        } else if ($user->isPC && $user->can_review_any()) {
            $d = $conf->printableTimeSetting($conf->review_deadline(null, $user->isPC, false), "span");
            if ($d != "N/A") {
                echo " <em class=\"deadline\">The review deadline is $d.</em><br>\n";
            }
        }
        if ($user->isPC && $user->can_review_any()) {
            echo '  <span class="hint">As a PC member, you may review <a href="', $conf->hoturl("search", "q=&amp;t=s"), "\">any submitted paper</a>.</span><br>\n";
        } else if ($user->privChair) {
            echo '  <span class="hint">As an administrator, you may review <a href="', $conf->hoturl("search", "q=&amp;t=s"), "\">any submitted paper</a>.</span><br>\n";
        }

        if ($this->_my_rinfo) {
            echo '<div id="foldre" class="homesubgrp foldo">';
        }

        // Actions
        $sep = "";
        $xsep = ' <span class="barsep">·</span> ';
        if ($this->_my_rinfo) {
            echo $sep, foldupbutton(), "<a href=\"", $conf->hoturl("search", "q=re%3Ame"), "\" title=\"Search in your reviews (more display and download options)\"><strong>Your Reviews</strong></a>";
            $sep = $xsep;
        }
        if ($user->isPC && $user->is_discussion_lead()) {
            echo $sep, '<a href="', $conf->hoturl("search", "q=lead%3Ame"), '" class="nw">Your discussion leads</a>';
            $sep = $xsep;
        }
        if ($conf->deadlinesAfter("rev_open") || $user->privChair) {
            echo $sep, '<a href="', $conf->hoturl("offline"), '">Offline reviewing</a>';
            $sep = $xsep;
        }
        if ($user->isPC && $conf->timePCReviewPreferences()) {
            echo $sep, '<a href="', $conf->hoturl("reviewprefs"), '">Review preferences</a>';
            $sep = $xsep;
        }
        if ($conf->setting("rev_tokens")) {
            echo $sep;
            $this->_in_reviews = true;
            $this->render_review_tokens($user, $qreq, $gx);
            $sep = $xsep;
        }

        if ($this->_my_rinfo && $conf->setting("rev_ratings") != REV_RATINGS_NONE) {
            $badratings = PaperSearch::unusableRatings($user);
            $qx = (count($badratings) ? " and not (PaperReview.reviewId in (" . join(",", $badratings) . "))" : "");
            $result = $conf->qe_raw("select sum((rating&" . ReviewInfo::RATING_GOODMASK . ")!=0), sum((rating&" . ReviewInfo::RATING_BADMASK . ")!=0) from PaperReview join ReviewRating using (reviewId) where PaperReview.contactId={$user->contactId} $qx");
            $row = $result->fetch_row();
            '@phan-var list $row';
            Dbl::free($result);

            $a = [];
            if ($row[0]) {
                $a[] = Ht::link(plural($row[0], "positive rating"), $conf->hoturl("search", "q=re:me+rate:good"));
            }
            if ($row[1]) {
                $a[] = Ht::link(plural($row[1], "negative rating"), $conf->hoturl("search", "q=re:me+rate:bad"));
            }
            if (!empty($a)) {
                echo '<div class="hint g">Your reviews have received ', commajoin($a), '.</div>';
            }
        }

        if ($user->has_review()) {
            $plist = new PaperList("reviewerHome", new PaperSearch($user, ["q" => "re:me"]));
            $plist->set_table_id_class(null, "pltable-reviewerhome");
            $ptext = $plist->table_html(["list" => true]);
            if ($plist->count > 0) {
                echo "<div class=\"fx\"><hr class=\"g\">", $ptext, "</div>";
            }
        }

        if ($this->_my_rinfo) {
            echo "</div>";
        }

        if ($user->is_reviewer()) {
            echo "<div class=\"homesubgrp has-fold fold20c ui-unfold js-open-activity need-fold-storage\" id=\"homeactivity\" data-fold-storage=\"homeactivity\">",
                foldupbutton(20),
                "<a href=\"\" class=\"q homeactivity ui js-foldup\" data-fold-target=\"20\">Recent activity<span class=\"fx20\">:</span></a>",
                "</div>";
            Ht::stash_script("fold_storage()");
        }

        echo "</div>\n";
    }

    // Review token printing
    function render_review_tokens(Contact $user, Qrequest $qreq, $gx) {
        if (!$this->_tokens_done
            && $user->has_email()
            && $user->conf->setting("rev_tokens")
            && (!$this->_in_reviews || $user->is_reviewer())) {
            if (!$this->_in_reviews) {
                echo '<div class="homegrp" id="homerev">',
                    $this->render_h2_home("Reviews");
            }
            $tokens = array_map("encode_token", $user->review_tokens());
            $ttexts = array_map(function ($t) use ($user) {
                return Ht::link($t, $user->conf->hoturl("paper", ["p" => "token:$t"]));
            }, $tokens);
            echo '<a href="" class="ui js-review-tokens" data-review-tokens="',
                join(" ", $tokens), '">Review tokens</a>',
                (empty($tokens) ? "" : " (" . join(", ", $ttexts) . ")");
            if (!$this->_in_reviews) {
                echo '</div>', "\n";
            }
            $this->_tokens_done = true;
        }
    }

    function render_review_requests(Contact $user, Qrequest $qreq, $gx) {
        $conf = $user->conf;
        if (!$user->is_requester()
            && !$user->has_review_pending_approval()
            && !$user->has_proposal_pending())
            return;

        echo '<div class="homegrp">', $this->render_h2_home("Requested Reviews");
        if ($user->has_review_pending_approval()) {
            echo '<a href="', $conf->hoturl("paper", "m=rea&amp;p=has%3Apending-approval"),
                ($user->has_review_pending_approval(true) ? '" class="attention' : ''),
                '">Reviews pending approval</a> <span class="barsep">·</span> ';
        }
        if ($user->has_proposal_pending()) {
            echo '<a href="', $conf->hoturl("assign", "p=has%3Aproposal"),
                '" class="attention">Review proposals</a> <span class="barsep">·</span> ';
        }
        echo '<a href="', $conf->hoturl("mail", "monreq=1"), '">Monitor requested reviews</a></div>', "\n";
    }

    function render_submissions(Contact $user, Qrequest $qreq, $gx) {
        $conf = $user->conf;
        if (!$user->is_author()
            && $conf->timeStartPaper() <= 0
            && !$user->privChair
            && $user->is_reviewer())
            return;

        echo '<div class="homegrp" id="homeau">',
            $this->render_h2_home($user->is_author() ? "Your Submissions" : "Submissions");

        $startable = $conf->timeStartPaper();
        if ($startable && !$user->has_email())
            echo '<em class="deadline">', $conf->printableDeadlineSetting("sub_reg", "span"), "</em><br />\n<small>You must sign in to start a submission.</small>";
        else if ($startable || $user->privChair) {
            echo '<strong><a href="', $conf->hoturl("paper", "p=new"), '">New submission</a></strong> <em class="deadline">(', $conf->printableDeadlineSetting("sub_reg", "span"), ")</em>";
            if ($user->privChair)
                echo '<br><span class="hint">As an administrator, you can start a submission regardless of deadlines and on behalf of others.</span>';
        }

        $plist = null;
        if ($user->is_author()) {
            $plist = new PaperList("authorHome", new PaperSearch($user, ["t" => "a"]));
            $ptext = $plist->table_html(["noheader" => true, "list" => true]);
            if ($plist->count > 0)
                echo '<hr class="g">', $ptext;
        }

        $deadlines = array();
        if ($plist && $plist->has("need_submit")) {
            if (!$conf->timeFinalizePaper()) {
                // Be careful not to refer to a future deadline; perhaps an admin
                // just turned off submissions.
                if ($conf->deadlinesBetween("", "sub_sub", "sub_grace"))
                    $deadlines[] = "The site is not open for submissions at the moment.";
                else
                    $deadlines[] = 'The <a href="' . $conf->hoturl("deadlines") . '">submission deadline</a> has passed.';
            } else if (!$conf->timeUpdatePaper()) {
                $deadlines[] = 'The <a href="' . $conf->hoturl("deadlines") . '">update deadline</a> has passed, but you can still submit.';
                $time = $conf->printableTimeSetting("sub_sub", "span", " to submit papers");
                if ($time != "N/A")
                    $deadlines[] = "You have until $time.";
            } else {
                $time = $conf->printableTimeSetting("sub_update", "span", " to submit papers");
                if ($time != "N/A")
                    $deadlines[] = "You have until $time.";
            }
        }
        if (!$startable && !count($deadlines)) {
            if ($conf->deadlinesAfter("sub_open"))
                $deadlines[] = 'The <a href="' . $conf->hoturl("deadlines") . '">deadline</a> for registering submissions has passed.';
            else
                $deadlines[] = "The site is not open for submissions at the moment.";
        }
        // NB only has("accepted") if author can see an accepted paper
        if ($plist && $plist->has("accepted")) {
            $time = $conf->printableTimeSetting("final_soft");
            if ($conf->deadlinesAfter("final_soft") && $plist->has("need_final"))
                $deadlines[] = "<strong class=\"overdue\">Final versions are overdue.</strong> They were requested by $time.";
            else if ($time != "N/A")
                $deadlines[] = "Submit final versions of your accepted papers by $time.";
        }
        if (!empty($deadlines)) {
            if ($plist && $plist->count > 0)
                echo '<hr class="g">';
            else if ($startable || $user->privChair)
                echo "<br>";
            echo '<em class="deadline">',
                join("</em><br>\n<em class=\"deadline\">", $deadlines),
                "</em>";
        }

        echo "</div>\n";
    }
}
