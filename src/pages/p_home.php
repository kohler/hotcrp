<?php
// pages/p_home.php -- HotCRP home page
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class Home_Page {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var int */
    private $_nh2 = 0;
    /** @var bool */
    private $_has_sidebar = false;
    private $_in_reviews;
    /** @var ?list<ReviewField> */
    private $_rfs;
    /** @var int */
    private $_r_num_submitted = 0;
    /** @var int */
    private $_r_num_needs_submit = 0;
    /** @var list<int> */
    private $_r_unsubmitted_rounds;
    /** @var list<int|float> */
    private $_rf_means;
    private $_tokens_done;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    static function disabled_request(Contact $user, Qrequest $qreq) {
        if (!$user->is_empty() && $user->is_disabled()) {
            Multiconference::fail_user_disabled($user, $qreq);
        }
    }

    static function reviewtokenreport_request(Contact $user, Qrequest $qreq) {
        if (!$user->is_empty() && $qreq->reviewtokenreport) {
            $ml = [];
            if (!$user->review_tokens()) {
                $ml[] = MessageItem::success("<0>Review tokens cleared");
            } else {
                $result = $user->conf->qe("select reviewToken, paperId from PaperReview where reviewToken?a order by paperId", $user->review_tokens());
                while (($row = $result->fetch_row())) {
                    $ml[] = MessageItem::success("<5>Review token ‘" . htmlspecialchars(encode_token((int) $row[0])) . "’ lets you review " . Ht::link("{$user->conf->snouns[0]} #{$row[1]}", $user->conf->hoturl("paper", "p={$row[1]}")));
                }
            }
            $user->conf->feedback_msg($ml);
        }
    }

    static function profile_redirect_request(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        if (!$user->is_empty() && $qreq->postlogin) {
            LoginHelper::check_postlogin($user, $qreq);
        }
        if (!$user->has_account_here()) {
            return;
        }
        if (self::profilecheck($user, $gx)) {
            $qreq->unset_csession("freshlogin");
        } else if ($qreq->csession("freshlogin") === true) {
            $qreq->set_csession("freshlogin", "redirect");
            $user->conf->redirect_hoturl("profile", "redirect=1");
        } else {
            $user->conf->feedback_msg([MessageItem::warning("<5>Please " . Ht::link("complete your profile", $user->conf->hoturl("profile")))]);
        }
    }

    /** @param Contact $user
     * @param ComponentSet $gx */
    static function profilecheck($user, $gx) {
        $gx->enter()->set_context_args($user);
        $v = true;
        foreach ($gx->members("__profilecheck") as $gj) {
            if (!($v = $gx->call_function($gj, $gj->function, $gj)))
                break;
        }
        $gx->leave();
        return $v;
    }

    function print_head(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        $qreq->print_header("Home", "home", ["body_class" => "body-home"]);
        if ($qreq->signedout && $user->is_empty()) {
            $user->conf->success_msg("<0>You have been signed out of the site");
        }
        $gx->print_on_leave("__footer");
        echo '<noscript><div class="msg msg-error"><strong>This site requires JavaScript.</strong> Your browser does not support JavaScript.<br><a href="https://github.com/kohler/hotcrp/">Report bad compatibility problems</a></div></noscript>', "\n";
        if ($user->privChair) {
            echo '<div id="p-clock-drift" class="homegrp hidden"></div>';
        }
    }

    function print_content(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        echo '<div class="home-content">';
        ob_start();
        $gx->print_members("home/sidebar");
        if (($t = ob_get_clean()) !== "") {
            echo '<nav class="home-sidebar">', $t, '</nav>';
            $this->_has_sidebar = true;
        }
        echo '<div class="home-main">';
        $gx->print_members("home/main");
        echo "</div></div>\n";
    }

    private function print_h2_home($x) {
        ++$this->_nh2;
        return "<h2 class=\"home\">" . $x . "</h2>";
    }

    static function print_admin_sidebar(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        echo '<div class="homegrp"><h2 class="home">Administration</h2><ul>';
        $gx->print_members("home/sidebar/admin");
        echo '</ul></div>';
    }
    static function print_admin_settings(Contact $user) {
        echo '<li>', Ht::link("Settings", $user->conf->hoturl("settings")), '</li>';
    }
    static function print_admin_users(Contact $user) {
        $t = $user->privChair ? "all" : "re";
        echo '<li>', Ht::link("Users", $user->conf->hoturl("users", ["t" => $t])), '</li>';
    }
    static function print_admin_assignments(Contact $user) {
        echo '<li>', Ht::link("Assignments", $user->conf->hoturl("autoassign")), '</li>';
    }
    static function print_admin_mail(Contact $user) {
        echo '<li>', Ht::link("Mail", $user->conf->hoturl("mail")), '</li>';
    }
    static function print_admin_log(Contact $user) {
        echo '<li>', Ht::link("Action log", $user->conf->hoturl("log")), '</li>';
    }

    static function print_info_sidebar(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        ob_start();
        $gx->print_members("home/sidebar/info");
        if (($t = ob_get_clean())) {
            echo '<div class="homegrp"><h2 class="home">',
                $user->conf->_c("home", "Conference information"),
                '</h2><ul>', $t, '</ul></div>';
        }
    }
    static function print_info_deadline(Contact $user) {
        if ($user->has_reportable_deadline()) {
            echo '<li>', Ht::link("Deadlines", $user->conf->hoturl("deadlines")), '</li>';
        }
    }
    static function print_info_pc(Contact $user) {
        if ($user->can_view_pc()) {
            echo '<li>', Ht::link("Program committee", $user->conf->hoturl("users", "t=pc")), '</li>';
        }
    }
    static function print_info_site(Contact $user) {
        if (($site = $user->conf->opt("conferenceSite"))
            && $site !== $user->conf->opt("paperSite")) {
            echo '<li>', Ht::link("Conference site", $site), '</li>';
        }
    }
    static function print_info_accepted(Contact $user) {
        assert($user->conf->time_all_author_view_decision());
        if ($user->conf->time_all_author_view_decision()) {
            list($n, $nyes) = $user->conf->count_submitted_accepted();
            echo '<li>', $user->conf->_("{naccepted} of {nsubmitted} {submissions} accepted", new FmtArg("naccepted", $nyes), new FmtArg("nsubmitted", $n)), '</li>';
        }
    }
    static function print_info_help(Contact $user) {
        if ($user->isPC) {
            echo '<li class="mt-2">', Ht::link("?⃝ <u>Help</u>", $user->conf->hoturl("help"), ["class" => "noul"]), '</li>';
        }
    }

    function print_message() {
        if (($t = $this->conf->_i("home"))) {
            echo '<div class="msg ',
                $this->_has_sidebar ? 'avoid-home-sidebar' : 'maxw-auto',
                ' mb-5">', $t, '</div>';
        }
    }

    function print_welcome(Contact $user) {
        if ($user->isPC && $user->conf->has_any_submitted()) {
            return;
        }
        echo '<div class="homegrp"><p>Welcome to the ', htmlspecialchars($this->conf->full_name()), " submissions site.";
        if (($site = $this->conf->opt("conferenceSite"))
            && $site !== $this->conf->opt("paperSite"))
            echo " For general information, see ", Ht::link(htmlspecialchars($site), htmlspecialchars($site)), ".";
        echo '</p></div>';
    }

    function print_signin(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        if (!$user->has_email() || $qreq->signin) {
            Signin_Page::print_signin_form($user, $qreq, $gx);
        }
    }

    function print_search(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        if (!$user->privChair
            && ($user->isPC
                ? !$this->conf->setting("pc_seeall") && !$this->conf->has_any_submitted()
                : !$user->is_reviewer())) {
            return;
        }

        $limits = PaperSearch::viewable_limits($user);
        echo '<div class="homegrp d-table" id="homelist">',
            $this->print_h2_home('<a class="q" href="' . $this->conf->hoturl("search") . '" id="homesearch-label">Search</a>'),
            Ht::form($this->conf->hoturl("search"), ["method" => "get", "class" => "form-basic-search", "role" => "search"]),
            Ht::entry("q", (string) $qreq->q, [
                "id" => "homeq", "size" => 32,
                "title" => "Enter paper numbers or search terms",
                "class" => "papersearch need-suggest flex-grow-1 mb-1",
                "placeholder" => "(All)",
                "aria-labelledby" => "homesearch-label",
                "spellcheck" => false, "autocomplete" => "off"
            ]), '<div class="form-basic-search-in"> in ',
            PaperSearch::limit_selector($this->conf, $limits, PaperSearch::default_limit($user, $limits)),
            Ht::submit("Search", ["class" => "basic-search"]),
            "</div></form>";

        if ($user->isPC) {
            $hs = [];
            foreach ($user->conf->named_searches() as $sj) {
                if (($sj->display ?? null) === "highlight"
                    && $user->can_view_named_search($sj, false)) {
                    $tw = strpos($sj->name, "~");
                    $name = $tw > 0 ? substr($sj->name, $tw) : $sj->name;
                    $hs[] = '<li><span class="mr-1">⭐️</span>' . Ht::link("ss:" . htmlspecialchars($name), $this->conf->hoturl("search", ["q" => "ss:{$name}"])) . '</li>';
                }
            }
            if (!empty($hs)) {
                echo '<div class="mt-1 font-weight-semibold"><ul class="inline">',
                    join("", $hs), '</ul></div>';
            }
        }

        echo '</div>';
    }

    /** @return list<Score_ReviewField> */
    private function default_review_fields() {
        $this->_rfs = $this->_rfs ?? $this->conf->review_form()->highlighted_main_scores();
        return $this->_rfs;
    }

    /** @param string $setting
     * @return ?string */
    private function setting_time_span($setting) {
        $t = $this->conf->setting($setting) ?? 0;
        return $t > 0 ? $this->conf->unparse_time_with_local_span($t) : null;
    }

    function print_reviews(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        $conf = $user->conf;
        if (!$user->privChair
            && (!$user->is_reviewer() || !$conf->has_any_submitted())) {
            return;
        }

        // which review fields to show?

        // Information about my reviews
        $where = [];
        if ($user->contactId) {
            $where[] = "PaperReview.contactId=" . $user->contactId;
        }
        if (($tokens = $user->review_tokens())) {
            $where[] = "reviewToken in (" . join(",", $tokens) . ")";
        }
        if (!empty($where)) {
            $rfs = $this->default_review_fields();
            $q = "select reviewType, reviewSubmitted, reviewNeedsSubmit, timeApprovalRequested, reviewRound";
            $missing_rounds = $scores = [];
            foreach ($rfs as $rf) {
                $q .= ", " . $rf->main_storage;
                $scores[] = [];
            }
            $result = $user->conf->qe("{$q} from PaperReview join Paper using (paperId) where (" . join(" or ", $where) . ") and (reviewSubmitted is not null or timeSubmitted>0)");
            while (($row = $result->fetch_row())) {
                if ($row[1] || $row[3] < 0) {
                    $this->_r_num_submitted += 1;
                    $this->_r_num_needs_submit += 1;
                    for ($i = 0; $i !== count($rfs); ++$i) {
                        if ($row[5 + $i] !== null)
                            $scores[$i][] = (int) $row[5 + $i];
                    }
                } else if ($row[2]) {
                    $this->_r_num_needs_submit += 1;
                    $missing_rounds[(int) $row[4]] = true;
                }
            }
            Dbl::free($result);
            $this->_r_unsubmitted_rounds = array_keys($missing_rounds);
            $this->_rf_means = [];
            foreach ($scores as $sarr) {
                $this->_rf_means[] = ScoreInfo::mean_of($sarr, true);
            }
        }
        $has_rinfo = $this->_r_num_needs_submit > 0;

        // Information about PC reviews
        $npc = $sumpc_submit = $npc_submit = 0;
        $pc_rf_means = [];
        if ($user->isPC || $user->privChair) {
            $rfs = $this->default_review_fields();
            $q = "select count(reviewId) num_submitted";
            $scores = [];
            foreach ($rfs as $rf) {
                $q .= ", group_concat(coalesce({$rf->main_storage},'')) {$rf->short_id}Scores";
                $scores[] = [];
            }
            $result = Dbl::qe_raw("{$q} from ContactInfo left join PaperReview on (PaperReview.contactId=ContactInfo.contactId and PaperReview.reviewSubmitted is not null)
                where roles!=0 and (roles&" . Contact::ROLE_PC . ")!=0 group by ContactInfo.contactId");
            while (($row = $result->fetch_row())) {
                ++$npc;
                if ($row[0]) {
                    $npc_submit += 1;
                    $sumpc_submit += (int) $row[0];
                    for ($i = 0; $i !== count($rfs); ++$i) {
                        $scores[$i][] = ScoreInfo::mean_of($row[1 + $i], true);
                    }
                }
            }
            Dbl::free($result);
            foreach ($scores as $sarr) {
                $pc_rf_means[] = ScoreInfo::mean_of($sarr, true);
            }
        }

        echo '<div class="homegrp" id="homerev">';

        // Overview
        echo $this->print_h2_home("Reviews");
        if ($has_rinfo) {
            $score_texts = [];
            foreach ($this->default_review_fields() as $i => $rf) {
                if ($this->_rf_means[$i] !== null) {
                    $score_texts[] = $conf->_("average {0} score {1}", $rf->name_html, $rf->unparse_computed($this->_rf_means[$i]), $this->_r_num_submitted);
                }
            }
            echo $conf->_("You have submitted {n} of <a href=\"{url}\">{na} reviews</a> with {scores:list}.",
                new FmtArg("n", $this->_r_num_submitted), new FmtArg("na", $this->_r_num_needs_submit),
                new FmtArg("url", $conf->hoturl_raw("search", "q=&t=r"), 0),
                new FmtArg("scores", $score_texts)),
                "<br>\n";
        }
        if (($user->isPC || $user->privChair) && $npc) {
            $score_texts = [];
            foreach ($this->default_review_fields() as $i => $rf) {
                if ($pc_rf_means[$i] !== null) {
                    $score_texts[] = $conf->_("average {0} score {1}", $rf->name_html, $rf->unparse_computed($pc_rf_means[$i]), null);
                }
            }
            echo $conf->_("The average PC member has submitted {n:.1f} reviews with {scores:list}.",
                new FmtArg("n", $sumpc_submit / $npc), new FmtArg("scores", $score_texts));
            if ($user->isPC || $user->privChair) {
                echo "&nbsp; <small class=\"nw\">(<a href=\"", $conf->hoturl("users", "t=pc"), "\">details</a><span class=\"barsep\">·</span><a href=\"", $conf->hoturl("graph", "group=procrastination"), "\">graphs</a>)</small>";
            }
            echo "<br>\n";
        }
        if ($this->_r_num_submitted < $this->_r_num_needs_submit
            && !$conf->time_review_open()) {
            echo ' <em class="deadline">Reviewing is currently closed.</em><br>', "\n";
        } else if ($this->_r_num_submitted < $this->_r_num_needs_submit) {
            sort($this->_r_unsubmitted_rounds, SORT_NUMERIC);
            foreach ($this->_r_unsubmitted_rounds as $round) {
                if (($rname = $conf->round_name($round))) {
                    if (strlen($rname) == 1) {
                        $rname = "“{$rname}”";
                    }
                    $rname .= " ";
                }
                if ($conf->time_review($round, $user->isPC, false)) {
                    $dn = $conf->review_deadline_name($round, $user->isPC, false);
                    if ($conf->setting($dn) <= 0) {
                        $dn = $conf->review_deadline_name($round, $user->isPC, true);
                    }
                    if (($d = $this->setting_time_span($dn))) {
                        echo ' <em class="deadline">Please submit your ', $rname, ($this->_r_num_needs_submit == 1 ? "review" : "reviews"), " by {$d}.</em><br>\n";
                    }
                } else if ($conf->time_review($round, $user->isPC, true)) {
                    $dn = $conf->review_deadline_name($round, $user->isPC, false);
                    $d = $this->setting_time_span($dn);
                    echo ' <em class="deadline"><strong class="overdue">', $rname, ($rname ? "reviews" : "Reviews"), ' are overdue.</strong> They were requested by ', $d, ".</em><br>\n";
                } else {
                    echo ' <em class="deadline"><strong class="overdue">The <a href="', $conf->hoturl("deadlines"), '">deadline</a> for submitting ', $rname, "reviews has passed.</strong></em><br>\n";
                }
            }
        } else if ($user->isPC && $user->can_review_any()) {
            $dn = $conf->review_deadline_name(null, $user->isPC, false);
            if (($d = $this->setting_time_span($dn))) {
                echo " <em class=\"deadline\">The review deadline is {$d}.</em><br>\n";
            }
        }
        if ($user->isPC && $user->can_review_any()) {
            echo '  <span class="hint">As a PC member, you may review <a href="', $conf->hoturl("search", "q=&amp;t=s"), "\">any submitted paper</a>.</span><br>\n";
        } else if ($user->privChair) {
            echo '  <span class="hint">As an administrator, you may review <a href="', $conf->hoturl("search", "q=&amp;t=s"), "\">any submitted paper</a>.</span><br>\n";
        }

        if ($has_rinfo) {
            echo '<div id="foldre" class="homesubgrp foldo">';
        }

        // Actions
        $sep = "";
        $xsep = ' <span class="barsep">·</span> ';
        if ($has_rinfo) {
            echo $sep, foldupbutton(), "<a href=\"", $conf->hoturl("search", "q=re%3Ame"), "\" title=\"Search in your reviews (more display and download options)\"><strong>Your Reviews</strong></a>";
            $sep = $xsep;
        }
        if ($user->isPC && $user->is_discussion_lead()) {
            echo $sep, '<a href="', $conf->hoturl("search", "q=lead%3Ame"), '" class="nw">Your discussion leads</a>';
            $sep = $xsep;
        }
        if ($conf->time_review_open() || $user->privChair) {
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
            $this->print_review_tokens($user, $qreq, $gx);
            $sep = $xsep;
        }

        if ($has_rinfo && $conf->review_ratings_visible() >= 0) {
            $badratings = self::unusable_ratings($user);
            $qx = (count($badratings) ? " and not (PaperReview.reviewId in (" . join(",", $badratings) . "))" : "");
            $result = $conf->qe_raw("select sum((rating&" . ReviewInfo::RATING_GOODMASK . ")!=0), sum((rating&" . ReviewInfo::RATING_BADMASK . ")!=0) from PaperReview join ReviewRating using (reviewId) where PaperReview.contactId={$user->contactId}{$qx}");
            $row = $result->fetch_row();
            '@phan-var list $row';
            Dbl::free($result);

            $a = [];
            if ($row[0]) {
                $a[] = Ht::link(plural($row[0], "positive rating"), $conf->hoturl("search", "q=rate:good:me"));
            }
            if ($row[1]) {
                $a[] = Ht::link(plural($row[1], "negative rating"), $conf->hoturl("search", "q=rate:bad:me"));
            }
            if (!empty($a)) {
                echo '<div class="hint g">Your reviews have received ', commajoin($a), '.</div>';
            }
        }

        if ($user->has_review()) {
            $plist = new PaperList("reviewerHome", new PaperSearch($user, ["q" => "re:me"]));
            $plist->set_table_id_class(null, "pltable-reviewerhome");
            if (!$plist->is_empty()) {
                echo '<div class="fx"><hr class="g">';
                $plist->set_table_decor(PaperList::DECOR_HEADER | PaperList::DECOR_LIST);
                $plist->print_table_html();
                echo '</div>';
            }
        }

        if ($has_rinfo) {
            echo "</div>";
        }

        if ($user->is_reviewer()) {
            echo "<div class=\"homesubgrp collapsed ui-fold js-open-activity need-fold-storage\" id=\"homeactivity\" data-fold-storage=\"homeactivity\">",
                '<button type="button" class="q ui js-foldup" aria-expanded="false" aria-controls="homeactivity-body">',
                aria_expander(), 'Recent activity<span class="ifx">:</span></button>',
                '<div id="homeactivity-body" class="has-events" hidden></div></div>';
            Ht::stash_script("hotcrp.fold_storage()");
        }

        echo "</div>\n";
    }

    static function unusable_ratings(Contact $user) {
        $rseer = $user->conf->review_ratings_visible();
        assert($rseer >= 0);
        if ($user->privChair
            || $user->conf->setting("viewrev") === Conf::VIEWREV_ALWAYS
            || $rseer > 0) {
            return [];
        }
        // This query should return those reviewIds whose ratings
        // are not visible to the current querier:
        // reviews by `$user` on papers with <=2 reviews and <=2 ratings
        if ($user->conf->review_ratings() === 0) {
            $npr_constraint = "reviewType>" . REVIEW_EXTERNAL;
        } else {
            $npr_constraint = "true";
        }
        $reviewMeta = REVIEW_META;
        $result = $user->conf->qe("select r.reviewId,
            coalesce((select count(*) from ReviewRating force index (primary) where paperId=r.paperId),0) numRatings,
            coalesce((select sum(if(reviewType={$reviewMeta},3,1)) from PaperReview force index (primary) where paperId=r.paperId and (reviewType={$reviewMeta} or (reviewNeedsSubmit=0 and {$npr_constraint}))),0) numReviews
            from PaperReview r
            join ReviewRating rr on (rr.paperId=r.paperId and rr.reviewId=r.reviewId)
            where r.contactId={$user->contactId} and r.reviewType!={$reviewMeta}
            having numReviews<=2 and numRatings<=2");
        return Dbl::fetch_first_columns($result);
    }

    // Review token printing
    function print_review_tokens(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        if (!$this->_tokens_done
            && $user->has_email()
            && $user->conf->setting("rev_tokens")
            && (!$this->_in_reviews || $user->is_reviewer())) {
            if (!$this->_in_reviews) {
                echo '<div class="homegrp" id="homerev">',
                    $this->print_h2_home("Reviews");
            }
            $tokens = array_map("encode_token", $user->review_tokens());
            $ttexts = array_map(function ($t) use ($user) {
                return Ht::link($t, $user->conf->hoturl("paper", ["q" => "token:$t"]));
            }, $tokens);
            echo '<button type="button" class="link ui js-review-tokens" data-review-tokens="',
                join(" ", $tokens), '">Review tokens</button>',
                (empty($tokens) ? "" : " (" . join(", ", $ttexts) . ")");
            if (!$this->_in_reviews) {
                echo '</div>', "\n";
            }
            $this->_tokens_done = true;
        }
    }

    function print_review_requests(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        $conf = $user->conf;
        if (!$user->is_requester()
            && !$user->has_review_pending_approval()
            && !$user->has_proposal_pending()) {
            return;
        }

        echo '<div class="homegrp">', $this->print_h2_home("Requested Reviews");
        if ($user->has_review_pending_approval()) {
            echo '<a href="', $conf->hoturl("paper", "m=rea&amp;q=re%3Apending-my-approval"),
                ($user->has_review_pending_approval(true) ? '" class="attention' : ''),
                '">Reviews pending approval</a> <span class="barsep">·</span> ';
        }
        if ($user->has_proposal_pending()) {
            echo '<a href="', $conf->hoturl("assign", "q=re%3Aproposal"),
                '" class="attention">Review proposals</a> <span class="barsep">·</span> ';
        }
        echo '<a href="', $conf->hoturl("mail", "monreq=1"), '">Monitor requested reviews</a></div>', "\n";
    }

    private function print_new_submission(Contact $user, SubmissionRound $sr) {
        $conf = $user->conf;
        if ($sr->register >= Conf::$now && $sr->register < $sr->submit) {
            $dname = $conf->_5("<5>{sclass} registration deadline", new FmtArg("sclass", $sr->label, 0));
            $dtime = $conf->unparse_time_with_local_span($sr->register);
            $dltx = "<em class=\"deadline\">{$dname}: {$dtime}</em>";
        } else if ($sr->submit > 0) {
            $dname = $conf->_5("<5>{sclass} deadline", new FmtArg("sclass", $sr->label, 0));
            $dtime = $conf->unparse_time_with_local_span($sr->submit);
            $dltx = "<em class=\"deadline\">{$dname}: {$dtime}</em>";
        } else {
            $dltx = "";
        }
        if ($user->has_email()) {
            $url = $conf->hoturl("paper", [
                "p" => "new", "sclass" => $sr->unnamed ? null : $sr->tag
            ]);
            $actions = [[
                "<a class=\"btn\" href=\"{$url}\">" . $conf->_c5("paper_edit", "<0>New {sclass} {submission}", new FmtArg("sclass", $sr->label, 0)) . "</a>",
                $sr->time_register(true) ? "" : "(admin only)"
            ]];
            if ($dltx !== "") {
                $actions[] = [$dltx];
            }
            echo Ht::actions($actions, ["class" => "aab mt-0 mb-2 align-items-baseline"]);
        } else if ($dltx !== "") {
            echo '<p class="mb-2">', $dltx, '</p>';
        }
    }

    /** @param int $srf */
    private function submission_round_deadlines(&$deadlines, SubmissionRound $sr, $srf) {
        $conf = $this->conf;
        $dlurl = $conf->hoturl("deadlines");
        if (($srf & 4) !== 0
            && $sr->final_open
            && ($dl = $sr->final_deadline_for_display()) > 0) {
            $d = $conf->unparse_time_with_local_span($dl);
            if (!$sr->time_edit_final(true)) {
                $deadlines[] = "The <a href=\"{$dlurl}\">deadline</a> for submitting {$sr->prefix}final versions has passed.";
            } else if ($d <= Conf::$now) {
                if ($d === $sr->final_done) {
                    $dx = " They were required by {$d}.";
                } else if ($sr->final_done > 0) {
                    $dx = " They were requested by {$d} and are required by " . $conf->unparse_time_with_local_span($sr->final_done) . ".";
                } else {
                    $dx = " They were requested by {$d}.";
                }
                $final = $sr->prefix === "" ? "Final" : "final";
                $deadlines[] = "<strong class=\"overdue\">{$sr->prefix}{$final} versions are overdue.</strong> {$dx}";
            } else {
                $deadlines[] = "Submit final versions of your accepted {$sr->prefix}{$conf->snouns[1]} by {$d}.";
            }
        }
        if (($srf & 3) === 0) {
            return;
        }
        if (!$sr->time_open()) {
            $deadlines[] = "The site is currently closed for {$sr->prefix}{$conf->snouns[1]}.";
            return;
        }
        if (($srf & 2) !== 0
            && $sr->time_edit(true, true)) {
            if ($sr->time_edit(false, true)) {
                $d = $conf->unparse_time_with_local_span($sr->update);
                $deadlines[] = "You have until {$d} to update {$sr->prefix}{$conf->snouns[1]}.";
            } else {
                $d = $conf->unparse_time_with_local_span($sr->resubmit);
                $deadlines[] = "You have until {$d} to update completed {$sr->prefix}{$conf->snouns[1]}.";
            }
        } else if (($srf & 2) !== 0) {
            $deadlines[] = "The <a href=\"{$dlurl}\">deadline</a> to update {$sr->prefix}{$conf->snouns[1]} has passed.";
            if ($sr->time_submit(true)) {
                $d = $conf->unparse_time_with_local_span($sr->submit);
                $deadlines[] = "You have until {$d} to mark {$sr->prefix}{$conf->snouns[1]} as completed.";
            }
        } else if (($srf & 1) !== 0
                   && $sr->time_edit(false, true)) {
            $d = $conf->unparse_time_with_local_span($sr->update);
            $deadlines[] = "You have until {$d} to complete {$sr->prefix}{$conf->snouns[1]}.";
        } else if (($srf & 1) !== 0) {
            $deadlines[] = "The <a href=\"{$dlurl}\">deadline</a> to complete {$sr->prefix}{$conf->snouns[1]} has passed.";
        }
    }

    private function submission_deadlines_for(PaperInfoSet $rowset, Contact $user) {
        $srinfo = [];
        foreach ($rowset as $prow) {
            if ($prow->timeWithdrawn > 0) {
                continue;
            }
            $sr = $prow->submission_round();
            $srinfo[$sr->tag] = $srinfo[$sr->tag] ?? 0;
            if ($prow->timeSubmitted > 0) {
                $srinfo[$sr->tag] |= 1;
            } else {
                $srinfo[$sr->tag] |= 2;
            }
            if ($prow->outcome > 0
                && $prow->viewable_phase($user) === PaperInfo::PHASE_FINAL) {
                $srinfo[$sr->tag] |= 4;
            }
        }
        if (empty($srinfo)) {
            return;
        }
        $dl = [];
        foreach ($this->conf->submission_round_list() as $sr) {
            if (($srf = $srinfo[$sr->tag] ?? 0) !== 0)
                $this->submission_round_deadlines($dl, $sr, $srf);
        }
        return $dl;
    }

    function print_submissions(Contact $user, Qrequest $qreq, ComponentSet $gx) {
        $conf = $user->conf;
        $srlist = [];
        $any_open = false;
        $sl = $conf->site_lock("paper:start");
        if ($sl <= 0 || ($sl === 1 && $user->is_manager())) {
            foreach ($conf->submission_round_list() as $sr) {
                $any_open = $any_open || $sr->open > 0;
                if ($user->privChair || $sr->time_register(true)) {
                    $srlist[] = $sr;
                }
            }
        }
        if (!$user->is_author()
            && !$user->privChair
            && $user->is_reviewer()
            && empty($srlist)) {
            return;
        }

        if ($user->is_author()) {
            $t = $conf->_c("home", "<0>Your {Submissions}");
        } else {
            $t = $conf->_c("home", "<0>{Submissions}");
        }
        echo '<div class="homegrp" id="homeau">', $this->print_h2_home(Ftext::as(5, $t));

        if (!empty($srlist)) {
            if (!$user->has_email()) {
                echo "<p>", Ht::link("Sign in", $conf->hoturl("signin")),
                    " to manage {$conf->snouns[1]}.</p>";
            }
            usort($srlist, "SubmissionRound::compare");
            foreach ($srlist as $sr) {
                $this->print_new_submission($user, $sr);
            }
        }

        $plist = null;
        if ($user->is_author()) {
            $plist = new PaperList("authorHome", new PaperSearch($user, ["t" => "a"]));
            if (!$plist->is_empty()) {
                $plist->set_table_decor(PaperList::DECOR_LIST);
                $plist->print_table_html();
            }
        }

        $deadlines = [];
        if ($plist && !$plist->is_empty()) {
            $deadlines = $this->submission_deadlines_for($plist->rowset(), $user);
        }
        if (empty($srlist) && empty($deadlines)) {
            if ($any_open) {
                $deadlines[] = "The <a href=\"" . $conf->hoturl("deadlines") . "\">deadline</a> for registering {$conf->snouns[1]} has passed.";
            } else {
                $deadlines[] = "{$conf->snouns[3]} are currently closed.";
            }
        }
        if (!empty($deadlines)) {
            if ($plist && !$plist->is_empty()) {
                echo '<hr class="g">';
            }
            echo '<p>', join("<br>\n", $deadlines), "</p>";
        }

        echo "</div>\n";
    }
}
