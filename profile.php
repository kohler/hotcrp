<?php
// profile.php -- HotCRP profile management page
// Copyright (c) 2006-2017 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");
$Qreq = make_qreq();

// check for change-email capabilities
function change_email_by_capability($Qreq) {
    global $Conf, $Me;
    $capmgr = $Conf->capability_manager();
    $capdata = $capmgr->check($Qreq->changeemail);
    if (!$capdata || $capdata->capabilityType != CAPTYPE_CHANGEEMAIL
        || !($capdata->data = json_decode($capdata->data))
        || !get($capdata->data, "uemail"))
        error_go(false, "That email change code has expired, or you didn’t enter it correctly.");
    $Acct = $Conf->user_by_id($capdata->contactId);
    if (!$Acct)
        error_go(false, "No such account.");

    $email = $capdata->data->uemail;
    if ($Conf->user_id_by_email($email))
        error_go(false, "Email address “" . htmlspecialchars($email) . "” is already in use. You may want to <a href=\"" . hoturl("mergeaccounts") . "\">merge these accounts</a>.");

    $Acct->change_email($email);
    $capmgr->delete($capdata);

    $Conf->confirmMsg("Your email address has been changed.");
    if (!$Me->has_database_account() || $Me->contactId == $Acct->contactId)
        $Me = $Acct->activate();
}
if ($Qreq->changeemail)
    change_email_by_capability($Qreq);

if (!$Me->has_email())
    $Me->escape();
$newProfile = false;
$useRequest = false;
$UserStatus = new UserStatus;

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
if ($Me->privChair && $Qreq->u) {
    if ($Qreq->u === "new") {
        $Acct = new Contact;
        $newProfile = true;
    } else if (($id = cvtint($Qreq->u)) > 0)
        $Acct = $Conf->user_by_id($id);
    else
        $Acct = $Conf->user_by_email($Qreq->u);
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
    else if (isset($Qreq->register) || isset($Qreq->bulkregister))
        Conf::msg_error("You’re logged in as a different user now, so your changes were ignored.");
    unset($Qreq->u, $Qreq->register, $Qreq->bulkregister);
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


function pc_request_as_json($cj) {
    global $Conf, $Me, $Acct, $Qreq;
    if ($Me->privChair && isset($Qreq->pctype)) {
        $cj->roles = (object) array();
        $pctype = $Qreq->pctype;
        if ($pctype === "chair")
            $cj->roles->chair = $cj->roles->pc = true;
        if ($pctype === "pc")
            $cj->roles->pc = true;
        if ($Qreq->ass)
            $cj->roles->sysadmin = true;
    }
    $cj->follow = (object) array();
    if ($Qreq->watchcomment)
        $cj->follow->reviews = true;
    if (($Me->privChair || $Acct->isPC) && $Qreq->watchcommentall)
        $cj->follow->allreviews = true;
    if ($Me->privChair && $Qreq->watchfinalall)
        $cj->follow->allfinal = true;
    if ($Me->privChair && isset($Qreq->contactTags))
        $cj->tags = explode(" ", simplify_whitespace($Qreq->contactTags));
    if ($Me->privChair ? get($cj->roles, "pc") : $Me->isPC) {
        $topics = (object) array();
        foreach ($Conf->topic_map() as $id => $t)
            if (isset($Qreq["ti$id"]) && is_numeric($Qreq["ti$id"]))
                $topics->$id = (int) $Qreq["ti$id"];
        if (count(get_object_vars($topics)))
            $cj->topics = (object) $topics;
    }
    return $cj;
}

function web_request_as_json($cj) {
    global $Conf, $Me, $Acct, $Qreq, $newProfile, $UserStatus;

    if ($newProfile || !$Acct->has_database_account())
        $cj->id = "new";
    else
        $cj->id = $Acct->contactId;

    if (!$Conf->external_login())
        $cj->email = trim((string) $Qreq->uemail);
    else if ($newProfile)
        $cj->email = trim((string) $Qreq->newUsername);
    else
        $cj->email = $Acct->email;

    foreach (array("firstName", "lastName", "preferredEmail", "affiliation",
                   "collaborators", "addressLine1", "addressLine2",
                   "city", "state", "zipCode", "country", "voicePhoneNumber") as $k) {
        $v = $Qreq[$k];
        if ($v !== null && ($cj->id !== "new" || trim($v) !== ""))
            $cj->$k = $v;
    }

    if (!$Conf->external_login() && !$newProfile
        && $Me->can_change_password($Acct)) {
        if ($Qreq->whichpassword === "t" && $Qreq->upasswordt)
            $pw = $pw2 = trim($Qreq->upasswordt);
        else {
            $pw = trim((string) $Qreq->upassword);
            $pw2 = trim((string) $Qreq->upassword2);
        }
        if ($pw === "" && $pw2 === "")
            /* do nothing */;
        else if ($pw !== $pw2)
            $UserStatus->error_at("password", "Those passwords do not match.");
        else if (!Contact::valid_password($pw))
            $UserStatus->error_at("password", "Invalid new password.");
        else if (!$Acct || $Me->can_change_password(null)) {
            $cj->old_password = null;
            $cj->new_password = $pw;
        } else {
            $cj->old_password = trim((string) $Qreq->oldpassword);
            if ($Acct->check_password($cj->old_password))
                $cj->new_password = $pw;
            else
                $UserStatus->error_at("password", "Incorrect current password. New password ignored.");
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
    if ($newProfile || $cj->email != $Acct->email) {
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
        } else if ($cj->email === "")
            return $user_status->error_at("email", "You must supply an email address.");
        else if (!validate_email($cj->email))
            return $user_status->error_at("email", "“" . htmlspecialchars($cj->email) . "” is not a valid email address.");
        if (!$newProfile && !$Me->privChair) {
            $old_preferredEmail = $Acct->preferredEmail;
            $Acct->preferredEmail = $cj->email;
            $capmgr = $Conf->capability_manager();
            $rest = array("capability" => $capmgr->create(CAPTYPE_CHANGEEMAIL, array("user" => $Acct, "timeExpires" => $Now + 259200, "data" => json_encode_db(array("uemail" => $cj->email)))));
            $mailer = new HotCRPMailer($Acct, null, $rest);
            $prep = $mailer->make_preparation("@changeemail", $rest);
            if ($prep->sendable) {
                Mailer::send_preparation($prep);
                $Conf->warnMsg("Mail has been sent to " . htmlspecialchars($cj->email) . ". Use the link it contains to confirm your email change request.");
            } else
                Conf::msg_error("Mail cannot be sent to " . htmlspecialchars($cj->email) . " at this time. Your email address was unchanged.");
            // Save changes *except* for new email, by restoring old email.
            $cj->email = $Acct->email;
            $Acct->preferredEmail = $old_preferredEmail;
        }
    }

    // save account
    return $user_status->save($cj, $Acct, $Me);
}


function parseBulkFile($text, $filename) {
    global $Conf;
    $text = cleannl($text);
    if (!is_valid_utf8($text))
        $text = windows_1252_to_utf8($text);
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

    $cj_template = (object) array();
    $topic_revmap = array();
    foreach ($Conf->topic_map() as $id => $name)
        $topic_revmap[strtolower($name)] = $id;
    $unknown_topics = array();

    $ignore_empty = array_flip(["firstname", "first", "firstName",
            "lastname", "last", "lastName", "fullname", "fullName", "name",
            "voice", "voicePhoneNumber", "phone", "address1", "addressLine1",
            "address2", "addressLine2", "province", "state", "region",
            "postalcode", "zip", "zipCode", "country"]);

    while (($line = $csv->next()) !== false) {
        $cj = clone $cj_template;
        foreach ($line as $k => $v)
            if ($v !== "" || !isset($ignore_empty[$k]))
                $cj->$k = $v;
        foreach (array("firstname" => "firstName", "first" => "firstName",
                       "lastname" => "lastName", "last" => "lastName",
                       "fullname" => "name", "fullName" => "name",
                       "voice" => "voicePhoneNumber", "phone" => "voicePhoneNumber",
                       "address1" => "addressLine1", "province" => "state", "region" => "state",
                       "address2" => "addressLine2", "postalcode" => "zipCode",
                       "zip" => "zipCode") as $k => $xk)
            if (isset($cj->$k)) {
                if (!isset($cj->$xk))
                    $cj->$xk = $cj->$k;
                unset($cj->$k);
            }
        // clean up name
        if (isset($cj->name) && !isset($cj->firstName) && !isset($cj->lastName))
            list($cj->firstName, $cj->lastName) = Text::split_name($cj->name);
        // thou shalt not set passwords by bulk update
        unset($cj->password, $cj->password_plaintext, $cj->new_password);
        // topics
        if (!empty($topic_revmap)) {
            foreach (array_keys($line) as $k)
                if (preg_match('/^topic:\s*(.*?)\s*$/i', $k, $m)) {
                    if (($ti = get($topic_revmap, strtolower($m[1]))) !== null) {
                        $x = $line[$k];
                        if (strtolower($x) === "low")
                            $x = -2;
                        else if (strtolower($x) === "high")
                            $x = 2;
                        else if (!is_numeric($x))
                            $x = 0;
                        if (!isset($cj->topics))
                            $cj->topics = (object) array();
                        $cj->topics->$ti = $x;
                    } else
                        $unknown_topics[$m[1]] = true;
                }
        }
        $cj->id = "new";

        $ustatus = new UserStatus(array("send_email" => true, "no_deprivilege_self" => true));
        if (($saved_user = save_user($cj, $ustatus, null, true)))
            $success[] = "<a href=\"" . hoturl("profile", "u=" . urlencode($saved_user->email)) . "\">"
                . Text::user_html_nolink($saved_user) . "</a>";
        foreach ($ustatus->errors() as $e)
            $errors[] = "<span class='lineno'>" . $filename . $csv->lineno() . ":</span> " . $e;
    }

    if (count($unknown_topics))
        $errors[] = "There were unrecognized topics (" . htmlspecialchars(commajoin($unknown_topics)) . ").";
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
    return count($errors) == 0;
}

if (!check_post($Qreq))
    /* do nothing */;
else if ($Qreq->bulkregister && $newProfile && $Qreq->has_file("bulk")) {
    if (($text = $Qreq->file_contents("bulk")) === false)
        Conf::msg_error("Internal error: cannot read file.");
    else
        parseBulkFile($text, $Qreq->file_filename("bulk"));
    $Qreq->bulkentry = "";
    SelfHref::redirect($Qreq, ["anchor" => "bulk"]);
} else if ($Qreq->bulkregister && $newProfile) {
    $success = true;
    if ($Qreq->bulkentry && $Qreq->bulkentry !== "Enter users one per line")
        $success = parseBulkFile($Qreq->bulkentry, "");
    if (!$success)
        $Conf->save_session("profile_bulkentry", array($Now, $Qreq->bulkentry));
    SelfHref::redirect($Qreq, ["anchor" => "bulk"]);
} else if (isset($Qreq->register)) {
    $cj = (object) array();
    web_request_as_json($cj);
    pc_request_as_json($cj);
    if ($newProfile)
        $UserStatus->send_email = true;
    $saved_user = save_user($cj, $UserStatus, $Acct, false);
    if ($UserStatus->has_error())
        Conf::msg_error('<div class="mmm">' . join('</div><div class="mmm">', $UserStatus->errors()) . "</div>");
    else {
        if ($newProfile)
            $Conf->confirmMsg("Created an account for <a href=\"" . hoturl("profile", "u=" . urlencode($saved_user->email)) . "\">" . Text::user_html_nolink($saved_user) . "</a>. A password has been emailed to that address. You may now create another account.");
        else {
            if ($UserStatus->errors())
                $Conf->confirmMsg('Profile updated. <div class="mmm">' . join('</div><div class="mmm">', $UserStatus->errors()) . "</div>");
            else
                $Conf->confirmMsg("Profile updated.");
            if ($Acct->contactId == $Me->contactId)
                $Me->update_trueuser(true);
            else
                $Qreq->u = $Acct->email;
        }
        if (isset($Qreq->redirect))
            go(hoturl("index"));
        else {
            if ($newProfile)
                $Conf->save_session("profile_redirect", $cj);
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

if (isset($Qreq->delete) && !Dbl::has_error() && check_post($Qreq)) {
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

function value($key, $value) {
    global $useRequest, $Qreq;
    if ($useRequest && isset($Qreq[$key]))
        return htmlspecialchars($Qreq[$key]);
    else
        return $value ? htmlspecialchars($value) : "";
}

function contact_value($key, $field = null) {
    global $Acct, $useRequest, $Qreq;
    if ($useRequest && isset($Qreq[$key]))
        return $Qreq[$key];
    else if ($field == "password") {
        $v = $Acct->plaintext_password();
        return $v ? : "";
    } else if ($field !== false) {
        $v = $field ? $Acct->$field : $Acct->$key;
        return $v !== null ? $v : "";
    } else
        return "";
}

function fcclass($field = false) {
    global $UserStatus;
    if ($field && $UserStatus->has_problem_at($field))
        return "f-c error";
    else
        return "f-c";
}

function feclass($field = false) {
    global $UserStatus;
    if ($field && $UserStatus->has_problem_at($field))
        return "f-e error";
    else
        return "f-e";
}

function echofield($type, $classname, $captiontext, $entrytext) {
    if ($type <= 1)
        echo '<div class="f-i">';
    if ($type >= 1)
        echo '<div class="f-ix">';
    echo '<div class="', fcclass($classname), '">', $captiontext, "</div>",
        '<div class="', feclass($classname), '">', $entrytext, "</div></div>\n";
    if ($type > 2)
        echo '<hr class="c" />', "</div>\n";
}

function textinput($name, $value, $size, $id = false, $password = false) {
    return '<input type="' . ($password ? "password" : "text")
        . '" name="' . $name . '" ' . ($id ? "id=\"$id\" " : "")
        . 'size="' . $size . '" value="' . $value . '" />';
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
        echo Ht::link("Bulk update", hoturl("profile", "u=new&amp;bulkregister=1"));
    echo '</div></div><hr class="c" style="margin-bottom:24px" />', "\n";
}


if ($newProfile)
    $Conf->header("User update", "account", actionBar("account"));
else
    $Conf->header($Me->email == $Acct->email ? "Profile" : "Account profile", "account", actionBar("account", $Acct));
$useRequest = !$Acct->has_database_account() && isset($Qreq->watchcomment);
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
        else if ($Acct->collaborators !== Contact::fix_collaborator_affiliations($Acct->collaborators, true))
            $UserStatus->warning_at("collaborators", "Please use parentheses to indicate affiliations in your collaborators. (It looks like you might have used other punctuation.)");
        if (Contact::suspect_collaborator_one_line($Acct->collaborators))
            $UserStatus->warning_at("collaborators", "Please enter one potential conflict per line.");
        if ($Conf->topic_map() && !$Acct->topic_interest_map())
            $UserStatus->warning_at("topics", "Please enter your topic interests. We use topic interests to improve the paper assignment process.");
    }
}


if ($useRequest) {
    $formcj = (object) array();
    pc_request_as_json($formcj);
} else if (($formcj = $Conf->session("profile_redirect")))
    $Conf->save_session("profile_redirect", null);
else
    $formcj = $UserStatus->user_to_json($Acct);
$pcrole = "no";
if (isset($formcj->roles)) {
    if (get($formcj->roles, "chair"))
        $pcrole = "chair";
    else if (get($formcj->roles, "pc"))
        $pcrole = "pc";
}
if (!$useRequest && $Me->privChair && $newProfile
    && ($Qreq->role == "chair" || $Qreq->role == "pc"))
    $pcrole = $Qreq->role;


$form_params = array();
if ($newProfile)
    $form_params[] = "u=new";
else if ($Me->contactId != $Acct->contactId)
    $form_params[] = "u=" . urlencode($Acct->email);
if (isset($Qreq->ls))
    $form_params[] = "ls=" . urlencode($Qreq->ls);
if ($newProfile)
    echo '<div id="foldbulk" class="fold9' . ($Qreq->bulkregister ? "o" : "c") . ' js-fold-focus"><div class="fn9">';

echo Ht::form(hoturl_post("profile", join("&amp;", $form_params)),
              array("id" => "accountform", "autocomplete" => "off")),
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
    echo '<div class="xmsgs-atbody">', Ht::xmsg($status, join("", $msgs)), "</div>\n";
}

echo '<div id="foldaccount" class="aahc profiletext', ($need_highlight ? " alert" : ""),
    " fold1", ($pcrole == "no" ? "c" : "o"), " fold2",
    ($Qreq->bulkregister ? "o" : "c"), "\">\n";

echo '<div class="f-contain">', "\n\n";
$actas = "";
if ($Acct !== $Me && $Acct->email && $Me->privChair)
    $actas = '&nbsp;' . actas_link($Acct);
if (!$Conf->external_login())
    echofield(0, "uemail", "Email",
              Ht::entry("uemail", contact_value("uemail", "email"), ["class" => "want-focus", "size" => 52]) . $actas);
else if (!$newProfile) {
    echofield(0, "uemail", "Username", htmlspecialchars(contact_value("uemail", "email")) . $actas);
    echofield(0, "preferredEmail", "Email",
              Ht::entry("preferredEmail", contact_value("preferredEmail"), ["class" => "want-focus", "size" => 52]));
} else {
    echofield(0, "uemail", "Username",
              Ht::entry("newUsername", contact_value("newUsername", false), ["class" => "want-focus", "size" => 52]));
    echofield(0, "preferredEmail", "Email",
              Ht::entry("preferredEmail", contact_value("preferredEmail"), ["size" => 52]));
}

echofield(1, "firstName", "First name",
          Ht::entry("firstName", contact_value("firstName"), ["size" => 24, "autocomplete" => "given-name"]));
echofield(3, "lastName", "Last name",
          Ht::entry("lastName", contact_value("lastName"), ["size" => 24, "autocomplete" => "family-name"]));
echofield(0, "affiliation", "Affiliation",
          Ht::entry("affiliation", contact_value("affiliation"), ["size" => 52, "autocomplete" => "organization"]));
echofield(0, false, "Country", Countries::selector("country", contact_value("country")));

$data = $Acct->data();
$any_address = $data && (get($data, "address") || get($data, "city") || get($data, "state") || get($data, "zip"));
if ($Conf->setting("acct_addr") || $any_address || $Acct->voicePhoneNumber) {
    echo "<div style='margin-top:20px'></div>\n";
    $address = get($data, "address");
    echofield(0, false, "Address line 1",
              Ht::entry("addressLine1", value("addressLine1", $address ? $address[0] : null), ["size" => 52, "autocomplete" => "address-line1"]));
    echofield(0, false, "Address line 2",
              Ht::entry("addressLine2", value("addressLine2", $address ? $address[1] : null), ["size" => 52, "autocomplete" => "address-line2"]));
    echofield(0, false, "City",
              Ht::entry("city", value("city", get($data, "city")), ["size" => 52, "autocomplete" => "address-level2"]));
    echofield(1, false, "State/Province/Region",
              Ht::entry("state", value("state", get($data, "state")), ["size" => 24, "autocomplete" => "address-level1"]));
    echofield(3, false, "ZIP/Postal code",
              Ht::entry("zipCode", value("zipCode", get($data, "zip")), ["size" => 12, "autocomplete" => "postal-code"]));
    echofield(0, false, "Phone <span class='f-cx'>(optional)</span>",
              Ht::entry("voicePhoneNumber", contact_value("voicePhoneNumber"), ["size" => 24, "autocomplete" => "tel"]));
}


if (!$newProfile && !$Conf->external_login() && $Me->can_change_password($Acct)) {
    echo '<div id="foldpassword" class="foldc ',
        ($UserStatus->has_problem_at("password") ? "fold3o" : "fold3c"),
        '" style="margin-top:20px">';
    // Hit a button to change your password
    echo Ht::button("Change password", ["class" => "btn ui js-foldup fn3", "data-fold-target" => "3o"]);
    // Display the following after the button is clicked
    echo '<div class="fx3">';
    if (!$Me->can_change_password(null)) {
        echo '<div class="f-h">Enter your current password as well as your desired new password.</div>';
        echo '<div class="f-i"><div class="', fcclass("password"), '">Current password</div>',
            '<div class="', feclass("password"), '">', Ht::password("oldpassword", "", ["size" => 24, "autocomplete" => "current-password"]), '</div>',
            '</div>';
    }
    if ($Conf->opt("contactdb_dsn") && $Conf->opt("contactdb_loginFormHeading"))
        echo $Conf->opt("contactdb_loginFormHeading");
    echo '<div class="f-i"><div class="f-ix">
  <div class="', fcclass("password"), '">New password</div>
  <div class="', feclass("password"), '">', Ht::password("upassword", "", ["size" => 24, "class" => "fn", "autocomplete" => "new-password"]);
    if ($Acct->plaintext_password() && $Me->privChair)
        echo Ht::entry("upasswordt", contact_value("upasswordt", "password"), ["size" => 24, "class" => "fx", "autocomplete" => "new-password"]);
    echo '</div>
</div><div class="fn f-ix">
  <div class="', fcclass("password"), '">Repeat new password</div>
  <div class="', feclass("password"), '">', Ht::password("upassword2", "", array("size" => 24)), "</div>
</div>\n";
    if ($Acct->plaintext_password()
        && ($Me->privChair || Contact::password_storage_cleartext())) {
        echo "  <div class=\"f-h\">";
        if (Contact::password_storage_cleartext())
            echo "The password is stored in our database in cleartext and will be mailed to you if you have forgotten it, so don’t use a login password or any other high-security password.";
        if ($Me->privChair) {
            if (Contact::password_storage_cleartext())
                echo " <span class=\"sep\"></span>";
            echo '<span class="f-cx"><a class="ui js-plaintext-password" href=""><span class="fn">Show password</span><span class="fx">Hide password</span></a></span>';
        }
        echo "</div>\n";
    }
    echo '  <hr class="c" />';
    echo "</div></div></div>\n\n";
}

echo "</div>\n"; // f-contain


echo '<h3 class="profile">Email notification</h3>';
$follow = isset($formcj->follow) ? $formcj->follow : (object) [];
if ($newProfile ? $Me->privChair : $Acct->isPC) {
    echo "<table><tr><td>Send mail for: &nbsp;</td>",
        "<td>", Ht::checkbox("watchcomment", 1, !!get($follow, "reviews")), "&nbsp;",
        Ht::label($Conf->_("Reviews and comments on authored or reviewed papers")), "</td></tr>",
        "<tr><td></td><td>", Ht::checkbox("watchcommentall", 1, !!get($follow, "allreviews")), "&nbsp;",
        Ht::label($Conf->_("Reviews and comments on <i>any</i> paper")), "</td></tr>";
    if (!$newProfile && $Acct->privChair)
        echo "<tr><td></td><td>", Ht::checkbox("watchfinalall", 1, !!get($follow, "allfinal")), "&nbsp;",
            Ht::label($Conf->_("Updates to final versions")), "</td></tr>";
    echo "</table>";
} else
    echo Ht::checkbox("watchcomment", 1, !!get($follow, "reviews")), "&nbsp;",
        Ht::label($Conf->_("Send mail for new comments on authored or reviewed papers"));


if ($newProfile || $Acct->contactId != $Me->contactId || $Me->privChair) {
    echo '<h3 class="profile">Roles</h3>', "\n",
      "<table><tr><td class=\"nw\">\n";
    foreach (array("chair" => "PC chair",
                   "pc" => "PC member",
                   "no" => "Not on the PC") as $k => $v) {
        echo Ht::radio("pctype", $k, $pcrole === $k, ["class" => "js-role"]),
            "&nbsp;", Ht::label($v), "<br />\n";
    }
    Ht::stash_script('$(".js-role").on("change", profile_ui);$(function(){$(".js-role").first().trigger("change")})');

    echo "</td><td><span class='sep'></span></td><td class='nw'>";
    $is_ass = isset($formcj->roles) && get($formcj->roles, "sysadmin");
    echo Ht::checkbox("ass", 1, $is_ass), "&nbsp;</td>",
        "<td>", Ht::label("Sysadmin"), "<br/>",
        '<div class="hint">Sysadmins and PC chairs have full control over all site operations. Sysadmins need not be members of the PC. There’s always at least one administrator (sysadmin or chair).</div></td></tr></table>', "\n";
}


if ($newProfile || $Acct->isPC || $Me->privChair) {
    echo '<div class="fx1"><div class="g"></div>', "\n";

    $className = $UserStatus->has_problem_at("collaborators") ? " error" : "";
    echo '<h3 class="profile', $className, '">Collaborators and other affiliations</h3>', "\n",
        "<div>Please list potential conflicts of interest. We use this information when assigning reviews. ",
        $Conf->message_html("conflictdef"),
        " <p>List one conflict per line, using parentheses for affiliations.<br />
    Examples: “Ping Yen Zhang (INRIA)”, “University College London”</p></div>
    <textarea name=\"collaborators\" rows=\"5\" cols=\"60\"",
        ($className ? " class=\"" . ltrim($className) . "\"" : ""),
        ">", htmlspecialchars(contact_value("collaborators")), "</textarea>\n";

    $topics = $Conf->topic_map();
    if (!empty($topics)) {
        echo '<div id="topicinterest"><h3 class="profile">Topic interests</h3>', "\n",
            '<p>
    Please indicate your interest in reviewing papers on these conference
    topics. We use this information to help match papers to reviewers.</p>
    <table class="topicinterest"><thead>
       <tr><td></td><th class="ti_interest">Low</th><th class="ti_interest" style="width:2.2em">-</th><th class="ti_interest" style="width:2.2em">-</th><th class="ti_interest" style="width:2.2em">-</th><th class="ti_interest">High</th></tr></thead><tbody>', "\n";

        $ibound = [-INF, -1.5, -0.5, 0.5, 1.5, INF];
        $formcj_topics = get($formcj, "topics", []);
        foreach ($topics as $id => $name) {
            echo "      <tr><td class=\"ti_topic\">", htmlspecialchars($name), "</td>";
            $ival = (float) get($formcj_topics, $id);
            for ($j = -2; $j <= 2; ++$j) {
                $checked = $ival >= $ibound[$j+2] && $ival < $ibound[$j+3];
                echo '<td class="ti_interest">', Ht::radio("ti$id", $j, $checked), "</td>";
            }
            echo "</tr>\n";
        }
        echo "    </tbody></table></div>\n";
    }


    if ($Me->privChair || isset($formcj->tags)) {
        if (isset($formcj->tags) && is_array($formcj->tags))
            $tags = $formcj->tags;
        else
            $tags = array();
        echo "<h3 class=\"profile\">Tags</h3>\n";
        if ($Me->privChair) {
            echo "<div class='", feclass("contactTags"), "'>",
                Ht::entry("contactTags", join(" ", $tags), ["size" => 60]),
                "</div>
  <div class='hint'>Example: “heavy”. Separate tags by spaces; the “pc” tag is set automatically.<br /><strong>Tip:</strong>&nbsp;Use <a href='", hoturl("settings", "group=tags"), "'>tag colors</a> to highlight subgroups in review lists.</div>\n";
        } else {
            echo join(" ", $tags), "
  <div class='hint'>Tags represent PC subgroups and are set by administrators.</div>\n";
        }
    }
    echo "</div>\n"; // fx1
}


$buttons = [Ht::submit("register", $newProfile ? "Create account" : "Save changes", ["class" => "btn btn-default"]),
    Ht::submit("cancel", "Cancel", ["class" => "btn"])];

if ($Me->privChair && !$newProfile && $Me->contactId != $Acct->contactId) {
    $tracks = databaseTracks($Acct->contactId);
    $args = ["class" => "btn ui"];
    if (count($tracks->soleAuthor)) {
        $args["class"] .= " js-cannot-delete-user";
        $args["data-sole-author"] = pluralx($tracks->soleAuthor, "paper") . " " . textArrayPapers($tracks->soleAuthor);
    } else {
        $args["class"] .= " js-delete-user";
        if (count($tracks->author) + count($tracks->review) + count($tracks->comment)) {
            $x = $y = array();
            if (count($tracks->author)) {
                $x[] = "contact for " . pluralx($tracks->author, "paper") . " " . textArrayPapers($tracks->author);
                $y[] = "delete " . pluralx($tracks->author, "this") . " " . pluralx($tracks->author, "authorship association");
            }
            if (count($tracks->review)) {
                $x[] = "reviewer for " . pluralx($tracks->review, "paper") . " " . textArrayPapers($tracks->review);
                $y[] = "<strong>permanently delete</strong> " . pluralx($tracks->review, "this") . " " . pluralx($tracks->review, "review");
            }
            if (count($tracks->comment)) {
                $x[] = "commenter for " . pluralx($tracks->comment, "paper") . " " . textArrayPapers($tracks->comment);
                $y[] = "<strong>permanently delete</strong> " . pluralx($tracks->comment, "this") . " " . pluralx($tracks->comment, "comment");
            }
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
    echo Ht::form(hoturl_post("profile", join("&amp;", $form_params)),
                  array("id" => "accountform", "autocomplete" => "off")),
        "<div class='profiletext aahc", ($UserStatus->has_error() ? " alert" : ""), "'>\n",
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
    echo '<div class="f-contain"><div class="f-i"><div class="f-e">',
        Ht::textarea("bulkentry", $bulkentry,
                     ["rows" => 1, "cols" => 80, "placeholder" => "Enter users one per line", "class" => "want-focus"]),
        '</div></div></div>';

    echo '<div class="g"><strong>OR</strong> &nbsp;',
        '<input type="file" name="bulk" size="30" /></div>';

    echo '<div>', Ht::submit("bulkregister", "Save accounts"), '</div>';

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


Ht::stash_script('hiliter_children("#accountform",true);$("textarea").autogrow()');
Ht::stash_script('focus_within($("#accountform"))');
if ($newProfile)
    Ht::stash_script("focus_fold.hash(true)");
$Conf->footer();
