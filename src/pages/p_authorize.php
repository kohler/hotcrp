<?php
// pages/p_authorize.php -- HotCRP OAuth 2.0 authorization provider page
// Copyright (c) 2022-2023 Eddie Kohler; see LICENSE.

class OAuthClient {
    /** @var string */
    public $name;
    /** @var ?string */
    public $title;
    /** @var string */
    public $client_id;
    /** @var string */
    public $client_secret;
    /** @var list<string> */
    public $redirect_uri = [];

    /** @var ?string */
    public $nonce;

    public $require;

    /** @param object $x
     * @return ?OAuthClient */
    static function make($x) {
        $oac = new OAuthClient;
        $oac->name = $x->name ?? null;
        $oac->title = $x->title ?? null;
        $oac->client_id = $x->client_id ?? null;
        $oac->client_secret = $x->client_secret ?? null;
        if (isset($x->redirect_uri)) {
            if (is_string($x->redirect_uri)) {
                $oac->redirect_uri[] = $x->redirect_uri;
            } else if (is_list($x->redirect_uri)) {
                $oac->redirect_uri = $x->redirect_uri;
            }
            $n = count($oac->redirect_uri);
            for ($i = 0; $i !== $n; ) {
                $s = $oac->redirect_uri[$i];
                if (is_string($s)
                    && str_starts_with($s, "https://")
                    && strpos($s, "#") === false) {
                    ++$i;
                } else {
                    array_splice($oac->redirect_uri, $i, 1);
                    --$n;
                }
            }
        }
        if (!is_string($oac->client_id)
            || empty($oac->redirect_uri)) {
            return null;
        }
        return $oac;
    }
}

class Authorize_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var ComponentSet */
    public $cs;
    /** @var array<string,object> */
    private $clients = [];
    /** @var TokenInfo */
    private $token;

    function __construct(Contact $viewer, Qrequest $qreq, ComponentSet $cs = null) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
        $this->cs = $cs;
        $this->clients = $this->conf->_xtbuild_resolve([], "oAuthClients");
    }

    /** @return ?OAuthClient */
    private function find_client($client_id) {
        foreach ($this->clients as $x) {
            if (($x->client_id ?? null) === $client_id)
                return OAuthClient::make($x);
        }
        return null;
    }

    /** @param array<string,mixed> $param
     * @return string */
    private function extend_redirect_uri($param) {
        $uri = $this->qreq->redirect_uri;
        if (($hash = strpos($uri, "#")) !== false) {
            $uri = substr($uri, 0, $hash);
        }
        if (strpos($uri, "?") === false) {
            $uri .= "?";
        } else if (!str_ends_with($uri, "&") && !str_ends_with($uri, "?")) {
            $uri .= "&";
        }
        return $uri . http_build_query($param);
    }

    private function handle_request(OAuthClient $client) {
        $scope = trim($this->qreq->scope ?? "");
        if (!preg_match('/\A[ !#-\x5b\x5d-\x7e]+\z/', $scope)) {
            $this->redirect_error("invalid_scope");
        }
        $scope_list = explode(" ", $scope);
        if (!in_array("openid", $scope_list)) {
            $this->redirect_error("invalid_scope", "Scope `openid` required");
        }

        if ($this->qreq->response_type !== "code") {
            $this->redirect_error("unsupported_response_type", "Response type `code` required");
        }

        if ($this->qreq->request !== null) {
            $this->redirect_error("request_not_supported");
        } else if ($this->qreq->request_uri !== null) {
            $this->redirect_error("request_uri_not_supported");
        } else if ($this->qreq->registration !== null) {
            $this->redirect_error("registration_not_supported");
        }

        if (isset($this->qreq->prompt)) {
            $prompts = [];
            foreach (explode(" ", trim($this->qreq->prompt)) as $p) {
                if ($p !== "")
                    $prompts[] = $p;
            }
            if (in_array("none", $prompts)) {
                $this->redirect_error("interaction_required");
            }
        }

        // XXX max_age
        // XXX prompt login vs. select_account vs. consent
        // XXX record consent for future use?

        $this->token = (new TokenInfo($this->conf, TokenInfo::OAUTHCODE))
            ->set_token_pattern("hcop[36]")
            ->set_invalid_after(3600)
            ->set_expires_after(86400)
            ->change_data("state", $this->qreq->state)
            ->change_data("nonce", $this->qreq->nonce)
            ->change_data("client_id", $client->client_id)
            ->change_data("redirect_uri", $this->qreq->redirect_uri);
        $this->token->create();

        $this->qreq->print_header("Sign in", "authorize", ["action_bar" => "", "hide_title" => true, "body_class" => "body-signin"]);
        Signin_Page::print_form_start_for($this->qreq, "=signin");
        $nav = $this->qreq->navigation();
        echo Ht::hidden("redirect", "authorize{$nav->php_suffix}?code=" . urlencode($this->token->salt) . "&authconfirm=1");
        $this->cs->print_members("authorize/form");
        echo '</div>';
        $this->qreq->print_footer();
    }

    function print_form_title() {
        echo '<h1>Sign in</h1>';
    }

    function print_form_description() {

    }

    function print_form_active() {
        $buttons = [];
        $nav = $this->qreq->navigation();
        foreach (Contact::session_users($this->qreq) as $i => $email) {
            if ($email === "") {
                continue;
            }
            $url = $nav->base_absolute() . "u/{$i}/authorize{$nav->php_suffix}?code=" . urlencode($this->token->salt) . "&authconfirm=1";
            $buttons[] = Ht::button("Sign in as " . htmlspecialchars($email), ["type" => "submit", "formaction" => $url, "formmethod" => "post", "class" => "mt-2 w-100 flex-grow-1"]);
        }
        if (!empty($buttons)) {
            echo '<div class="mt-4">', join("", $buttons), '</div>';
        }
    }

    function print_form_actions() {
        if (($lt = $this->conf->login_type()) === "none" || $lt === "oauth") {
            return;
        }
        echo '<div class="popup-actions">',
            Ht::submit("", "Sign in", ["id" => "k-signin", "class" => "btn-success", "tabindex" => 1]);
        if ($this->cs->root !== "home") {
            echo Ht::submit("cancel", "Cancel", ["tabindex" => 1, "formnovalidate" => true, "class" => "uic js-no-signin"]);
        }
        echo '</div>';
    }

    private function handle_authconfirm() {
        if (!$this->qreq->code
            || !($tok = TokenInfo::find_active($this->qreq->code, TokenInfo::OAUTHCODE, $this->conf))
            || !($client = $this->find_client($tok->data("client_id")))) {
            $this->print_error_exit("<0>Invalid or expired authentication request");
        }
        '@phan-var-force OAuthClient $client';

        if ($tok->data("cancelled")
            || ($this->qreq->cancel && $tok->data("id_token") === null)) {
            $tok->change_data("cancelled", true)->update();
            $this->redirect_error("access_denied");
        }

        if (!$tok->data("id_token")) {
            $this->make_authconfirm_jwt($client, $tok);
        }

        $this->qreq->redirect_uri = $tok->data("redirect_uri");
        throw new Redirection($this->extend_redirect_uri([
            "code" => $tok->salt, "state" => $tok->data("state")
        ]));
    }

    private function make_authconfirm_jwt(OAuthClient $client, TokenInfo $tok) {
        if (!$this->viewer->has_email()
            || $this->viewer->is_actas_user()
            || $this->viewer->is_bearer_authorized()) {
            $this->print_error_exit("<0>Authentication request failed");
        }

        $payload = [
            "iss" => $this->conf->opt("oAuthIssuer"),
            "aud" => $client->client_id,
            "exp" => $tok->timeInvalid,
            "iat" => Conf::$now
        ];
        if (($nonce = $tok->data("nonce")) !== null) {
            $payload["nonce"] = $nonce;
        }
        $payload["email"] = $this->viewer->email;
        $payload["email_verified"] = true; // XXX special users?
        $payload["given_name"] = $this->viewer->firstName;
        $payload["family_name"] = $this->viewer->lastName;

        $jwt = JWTParser::make_mac((object) $payload, $client->client_secret);

        $tok->change_data("id_token", $jwt)
            ->set_invalid_after(10 * 60)
            ->update();
    }

    /** @param string $error
     * @param ?string $error_description
     * @return never */
    private function redirect_error($error, $error_description = null) {
        $p = ["error" => $error];
        if ($error_description !== null) {
            $p["error_description"] = $error_description;
        }
        if (isset($this->qreq->state)) {
            $p["state"] = $this->qreq->state;
        }
        throw new Redirection($this->extend_redirect_uri($p));
    }

    /** @param string $m
     * @return never */
    private function print_error_exit($m) {
        if (http_response_code() === 200) {
            http_response_code(400);
        }
        $this->qreq->print_header("Sign in", "authorize", ["action_bar" => "", "body_class" => "body-error"]);
        $this->conf->error_msg($m);
        $this->qreq->print_footer();
        exit;
    }

    function go() {
        // handle internal action
        if ($this->qreq->authconfirm) {
            $this->handle_authconfirm();
        }

        // look up client
        if (empty($this->clients) || $this->conf->opt("oAuthIssuer") === null) {
            $this->print_error_exit("<0>This site does not support authorization clients");
        } else if (!isset($this->qreq->client_id)) {
            $this->print_error_exit("<0>Authorization client missing");
        }
        $client = $this->find_client($this->qreq->client_id);
        if (!$client) {
            http_response_code(404);
            $this->print_error_exit("<0>Authorization client not found");
        }

        // `redirect_uri` must be present and match a configured value
        if (!isset($this->qreq->redirect_uri)) {
            $this->print_error_exit("<0>Authorization parameter <code>redirect_uri</code> missing");
        } else if (!in_array($this->qreq->redirect_uri, $client->redirect_uri)) {
            $this->print_error_exit("<0>Invalid authorization parameter <code>redirect_uri</code>");
        }

        // From here on, all errors should be sent to `redirect_uri`.

        if ($this->conf->external_login()
            || ((($lt = $this->conf->login_type()) === "none" || $lt === "oauth")
                && !$this->conf->oauth_providers())) {
            $this->redirect_error("unauthorized_client", "This site does not support authorization clients");
        } else if (!isset($this->qreq->state)
                   || $this->qreq->response_type !== "code") {
            $this->redirect_error("invalid_request");
        } else {
            $this->handle_request($client);
        }
    }

    static function oauthtoken_api(Contact $user, Qrequest $qreq) {
        return (new Authorize_Page($user, $qreq))->handle_oauthtoken_api();
    }

    private function handle_oauthtoken_api() {
        // reject if not oAuthIssuer
        if (($issuer = $this->conf->opt("oAuthIssuer")) === null) {
            return JsonResult::make_minimal(400, ["error" => "invalid_request"]);
        }

        // look up client
        $clids = $clsecrets = [];
        $clauth = false;
        if (($auth = $this->qreq->header("Authorization"))) {
            if (preg_match('/\A\s*Basic\s+(\S+)\s*\z/i', $auth, $m)
                && ($d = base64_decode($m[1], true)) !== false
                && ($p = strpos($d, ":")) !== false) {
                $clauth = true;
                $clids[] = substr($d, 0, $p);
                $clsecrets[] = substr($d, $p + 1);
            } else {
                return JsonResult::make_minimal(400, ["error" => "invalid_request"]);
            }
        }
        if (isset($this->qreq->client_id)) {
            $clids[] = $this->qreq->client_id;
            $clsecrets[] = $this->qreq->client_secret ?? "";
        }
        if (count($clids) !== 1) {
            return JsonResult::make_minimal(400, ["error" => "invalid_request"]);
        }

        if (!($client = $this->find_client($clids[0]))
            || ($client->client_secret ?? "") !== $clsecrets[0]) {
            header("WWW-Authenticate: Basic realm=\"{$issuer}\"");
            return JsonResult::make_minimal(401, ["error" => "invalid_client"]);
        }

        // look up code
        if (!$this->qreq->code
            || !($tok = TokenInfo::find_active($this->qreq->code, TokenInfo::OAUTHCODE, $this->conf))
            || !$tok->data("id_token")
            || $tok->data("client_id") !== $this->qreq->client_id
            || $tok->data("redirect_uri") !== $this->qreq->redirect_uri) {
            return JsonResult::make_minimal(400, ["error" => "invalid_grant"]);
        }

        // check grant type
        if ($this->qreq->grant_type !== "authorization_code") {
            return JsonResult::make_minimal(400, ["error" => "unsupported_grant_type"]);
        }

        // return code
        header("Cache-Control: no-store");
        return JsonResult::make_minimal(200, ["id_token" => $tok->data("id_token")]);
    }
}
