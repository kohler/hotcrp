<?php
// users.php -- HotCRP people listing/editing page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

require_once("src/initweb.php");
require_once("src/contactlist.php");
$getaction = "";
if (isset($_REQUEST["get"]))
    $getaction = $_REQUEST["get"];
else if (isset($_REQUEST["getgo"]) && isset($_REQUEST["getaction"]))
    $getaction = $_REQUEST["getaction"];


// list type
$tOpt = array();
if ($Me->isPC || !opt("privatePC"))
    $tOpt["pc"] = "Program committee";
if ($Me->isPC && count($pctags = $Conf->pc_tags())) {
    foreach ($pctags as $t)
        if ($t != "pc")
            $tOpt["#$t"] = "#$t program committee";
}
if ($Me->isPC)
    $tOpt["admin"] = "System administrators";
if ($Me->privChair || ($Me->isPC && $Conf->timePCViewAllReviews())) {
    $tOpt["re"] = "All reviewers";
    $tOpt["ext"] = "External reviewers";
    $tOpt["extsub"] = "External reviewers who completed a review";
}
if ($Me->isPC)
    $tOpt["req"] = "External reviewers you requested";
if ($Me->privChair || ($Me->isPC && $Conf->subBlindNever()))
    $tOpt["au"] = "Contact authors of submitted papers";
if ($Me->privChair
    || ($Me->isPC && $Conf->timePCViewDecision(true)))
    $tOpt["auacc"] = "Contact authors of accepted papers";
if ($Me->privChair
    || ($Me->isPC && $Conf->subBlindNever() && $Conf->timePCViewDecision(true)))
    $tOpt["aurej"] = "Contact authors of rejected papers";
if ($Me->privChair) {
    $tOpt["auuns"] = "Contact authors of non-submitted papers";
    $tOpt["all"] = "All users";
}
if (empty($tOpt))
    $Me->escape();
if (isset($_REQUEST["t"]) && !isset($tOpt[$_REQUEST["t"]])) {
    if (str_starts_with($_REQUEST["t"], "pc:")
        && isset($tOpt["#" . substr($_REQUEST["t"], 3)]))
        $_REQUEST["t"] = "#" . substr($_REQUEST["t"], 3);
    else if (isset($tOpt["#" . $_REQUEST["t"]]))
        $_REQUEST["t"] = "#" . $_REQUEST["t"];
    else if ($_REQUEST["t"] == "#pc")
        $_REQUEST["t"] = "pc";
    else {
        Conf::msg_error("Unknown user collection.");
        unset($_REQUEST["t"]);
    }
}
if (!isset($_REQUEST["t"]))
    $_REQUEST["t"] = key($tOpt);


// paper selection and download actions
function paperselPredicate($papersel) {
    return "ContactInfo.contactId" . sql_in_numeric_set($papersel);
}

if (isset($_REQUEST["pap"]) && is_string($_REQUEST["pap"]))
    $_REQUEST["pap"] = preg_split('/\s+/', $_REQUEST["pap"]);
if ((isset($_REQUEST["pap"]) && is_array($_REQUEST["pap"]))
    || ($getaction && !isset($_REQUEST["pap"]))) {
    $allowed_papers = array();
    $pl = new ContactList($Me, true);
    // Ensure that we only select contacts we're allowed to see.
    if (($rows = $pl->rows($_REQUEST["t"]))) {
        foreach ($rows as $row)
            $allowed_papers[$row->contactId] = true;
    }
    $papersel = array();
    if (isset($_REQUEST["pap"])) {
        foreach ($_REQUEST["pap"] as $p)
            if (($p = cvtint($p)) > 0 && isset($allowed_papers[$p]))
                $papersel[] = $p;
    } else
        $papersel = array_keys($allowed_papers);
    if (count($papersel) == 0)
        unset($papersel);
}

if ($getaction == "nameemail" && isset($papersel) && $Me->isPC) {
    $result = $Conf->qe_raw("select firstName first, lastName last, email, affiliation from ContactInfo where " . paperselPredicate($papersel) . " order by lastName, firstName, email");
    $people = edb_orows($result);
    downloadCSV($people, array("first", "last", "email", "affiliation"), "users",
                array("selection" => true));
}

function urlencode_matches($m) {
    return urlencode($m[0]);
}

if ($getaction == "pcinfo" && isset($papersel) && $Me->privChair) {
    assert($Conf->sversion >= 73);
    $result = $Conf->qe_raw("select ContactInfo.*,
        (select group_concat(topicId, ' ', interest) from TopicInterest where contactId=ContactInfo.contactId) topicInterest
        from ContactInfo
        where " . paperselPredicate($papersel) . "
        order by lastName, firstName, email");

    // NB This format is expected to be parsed by profile.php's bulk upload.
    $tagger = new Tagger($Me);
    $people = [];
    $has = (object) [];
    while (($user = Contact::fetch($result, $Conf))) {
        $row = (object) ["first" => $user->firstName, "last" => $user->lastName,
            "email" => $user->email, "phone" => $user->voicePhoneNumber,
            "disabled" => !!$user->disabled];
        if ($user->preferredEmail && $user->preferredEmail !== $user->email)
            $row->preferred_email = $user->preferredEmail;
        if ($user->contactTags)
            $row->tags = $tagger->unparse($user->contactTags);
        foreach ($user->topic_interest_map() as $t => $i) {
            $k = "topic$t";
            $row->$k = $i;
        }
        $f = array();
        if ($user->defaultWatch & (WATCHTYPE_COMMENT << WATCHSHIFT_ON))
            $f[] = "reviews";
        if (($user->defaultWatch & (WATCHTYPE_COMMENT << WATCHSHIFT_ALLON))
            && ($user->roles & Contact::ROLE_PCLIKE))
            $f[] = "allreviews";
        if (($user->defaultWatch & (WATCHTYPE_FINAL_SUBMIT << WATCHSHIFT_ALLON))
            && ($user->roles & (Contact::ROLE_ADMIN | Contact::ROLE_CHAIR)))
            $f[] = "allfinal";
        $row->follow = join(",", $f);
        if ($user->roles & (Contact::ROLE_PC | Contact::ROLE_ADMIN | Contact::ROLE_CHAIR)) {
            $r = array();
            if ($user->roles & Contact::ROLE_CHAIR)
                $r[] = "chair";
            if ($user->roles & Contact::ROLE_PC)
                $r[] = "pc";
            if ($user->roles & Contact::ROLE_ADMIN)
                $r[] = "sysadmin";
            $row->roles = join(",", $r);
        } else
            $row->roles = "";
        $people[] = $row;

        foreach ((array) $row as $k => $v)
            if ($v !== null && $v !== false && $v !== "")
                $has->$k = true;
    }

    $header = array("first", "last", "email");
    if (isset($has->preferred_email))
        $header[] = "preferred_email";
    $header[] = "roles";
    if (isset($has->tags))
        $header[] = "tags";
    array_push($header, "affiliation", "collaborators", "follow");
    if (isset($has->phone))
        $header[] = "phone";
    $selection = $header;
    foreach ($Conf->topic_map() as $t => $tn) {
        $k = "topic$t";
        if (isset($has->$k)) {
            $header[] = "topic: " . $tn;
            $selection[] = $k;
        }
    }
    downloadCSV($people, $header, "pcinfo", array("selection" => $selection));
}


// modifications
function modify_confirm($j, $ok_message, $ok_message_optional) {
    global $Conf;
    if (get($j, "ok") && get($j, "warnings"))
        $Conf->warnMsg("<div>" . join("</div><div style='margin-top:0.5em'>", $j->warnings) . "</div>");
    if (get($j, "ok") && $ok_message && (!$ok_message_optional || !get($j, "warnings")))
        $Conf->confirmMsg($ok_message);
}

if ($Me->privChair && @$_REQUEST["modifygo"] && check_post() && isset($papersel)) {
    if (@$_REQUEST["modifytype"] == "disableaccount")
        modify_confirm(UserActions::disable($papersel, $Me), "Accounts disabled.", true);
    else if (@$_REQUEST["modifytype"] == "enableaccount")
        modify_confirm(UserActions::enable($papersel, $Me), "Accounts enabled.", true);
    else if (@$_REQUEST["modifytype"] == "resetpassword")
        modify_confirm(UserActions::reset_password($papersel, $Me), "Passwords reset. <a href=\"" . hoturl_post("users", "t=" . urlencode($_REQUEST["t"]) . "&amp;modifygo=1&amp;modifytype=sendaccount&amp;pap=" . join("+", $papersel)) . "\">Send account information to those accounts</a>", false);
    else if (@$_REQUEST["modifytype"] == "sendaccount")
        modify_confirm(UserActions::send_account_info($papersel, $Me), "Account information sent.", false);
    redirectSelf(array("modifygo" => null, "modifytype" => null));
}

function do_tags() {
    global $Conf, $Me, $papersel;
    // check tags
    $tagger = new Tagger($Me);
    $t1 = array();
    $errors = array();
    foreach (preg_split('/[\s,]+/', (string) @$_REQUEST["tag"]) as $t)
        if ($t === "")
            /* nada */;
        else if (!($t = $tagger->check($t, Tagger::NOPRIVATE)))
            $errors[] = $tagger->error_html;
        else if (TagInfo::base($t) === "pc")
            $errors[] = "The “pc” user tag is set automatically for all PC members.";
        else
            $t1[] = $t;
    if (count($errors))
        return Conf::msg_error(join("<br>", $errors));
    else if (!count($t1))
        return $Conf->warnMsg("Nothing to do.");

    // modify database
    Dbl::qe("lock tables ContactInfo write");
    Conf::$no_invalidate_caches = true;
    $users = array();
    if ($_REQUEST["tagtype"] === "s") {
        // erase existing tags
        $likes = array();
        $removes = array();
        foreach ($t1 as $t) {
            list($tag, $index) = TagInfo::unpack($t);
            $removes[] = $t;
            $likes[] = "contactTags like " . Dbl::utf8ci("'% " . sqlq_for_like($tag) . "#%'");
        }
        foreach (Dbl::fetch_first_columns(Dbl::qe("select contactId from ContactInfo where " . join(" or ", $likes))) as $cid)
            $users[(int) $cid] = (object) array("id" => (int) $cid, "add_tags" => [], "remove_tags" => $removes);
    }
    // account for request
    $key = $_REQUEST["tagtype"] === "d" ? "remove_tags" : "add_tags";
    foreach ($papersel as $cid) {
        if (!isset($users[(int) $cid]))
            $users[(int) $cid] = (object) array("id" => (int) $cid, "add_tags" => [], "remove_tags" => []);
        $users[(int) $cid]->$key = array_merge($users[(int) $cid]->$key, $t1);
    }
    // apply modifications
    foreach ($users as $cid => $cj) {
        $us = new UserStatus(array("send_email" => false));
        if (!$us->save($cj))
            $errors = array_merge($errors, $us->error_messages());
    }
    Dbl::qe("unlock tables");
    Conf::$no_invalidate_caches = false;
    $Conf->invalidate_caches(["pc" => true]);
    // report
    if (!count($errors)) {
        $Conf->confirmMsg("Tags saved.");
        redirectSelf(array("tagact" => null, "tag" => null));
    } else
        Conf::msg_error(join("<br>", $errors));
}

if ($Me->privChair && @$_REQUEST["tagact"] && check_post() && isset($papersel)
    && preg_match('/\A[ads]\z/', (string) @$_REQUEST["tagtype"]))
    do_tags();


// set scores to view
if (isset($_REQUEST["redisplay"])) {
    $Conf->save_session("uldisplay", "");
    foreach (ContactList::$folds as $key)
        displayOptionsSet("uldisplay", $key, defval($_REQUEST, "show$key", 0));
    foreach ($Conf->all_review_fields() as $f)
        if ($f->has_options)
            displayOptionsSet("uldisplay", $f->id, defval($_REQUEST, "show{$f->id}", 0));
}
if (isset($_REQUEST["scoresort"])
    && ($_REQUEST["scoresort"] == "A" || $_REQUEST["scoresort"] == "V"
        || $_REQUEST["scoresort"] == "D"))
    $Conf->save_session("scoresort", $_REQUEST["scoresort"]);


if ($_REQUEST["t"] == "pc")
    $title = "Program committee";
else if (str_starts_with($_REQUEST["t"], "#"))
    $title = "#" . substr($_REQUEST["t"], 1) . " program committee";
else
    $title = "Users";
$Conf->header($title, "accounts", actionBar());


$pl = new ContactList($Me, true);
$pl_text = $pl->table_html($_REQUEST["t"], hoturl("users", ["t" => $_REQUEST["t"]]),
                     $tOpt[$_REQUEST["t"]], 'uldisplay.$');


// form
echo "<div class='g'></div>\n";
if (count($tOpt) > 1) {
    echo "<table id='contactsform' class='tablinks1'>
<tr><td><div class='tlx'><div class='tld1'>";

    echo Ht::form_div(hoturl("users"), array("method" => "get"));
    if (isset($_REQUEST["sort"]))
        echo Ht::hidden("sort", $_REQUEST["sort"]);
    echo Ht::select("t", $tOpt, $_REQUEST["t"], ["class" => "want-focus"]),
        " &nbsp;", Ht::submit("Go"), "</div></form>";

    echo "</div><div class='tld2'>";

    // Display options
    echo Ht::form_div(hoturl("users"), array("method" => "get"));
    foreach (array("t", "sort") as $x)
        if (isset($_REQUEST[$x]))
            echo Ht::hidden($x, $_REQUEST[$x]);

    echo "<table><tr><td><strong>Show:</strong> &nbsp;</td>
  <td class='pad'>";
    Ht::stash_script('foldmap.ul={"aff":2,"tags":3,"topics":1};');
    foreach (array("aff" => "Affiliations", "collab" => "Collaborators",
                   "tags" => "Tags", "topics" => "Topics") as $fold => $text)
        if (@$pl->have_folds[$fold] !== null) {
            echo Ht::checkbox("show$fold", 1, $pl->have_folds[$fold],
                               array("onchange" => "fold('ul',!this.checked,'$fold')")),
                "&nbsp;", Ht::label($text), "<br />\n";
        }
    echo "</td>";
    if (isset($pl->scoreMax)) {
        echo "<td class='pad'>";
        $revViewScore = $Me->aggregated_view_score_bound();
        foreach ($Conf->all_review_fields() as $f)
            if ($f->view_score > $revViewScore && $f->has_options) {
                $checked = strpos(displayOptionsSet("uldisplay"), $f->id) !== false;
                echo Ht::checkbox("show{$f->id}", 1, $checked),
                    "&nbsp;", Ht::label($f->name_html), "<br />";
            }
        echo "</td>";
    }
    echo "<td>", Ht::submit("redisplay", "Redisplay"), "</td></tr>\n";
    if (isset($pl->scoreMax)) {
        $ss = array();
        foreach (array("A", "V", "D") as $k) /* ghetto array_intersect_key */
            if (isset(ListSorter::$score_sorts[$k]))
                $ss[$k] = ListSorter::$score_sorts[$k];
        echo "<tr><td colspan='3'><div class='g'></div><b>Sort scores by:</b> &nbsp;",
            Ht::select("scoresort", $ss, $Conf->session("scoresort", "A")),
            "</td></tr>";
    }
    echo "</table></div></form>";

    echo "</div></div></td></tr>\n";

    // Tab selectors
    echo "<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a class='tla' onclick='return crpfocus(\"contactsform\", 1)' href=''>User selection</a></div></td>
  <td><div class='tll2'><a class='tla' onclick='return crpfocus(\"contactsform\", 2)' href=''>Display options</a></div></td>
</tr></table></td></tr>
</table>\n\n";
}


if ($Me->privChair && $_REQUEST["t"] == "pc")
    $Conf->infoMsg("<p><a href='" . hoturl("profile", "u=new&amp;role=pc") . "' class='btn'>Add PC member</a></p><p>Select a PC member’s name to edit their profile or remove them from the PC.</p>");
else if ($Me->privChair && $_REQUEST["t"] == "all")
    $Conf->infoMsg("<p><a href='" . hoturl("profile", "u=new") . "' class='btn'>Create account</a></p><p>Select a user to edit their profile.  Select " . Ht::img("viewas.png", "[Act as]") . " to view the site as that user would see it.</p>");


if (isset($pl->any->sel)) {
    echo Ht::form(hoturl_post("users", ["t" => $_REQUEST["t"]])), "<div>";
    if (isset($_REQUEST["sort"]))
        echo Ht::hidden("sort", $_REQUEST["sort"]);
}
echo $pl_text;
if (isset($pl->any->sel))
    echo "</div></form>";


$Conf->footer();
