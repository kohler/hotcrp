<?php

class Home_Partial {
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

    static function render(Contact $user, Qrequest $qreq, $gx) {
        $conf = $user->conf;

        // Home message
        if (($v = $conf->message_html("home")))
            $conf->infoMsg($v);

        // Sign in
        if (!$user->isPC) {
            echo '<div class="homegrp">
        Welcome to the ', htmlspecialchars($conf->full_name()), " submissions site.";
            if ($conf->opt("conferenceSite"))
                echo " For general conference information, see <a href=\"", htmlspecialchars($conf->opt("conferenceSite")), "\">", htmlspecialchars($conf->opt("conferenceSite")), "</a>.";
            echo '</div>';
        }
        if (!$user->has_email() || isset($qreq->signin)) {
            echo '<div class="homegrp">', $conf->_("Sign in to submit or review papers."), '</div>';
            $passwordFocus = !Ht::control_class("email") && Ht::control_class("password");
            echo '<div class="homegrp foldo" id="homeacct">',
                Ht::form(hoturl("index", ["post" => post_value(true)])),
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
                '</div>
        <div class="', Ht::control_class("password", "f-i fx"), '">';
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


        // Submissions
        $papersub = $conf->has_any_submitted();
        $homelist = ($user->privChair || ($user->isPC && $papersub) || ($user->is_reviewer() && $papersub));

        if ($homelist) {
            echo '<div class="homegrp" id="homelist">';

            // Lists
            echo Ht::form(hoturl("search"), array("method" => "get")),
                '<h4><a class="qq" href="', hoturl("search"), '" id="homesearch-label">Search</a>: &nbsp;&nbsp;</h4>';

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


        // Review assignment
        if ($user->is_reviewer() && ($user->privChair || $papersub)) {
            echo "<div class='homegrp' id='homerev'>\n";
            $all_review_fields = $conf->all_review_fields();
            $merit_field = get($all_review_fields, "overAllMerit");
            $merit_noptions = $merit_field ? count($merit_field->options) : 0;

            // Information about my reviews
            $where = array();
            if ($user->contactId)
                $where[] = "PaperReview.contactId=" . $user->contactId;
            if (($tokens = $user->review_tokens()))
                $where[] = "reviewToken in (" . join(",", $tokens) . ")";
            $result = Dbl::qe_raw("select count(reviewSubmitted) num_submitted,
            count(if(reviewNeedsSubmit=0,reviewSubmitted,1)) num_needs_submit,
            group_concat(if(reviewSubmitted is not null,overAllMerit,null)) scores,
            group_concat(distinct if(reviewNeedsSubmit!=0 and reviewSubmitted is null,reviewRound,null)) unsubmitted_rounds
            from PaperReview
            join Paper using (paperId)
            where (" . join(" or ", $where) . ")
            and (reviewSubmitted is not null or timeSubmitted>0)
            group by PaperReview.reviewId>0");
            if (($myrow = edb_orow($result)))
                $myrow->mean_score = ScoreInfo::mean_of($myrow->scores, true);
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

            // Overview
            echo "<h4>Reviews: &nbsp;</h4> ";
            if ($myrow) {
                if ($myrow->num_needs_submit == 1 && $myrow->num_submitted <= 1)
                    echo "You ", ($myrow->num_submitted == 1 ? "have" : "have not"), " submitted your <a href=\"", hoturl("search", "q=&amp;t=r"), "\">review</a>";
                else
                    echo "You have submitted ", $myrow->num_submitted, " of <a href=\"", hoturl("search", "q=&amp;t=r"), "\">", plural($myrow->num_needs_submit, "review"), "</a>";
                if ($merit_field && $merit_field->displayed && $myrow->num_submitted)
                    echo " with an average $merit_field->name_html score of ", $merit_field->unparse_average($myrow->mean_score);
                echo ".<br />\n";
            }
            if (($user->isPC || $user->privChair) && $npc) {
                echo sprintf(" The average PC member has submitted %.1f reviews", $sumpcSubmit / $npc);
                if ($merit_field && $merit_field->displayed && $npcScore)
                    echo " with an average $merit_field->name_html score of ", $merit_field->unparse_average($sumpcScore / $npcScore);
                echo ".";
                if ($user->isPC || $user->privChair)
                    echo "&nbsp; <small class=\"nw\">(<a href=\"", hoturl("users", "t=pc&amp;score%5B%5D=0"), "\">details</a><span class='barsep'>·</span><a href=\"", hoturl("graph", "g=procrastination"), "\">graphs</a>)</small>";
                echo "<br />\n";
            }
            if ($myrow && $myrow->num_submitted < $myrow->num_needs_submit
                && !$conf->time_review_open())
                echo ' <span class="deadline">The site is not open for reviewing.</span><br />', "\n";
            else if ($myrow && $myrow->num_submitted < $myrow->num_needs_submit) {
                $missing_rounds = explode(",", $myrow->unsubmitted_rounds);
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
                            echo ' <span class="deadline">Please submit your ', $rname, ($myrow->num_needs_submit == 1 ? "review" : "reviews"), " by $d.</span><br />\n";
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

            if ($myrow)
                echo "</div>\n<div id='foldre' class='homegrp foldo'>";

            // Actions
            $sep = "";
            $xsep = " <span class='barsep'>·</span> ";
            if ($myrow) {
                echo $sep, foldupbutton(), "<a href=\"", hoturl("search", "q=re%3Ame"), "\" title='Search in your reviews (more display and download options)'><strong>Your Reviews</strong></a>";
                $sep = $xsep;
            }
            if ($user->is_requester() && $conf->setting("extrev_approve") && $conf->setting("pcrev_editdelegate")) {
                $search = new PaperSearch($user, "ext:approvable");
                if ($search->paper_ids()) {
                    echo $sep, '<a href="', hoturl("paper", ["m" => "rea", "p" => "ext:approvable"]), '"><strong>Approve external reviews</strong></a>';
                    $sep = $xsep;
                }
            }
            if ($user->isPC && $conf->has_any_lead_or_shepherd()
                && $user->is_discussion_lead()) {
                echo $sep, '<a href="', hoturl("search", "q=lead%3Ame"), '" class="nw">Your discussion leads</a>';
                $sep = $xsep;
            }
            if ($user->isPC && $conf->timePCReviewPreferences()) {
                echo $sep, '<a href="', hoturl("reviewprefs"), '">Review preferences</a>';
                $sep = $xsep;
            }
            if ($conf->deadlinesAfter("rev_open") || $user->privChair) {
                echo $sep, '<a href="', hoturl("offline"), '">Offline reviewing</a>';
                $sep = $xsep;
            }
            if ($user->is_requester()) {
                echo $sep, '<a href="', hoturl("mail", "monreq=1"), '">Monitor requested reviews</a>';
                $sep = $xsep;
            }
            if ($conf->setting("rev_tokens")) {
                echo $sep;
                self::render_review_tokens($user, $qreq, false);
                $sep = $xsep;
            }

            if ($myrow && $conf->setting("rev_ratings") != REV_RATINGS_NONE) {
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

            if ($user->is_reviewer()) {
                echo "<div class=\"homegrp has-fold fold20c\" id=\"homeactivity\" data-fold-session=\"foldhomeactivity\">",
                    foldupbutton(20),
                    "<h4><a href=\"\" class=\"x homeactivity ui js-foldup\" data-fold-target=\"20\">Recent activity<span class='fx20'>:</span></a></h4>",
                    "</div>";
                Ht::stash_script('$("#homeactivity").on("fold", function(e,opts) { opts.f || unfold_events(this); })');
                if (!$conf->session("foldhomeactivity", 1))
                    Ht::stash_script("foldup.call(\$(\"#homeactivity\")[0],null,20)");
            }

            echo "</div>\n";
        }

        // Authored papers
        if ($user->is_author() || $conf->timeStartPaper() > 0 || $user->privChair
            || !$user->is_reviewer()) {
            echo '<div class="homegrp" id="homeau">';

            // Overview
            if ($user->is_author())
                echo "<h4>Your Submissions: &nbsp;</h4> ";
            else
                echo "<h4>Submissions: &nbsp;</h4> ";

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
            if (count($deadlines) > 0) {
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


        // Review tokens
        if ($user->has_email() && $conf->setting("rev_tokens"))
            self::render_review_tokens($user, $qreq, true);
    }

    // Review token printing
    static function render_review_tokens(Contact $user, Qrequest $qreq, $non_reviews) {
        if ($qreq->attachment("review_tokens_printed"))
            return;

        $tokens = array();
        foreach ($user->conf->session("rev_tokens", array()) as $tt)
            $tokens[] = encode_token((int) $tt);

        if ($non_reviews)
            echo '<div class="homegrp" id="homerev">',
                "<h4>Review tokens: &nbsp;</h4>";
        else
            echo '<table id="foldrevtokens" class="', count($tokens) ? "fold2o" : "fold2c", '" style="display:inline-table">',
                '<tr><td class="fn2"><a href="" class="fn2 ui js-foldup">Add review tokens</a></td>',
                '<td class="fx2">Review tokens: &nbsp;';

        echo Ht::form(hoturl_post("index")),
            Ht::entry("token", join(" ", $tokens), array("size" => max(15, count($tokens) * 8))),
            " &nbsp;", Ht::submit("Save");
        if (empty($tokens))
            echo '<div class="hint">Enter tokens to gain access to the corresponding reviews.</div>';
        echo '</form>';

        if ($non_reviews)
            echo '</div>', "\n";
        else
            echo '</td></tr></table>', "\n";
        $qreq->set_attachment("review_tokens_printed", true);
    }
}
