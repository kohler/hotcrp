<?php
// u_security.php -- HotCRP Profile > Security
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Security_UserInfo {
    /** @var UserStatus
     * @readonly */
    public $us;
    /** @var ?array{string,string} */
    private $_req_passwords;

    function __construct(UserStatus $us) {
        $this->us = $us;
    }

    function display_if() {
        if ($this->us->is_actas_self()) {
            return "dim";
        }
        return $this->us->is_auth_self() || $this->us->viewer->can_edit_any_password();
    }

    function request() {
        $this->us->request_group("security");
    }

    function save() {
        $this->us->save_members("security");
    }

    /** @return bool
     * @deprecated */
    function allow_security_changes() {
        return $this->us->has_recent_authentication();
    }

    function print(UserStatus $us) {
        if ($us->viewer->is_actas_user()) {
            $us->conf->warning_msg("<0>Security settings cannot be edited when acting as another user.");
            return false;
        }
        if (!$us->is_auth_self() && !$us->viewer->can_edit_any_password()) {
            $us->conf->warning_msg("<0>You can only access your own account’s security settings.");
            return false;
        }
        if ($us->user->security_locked()) {
            $us->conf->warning_msg("<0>This account‘s security settings are locked and cannot be changed.");
            return false;
        }
    }

    function parse_qreq_new_password(UserStatus $us) {
        $pw = trim($us->qreq->upassword ?? "");
        $pw2 = trim($us->qreq->upassword2 ?? "");
        $this->_req_passwords = [$us->qreq->upassword ?? "", $us->qreq->upassword2 ?? ""];
        if ($pw === "" && $pw2 === "") {
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
        $us->cs()->add_section_class("form-outline-section reauthentication-section maxw-480")
            ->print_start_section("Confirm account", "reauth");
        echo '<p>You must confirm your account to make changes to security settings.</p>';
        $us->authentication_checker()->print();
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
        echo $us->feedback_html_at("password:context", ["class" => "mb-3"]),
            '<div class="', $us->control_class("password", "f-i w-text"), '">',
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
        // This function is protected by `request_recent_authentication`,
        // which checks `has_recent_authentication`. But check it explicitly
        // for clarity.
        // `Contact::can_edit_password` checks `security_locked`.
        if (!isset($us->jval->new_password)
            || !$us->viewer->can_edit_password($us->user)
            || !$us->has_recent_authentication()) {
            return;
        }
        $us->user->change_password($us->jval->new_password);
        $us->diffs["password"] = true;
    }
}
