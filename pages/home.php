<?php
// home.php -- HotCRP home page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

// signin links
// auto-signin when email & password set
if (isset($Qreq->email) && isset($Qreq->password)) {
    $Qreq->action = $Qreq->get("action", "login");
    $Qreq->signin = $Qreq->get("signin", "go");
}
// CSRF protection: ignore unvalidated signin/signout for known users
if (!$Me->is_empty() && !$Qreq->post_ok())
    unset($Qreq->signout);
if ($Me->has_email()
    && (!$Qreq->post_ok() || strcasecmp($Me->email, trim($Qreq->email)) == 0))
    unset($Qreq->signin);
if (!isset($Qreq->email) || !isset($Qreq->action))
    unset($Qreq->signin);
// signout
if (isset($Qreq->signout))
    LoginHelper::logout(true);
else if (isset($Qreq->signin) && !$Conf->opt("httpAuthLogin"))
    LoginHelper::logout(false);
// signin
if ($Conf->opt("httpAuthLogin"))
    LoginHelper::check_http_auth($Qreq);
else if (isset($Qreq->signin))
    LoginHelper::check_login($Qreq);
else if ((isset($Qreq->signin) || isset($Qreq->signout))
         && isset($Qreq->post))
    SelfHref::redirect($Qreq);
else if (isset($Qreq->postlogin))
    LoginHelper::check_postlogin($Qreq);

// disabled users
if (!$Me->is_empty() && $Me->disabled) {
    $Conf->header("Account disabled", "home", ["action_bar" => false]);
    echo Conf::msg_info("Your account on this site has been disabled by an administrator. Please contact the site administrators with questions.");
    echo "<hr class=\"c\" />\n";
    $Conf->footer();
    exit;
}

// perhaps redirect through account
function need_profile_redirect($user) {
    if (!get($user, "firstName") && !get($user, "lastName"))
        return true;
    if ($user->conf->opt("noProfileRedirect"))
        return false;
    if (!$user->affiliation)
        return true;
    if ($user->is_pc_member() && !$user->has_review()
        && (!$user->collaborators
            || ($user->conf->topic_map() && !$user->topic_interest_map())))
        return true;
    return false;
}

if ($Me->has_database_account() && $Conf->session("freshlogin") === true) {
    if (need_profile_redirect($Me)) {
        $Conf->save_session("freshlogin", "redirect");
        go(hoturl("profile", "redirect=1"));
    } else
        $Conf->save_session("freshlogin", null);
}


// review tokens
function change_review_tokens($qreq) {
    global $Conf, $Me;
    $cleared = $Me->change_review_token(false, false);
    $tokeninfo = array();
    foreach (preg_split('/\s+/', $qreq->token) as $x)
        if ($x == "")
            /* no complaints */;
        else if (!($token = decode_token($x, "V")))
            Conf::msg_error("Invalid review token &ldquo;" . htmlspecialchars($x) . "&rdquo;.  Check your typing and try again.");
        else if ($Conf->session("rev_token_fail", 0) >= 5)
            Conf::msg_error("Too many failed attempts to use a review token.  <a href='" . hoturl("index", "signout=1") . "'>Sign out</a> and in to try again.");
        else {
            $result = Dbl::qe("select paperId from PaperReview where reviewToken=" . $token);
            if (($row = edb_row($result))) {
                $tokeninfo[] = "Review token “" . htmlspecialchars($x) . "” lets you review <a href='" . hoturl("paper", "p=$row[0]") . "'>paper #" . $row[0] . "</a>.";
                $Me->change_review_token($token, true);
            } else {
                Conf::msg_error("Review token “" . htmlspecialchars($x) . "” hasn’t been assigned.");
                $nfail = $Conf->session("rev_token_fail", 0) + 1;
                $Conf->save_session("rev_token_fail", $nfail);
            }
        }
    if ($cleared && !count($tokeninfo))
        $tokeninfo[] = "Review tokens cleared.";
    if (count($tokeninfo))
        $Conf->infoMsg(join("<br />\n", $tokeninfo));
    SelfHref::redirect($qreq);
}

if (isset($Qreq->token) && $Qreq->post_ok() && !$Me->is_empty())
    change_review_tokens($Qreq);
if (isset($Qreq->cleartokens) && $Qreq->post_ok())
    $Me->change_review_token(false, false);


if ($Me->privChair)
    require_once("adminhome.php");


$title = ($Me->is_empty() || isset($Qreq->signin) ? "Sign in" : "Home");
$Conf->header($title, "home");
$xsep = " <span class='barsep'>·</span> ";

if ($Me->privChair)
    echo "<div id='clock_drift_container'></div>";


// Sidebar
echo '<div class="homeside">';

echo '<noscript><div class="homeinside"><strong>This site requires JavaScript.</strong> ',
    "Many features will work without JavaScript, but not all.<br />",
    '<a class="small" href="https://github.com/kohler/hotcrp/">Report bad compatibility problems</a></div></noscript>';

// Conference management and information sidebar
$inside_links = [];
if ($Me->is_manager()) {
    $links = array();
    if ($Me->privChair) {
        $links[] = '<a href="' . hoturl("settings") . '">Settings</a>';
        $links[] = '<a href="' . hoturl("users", "t=all") . '">Users</a>';
    }
    $links[] = '<a href="' . hoturl("autoassign") . '">Assignments</a>';
    $links[] = '<a href="' . hoturl("mail") . '">Mail</a>';
    $links[] = '<a href="' . hoturl("log") . '">Action log</a>';
    $inside_links[] = '<h4>Administration</h4><ul style="margin-bottom:0.75em">'
        . '<li>' . join('</li><li>', $links) . '</li></ul>';
}

// Conference info sidebar
$links = [];
$sep = "";
if ($Me->has_reportable_deadline())
    $links[] = '<li><a href="' . hoturl("deadlines") . '">Deadlines</a></li>';
if ($Me->can_view_pc())
    $links[] = '<li><a href="' . hoturl("users", "t=pc") . '">Program committee</a></li>';
if ($Conf->opt("conferenceSite")
    && $Conf->opt("conferenceSite") != $Conf->opt("paperSite"))
    $links[] = '<li><a href="' . $Conf->opt("conferenceSite") . '">Conference site</a></li>';
if ($Conf->can_all_author_view_decision()) {
    list($n, $nyes) = $Conf->count_submitted_accepted();
    $links[] = '<li>' . $Conf->_("%d papers accepted out of %d submitted.", $nyes, $n) . '</li>';
}
if (!empty($links))
    $inside_links[] = '<h4>' . $Conf->_("Conference information") . '</h4><ul>' . join('', $links) . '</ul>';

if (!empty($inside_links))
    echo '<div class="homeinside">', join('', $inside_links), '</div>';

echo "</div>\n\n";
// End sidebar


// Home message
if (($v = $Conf->message_html("home")))
    $Conf->infoMsg($v);


// Sign in
if (!$Me->isPC) {
    echo '<div class="homegrp">
Welcome to the ', htmlspecialchars($Conf->full_name()), " submissions site.";
    if ($Conf->opt("conferenceSite"))
        echo " For general conference information, see <a href=\"", htmlspecialchars($Conf->opt("conferenceSite")), "\">", htmlspecialchars($Conf->opt("conferenceSite")), "</a>.";
    echo '</div>';
}
if (!$Me->has_email() || isset($Qreq->signin)) {
    echo '<div class="homegrp">', $Conf->_("Sign in to submit or review papers."), '</div>';
    $passwordFocus = !Ht::control_class("email") && Ht::control_class("password");
    echo '<hr class="home" />
<div class="homegrp foldo" id="homeacct">',
        Ht::form(hoturl("index", ["post" => post_value(true)])),
        '<div class="f-contain">';
    if ($Conf->opt("contactdb_dsn") && $Conf->opt("contactdb_loginFormHeading"))
        echo $Conf->opt("contactdb_loginFormHeading");
    $password_reset = $Conf->session("password_reset");
    if ($password_reset && $password_reset->time < $Now - 900) {
        $password_reset = null;
        $Conf->save_session("password_reset", null);
    }
    $is_external_login = $Conf->external_login();
    echo '<div class="', Ht::control_class("email", "f-i"), '">',
        Ht::label($is_external_login ? "Username" : "Email", "signin_email"),
        Ht::entry("email", $Qreq->get("email", $password_reset ? $password_reset->email : ""),
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
        '</div><p class="hint">New to the site? <a href="" class="ui js-create-account">Create an account</a></p></div></form></div>
<hr class="home" />';
    Ht::stash_script("focus_within(\$(\"#login\"));window.scroll(0,0)");
}


// Submissions
$papersub = $Conf->has_any_submitted();
$homelist = ($Me->privChair || ($Me->isPC && $papersub) || ($Me->is_reviewer() && $papersub));
$home_hr = "<hr class=\"home\" />\n";
$nhome_hr = 0;

if ($homelist) {
    echo ($nhome_hr ? $home_hr : ""), '<div class="homegrp" id="homelist">';

    // Lists
    echo Ht::form(hoturl("search"), array("method" => "get")),
        '<h4><a class="qq" href="', hoturl("search"), '">Search</a>: &nbsp;&nbsp;</h4>';

    $tOpt = PaperSearch::search_types($Me);
    echo Ht::entry("q", (string) $Qreq->q,
                   array("id" => "homeq", "size" => 32, "title" => "Enter paper numbers or search terms",
                         "class" => "papersearch", "placeholder" => "(All)")),
        " &nbsp;in&nbsp; ",
        PaperSearch::searchTypeSelector($tOpt, key($tOpt), 0), "
    &nbsp; ", Ht::submit("Search"),
        "</form></div>\n";
    ++$nhome_hr;
}


// Review token printing
function reviewTokenGroup($non_reviews) {
    global $Conf, $reviewTokenGroupPrinted;
    if ($reviewTokenGroupPrinted)
        return;

    $tokens = array();
    foreach ($Conf->session("rev_tokens", array()) as $tt)
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
        echo '<hr class="home" /></div>', "\n";
    else
        echo '</td></tr></table>', "\n";
    $reviewTokenGroupPrinted = true;
}


// Review assignment
if ($Me->is_reviewer() && ($Me->privChair || $papersub)) {
    echo ($nhome_hr ? $home_hr : ""), "<div class='homegrp' id='homerev'>\n";
    $all_review_fields = $Conf->all_review_fields();
    $merit_field = get($all_review_fields, "overAllMerit");
    $merit_noptions = $merit_field ? count($merit_field->options) : 0;

    // Information about my reviews
    $where = array();
    if ($Me->contactId)
        $where[] = "PaperReview.contactId=" . $Me->contactId;
    if (($tokens = $Me->review_tokens()))
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
    if ($Me->isPC || $Me->privChair) {
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
    if (($Me->isPC || $Me->privChair) && $npc) {
        echo sprintf(" The average PC member has submitted %.1f reviews", $sumpcSubmit / $npc);
        if ($merit_field && $merit_field->displayed && $npcScore)
            echo " with an average $merit_field->name_html score of ", $merit_field->unparse_average($sumpcScore / $npcScore);
        echo ".";
        if ($Me->isPC || $Me->privChair)
            echo "&nbsp; <small class=\"nw\">(<a href=\"", hoturl("users", "t=pc&amp;score%5B%5D=0"), "\">details</a><span class='barsep'>·</span><a href=\"", hoturl("graph", "g=procrastination"), "\">graphs</a>)</small>";
        echo "<br />\n";
    }
    if ($myrow && $myrow->num_submitted < $myrow->num_needs_submit
        && !$Conf->time_review_open())
        echo ' <span class="deadline">The site is not open for reviewing.</span><br />', "\n";
    else if ($myrow && $myrow->num_submitted < $myrow->num_needs_submit) {
        $missing_rounds = explode(",", $myrow->unsubmitted_rounds);
        sort($missing_rounds, SORT_NUMERIC);
        foreach ($missing_rounds as $round) {
            if (($rname = $Conf->round_name($round))) {
                if (strlen($rname) == 1)
                    $rname = "“{$rname}”";
                $rname .= " ";
            }
            if ($Conf->time_review($round, $Me->isPC, false)) {
                $dn = $Conf->review_deadline($round, $Me->isPC, false);
                $d = $Conf->printableTimeSetting($dn, "span");
                if ($d == "N/A")
                    $d = $Conf->printableTimeSetting($Conf->review_deadline($round, $Me->isPC, true), "span");
                if ($d != "N/A")
                    echo ' <span class="deadline">Please submit your ', $rname, ($myrow->num_needs_submit == 1 ? "review" : "reviews"), " by $d.</span><br />\n";
            } else if ($Conf->time_review($round, $Me->isPC, true))
                echo ' <span class="deadline"><strong class="overdue">', $rname, ($rname ? "reviews" : "Reviews"), ' are overdue.</strong> They were requested by ', $Conf->printableTimeSetting($Conf->review_deadline($round, $Me->isPC, false), "span"), ".</span><br />\n";
            else
                echo ' <span class="deadline"><strong class="overdue">The <a href="', hoturl("deadlines"), '">deadline</a> for submitting ', $rname, "reviews has passed.</strong></span><br />\n";
        }
    } else if ($Me->isPC && $Me->can_review_any()) {
        $d = $Conf->printableTimeSetting($Conf->review_deadline(null, $Me->isPC, false), "span");
        if ($d != "N/A")
            echo " <span class='deadline'>The review deadline is $d.</span><br />\n";
    }
    if ($Me->isPC && $Me->can_review_any())
        echo "  <span class='hint'>As a PC member, you may review <a href='", hoturl("search", "q=&amp;t=s"), "'>any submitted paper</a>.</span><br />\n";
    else if ($Me->privChair)
        echo "  <span class='hint'>As an administrator, you may review <a href='", hoturl("search", "q=&amp;t=s"), "'>any submitted paper</a>.</span><br />\n";

    if ($myrow)
        echo "</div>\n<div id='foldre' class='homegrp foldo'>";

    // Actions
    $sep = "";
    if ($myrow) {
        echo $sep, foldupbutton(), "<a href=\"", hoturl("search", "q=re%3Ame"), "\" title='Search in your reviews (more display and download options)'><strong>Your Reviews</strong></a>";
        $sep = $xsep;
    }
    if ($Me->is_requester() && $Conf->setting("extrev_approve") && $Conf->setting("pcrev_editdelegate")) {
        $search = new PaperSearch($Me, "ext:approvable");
        if ($search->paper_ids()) {
            echo $sep, '<a href="', hoturl("paper", ["m" => "rea", "p" => "ext:approvable"]), '"><strong>Approve external reviews</strong></a>';
            $sep = $xsep;
        }
    }
    if ($Me->isPC && $Conf->has_any_lead_or_shepherd()
        && $Me->is_discussion_lead()) {
        echo $sep, '<a href="', hoturl("search", "q=lead%3Ame"), '" class="nw">Your discussion leads</a>';
        $sep = $xsep;
    }
    if ($Me->isPC && $Conf->timePCReviewPreferences()) {
        echo $sep, '<a href="', hoturl("reviewprefs"), '">Review preferences</a>';
        $sep = $xsep;
    }
    if ($Conf->deadlinesAfter("rev_open") || $Me->privChair) {
        echo $sep, '<a href="', hoturl("offline"), '">Offline reviewing</a>';
        $sep = $xsep;
    }
    if ($Me->is_requester()) {
        echo $sep, '<a href="', hoturl("mail", "monreq=1"), '">Monitor requested reviews</a>';
        $sep = $xsep;
    }
    if ($Conf->setting("rev_tokens")) {
        echo $sep;
        reviewTokenGroup(false);
        $sep = $xsep;
    }

    if ($myrow && $Conf->setting("rev_ratings") != REV_RATINGS_NONE) {
        $badratings = PaperSearch::unusableRatings($Me);
        $qx = (count($badratings) ? " and not (PaperReview.reviewId in (" . join(",", $badratings) . "))" : "");
        $result = $Conf->qe_raw("select sum((rating&" . ReviewInfo::RATING_GOODMASK . ")!=0), sum((rating&" . ReviewInfo::RATING_BADMASK . ")!=0) from PaperReview join ReviewRating using (reviewId) where PaperReview.contactId={$Me->contactId} $qx");
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

    if ($Me->has_review()) {
        $plist = new PaperList(new PaperSearch($Me, ["q" => "re:me"]));
        $plist->set_table_id_class(null, "pltable_reviewerhome");
        $ptext = $plist->table_html("reviewerHome", ["list" => true]);
        if ($plist->count > 0)
            echo "<div class='fx'><div class='g'></div>", $ptext, "</div>";
    }

    if ($Me->is_reviewer()) {
        echo "<div class=\"homegrp has-fold fold20c\" id=\"homeactivity\" data-fold-session=\"foldhomeactivity\">",
            foldupbutton(20),
            "<h4><a href=\"\" class=\"x homeactivity ui js-foldup\" data-fold-target=\"20\">Recent activity<span class='fx20'>:</span></a></h4>",
            "</div>";
        Ht::stash_script('$("#homeactivity").on("fold", function(e,opts) { opts.f || unfold_events(this); })');
        if (!$Conf->session("foldhomeactivity", 1))
            Ht::stash_script("foldup.call(\$(\"#homeactivity\")[0],null,20)");
    }

    echo "</div>\n";
    ++$nhome_hr;
}

// Authored papers
if ($Me->is_author() || $Conf->timeStartPaper() > 0 || $Me->privChair
    || !$Me->is_reviewer()) {
    echo ($nhome_hr ? $home_hr : ""), '<div class="homegrp" id="homeau">';

    // Overview
    if ($Me->is_author())
        echo "<h4>Your Submissions: &nbsp;</h4> ";
    else
        echo "<h4>Submissions: &nbsp;</h4> ";

    $startable = $Conf->timeStartPaper();
    if ($startable && !$Me->has_email())
        echo "<span class='deadline'>", $Conf->printableDeadlineSetting("sub_reg", "span"), "</span><br />\n<small>You must sign in to start a submission.</small>";
    else if ($startable || $Me->privChair) {
        echo "<strong><a href='", hoturl("paper", "p=new"), "'>New submission</a></strong> <span class='deadline'>(", $Conf->printableDeadlineSetting("sub_reg", "span"), ")</span>";
        if ($Me->privChair)
            echo "<br />\n<span class='hint'>As an administrator, you can start a submission regardless of deadlines and on behalf of others.</span>";
    }

    $plist = null;
    if ($Me->is_author()) {
        $plist = new PaperList(new PaperSearch($Me, ["t" => "a"]));
        $ptext = $plist->table_html("authorHome", ["noheader" => true, "list" => true]);
        if ($plist->count > 0)
            echo "<div class='g'></div>\n", $ptext;
    }

    $deadlines = array();
    if ($plist && $plist->has("need_submit")) {
        if (!$Conf->timeFinalizePaper()) {
            // Be careful not to refer to a future deadline; perhaps an admin
            // just turned off submissions.
            if ($Conf->deadlinesBetween("", "sub_sub", "sub_grace"))
                $deadlines[] = "The site is not open for submissions at the moment.";
            else
                $deadlines[] = "The <a href='" . hoturl("deadlines") . "'>submission deadline</a> has passed.";
        } else if (!$Conf->timeUpdatePaper()) {
            $deadlines[] = "The <a href='" . hoturl("deadlines") . "'>update deadline</a> has passed, but you can still submit.";
            $time = $Conf->printableTimeSetting("sub_sub", "span", " to submit papers");
            if ($time != "N/A")
                $deadlines[] = "You have until $time.";
        } else {
            $time = $Conf->printableTimeSetting("sub_update", "span", " to submit papers");
            if ($time != "N/A")
                $deadlines[] = "You have until $time.";
        }
    }
    if (!$startable && !count($deadlines)) {
        if ($Conf->deadlinesAfter("sub_open"))
            $deadlines[] = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for registering submissions has passed.";
        else
            $deadlines[] = "The site is not open for submissions at the moment.";
    }
    // NB only has("accepted") if author can see an accepted paper
    if ($plist && $plist->has("accepted")) {
        $time = $Conf->printableTimeSetting("final_soft");
        if ($Conf->deadlinesAfter("final_soft") && $plist->has("need_final"))
            $deadlines[] = "<strong class='overdue'>Final versions are overdue.</strong> They were requested by $time.";
        else if ($time != "N/A")
            $deadlines[] = "Submit final versions of your accepted papers by $time.";
    }
    if (count($deadlines) > 0) {
        if ($plist && $plist->count > 0)
            echo "<div class='g'></div>";
        else if ($startable || $Me->privChair)
            echo "<br />";
        echo "<span class='deadline'>",
            join("</span><br>\n<span class='deadline'>", $deadlines),
            "</span>";
    }

    echo "</div>\n";
}


// Review tokens
if ($Me->has_email() && $Conf->setting("rev_tokens"))
    reviewTokenGroup(true);


echo "<hr class=\"c\" />\n";
$Conf->footer();
