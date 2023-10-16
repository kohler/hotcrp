<?php
// pages/p_oauth.php -- HotCRP OAuth 2.0 authentication page
// Copyright (c) 2022 Eddie Kohler; see LICENSE.

class OAuthInstance {
    /** @var string */
    public $authtype;
    /** @var ?string */
    public $title;
    /** @var string */
    public $client_id;
    /** @var string */
    public $client_secret;
    /** @var string */
    public $auth_uri;
    /** @var string */
    public $redirect_uri;
    /** @var string */
    public $token_uri;
    /** @var ?string */
    public $load_function;
    public $require;

    function __construct($authtype) {
        $this->authtype = $authtype;
    }

    /** @param Conf $conf
     * @param ?string $authtype
     * @return ?OAuthInstance */
    static function find($conf, $authtype) {
        $authinfo = $conf->oauth_types();
        if (empty($authinfo)) {
            return null;
        }
        if ($authtype === null) {
            $authtype = (array_keys($authinfo))[0];
        }
        if (!($authdata = $authinfo[$authtype] ?? null)) {
            return null;
        }
        $instance = new OAuthInstance($authtype);
        $instance->client_id = $authdata->client_id ?? null;
        $instance->client_secret = $authdata->client_secret ?? null;
        $instance->auth_uri = $authdata->auth_uri ?? null;
        $instance->token_uri = $authdata->token_uri ?? null;
        $instance->redirect_uri = $authdata->redirect_uri ?? $conf->hoturl("oauth", null, Conf::HOTURL_RAW | Conf::HOTURL_ABSOLUTE);
        $instance->title = $authdata->title ?? null;
        $instance->load_function = $authdata->load_function ?? null;
        $instance->require = $authdata->require ?? null;
        foreach (["client_id", "client_secret", "auth_uri", "token_uri", "redirect_uri", "title"] as $k) {
            if (!is_string($instance->$k) && ($k !== "title" || $instance->$k !== null))
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
        if (($authi = OAuthInstance::find($this->conf, $this->qreq->authtype))) {
            $tok = new TokenInfo($this->conf, TokenInfo::OAUTHSIGNIN);
            $tok->set_contactdb(!!$this->conf->contactdb())
                ->set_expires_after(60)
                ->set_token_pattern("hcoa[20]");
            $tok->data = json_encode_db([
                "authtype" => $authi->authtype,
                "session" => $this->qreq->qsid(),
                "site_uri" => $this->conf->opt("paperSite")
            ]);
            if ($tok->create()) {
                $params = "client_id=" . urlencode($authi->client_id)
                    . "&response_type=code"
                    . "&scope=openid%20email%20profile"
                    . "&redirect_uri=" . urlencode($authi->redirect_uri)
                    . "&state=" . $tok->salt;
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
                   || !($jdata = json_decode($tok->data ?? "0"))) {
            return MessageItem::error("<0>Invalid authentication request ‘{$state}’, internal error");
        } else if (($jdata->session ?? "<NO SESSION>") !== $this->qreq->qsid()) {
            return MessageItem::error("<0>Authentication request ‘{$state}’ was for a different session");
        } else if (!isset($this->qreq->code)) {
            return MessageItem::error("<0>Authentication failed");
        } else if (($authi = OAuthInstance::find($this->conf, $jdata->authtype ?? null))) {
            return $this->instance_response($authi, $tok, $jdata);
        } else {
            $this->conf->error_msg("<0>OAuth authentication internal error");
        }
    }

    /** @param OAuthInstance $authi
     * @param TokenInfo $tok
     * @param object $jdata */
    private function instance_response($authi, $tok, $jdata) {
        // make authentication request
        $authtitle = $authi->title ?? $authi->authtype;
        $tok->delete();
        $curlh = curl_init();
        $nonce = base48_encode(random_bytes(10));
        curl_setopt($curlh, CURLOPT_URL, $authi->token_uri);
        curl_setopt($curlh, CURLOPT_POST, true);
        curl_setopt($curlh, CURLOPT_HTTPHEADER, ["Content-Type: application/x-www-form-urlencoded"]);
        curl_setopt($curlh, CURLOPT_POSTFIELDS, http_build_query([
            "code" => $this->qreq->code,
            "client_id" => $authi->client_id,
            "client_secret" => $authi->client_secret,
            "redirect_uri" => $authi->redirect_uri,
            "grant_type" => "authorization_code",
            "nonce" => $nonce
        ], "", "&"));
        curl_setopt($curlh, CURLOPT_RETURNTRANSFER, true);
        $txt = curl_exec($curlh);
        curl_close($curlh);

        // check response
        if (!$txt) {
            return MessageItem::error("<0>{$authtitle} authentication request failed");
        } else if (!($response = json_decode($txt))
                   || !is_object($response)) {
            return MessageItem::error("<0>{$authtitle} authentication response was incorrectly formatted");
        } else if (!isset($response->id_token)
                   || !is_string($response->id_token)) {
            return MessageItem::error("<0>{$authtitle} authentication response doesn’t confirm your identity");
        }

        // parse returned JSON web token
        $jwt = new JWTParser;
        if (!($jid = $jwt->validate($response->id_token))) {
            return MessageItem::error("<0>The identity portion of the {$authtitle} authentication response doesn’t validate");
        }

        if (isset($authi->load_function)
            && Conf::xt_resolve_require($authi)
            && ($m = call_user_func($authi->load_function, $this, $authi, $response, $jid))) {
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
            throw new Redirection($jdata->site_url);
        } else {
            $user = $info["user"];
            $this->conf->feedback_msg(new MessageItem(null, "<0>Signed in", MessageSet::SUCCESS));
            LoginHelper::change_session_users($this->qreq, [$user->email => 1]);
            throw new Redirection(hoturl_add_raw($jdata->site_uri, "i=" . urlencode($user->email)));
        }
    }

    static function go(Contact $user, Qrequest $qreq) {
        $oap = new OAuth_Page($user, $qreq);
        if (isset($qreq->state)) {
            $mi = $oap->response();
            if ($mi) {
                $user->conf->feedback_msg($mi);
                throw new Redirection($user->conf->hoturl("signin"));
            }
        } else {
            $oap->start();
        }
        $qreq->print_header("Authentication", "oauth", ["action_bar" => ""]);
        $qreq->print_footer();
    }
}
