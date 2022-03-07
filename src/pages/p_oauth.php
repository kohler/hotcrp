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

    /** @param Conf $conf
     * @param ?string $authtype
     * @return ?OAuthInstance */
    static function find($conf, $authtype) {
        $instance = new OAuthInstance;
        $instance->authtype = $authtype ?? $conf->opt("defaultOauthType") ?? "";
        $authinfo = $conf->opt("oAuthTypes") ?? [];
        $authdata = $authinfo[$instance->authtype] ?? [];
        foreach (["client_id", "client_secret", "auth_uri", "redirect_uri", "token_uri"] as $k) {
            if (isset($authdata[$k]) && is_string($authdata[$k])) {
                $instance->$k = $authdata[$k];
            } else {
                return null;
            }
        }
        if (isset($authdata["title"]) && is_string($authdata["title"])) {
            $instance->title = $authdata["title"];
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
        ensure_session();
        if (($authi = OAuthInstance::find($this->conf, $this->qreq->authtype))) {
            $tok = new TokenInfo($this->conf, TokenInfo::OAUTHSIGNIN);
            $tok->set_contactdb(!!$this->conf->contactdb())
                ->set_expires_after(60)
                ->set_token_pattern("hcoa[20]");
            $tok->data = json_encode_db([
                "authtype" => $authi->authtype,
                "session" => session_id(),
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
            $this->conf->error_msg("<0>‘{$this->qreq->authtype}’ OAuth authentication is not supported on this site");
        } else {
            $this->conf->error_msg("<0>OAuth authentication is not supported on this site");
        }
    }

    function response() {
        $state = $this->qreq->state;
        if (!isset($state) || !isset($this->qreq->code)) {
            return MessageItem::error("<0>OAuth authentication response parameters required");
        } else if (!($tok = TokenInfo::find($state, $this->conf, !!$this->conf->contactdb()))) {
            return MessageItem::error("<0>OAuth authentication request ‘{$state}’ not found or expired");
        } else if (!$tok->is_active()) {
            return MessageItem::error("<0>OAuth authentication request ‘{$state}’ expired");
        } else if ($tok->timeUsed) {
            return MessageItem::error("<0>OAuth authentication request ‘{$state}’ reused");
        } else if ($tok->capabilityType !== TokenInfo::OAUTHSIGNIN
                   || !($jdata = json_decode($tok->data ?? "0"))) {
            return MessageItem::error("<0>Invalid OAuth authentication request ‘{$state}’, internal error");
        } else if (($jdata->session ?? "<NO SESSION>") !== session_id()) {
            return MessageItem::error("<0>OAuth authentication request ‘{$state}’ was for a different session");
        } else if (($authi = OAuthInstance::find($this->conf, $jdata->authtype ?? null))) {
            $authtitle = $authi->title ?? $authi->authtype;
            $tok->delete();
            $curlh = curl_init();
            $nonce = base48_encode(random_bytes(10));
            curl_setopt($curlh, CURLOPT_URL, $authi->token_uri);
            curl_setopt($curlh, CURLOPT_POST, true);
            curl_setopt($curlh, CURLOPT_POSTFIELDS, [
                "code" => $this->qreq->code,
                "client_id" => $authi->client_id,
                "client_secret" => $authi->client_secret,
                "redirect_uri" => $authi->redirect_uri,
                "grant_type" => "authorization_code",
                "nonce" => $nonce
            ]);
            curl_setopt($curlh, CURLOPT_RETURNTRANSFER, true);
            $txt = curl_exec($curlh);
            curl_close($curlh);

            if (!$txt) {
                return MessageItem::error("<0>{$authtitle} authentication request failed");
            } else if (!($response = json_decode($txt))
                       || !is_object($response)) {
                return MessageItem::error("<0>{$authtitle} authentication response was incorrectly formatted");
            } else if (!isset($response->id_token)
                       || !is_string($response->id_token)) {
                return MessageItem::error("<0>{$authtitle} authentication response doesn’t confirm your identity");
            }

            $jwt = new JWTParser;
            if (!($jid = $jwt->validate($response->id_token))) {
                return MessageItem::error("<0>The identity portion of the {$authtitle} authentication response doesn’t validate");
            } else if (!isset($jid->email)
                       || !is_string($jid->email)) {
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
            $user = Contact::make_keyed($this->conf, $reg)->store(0, $this->viewer);
            if (!$user) {
                return MessageItem::error("<0>Error creating your account");
            }

            $user->conf->feedback_msg(new MessageItem(null, "<0>Login successful", MessageSet::SUCCESS));
            LoginHelper::change_session_users([$user->email => 1]);
            throw new Redirection(hoturl_add_raw($jdata->site_uri, "i=" . urlencode($user->email)));
        } else {
            $this->conf->error_msg("<0>OAuth authentication internal error");
        }
    }

    static function go(Contact $user, Qrequest $qreq) {
        $oap = new OAuth_Page($user, $qreq);
        if (isset($qreq->state)) {
            $mi = $oap->response();
            if ($mi) {
                $user->conf->feedback_msg($mi);
            }
        } else {
            $oap->start();
        }
        $user->conf->header("Authentication", "oauth", ["action_bar" => false]);
        $user->conf->footer();
    }
}
