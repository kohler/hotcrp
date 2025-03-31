<?php
// authenticationchecker.php -- HotCRP class for reauthenticating users
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class AuthenticationChecker {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var Qrequest
     * @readonly */
    protected $qreq;
    /** @var string
     * @readonly */
    public $reason;
    /** @var int
     * @readonly */
    public $max_age;
    /** @var ?bool */
    protected $ok;
    /** @var ?string */
    protected $actions_class;
    /** @var ?list<string> */
    protected $additional_actions;
    /** @var ?string */
    protected $redirect;

    function __construct(Contact $user, Qrequest $qreq, $reason) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->reason = $reason;
        if ($reason === "manageemail") {
            $this->max_age = 3600;
        } else {
            $this->max_age = 600;
        }
    }

    /** @param string $url
     * @return $this */
    function set_redirect($url) {
        $this->redirect = $url;
        return $this;
    }

    /** @param ?string $class
     * @return $this */
    function set_actions_class($class) {
        $this->actions_class = $class;
        return $this;
    }

    /** @param string ...$actions
     * @return $this */
    function add_actions(...$actions) {
        $this->additional_actions = $this->additional_actions ?? [];
        array_push($this->additional_actions, ...$actions);
        return $this;
    }


    /** @return string */
    function redirect() {
        if ($this->redirect !== null) {
            return $this->redirect;
        }
        $nav = $this->qreq->navigation();
        return $nav->site_path . $nav->raw_page . $nav->query;
    }

    /** @return string */
    function actions_class() {
        return $this->actions_class ?? "aax fullw mt-3";
    }

    /** @return list<string> */
    function additional_actions() {
        return $this->additional_actions ?? [];
    }



    /** @return Generator<UserSecurityEvent> */
    function security_events() {
        return UserSecurityEvent::session_list_by_email($this->qreq->qsession(), $this->user->email);
    }

    /** @return bool */
    function test() {
        if ($this->ok !== null) {
            return $this->ok;
        }
        $this->ok = false;
        if (!$this->user->has_email()) {
            return false;
        }
        if ($this->user === $this->qreq->user()
            && $this->user->is_bearer_authorized()) {
            $this->ok = true;
            return true;
        }
        foreach ($this->security_events() as $use) {
            if (($use->reason === UserSecurityEvent::REASON_REAUTH
                 && $use->timestamp >= Conf::$now - $this->max_age)
                || ($use->reason === UserSecurityEvent::REASON_SIGNIN
                    && $use->timestamp >= Conf::$now - min(120, $this->max_age))) {
                $this->ok = $use->success;
            }
        }
        return $this->ok;
    }

    protected function print_actions(...$actions) {
        echo '<div class="', $this->actions_class(), '">',
            join("", $actions), join("", $this->additional_actions()),
            '</div>';
    }

    function print() {
        $use = null;
        foreach ($this->security_events() as $usex) {
            if ($usex->reason === UserSecurityEvent::REASON_SIGNIN
                && ($usex->type === UserSecurityEvent::TYPE_PASSWORD
                    || $usex->type === UserSecurityEvent::TYPE_OAUTH))
                $use = $usex;
        }
        if (!$use && $this->user->can_use_password()) {
            $use = UserSecurityEvent::make($this->user->email, UserSecurityEvent::TYPE_PASSWORD);
        }
        if (!$use) {
            echo Ht::feedback_msg(MessageItem::error("<5><strong>Account {$this->user->email} cannot be confirmed using this session.</strong> Please sign out and sign in again and retry.")),
                '<div class="', $this->actions_class(), '">',
                Ht::submit("Sign out", ["type" => "submit", "class" => "btn-danger", "form" => "f-signout"]),
                '</div>';
            Ht::stash_html(Ht::form($this->conf->hoturl("=signout", ["cap" => null]), ["id" => "f-signout"]) . "</form>", "f-signout");
            return false;
        }
        echo Ht::hidden("reason", $this->reason, ["form" => "f-reauth", "class" => "ignore-diff"]);

        // password
        if ($use->type === UserSecurityEvent::TYPE_PASSWORD) {
            echo '<div class="f-i"><label for="k-reauth-password">Current password for ',
                htmlspecialchars($this->user->email), '</label>',
                Ht::entry("email", $this->user->email, ["autocomplete" => "username", "class" => "ignore-diff", "readonly" => true, "form" => "f-reauth", "hidden" => true]),
                Ht::password("password", "", ["size" => 52, "autocomplete" => "current-password", "class" => "ignore-diff", "id" => "k-reauth-password", "form" => "f-reauth", "required" => true]),
                '</div>';
            $this->print_actions(Ht::submit("Confirm account", [
                "class" => "btn-success",
                "form" => "f-reauth"
            ]));
            return true;
        }

        // OAuth
        $authi = null;
        foreach ($this->conf->oauth_providers() as $authdata) {
            if ($authdata->name === $use->subtype
                && !($authdata->disabled ?? false)) {
                $authi = $authdata;
            }
        }
        if (!$authi) {
            return false;
        }
        $url = $this->conf->hoturl("oauth", [
            "reauth" => 1, "max_age" => $this->max_age, "redirect" => $this->redirect()
        ], Conf::HOTURL_SITEREL | Conf::HOTURL_RAW);
        if (($uindex = Contact::session_index_by_email($this->qreq, $this->user->email)) >= 0) {
            $url = $this->qreq->navigation()->base_path . "u/{$uindex}/" . $url;
        } else {
            $url = $this->qreq->navigation()->site_path . $url;
        }
        $this->print_actions(Ht::submit("Confirm " . htmlspecialchars($this->user->email), [
            "class" => "btn-success",
            "form" => "f-reauth",
            "formaction" => htmlspecialchars($url),
            "formmethod" => "post"
        ]));
        return true;
    }

    function api() {
        if (!isset($this->qreq->password)) {
            return JsonResult::make_missing_error("password");
        }
        $info = $this->user->check_password_info($this->qreq->password);
        foreach ($info["usec"] ?? [] as $use) {
            $use->set_reason(UserSecurityEvent::REASON_REAUTH)
                ->store($this->qreq->qsession());
        }
        $ms = new MessageSet;
        if ($info["ok"]) {
            if (friendly_boolean($this->qreq->confirm)) {
                $ms->success("<0>Reauthentication succeeded");
            }
        } else {
            $info["field"] = "password";
            LoginHelper::login_error($this->conf, $this->user->email, $info, $ms);
        }
        return JsonResult::make_message_list($ms);
    }
}
