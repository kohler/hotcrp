<?php
// users.php -- HotCRP people listing/editing page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
require_once("src/contactlist.php");

$Viewer = $Me;
if ($Viewer->contactId && $Viewer->is_disabled()) {
    $Viewer = new Contact(["email" => $Viewer->email], $Conf);
}

$getaction = "";
if (isset($Qreq->get)) {
    $getaction = $Qreq->get;
} else if (isset($Qreq->getgo) && isset($Qreq->getaction)) {
    $getaction = $Qreq->getaction;
}


// list type
$tOpt = [];
if ($Viewer->can_view_pc()) {
    $tOpt["pc"] = "Program committee";
}
foreach ($Conf->viewable_user_tags($Viewer) as $t) {
    if ($t !== "pc")
        $tOpt["#$t"] = "#$t program committee";
}
if ($Viewer->can_view_pc()
    && $Viewer->isPC) {
    $tOpt["admin"] = "System administrators";
}
if ($Viewer->can_view_pc()
    && $Viewer->isPC
    && ($Qreq->t === "pcadmin" || $Qreq->t === "pcadminx")) {
    $tOpt["pcadmin"] = "PC and system administrators";
}
if ($Viewer->privChair
    || ($Viewer->isPC && $Conf->setting("pc_seeallrev"))) {
    $tOpt["re"] = "All reviewers";
    $tOpt["ext"] = "External reviewers";
    $tOpt["extsub"] = "External reviewers who completed a review";
}
if ($Viewer->isPC) {
    $tOpt["req"] = "External reviewers you requested";
}
if ($Viewer->privChair || ($Viewer->isPC && $Conf->subBlindNever())) {
    $tOpt["au"] = "Contact authors of submitted papers";
}
if ($Viewer->privChair
    || ($Viewer->isPC && $Conf->time_pc_view_decision(true))) {
    $tOpt["auacc"] = "Contact authors of accepted papers";
}
if ($Viewer->privChair
    || ($Viewer->isPC && $Conf->subBlindNever() && $Conf->time_pc_view_decision(true))) {
    $tOpt["aurej"] = "Contact authors of rejected papers";
}
if ($Viewer->privChair) {
    $tOpt["auuns"] = "Contact authors of non-submitted papers";
    $tOpt["all"] = "Active users";
}
if (empty($tOpt)) {
    $Viewer->escape();
}
if (isset($Qreq->t) && !isset($tOpt[$Qreq->t])) {
    if (str_starts_with($Qreq->t, "pc:")
        && isset($tOpt["#" . substr($Qreq->t, 3)])) {
        $Qreq->t = "#" . substr($Qreq->t, 3);
    } else if (isset($tOpt["#" . $Qreq->t])) {
        $Qreq->t = "#" . $Qreq->t;
    } else if ($Qreq->t === "#pc") {
        $Qreq->t = "pc";
    } else if ($Qreq->t === "pcadminx" && isset($tOpt["pcadmin"])) {
        $Qreq->t = "pcadmin";
    } else {
        Conf::msg_error("Unknown user collection.");
        unset($Qreq->t);
    }
}
if (!isset($Qreq->t)) {
    $Qreq->t = key($tOpt);
}


// paper selection and download actions
function paperselPredicate($papersel) {
    return "ContactInfo.contactId" . sql_in_int_list($papersel);
}

$Qreq->allow_a("pap");
if (isset($Qreq->pap) && is_string($Qreq->pap)) {
    $Qreq->pap = preg_split('/\s+/', $Qreq->pap);
}
if ((isset($Qreq->pap) && is_array($Qreq->pap))
    || ($getaction && !isset($Qreq->pap))) {
    $allowed_papers = array();
    $pl = new ContactList($Viewer, true);
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
    } else {
        $papersel = array_keys($allowed_papers);
    }
    if (empty($papersel)) {
        unset($papersel);
    }
}

if ($getaction == "nameemail" && isset($papersel) && $Viewer->isPC) {
    $result = $Conf->qe_raw("select * from ContactInfo where " . paperselPredicate($papersel));
    $users = [];
    while (($user = Contact::fetch($result, $Conf))) {
        $users[] = $user;
    }
    Dbl::free($result);

    usort($users, $Conf->user_comparator());
    Contact::ensure_contactdb_users($Conf, $users);

    $texts = [];
    $has_country = false;
    foreach ($users as $u) {
        $texts[] = $line = [
            "first" => $u->firstName,
            "last" => $u->lastName,
            "email" => $u->email,
            "affiliation" => $u->affiliation,
            "country" => $u->country()
        ];
        $has_country = $has_country || $line["country"] !== "";
    }
    $header = ["first", "last", "email", "affiliation"];
    if ($has_country) {
        $header[] = "country";
    }
    csv_exit($Conf->make_csvg("users")->select($header)->append($texts));
}

if ($getaction == "pcinfo" && isset($papersel) && $Viewer->privChair) {
    $result = $Conf->qe_raw("select * from ContactInfo where " . paperselPredicate($papersel));
    $users = [];
    while (($user = Contact::fetch($result, $Conf))) {
        $users[] = $user;
    }
    Dbl::free($result);

    usort($users, $Conf->user_comparator());
    Contact::load_topic_interests($users);
    Contact::ensure_contactdb_users($Conf, $users);

    // NB This format is expected to be parsed by profile.php's bulk upload.
    $tagger = new Tagger($Viewer);
    $people = [];
    $has_preferred_email = $has_tags = $has_topics =
        $has_phone = $has_country = $has_disabled = false;
    $has = (object) [];
    foreach ($users as $user) {
        $row = [
            "first" => $user->firstName,
            "last" => $user->lastName,
            "email" => $user->email,
            "affiliation" => $user->affiliation,
            "country" => $user->country(),
            "phone" => $user->phone,
            "disabled" => $user->is_disabled() ? "yes" : "",
            "collaborators" => rtrim($user->collaborators())
        ];
        $has_country = $has_country || $row["country"] !== "";
        $has_phone = $has_phone || ($row["phone"] ?? "") !== "";
        $has_disabled = $has_disabled || $user->is_disabled();
        if ($user->preferredEmail && $user->preferredEmail !== $user->email) {
            $row["preferred_email"] = $user->preferredEmail;
            $has_preferred_email = true;
        }
        if ($user->contactTags) {
            $row["tags"] = $tagger->unparse($user->contactTags);
            $has_tags = $has_tags || $row["tags"] !== "";
        }
        foreach ($user->topic_interest_map() as $t => $i) {
            $row["topic$t"] = $i;
            $has_topics = true;
        }
        $f = [];
        if ($user->defaultWatch & Contact::WATCH_REVIEW) {
            $f[] = "reviews";
        }
        if (($user->defaultWatch & Contact::WATCH_REVIEW_ALL)
            && ($user->roles & Contact::ROLE_PCLIKE)) {
            $f[] = "allreviews";
        }
        if ($user->defaultWatch & Contact::WATCH_REVIEW_MANAGED) {
            $f[] = "adminreviews";
        }
        if ($user->defaultWatch & Contact::WATCH_FINAL_SUBMIT_ALL) {
            $f[] = "allfinal";
        }
        $row["follow"] = empty($f) ? "none" : join(" ", $f);
        if ($user->roles & (Contact::ROLE_PC | Contact::ROLE_ADMIN | Contact::ROLE_CHAIR)) {
            $r = array();
            if ($user->roles & Contact::ROLE_CHAIR) {
                $r[] = "chair";
            }
            if ($user->roles & Contact::ROLE_PC) {
                $r[] = "pc";
            }
            if ($user->roles & Contact::ROLE_ADMIN) {
                $r[] = "sysadmin";
            }
            $row["roles"] = join(" ", $r);
        } else {
            $row["roles"] = "";
        }
        $people[] = $row;
    }

    $header = ["first", "last", "email", "affiliation"];
    if ($has_country) {
        $header[] = "country";
    }
    if ($has_phone) {
        $header[] = "phone";
    }
    if ($has_disabled) {
        $header[] = "disabled";
    }
    if ($has_preferred_email) {
        $header[] = "preferred_email";
    }
    $header[] = "roles";
    if ($has_tags) {
        $header[] = "tags";
    }
    $header[] = "collaborators";
    $header[] = "follow";
    $selection = $header;
    if ($has_topics) {
        foreach ($Conf->topic_set() as $t => $tn) {
            $header[] = "topic: " . $tn;
            $selection[] = "topic$t";
        }
    }
    csv_exit($Conf->make_csvg("pcinfo")->select($selection, $header)->append($people));
}


// modifications
function modify_confirm($j, $ok_message, $ok_message_optional) {
    global $Conf;
    if (($j->ok ?? false) && ($j->warnings ?? false)) {
        $Conf->warnMsg("<div>" . join('</div><div style="margin-top:0.5em">', $j->warnings) . "</div>");
    }
    if (($j->ok ?? false)
        && $ok_message
        && (!$ok_message_optional || !($j->warnings ?? false))
        && (!isset($j->users) || !empty($j->users))) {
        $Conf->confirmMsg($ok_message);
    }
}

if ($Viewer->privChair && $Qreq->modifygo && $Qreq->valid_post() && isset($papersel)) {
    if ($Qreq->modifytype == "disableaccount") {
        modify_confirm(UserActions::disable($Viewer, $papersel), "Accounts disabled.", true);
    } else if ($Qreq->modifytype == "enableaccount") {
        modify_confirm(UserActions::enable($Viewer, $papersel), "Accounts enabled.", true);
    } else if ($Qreq->modifytype == "sendaccount") {
        modify_confirm(UserActions::send_account_info($Viewer, $papersel), "Account information sent.", false);
    }
    unset($Qreq->modifygo, $Qreq->modifytype);
    $Conf->redirect_self($Qreq);
}

function do_tags($qreq) {
    global $Conf, $Viewer, $papersel;
    // check tags
    $tagger = new Tagger($Viewer);
    $t1 = array();
    $errors = array();
    foreach (preg_split('/[\s,]+/', (string) $qreq->tag) as $t) {
        if ($t === "") {
            /* nada */
        } else if (!($t = $tagger->check($t, Tagger::NOPRIVATE))) {
            $errors[] = $tagger->error_html;
        } else if (Tagger::base($t) === "pc") {
            $errors[] = "The “pc” user tag is set automatically for all PC members.";
        } else {
            $t1[] = $t;
        }
    }
    if (count($errors)) {
        return Conf::msg_error(join("<br>", $errors));
    } else if (!count($t1)) {
        return $Conf->warnMsg("Nothing to do.");
    }

    // modify database
    Dbl::qe("lock tables ContactInfo write, ActionLog write");
    Conf::$no_invalidate_caches = true;
    $users = array();
    if ($qreq->tagtype === "s") {
        // erase existing tags
        $likes = array();
        $removes = array();
        foreach ($t1 as $t) {
            list($tag, $index) = Tagger::unpack($t);
            $removes[] = $t;
            $likes[] = "contactTags like " . Dbl::utf8ci("'% " . sqlq_for_like($tag) . "#%'");
        }
        foreach (Dbl::fetch_first_columns(Dbl::qe("select contactId from ContactInfo where " . join(" or ", $likes))) as $cid) {
            $users[(int) $cid] = (object) array("id" => (int) $cid, "add_tags" => [], "remove_tags" => $removes);
        }
    }
    // account for request
    $key = $qreq->tagtype === "d" ? "remove_tags" : "add_tags";
    foreach ($papersel as $cid) {
        if (!isset($users[(int) $cid])) {
            $users[(int) $cid] = (object) array("id" => (int) $cid, "add_tags" => [], "remove_tags" => []);
        }
        $users[(int) $cid]->$key = array_merge($users[(int) $cid]->$key, $t1);
    }
    // apply modifications
    $us = new UserStatus($Viewer);
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
        $Conf->redirect_self($qreq);
    } else {
        Conf::msg_error($us->error_texts());
    }
}

if ($Viewer->privChair && $Qreq->tagact && $Qreq->valid_post() && isset($papersel)
    && preg_match('/\A[ads]\z/', (string) $Qreq->tagtype)) {
    do_tags($Qreq);
}


// set scores to view
if (isset($Qreq->redisplay)) {
    $sv = [];
    foreach (ContactList::$folds as $key) {
        $sv[] = "uldisplay.$key=" . ($Qreq->get("show$key") ? 0 : 1);
    }
    foreach ($Conf->all_review_fields() as $f) {
        if ($Qreq["has_show{$f->id}"])
            $sv[] = "uldisplay.{$f->id}=" . ($Qreq->get("show{$f->id}") ? 0 : 1);
    }
    if (isset($Qreq->scoresort)) {
        $sv[] = "ulscoresort=" . ListSorter::canonical_short_score_sort($Qreq->scoresort);
    }
    Session_API::setsession($Viewer, join(" ", $sv));
    $Conf->redirect_self($Qreq);
}

if ($Qreq->t === "pc") {
    $title = "Program committee";
} else if (str_starts_with($Qreq->t, "#")) {
    $title = "#" . substr($Qreq->t, 1) . " program committee";
} else {
    $title = "Users";
}
$Conf->header($title, "users", ["action_bar" => actionBar("account")]);


$pl = new ContactList($Viewer, true, $Qreq);
$pl_text = $pl->table_html($Qreq->t, $Conf->hoturl("users", ["t" => $Qreq->t]),
                     $tOpt[$Qreq->t], 'uldisplay.');


// form
echo '<hr class="g">';
if (count($tOpt) > 1) {
    echo '<table id="contactsform">
<tr><td><div class="tlx"><div class="tld is-tla active" id="tla-default">';

    echo Ht::form($Conf->hoturl("users"), ["method" => "get"]);
    if (isset($Qreq->sort)) {
        echo Ht::hidden("sort", $Qreq->sort);
    }
    echo Ht::select("t", $tOpt, $Qreq->t, ["class" => "want-focus"]),
        " &nbsp;", Ht::submit("Go"), "</form>";

    echo '</div><div class="tld is-tla" id="tla-view">';

    // Display options
    echo Ht::form($Conf->hoturl("users"), ["method" => "get"]);
    foreach (array("t", "sort") as $x) {
        if (isset($Qreq[$x]))
            echo Ht::hidden($x, $Qreq[$x]);
    }

    echo '<table><tr><td><strong>Show:</strong> &nbsp;</td>
  <td class="pad">';
    foreach (array("tags" => "Tags",
                   "aff" => "Affiliations", "collab" => "Collaborators",
                   "topics" => "Topics") as $fold => $text) {
        if (($pl->have_folds[$fold] ?? null) !== null) {
            $k = array_search($fold, ContactList::$folds) + 1;
            echo Ht::checkbox("show$fold", 1, $pl->have_folds[$fold],
                              ["data-fold-target" => "foldul#$k", "class" => "uich js-foldup"]),
                "&nbsp;", Ht::label($text), "<br />\n";
        }
    }
    echo "</td>";
    if (isset($pl->scoreMax)) {
        echo '<td class="pad">';
        $revViewScore = $Viewer->permissive_view_score_bound();
        $uldisplay = $Viewer->session("uldisplay", " tags overAllMerit ");
        foreach ($Conf->all_review_fields() as $f)
            if ($f->view_score > $revViewScore
                && $f->has_options
                && $f->main_storage) {
                $checked = strpos($uldisplay, $f->id) !== false;
                echo Ht::checkbox("show{$f->id}", 1, $checked),
                    "&nbsp;", Ht::label($f->name_html),
                    Ht::hidden("has_show{$f->id}", 1), "<br />";
            }
        echo "</td>";
    }
    echo "<td>", Ht::submit("redisplay", "Redisplay"), "</td></tr>\n";
    if (isset($pl->scoreMax)) {
        $ss = [];
        foreach (ListSorter::score_sort_selector_options() as $k => $v) {
            if (in_array($k, ["average", "variance", "maxmin"]))
                $ss[$k] = $v;
        }
        echo '<tr><td colspan="3"><hr class="g"><b>Sort scores by:</b> &nbsp;',
            Ht::select("scoresort", $ss,
                       ListSorter::canonical_long_score_sort($Viewer->session("ulscoresort", "A"))),
            "</td></tr>";
    }
    echo "</table></form>";

    echo "</div></div></td></tr>\n";

    // Tab selectors
    echo '<tr><td class="tllx"><table><tr>
  <td><div class="tll active"><a class="ui tla" href="">User selection</a></div></td>
  <td><div class="tll"><a class="ui tla" href="#view">View options</a></div></td>
</tr></table></td></tr>
</table>', "\n\n";
}


if ($Viewer->privChair && $Qreq->t == "pc") {
    $Conf->infoMsg('<p><a href="' . $Conf->hoturl("profile", "u=new&amp;role=pc") . '" class="btn">Create accounts</a></p>Select a PC member’s name to edit their profile or remove them from the PC.');
} else if ($Viewer->privChair && $Qreq->t == "all") {
    $Conf->infoMsg('<p><a href="' . $Conf->hoturl("profile", "u=new") . '" class="btn">Create accounts</a></p>Select a user to edit their profile.  Select ' . Ht::img("viewas.png", "[Act as]") . ' to view the site as that user would see it.');
}


if ($pl->any->sel) {
    echo Ht::form($Conf->hoturl_post("users", ["t" => $Qreq->t])),
        Ht::hidden("defaultact", "", ["id" => "defaultact"]),
        Ht::hidden_default_submit("default", 1);
    if (isset($Qreq->sort)) {
        echo Ht::hidden("sort", $Qreq->sort);
    }
}
echo Ht::unstash(), $pl_text;
if ($pl->any->sel) {
    echo "</form>";
}


$Conf->footer();
