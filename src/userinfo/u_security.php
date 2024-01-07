<?php
// u_security.php -- HotCRP Profile > Security
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class Security_UserInfo {
    /** @var UserStatus
     * @readonly */
    public $us;
    /** @var bool */
    private $_approved = false;
    /** @var bool */
    private $_complained = false;
    /** @var ?list<MessageItem> */
    private $_approval_errors;
    /** @var ?array{string,string} */
    private $_req_passwords;

    function __construct(UserStatus $us) {
        $this->us = $us;
        $this->_approved = $us->allow_some_security()
            && UpdateSession::usec_query($us->qreq, $us->viewer->email, 0, 1, Conf::$now - 300);
    }

    function request(UserStatus $us) {
        if (!$us->allow_some_security()) {
            return;
        }
        $pw = trim($us->qreq->oldpassword ?? "");
        if ($pw === "") {
            if (!$this->_approved) {
                $this->_approval_errors[] = MessageItem::error_at("oldpassword", "<0>Current password required");
            }
        } else {
            $info = $us->viewer->check_password_info($pw);
            UpdateSession::usec_add_list($us->qreq, $us->viewer->email, $info["usec"] ?? [], 1);
            $this->_approved = $info["ok"];
            if (!$this->_approved) {
                $this->_approval_errors[] = MessageItem::error_at("oldpassword", "<0>Incorrect current password");
            }
        }
        $us->request_group("security");
    }

    /** @return bool */
    function allow_security_changes() {
        if (!$this->_approved && !$this->_complained) {
            $this->_approval_errors[] = new MessageItem("oldpassword", "<0>Changes to security settings were ignored.", MessageSet::INFORM);
            $this->us->append_list($this->_approval_errors);
        }
        return $this->_approved;
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
            || !$this->allow_security_changes()
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

    function print_current_password(UserStatus $us) {
        if ($this->_approved) {
            return;
        }
        $original_ignore_msgs = $us->swap_ignore_messages(false);
        if ($us->is_auth_self()) {
            $us->msg_at("oldpassword", "<0>Enter your current password to make changes to security settings", MessageSet::WARNING_NOTE);
        } else {
            $us->msg_at("oldpassword", "<0>Enter your current password to make changes to other users’ security settings", MessageSet::WARNING_NOTE);
        }
        $us->swap_ignore_messages($original_ignore_msgs);
        echo '<div class="', $us->control_class("oldpassword", "f-i w-text"), '">',
            '<label for="oldpassword">',
            $us->is_auth_self() ? "Current password" : "Current password for " . htmlspecialchars($us->viewer->email),
            '</label>',
            $us->feedback_html_at("oldpassword"),
            Ht::entry("viewer_email", $us->viewer->email, ["autocomplete" => "username", "class" => "hidden ignore-diff", "readonly" => true]),
            Ht::password("oldpassword", "", ["size" => 52, "autocomplete" => "current-password", "class" => "ignore-diff uii js-profile-current-password", "id" => "oldpassword", "autofocus" => true]),
            '</div>';
    }

    function print_new_password(UserStatus $us) {
        if (!$us->viewer->can_edit_password($us->user)) {
            return;
        }
        $us->print_start_section("Change password");
        $pws = $this->_req_passwords ?? ["", ""];
        $open = $pws[0] !== "" || $pws[1] !== ""
            || $us->has_problem_at("upassword") || $us->has_problem_at("upassword2");
        echo '<div class="has-fold foldc ui-fold js-fold-focus">';
        if (!$open) {
            echo '<div class="fn">',
                Ht::button("Change password", ["class" => "ui js-foldup need-profile-current-password", "disabled" => !$this->_approved]),
                '</div><div class="fx">';
        }
        echo '<div class="', $us->control_class("password", "f-i w-text"), '">',
            '<label for="upassword">New password</label>',
            $us->feedback_html_at("password"),
            $us->feedback_html_at("upassword"),
            Ht::password("upassword", $pws[0], ["size" => 52, "autocomplete" => $us->autocomplete("new-password"), "disabled" => !$this->_approved, "class" => "need-profile-current-password want-focus"]),
            '</div>',
            '<div class="', $us->control_class("upassword2", "f-i w-text"), '">',
            '<label for="upassword2">Repeat new password</label>',
            $us->feedback_html_at("upassword2"),
            Ht::password("upassword2", $pws[1], ["size" => 52, "autocomplete" => $us->autocomplete("new-password"), "disabled" => !$this->_approved, "class" => "need-profile-current-password"]),
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
