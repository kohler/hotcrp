<?php
// index.php -- HotCRP home page
// HotCRP is Copyright (c) 2006-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
if (Navigation::page() !== "index") {
    if (is_readable(Navigation::page() . ".php")
        /* The following is paranoia (currently can't happen): */
        && strpos(Navigation::page(), "/") === false) {
        include(Navigation::page() . ".php");
        exit;
    } else
        go(hoturl("index"));
}

require_once("src/papersearch.php");

$email_class = "";
$password_class = "";

// signin links
if (isset($_REQUEST["email"]) && isset($_REQUEST["password"])
    && ($Me->is_empty() || check_post())) {
    if ($Me->email === $_REQUEST["email"])
        unset($_REQUEST["email"], $_REQUEST["password"]);
    else {
        $_REQUEST["action"] = defval($_REQUEST, "action", "login");
        $_REQUEST["signin"] = defval($_REQUEST, "signin", "go");
    }
}

if ((isset($_REQUEST["email"]) && isset($_REQUEST["password"])
     && isset($_REQUEST["signin"]) && !isset($Opt["httpAuthLogin"]))
    || (isset($_REQUEST["signout"]) && check_post()))
    LoginHelper::logout();

if (isset($Opt["httpAuthLogin"]))
    LoginHelper::check_http_auth();
else if (isset($_REQUEST["email"])
         && isset($_REQUEST["action"])
         && isset($_REQUEST["signin"]))
    LoginHelper::check_login();
else if ((isset($_REQUEST["signin"]) || isset($_REQUEST["signout"]))
         && isset($_REQUEST["post"]))
    redirectSelf();

// set a session variable to test that their browser supports cookies
// NB need to do this whenever we'll send a "testsession=1" param
if ($Me->is_empty() || isset($_REQUEST["signin"]))
    $_SESSION["testsession"] = true;

// perhaps redirect through account
if ($Me->has_database_account() && $Conf->session("freshlogin") === true) {
    $needti = false;
    if ($Me->is_pc_member() && !$Me->has_review()) {
        $result = $Conf->q("select count(ta.topicId), count(ti.topicId) from TopicArea ta left join TopicInterest ti on (ti.contactId=$Me->contactId and ti.topicId=ta.topicId)");
        $needti = ($row = edb_row($result)) && $row[0] && !$row[1];
    }
    if (!($Me->firstName || $Me->lastName)
        || !$Me->affiliation
        || ($Me->is_pc_member() && !$Me->collaborators)
        || $needti) {
        $Conf->save_session("freshlogin", "redirect");
        go(hoturl("profile", "redirect=1"));
    } else
        $Conf->save_session("freshlogin", null);
}

// check global system settings
function admin_home_messages() {
    global $Opt, $Conf;
    $m = array();
    $errmarker = "<span class=\"error\">Error:</span> ";
    if (preg_match("/^(?:[1-4]\\.|5\\.[01])/", phpversion()))
        $m[] = $errmarker . "HotCRP requires PHP version 5.2 or higher.  You are running PHP version " . htmlspecialchars(phpversion()) . ".";
    if (get_magic_quotes_gpc())
        $m[] = $errmarker . "The PHP <code>magic_quotes_gpc</code> feature is on, which is a bad idea.  Check that your Web server is using HotCRP’s <code>.htaccess</code> file.  You may also want to disable <code>magic_quotes_gpc</code> in your <code>php.ini</code> configuration file.";
    if (get_magic_quotes_runtime())
        $m[] = $errmarker . "The PHP <code>magic_quotes_runtime</code> feature is on, which is a bad idea.  Check that your Web server is using HotCRP’s <code>.htaccess</code> file.  You may also want to disable <code>magic_quotes_runtime</code> in your <code>php.ini</code> configuration file.";
    if (defined("JSON_HOTCRP"))
        $m[] = "Your PHP was built without JSON functionality. HotCRP is using its built-in replacements; the native functions would be faster.";
    if ((int) $Opt["globalSessionLifetime"] < $Opt["sessionLifetime"])
        $m[] = "PHP’s systemwide <code>session.gc_maxlifetime</code> setting, which is " . htmlspecialchars($Opt["globalSessionLifetime"]) . " seconds, is less than HotCRP’s preferred session expiration time, which is " . $Opt["sessionLifetime"] . " seconds.  You should update <code>session.gc_maxlifetime</code> in the <code>php.ini</code> file or users may be booted off the system earlier than you expect.";
    if (!function_exists("imagecreate"))
        $m[] = $errmarker . "This PHP installation lacks support for the GD library, so HotCRP cannot generate score charts. You should update your PHP installation. For example, on Ubuntu Linux, install the <code>php5-gd</code> package.";
    $result = $Conf->qx("show variables like 'max_allowed_packet'");
    $max_file_size = ini_get_bytes("upload_max_filesize");
    if (($row = edb_row($result))
        && $row[1] < $max_file_size
        && !@$Opt["dbNoPapers"])
        $m[] = $errmarker . "MySQL’s <code>max_allowed_packet</code> setting, which is " . htmlspecialchars($row[1]) . "&nbsp;bytes, is less than the PHP upload file limit, which is $max_file_size&nbsp;bytes.  You should update <code>max_allowed_packet</code> in the system-wide <code>my.cnf</code> file or the system may not be able to handle large papers.";
    // Conference names
    if (@$Opt["shortNameDefaulted"])
        $m[] = "<a href=\"" . hoturl("settings", "group=msg") . "\">Set the conference abbreviation</a> to a short name for your conference, such as “OSDI ’14”.";
    else if (simplify_whitespace($Opt["shortName"]) != $Opt["shortName"])
        $m[] = "The <a href=\"" . hoturl("settings", "group=msg") . "\">conference abbreviation</a> setting has a funny value. To fix it, remove leading and trailing spaces, use only space characters (no tabs or newlines), and make sure words are separated by single spaces (never two or more).";
    $site_contact = Contact::site_contact();
    if (!$site_contact->email || $site_contact->email == "you@example.com")
        $m[] = "<a href=\"" . hoturl("settings", "group=msg") . "\">Set the conference contact’s name and email</a> so submitters can reach someone if things go wrong.";
    // Backwards compatibility
    if (@$Conf->setting_data("clickthrough_submit")) // delete 12/2014
        $m[] = "You need to recreate the <a href=\"" . hoturl("settings", "group=msg") . "\">clickthrough submission terms</a>.";
    // Any -100 preferences around?
    $result = $Conf->ql($Conf->preferenceConflictQuery(false, "limit 1"));
    if (($row = edb_row($result)))
        $m[] = "PC members have indicated paper conflicts (using review preferences of &#8722;100 or less) that aren’t yet confirmed. <a href='" . hoturl_post("autoassign", "a=prefconflict&amp;assign=1") . "' class='nowrap'>Confirm these conflicts</a>";
    // Weird URLs?
    foreach (array("conferenceSite", "paperSite") as $k)
        if (isset($Opt[$k]) && $Opt[$k] && !preg_match('`\Ahttps?://(?:[-.~\w:/?#\[\]@!$&\'()*+,;=]|%[0-9a-fA-F][0-9a-fA-F])*\z`', $Opt[$k]))
            $m[] = $errmarker . "The <code>\$Opt[\"$k\"]</code> setting, ‘<code>" . htmlspecialchars($Opt[$k]) . "</code>’, is not a valid URL.  Edit the <code>conf/options.php</code> file to fix this problem.";
    // Double-encoding bugs found?
    if ($Conf->setting("bug_doubleencoding"))
        $m[] = "Double-encoded URLs have been detected. Incorrect uses of Apache’s <code>mod_rewrite</code>, and other middleware, can encode URL parameters twice. This can cause problems, for instance when users log in via links in email. (“<code>a@b.com</code>” should be encoded as “<code>a%40b.com</code>”; a double encoding will produce “<code>a%2540b.com</code>”.) HotCRP has tried to compensate, but you really should fix the problem. For <code>mod_rewrite</code> add <a href='http://httpd.apache.org/docs/current/mod/mod_rewrite.html'>the <code>[NE]</code> option</a> to the relevant RewriteRule. <a href=\"" . hoturl_post("index", "clearbug=doubleencoding") . "\">(Clear&nbsp;this&nbsp;message)</a>";
    // Unnotified reviews?
    if ($Conf->setting("pcrev_assigntime", 0) > $Conf->setting("pcrev_informtime", 0)) {
        $assigntime = $Conf->setting("pcrev_assigntime");
        $result = $Conf->qe("select paperId from PaperReview where reviewType>" . REVIEW_PC . " and timeRequested>timeRequestNotified and reviewSubmitted is null and reviewNeedsSubmit!=0 limit 1");
        if (edb_nrows($result))
            $m[] = "PC review assignments have changed. You may want to <a href=\"" . hoturl("mail", "template=newpcrev") . "\">send mail about the new assignments</a>. <a href=\"" . hoturl_post("index", "clearnewpcrev=$assigntime") . "\">(Clear&nbsp;this&nbsp;message)</a>";
        else
            $Conf->save_setting("pcrev_informtime", $assigntime);
    }

    if (count($m))
        $Conf->warnMsg("<div>" . join("</div><div style='margin-top:0.5em'>", $m) . "</div>");
}

if ($Me->privChair) {
    if (isset($_REQUEST["clearbug"]) && check_post())
        $Conf->save_setting("bug_" . $_REQUEST["clearbug"], null);
    if (isset($_REQUEST["clearnewpcrev"]) && ctype_digit($_REQUEST["clearnewpcrev"])
        && check_post() && $Conf->setting("pcrev_informtime", 0) <= $_REQUEST["clearnewpcrev"])
        $Conf->save_setting("pcrev_informtime", $_REQUEST["clearnewpcrev"]);
    if (isset($_REQUEST["clearbug"]) || isset($_REQUEST["clearnewpcrev"]))
        redirectSelf(array("clearbug" => null, "clearnewpcrev" => null));
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
            $Conf->errorMsg("Invalid review token &ldquo;" . htmlspecialchars($x) . "&rdquo;.  Check your typing and try again.");
        else if ($Conf->session("rev_token_fail", 0) >= 5)
            $Conf->errorMsg("Too many failed attempts to use a review token.  <a href='" . hoturl("index", "signout=1") . "'>Sign out</a> and in to try again.");
        else {
            $result = $Conf->qe("select paperId from PaperReview where reviewToken=" . $token);
            if (($row = edb_row($result))) {
                $tokeninfo[] = "Review token “" . htmlspecialchars($x) . "” lets you review <a href='" . hoturl("paper", "p=$row[0]") . "'>paper #" . $row[0] . "</a>.";
                $Me->change_review_token($token, true);
            } else {
                $Conf->errorMsg("Review token “" . htmlspecialchars($x) . "” hasn’t been assigned.");
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
    admin_home_messages();

$title = ($Me->is_empty() || isset($_REQUEST["signin"]) ? "Sign in" : "Home");
$Conf->header($title, "home", actionBar());
$xsep = " <span class='barsep'>&nbsp;|&nbsp;</span> ";

if ($Me->privChair)
    echo "<div id='clock_drift_container'></div>";


// Sidebar
echo "<div class='homeside'>";

echo "<noscript><div class='homeinside'>",
    "<strong>HotCRP requires Javascript.</strong> ",
    "Many features will work without Javascript, but not all.<br />",
    "<a style='font-size:smaller' href='http://read.seas.harvard.edu/~kohler/hotcrp/'>Report bad compatibility problems</a></div></noscript>";

// Conference management
if ($Me->privChair) {
    echo "<div id='homemgmt' class='homeinside'>
  <h4>Administration</h4>
  <ul>
    <li><a href='", hoturl("settings"), "'>Settings</a></li>
    <li><a href='", hoturl("users", "t=all"), "'>Users</a></li>
    <li><a href='", hoturl("autoassign"), "'>Assign reviews</a></li>
    <li><a href='", hoturl("mail"), "'>Send mail</a></li>
    <li><a href='", hoturl("log"), "'>Action log</a></li>
  </ul>
</div>\n";
}

// Conference info sidebar
echo "<div class='homeinside'><div id='homeinfo'>
  <h4>Conference information</h4>
  <ul>\n";
// Any deadlines set?
$sep = "";
if ($Me->has_reportable_deadline())
    echo "    <li><a href='", hoturl("deadlines"), "'>Deadlines</a></li>\n";
echo "    <li><a href='", hoturl("users", "t=pc"), "'>Program committee</a></li>\n";
if (isset($Opt['conferenceSite']) && $Opt['conferenceSite'] != $Opt['paperSite'])
    echo "    <li><a href='", $Opt['conferenceSite'], "'>Conference site</a></li>\n";
if ($Conf->timeAuthorViewDecision()) {
    $dl = $Conf->deadlines();
    $dlt = max($dl["sub_sub"], $dl["sub_close"]);
    $result = $Conf->qe("select outcome, count(paperId) from Paper where timeSubmitted>0 " . ($dlt ? "or (timeSubmitted=-100 and timeWithdrawn>=$dlt) " : "") . "group by outcome");
    $n = $nyes = 0;
    while (($row = edb_row($result))) {
        $n += $row[1];
        if ($row[0] > 0)
            $nyes += $row[1];
    }
    echo "    <li>", plural($nyes, "paper"), " were accepted out of ", $n, " submitted.</li>\n";
}
echo "  </ul>\n</div>\n";

echo "</div></div>\n\n";
// End sidebar


// Home message
if (($v = $Conf->setting_data("msg.home")))
    $Conf->infoMsg($v);


// Sign in
if (!$Me->has_email() || isset($_REQUEST["signin"])) {
    $confname = $Opt["longName"];
    if ($Opt["shortName"] && $Opt["shortName"] != $Opt["longName"])
        $confname .= " (" . $Opt["shortName"] . ")";
    echo "<div class='homegrp'>
Welcome to the ", htmlspecialchars($confname), " submissions site.
Sign in to submit or review papers.";
    if (isset($Opt["conferenceSite"]))
        echo " For general information about ", htmlspecialchars($Opt["shortName"]), ", see <a href=\"", htmlspecialchars($Opt["conferenceSite"]), "\">the conference site</a>.";
    $passwordFocus = ($email_class == "" && $password_class != "");
    echo "</div>
<hr class='home' />
<div class='homegrp' id='homeacct'>\n",
        Ht::form(hoturl_post("index")),
        "<div class=\"f-contain\">";
    if ($Me->is_empty() || isset($_REQUEST["signin"]))
        echo Ht::hidden("testsession", 1);
    if (@$Opt["contactdb_dsn"] && @$Opt["contactdb_loginFormHeading"])
        echo $Opt["contactdb_loginFormHeading"];
    if (($password_reset = $Conf->session("password_reset")))
        $Conf->save_session("password_reset", null);
    echo "<div class='f-ii'>
  <div class='f-c", $email_class, "'>",
        (isset($Opt["ldapLogin"]) ? "Username" : "Email"),
        "</div>
  <div class='f-e", $email_class, "'><input",
        ($passwordFocus ? "" : " id='login_d'"),
        " type='text' name='email' size='36' tabindex='1' ";
    if (isset($_REQUEST["email"]))
        echo "value=\"", htmlspecialchars($_REQUEST["email"]), "\" ";
    else if ($password_reset)
        echo "value=\"", htmlspecialchars($password_reset->email), "\" ";
    echo " /></div>
</div>
<div class='f-i'>
  <div class='f-c", $password_class, "'>Password</div>
  <div class='f-e'><input",
        ($passwordFocus ? " id='login_d'" : ""),
        " type='password' name='password' size='36' tabindex='1'";
    if ($password_reset)
        echo " value=\"", htmlspecialchars($password_reset->password), "\"";
    else
        echo " value=\"\"";
    echo " /></div>
</div>\n";
    if (isset($Opt["ldapLogin"]))
        echo Ht::hidden("action", "login");
    else {
        echo "<div class='f-i'>\n  ",
            Ht::radio("action", "login", true, array("tabindex" => 2)),
            "&nbsp;", Ht::label("<b>Sign me in</b>"), "<br />\n";
        echo Ht::radio("action", "forgot", false, array("tabindex" => 2)),
            "&nbsp;", Ht::label("I forgot my password"), "<br />\n";
        echo Ht::radio("action", "new", false, array("tabindex" => 2)),
            "&nbsp;", Ht::label("I’m a new user and want to create an account using this email address");
        echo "\n</div>\n";
    }
    echo "<div class='f-i'>",
        Ht::submit("signin", "Sign in", array("tabindex" => 1)),
        "</div></div></form>
<hr class='home' /></div>\n";
    $Conf->footerScript("crpfocus(\"login\", null, 2)");
}


// Submissions
$papersub = $Conf->setting("papersub");
$homelist = ($Me->privChair || ($Me->isPC && $papersub) || ($Me->is_reviewer() && $papersub));
if ($homelist) {
    echo "<div class='homegrp' id='homelist'>\n";

    // Lists
    echo "<table><tr><td><h4>Search: &nbsp;&nbsp;</h4></td>\n";

    $tOpt = PaperSearch::searchTypes($Me);
    $q = defval($_REQUEST, "q", "(All)");
    echo "  <td>", Ht::form_div(hoturl("search"), array("method" => "get")),
        "<input id='homeq' class='",
        ($q == "(All)" ? "temptext" : "temptextoff"),
        "' type='text' size='32' name='q' value=\"",
        htmlspecialchars($q),
        "\" title='Enter paper numbers or search terms' />
    &nbsp;in&nbsp; ",
        PaperSearch::searchTypeSelector($tOpt, key($tOpt), 0), "
    &nbsp; ", Ht::submit("Search"),
        "    <div id='taghelp_homeq' class='taghelp_s'></div>
    <div style='font-size:85%'><a href='", hoturl("help", "t=search"), "'>Search help</a> <span class='barsep'>&nbsp;|&nbsp;</span> <a href='", hoturl("help", "t=keywords"), "'>Search keywords</a> <span class='barsep'>&nbsp;|&nbsp;</span> <a href='", hoturl("search", "tab=advanced"), "'>Advanced search</a></div>
  </div></form>
  </td></tr></table>
</div>
<hr class='home' />\n";
    $Conf->footerScript("mktemptext('homeq','(All)')");
    if (!defval($Opt, "noSearchAutocomplete"))
        $Conf->footerScript("taghelp(\"homeq\",\"taghelp_homeq\",taghelp_q)");
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
    echo "<div class='homegrp' id='homerev'>\n";
    $all_review_fields = ReviewForm::field_list_all_rounds();
    $merit_field = @$all_review_fields["overAllMerit"];
    $merit_noptions = $merit_field ? count($merit_field->options) : 0;

    // Information about my reviews
    $where = array();
    if ($Me->contactId)
        $where[] = "PaperReview.contactId=" . $Me->contactId;
    if (($tokens = $Me->review_tokens()))
        $where[] = "reviewToken in (" . join(",", $tokens) . ")";
    $result = $Conf->qe("select count(reviewSubmitted) num_submitted,
	count(if(reviewNeedsSubmit=0,reviewSubmitted,1)) num_needs_submit,
	group_concat(if(reviewSubmitted is not null,overAllMerit,null)) scores,
	group_concat(if(reviewNeedsSubmit=1 and reviewSubmitted is null,reviewRound,null)) unsubmitted_rounds
	from PaperReview
	join Paper using (paperId)
	where " . join(" or ", $where) . " group by PaperReview.reviewId>0");
    if (($myrow = edb_orow($result)))
        $myrow->scores = scoreCounts($myrow->scores, $merit_noptions);

    // Information about PC reviews
    $npc = $sumpcSubmit = $npcScore = $sumpcScore = 0;
    if ($Me->isPC || $Me->privChair) {
        $result = $Conf->qe("select count(reviewId) num_submitted,
	group_concat(overAllMerit) scores
	from PCMember
	left join PaperReview on (PaperReview.contactId=PCMember.contactId and PaperReview.reviewSubmitted is not null)
	group by PCMember.contactId");
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
            echo "&nbsp; <small>(<a href=\"", hoturl("users", "t=pc&amp;score%5B%5D=0"), "\">Details</a>)</small>";
        echo "<br />\n";
    }
    if ($myrow && $myrow->num_submitted < $myrow->num_needs_submit
        && !$Conf->time_review_open())
        echo ' <span class="deadline">The site is not open for reviewing.</span><br />', "\n";
    else if ($myrow && $myrow->num_submitted < $myrow->num_needs_submit) {
        $missing_rounds = explode(",", $myrow->unsubmitted_rounds);
        sort($missing_rounds, SORT_NUMERIC);
        foreach ($missing_rounds as $round) {
            if (($rname = $Conf->round_name($round, false)))
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

    if (($myrow || $Me->privChair) && $npc)
        echo "</div>\n<div id='foldre' class='homegrp foldo'>";

    // Actions
    $sep = "";
    if ($myrow) {
        echo $sep, foldbutton("re"), "<a href=\"", hoturl("search", "q=re%3Ame"), "\" title='Search in your reviews (more display and download options)'><strong>Your Reviews</strong></a>";
        $sep = $xsep;
    }
    if ($Me->isPC && $Conf->setting("paperlead") > 0
        && $Me->is_discussion_lead()) {
        echo $sep, '<a href="', hoturl("search", "q=lead%3Ame"), '" class="nowrap">Your discussion leads</a>';
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
        echo $sep, '<a href="', hoturl("mail", "monreq=1"), '">Monitor external reviews</a>';
        $sep = $xsep;
    }
    if ($Conf->setting("rev_tokens")) {
        echo $sep;
        reviewTokenGroup(false);
        $sep = $xsep;
    }

    if ($myrow && $Conf->setting("rev_ratings") != REV_RATINGS_NONE) {
        $badratings = PaperSearch::unusableRatings($Me->privChair, $Me->contactId);
        $qx = (count($badratings) ? " and not (PaperReview.reviewId in (" . join(",", $badratings) . "))" : "");
        $result = $Conf->qe("select rating, count(PaperReview.reviewId) from PaperReview join ReviewRating on (PaperReview.contactId=$Me->contactId and PaperReview.reviewId=ReviewRating.reviewId$qx) group by rating order by rating desc");
        if (edb_nrows($result)) {
            $a = array();
            while (($row = edb_row($result)))
                if (isset(ReviewForm::$rating_types[$row[0]]))
                    $a[] = "<a href=\"" . hoturl("search", "q=rate:%22" . urlencode(ReviewForm::$rating_types[$row[0]]) . "%22") . "\" title='List rated reviews'>$row[1] &ldquo;" . htmlspecialchars(ReviewForm::$rating_types[$row[0]]) . "&rdquo; " . pluralx($row[1], "rating") . "</a>";
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
        $plist = new PaperList(new PaperSearch($Me, array("q" => "re:me")), array("list" => true));
        $ptext = $plist->text("reviewerHome");
        if ($plist->count > 0)
            echo "<div class='fx'><div class='g'></div>", $ptext, "</div>";
    }

    if ($Me->is_reviewer()) {
        $entries = $Conf->reviewerActivity($Me, time(), 30);
        if (count($entries)) {
            $fold20 = $Conf->session("foldhomeactivity", 1) ? "fold20c" : "fold20o";
            echo "<div class=\"homegrp $fold20\" id=\"homeactivity\" hotcrp_foldsession=\"foldhomeactivity\">",
                "<div class=\"fold21c\" id=\"homeactivitymore\">",
                foldbutton("homeactivity", 20),
                "<h4><a href=\"#\" onclick=\"return fold('homeactivity',null,20)\" class=\"x homeactivity\">Recent activity<span class='fx20'>:</span></a></h4>";
            if (count($entries) > 10)
                echo "&nbsp; <a href=\"#\" onclick=\"return fold('homeactivitymore',null,21)\" class='fx20'><span class='fn21'>More &#187;</span><span class='fx21'>&#171; Fewer</span></a>";
            echo "<div class='fx20' style='overflow:hidden;padding-top:3px'><table><tbody>";
            foreach ($entries as $which => $xr) {
                $tr_class = "k" . ($which % 2) . ($which >= 10 ? " fx21" : "");
                if ($xr->isComment)
                    echo CommentView::commentFlowEntry($Me, $xr, $tr_class);
                else {
                    $rf = ReviewForm::get($xr);
                    echo $rf->reviewFlowEntry($Me, $xr, $tr_class);
                }
            }
            echo "</tbody></table></div></div></div>";
        }
    }

    echo "<hr class='home' /></div>\n";
}

// Authored papers
if ($Me->is_author() || $Conf->timeStartPaper() > 0 || $Me->privChair
    || !$Me->is_reviewer()) {
    echo "<div class='homegrp' id='homeau'>";

    // Overview
    if ($Me->is_author())
        echo "<h4>Your Submissions: &nbsp;</h4> ";
    else
        echo "<h4>Submissions: &nbsp;</h4> ";

    $startable = $Conf->timeStartPaper();
    if ($startable && !$Me->has_email())
        echo "<span class='deadline'>", $Conf->printableDeadlineSetting("sub_reg", "span"), "</span><br />\n<small>You must sign in to register papers.</small>";
    else if ($startable || $Me->privChair) {
        echo "<strong><a href='", hoturl("paper", "p=new"), "'>Start new paper</a></strong> <span class='deadline'>(", $Conf->printableDeadlineSetting("sub_reg", "span"), ")</span>";
        if ($Me->privChair)
            echo "<br />\n<span class='hint'>As an administrator, you can start a paper regardless of deadlines and on behalf of others.</span>";
    }

    $plist = null;
    if ($Me->is_author()) {
        $plist = new PaperList(new PaperSearch($Me, array("t" => "a")), array("list" => true));
        $ptext = $plist->text("authorHome", array("noheader" => true));
        if ($plist->count > 0)
            echo "<div class='g'></div>\n", $ptext;
    }

    $deadlines = array();
    if ($plist && $plist->any->need_submit) {
        if (!$Conf->timeFinalizePaper()) {
            // Be careful not to refer to a future deadline; perhaps an admin
            // just turned off submissions.
            if ($Conf->deadlinesBetween("", "sub_sub", "sub_grace"))
                $deadlines[] = "The site is not open for submissions at the moment.";
            else
                $deadlines[] = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for submitting papers has passed.";
        } else if (!$Conf->timeUpdatePaper()) {
            $deadlines[] = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for updating papers has passed, but you can still submit.";
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
            $deadlines[] = "The <a href='" . hoturl("deadlines") . "'>deadline</a> for registering new papers has passed.";
        else
            $deadlines[] = "The site is not open for submissions at the moment.";
    }
    if ($plist && $Conf->timeSubmitFinalPaper() && $plist->any->accepted) {
        $time = $Conf->printableTimeSetting("final_soft");
        if ($Conf->deadlinesAfter("final_soft") && $plist->any->need_final)
            $deadlines[] = "<strong class='overdue'>Final versions are overdue.</strong>  They were requested by $time.";
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

    echo "<hr class='home' /></div>\n";
}


// Review tokens
if ($Me->has_email() && $Conf->setting("rev_tokens"))
    reviewTokenGroup(true);


echo "<div class='clear'></div>\n";
$Conf->footer();
