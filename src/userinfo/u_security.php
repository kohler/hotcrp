<?php
// u_security.php -- HotCRP Profile > Security
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Security_UserInfo {
    /** @var ?array{string,string} */
    private $_req_passwords;

    static function request(UserStatus $us) {
        if ($us->allow_security()) {
            if (trim($us->qreq->oldpassword ?? "") !== "") {
                $us->has_req_security();
            }
            $us->request_group("security");
        }
    }


    static function print_current_password(UserStatus $us) {
        $original_ignore_msgs = $us->swap_ignore_messages(false);
        $us->msg_at("oldpassword", "<0>Enter your current password to make changes to security settings", MessageSet::WARNING_NOTE);
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
        if ($us->viewer->can_change_password($us->user)) {
            $us->print_start_section("Change password");
            $pws = $this->_req_passwords ?? ["", ""];
            $open = $pws[0] !== "" || $pws[1] !== ""
                || $us->has_problem_at("upassword") || $us->has_problem_at("upassword2");
            echo '<div class="has-fold foldc ui-fold js-fold-focus">';
            if (!$open) {
                echo '<div class="fn">',
                    Ht::button("Change password", ["class" => "ui js-foldup need-profile-current-password", "disabled" => true]),
                    '</div><div class="fx">';
            }
            echo '<div class="', $us->control_class("password", "f-i w-text"), '">',
                '<label for="upassword">New password</label>',
                $us->feedback_html_at("password"),
                $us->feedback_html_at("upassword"),
                Ht::password("upassword", $pws[0], ["size" => 52, "autocomplete" => $us->autocomplete("new-password"), "disabled" => true, "class" => "need-profile-current-password want-focus"]),
                '</div>',
                '<div class="', $us->control_class("upassword2", "f-i w-text"), '">',
                '<label for="upassword2">Repeat new password</label>',
                $us->feedback_html_at("upassword2"),
                Ht::password("upassword2", $pws[1], ["size" => 52, "autocomplete" => $us->autocomplete("new-password"), "disabled" => true, "class" => "need-profile-current-password"]),
                '</div>', $open ? '' : '</div>', '</div>';
        }
    }

    function request_new_password(UserStatus $us) {
        $pw = trim($us->qreq->upassword ?? "");
        $pw2 = trim($us->qreq->upassword2 ?? "");
        $this->_req_passwords = [$us->qreq->upassword ?? "", $us->qreq->upassword2 ?? ""];
        if ($pw === "" && $pw2 === "") {
            // do nothing
        } else if ($us->has_req_security()
                   && $us->viewer->can_change_password($us->user)) {
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
    }

    static function save_new_password(UserStatus $us) {
        if (isset($us->jval->new_password)) {
            $us->user->change_password($us->jval->new_password);
            $us->diffs["password"] = true;
        }
    }
}
