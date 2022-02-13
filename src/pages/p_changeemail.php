<?php
// pages/p_changeemail.php -- HotCRP profile page for email changes
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ChangeEmail_Page {
    static function go(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        ensure_session();
        $capdata = TokenInfo::find(trim($qreq->changeemail), $conf);
        $capcontent = null;
        if (!$capdata
            || !$capdata->contactId
            || $capdata->capabilityType !== TokenInfo::CHANGEEMAIL
            || !$capdata->is_active()
            || !($capcontent = json_decode($capdata->data))
            || !is_object($capcontent)
            || !($capcontent->uemail ?? null)) {
            if (trim($qreq->changeemail) !== "1") {
                Ht::error_at("changeemail", "<0>That email change code has expired, or you didn’t enter it correctly");
            }
            $capdata = false;
        }

        $chuser = null;
        if ($capdata && !($chuser = $conf->user_by_id($capdata->contactId))) {
            Ht::error_at("changeemail", "<0>The account associated with that email change code no longer exists");
        }
        if ($chuser && strcasecmp($chuser->email, $capcontent->oldemail) !== 0) {
            Ht::error_at("changeemail", "<0>You have changed your email address since creating that email change code");
            $chuser = null;
        }

        $newemail = $chuser ? $capcontent->uemail : null;
        if ($chuser && $conf->user_by_email($newemail)) {
            $conf->feedback_msg([
                MessageItem::error("<0>The email address you requested, {$newemail}, is already in use on this site"),
                MessageItem::inform("<5>You may want to <a href=\"" . $conf->hoturl("mergeaccounts") . "\">merge these accounts</a>.")
            ]);
            return false;
        }

        $newcdbu = $newemail ? $conf->cdb_user_by_email($newemail) : null;
        if ($newcdbu) {
            if ($newcdbu->contactdb_disabled()) { // NB do not use is_disabled()
                Ht::error_at("changeemail", "<0>That user is disabled on all sites");
            } else if ($qreq->go && $qreq->valid_post()) {
                $qreq->password = trim((string) $qreq->password);
                $info = $newcdbu->check_password_info($qreq->password);
                if (!$info["ok"]) {
                    $qreqa = ["email" => $newemail] + $qreq->as_array();
                    LoginHelper::login_error($conf, new Qrequest("POST", $qreqa), $info);
                    unset($qreq->go);
                }
            }
        }

        if ($newemail
            && $qreq->go
            && $qreq->valid_post()) {
            $chuser->change_email($newemail);
            $capdata->delete();
            $conf->success_msg("<0>Your email address has been changed");
            if (!$user->has_account_here() || $user->contactId == $chuser->contactId) {
                Contact::set_main_user($chuser->activate($qreq));
            }
            if (Contact::session_user_index($capcontent->oldemail) >= 0) {
                LoginHelper::change_session_users([
                    $capcontent->oldemail => -1, $newemail => 1
                ]);
            }
            $conf->redirect_hoturl("profile");
        } else {
            $conf->header("Change email", "account", ["action_bar" => false]);
            if ($chuser) {
                echo '<p class="mb-5">Complete the email change using this form.</p>';
            } else {
                echo '<p class="mb-5">Enter an email change code.</p>';
            }
            echo Ht::form($conf->hoturl("profile", "changeemail=1"), ["class" => "compact-form", "id" => "changeemailform"]),
                Ht::hidden("post", post_value());
            if ($chuser) {
                echo '<div class="f-i"><label>Old email</label>', htmlspecialchars($chuser->email), '</div>',
                    '<div class="f-i"><label>New email</label>',
                    Ht::entry("email", $newemail, ["autocomplete" => "username", "readonly" => true, "class" => "fullw"]),
                    '</div>';
            }
            echo '<div class="', Ht::control_class("changeemail", "f-i"), '"><label for="changeemail">Change code</label>',
                Ht::feedback_html_at("changeemail"),
                Ht::entry("changeemail", $qreq->changeemail == "1" ? "" : $qreq->changeemail, ["id" => "changeemail", "class" => "fullw", "autocomplete" => "one-time-code"]),
                '</div>';
            if ($newcdbu) {
                echo '<div class="', Ht::control_class("password", "f-i"), '"><label for="password">Password for ', htmlspecialchars($newemail), '</label>',
                Ht::feedback_html_at("password"),
                Ht::password("password", "", ["autocomplete" => "password", "class" => "fullw"]),
                '</div>';
            }
            echo '<div class="popup-actions">',
                Ht::submit("go", "Change email", ["class" => "btn-primary", "value" => 1]),
                Ht::submit("cancel", "Cancel", ["formnovalidate" => true]),
                '</div></form>';
            Ht::stash_script("hotcrp.focus_within(\$(\"#changeemailform\"));window.scroll(0,0)");
            $conf->footer();
            exit;
        }
    }
}
