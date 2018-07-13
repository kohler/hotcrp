<?php
// users.php -- HotCRP people listing/editing page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/contactlist.php");

$getaction = "";
if (isset($Qreq->get))
    $getaction = $Qreq->get;
else if (isset($Qreq->getgo) && isset($Qreq->getaction))
    $getaction = $Qreq->getaction;


// list type
$tOpt = array();
if ($Me->can_view_pc())
    $tOpt["pc"] = "Program committee";
if ($Me->can_view_contact_tags() && count($pctags = $Conf->pc_tags())) {
    foreach ($pctags as $t)
        if ($t != "pc")
            $tOpt["#$t"] = "#$t program committee";
}
if ($Me->isPC)
    $tOpt["admin"] = "System administrators";
if ($Me->privChair || ($Me->isPC && $Conf->setting("pc_seeallrev"))) {
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
if (isset($Qreq->t) && !isset($tOpt[$Qreq->t])) {
    if (str_starts_with($Qreq->t, "pc:")
        && isset($tOpt["#" . substr($Qreq->t, 3)]))
        $Qreq->t = "#" . substr($Qreq->t, 3);
    else if (isset($tOpt["#" . $Qreq->t]))
        $Qreq->t = "#" . $Qreq->t;
    else if ($Qreq->t == "#pc")
        $Qreq->t = "pc";
    else {
        Conf::msg_error("Unknown user collection.");
        unset($Qreq->t);
    }
}
if (!isset($Qreq->t))
    $Qreq->t = key($tOpt);


// paper selection and download actions
function paperselPredicate($papersel) {
    return "ContactInfo.contactId" . sql_in_numeric_set($papersel);
}

$Qreq->allow_a("pap");
if (isset($Qreq->pap) && is_string($Qreq->pap))
    $Qreq->pap = preg_split('/\s+/', $Qreq->pap);
if ((isset($Qreq->pap) && is_array($Qreq->pap))
    || ($getaction && !isset($Qreq->pap))) {
    $allowed_papers = array();
    $pl = new ContactList($Me, true);
    // Ensure that we only select contacts we're allowed to see.
    if (($rows = $pl->rows($Qreq->t))) {
        foreach ($rows as $row)
            $allowed_papers[$row->contactId] = true;
    }
    $papersel = array();
    if (isset($Qreq->pap)) {
        foreach ($Qreq->pap as $p)
            if (($p = cvtint($p)) > 0 && isset($allowed_papers[$p]))
                $papersel[] = $p;
    } else
        $papersel = array_keys($allowed_papers);
    if (empty($papersel))
        unset($papersel);
}

if ($getaction == "nameemail" && isset($papersel) && $Me->isPC) {
    $result = $Conf->qe_raw("select firstName first, lastName last, email, affiliation from ContactInfo where " . paperselPredicate($papersel) . " order by lastName, firstName, email");
    $people = edb_orows($result);
    csv_exit($Conf->make_csvg("users")
             ->select(["first", "last", "email", "affiliation"])
             ->add($people));
}

function urlencode_matches($m) {
    return urlencode($m[0]);
}

if ($getaction == "pcinfo" && isset($papersel) && $Me->privChair) {
    $users = [];
    $result = $Conf->qe_raw("select ContactInfo.* from ContactInfo where " . paperselPredicate($papersel));
    while (($user = Contact::fetch($result, $Conf)))
        $users[] = $user;
    Dbl::free($result);

    usort($users, "Contact::compare");
    Contact::load_topic_interests($users);

    // NB This format is expected to be parsed by profile.php's bulk upload.
    $tagger = new Tagger($Me);
    $people = [];
    $has = (object) [];
    foreach ($users as $user) {
        $row = (object) ["first" => $user->firstName, "last" => $user->lastName,
            "email" => $user->email, "phone" => $user->phone,
            "disabled" => !!$user->disabled, "affiliation" => $user->affiliation,
            "collaborators" => rtrim($user->collaborators)];
        if ($user->preferredEmail && $user->preferredEmail !== $user->email)
            $row->preferred_email = $user->preferredEmail;
        if ($user->contactTags)
            $row->tags = $tagger->unparse($user->contactTags);
        foreach ($user->topic_interest_map() as $t => $i) {
            $k = "topic$t";
            $row->$k = $i;
        }
        $f = array();
        if ($user->defaultWatch & Contact::WATCH_REVIEW)
            $f[] = "reviews";
        if (($user->defaultWatch & Contact::WATCH_REVIEW_ALL)
            && ($user->roles & Contact::ROLE_PCLIKE))
            $f[] = "allreviews";
        if ($user->defaultWatch & Contact::WATCH_REVIEW_MANAGED)
            $f[] = "managedreviews";
        if ($user->defaultWatch & Contact::WATCH_FINAL_SUBMIT_ALL)
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
    csv_exit($Conf->make_csvg("pcinfo")->select($selection, $header)->add($people));
}


// modifications
function modify_confirm($j, $ok_message, $ok_message_optional) {
    global $Conf;
    if (get($j, "ok") && get($j, "warnings"))
        $Conf->warnMsg("<div>" . join("</div><div style='margin-top:0.5em'>", $j->warnings) . "</div>");
    if (get($j, "ok") && $ok_message
        && (!$ok_message_optional || !get($j, "warnings"))
        && (!isset($j->users) || !empty($j->users)))
        $Conf->confirmMsg($ok_message);
}

if ($Me->privChair && $Qreq->modifygo && $Qreq->post_ok() && isset($papersel)) {
    if ($Qreq->modifytype == "disableaccount")
        modify_confirm(UserActions::disable($Me, $papersel), "Accounts disabled.", true);
    else if ($Qreq->modifytype == "enableaccount")
        modify_confirm(UserActions::enable($Me, $papersel), "Accounts enabled.", true);
    else if ($Qreq->modifytype == "resetpassword"
             && $Me->can_change_password(null))
        modify_confirm(UserActions::reset_password($Me, $papersel), "Passwords reset. <a href=\"" . hoturl_post("users", "t=" . urlencode($Qreq->t) . "&amp;modifygo=1&amp;modifytype=sendaccount&amp;pap=" . join("+", $papersel)) . "\">Send account information to those accounts</a>", false);
    else if ($Qreq->modifytype == "sendaccount")
        modify_confirm(UserActions::send_account_info($Me, $papersel), "Account information sent.", false);
    unset($Qreq->modifygo, $Qreq->modifytype);
    SelfHref::redirect($Qreq);
}

function do_tags($qreq) {
    global $Conf, $Me, $papersel;
    // check tags
    $tagger = new Tagger($Me);
    $t1 = array();
    $errors = array();
    foreach (preg_split('/[\s,]+/', (string) $qreq->tag) as $t) {
        if ($t === "")
            /* nada */;
        else if (!($t = $tagger->check($t, Tagger::NOPRIVATE)))
            $errors[] = $tagger->error_html;
        else if (TagInfo::base($t) === "pc")
            $errors[] = "The “pc” user tag is set automatically for all PC members.";
        else
            $t1[] = $t;
    }
    if (count($errors))
        return Conf::msg_error(join("<br>", $errors));
    else if (!count($t1))
        return $Conf->warnMsg("Nothing to do.");

    // modify database
    Dbl::qe("lock tables ContactInfo write");
    Conf::$no_invalidate_caches = true;
    $users = array();
    if ($qreq->tagtype === "s") {
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
    $key = $qreq->tagtype === "d" ? "remove_tags" : "add_tags";
    foreach ($papersel as $cid) {
        if (!isset($users[(int) $cid]))
            $users[(int) $cid] = (object) array("id" => (int) $cid, "add_tags" => [], "remove_tags" => []);
        $users[(int) $cid]->$key = array_merge($users[(int) $cid]->$key, $t1);
    }
    // apply modifications
    $us = new UserStatus($Me, ["send_email" => false]);
    foreach ($users as $cid => $cj) {
        $us->save($cj);
    }
    Dbl::qe("unlock tables");
    Conf::$no_invalidate_caches = false;
    $Conf->invalidate_caches(["pc" => true]);
    // report
    if (!$us->has_error()) {
        $Conf->confirmMsg("Tags saved.");
        unset($qreq->tagact, $qreq->tag);
        SelfHref::redirect($qreq);
    } else
        Conf::msg_error($us->errors());
}

if ($Me->privChair && $Qreq->tagact && $Qreq->post_ok() && isset($papersel)
    && preg_match('/\A[ads]\z/', (string) $Qreq->tagtype))
    do_tags($Qreq);


// set scores to view
if (isset($Qreq->redisplay)) {
    $Conf->save_session("uldisplay", "");
    foreach (ContactList::$folds as $key)
        displayOptionsSet("uldisplay", $key, $Qreq->get("show$key", 0));
    foreach ($Conf->all_review_fields() as $f)
        if ($f->has_options)
            displayOptionsSet("uldisplay", $f->id, $Qreq->get("show{$f->id}", 0));
}
if (isset($Qreq->scoresort))
    $Qreq->scoresort = ListSorter::canonical_short_score_sort($Qreq->scoresort);
if (isset($Qreq->scoresort))
    $Conf->save_session("scoresort", $Qreq->scoresort);


if ($Qreq->t === "pc")
    $title = "Program committee";
else if (str_starts_with($Qreq->t, "#"))
    $title = "#" . substr($Qreq->t, 1) . " program committee";
else
    $title = "Users";
$Conf->header($title, "accounts", ["action_bar" => actionBar("account")]);


$pl = new ContactList($Me, true, $Qreq);
$pl_text = $pl->table_html($Qreq->t, hoturl("users", ["t" => $Qreq->t]),
                     $tOpt[$Qreq->t], 'uldisplay.');


// form
echo "<div class='g'></div>\n";
if (count($tOpt) > 1) {
    echo "<table id='contactsform' class='tablinks1'>
<tr><td><div class='tlx'><div class='tld1'>";

    echo Ht::form(hoturl("users"), ["method" => "get"]);
    if (isset($Qreq->sort))
        echo Ht::hidden("sort", $Qreq->sort);
    echo Ht::select("t", $tOpt, $Qreq->t, ["class" => "want-focus"]),
        " &nbsp;", Ht::submit("Go"), "</form>";

    echo "</div><div class='tld2'>";

    // Display options
    echo Ht::form(hoturl("users"), ["method" => "get"]);
    foreach (array("t", "sort") as $x)
        if (isset($Qreq[$x]))
            echo Ht::hidden($x, $Qreq[$x]);

    echo "<table><tr><td><strong>Show:</strong> &nbsp;</td>
  <td class='pad'>";
    foreach (array("tags" => "Tags",
                   "aff" => "Affiliations", "collab" => "Collaborators",
                   "topics" => "Topics") as $fold => $text)
        if (get($pl->have_folds, $fold) !== null) {
            $k = array_search($fold, ContactList::$folds) + 1;
            echo Ht::checkbox("show$fold", 1, $pl->have_folds[$fold],
                              ["data-fold-target" => "foldul#$k", "class" => "uich js-foldup"]),
                "&nbsp;", Ht::label($text), "<br />\n";
        }
    echo "</td>";
    if (isset($pl->scoreMax)) {
        echo "<td class='pad'>";
        $revViewScore = $Me->permissive_view_score_bound();
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
        $ss = [];
        foreach (ListSorter::score_sort_selector_options() as $k => $v)
            if (in_array($k, ["average", "variance", "maxmin"]))
                $ss[$k] = $v;
        echo "<tr><td colspan='3'><div class='g'></div><b>Sort scores by:</b> &nbsp;",
            Ht::select("scoresort", $ss,
                       ListSorter::canonical_long_score_sort($Conf->session("scoresort", "A"))),
            "</td></tr>";
    }
    echo "</table></form>";

    echo "</div></div></td></tr>\n";

    // Tab selectors
    echo "<tr><td class='tllx'><table><tr>
  <td><div class='tll1'><a class='ui tla' href=''>User selection</a></div></td>
  <td><div class='tll2'><a class='ui tla' href=''>Display options</a></div></td>
</tr></table></td></tr>
</table>\n\n";
}


if ($Me->privChair && $Qreq->t == "pc")
    $Conf->infoMsg("<p><a href='" . hoturl("profile", "u=new&amp;role=pc") . "' class='btn'>Create accounts</a></p><p>Select a PC member’s name to edit their profile or remove them from the PC.</p>");
else if ($Me->privChair && $Qreq->t == "all")
    $Conf->infoMsg("<p><a href='" . hoturl("profile", "u=new") . "' class='btn'>Create accounts</a></p><p>Select a user to edit their profile.  Select " . Ht::img("viewas.png", "[Act as]") . " to view the site as that user would see it.</p>");


if ($pl->any->sel) {
    echo Ht::form(hoturl_post("users", ["t" => $Qreq->t])), "<div>";
    if (isset($Qreq->sort))
        echo Ht::hidden("sort", $Qreq->sort);
}
echo $pl_text;
if ($pl->any->sel)
    echo "</div></form>";


$Conf->footer();
