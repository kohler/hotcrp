<?php
// profile.php -- HotCRP profile management page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

// check for change-email capabilities
function change_email_by_capability($Qreq) {
    global $Conf, $Me;
    $capmgr = $Conf->capability_manager();
    $capdata = $capmgr->check($Qreq->changeemail);
    if (!$capdata
        || $capdata->capabilityType != CAPTYPE_CHANGEEMAIL
        || !($capdata->data = json_decode($capdata->data))
        || !get($capdata->data, "uemail"))
        error_go(false, "That email change code has expired, or you didn’t enter it correctly.");

    if ($capdata->contactId)
        $Acct = $Conf->user_by_id($capdata->contactId);
    else
        error_go(false, "That email change code was created improperly due to a server error. Please create another email change code, or sign out of your current account and create a new account using your preferred email address.");

    if (!$Acct)
        error_go(false, "No such account.");
    else if (isset($capdata->data->oldemail)
             && strcasecmp($Acct->email, $capdata->data->oldemail))
        error_go(false, "You have changed your email address since creating that email change code.");

    $email = $capdata->data->uemail;
    if ($Conf->user_id_by_email($email))
        error_go(false, "Email address “" . htmlspecialchars($email) . "” is already in use. You may want to <a href=\"" . hoturl("mergeaccounts") . "\">merge these accounts</a>.");

    $Acct->change_email($email);
    $capmgr->delete($capdata);

    $Conf->confirmMsg("Your email address has been changed.");
    if (!$Me->has_database_account() || $Me->contactId == $Acct->contactId)
        $Me = $Acct->activate($Qreq);
}
if ($Qreq->changeemail)
    change_email_by_capability($Qreq);

if (!$Me->has_email())
    $Me->escape();
$newProfile = false;
$useRequest = false;
$UserStatus = new UserStatus($Me);

if ($Qreq->u === null) {
    if ($Qreq->user)
        $Qreq->u = $Qreq->user;
    else if ($Qreq->contact)
        $Qreq->u = $Qreq->contact;
    else if (preg_match(',\A/(?:new|[^\s/]+)\z,i', Navigation::path()))
        $Qreq->u = substr(Navigation::path(), 1);
}
if ($Me->privChair && $Qreq->new)
    $Qreq->u = "new";


// Load user.
$Acct = $Me;
if ($Me->privChair && ($Qreq->u || $Qreq->search)) {
    if ($Qreq->u === "new") {
        $Acct = new Contact(null, $Conf);
        $newProfile = true;
    } else if (($id = cvtint($Qreq->u)) > 0)
        $Acct = $Conf->user_by_id($id);
    else if ($Qreq->u === "" && $Qreq->search)
        Navigation::redirect_site("users");
    else {
        $Acct = $Conf->user_by_email($Qreq->u);
        if (!$Acct && $Qreq->search) {
            $cs = new ContactSearch(ContactSearch::F_USER, $Qreq->u, $Me);
            if ($cs->ids) {
                $Acct = $Conf->user_by_id($cs->ids[0]);
                $list = new SessionList("u/all/" . urlencode($Qreq->search), $cs->ids, "“" . htmlspecialchars($Qreq->u) . "”", hoturl_site_relative_raw("users", ["t" => "all"]));
                $list->set_cookie();
                $Qreq->u = $Acct->email;
                SelfHref::redirect($Qreq);
            }
        }
    }
}

// Redirect if requested user isn't loaded user.
if (!$Acct
    || ($Qreq->u !== null
        && $Qreq->u !== (string) $Acct->contactId
        && strcasecmp($Qreq->u, $Acct->email)
        && ($Acct->contactId || $Qreq->u !== "new"))
    || (isset($Qreq->profile_contactid)
        && $Qreq->profile_contactid !== (string) $Acct->contactId)) {
    if (!$Acct)
        Conf::msg_error("Invalid user.");
    else if (isset($Qreq->save) || isset($Qreq->savebulk))
        Conf::msg_error("You’re logged in as a different user now, so your changes were ignored.");
    unset($Qreq->u, $Qreq->save, $Qreq->savebulk);
    SelfHref::redirect($Qreq);
}

$need_highlight = false;
if (($Acct->contactId != $Me->contactId || !$Me->has_database_account())
    && $Acct->has_email()
    && !$Acct->firstName && !$Acct->lastName && !$Acct->affiliation
    && !$Qreq->post) {
    $result = $Conf->qe_raw("select Paper.paperId, authorInformation from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$Acct->contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")");
    while (($prow = PaperInfo::fetch($result, $Me)))
        foreach ($prow->author_list() as $au)
            if (strcasecmp($au->email, $Acct->email) == 0
                && ($au->firstName || $au->lastName || $au->affiliation)) {
                if (!$Acct->firstName && $au->firstName) {
                    $Acct->firstName = $au->firstName;
                    $need_highlight = true;
                }
                if (!$Acct->lastName && $au->lastName) {
                    $Acct->lastName = $au->lastName;
                    $need_highlight = true;
                }
                if (!$Acct->affiliation && $au->affiliation) {
                    $Acct->affiliation = $au->affiliation;
                    $need_highlight = true;
                }
            }
}


function save_user($cj, $user_status, $Acct, $allow_modification) {
    global $Conf, $Me, $Now, $newProfile;
    if ($newProfile)
        $Acct = null;

    // check for missing fields
    UserStatus::normalize_name($cj);
    if ($newProfile && !isset($cj->email)) {
        $user_status->error_at("email", "Email address required.");
        return false;
    }

    // check email
    if ($newProfile || strcasecmp($cj->email, $Acct->email)) {
        if ($Acct && $Acct->data("locked"))
            return $user_status->error_at("email", "This account is locked, so you can’t change its email address.");
        else if (($new_acct = $Conf->user_by_email($cj->email))) {
            if ($allow_modification)
                $cj->id = $new_acct->contactId;
            else {
                $msg = "Email address “" . htmlspecialchars($cj->email) . "” is already in use.";
                if ($Me->privChair)
                    $msg = str_replace("an account", "<a href=\"" . hoturl("profile", "u=" . urlencode($cj->email)) . "\">an account</a>", $msg);
                if (!$newProfile)
                    $msg .= " You may want to <a href=\"" . hoturl("mergeaccounts") . "\">merge these accounts</a>.";
                return $user_status->error_at("email", $msg);
            }
        } else if ($Conf->external_login()) {
            if ($cj->email === "")
                return $user_status->error_at("email", "Not a valid username.");
        } else if ($cj->email === "") {
            return $user_status->error_at("email", "You must supply an email address.");
        } else if (!validate_email($cj->email)) {
            return $user_status->error_at("email", "“" . htmlspecialchars($cj->email) . "” is not a valid email address.");
        } else if ($Acct && !$Acct->has_database_account()) {
            return $user_status->error_at("email", "Your current account is only active on other HotCRP.com sites. Due to a server limitation, you can’t change your email until activating your account on this site.");
        }
        if (!$newProfile && !$Me->privChair) {
            $old_preferredEmail = $Acct->preferredEmail;
            $Acct->preferredEmail = $cj->email;
            $capmgr = $Conf->capability_manager();
            $rest = array("capability" => $capmgr->create(CAPTYPE_CHANGEEMAIL, array("user" => $Acct, "timeExpires" => $Now + 259200, "data" => json_encode_db(array("oldemail" => $Acct->email, "uemail" => $cj->email)))));
            $mailer = new HotCRPMailer($Conf, $Acct, null, $rest);
            $prep = $mailer->make_preparation("@changeemail", $rest);
            if ($prep->sendable) {
                $prep->send();
                $Conf->warnMsg("Mail has been sent to " . htmlspecialchars($cj->email) . ". Use the link it contains to confirm your email change request.");
            } else
                Conf::msg_error("Mail cannot be sent to " . htmlspecialchars($cj->email) . " at this time. Your email address was unchanged.");
            // Save changes *except* for new email, by restoring old email.
            $cj->email = $Acct->email;
            $Acct->preferredEmail = $old_preferredEmail;
        }
    }

    // save account
    return $user_status->save($cj, $Acct);
}


function parseBulkFile($text, $filename) {
    global $Conf, $Me;
    $text = cleannl(convert_to_utf8($text));
    $filename = $filename ? "$filename:" : "line ";
    $success = $errors = array();

    if (!preg_match('/\A[^\r\n]*(?:,|\A)(?:user|email)(?:[,\r\n]|\z)/', $text)
        && !preg_match('/\A[^\r\n]*,[^\r\n]*,/', $text)) {
        $tarr = CsvParser::split_lines($text);
        foreach ($tarr as &$t) {
            if (($t = trim($t)) && $t[0] !== "#" && $t[0] !== "%")
                $t = CsvGenerator::quote($t);
            $t .= "\n";
        }
        unset($t);
        $text = join("", $tarr);
    }

    $csv = new CsvParser($text);
    $csv->set_comment_chars("#%");
    if (($line = $csv->next())) {
        $lcline = array_map(function ($a) { return strtolower(trim($a)); }, $line);
        if (array_search("email", $lcline) !== false
            || array_search("user", $lcline) !== false)
            $csv->set_header($lcline);
        else if (count($line) == 1) {
            $csv->set_header(["user"]);
            $csv->unshift($line);
        } else {
            // interpolate a likely header
            $lcline = [];
            for ($i = 0; $i < count($line); ++$i)
                if (validate_email($line[$i]) && array_search("email", $lcline) === false)
                    $lcline[] = "email";
                else if (strpos($line[$i], " ") !== false && array_search("name", $lcline) === false)
                    $lcline[] = "name";
                else if (array_search($line[$i], ["pc", "chair"]) !== false && array_search("roles", $lcline) === false)
                    $lcline[] = "roles";
                else if (array_search("name", $lcline) !== false && array_search("affiliation", $lcline) === false)
                    $lcline[] = "affiliation";
                else
                    $lcline[] = "unknown";
            $csv->set_header($lcline);
            $csv->unshift($line);
            $errors[] = "<span class='lineno'>" . $filename . $csv->lineno() . ":</span> Header missing, assuming “<code>" . join(",", $lcline) . "</code>”";
        }

    }

    $saved_users = [];
    $ustatus = new UserStatus($Me, ["send_email" => true, "no_deprivilege_self" => true]);

    while (($line = $csv->next()) !== false) {
        $ustatus->set_user(new Contact(null, $Conf));
        $ustatus->clear_messages();
        $cj = (object) ["id" => null];
        $ustatus->parse_csv_group("", $cj, $line);

        if (isset($cj->email) && isset($saved_users[strtolower($cj->email)])) {
            $errors[] = '<span class="lineno">' . $filename . $csv->lineno() . ":</span> Already saved a user with email “" . htmlspecialchars($cj->email) . "”.";
            $errors[] = '<span class="lineno">' . $filename . $saved_users[strtolower($cj->email)] . ":</span> (That user was saved here.)";
            continue;
        }

        if (isset($cj->email) && $cj->email !== "")
            $saved_users[strtolower($cj->email)] = $csv->lineno();
        if (($saved_user = save_user($cj, $ustatus, null, true)))
            $success[] = "<a href=\"" . hoturl("profile", "u=" . urlencode($saved_user->email)) . "\">"
                . Text::user_html_nolink($saved_user) . "</a>";
        foreach ($ustatus->errors() as $e)
            $errors[] = '<span class="lineno">' . $filename . $csv->lineno() . ":</span> " . $e;
    }

    if (!empty($ustatus->unknown_topics))
        $errors[] = "There were unrecognized topics (" . htmlspecialchars(commajoin(array_keys($ustatus->unknown_topics))) . ").";
    if (count($success) == 1)
        $successMsg = "Saved account " . $success[0] . ".";
    else if (count($success))
        $successMsg = "Saved " . plural($success, "account") . ": " . commajoin($success) . ".";
    if (count($errors))
        $errorMsg = "<div class='parseerr'><p>" . join("</p>\n<p>", $errors) . "</p></div>";
    if (count($success) && count($errors))
        $Conf->confirmMsg($successMsg . "<br />$errorMsg");
    else if (count($success))
        $Conf->confirmMsg($successMsg);
    else if (count($errors))
        Conf::msg_error($errorMsg);
    else
        $Conf->warnMsg("Nothing to do.");
    return empty($errors);
}

if (!$Qreq->post_ok())
    /* do nothing */;
else if ($Qreq->savebulk && $newProfile && $Qreq->has_file("bulk")) {
    if (($text = $Qreq->file_contents("bulk")) === false)
        Conf::msg_error("Internal error: cannot read file.");
    else
        parseBulkFile($text, $Qreq->file_filename("bulk"));
    $Qreq->bulkentry = "";
    SelfHref::redirect($Qreq, ["anchor" => "bulk"]);
} else if ($Qreq->savebulk && $newProfile) {
    $success = true;
    if ($Qreq->bulkentry && $Qreq->bulkentry !== "Enter users one per line")
        $success = parseBulkFile($Qreq->bulkentry, "");
    if (!$success)
        $Conf->save_session("profile_bulkentry", array($Now, $Qreq->bulkentry));
    SelfHref::redirect($Qreq, ["anchor" => "bulk"]);
} else if (isset($Qreq->save)) {
    assert($Acct->is_empty() === $newProfile);
    $cj = (object) ["id" => $Acct->has_database_account() ? $Acct->contactId : "new"];
    $UserStatus->set_user($Acct);
    $UserStatus->parse_request_group("", $cj, $Qreq);
    if ($newProfile)
        $UserStatus->send_email = true;
    $saved_user = save_user($cj, $UserStatus, $Acct, false);
    if (!$UserStatus->has_error()) {
        if ($UserStatus->has_messages())
            $Conf->msg($UserStatus->problem_status(), $UserStatus->messages());
        if ($newProfile) {
            $Conf->msg("xconfirm", "Created an account for <a href=\"" . hoturl("profile", "u=" . urlencode($saved_user->email)) . "\">" . Text::user_html_nolink($saved_user) . "</a>. A password has been emailed to that address. You may now create another account.");
        } else {
            $Conf->msg("xconfirm", "Profile updated.");
            if ($Acct->contactId != $Me->contactId)
                $Qreq->u = $Acct->email;
        }
        if (isset($Qreq->redirect))
            go(hoturl("index"));
        else {
            $xcj = [];
            if ($newProfile) {
                foreach (["roles", "follow", "tags"] as $k)
                    if (isset($cj->$k))
                        $xcj[$k] = $cj->$k;
            }
            if ($UserStatus->has_warning())
                $xcj["warning_fields"] = $UserStatus->problem_fields();
            $Conf->save_session("profile_redirect", $xcj);
            SelfHref::redirect($Qreq);
        }
    }
} else if (isset($Qreq->merge) && !$newProfile
           && $Acct->contactId == $Me->contactId)
    go(hoturl("mergeaccounts"));

function databaseTracks($who) {
    global $Conf;
    $tracks = (object) array("soleAuthor" => array(),
                             "author" => array(),
                             "review" => array(),
                             "comment" => array());

    // find authored papers
    $result = $Conf->qe_raw("select Paper.paperId, count(pc.contactId)
        from Paper
        join PaperConflict c on (c.paperId=Paper.paperId and c.contactId=$who and c.conflictType>=" . CONFLICT_AUTHOR . ")
        join PaperConflict pc on (pc.paperId=Paper.paperId and pc.conflictType>=" . CONFLICT_AUTHOR . ")
        group by Paper.paperId order by Paper.paperId");
    while (($row = edb_row($result))) {
        if ($row[1] == 1)
            $tracks->soleAuthor[] = $row[0];
        $tracks->author[] = $row[0];
    }

    // find reviews
    $result = $Conf->qe_raw("select paperId from PaperReview
        where PaperReview.contactId=$who
        group by paperId order by paperId");
    while (($row = edb_row($result)))
        $tracks->review[] = $row[0];

    // find comments
    $result = $Conf->qe_raw("select paperId from PaperComment
        where PaperComment.contactId=$who
        group by paperId order by paperId");
    while (($row = edb_row($result)))
        $tracks->comment[] = $row[0];

    return $tracks;
}

function textArrayPapers($pids) {
    return commajoin(preg_replace('/(\d+)/', "<a href='" . hoturl("paper", "p=\$1&amp;ls=" . join("+", $pids)) . "'>\$1</a>", $pids));
}

if (isset($Qreq->delete) && !Dbl::has_error() && $Qreq->post_ok()) {
    if (!$Me->privChair)
        Conf::msg_error("Only administrators can delete accounts.");
    else if ($Acct->contactId == $Me->contactId)
        Conf::msg_error("You aren’t allowed to delete your own account.");
    else if ($Acct->has_database_account()) {
        $tracks = databaseTracks($Acct->contactId);
        if (!empty($tracks->soleAuthor))
            Conf::msg_error("This account can’t be deleted since it is sole contact for " . pluralx($tracks->soleAuthor, "paper") . " " . textArrayPapers($tracks->soleAuthor) . ". You will be able to delete the account after deleting those papers or adding additional paper contacts.");
        else if ($Acct->data("locked"))
            Conf::msg_error("This account is locked and can’t be deleted.");
        else {
            $Conf->q("insert into DeletedContactInfo set contactId=?, firstName=?, lastName=?, unaccentedName=?, email=?", $Acct->contactId, $Acct->firstName, $Acct->lastName, $Acct->unaccentedName, $Acct->email);
            foreach (array("ContactInfo",
                           "PaperComment", "PaperConflict", "PaperReview",
                           "PaperReviewPreference", "PaperReviewRefused",
                           "PaperWatch", "ReviewRating", "TopicInterest")
                     as $table)
                $Conf->qe_raw("delete from $table where contactId=$Acct->contactId");
            // delete twiddle tags
            $assigner = new AssignmentSet($Me, true);
            $assigner->parse("paper,tag\nall,{$Acct->contactId}~all#clear\n");
            $assigner->execute();
            // clear caches
            if ($Acct->isPC || $Acct->privChair)
                $Conf->invalidate_caches(["pc" => 1]);
            // done
            $Conf->confirmMsg("Permanently deleted account " . htmlspecialchars($Acct->email) . ".");
            $Me->log_activity_for($Acct, "Permanently deleted account " . htmlspecialchars($Acct->email));
            go(hoturl("users", "t=all"));
        }
    }
}

function echo_modes($hlbulk) {
    global $Me, $Acct, $newProfile;
    echo '<div class="psmode">',
        '<div class="', ($hlbulk == 0 ? "papmodex" : "papmode"), '">',
        Ht::link($newProfile || $Me->email == $Acct->email ? "Your profile" : "Profile", selfHref(["u" => null])),
        '</div><div class="', ($hlbulk == 1 ? "papmodex" : "papmode"), '">';
    if ($newProfile)
        echo Ht::link("New account", "", ["class" => "ui tla has-focus-history", "data-fold-target" => "9c"]);
    else
        echo Ht::link("New account", hoturl("profile", "u=new"));
    echo '</div><div class="', ($hlbulk == 2 ? "papmodex" : "papmode"), '">';
    if ($newProfile)
        echo Ht::link("Bulk update", "#bulk", ["class" => "ui tla has-focus-history", "data-fold-target" => "9o"]);
    else
        echo Ht::link("Bulk update", hoturl("profile", "u=new#bulk"));
    echo '</div></div><hr class="c" style="margin-bottom:24px" />', "\n";
}


// set session list
if (!$newProfile
    && isset($_COOKIE["hotlist-info"])
    && ($list = SessionList::decode_info_string($_COOKIE["hotlist-info"]))
    && $list->list_type() === "u"
    && $list->set_current_id($Acct->contactId)) {
    $Conf->set_active_list($list);
}

if ($newProfile) {
    $title = "User update";
} else if (strcasecmp($Me->email, $Acct->email) == 0) {
    $title = "Profile";
} else {
    $title = $Me->name_html_for($Acct) . " profile";
}
$Conf->header($title, "account", ["action_bar" => actionBar("account")]);

$useRequest = !$Acct->has_database_account() && isset($Qreq->watchreview);
if ($UserStatus->has_error())
    $need_highlight = $useRequest = true;

if (!$UserStatus->has_error() && $Conf->session("freshlogin") === "redirect")
    $Conf->save_session("freshlogin", null);
// Set warnings
if (!$newProfile) {
    if (!$Acct->firstName && !$Acct->lastName) {
        $UserStatus->warning_at("firstName", "Please enter your name.");
        $UserStatus->warning_at("lastName", false);
    }
    if (!$Acct->affiliation)
        $UserStatus->warning_at("affiliation", "Please enter your affiliation (use “None” or “Unaffiliated” if you have none).");
    if ($Acct->is_pc_member()) {
        if (!$Acct->collaborators)
            $UserStatus->warning_at("collaborators", "Please enter your recent collaborators and other affiliations. This information can help detect conflicts of interest. Enter “None” if you have none.");
        if ($Conf->topic_map() && !$Acct->topic_interest_map())
            $UserStatus->warning_at("topics", "Please enter your topic interests. We use topic interests to improve the paper assignment process.");
    }
}


$UserStatus->set_user($Acct);
$userj = $UserStatus->user_json(["include_password" => true]);
if (!$useRequest && $Me->privChair && $Acct->is_empty()
    && ($Qreq->role === "chair" || $Qreq->role === "pc")) {
    $userj->roles = (object) [$Qreq->role => true];
}

if ($useRequest) {
    $UserStatus->ignore_msgs = true;
    $formcj = (object) ["id" => $Acct->has_database_account() ? $Acct->contactId : "new"];
    $UserStatus->parse_request_group("", $formcj, $Qreq);
} else {
    $formcj = $userj;
}
if (($prdj = $Conf->session("profile_redirect"))) {
    $Conf->save_session("profile_redirect", null);
    foreach ($prdj as $k => $v) {
        if ($k === "warning_fields") {
            foreach ($v as $k)
                $UserStatus->warning_at($k, null);
        } else {
            $formcj->$k = $v;
        }
    }
}

$form_params = array();
if ($newProfile)
    $form_params[] = "u=new";
else if ($Me->contactId != $Acct->contactId)
    $form_params[] = "u=" . urlencode($Acct->email);
if (isset($Qreq->ls))
    $form_params[] = "ls=" . urlencode($Qreq->ls);
if ($newProfile)
    echo '<div id="foldbulk" class="fold9' . ($Qreq->savebulk ? "o" : "c") . ' js-fold-focus"><div class="fn9">';

echo Ht::form(hoturl_post("profile", join("&amp;", $form_params)),
              array("id" => "profile-form")),
    // Don't want chrome to autofill the password changer.
    // But chrome defaults to autofilling the password changer
    // unless we supply an earlier password input.
    Ht::password("chromefooler", "", ["class" => "ignore-diff hidden"]),
    Ht::hidden("profile_contactid", $Acct->contactId);
if (isset($Qreq->redirect))
    echo Ht::hidden("redirect", $Qreq->redirect);
if ($Me->privChair)
    echo Ht::hidden("whichpassword", "");

if ($newProfile)
    echo_modes(1);
else if ($Me->privChair)
    echo_modes(0);

if ($UserStatus->has_messages()) {
    $status = 0;
    $msgs = [];
    foreach ($UserStatus->messages(true) as $m) {
        $status = max($m[2], $status);
        $msgs[] = '<div class="mmm">' . $m[1] . '</div>';
    }
    echo '<div class="msgs-wide">', Ht::xmsg($status, join("", $msgs)), "</div>\n";
}

echo '<div id="foldaccount" class="';
if (isset($formcj->roles)
    && (isset($formcj->roles->pc) || isset($formcj->roles->chair)))
    echo "fold1o fold2o";
else if (isset($formcj->roles) && isset($formcj->roles->sysadmin))
    echo "fold1c fold2o";
else
    echo "fold1c fold2c";
echo "\">\n";


$UserStatus->set_user($Acct);
$UserStatus->render_group("", $userj, $formcj);

if ($UserStatus->global_user() && false) {
    echo '<div class="profile-g"><div class="checki"><label><span class="checkc">',
        Ht::checkbox("saveglobal", 1, $useRequest ? !!$Qreq->saveglobal : true, ["class" => "ignore-diff"]),
        '</span>Update global profile</label></div></div>';
}

$buttons = [Ht::submit("save", $newProfile ? "Create account" : "Save changes", ["class" => "btn btn-primary"]),
    Ht::submit("cancel", "Cancel", ["class" => "btn"])];

if ($Me->privChair && !$newProfile && $Me->contactId != $Acct->contactId) {
    $tracks = databaseTracks($Acct->contactId);
    $args = ["class" => "btn ui"];
    if (!empty($tracks->soleAuthor)) {
        $args["class"] .= " js-cannot-delete-user";
        $args["data-sole-author"] = pluralx($tracks->soleAuthor, "paper") . " " . textArrayPapers($tracks->soleAuthor);
    } else {
        $args["class"] .= " js-delete-user";
        $x = $y = array();
        if (!empty($tracks->author)) {
            $x[] = "contact for " . pluralx($tracks->author, "paper") . " " . textArrayPapers($tracks->author);
            $y[] = "delete " . pluralx($tracks->author, "this") . " " . pluralx($tracks->author, "authorship association");
        }
        if (!empty($tracks->review)) {
            $x[] = "reviewer for " . pluralx($tracks->review, "paper") . " " . textArrayPapers($tracks->review);
            $y[] = "<strong>permanently delete</strong> " . pluralx($tracks->review, "this") . " " . pluralx($tracks->review, "review");
        }
        if (!empty($tracks->comment)) {
            $x[] = "commenter for " . pluralx($tracks->comment, "paper") . " " . textArrayPapers($tracks->comment);
            $y[] = "<strong>permanently delete</strong> " . pluralx($tracks->comment, "this") . " " . pluralx($tracks->comment, "comment");
        }
        if (!empty($x)) {
            $args["data-delete-info"] = "<p>This user is " . commajoin($x) . ". Deleting the user will also " . commajoin($y) . ".</p>";
        }
    }
    $buttons[] = "";
    $buttons[] = [Ht::button("Delete user", $args), "(admin only)"];
}
if (!$newProfile && $Acct->contactId == $Me->contactId)
    array_push($buttons, "", Ht::submit("merge", "Merge with another account"));

echo Ht::actions($buttons, ["class" => "aab aabr aabig"]);

echo "</div>\n", // foldaccount
    "</form>\n";

if ($newProfile) {
    echo '</div><div class="fx9">';
    echo Ht::form(hoturl_post("profile", join("&amp;", $form_params))),
        "<div class='profiletext", ($UserStatus->has_error() ? " alert" : ""), "'>\n",
        // Don't want chrome to autofill the password changer.
        // But chrome defaults to autofilling the password changer
        // unless we supply an earlier password input.
        Ht::password("chromefooler", "", ["class" => "ignore-diff hidden"]);
    echo_modes(2);

    $bulkentry = $Qreq->bulkentry;
    if ($bulkentry === null
        && ($session_bulkentry = $Conf->session("profile_bulkentry"))
        && is_array($session_bulkentry) && $session_bulkentry[0] > $Now - 5) {
        $bulkentry = $session_bulkentry[1];
        $Conf->save_session("profile_bulkentry", null);
    }
    echo '<div class="f-i">',
        Ht::textarea("bulkentry", $bulkentry,
                     ["rows" => 1, "cols" => 80, "placeholder" => "Enter users one per line", "class" => "want-focus need-autogrow"]),
        '</div>';

    echo '<div class="g"><strong>OR</strong> &nbsp;',
        '<input type="file" name="bulk" size="30" /></div>';

    echo '<div>', Ht::submit("savebulk", "Save accounts", ["class" => "btn btn-primary"]), '</div>';

    echo "<p>Enter or upload CSV user data with header. For example:</p>\n",
        '<pre class="entryexample">
name,email,affiliation,roles
John Adams,john@earbox.org,UC Berkeley,pc
"Adams, John Quincy",quincy@whitehouse.gov
</pre>', "\n",
        '<p>Or just enter an email address per line.</p>',
        '<p>Supported CSV fields include:</p><table>',
        '<tr><td class="lmcaption"><code>name</code></td>',
          '<td>User name</td></tr>',
        '<tr><td class="lmcaption"><code>first</code></td>',
          '<td>First name</td></tr>',
        '<tr><td class="lmcaption"><code>last</code></td>',
          '<td>Last name</td></tr>',
        '<tr><td class="lmcaption"><code>affiliation</code></td>',
          '<td>Affiliation</td></tr>',
        '<tr><td class="lmcaption"><code>roles</code></td>',
          '<td>User roles: blank, “<code>pc</code>”, “<code>chair</code>”, or “<code>sysadmin</code>”</td></tr>',
        '<tr><td class="lmcaption"><code>tags</code></td>',
          '<td>PC tags (space-separated)</td></tr>',
        '<tr><td class="lmcaption"><code>add_tags</code>, <code>remove_tags</code></td>',
          '<td>PC tags to add or remove</td></tr>',
        '<tr><td class="lmcaption"><code>collaborators</code></td>',
          '<td>Collaborators</td></tr>',
        '<tr><td class="lmcaption"><code>follow</code></td>',
          '<td>Email notification: blank, “<code>reviews</code>”, “<code>allreviews</code>”</td></tr>',
        "</table>\n";

    echo '</div></form></div></div>';
}


Ht::stash_script('focus_within($("#profile-form"))');
if ($newProfile)
    Ht::stash_script("focus_fold.hash(true)");
else
    Ht::stash_script('hiliter_children("#profile-form",true)');
$Conf->footer();
