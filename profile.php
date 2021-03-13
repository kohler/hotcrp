<?php
// profile.php -- HotCRP profile management page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

// check for change-email capabilities

function change_email_by_capability($Qreq) {
    global $Conf, $Me;
    ensure_session();
    $capdata = CapabilityInfo::find($Conf, trim($Qreq->changeemail), CapabilityInfo::CHANGEEMAIL);
    $capcontent = null;
    if (!$capdata
        || !$capdata->contactId
        || !($capcontent = json_decode($capdata->data))
        || !is_object($capcontent)
        || !($capcontent->uemail ?? null)) {
        if (trim($Qreq->changeemail) !== "1") {
            Ht::error_at("changeemail", "That email change code has expired, or you didn’t enter it correctly.");
        }
        $capdata = false;
    }

    $Acct = null;
    if ($capdata && !($Acct = $Conf->user_by_id($capdata->contactId))) {
        Ht::error_at("changeemail", "The account associated with that email change code no longer exists.");
    }
    if ($Acct && strcasecmp($Acct->email, $capcontent->oldemail) !== 0) {
        Ht::error_at("changeemail", "You have changed your email address since creating that email change code.");
        $Acct = null;
    }

    $newemail = $Acct ? $capcontent->uemail : null;
    if ($Acct && $Conf->user_by_email($newemail)) {
        Conf::msg_error("The email address you requested, " . htmlspecialchars($newemail) . ", is already in use on this site. You may want to <a href=\"" . $Conf->hoturl("mergeaccounts") . "\">merge these accounts</a>.");
        return false;
    }

    $newcdbu = $newemail ? $Conf->contactdb_user_by_email($newemail) : null;
    if ($newcdbu) {
        if ($newcdbu->contactdb_disabled()) { // NB do not use is_disabled()
            Conf::msg_error("changeemail", "That user is globally disabled.");
            return false;
        } else if ($Qreq->go && $Qreq->valid_post()) {
            $Qreq->password = trim((string) $Qreq->password);
            $info = $newcdbu->check_password_info($Qreq->password);
            if (!$info["ok"]) {
                $qreqa = ["email" => $newemail] + $Qreq->as_array();
                LoginHelper::login_error($Conf, new Qrequest("POST", $qreqa), $info);
                unset($Qreq->go);
            }
        }
    }

    if ($newemail
        && $Qreq->go
        && $Qreq->valid_post()) {
        $Acct->change_email($newemail);
        $capdata->delete();
        $Conf->confirmMsg("Your email address has been changed.");
        if (!$Me->has_account_here() || $Me->contactId == $Acct->contactId) {
            Contact::set_guser($Acct->activate($Qreq));
        }
        if (Contact::session_user_index($capcontent->oldemail) >= 0) {
            LoginHelper::change_session_users([
                $capcontent->oldemail => -1, $newemail => 1
            ]);
        }
        Navigation::redirect($Conf->hoturl("profile"));
    } else {
        $Conf->header("Change email", "account", ["action_bar" => false]);
        if ($Acct) {
            echo '<p class="mb-5">Complete the email change using this form.</p>';
        } else {
            echo '<p class="mb-5">Enter an email change code.</p>';
        }
        echo Ht::form($Conf->hoturl("profile", "changeemail=1"), ["class" => "compact-form", "id" => "changeemailform"]),
            Ht::hidden("post", post_value());
        if ($Acct) {
            echo '<div class="f-i"><label>Old email</label>', htmlspecialchars($Acct->email), '</div>',
                '<div class="f-i"><label>New email</label>',
                Ht::entry("email", $newemail, ["autocomplete" => "username", "readonly" => true, "class" => "fullw"]),
                '</div>';
        }
        echo '<div class="', Ht::control_class("changeemail", "f-i"), '"><label for="changeemail">Change code</label>',
            Ht::feedback_at("changeemail"),
            Ht::entry("changeemail", $Qreq->changeemail == "1" ? "" : $Qreq->changeemail, ["id" => "changeemail", "class" => "fullw", "autocomplete" => "one-time-code"]),
            '</div>';
        if ($newcdbu) {
            echo '<div class="', Ht::control_class("password", "f-i"), '"><label for="password">Password for ', htmlspecialchars($newemail), '</label>',
            Ht::feedback_at("password"),
            Ht::password("password", "", ["autocomplete" => "password", "class" => "fullw"]),
            '</div>';
        }
        echo '<div class="popup-actions">',
            Ht::submit("go", "Change email", ["class" => "btn-primary", "value" => 1]),
            Ht::submit("cancel", "Cancel", ["formnovalidate" => true]),
            '</div></form>';
        Ht::stash_script("hotcrp.focus_within(\$(\"#changeemailform\"));window.scroll(0,0)");
        $Conf->footer();
        exit;
    }
}

if ($Qreq->changeemail) {
    if ($Qreq->cancel) {
        $Conf->redirect_self($Qreq);
    } else if (!$Me->is_actas_user()) {
        change_email_by_capability($Qreq);
    }
}

if (!$Me->is_signed_in()) {
    $Me->escape();
}

$newProfile = 0;
$UserStatus = new UserStatus($Me);
$UserStatus->set_user($Me);
$UserStatus->set_context_args([$UserStatus]);

if ($Qreq->u === null && ($Qreq->user || $Qreq->contact)) {
    $Qreq->u = $Qreq->user ? : $Qreq->contact;
}
if (($p = $Qreq->path_component(0)) !== null) {
    if (in_array($p, ["", "me", "self", "new", "bulk"])
        || strpos($p, "@") !== false
        || !$UserStatus->gxt()->canonical_group($p)) {
        if ($Qreq->u === null) {
            $Qreq->u = urldecode($p);
        }
        if (($p = $Qreq->path_component(1)) !== null
            && $Qreq->t === null) {
            $Qreq->t = $p;
        }
    } else if ($Qreq->t === null) {
        $Qreq->t = $p;
    }
}
if ($Me->privChair && $Qreq->new) {
    $Qreq->u = "new";
}


// Load user.
$Acct = $Me;
if ($Qreq->u === "me" || $Qreq->u === "self") {
    $Qreq->u = "me";
} else if ($Me->privChair && ($Qreq->u || $Qreq->search)) {
    if ($Qreq->u === "new") {
        $Acct = new Contact(null, $Conf);
        $newProfile = 1;
    } else if ($Qreq->u === "bulk") {
        $Acct = new Contact(null, $Conf);
        $newProfile = 2;
    } else if (($id = cvtint($Qreq->u)) > 0) {
        $Acct = $Conf->user_by_id($id);
    } else if ($Qreq->u === "" && $Qreq->search) {
        Navigation::redirect_site("users");
    } else {
        $Acct = $Conf->user_by_email($Qreq->u);
        if (!$Acct && $Qreq->search) {
            $cs = new ContactSearch(ContactSearch::F_USER, $Qreq->u, $Me);
            if ($cs->user_ids()) {
                $Acct = $Conf->user_by_id(($cs->user_ids())[0]);
                $list = new SessionList("u/all/" . urlencode($Qreq->search), $cs->user_ids(), "“" . htmlspecialchars($Qreq->u) . "”", $Conf->hoturl_site_relative_raw("users", ["t" => "all"]));
                $list->set_cookie($Me);
                $Qreq->u = $Acct->email;
            } else {
                Conf::msg_error("No user matches “" . htmlspecialchars($Qreq->u) . "”.");
                unset($Qreq->u);
            }
            $Conf->redirect_self($Qreq);
        }
    }
}

// Redirect if requested user isn't loaded user.
if (!$Acct
    || ($Qreq->u !== null
        && $Qreq->u !== (string) $Acct->contactId
        && ($Qreq->u !== "me" || $Acct !== $Me)
        && strcasecmp($Qreq->u, $Acct->email)
        && ($Acct->contactId || !$newProfile))
    || (isset($Qreq->profile_contactid)
        && $Qreq->profile_contactid !== (string) $Acct->contactId)) {
    if (!$Acct) {
        Conf::msg_error("Invalid user.");
    } else if (isset($Qreq->save) || isset($Qreq->savebulk)) {
        Conf::msg_error("You’re logged in as a different user now, so your changes were ignored.");
    }
    unset($Qreq->u, $Qreq->save, $Qreq->savebulk);
    $Conf->redirect_self($Qreq);
}

// Redirect if canceled.
if ($Qreq->cancel) {
    $Conf->redirect_self($Qreq);
}

$need_highlight = false;
if (($Acct->contactId != $Me->contactId || !$Me->has_account_here())
    && $Acct->has_email()
    && !$Acct->firstName && !$Acct->lastName && !$Acct->affiliation
    && !$Qreq->post) {
    $result = $Conf->qe_raw("select Paper.paperId, authorInformation from Paper join PaperConflict on (PaperConflict.paperId=Paper.paperId and PaperConflict.contactId=$Acct->contactId and PaperConflict.conflictType>=" . CONFLICT_AUTHOR . ")");
    while (($prow = PaperInfo::fetch($result, $Me))) {
        foreach ($prow->author_list() as $au) {
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
    }
}


/** @param UserStatus $ustatus
 * @param ?Contact $acct
 * @return ?Contact */
function save_user($cj, $ustatus, $acct) {
    // check for missing fields
    UserStatus::normalize_name($cj);
    if (!$acct && !isset($cj->email)) {
        $ustatus->error_at("email", "Email address required.");
        return null;
    }

    // check email
    if (!$acct || strcasecmp($cj->email, $acct->email)) {
        if ($acct && $acct->data("locked")) {
            $ustatus->error_at("email", "This account is locked, so you can’t change its email address.");
            return null;
        } else if (($new_acct = $ustatus->conf->user_by_email($cj->email))) {
            if (!$acct) {
                $cj->id = $new_acct->contactId;
            } else {
                $msg = "Email address “" . htmlspecialchars($cj->email) . "” is already in use.";
                if ($ustatus->viewer->privChair) {
                    $msg = str_replace("an account", "<a href=\"" . $ustatus->conf->hoturl("profile", "u=" . urlencode($cj->email)) . "\">an account</a>", $msg);
                }
                if ($acct) {
                    $msg .= " You may want to <a href=\"" . $ustatus->conf->hoturl("mergeaccounts") . "\">merge these accounts</a>.";
                }
                $ustatus->error_at("email", $msg);
                return null;
            }
        } else if ($ustatus->conf->external_login()) {
            if ($cj->email === "") {
                $ustatus->error_at("email", "Not a valid username.");
                return null;
            }
        } else if ($cj->email === "") {
            $ustatus->error_at("email", "You must supply an email address.");
            return null;
        } else if (!validate_email($cj->email)) {
            $ustatus->error_at("email", "“" . htmlspecialchars($cj->email) . "” is not a valid email address.");
            return null;
        } else if ($acct && !$acct->has_account_here()) {
            $ustatus->error_at("email", "Your current account is only active on other HotCRP.com sites. Due to a server limitation, you can’t change your email until activating your account on this site.");
            return null;
        }
        if ($acct && (!$ustatus->viewer->privChair || $acct === $ustatus->viewer)) {
            assert($acct->contactId > 0);
            $old_preferredEmail = $acct->preferredEmail;
            $acct->preferredEmail = $cj->email;
            $capability = new CapabilityInfo($ustatus->conf, false, CapabilityInfo::CHANGEEMAIL);
            $capability->set_user($acct)->set_expires_after(259200);
            $capability->data = json_encode_db(["oldemail" => $acct->email, "uemail" => $cj->email]);
            if (($token = $capability->create())) {
                $rest = ["capability_token" => $token, "sensitive" => true];
                $mailer = new HotCRPMailer($ustatus->conf, $acct, $rest);
                $prep = $mailer->prepare("@changeemail", $rest);
            } else {
                $prep = null;
            }
            if ($prep->can_send()) {
                $prep->send();
                $ustatus->conf->warnMsg("Mail has been sent to " . htmlspecialchars($cj->email) . ". Use the link it contains to confirm your email change request.");
            } else {
                Conf::msg_error("Mail cannot be sent to " . htmlspecialchars($cj->email) . " at this time. Your email address was unchanged.");
            }
            // Save changes *except* for new email, by restoring old email.
            $cj->email = $acct->email;
            $acct->preferredEmail = $old_preferredEmail;
        }
    }

    // save account
    return $ustatus->save($cj, $acct);
}


function parseBulkFile($text, $filename) {
    global $Conf, $Me;
    $text = cleannl(convert_to_utf8($text));
    $filename = $filename ? htmlspecialchars($filename) . ":" : "line ";
    $success = $errors = $nochanges = $notified = [];

    if (!preg_match('/\A[^\r\n]*(?:,|\A)(?:user|email)(?:[,\r\n]|\z)/', $text)
        && !preg_match('/\A[^\r\n]*,[^\r\n]*,/', $text)) {
        $tarr = CsvParser::split_lines($text);
        foreach ($tarr as &$t) {
            if (($t = trim($t)) && $t[0] !== "#" && $t[0] !== "%") {
                $t = CsvGenerator::quote($t);
            }
            $t .= "\n";
        }
        unset($t);
        $text = join("", $tarr);
    }

    $csv = new CsvParser($text);
    $csv->set_filename($filename);
    $csv->set_comment_chars("#%");
    if (($line = $csv->next_list())) {
        if (preg_grep('/\A(?:email|user)\z/i', $line)) {
            $csv->set_header($line);
        } else if (count($line) == 1) {
            $csv->set_header(["user"]);
            $csv->unshift($line);
        } else {
            // interpolate a likely header
            $csv->unshift($line);
            $hdr = [];
            for ($i = 0; $i < count($line); ++$i) {
                if (validate_email($line[$i])
                    && array_search("email", $hdr) === false) {
                    $hdr[] = "email";
                } else if (strpos($line[$i], " ") !== false
                           && array_search("name", $hdr) === false) {
                    $hdr[] = "name";
                } else if (preg_match('/\A(?:pc|chair|sysadmin|admin)\z/i', $line[$i])
                           && array_search("roles", $hdr) === false) {
                    $hdr[] = "roles";
                } else if (array_search("name", $hdr) !== false
                           && array_search("affiliation", $hdr) === false) {
                    $hdr[] = "affiliation";
                } else {
                    $hdr[] = "unknown" . count($hdr);
                }
            }
            $csv->set_header($hdr);
            $errors[] = '<span class="lineno">' . $csv->landmark_html() . ":</span> Header missing, assuming “<code>" . join(",", $hdr) . "</code>”";
        }
    }

    $ustatus = new UserStatus($Me);
    $ustatus->notify = true; // notify all new users
    $ustatus->no_deprivilege_self = true;
    $ustatus->no_nonempty_profile = true;
    $ustatus->add_csv_synonyms($csv);

    while (($line = $csv->next_row())) {
        $ustatus->set_user(new Contact(null, $Conf));
        $ustatus->set_context_args([$ustatus]);
        $ustatus->clear_messages();
        $cj = (object) ["id" => null];
        $ustatus->parse_csv_group("", $cj, $line);
        if (($saved_user = save_user($cj, $ustatus, null))) {
            $x = "<a class=\"nb\" href=\"" . $Conf->hoturl("profile", "u=" . urlencode($saved_user->email)) . "\">" . $saved_user->name_h(NAME_E) . "</a>";
            if ($ustatus->notified) {
                $notified[] = $x;
            } else if (empty($ustatus->diffs)) {
                $nochanges[] = $x;
            } else {
                $success[] = $x;
            }
        }
        foreach ($ustatus->problem_texts() as $e) {
            $errors[] = '<span class="lineno">' . $csv->landmark_html() . ":</span> " . $e;
        }
    }

    if (!empty($ustatus->unknown_topics)) {
        $errors[] = "There were unrecognized topics (" . htmlspecialchars(commajoin(array_keys($ustatus->unknown_topics))) . ").";
    }
    $msgs = [];
    if (!empty($notified)) {
        $msgs[] = "<p class=\"bigod\">Saved " . plural($notified, "account") . " with confirmation email (" . commajoin($notified) . ").</p>";
    }
    if (!empty($success)) {
        $msgs[] = "<p class=\"bigod\">Saved " . plural($success, "account") . " (" . commajoin($success) . ").</p>";
    }
    if (!empty($nochanges)) {
        $msgs[] = "<p class=\"bigod\">No changes to " . plural($nochanges, "account") . " (" . commajoin($nochanges) . ").</p>";
    }
    if (!empty($errors)) {
        $msgs[] = "<div class=\"parseerr\"><p>" . join("</p>\n<p>", $errors) . "</p></div>";
    }
    if (empty($msgs)) {
        $Conf->warnMsg("No changes.");
    } else if ((!empty($success) || !empty($notified)) && empty($errors)) {
        $Conf->confirmMsg(join("", $msgs));
    } else if (empty($errors)) {
        $Conf->warnMsg(join("", $msgs));
    } else {
        $Conf->errorMsg(join("", $msgs));
    }
    return empty($errors);
}

if (!$Qreq->valid_post()) {
    // do nothing
} else if ($Qreq->savebulk && $newProfile && $Qreq->has_file("bulk")) {
    if (($text = $Qreq->file_contents("bulk")) === false) {
        Conf::msg_error("Internal error: cannot read file.");
    } else {
        parseBulkFile($text, $Qreq->file_filename("bulk"));
    }
    $Qreq->bulkentry = "";
    $Conf->redirect_self($Qreq, ["#" => "bulk"]);
} else if ($Qreq->savebulk && $newProfile) {
    $success = true;
    if ($Qreq->bulkentry && $Qreq->bulkentry !== "Enter users one per line") {
        $success = parseBulkFile($Qreq->bulkentry, "");
    }
    if (!$success) {
        $Me->save_session("profile_bulkentry", [Conf::$now, $Qreq->bulkentry]);
    }
    $Conf->redirect_self($Qreq, ["#" => "bulk"]);
} else if (isset($Qreq->save)) {
    assert($Acct->is_empty() === !!$newProfile);
    $cj = (object) ["id" => $Acct->has_account_here() ? $Acct->contactId : "new"];
    $UserStatus->set_user($Acct);
    $UserStatus->set_context_args([$UserStatus, $cj, $Qreq]);
    $UserStatus->no_deprivilege_self = true;
    if ($newProfile) {
        $UserStatus->no_nonempty_profile = true;
        $UserStatus->no_nonempty_pc = true;
        $UserStatus->notify = true;
    }
    $UserStatus->request_group("");
    $saved_user = save_user($cj, $UserStatus, $newProfile ? null : $Acct);
    if (!$UserStatus->has_error()) {
        if ($UserStatus->has_messages()) {
            $Conf->msg($UserStatus->message_texts(), $UserStatus->problem_status());
        }
        if ($UserStatus->created || $newProfile) {
            $purl = $Conf->hoturl("profile", ["u" => $saved_user->email]);
            if ($UserStatus->created) {
                $Conf->msg("Created " . Ht::link("an account for " . $saved_user->name_h(NAME_E), $purl) . ($UserStatus->notified ? " and sent confirmation email" : "") . ". You may now create another account.", "xconfirm");
            } else {
                if (!empty($UserStatus->diffs) && $UserStatus->notified) {
                    $changes = " Updated profile (" . commajoin(array_keys($UserStatus->diffs)) . ") and sent confirmation email.";
                } else if (!empty($UserStatus->diffs)) {
                    $changes = " Updated profile (" . commajoin(array_keys($UserStatus->diffs)) . ").";
                } else {
                    $changes = "";
                }
                $Conf->msg(Ht::link($saved_user->name_h(NAME_E), $purl) . " already had " . Ht::link("an account", $purl) . ".{$changes} You may now create another account.", "xconfirm");
            }
        } else {
            if (empty($UserStatus->diffs)) {
                $Conf->msg("No changes.", "xconfirm");
            } else if ($UserStatus->notified) {
                $Conf->msg("Updated profile and sent confirmation email.", "xconfirm");
            } else {
                $Conf->msg("Updated profile.", "xconfirm");
            }
            if ($Acct->contactId != $Me->contactId) {
                $Qreq->u = $Acct->email;
            }
        }
        if (isset($Qreq->redirect)) {
            $Conf->redirect();
        } else {
            $xcj = [];
            if ($newProfile) {
                foreach (["pctype", "ass", "contactTags"] as $k) {
                    if (isset($cj->$k))
                        $xcj[$k] = $cj->$k;
                }
            }
            if ($UserStatus->has_problem()) {
                $xcj["warning_fields"] = $UserStatus->problem_fields();
            }
            $Me->save_session("profile_redirect", $xcj);
            $Conf->redirect_self($Qreq);
        }
    }
} else if (isset($Qreq->merge)
           && !$newProfile
           && $Acct->contactId == $Me->contactId) {
    $Conf->redirect_hoturl("mergeaccounts");
}

if (isset($Qreq->delete) && !Dbl::has_error() && $Qreq->valid_post()) {
    if (!$Me->privChair) {
        Conf::msg_error("Only administrators can delete accounts.");
    } else if ($Acct->contactId == $Me->contactId) {
        Conf::msg_error("You aren’t allowed to delete your own account.");
    } else if ($Acct->has_account_here()) {
        $tracks = UserStatus::user_paper_info($Conf, $Acct->contactId);
        if (!empty($tracks->soleAuthor)) {
            Conf::msg_error("This account can’t be deleted since it is sole contact for " . pluralx($tracks->soleAuthor, "paper") . " " . UserStatus::render_paper_link($Conf, $tracks->soleAuthor) . ". You will be able to delete the account after deleting those papers or adding additional paper contacts.");
        } else if ($Acct->data("locked")) {
            Conf::msg_error("This account is locked and can’t be deleted.");
        } else {
            $Conf->q("insert into DeletedContactInfo set contactId=?, firstName=?, lastName=?, unaccentedName=?, email=?, affiliation=?", $Acct->contactId, $Acct->firstName, $Acct->lastName, $Acct->unaccentedName, $Acct->email, $Acct->affiliation);
            foreach (array("ContactInfo",
                           "PaperComment", "PaperConflict", "PaperReview",
                           "PaperReviewPreference", "PaperReviewRefused",
                           "PaperWatch", "ReviewRating", "TopicInterest")
                     as $table) {
                $Conf->qe_raw("delete from $table where contactId=$Acct->contactId");
            }
            // delete twiddle tags
            $assigner = new AssignmentSet($Me, true);
            $assigner->parse("paper,tag\nall,{$Acct->contactId}~all#clear\n");
            $assigner->execute();
            // clear caches
            if ($Acct->isPC || $Acct->privChair) {
                $Conf->invalidate_caches(["pc" => true]);
            }
            // done
            $Conf->confirmMsg("Permanently deleted account " . htmlspecialchars($Acct->email) . ".");
            $Me->log_activity_for($Acct, "Account deleted " . htmlspecialchars($Acct->email));
            $Conf->redirect_hoturl("users", "t=all");
        }
    }
}

// canonicalize topic
$UserStatus->set_user($Acct);
$UserStatus->set_context_args([$UserStatus]);
if (!$newProfile
    && ($g = $UserStatus->gxt()->canonical_group($Qreq->t ? : "main"))) {
    $profile_topic = $g;
} else {
    $profile_topic = "main";
}
if ($Qreq->t && $Qreq->t !== $profile_topic && $Qreq->method() === "GET") {
    $Qreq->t = $profile_topic === "main" ? null : $profile_topic;
    $Conf->redirect_self($Qreq);
}
$UserStatus->gxt()->set_root($profile_topic);

// set session list
if (!$newProfile
    && ($list = SessionList::load_cookie($Me, "u"))
    && $list->set_current_id($Acct->contactId)) {
    $Conf->set_active_list($list);
}

// set title
if ($newProfile == 2) {
    $title = "Bulk update";
} else if ($newProfile) {
    $title = "New account";
} else if (strcasecmp($Me->email, $Acct->email) == 0) {
    $title = "Profile";
} else {
    $title = $Me->name_html_for($Acct) . " profile";
}
$Conf->header($title, "account", [
    "title_div" => '<hr class="c">', "body_class" => "leftmenu",
    "action_bar" => actionBar("account")
]);

$useRequest = !$Acct->has_account_here() && isset($Qreq->watchreview);
if ($UserStatus->has_error()) {
    $need_highlight = $useRequest = true;
} else if ($Me->session("freshlogin")) {
    $Me->save_session("freshlogin", null);
}

// obtain user json
$userj = $UserStatus->user_json();
if (!$useRequest
    && $Me->privChair
    && $Acct->is_empty()
    && ($Qreq->role === "chair" || $Qreq->role === "pc")) {
    $userj->roles = [$Qreq->role];
}

// set warnings about user json
if (!$newProfile && !$useRequest) {
    $UserStatus->gxt()->set_context_args([$UserStatus, $Acct]);
    foreach ($UserStatus->gxt()->members("__crosscheck", "crosscheck_function") as $gj) {
        $UserStatus->gxt()->call_function($gj->crosscheck_function, $gj);
    }
}

// compute current form json
if ($useRequest) {
    $UserStatus->ignore_msgs = true;
} else {
    if (($cdbu = $Acct->contactdb_user())) {
        $Acct->import_prop($cdbu);
        if ($Acct->prop_changed()) {
            $Acct->save_prop();
        }
    }
}
if (($prdj = $Me->session("profile_redirect"))) {
    $Me->save_session("profile_redirect", null);
    foreach ($prdj as $k => $v) {
        if ($k === "warning_fields") {
            foreach ($v as $k) {
                $UserStatus->warning_at($k, null);
            }
        } else {
            $Qreq->$k = $v;
        }
    }
}

// start form
$form_params = [];
if ($newProfile) {
    $form_params["u"] = ($newProfile === 2 ? "bulk" : "new");
} else if ($Me->contactId != $Acct->contactId) {
    $form_params["u"] = $Acct->email;
}
$form_params["t"] = $Qreq->t;
if (isset($Qreq->ls)) {
    $form_params["ls"] = $Qreq->ls;
}
echo Ht::form($Conf->hoturl_post("profile", $form_params), [
    "id" => "form-profile",
    "class" => "need-unload-protection",
    "data-user" => $newProfile ? null : $Acct->email
]);

// left menu
echo '<div class="leftmenu-left"><nav class="leftmenu-menu">',
    '<h1 class="leftmenu"><a href="" class="uic js-leftmenu qq">Account</a></h1>',
    '<ul class="leftmenu-list">';

if ($Me->privChair) {
    foreach ([["New account", "new"], ["Bulk update", "bulk"], ["Your profile", null]] as $t) {
        if (!$t[1] && !$newProfile && $Acct->contactId == $Me->contactId) {
            continue;
        }
        $active = $t[1] && $newProfile === ($t[1] === "new" ? 1 : 2);
        echo '<li class="leftmenu-item',
            ($active ? ' active' : ' ui js-click-child'),
            ' font-italic', '">';
        if ($active) {
            echo $t[0];
        } else {
            echo Ht::link($t[0], $Conf->selfurl($Qreq, ["u" => $t[1], "t" => null]));
        }
        echo '</li>';
    }
}

if (!$newProfile) {
    $first = $Me->privChair;
    foreach ($UserStatus->gxt()->members("", "title") as $gj) {
        echo '<li class="leftmenu-item',
            ($gj->name === $profile_topic ? ' active' : ' ui js-click-child'),
            ($first ? ' leftmenu-item-gap4' : ''), '">';
        if ($gj->name === $profile_topic) {
            echo $gj->title;
        } else {
            echo Ht::link($gj->title, $Conf->selfurl($Qreq, ["t" => $gj->name]));
        }
        echo '</li>';
        $first = false;
    }

    echo '</ul><div class="leftmenu-if-left if-alert mt-5">',
        Ht::submit("save", "Save changes", ["class" => "btn-primary"]),
        '</div>';
} else {
    echo '</ul>';
}

echo '</nav></div>',
    '<main id="profilecontent" class="leftmenu-content main-column">';

if ($newProfile === 2
    && $Qreq->bulkentry === null
    && ($session_bulkentry = $Me->session("profile_bulkentry"))
    && is_array($session_bulkentry)
    && $session_bulkentry[0] > Conf::$now - 5) {
    $Qreq->bulkentry = $session_bulkentry[1];
    $Me->save_session("profile_bulkentry", null);
}

if ($newProfile === 2) {
    echo '<h2 class="leftmenu">Bulk update</h2>';
} else {
    echo Ht::hidden("profile_contactid", $Acct->contactId);
    if (isset($Qreq->redirect)) {
        echo Ht::hidden("redirect", $Qreq->redirect);
    }

    echo '<div id="foldaccount" class="';
    if ($Qreq->pctype === "chair"
        || $Qreq->pctype === "pc"
        || (!isset($Qreq->pctype) && ($Acct->roles & Contact::ROLE_PC) !== 0)) {
        echo "fold1o fold2o";
    } else if ($Qreq->ass
               || (!isset($Qreq->pctype) && ($Acct->roles & Contact::ROLE_ADMIN) !== 0)) {
        echo "fold1c fold2o";
    } else {
        echo "fold1c fold2c";
    }
    echo "\">";

    echo '<h2 class="leftmenu">';
    if ($newProfile) {
        echo 'New account';
    } else {
        if ($Me->contactId !== $Acct->contactId) {
            echo $Me->reviewer_html_for($Acct), ' ';
        }
        echo htmlspecialchars($UserStatus->gxt()->get($profile_topic)->title);
        if ($Acct->is_disabled()) {
            echo ' <span class="n dim">(disabled)</span>';
        }
    }
    echo '</h2>';
}

if ($UserStatus->has_messages()) {
    $status = 0;
    $msgs = [];
    foreach ($UserStatus->message_list() as $m) {
        $status = max($m->status, $status);
        $msgs[] = $m->message;
    }
    echo '<div class="msgs-wide">', Ht::msg($msgs, $status), "</div>\n";
}

$UserStatus->set_context_args([$UserStatus, $Qreq]);
$UserStatus->render_group($newProfile === 2 ? "__bulk" : $profile_topic);

if ($newProfile !== 2) {
    if ($UserStatus->global_self() && false) {
        echo '<div class="form-g"><div class="checki"><label><span class="checkc">',
            Ht::checkbox("saveglobal", 1, $useRequest ? !!$Qreq->saveglobal : true, ["class" => "ignore-diff"]),
            '</span>Update global profile</label></div></div>';
    }

    echo "</div>"; // foldaccount
}

echo "</main></form>";

if (!$newProfile) {
    Ht::stash_script('hotcrp.highlight_form_children("#form-profile")');
}
$Conf->footer();
