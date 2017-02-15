<?php
// home.php -- HotCRP home page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");

$email_class = "";
$password_class = "";

// signin links
// auto-signin when email & password set
if (isset($_REQUEST["email"]) && isset($_REQUEST["password"])) {
    $_REQUEST["action"] = get($_REQUEST, "action", "login");
    $_REQUEST["signin"] = get($_REQUEST, "signin", "go");
}
// CSRF protection: ignore unvalidated signin/signout for known users
if (!$Me->is_empty() && !check_post())
    unset($_REQUEST["signout"]);
if ($Me->has_email()
    && (!check_post() || strcasecmp($Me->email, trim(req("email"))) == 0))
    unset($_REQUEST["signin"]);
if (!isset($_REQUEST["email"]) || !isset($_REQUEST["action"]))
    unset($_REQUEST["signin"]);
// signout
if (isset($_REQUEST["signout"]))
    LoginHelper::logout(true);
else if (isset($_REQUEST["signin"]) && !opt("httpAuthLogin"))
    LoginHelper::logout(false);
// signin
if (opt("httpAuthLogin"))
    LoginHelper::check_http_auth();
else if (isset($_REQUEST["signin"]))
    LoginHelper::check_login();
else if ((isset($_REQUEST["signin"]) || isset($_REQUEST["signout"]))
         && isset($_REQUEST["post"]))
    redirectSelf();

// set a session variable to test that their browser supports cookies
// NB need to do this whenever we'll send a "testsession=1" param
if ($Me->is_empty() || isset($_REQUEST["signin"]))
    $_SESSION["testsession"] = true;

// disabled users
if (!$Me->is_empty() && $Me->disabled) {
    $Conf->header("Account disabled", "home", false);
    echo Conf::msg_info("Your account on this site has been disabled by an administrator. Please contact the site administrators with questions.");
    echo "<hr class=\"c\" />\n";
    $Conf->footer();
    exit;
}

// perhaps redirect through account
function need_profile_redirect($user) {
    global $Conf;
    if (!get($user, "firstName") && !get($user, "lastName"))
        return true;
    if (opt("noProfileRedirect"))
        return false;
    if (!$user->affiliation)
        return true;
    if ($user->is_pc_member() && !$user->has_review()
        && (!$user->collaborators
            || ($Conf->topic_map() && !$user->topic_interest_map())))
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
function change_review_tokens() {
    global $Conf, $Me;
    $cleared = $Me->change_review_token(false, false);
    $tokeninfo = array();
    foreach (preg_split('/\s+/', $_REQUEST["token"]) as $x)
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
    redirectSelf();
}

if (isset($_REQUEST["token"]) && check_post() && !$Me->is_empty())
    change_review_tokens();
if (isset($_REQUEST["cleartokens"]))
    $Me->change_review_token(false, false);


if ($Me->privChair)
    require_once("adminhome.php");


$title = ($Me->is_empty() || isset($_REQUEST["signin"]) ? "Sign in" : "Home");
$Conf->header($title, "home", actionBar());
$xsep = " <span class='barsep'>·</span> ";

if ($Me->privChair)
    echo "<div id='clock_drift_container'></div>";


// Sidebar
echo '<div class="homeside">';

echo '<noscript><div class="homeinside"><strong>This site requires JavaScript.</strong> ',
    "Many features will work without JavaScript, but not all.<br />",
    '<a style="font-size:smaller" href="https://github.com/kohler/hotcrp/">Report bad compatibility problems</a></div></noscript>';

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
    if ($Me->privChair)
        $links[] = '<a href="' . hoturl("log") . '">Action log</a>';
    $inside_links[] = '<h4>Administration</h4><ul style="margin-bottom:0.75em">'
        . '<li>' . join('</li><li>', $links) . '</li></ul>';
}

// Conference info sidebar
$links = [];
$sep = "";
if ($Me->has_reportable_deadline())
    $links[] = '<li><a href="' . hoturl("deadlines") . '">Deadlines</a></li>';
if ($Me->isPC || !opt("privatePC"))
    $links[] = '<li><a href="' . hoturl("users", "t=pc") . '">Program committee</a></li>';
if (opt("conferenceSite") && opt("conferenceSite") != opt("paperSite"))
    $links[] = '<li><a href="' . opt("conferenceSite") . '">Conference site</a></li>';
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
    if (opt("conferenceSite"))
        echo " For general conference information, see <a href=\"", htmlspecialchars(opt("conferenceSite")), "\">", htmlspecialchars(opt("conferenceSite")), "</a>.";
    echo '</div>';
}
if (!$Me->has_email() || isset($_REQUEST["signin"])) {
    echo '<div class="homegrp">', $Conf->_("Sign in to submit or review papers."), '</div>';
    $passwordFocus = ($email_class == "" && $password_class != "");
    echo '<hr class="home" />
<div class="homegrp foldo" id="homeacct">',
        Ht::form(hoturl_post("index")),
        '<div class="f-contain">';
    if ($Me->is_empty() || isset($_REQUEST["signin"]))
        echo Ht::hidden("testsession", 1);
    if (opt("contactdb_dsn") && opt("contactdb_loginFormHeading"))
        echo opt("contactdb_loginFormHeading");
    $password_reset = $Conf->session("password_reset");
    if ($password_reset && $password_reset->time < $Now - 900) {
        $password_reset = null;
        $Conf->save_session("password_reset", null);
    }
    echo '<div class="f-ii">
  <div class="f-c', $email_class, '">',
        (opt("ldapLogin") ? "Username" : "Email"),
        '</div>
  <div class="f-e', $email_class, '">',
        Ht::entry("email", (isset($_REQUEST["email"]) ? $_REQUEST["email"] : ($password_reset ? $password_reset->email : "")),
                  ["size" => 36, "tabindex" => 1, "id" => "signin_email"]),
        '</div>
</div>
<div class="f-i fx">
  <div class="f-c', $password_class, '">Password</div>
  <div class="f-e">',
        Ht::password("password", "",
                     array("size" => 36, "tabindex" => 1, "id" => "signin_password")),
        "</div>\n</div>\n";
    if ($password_reset)
        echo Ht::unstash_script("jQuery(function(){jQuery(\"#signin_password\").val(" . json_encode($password_reset->password) . ")})");
    if (opt("ldapLogin"))
        echo Ht::hidden("action", "login");
    else {
        echo "<div class='f-i'>\n  ",
            Ht::radio("action", "login", true, array("tabindex" => 2, "id" => "signin_action_login")),
            "&nbsp;", Ht::label("<b>Sign me in</b>"), "<br />\n";
        echo Ht::radio("action", "forgot", false, array("tabindex" => 2)),
            "&nbsp;", Ht::label("I forgot my password"), "<br />\n";
        echo Ht::radio("action", "new", false, array("tabindex" => 2)),
            "&nbsp;", Ht::label("I’m a new user and want to create an account");
        echo "\n</div>\n";
        Ht::stash_script("function login_type() {
    var act = jQuery(\"#homeacct input[name=action]:checked\")[0] || jQuery(\"#signin_action_login\")[0];
    fold(\"homeacct\", act.value != \"login\");
    var felt = act.value != \"login\" || !jQuery(\"#signin_email\").val().length;
    jQuery(\"#signin_\" + (felt ? \"email\" : \"password\"))[0].focus();
    jQuery(\"#signin_signin\")[0].value = {\"login\":\"Sign in\",\"forgot\":\"Reset password\",\"new\":\"Create account\"}[act.value];
}
jQuery(\"#homeacct input[name='action']\").on('click',login_type);jQuery(login_type)");
    }
    echo "<div class='f-i'>",
        Ht::submit("signin", "Sign in", array("tabindex" => 1, "id" => "signin_signin")),
        "</div></div></form>
<hr class='home' /></div>\n";
    Ht::stash_script("crpfocus(\"login\", null, 2)");
}


// Submissions
$papersub = $Conf->setting("papersub");
$homelist = ($Me->privChair || ($Me->isPC && $papersub) || ($Me->is_reviewer() && $papersub));
$home_hr = "<hr class=\"home\" />\n";
$nhome_hr = 0;

if ($homelist) {
    echo ($nhome_hr ? $home_hr : ""), '<div class="homegrp" id="homelist">';

    // Lists
    echo Ht::form_div(hoturl("search"), array("method" => "get")),
        '<h4><a class="qq" href="', hoturl("search"), '">Search</a>: &nbsp;&nbsp;</h4>';

    $tOpt = PaperSearch::search_types($Me);
    echo Ht::entry("q", req("q"),
                   array("id" => "homeq", "size" => 32, "title" => "Enter paper numbers or search terms",
                         "class" => "hotcrp_searchbox", "placeholder" => "(All)")),
        " &nbsp;in&nbsp; ",
        PaperSearch::searchTypeSelector($tOpt, key($tOpt), 0), "
    &nbsp; ", Ht::submit("Search"),
        "</div></form></div>\n";
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
            '<tr><td class="fn2"><a class="fn2" href="#" onclick="return foldup(this,event)">Add review tokens</a></td>',
            '<td class="fx2">Review tokens: &nbsp;';

    echo Ht::form_div(hoturl_post("index")),
        Ht::entry("token", join(" ", $tokens), array("size" => max(15, count($tokens) * 8))),
        " &nbsp;", Ht::submit("Save");
    if (!count($tokens))
        echo '<div class="hint">Enter tokens to gain access to the corresponding reviews.</div>';
    echo '</div></form>';

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
        $myrow->scores = scoreCounts($myrow->scores, $merit_noptions);

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
                $scores = scoreCounts($row[1], $merit_noptions);
                ++$npcScore;
                $sumpcScore += $scores->avg;
            }
        }
    }

    // Overview
    echo "<h4>Reviews: &nbsp;</h4> ";
    if ($myrow) {
        if ($myrow->num_needs_submit == 1 && $myrow->num_submitted <= 1)
            echo "You ", ($myrow->num_submitted == 1 ? "have" : "have not"), " submitted your <a href=\"", hoturl("search", "q=&amp;t=r"), "\">review</a>";
        else
            echo "You have submitted ", $myrow->num_submitted, " of <a href=\"", hoturl("search", "q=&amp;t=r"), "\">", plural($myrow->num_needs_submit, "review"), "</a>";
        if ($merit_field && $merit_field->displayed && $myrow->num_submitted)
            echo " with an average $merit_field->name_html score of ", $merit_field->unparse_average($myrow->scores->avg);
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
            if (($rname = $Conf->round_name($round)))
                $rname .= " ";
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
        echo $sep, foldbutton("re"), "<a href=\"", hoturl("search", "q=re%3Ame"), "\" title='Search in your reviews (more display and download options)'><strong>Your Reviews</strong></a>";
        $sep = $xsep;
    }
    if ($Me->is_requester() && $Conf->setting("extrev_approve") && $Conf->setting("pcrev_editdelegate")) {
        $search = new PaperSearch($Me, "ext:approvable");
        if ($search->paperList()) {
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
        $result = Dbl::qe_raw("select rating, count(PaperReview.reviewId) from PaperReview join ReviewRating on (PaperReview.contactId=$Me->contactId and PaperReview.reviewId=ReviewRating.reviewId$qx) group by rating order by rating desc");
        if (edb_nrows($result)) {
            $a = array();
            while (($row = edb_row($result)))
                if (isset(ReviewForm::$rating_types[$row[0]]))
                    $a[] = "<a href=\"" . hoturl("search", "q=re:me+rate:%22" . urlencode(ReviewForm::$rating_types[$row[0]]) . "%22") . "\" title='List rated reviews'>$row[1] &ldquo;" . htmlspecialchars(ReviewForm::$rating_types[$row[0]]) . "&rdquo; " . pluralx($row[1], "rating") . "</a>";
            if (count($a) > 0) {
                echo "<div class='hint g'>\nYour reviews have received ",
                    commajoin($a);
                if (count($a) > 1)
                    echo " (these sets might overlap)";
                echo ".<a class='help' href='", hoturl("help", "t=revrate"), "' title='About ratings'>?</a></div>\n";
            }
        }
    }

    if ($Me->has_review()) {
        $plist = new PaperList(new PaperSearch($Me, ["q" => "re:me"]));
        $ptext = $plist->table_html("reviewerHome", ["list" => true]);
        if ($plist->count > 0)
            echo "<div class='fx'><div class='g'></div>", $ptext, "</div>";
    }

    if ($Me->is_reviewer()) {
        echo "<div class=\"homegrp fold20c\" id=\"homeactivity\" data-fold=\"true\" data-fold-session=\"foldhomeactivity\" data-onunfold=\"unfold_events(this)\">",
            foldbutton("homeactivity", 20),
            "<h4><a href=\"#\" onclick=\"return foldup(this,event,{n:20})\" class=\"x homeactivity\">Recent activity<span class='fx20'>:</span></a></h4>",
            "</div>";
        if (!$Conf->session("foldhomeactivity", 1))
            Ht::stash_script("foldup(jQuery(\"#homeactivity\")[0],null,{n:20})");
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
            join("</span><br />\n<span class='deadline'>", $deadlines),
            "</span>";
    }

    echo "</div>\n";
}


// Review tokens
if ($Me->has_email() && $Conf->setting("rev_tokens"))
    reviewTokenGroup(true);


echo "<hr class=\"c\" />\n";
$Conf->footer();
