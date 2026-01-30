<?php
// pages/p_oauth.php -- HotCRP OAuth 2 authentication page
// Copyright (c) 2022-2026 Eddie Kohler; see LICENSE.

namespace HotCRP;
use Conf, Contact, MessageItem, NavigationState, Qrequest, Redirection;
use LoginHelper, TokenInfo, UserSecurityEvent, UserStatus;

class OAuthProvider {
    /** @var string */
    public $name;
    /** @var ?string */
    public $title;
    /** @var ?string */
    public $scope;
    /** @var string */
    public $client_id;
    /** @var string */
    public $client_secret;
    /** @var ?string */
    public $issuer;
    /** @var string */
    public $auth_uri;
    /** @var string */
    public $redirect_uri;
    /** @var string */
    public $token_uri;
    /** @var ?string */
    public $token_function;
    /** @var ?bool */
    public $roles;
    /** @var ?object */
    public $group_roles;
    /** @var ?bool */
    public $reset_roles;

    /** @var ?string */
    public $nonce;
    public $require;

    function __construct($name) {
        $this->name = $name;
    }

    /** @return string */
    function title() {
        return $this->title ?? $this->name;
    }

    /** @param Conf $conf
     * @param ?string $name
     * @return ?OAuthProvider */
    static function find($conf, $name) {
        $authinfo = $conf->oauth_providers();
        if (empty($authinfo)) {
            return null;
        }
        if ($name === null) {
            $name = (array_keys($authinfo))[0];
        }
        if (!($authdata = $authinfo[$name] ?? null)) {
            return null;
        }
        $instance = new OAuthProvider($name);
        $instance->title = $authdata->title ?? null;
        $instance->issuer = $authdata->issuer ?? null;
        $instance->scope = $authdata->scope ?? null;
        $instance->client_id = $authdata->client_id ?? null;
        $instance->client_secret = $authdata->client_secret ?? null;
        $instance->auth_uri = $authdata->auth_uri ?? null;
        $instance->token_uri = $authdata->token_uri ?? null;
        $instance->redirect_uri = $authdata->redirect_uri
            ?? $conf->hoturl("oauth", null, Conf::HOTURL_RAW | Conf::HOTURL_ABSOLUTE);
        $instance->token_function = $authdata->token_function ?? null;
        $instance->require = $authdata->require ?? null;
        $instance->roles = $authdata->roles ?? false;
        $instance->group_roles = $authdata->group_roles ?? $authdata->group_mappings /* XXX */ ?? null;
        $instance->reset_roles = $authdata->reset_roles ?? $authdata->remove_groups /* XXX */ ?? false;
        foreach (["title", "issuer", "scope"] as $k) {
            if ($instance->$k !== null && !is_string($instance->$k))
                return null;
        }
        foreach (["client_id", "client_secret", "auth_uri", "token_uri", "redirect_uri"] as $k) {
            if (!is_string($instance->$k))
                return null;
        }
        return $instance;
    }
}

class OAuth_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var ?string */
    public $site_uri;
    /** @var ?string */
    public $email;
    /** @var ?string */
    public $success_redirect;
    /** @var ?string */
    public $failure_redirect;
    /** @var bool */
    public $success = false;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
        $this->site_uri = $qreq->navigation()->base_absolute();
    }

    /** @return MessageItem|list<MessageItem> */
    function start() {
        $this->qreq->open_session();
        $authi = OAuthProvider::find($this->conf, $this->qreq->authtype);
        if (!$authi) {
            if ($this->qreq->authtype) {
                return MessageItem::error("<0>{$this->qreq->authtype} authentication is not supported on this site");
            } else {
                return MessageItem::error("<0>OAuth authentication is not supported on this site");
            }
        }
        $reauth = friendly_boolean($this->qreq->reauth)
            && $this->viewer->has_email();

        $tokdata = [
            "authtype" => $authi->name,
            "session" => $this->qreq->qsid(),
            "site_uri" => $this->conf->opt("paperSite"),
            "nonce" => base48_encode(random_bytes(12))
        ];
        if ($reauth) {
            $tokdata["reauth"] = $this->viewer->email;
        }
        if (friendly_boolean($this->qreq->quiet)) {
            $tokdata["quiet"] = true;
        }
        if (($r = $this->qreq->sucess_redirect ?? $this->qreq->redirect) !== null) {
            $tokdata["success_redirect"] = $r;
        }
        if (($r = $this->qreq->failure_redirect ?? $this->qreq->redirect) !== null) {
            $tokdata["failure_redirect"] = $r;
        }

        $tok = new TokenInfo($this->conf, TokenInfo::OAUTHSIGNIN);
        $tok->set_contactdb(!!$this->conf->contactdb())
            ->set_expires_in(600)
            ->set_token_pattern("hcoa[20]")
            ->assign_data($tokdata)
            ->insert();
        if (!$tok->stored()) {
            return MessageItem::error("<0>Authentication attempt failed");
        }
        $this->qreq->set_cookie_opt("hotcrp-oauth-nonce-" . $tokdata["nonce"], "1", [
            "expires" => Conf::$now + 600, "path" => "/", "httponly" => true
        ]);
        $params = "client_id=" . urlencode($authi->client_id)
            . "&response_type=code"
            . "&scope=" . rawurlencode($authi->scope ?? "openid email profile")
            . "&redirect_uri=" . rawurlencode($authi->redirect_uri)
            . "&state=" . $tok->salt
            . "&nonce=" . $tokdata["nonce"];
        if ($reauth) {
            $params .= "&login_hint=" . rawurlencode($this->viewer->email);
        } else if (isset($this->qreq->email) && validate_email($this->qreq->email)) {
            $params .= "&login_hint=" . rawurlencode($this->qreq->email);
        }
        if (ctype_digit($this->qreq->max_age ?? "")) {
            $params .= "&max_age=" . $this->qreq->max_age;
        }
        throw new Redirection(hoturl_add_raw($authi->auth_uri, $params));
    }

    /** @return MessageItem|list<MessageItem> */
    function response() {
        $state = $this->qreq->state;
        if (!isset($state)) {
            return MessageItem::error("<0>OAuth authentication response parameters required");
        }
        $tok = TokenInfo::find_from($state, $this->conf, !!$this->conf->contactdb());
        if (!$tok) {
            return MessageItem::error("<0>Authentication request not found or expired");
        } else if (!$tok->is_active()) {
            return MessageItem::error("<0>Authentication request expired");
        } else if ($tok->timeUsed) {
            return MessageItem::error("<0>Authentication request reused");
        } else if ($tok->capabilityType !== TokenInfo::OAUTHSIGNIN
                   || !$tok->data()) {
            return MessageItem::error("<0>Invalid authentication request ‘{$state}’, internal error");
        }

        // set redirection information from token
        $this->site_uri = $tok->data("site_uri") ?? $this->site_uri;
        if (!str_ends_with($this->site_uri, "/")) {
            $this->site_uri .= "/";
        }
        $redirect = $tok->data("redirect") /* XXX obsolete */;
        $this->success_redirect = $tok->data("success_redirect") ?? $redirect;
        $this->failure_redirect = $tok->data("failure_redirect") ?? $redirect;

        if (($nonce = $tok->data("nonce")) !== null) {
            $noncematch = isset($_COOKIE["hotcrp-oauth-nonce-{$nonce}"]);
            $this->qreq->set_httponly_cookie("hotcrp-oauth-nonce-{$nonce}", "", Conf::$now - 86400);
        } else {
            $noncematch = true;
        }

        if (($tok->data("session") ?? "<NO SESSION>") !== $this->qreq->qsid()) {
            return MessageItem::error("<0>Authentication request ‘{$state}’ was for a different session");
        } else if (!$noncematch) {
            return MessageItem::error("<0>Authentication request ‘{$state}’ may have been replayed");
        } else if (!isset($this->qreq->code)) {
            return MessageItem::error("<0>Authentication failed");
        } else if (($authi = OAuthProvider::find($this->conf, $tok->data("authtype")))) {
            return $this->instance_response($authi, $tok);
        } else {
            return MessageItem::error("<0>OAuth authentication internal error");
        }
    }

    /** @param OAuthProvider $authi
     * @param TokenInfo $tok
     * @return MessageItem|list<MessageItem> */
    private function instance_response($authi, $tok) {
        // make authentication request
        $tok->delete();
        $curlh = curl_init();
        curl_setopt($curlh, CURLOPT_URL, $authi->token_uri);
        curl_setopt($curlh, CURLOPT_POST, true);
        curl_setopt($curlh, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);
        curl_setopt($curlh, CURLOPT_TIMEOUT, 15);
        curl_setopt($curlh, CURLOPT_POSTFIELDS, http_build_query([
            "code" => $this->qreq->code,
            "client_id" => $authi->client_id,
            "client_secret" => $authi->client_secret,
            "redirect_uri" => $authi->redirect_uri,
            "grant_type" => "authorization_code"
        ], "", "&"));
        curl_setopt($curlh, CURLOPT_RETURNTRANSFER, true);
        $txt = curl_exec($curlh);
        $errno = curl_errno($curlh);
        $status = curl_getinfo($curlh, CURLINFO_RESPONSE_CODE);
        $curlh = null;

        // check response
        $response = $txt ? json_decode($txt) : null;
        if (!$response
            || !is_object($response)
            || !is_string($response->id_token ?? null)) {
            if ($errno !== 0 || $status < 200 || $status > 299) {
                return MessageItem::error("<0>{$authi->title()} authentication returned an error");
            } else {
                return MessageItem::error("<0>{$authi->title()} authentication returned an incorrectly formatted response");
            }
        }

        // parse returned JSON web token
        $jwt = new JWTParser;
        $authi->nonce = $tok->data("nonce");
        if (!($jid = $jwt->validate($response->id_token))
            || !$jwt->validate_id_token($jid, $authi)) {
            return MessageItem::error("<0>Invalid {$authi->title()} authentication response (code {$jwt->errcode})");
        }

        if (isset($authi->token_function)
            && Conf::xt_resolve_require($authi)
            && ($ml = call_user_func($authi->token_function, $this, $authi, $response, $jid))) {
            return $ml;
        }

        // check returned email
        if (!isset($jid->email) || !is_string($jid->email)) {
            return [
                MessageItem::error("<0>The {$authi->title()} authenticator didn’t provide your email"),
                MessageItem::inform("<0>HotCRP requires your email to sign you in.")
            ];
        }
        if (($jid->email_verified ?? null) === false) {
            return [
                MessageItem::error("<0>The {$authi->title()} authenticator hasn’t verified your email"),
                MessageItem::inform("<0>HotCRP requires a verified email to sign you in.")
            ];
        }
        $this->email = $jid->email;

        // handle reauthentication requests
        if ($tok->data("reauth")) {
            return $this->instance_reauth($authi, $tok, $jid);
        } else {
            return $this->instance_signin($authi, $tok, $jid);
        }
    }

    /** @param OAuthProvider $authi
     * @param TokenInfo $tok
     * @param object $jid
     * @return null|MessageItem|list<MessageItem> */
    private function instance_reauth($authi, $tok, $jid) {
        $reauth = $tok->data("reauth");
        $use = UserSecurityEvent::make($reauth, UserSecurityEvent::TYPE_OAUTH)
            ->set_subtype($authi->name)
            ->set_reason(UserSecurityEvent::REASON_REAUTH);
        if (strcasecmp($this->email, $reauth) !== 0) {
            $use->set_success(false)->store($this->qreq->qsession());
            return [
                MessageItem::error("<0>The {$authi->title()} authenticator verified the wrong email"),
                MessageItem::inform("<0>You must provide reauthentication for {$reauth}.")
            ];
        }
        $use->store($this->qreq->qsession());
        $this->success = true;
        return $tok->data("quiet") ? [] : [MessageItem::success("<0>Authentication confirmed")];
    }

    /** @param list<int|array{string,float|false}> &$roles
     * @param string|list<string> $rc
     * @param bool $remove */
    private function parse_role_changes(&$roles, $rc, $remove) {
        $rcx = is_string($rc) ? preg_split('/[\s,;]+/', $rc) : $rc;
        foreach ($rcx as $rcn) {
            if ($rcn === "") {
                continue;
            }
            if (preg_match('/\A(?:\+|-|–|—|−)/s', $rcn, $m)) {
                if ($remove) {
                    continue;
                }
                $action = $m[0] === "+";
                $rcn = substr($rcn, strlen($m[0]));
            } else {
                $action = !$remove;
            }
            if (!preg_match('/\A((?:|~|~~)' . TAG_REGEX_NOTWIDDLE . ')(|[#](?:-?\d+(?:\.\d*)?|-?\.\d+|clear|))\z/', $rcn, $m)) {
                continue;
            }
            if (strcasecmp($m[1], "pc") === 0) {
                $change = Contact::ROLE_PC;
            } else if (strcasecmp($m[1], "chair") === 0) {
                $change = Contact::ROLE_CHAIR;
            } else if (strcasecmp($m[1], "sysadmin") === 0
                       || strcasecmp($m[1], "admin") === 0) {
                $change = Contact::ROLE_ADMIN;
            } else if (UserStatus::check_pc_tag($m[1])) {
                $change = $m[1];
            } else {
                continue;
            }
            if (is_int($change)) {
                if ($m[2] !== "") {
                    continue;
                }
                $roles[] = $action ? $change : -$change;
            } else if (!$action || strcasecmp($m[2], "#clear") === 0) {
                $roles[] = [$m[1], false];
            } else {
                $roles[] = [$m[1], $m[2] === "" ? 0 : (float) substr($m[2], 1)];
            }
        }
    }

    /** @param Contact $u
     * @param list<int|array{string,float|false}> $roles */
    private function apply_role_changes(Contact $u, $roles) {
        foreach ($roles as $r) {
            if (is_int($r)) {
                $u->set_prop("roles", $r < 0 ? $u->roles & ~(-$r) : $u->roles | $r);
            } else {
                $u->change_tag_prop($r[0], $r[1]);
            }
        }
        if (($u->roles & Contact::ROLE_CHAIR) !== 0) {
            $u->set_prop("roles", $u->roles | Contact::ROLE_PC);
        }
    }

    /** @param OAuthProvider $authi
     * @param TokenInfo $tok
     * @param object $jid
     * @return null|MessageItem|list<MessageItem> */
    private function instance_signin($authi, $tok, $jid) {
        $reg = ["email" => $jid->email];
        if (isset($jid->given_name) && is_string($jid->given_name)) {
            $reg["firstName"] = $jid->given_name;
        }
        if (isset($jid->family_name) && is_string($jid->family_name)) {
            $reg["lastName"] = $jid->family_name;
        }
        if (isset($jid->name) && is_string($jid->name)) {
            $reg["name"] = $jid->name;
        }
        if (isset($jid->orcid) && is_string($jid->orcid)) {
            $reg["orcid"] = $jid->orcid;
        }
        if (isset($jid->affiliation) && is_string($jid->affiliation)) {
            $reg["affiliation"] = $jid->affiliation;
        }

        $role_changes = [];
        if ($authi->roles || $authi->group_roles) {
            if ($authi->reset_roles) {
                $rc = $authi->reset_roles === true ? "pc admin chair" : $authi->reset_roles;
                $this->parse_role_changes($role_changes, $rc, true);
            }
            if ($authi->roles && isset($jid->roles) && is_array($jid->roles)) {
                $this->parse_role_changes($role_changes, $jid->roles, false);
            }
            if ($authi->group_roles && isset($jid->groups) && is_array($jid->groups)) {
                foreach ($authi->group_roles as $group => $rc) {
                    if (in_array($group, $jid->groups, true))
                        $this->parse_role_changes($role_changes, $rc, false);
                }
            }
        }

        $regu = Contact::make_keyed($this->conf, $reg);
        $this->apply_role_changes($regu, $role_changes);

        $info = LoginHelper::check_external_login($regu);
        if (!$info["ok"]) {
            LoginHelper::login_error($this->conf, $jid->email, $info, null);
            UserSecurityEvent::make($jid->email, UserSecurityEvent::TYPE_OAUTH)
                ->set_subtype($authi->name)
                ->set_success(false)
                ->store($this->qreq->qsession());
            return null;
        }

        $user = $info["user"];
        if (!empty($role_changes)) {
            $this->apply_role_changes($user, $role_changes);
            $user->save_roles($user->roles, $this->conf->root_user());
            $user->save_prop();
        }

        $qs = $this->qreq->qsession();
        UserSecurityEvent::session_user_add($qs, $user->email);
        UserSecurityEvent::make($user->email, UserSecurityEvent::TYPE_OAUTH)
            ->set_subtype($authi->name)
            ->store($qs);
        $this->success = true;
        return $tok->data("quiet") ? [] : [MessageItem::success("<0>Signed in")];
    }

    private function resolve($ml) {
        if ($ml) {
            $this->conf->feedback_msg($ml);
        }
        $uri = $this->site_uri;
        $qs = $this->qreq->qsession();
        if ($this->success
            && count(Contact::session_emails($qs)) > 1
            && ($uindex = Contact::session_index_by_email($qs, $this->email)) >= 0) {
            $uri .= "u/{$uindex}/";
        }
        $r = $this->success ? $this->success_redirect : $this->failure_redirect;
        if ($r) {
            $rnav = NavigationState::make_base($uri);
            $uri = $rnav->resolve_within($r, $this->site_uri) ?? $uri;
        }
        throw new Redirection($uri);
    }

    static function go(Contact $user, Qrequest $qreq) {
        $oap = new OAuth_Page($user, $qreq);
        $redirect = false;
        if (isset($qreq->state)) {
            $oap->resolve($oap->response());
        } else if ($qreq->qsid()) {
            $oap->resolve($oap->start());
        } else if ($qreq->setcookie) {
            http_response_code(400);
            $user->conf->feedback_msg(MessageItem::error($user->conf->_i("session_failed_error")));
            $qreq->print_header("Authentication", "oauth", ["action_bar" => "", "body_class" => "body-error"]);
            $qreq->print_footer();
        } else {
            $qreq->open_session();
            $user->conf->redirect_self($qreq, ["setcookie" => 1]);
        }
    }
}
