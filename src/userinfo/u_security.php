<?php
// u_security.php -- HotCRP Profile > Security
// Copyright (c) 2008-2024 Eddie Kohler; see LICENSE.

class Security_UserInfo {
    /** @var UserStatus
     * @readonly */
    public $us;
    /** @var ?array{string,string} */
    private $_req_passwords;

    function __construct(UserStatus $us) {
        $this->us = $us;
    }

    function request(UserStatus $us) {
        if (!$us->allow_some_security()) {
            return;
        }
        $us->request_group("security");
    }

    /** @return bool */
    function allow_security_changes() {
        return $this->us->has_recent_authentication();
    }

    function print(UserStatus $us) {
        if (!$us->allow_some_security()) {
            if ($us->viewer->is_actas_user()) {
                $us->conf->warning_msg("<0>You cannot edit other users’ security settings.");
            } else {
                $us->conf->warning_msg("<0>You can only access your own account’s security settings.");
            }
            return false;
        } else if ($us->user->security_locked()) {
            $us->conf->warning_msg("<0>This account‘s security settings are locked and cannot be changed.");
            return false;
        }
    }

    function parse_qreq_new_password(UserStatus $us) {
        $pw = trim($us->qreq->upassword ?? "");
        $pw2 = trim($us->qreq->upassword2 ?? "");
        $this->_req_passwords = [$us->qreq->upassword ?? "", $us->qreq->upassword2 ?? ""];
        if (($pw === "" && $pw2 === "")
            || !$us->has_recent_authentication()
            || !$us->viewer->can_edit_password($us->user)) {
            return;
        }
        if ($pw !== $pw2) {
            $us->error_at("password", "<0>Passwords do not match");
            $us->error_at("upassword2");
        } else if (strlen($pw) <= 5) {
            $us->error_at("password", "<0>Password too short");
        } else if (!Contact::valid_password($pw)) {
            $us->error_at("password", "<0>Invalid new password");
        } else {
            $us->jval->new_password = $pw;
        }
    }

    static function print_reauthenticate(UserStatus $us) {
        if ($us->has_recent_authentication()) {
            return;
        }
        $us->cs()->add_section_class("form-outline-section tag-yellow w-text")
            ->print_start_section("Confirm account", "reauth");
        echo '<p>Please re-enter your signin credentials if you want to change these security-sensitive settings.</p>';
        $original_ignore_msgs = $us->swap_ignore_messages(false);
        $us->swap_ignore_messages($original_ignore_msgs);
        echo '<div class="', $us->control_class("reauth:password", "f-i w-text"), '">',
            '<label for="reauth:password">',
            $us->is_auth_self() ? "Current password" : "Current password for " . htmlspecialchars($us->viewer->email),
            '</label>',
            $us->feedback_html_at("reauth:password"),
            Ht::entry("reauth:email", $us->viewer->email, ["autocomplete" => "username", "class" => "hidden ignore-diff", "readonly" => true]),
            Ht::password("reauth:password", "", ["size" => 52, "autocomplete" => "current-password", "class" => "ignore-diff", "id" => "reauth:password"]),
            '</div>',
            '<div class="aab aabig mb-0">',
            Ht::submit("reauth", "Confirm account", ["class" => "btn-success mt-0"]),
            '</div>';
    }

    function print_new_password(UserStatus $us) {
        if (!$us->viewer->can_edit_password($us->user)
            || !$us->has_recent_authentication()) {
            return;
        }
        $us->print_start_section("Change password");
        $pws = $this->_req_passwords ?? ["", ""];
        $open = $pws[0] !== "" || $pws[1] !== ""
            || $us->has_problem_at("upassword") || $us->has_problem_at("upassword2");
        echo '<div class="has-fold foldc ui-fold js-fold-focus">';
        if (!$open) {
            echo '<div class="fn">',
                Ht::button("Change password", ["class" => "ui js-foldup"]),
                '</div><div class="fx">';
        }
        echo '<div class="', $us->control_class("password", "f-i w-text"), '">',
            '<label for="upassword">New password</label>',
            $us->feedback_html_at("password"),
            $us->feedback_html_at("upassword"),
            Ht::password("upassword", $pws[0], ["size" => 52, "autocomplete" => $us->autocomplete("new-password"), "class" => "want-focus"]),
            '</div>',
            '<div class="', $us->control_class("upassword2", "f-i w-text"), '">',
            '<label for="upassword2">Repeat new password</label>',
            $us->feedback_html_at("upassword2"),
            Ht::password("upassword2", $pws[1], ["size" => 52, "autocomplete" => $us->autocomplete("new-password")]),
            '</div>', $open ? '' : '</div>', '</div>';
        $us->mark_inputs_printed();
    }

    static function save_new_password(UserStatus $us) {
        if (isset($us->jval->new_password)
            && $us->viewer->can_edit_password($us->user)) {
            $us->user->change_password($us->jval->new_password);
            $us->diffs["password"] = true;
        }
    }
}
