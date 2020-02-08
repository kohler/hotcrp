<?php
// src/partials/p_signin.php -- HotCRP password reset partials
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Signin_Partial {
    private $_reset_cap;
    private $_reset_capdata;
    private $_reset_user;
    private $_stop = false;

    private function forgot_message(Conf $conf) {
        return $conf->_("Enter your email and we’ll send you instructions for signing in.");
    }
    private function _render_email_entry($user, $qreq, $k) {
        echo '<div class="', Ht::control_class($k, "f-i"), '">',
            '<label for="', $k, '">',
            ($k === "email" ? "Email" : "Email or password reset code"),
            '</label>',
            Ht::entry($k, $qreq[$k], ["class" => "want-focus fullw", "size" => 36, "id" => $k, "autocomplete" => ($k === "email" ? "email" : null)]),
            Ht::render_messages_at("resetcap"),
            Ht::render_messages_at("email"),
            '</div>';
    }


    // Create account request
    private function _create_message(Conf $conf) {
        return $conf->_("Enter your email and we’ll create an account and send you an initial password.");
    }
    function create_request(Contact $user, Qrequest $qreq) {
        if ($qreq->cancel && $qreq->post_ok()) {
            Navigation::redirect("");
        } else if (!$user->conf->allow_user_self_register()) {
            $user->conf->msg("New users can’t self-register for this site.", 2);
            $this->_stop = true;
            return;
        }

        ensure_session();
        $email = trim((string) $qreq->email);
        if ($email !== "" && $qreq->go && $qreq->post_ok()) {
            $url = LoginHelper::login($user->conf, $qreq, "create");
            if ($url !== false) {
                Navigation::redirect("");
            }
        }
    }
    function render_create_head(Contact $user, Qrequest $qreq, $gx) {
        $user->conf->header("New account", "newaccount", ["action_bar" => false]);
        $gx->push_render_cleanup("__footer");
        return !$this->_stop;
    }
    function render_create_body(Contact $user, Qrequest $qreq, $gx, $gj) {
        echo '<div class="homegrp" id="homeaccount">',
            Ht::form($user->conf->hoturl("newaccount"), ["class" => "compact-form"]),
            Ht::hidden("post", post_value());
        if (($m = $this->_create_message($user->conf))) {
            echo '<p class="mb-5">', $m, '</p>';
        }
        $this->_render_email_entry($user, $qreq, "email");
        echo '<div class="popup-actions">',
            Ht::submit("go", "Create account", ["class" => "btn-success", "value" => 1]),
            Ht::submit("cancel", "Cancel"),
            '</div>';
        echo '</form></div>';
        Ht::stash_script("focus_within(\$(\"#homeaccount\"));window.scroll(0,0)");
    }


    // Forgot password request
    function check_password_links(Contact $user, Qrequest $qreq) {
        if ($qreq->cancel && $qreq->post_ok()) {
            Navigation::redirect("");
        } else if ($user->conf->external_login()) {
            $user->conf->msg("Password reset links aren’t used for this conference. Contact your system administrator if you’ve forgotten your password.", 2);
            $this->_stop = true;
        }
    }
    function forgot_request(Contact $user, Qrequest $qreq) {
        self::check_password_links($user, $qreq);
        if (!$this->_stop) {
            ensure_session();
            $email = trim((string) $qreq->email);
            if ($email !== "" && $qreq->go && $qreq->post_ok()) {
                $url = LoginHelper::forgot_password($user->conf, $qreq);
                if ($url !== false) {
                    Navigation::redirect("");
                }
            }
        }
    }
    function render_forgot_head(Contact $user, Qrequest $qreq, $gx) {
        $user->conf->header("Forgot password", "resetpassword", ["action_bar" => false]);
        $gx->push_render_cleanup("__footer");
        return !$this->_stop;
    }
    function render_forgot_body(Contact $user, Qrequest $qreq, $gx, $gj) {
        echo '<div class="homegrp" id="homeaccount">',
            Ht::form($user->conf->hoturl("forgotpassword"), ["class" => "compact-form"]),
            Ht::hidden("post", post_value()),
            '<p class="mb-5">', $this->forgot_message($user->conf), '</p>';
        $this->_render_email_entry($user, $qreq, "email");
        echo '<div class="popup-actions">',
            Ht::submit("go", "Reset password", ["class" => "btn-primary", "value" => 1]),
            Ht::submit("cancel", "Cancel"),
            '</div>';
        echo '</form></div>';
        Ht::stash_script("focus_within(\$(\"#homeaccount\"));window.scroll(0,0)");
    }


    // Password reset
    function reset_request(Contact $user, Qrequest $qreq) {
        global $Now;
        self::check_password_links($user, $qreq);
        if ($this->_stop) {
            return;
        }

        ensure_session();
        $conf = $user->conf;
        if ($qreq->resetcap === null
            && preg_match('{\A/(U?1[-\w]+)(?:/|\z)}', Navigation::path(), $m)) {
            $qreq->resetcap = $m[1];
        }

        $resetcap = trim((string) $qreq->resetcap);
        if ($resetcap === "") {
            // nothing
        } else if (strpos($resetcap, "@") !== false) {
            if ($qreq->go
                && $qreq->post_ok()) {
                $nqreq = new Qrequest("POST", ["email" => $resetcap]);
                $nqreq->approve_post();
                $url = LoginHelper::forgot_password($conf, $nqreq);
                if ($url !== false) {
                    $conf->self_redirect();
                }
                if (Ht::problem_status_at("email")) {
                    Ht::error_at("resetcap");
                }
            }
        } else {
            if (preg_match('{\A/?(U?1[-\w]+)/?\z}', $resetcap, $m)) {
                $this->_reset_cap = $m[1];
            }
            if ($this->_reset_cap) {
                $capmgr = $conf->capability_manager($this->_reset_cap);
                $this->_reset_capdata = $capmgr->check($this->_reset_cap);
            }
            if (!$this->_reset_capdata
                || $this->_reset_capdata->capabilityType != CAPTYPE_RESETPASSWORD) {
                Ht::error_at("resetcap", "Unknown or expired password reset code. Please check that you entered the code correctly.");
                $this->_reset_capdata = null;
            }
        }

        if ($this->_reset_capdata) {
            if (str_starts_with($this->_reset_cap, "U")) {
                $this->_reset_user = $conf->contactdb_user_by_id($this->_reset_capdata->contactId);
            } else {
                $this->_reset_user = $conf->user_by_id($this->_reset_capdata->contactId);
            }
        }

        if ($this->_reset_user
            && $qreq->go
            && $qreq->post_ok()) {
            $p1 = (string) $qreq->password;
            $p2 = (string) $qreq->password2;
            if ($p1 === "") {
                if ($p2 !== "" || $qreq->autopassword) {
                    Ht::error_at("password", "Password required.");
                }
            } else if (trim($p1) !== $p1) {
                Ht::error_at("password", "Passwords cannot begin or end with spaces.");
                Ht::error_at("password2");
            } else if (strlen($p1) < 4) {
                Ht::error_at("password", "Password too short.");
                Ht::error_at("password2");
            } else if (!Contact::valid_password($p1)) {
                Ht::error_at("password", "Invalid password.");
                Ht::error_at("password2");
            } else if ($p1 !== $p2) {
                Ht::error_at("password", "The passwords you entered did not match.");
                Ht::error_at("password2");
            } else {
                $accthere = $conf->user_by_email($this->_reset_user->email)
                    ? : Contact::create($conf, null, $this->_reset_user);
                $accthere->change_password($p1, 0);
                $accthere->log_activity("Password reset via " . substr($this->_reset_cap, 0, 8) . "...");
                $conf->confirmMsg("Your password has been changed. You may now sign in to the conference site.");
                $capmgr->delete($this->_reset_capdata);
                $user->save_session("password_reset", (object) [
                    "time" => $Now, "email" => $this->_reset_user->email, "password" => $p1
                ]);
                Navigation::redirect($conf->hoturl("index", ["signin" => 1]));
            }
        } else if (!$this->_reset_user
                   && $this->_reset_capdata) {
            Ht::error_at("resetcap", "This password reset code refers to a user who no longer exists. Either create a new account or contact the conference administrator.");
        } else if ($qreq->cancel) {
            Navigation::redirect($conf->hoturl("index"));
        }
    }
    function render_reset_head(Contact $user, Qrequest $qreq, $gx, $gj) {
        $user->conf->header($gj->htitle, "resetpassword", ["action_bar" => false]);
        $gx->push_render_cleanup("__footer");
        return !$this->_stop;
    }
    private function _render_reset_success($user, $qreq) {
        if (!isset($qreq->autopassword)
            || trim($qreq->autopassword) !== $qreq->autopassword
            || strlen($qreq->autopassword) < 16
            || !preg_match('{\A[-0-9A-Za-z@_+=]*\z}', $qreq->autopassword)) {
            $qreq->autopassword = Contact::random_password();
        }
        echo Ht::hidden("resetcap", $this->_reset_cap),
            Ht::hidden("autopassword", $qreq->autopassword),
            '<p class="mb-5">Use this form to reset your password. You may want to use the random password we’ve chosen.</p>',
            '<div class="f-i"><label>Email</label>', htmlspecialchars($this->_reset_user->email), '</div>',
            Ht::entry("email", $this->_reset_user->email, ["class" => "hidden", "autocomplete" => "username"]),
            '<div class="f-i"><label>Suggested password</label>',
            htmlspecialchars($qreq->autopassword), '</div>',

            '<div class="', Ht::control_class("password", "f-i"), '">',
            '<label for="password">New password</label>',
            Ht::password("password", "", ["class" => "want-focus fullw", "size" => 36, "id" => "password", "autocomplete" => "new-password"]),
            Ht::render_messages_at("password"),
            '</div>',

            '<div class="', Ht::control_class("password2", "f-i"), '">',
            '<label for="password2">Repeat new password</label>',
            Ht::password("password2", "", ["class" => "fullw", "size" => 36, "id" => "password2", "autocomplete" => "new-password"]),
            Ht::render_messages_at("password2"),
            '</div>';
    }
    function render_reset_body(Contact $user, Qrequest $qreq, $gx, $gj) {
        echo '<div class="homegrp" id="homeaccount">',
            Ht::form($user->conf->hoturl("resetpassword"), ["class" => "compact-form"]),
            Ht::hidden("post", post_value());
        if ($this->_reset_user) {
            $this->_render_reset_success($user, $qreq);
            $k = "btn-danger";
        } else {
            echo '<p class="mb-5">', $this->forgot_message($user->conf),
                ' Or enter a password reset code if you have one.</p>';
            $this->_render_email_entry($user, $qreq, "resetcap");
            $k = "btn-primary";
        }
        echo '<div class="popup-actions">',
            Ht::submit("go", "Reset password", ["class" => $k, "value" => 1]),
            Ht::submit("cancel", "Cancel"),
            '</div>';
        echo '</form></div>';
        Ht::stash_script("focus_within(\$(\"#homeaccount\"));window.scroll(0,0)");
    }
}
