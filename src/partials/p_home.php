<?php

class Home_Partial {
    private $_in_reviews;
    private $_merit_field;
    private $_my_rinfo;
    private $_pc_rinfo;
    private $_tokens_done;

    function render_head(Contact $user, Qrequest $qreq) {
        echo '<noscript><div class="msg msg-error"><strong>This site requires JavaScript.</strong> Your browser does not support JavaScript.<br><a href="https://github.com/kohler/hotcrp/">Report bad compatibility problems</a></div></noscript>', "\n";
        if ($user->is_empty() || isset($qreq->signin))
            $user->conf->header("Sign in", "home");
        else
            $user->conf->header("Home", "home");
        if ($user->privChair)
            echo '<div id="clock_drift_container"></div>';
    }

    function render_sidebar(Contact $user, Qrequest $qreq, $gx) {
        $conf = $user->conf;
        echo '<div class="homeside">';
        $gx->start_render();
        foreach ($gx->members("home/sidebar/*") as $gj)
            $gx->render($gj, [$user, $qreq, $gx, $gj]);
        $gx->end_render();
        echo "</div>\n";
    }

    function render_admin_sidebar(Contact $user, Qrequest $qreq, $gx) {
        echo '<div class="homeinside"><h4>Administration</h4><ul>';
        $gx->start_render();
        foreach ($gx->members("home/sidebar/admin/*") as $gj)
            $gx->render($gj, [$user, $qreq, $gx, $gj]);
        $gx->end_render();
        echo '</ul></div>';
    }
    function render_admin_settings(Contact $user) {
        echo '<li>', Ht::link("Settings", $user->conf->hoturl("settings")), '</li>';
    }
    function render_admin_users(Contact $user) {
        echo '<li>', Ht::link("Users", $user->conf->hoturl("users", "t=all")), '</li>';
    }
    function render_admin_assignments(Contact $user) {
        echo '<li>', Ht::link("Assignments", $user->conf->hoturl("autoassign")), '</li>';
    }
    function render_admin_mail(Contact $user) {
        echo '<li>', Ht::link("Mail", $user->conf->hoturl("mail")), '</li>';
    }
    function render_admin_log(Contact $user) {
        echo '<li>', Ht::link("Action log", $user->conf->hoturl("log")), '</li>';
    }

    function render_info_sidebar(Contact $user, Qrequest $qreq, $gx) {
        ob_start();
        $gx->start_render();
        foreach ($gx->members("home/sidebar/info/*") as $gj)
            $gx->render($gj, [$user, $qreq, $gx, $gj]);
        $gx->end_render();
        if (($t = ob_get_clean()))
            echo '<div class="homeinside"><h4>',
                $user->conf->_c("home", "Conference information"),
                '</h4><ul>', $t, '</ul></div>';
    }
    function render_info_deadline(Contact $user) {
        if ($user->has_reportable_deadline())
            echo '<li>', Ht::link("Deadlines", $user->conf->hoturl("deadlines")), '</li>';
    }
    function render_info_pc(Contact $user) {
        if ($user->can_view_pc())
            echo '<li>', Ht::link("Program committee", $user->conf->hoturl("users", "t=pc")), '</li>';
    }
    function render_info_site(Contact $user) {
        if (($site = $user->conf->opt("conferenceSite"))
            && $site !== $user->conf->opt("paperSite"))
            echo '<li>', Ht::link("Conference site", $site), '</li>';
    }
    function render_info_accepted(Contact $user) {
        if ($user->conf->can_all_author_view_decision()) {
            list($n, $nyes) = $user->conf->count_submitted_accepted();
            echo '<li>', $user->conf->_("%d papers accepted out of %d submitted.", $nyes, $n), '</li>';
        }
    }

    function render_message(Contact $user) {
        if (($t = $user->conf->message_html("home")))
            $user->conf->infoMsg($t);
    }

    function render_welcome(Contact $user) {
        echo '<div class="homegrp">Welcome to the ', htmlspecialchars($user->conf->full_name()), " submissions site.";
        if (($site = $user->conf->opt("conferenceSite"))
            && $site !== $user->conf->opt("paperSite"))
            echo " For general conference information, see ", Ht::link(htmlspecialchars($site), htmlspecialchars($site)), ".";
        echo '</div>';
    }

    function render_signin(Contact $user, Qrequest $qreq) {
        if ($user->has_email() && !isset($qreq->signin))
            return;

        $conf = $user->conf;
        echo '<div class="homegrp">', $conf->_("Sign in to submit or review papers."), '</div>';
        $passwordFocus = !Ht::control_class("email") && Ht::control_class("password");
        echo '<div class="homegrp foldo" id="homeacct">',
            Ht::form($conf->hoturl("index", ["post" => post_value(true)])),
            '<div class="f-contain">';
        if ($conf->opt("contactdb_dsn") && $conf->opt("contactdb_loginFormHeading"))
            echo $conf->opt("contactdb_loginFormHeading");
        $password_reset = $conf->session("password_reset");
        if ($password_reset && $password_reset->time < $Now - 900) {
            $password_reset = null;
            $conf->save_session("password_reset", null);
        }
        $is_external_login = $conf->external_login();
        echo '<div class="', Ht::control_class("email", "f-i"), '">',
            Ht::label($is_external_login ? "Username" : "Email", "signin_email"),
            Ht::entry("email", $qreq->get("email", $password_reset ? $password_reset->email : ""),
                      ["size" => 36, "id" => "signin_email", "class" => "fullw", "autocomplete" => "username", "tabindex" => 1, "type" => $is_external_login ? "text" : "email"]),
            '</div><div class="', Ht::control_class("password", "f-i fx"), '">';
        if (!$is_external_login)
            echo '<div class="floatright"><a href="" class="n x small ui js-forgot-password">Forgot your password?</a></div>';
        echo Ht::label("Password", "signin_password"),
            Ht::password("password", "",
                         ["size" => 36, "id" => "signin_password", "class" => "fullw", "autocomplete" => "current-password", "tabindex" => 1]),
            "</div>\n";
        if ($password_reset)
            echo Ht::unstash_script("jQuery(function(){jQuery(\"#signin_password\").val(" . json_encode_browser($password_reset->password) . ")})");
        if ($is_external_login)
            echo Ht::hidden("action", "login");
        echo '<div class="popup-actions">',
            Ht::submit("signin", "Sign in", ["id" => "signin_signin", "class" => "btn btn-primary"]),
            '</div><p class="hint">New to the site? <a href="" class="ui js-create-account">Create an account</a></p></div></form></div>';
        Ht::stash_script("focus_within(\$(\"#login\"));window.scroll(0,0)");
    }

    function render_search(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        if (!$user->privChair
            && ((!$conf->has_any_submitted()
                 && !($user->isPC && $conf->setting("pc_seeall")))
                || !$user->is_reviewer()))
            return;

        echo '<div class="homegrp" id="homelist">',
            Ht::form($conf->hoturl("search"), ["method" => "get"]),
            '<h2 class="home"><a class="qq" href="', $conf->hoturl("search"), '" id="homesearch-label">Search</a></h2>';

        $tOpt = PaperSearch::search_types($user);
        echo Ht::entry("q", (string) $qreq->q,
                       array("id" => "homeq", "size" => 32, "title" => "Enter paper numbers or search terms",
                             "class" => "papersearch", "placeholder" => "(All)",
                             "aria-labelledby" => "homesearch-label")),
            " &nbsp;in&nbsp; ",
            PaperSearch::searchTypeSelector($tOpt, key($tOpt), 0), "
        &nbsp; ", Ht::submit("Search"),
            "</form></div>\n";
    }

    function render_reviews(Contact $user, Qrequest $qreq) {
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
        $q = "count(reviewSubmitted) num_submitted,
            count(if(reviewNeedsSubmit=0,reviewSubmitted,1)) num_needs_submit,
            group_concat(distinct if(reviewNeedsSubmit!=0 and reviewSubmitted is null,reviewRound,null)) unsubmitted_rounds";
        if ($this->_merit_field)
            $q .= ", group_concat(if(reviewSubmitted is not null,{$this->_merit_field->main_storage},null)) scores";
        else
            $q .= ", '' scores";
        $result = $user->conf->qe("select $q from PaperReview join Paper using (paperId) where (" . join(" or ", $where) . ") and (reviewSubmitted is not null or timeSubmitted>0) group by PaperReview.reviewId>0");
        if (($this->_my_rinfo = $result->fetch_object()))
            $this->_my_rinfo->mean_score = ScoreInfo::mean_of($this->_my_rinfo->scores, true);
        Dbl::free($result);

        // Information about PC reviews
        $npc = $sumpcSubmit = $npcScore = $sumpcScore = 0;
        if ($user->isPC || $user->privChair) {
            $result = Dbl::qe_raw("select count(reviewId) num_submitted,
        group_concat(overAllMerit) scores
        from ContactInfo
        left join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.reviewSubmitted is not null)
            where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0
        group by ContactInfo.contactId");
            while (($row = edb_row($result))) {
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
        echo "<h2 class=\"home\">Reviews</h2> ";
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
                $this->_merit_field ? $this->_merit_field->name_html : false,
                $this->_merit_field ? $this->_merit_field->unparse_average($sumpcScore / $npcScore) : false);
            if ($user->isPC || $user->privChair)
                echo "&nbsp; <small class=\"nw\">(<a href=\"", hoturl("users", "t=pc&amp;score%5B%5D=0"), "\">details</a><span class='barsep'>·</span><a href=\"", hoturl("graph", "g=procrastination"), "\">graphs</a>)</small>";
            echo "<br />\n";
        }
        if ($this->_my_rinfo
            && $this->_my_rinfo->num_submitted < $this->_my_rinfo->num_needs_submit
            && !$conf->time_review_open())
            echo ' <span class="deadline">The site is not open for reviewing.</span><br />', "\n";
        else if ($this->_my_rinfo
                 && $this->_my_rinfo->num_submitted < $this->_my_rinfo->num_needs_submit) {
            $missing_rounds = explode(",", $this->_my_rinfo->unsubmitted_rounds);
            sort($missing_rounds, SORT_NUMERIC);
            foreach ($missing_rounds as $round) {
                if (($rname = $conf->round_name($round))) {
                    if (strlen($rname) == 1)
                        $rname = "“{$rname}”";
                    $rname .= " ";
                }
                if ($conf->time_review($round, $user->isPC, false)) {
                    $dn = $conf->review_deadline($round, $user->isPC, false);
                    $d = $conf->printableTimeSetting($dn, "span");
                    if ($d == "N/A")
                        $d = $conf->printableTimeSetting($conf->review_deadline($round, $user->isPC, true), "span");
                    if ($d != "N/A")
                        echo ' <span class="deadline">Please submit your ', $rname, ($this->_my_rinfo->num_needs_submit == 1 ? "review" : "reviews"), " by $d.</span><br />\n";
                } else if ($conf->time_review($round, $user->isPC, true))
                    echo ' <span class="deadline"><strong class="overdue">', $rname, ($rname ? "reviews" : "Reviews"), ' are overdue.</strong> They were requested by ', $conf->printableTimeSetting($conf->review_deadline($round, $user->isPC, false), "span"), ".</span><br />\n";
                else
                    echo ' <span class="deadline"><strong class="overdue">The <a href="', hoturl("deadlines"), '">deadline</a> for submitting ', $rname, "reviews has passed.</strong></span><br />\n";
            }
        } else if ($user->isPC && $user->can_review_any()) {
            $d = $conf->printableTimeSetting($conf->review_deadline(null, $user->isPC, false), "span");
            if ($d != "N/A")
                echo " <span class='deadline'>The review deadline is $d.</span><br />\n";
        }
        if ($user->isPC && $user->can_review_any())
            echo "  <span class='hint'>As a PC member, you may review <a href='", hoturl("search", "q=&amp;t=s"), "'>any submitted paper</a>.</span><br />\n";
        else if ($user->privChair)
            echo "  <span class='hint'>As an administrator, you may review <a href='", hoturl("search", "q=&amp;t=s"), "'>any submitted paper</a>.</span><br />\n";

        if ($this->_my_rinfo)
            echo '<div id="foldre" class="homesubgrp foldo">';

        // Actions
        $sep = "";
        $xsep = " <span class='barsep'>·</span> ";
        if ($this->_my_rinfo) {
            echo $sep, foldupbutton(), "<a href=\"", hoturl("search", "q=re%3Ame"), "\" title='Search in your reviews (more display and download options)'><strong>Your Reviews</strong></a>";
            $sep = $xsep;
        }
        if ($user->isPC && $user->is_discussion_lead()) {
            echo $sep, '<a href="', hoturl("search", "q=lead%3Ame"), '" class="nw">Your discussion leads</a>';
            $sep = $xsep;
        }
        if ($conf->deadlinesAfter("rev_open") || $user->privChair) {
            echo $sep, '<a href="', hoturl("offline"), '">Offline reviewing</a>';
            $sep = $xsep;
        }
        if ($user->isPC && $conf->timePCReviewPreferences()) {
            echo $sep, '<a href="', hoturl("reviewprefs"), '">Review preferences</a>';
            $sep = $xsep;
        }
        if ($conf->setting("rev_tokens")) {
            echo $sep;
            $this->render_review_tokens($user, $qreq);
            $sep = $xsep;
        }

        if ($this->_my_rinfo && $conf->setting("rev_ratings") != REV_RATINGS_NONE) {
            $badratings = PaperSearch::unusableRatings($user);
            $qx = (count($badratings) ? " and not (PaperReview.reviewId in (" . join(",", $badratings) . "))" : "");
            $result = $conf->qe_raw("select sum((rating&" . ReviewInfo::RATING_GOODMASK . ")!=0), sum((rating&" . ReviewInfo::RATING_BADMASK . ")!=0) from PaperReview join ReviewRating using (reviewId) where PaperReview.contactId={$user->contactId} $qx");
            $row = edb_row($result);
            Dbl::free($result);

            $a = [];
            if ($row[0])
                $a[] = Ht::link(plural($row[0], "positive rating"), hoturl("search", "q=re:me+rate:good"));
            if ($row[1])
                $a[] = Ht::link(plural($row[1], "negative rating"), hoturl("search", "q=re:me+rate:bad"));
            if (!empty($a))
                echo '<div class="hint g">Your reviews have received ', commajoin($a), '.</div>';
        }

        if ($user->has_review()) {
            $plist = new PaperList(new PaperSearch($user, ["q" => "re:me"]));
            $plist->set_table_id_class(null, "pltable_reviewerhome");
            $ptext = $plist->table_html("reviewerHome", ["list" => true]);
            if ($plist->count > 0)
                echo "<div class='fx'><div class='g'></div>", $ptext, "</div>";
        }

        if ($this->_my_rinfo)
            echo "</div>";

        if ($user->is_reviewer()) {
            echo "<div class=\"homesubgrp has-fold fold20c\" id=\"homeactivity\" data-fold-session=\"foldhomeactivity\">",
                foldupbutton(20),
                "<a href=\"\" class=\"q homeactivity ui js-foldup\" data-fold-target=\"20\">Recent activity<span class='fx20'>:</span></a>",
                "</div>";
            Ht::stash_script('$("#homeactivity").on("fold", function(e,opts) { opts.f || unfold_events(this); })');
            if (!$conf->session("foldhomeactivity", 1))
                Ht::stash_script("foldup.call(\$(\"#homeactivity\")[0],null,20)");
        }

        echo "</div>\n";
    }

    // Review token printing
    function render_review_tokens(Contact $user, Qrequest $qreq) {
        if ($this->_tokens_done
            || !$user->has_email()
            || !$user->conf->setting("rev_tokens")
            || (!$this->_in_reviews && !$user->is_reviewer()))
            return;

        $tokens = [];
        foreach ($user->conf->session("rev_tokens", []) as $tt)
            $tokens[] = encode_token((int) $tt);

        if ($this->_in_reviews)
            echo '<div class="homegrp" id="homerev">',
                "<h2 class=\"home\">Review tokens</h2>";
        else
            echo '<table id="foldrevtokens" class="fold2', empty($tokens) ? "c" : "o", '" style="display:inline-table">',
                '<tr><td class="fn2"><a href="" class="fn2 ui js-foldup">Add review tokens</a></td>',
                '<td class="fx2">Review tokens: &nbsp;';

        echo Ht::form($user->conf->hoturl_post("index")),
            Ht::entry("token", join(" ", $tokens), ["size" => max(15, count($tokens) * 8)]),
            " &nbsp;", Ht::submit("Save");
        if (empty($tokens))
            echo '<div class="hint">Enter tokens to gain access to the corresponding reviews.</div>';
        echo '</form>';

        if ($this->_in_reviews)
            echo '</div>', "\n";
        else
            echo '</td></tr></table>', "\n";
        $this->_tokens_done = true;
    }

    function render_review_requests(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        if (!$user->privChair
            && !($user->is_reviewer() && $conf->has_any_submitted())
            && !$user->is_requester())
            return;

        echo '<div class="homegrp">';
        echo "<h2 class=\"home\">Requested Reviews</h2> ";
        if ($conf->setting("extrev_approve")
            && $conf->setting("pcrev_editdelegate")) {
            $search = new PaperSearch($user, "ext:approvable");
            if ($search->paper_ids()) {
                echo '<a href="', hoturl("paper", ["m" => "rea", "p" => "ext:approvable"]), '"><strong>Approve external reviews</strong></a> <span class="barsep">·</span> ';
            }
        }
        echo '<a href="', hoturl("mail", "monreq=1"), '">Monitor requested reviews</a></div>', "\n";
    }

    static function render_submissions(Contact $user, Qrequest $qreq, $gx) {
        $conf = $user->conf;
        if (!$user->is_author()
            && $conf->timeStartPaper() <= 0
            && $user->privChair
            && $user->is_reviewer())
            return;

        echo '<div class="homegrp" id="homeau">';
        if ($user->is_author())
            echo "<h2 class=\"home\">Your Submissions</h2> ";
        else
            echo "<h2 class=\"home\">Submissions</h2> ";

        $startable = $conf->timeStartPaper();
        if ($startable && !$user->has_email())
            echo "<span class='deadline'>", $conf->printableDeadlineSetting("sub_reg", "span"), "</span><br />\n<small>You must sign in to start a submission.</small>";
        else if ($startable || $user->privChair) {
            echo "<strong><a href='", hoturl("paper", "p=new"), "'>New submission</a></strong> <span class='deadline'>(", $conf->printableDeadlineSetting("sub_reg", "span"), ")</span>";
            if ($user->privChair)
                echo "<br />\n<span class='hint'>As an administrator, you can start a submission regardless of deadlines and on behalf of others.</span>";
        }

        $plist = null;
        if ($user->is_author()) {
            $plist = new PaperList(new PaperSearch($user, ["t" => "a"]));
            $ptext = $plist->table_html("authorHome", ["noheader" => true, "list" => true]);
            if ($plist->count > 0)
                echo "<div class='g'></div>\n", $ptext;
        }

        $deadlines = array();
        if ($plist && $plist->has("need_submit")) {
            if (!$conf->timeFinalizePaper()) {
                // Be careful not to refer to a future deadline; perhaps an admin
                // just turned off submissions.
                if ($conf->deadlinesBetween("", "sub_sub", "sub_grace"))
                    $deadlines[] = "The site is not open for submissions at the moment.";
                else
                    $deadlines[] = "The <a href='" . hoturl("deadlines") . "'>submission deadline</a> has passed.";
            } else if (!$conf->timeUpdatePaper()) {
                $deadlines[] = "The <a href='" . hoturl("deadlines") . "'>update deadline</a> has passed, but you can still submit.";
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
                $deadlines[] = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for registering submissions has passed.";
            else
                $deadlines[] = "The site is not open for submissions at the moment.";
        }
        // NB only has("accepted") if author can see an accepted paper
        if ($plist && $plist->has("accepted")) {
            $time = $conf->printableTimeSetting("final_soft");
            if ($conf->deadlinesAfter("final_soft") && $plist->has("need_final"))
                $deadlines[] = "<strong class='overdue'>Final versions are overdue.</strong> They were requested by $time.";
            else if ($time != "N/A")
                $deadlines[] = "Submit final versions of your accepted papers by $time.";
        }
        if (!empty($deadlines)) {
            if ($plist && $plist->count > 0)
                echo "<div class='g'></div>";
            else if ($startable || $user->privChair)
                echo "<br />";
            echo "<span class='deadline'>",
                join("</span><br>\n<span class='deadline'>", $deadlines),
                "</span>";
        }

        echo "</div>\n";
    }
}
