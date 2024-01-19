<?php
// pages/p_oauth.php -- HotCRP OAuth 2.0 authentication page
// Copyright (c) 2022-2023 Eddie Kohler; see LICENSE.

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

    /** @var ?string */
    public $nonce;

    public $require;

    function __construct($name) {
        $this->name = $name;
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
        $instance->redirect_uri = $authdata->redirect_uri ?? $conf->hoturl("oauth", null, Conf::HOTURL_RAW | Conf::HOTURL_ABSOLUTE);
        $instance->token_function = $authdata->token_function ?? null;
        $instance->require = $authdata->require ?? null;
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

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
    }

    function start() {
        $this->qreq->open_session();
        if (($authi = OAuthProvider::find($this->conf, $this->qreq->authtype))) {
            $tok = new TokenInfo($this->conf, TokenInfo::OAUTHSIGNIN);
            $tok->set_contactdb(!!$this->conf->contactdb())
                ->set_expires_after(60)
                ->set_token_pattern("hcoa[20]");
            $nonce = base48_encode(random_bytes(8));
            $tok->assign_data([
                "authtype" => $authi->name,
                "session" => $this->qreq->qsid(),
                "redirect" => $this->qreq->redirect,
                "site_uri" => $this->conf->opt("paperSite"),
                "nonce" => $nonce
            ]);
            if ($tok->create()) {
                $params = "client_id=" . urlencode($authi->client_id)
                    . "&response_type=code"
                    . "&scope=" . rawurlencode($authi->scope ?? "openid email profile")
                    . "&redirect_uri=" . rawurlencode($authi->redirect_uri)
                    . "&state=" . $tok->salt
                    . "&nonce=" . $nonce;
                $this->qreq->set_httponly_cookie("oauth-{$nonce}", "1", Conf::$now + 600);
                throw new Redirection(hoturl_add_raw($authi->auth_uri, $params));
            } else {
                $this->conf->error_msg("<0>Authentication attempt failed");
            }
        } else if ($this->qreq->authtype) {
            $this->conf->error_msg("<0>{$this->qreq->authtype} authentication is not supported on this site");
        } else {
            $this->conf->error_msg("<0>OAuth authentication is not supported on this site");
        }
    }

    /** @return MessageItem|list<MessageItem> */
    function response() {
        $state = $this->qreq->state;
        if (!isset($state)) {
            return MessageItem::error("<0>OAuth authentication response parameters required");
        } else if (!($tok = TokenInfo::find($state, $this->conf, !!$this->conf->contactdb()))) {
            return MessageItem::error("<0>Authentication request not found or expired");
        } else if (!$tok->is_active()) {
            return MessageItem::error("<0>Authentication request expired");
        } else if ($tok->timeUsed) {
            return MessageItem::error("<0>Authentication request reused");
        } else if ($tok->capabilityType !== TokenInfo::OAUTHSIGNIN
                   || !($tokdata = json_decode($tok->data ?? "0"))) {
            return MessageItem::error("<0>Invalid authentication request ‘{$state}’, internal error");
        }

        if (isset($tokdata->nonce)) {
            $noncematch = isset($_COOKIE["oauth-{$tokdata->nonce}"]);
            $this->qreq->set_httponly_cookie("oauth-{$tokdata->nonce}", "", Conf::$now - 86400);
        } else {
            $noncematch = true;
        }

        if (($tokdata->session ?? "<NO SESSION>") !== $this->qreq->qsid()
            || !$noncematch) {
            return MessageItem::error("<0>Authentication request ‘{$state}’ was for a different session");
        } else if (!isset($this->qreq->code)) {
            return MessageItem::error("<0>Authentication failed");
        } else if (($authi = OAuthProvider::find($this->conf, $tokdata->authtype ?? null))) {
            return $this->instance_response($authi, $tok, $tokdata);
        } else {
            return MessageItem::error("<0>OAuth authentication internal error");
        }
    }

    /** @param OAuthProvider $authi
     * @param TokenInfo $tok
     * @param object $tokdata
     * @return MessageItem|list<MessageItem> */
    private function instance_response($authi, $tok, $tokdata) {
        // make authentication request
        $authtitle = $authi->title ?? $authi->name;
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
        curl_close($curlh);

        // check response
        $response = $txt ? json_decode($txt) : null;
        if (!$response
            || !is_object($response)
            || !is_string($response->id_token ?? null)) {
            if ($errno !== 0 || $status < 200 || $status > 299) {
                return MessageItem::error("<0>{$authtitle} authentication returned an error");
            } else {
                return MessageItem::error("<0>{$authtitle} authentication returned an incorrectly formatted response");
            }
        }

        // parse returned JSON web token
        $jwt = new JWTParser;
        $authi->nonce = $tokdata->nonce ?? null;
        if (!($jid = $jwt->validate($response->id_token))
            || !$jwt->validate_id_token($jid, $authi)) {
            return MessageItem::error("<0>Invalid {$authtitle} authentication response (code {$jwt->errcode})");
        }

        if (isset($authi->token_function)
            && Conf::xt_resolve_require($authi)
            && ($m = call_user_func($authi->token_function, $this, $authi, $response, $jid))) {
            return $m;
        }

        if (!isset($jid->email) || !is_string($jid->email)) {
            return [
                MessageItem::error("<0>The {$authtitle} authenticator didn’t provide your email"),
                new MessageItem(null, "<0>HotCRP requires your email to sign you in.", MessageSet::INFORM)
            ];
        } else if (isset($jid->email_verified)
                   && $jid->email_verified === false) {
            return [
                MessageItem::error("<0>The {$authtitle} authenticator hasn’t verified your email"),
                new MessageItem(null, "<0>HotCRP requires a verified email to sign you in.", MessageSet::INFORM)
            ];
        }

        // log user in
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
        $info = LoginHelper::check_external_login(Contact::make_keyed($this->conf, $reg));
        if (!$info["ok"]) {
            LoginHelper::login_error($this->conf, $jid->email, $info, null);
            throw new Redirection($tokdata->site_uri);
        }

        $user = $info["user"];
        $this->conf->feedback_msg(new MessageItem(null, "<0>Signed in", MessageSet::SUCCESS));
        $uindex = UpdateSession::user_change($this->qreq, $user->email, true);
        UpdateSession::usec_add($this->qreq, $user->email, 1, 0, true);

        $uri = $tokdata->site_uri;
        if (!str_ends_with($uri, "/")) {
            $uri .= "/";
        }
        if (count(Contact::session_users($this->qreq)) > 1) {
            $uri .= "u/{$uindex}/";
        }
        if ($tokdata->redirect) {
            $rnav = NavigationState::make_base($uri);
            $uri = $rnav->resolve_within($tokdata->redirect) ?? $uri;
        }
        throw new Redirection($uri);
    }

    static function go(Contact $user, Qrequest $qreq) {
        $oap = new OAuth_Page($user, $qreq);
        if (isset($qreq->state)) {
            $ml = $oap->response();
            if ($ml) {
                $user->conf->feedback_msg($ml);
                throw new Redirection($user->conf->hoturl("signin"));
            }
        } else if ($qreq->valid_post()) {
            $oap->start();
        } else {
            $user->conf->error_msg("<0>Missing CSRF token");
        }
        if (http_response_code() === 200) {
            http_response_code(400);
        }
        $qreq->print_header("Authentication", "oauth", ["action_bar" => "", "body_class" => "body-error"]);
        $qreq->print_footer();
    }
}
