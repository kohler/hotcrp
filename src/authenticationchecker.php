<?php
// authenticationchecker.php -- HotCRP class for reauthenticating users
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class AuthenticationChecker {
    /** @var Conf */
    private $conf;
    /** @var Contact */
    private $user;
    /** @var Qrequest */
    private $qreq;
    /** @var string */
    public $reason;
    /** @var int */
    public $max_age;
    /** @var ?bool */
    private $ok;

    function __construct(Contact $user, Qrequest $qreq, $reason) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->reason = $reason;
        $this->max_age = 600;
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
        foreach ($this->security_events() as $use) {
            if ($use->reason === UserSecurityEvent::REASON_REAUTH
                && $use->timestamp >= Conf::$now - $this->max_age) {
                $this->ok = $use->success;
            }
        }
        return $this->ok;
    }

    function print() {
        $use = null;
        foreach ($this->security_events() as $usex) {
            if ($usex->reason === UserSecurityEvent::REASON_SIGNIN
                && ($usex->type === UserSecurityEvent::TYPE_PASSWORD
                    || $usex->type === UserSecurityEvent::TYPE_OAUTH))
                $use = $usex;
        }
        if (!$use) {
            return false;
        }
        echo Ht::hidden("reason", $this->reason, ["form" => "f-reauth"]);

        // password
        if ($use->type === UserSecurityEvent::TYPE_PASSWORD) {
            echo '<div class="f-i"><label for="k-reauth-password">Current password for ',
                htmlspecialchars($this->user->email), '</label>',
                Ht::entry("email", $this->user->email, ["autocomplete" => "username", "class" => "ignore-diff", "readonly" => true, "form" => "f-reauth", "hidden" => true]),
                Ht::password("password", "", ["size" => 52, "autocomplete" => "current-password", "class" => "ignore-diff", "id" => "k-reauth-password", "form" => "f-reauth"]),
                '</div><div class="mt-3">',
                Ht::submit("Confirm account", [
                    "class" => "btn-success w-100 flex-grow-1",
                    "form" => "f-reauth"
                ]),
                '</div>';
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
        echo Ht::submit("Confirm account", [
            "class" => "btn-success w-100 flex-grow-1",
            "form" => "f-reauth",
            "formaction" => $this->conf->hoturl("oauth", ["reauth" => 1, "max_age" => $this->max_age, "redirect" => $this->conf->selfurl($this->qreq, null, Conf::HOTURL_SITEREL)]),
            "formmethod" => "post"
        ]);
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
