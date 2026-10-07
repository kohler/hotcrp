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
    protected $caller_id;
    /** @var int */
    protected $max_age = 600;
    /** @var int */
    protected $max_signin_age = 600;
    /** @var ?string */
    protected $actions_class;
    /** @var ?list<string> */
    protected $additional_actions;
    /** @var bool */
    protected $quiet = false;
    /** @var bool */
    protected $require_attested = false;
    /** @var ?int */
    protected $latest;

    /** @param string $caller_id */
    function __construct(Contact $user, Qrequest $qreq, $caller_id) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->qreq = $qreq;
        $this->caller_id = $caller_id;
        if ($caller_id === "manageemail") {
            // Manageemail collects multiple confirmations, which might take time
            $this->max_age = 1800;
        } else if ($caller_id === "profile_security") {
            // Profile security changes should ignore signins,
            // except for very recent ones
            $this->max_signin_age = 120;
        }
    }

    /** The oldest authentication this site will call fresh (30 days) */
    const MAX_AGE_BOUND = 2592000;

    /** Set the desired freshness window for authentication.
     * @param int $max_age
     * @return $this */
    function set_max_age($max_age) {
        $this->max_age = min($max_age, self::MAX_AGE_BOUND);
        return $this;
    }

    /** Set the desired freshness window for authentication by signin
     * (rather than reauth).
     * @param int $max_age
     * @return $this */
    function set_max_signin_age($max_age) {
        $this->max_signin_age = min($max_age, self::MAX_AGE_BOUND);
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

    /** @param bool $x
     * @return $this */
    function set_quiet($x) {
        $this->quiet = $x;
        return $this;
    }

    /** Require authentications whose time a provider attested: a provider
     * round trip that didn't say when the user authenticated won't count.
     * @param bool $x
     * @return $this */
    function set_require_attested($x) {
        $this->require_attested = $x;
        $this->latest = null;
        return $this;
    }


    /** @return string */
    function actions_class() {
        return $this->actions_class ?? "aax fullw mt-3";
    }

    /** @return list<string> */
    function additional_actions() {
        return $this->additional_actions ?? [];
    }


    /** @param bool $reverse
     * @return iterable<UserSecurityEvent> */
    final function security_events($reverse = false) {
        if (!$this->user->has_email()) {
            return [];
        }
        return UserSecurityEvent::session_list_by_email($this->qreq->qsession(), $this->user->email, $reverse);
    }

    /** @return int */
    final function latest() {
        if ($this->latest === null) {
            $this->latest = 0;
            foreach ($this->security_events(true) as $use) {
                if ($this->include_security_event($use)) {
                    $this->latest = $use->success ? $use->timestamp : 0;
                    break;
                }
            }
        }
        return $this->latest;
    }

    /** @param UserSecurityEvent $use
     * @return bool */
    function include_security_event($use) {
        if ($this->require_attested && $use->roundtrip_only) {
            return false;
        }
        // NB failed reauthentication events take precedence
        return $use->reason === UserSecurityEvent::REASON_REAUTH
            || ($use->reason === UserSecurityEvent::REASON_SIGNIN
                && $this->max_signin_age > 0
                && $use->timestamp >= Conf::$now - $this->max_signin_age);
    }

    /** Return true if this account's latest sign-in or confirmation, within
     * the last `$bound` seconds, was a provider round trip whose time the
     * provider did not attest; asking that provider again won't help.
     * @param int $bound
     * @return bool */
    final function recently_unattested($bound) {
        foreach ($this->security_events(true) as $use) {
            if ($use->reason === UserSecurityEvent::REASON_SIGNIN
                || $use->reason === UserSecurityEvent::REASON_REAUTH) {
                return $use->success
                    && $use->roundtrip_only
                    && $use->timestamp >= Conf::$now - $bound;
            }
        }
        return false;
    }

    /** Return true if this account's latest confirmation was a provider round
     * trip that failed, so a password should be asked for instead.
     * @return bool */
    private function oauth_reauth_failed() {
        foreach ($this->security_events(true) as $use) {
            if ($use->reason === UserSecurityEvent::REASON_REAUTH) {
                return !$use->success
                    && $use->type === UserSecurityEvent::TYPE_OAUTH;
            }
        }
        return false;
    }

    /** @return bool */
    function test() {
        if ($this->user->is_root_user()) {
            return true;
        }
        // NB bearer tokens have no security events, so this will correctly return false
        return ($t = $this->latest()) > 0
            && $t >= Conf::$now - $this->max_age;
    }

    protected function print_actions(...$actions) {
        echo '<div class="', $this->actions_class(), '">',
            join("", $actions), join("", $this->additional_actions()),
            '</div>';
    }

    /** How this account most recently proved who it was, which is how it will
     * be asked to prove it again.
     * @return ?UserSecurityEvent */
    protected function signin_event() {
        foreach ($this->security_events(true) as $usex) {
            if ($usex->reason === UserSecurityEvent::REASON_SIGNIN
                && ($usex->type === UserSecurityEvent::TYPE_PASSWORD
                    || $usex->type === UserSecurityEvent::TYPE_OAUTH)) {
                return $usex;
            }
        }
        if ($this->user->can_use_password()) {
            return UserSecurityEvent::make($this->user->email, UserSecurityEvent::TYPE_PASSWORD);
        }
        return null;
    }

    /** Where to send the user to confirm against `$use`'s provider, or null if
     * this site has no such provider configured.
     * @param UserSecurityEvent $use
     * @return ?string */
    private function oauth_url($use, $redirect) {
        if (!((HotCRP\OAuthProvider::list($this->conf))[$use->subtype] ?? null)) {
            return null;
        }
        $nav = $this->qreq->navigation();
        $redirect = $redirect ?? $nav->site_path . $nav->raw_page . $nav->query;
        $path = $nav->base_path;
        if (($uindex = Contact::session_index_by_email($this->qreq, $this->user->email)) >= 0) {
            $path .= "u/{$uindex}/";
        }
        return $path . $this->conf->hoturl("oauth", [
            "reauth" => 1, "authtype" => $use->subtype,
            "max_age" => $this->max_age, "redirect" => $redirect,
            "quiet" => $this->quiet ? 1 : null
        ], Conf::HOTURL_SITEREL);
    }

    /** URL that carries out this confirmation without showing the user
     * anything, or null when confirmation needs something typed here.
     * @param string $redirect
     * @return ?string */
    function authenticator_url($redirect) {
        if (($use = $this->signin_event())
            && $use->type === UserSecurityEvent::TYPE_OAUTH
            && !($this->user->can_use_password() && $this->oauth_reauth_failed())) {
            return $this->oauth_url($use, $redirect);
        }
        return null;
    }

    private function print_password_entry() {
        echo '<div class="f-i"><label for="k-reauth-password">Current password for ',
            htmlspecialchars($this->user->email), '</label>',
            Ht::entry("email", $this->user->email, ["autocomplete" => "username", "class" => "ignore-diff", "readonly" => true, "form" => "f-reauth", "hidden" => true]),
            Ht::password("password", "", ["size" => 52, "autocomplete" => "current-password", "class" => "ignore-diff", "id" => "k-reauth-password", "form" => "f-reauth", "required" => true]),
            '</div>';
    }

    function print() {
        $use = $this->signin_event();

        // password
        if ($use
            && $use->type === UserSecurityEvent::TYPE_PASSWORD) {
            $this->print_password_entry();
            $this->print_actions(Ht::submit("Confirm account", [
                "class" => "btn-success",
                "form" => "f-reauth"
            ]));
            return true;
        }

        // OAuth, with the password as an alternative (the provider might be
        // unable to confirm a recent sign-in)
        if ($use
            && $use->type === UserSecurityEvent::TYPE_OAUTH
            && ($url = $this->oauth_url($use, null))) {
            $actions = [];
            if ($this->user->can_use_password()) {
                $this->print_password_entry();
                $actions[] = Ht::submit("Confirm with password", [
                    "class" => "btn-success",
                    "form" => "f-reauth"
                ]);
            }
            $actions[] = Ht::submit("Confirm " . htmlspecialchars($this->user->email), [
                "class" => empty($actions) ? "btn-success" : "",
                "form" => "f-reauth",
                "formaction" => $url,
                "formmethod" => "post",
                "formnovalidate" => true
            ]);
            $this->print_actions(...$actions);
            return true;
        }

        echo Ht::feedback_msg(MessageItem::error("<5><strong>Account {$this->user->email} cannot be confirmed using this session.</strong> Please sign out and sign in again and retry.")),
            '<div class="', $this->actions_class(), '">',
            Ht::submit("Sign out", ["type" => "submit", "class" => "btn-danger", "form" => "f-signout"]),
            '</div>';
        Ht::stash_html($this->conf->hotform("=signout", ["cap" => null], ["id" => "f-signout"]) . "</form>", "f-signout");
        return false;
    }

    function api() {
        if (!isset($this->qreq->password)) {
            return JsonResult::make_missing_error("password");
        }
        $info = $this->user->check_password_info($this->qreq->password);
        foreach ($info["usec"] ?? [] as $use) {
            $use->set_reason(UserSecurityEvent::REASON_REAUTH)
                ->store($this->qreq);
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
