<?php
// pages/p_authorize.php -- HotCRP OAuth 2.0 authorization provider page
// Copyright (c) 2022-2026 Eddie Kohler; see LICENSE.

namespace HotCRP;
use Conf, ComponentSet, Contact, Ht, JsonResult, Qrequest, Redirection, PageCompletion;
use TokenInfo, TokenScope, Signin_Page, Authorization_Token, XtParams;
use MessageItem, FmtArg;

class OAuthClient {
    /** @var string */
    public $name;
    /** @var ?string */
    public $title;
    /** @var string */
    public $client_id;
    /** @var string */
    public $client_secret;
    /** @var ?string */
    public $client_uri;
    /** @var ?bool */
    public $is_cdb;
    /** @var null|int|string */
    public $access_token_expires_in;
    /** @var null|int|string */
    public $refresh_token_expires_in;
    /** @var ?string */
    public $scope;
    /** @var bool */
    public $only_openid;
    /** @var mixed */
    public $allow_if;
    /** @var list<string> */
    public $redirect_uris = [];

    /** @var ?string */
    public $requested_scope;
    /** @var ?TokenInfo */
    public $token;

    /** @param object $x */
    function __construct($x) {
        $this->name = $x->name ?? null;
        $this->title = $x->title ?? null;
        $this->client_id = $x->client_id ?? null;
        $this->client_secret = $x->client_secret ?? null;
        $this->client_uri = $x->client_uri ?? null;
        $this->is_cdb = $x->is_cdb ?? false;
        $this->access_token_expires_in = $x->access_token_expires_in ?? null;
        $this->refresh_token_expires_in = $x->refresh_token_expires_in ?? null;
        $this->scope = $x->scope ?? null;
        $this->only_openid = $this->scope === null
            || trim($this->scope) === ""
            || TokenScope::scope_str_all_openid($this->scope);
        $this->allow_if = $x->allow_if ?? null;
        $uri = $x->redirect_uris ?? $x->redirect_uri ?? [];
        if (is_string($uri)) {
            $this->redirect_uris[] = $uri;
        } else if (is_list($uri)) {
            $this->redirect_uris = $uri;
        }
    }

    /** @param object $x
     * @return ?OAuthClient */
    static function make($x) {
        $oac = new OAuthClient($x);
        if (!is_string($oac->client_id)
            || empty($oac->redirect_uris)) {
            return null;
        }
        return $oac;
    }

    /** @param object $x
     * @param TokenInfo $ctok
     * @return OAuthClient */
    static function make_dynamic($x, $ctok) {
        $oac = new OAuthClient($x);
        $oac->title = $ctok->data("client_name") ?? $oac->title;
        $oac->client_id = $ctok->salt;
        $oac->client_secret = $ctok->data("client_secret");
        $oac->redirect_uris = $ctok->data("redirect_uris");
        $oac->requested_scope = $ctok->data("requested_scope");
        return $oac;
    }

    /** @return string */
    function title_text() {
        return $this->title ?? $this->name;
    }

    /** @return string */
    function title_html() {
        return htmlspecialchars($this->title_text());
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
    /** @var OAuthClient */
    public $client;
    /** @var array<string,object> */
    private $clients = [];
    /** @var TokenInfo */
    private $token;

    function __construct(Contact $viewer, Qrequest $qreq, ?ComponentSet $cs = null) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
        $this->cs = $cs;
        $this->clients = self::oauth_clients($this->conf);
    }


    /** @return array<string,object> */
    static function oauth_clients(Conf $conf) {
        $clients = $conf->_xtbuild_resolve([], "oAuthClients");
        if (empty($clients) || $conf->opt("oAuthDynamicClients")) {
            return $clients;
        }
        return array_filter($clients, function ($cx) { return !($cx->dynamic ?? false); });
    }

    /** @param string $salt
     * @return ?TokenInfo */
    private function find_token($salt) {
        return TokenInfo::find_from($salt, $this->conf, $salt[2] === "T");
    }

    /** @return ?OAuthClient */
    private function find_client($client_id) {
        $dynamic = [];
        foreach ($this->clients as $cx) {
            if ($cx->dynamic ?? false) {
                $dynamic[] = $cx;
            } else if (($cx->client_id ?? null) === $client_id) {
                return OAuthClient::make($cx);
            }
        }
        if ($dynamic
            && strlen($client_id) >= 30
            && strlen($client_id) <= 128
            && (str_starts_with($client_id, "hctk_")
                || str_starts_with($client_id, "hcTk_"))
            && ($ctok = $this->find_token($client_id))
            && $ctok->is_active(TokenInfo::OAUTHCLIENT)) {
            $client_name = $ctok->data("hotcrp_name") ?? "dynamic";
            foreach ($dynamic as $cx) {
                if ($cx->name === $client_name)
                    return OAuthClient::make_dynamic($cx, $ctok);
            }
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

    /** @param string $s
     * @return bool */
    static private function check_code_challenge_syntax($s) {
        return strlen($s) >= 43
            && strlen($s) <= 128
            && preg_match('/\A[-._~0-9A-Za-z]++\z/', $s);
    }

    /** @param string $s
     * @return bool */
    static private function check_scope_syntax($s) {
        return strlen($s) <= 1024
            && preg_match('/\A[ !\#-\x5b\x5d-~]*+\z/', $s);
    }

    private function handle_request() {
        $scope = trim($this->qreq->scope ?? "");
        if (!self::check_scope_syntax($scope)) {
            $this->redirect_error("invalid_scope");
        }
        if ($scope === "") {
            $scope = "openid";
        }
        if ($this->client->only_openid
            && !TokenScope::scope_str_contains($scope, "openid")) {
            $this->redirect_error("invalid_scope", "Scope `openid` required");
        }

        if ($this->qreq->response_type !== "code") {
            $this->redirect_error("unsupported_response_type", "Response type `code` required");
        }

        if (($this->qreq->request ?? "") !== "") {
            $this->redirect_error("request_not_supported");
        } else if (($this->qreq->request_uri ?? "") !== "") {
            $this->redirect_error("request_uri_not_supported");
        } else if (($this->qreq->registration ?? "") !== "") {
            $this->redirect_error("registration_not_supported");
        }

        if (($this->qreq->prompt ?? "") !== "") {
            $prompts = [];
            foreach (explode(" ", trim($this->qreq->prompt)) as $p) {
                if ($p !== "")
                    $prompts[] = $p;
            }
            if (in_array("none", $prompts, true)) {
                $this->redirect_error("interaction_required");
            }
        }

        $code_challenge = $code_challenge_method = null;
        if (($this->qreq->code_challenge ?? "") !== "") {
            $code_challenge = $this->qreq->code_challenge;
            if (!self::check_code_challenge_syntax($code_challenge)) {
                $this->redirect_error("invalid_request");
            }
            $code_challenge_method = $this->qreq->code_challenge_method ?? "";
            if ($code_challenge_method === "") {
                $code_challenge_method = "plain";
            } else if ($code_challenge_method !== "plain"
                       && $code_challenge_method !== "S256") {
                $this->redirect_error("invalid_request", "Invalid `code_challenge_method`");
            }
        }

        // XXX max_age
        // XXX prompt login vs. select_account vs. consent
        // XXX record consent for future use?

        $token = (new TokenInfo($this->conf, TokenInfo::OAUTHCODE))
            ->set_token_pattern("hcoc[36]")
            ->set_invalid_in(3600)
            ->set_expires_in(86400)
            ->change_data("state", $this->qreq->state)
            ->change_data("nonce", $this->qreq->nonce)
            ->change_data("scope", $scope)
            ->change_data("client_id", $this->client->client_id)
            ->change_data("redirect_uri", $this->qreq->redirect_uri);
        if ($code_challenge !== null) {
            $token->change_data("code_challenge", $code_challenge)
                ->change_data("code_challenge_method", $code_challenge_method);
        }
        $this->token = $token->insert();
        $this->print_form();
    }

    private function actual_emails() {
        return array_filter(Contact::session_emails($this->qreq),
            function ($e) { return $e !== ""; });
    }

    private function signin_url() {
        $nav = $this->qreq->navigation();
        return $this->conf->hoturl("signin", ["redirect" => "authorize{$nav->php_suffix}?code=" . urlencode($this->token->salt)]);
    }

    function print_form() {
        if (!$this->cs) {
            JsonResult::make_minimal(200, ["code" => $this->token->salt])->complete();
        }

        // redirect to signin if we have any available accounts
        if (!count($this->actual_emails())) {
            throw new Redirection($this->signin_url());
        }

        $this->qreq->print_header("Sign in", "authorize", [
            "action_bar" => "", "hide_header" => true,
            "save_messages" => true, "body_class" => "body-signin"
        ]);
        Signin_Page::print_form_start_for($this->qreq, "=signin");
        $this->cs->print_members("authorize/form");
        echo '</div>';
        $this->qreq->print_footer();
    }

    function print_form_title() {
        echo '<h1 id="h-title">Sign in</h1>';
        $clt = $this->client->title_html();
        if ($this->client->client_uri) {
            $clt = Ht::link($clt, htmlspecialchars($this->client->client_uri));
        }
        echo '<div class="mb-4">to continue to ', $clt, '</div>';
        $this->conf->report_saved_messages();
    }

    function print_form_annotation() {
        $clt = $this->client->title_html();
        echo '<p class="mt-4 mb-0 hint">If you continue, HotCRP will share your name, email address, affiliation, and other profile information with ', $clt, '.</p>';
        if (!$this->client->only_openid
            && !TokenScope::scope_str_all_openid($this->client->requested_scope)) {
            echo '<div class="has-fold foldc mt-3 js-fold-focus">',
                '<p class="hint">HotCRP will also allow ', $clt, ' to act on your behalf using an API. <strong>Do not approve this request</strong> unless you trust ', $clt, '.</p>',
                '<p class="fn hint mb-0">', Ht::button("Edit scopes (advanced)", ["class" => "link ui js-foldup"]), '</p>',
                '<div class="f-i fx mb-0">',
                '<label for="k-scope">Scope</label>',
                Ht::entry("scope", $this->client->requested_scope, ["id" => "k-scope", "spellcheck" => false, "class" => "w-99 want-focus"]),
                '<p class="mt-1 mb-0 hint">This space-separated list limits the rights available for API access. Examples: <code>read</code> (read-only access), <code>submission:admin#r1</code> (access to submissions tagged #r1)</p>',
                '</div>';
        }
    }

    function print_form_main() {
        $buttons = [];
        $nav = $this->qreq->navigation();
        $top = "";
        foreach ($this->actual_emails() as $i => $email) {
            $url = $nav->base_absolute() . "u/{$i}/authorize{$nav->php_suffix}?code=" . urlencode($this->token->salt) . "&authconfirm=1";
            $buttons[] = Ht::button("Sign in as " . htmlspecialchars($email), ["type" => "submit", "formaction" => $url, "formmethod" => "post", "class" => "btn-primary{$top} w-100 flex-grow-1"]);
            $top = " mt-2";
        }

        $buttons[] = Ht::link("Use another account", $this->signin_url(),
            ["class" => "btn{$top} w-100 flex-grow-1"]);
        echo '<div class="mb-5">', join("", $buttons), '</div>';
    }

    private function lookup_code() {
        return $this->qreq->code
            && ($this->token = TokenInfo::find($this->qreq->code, $this->conf))
            && $this->token->is_active(TokenInfo::OAUTHCODE)
            && ($this->client = $this->find_client($this->token->data("client_id")));
    }

    private function handle_authconfirm() {
        if (!$this->lookup_code()) {
            $this->print_error_exit("<0>Invalid or expired authentication request");
        }

        if (!$this->viewer->has_email()
            || $this->viewer->is_actas_user()
            || $this->viewer->is_bearer_authorized()) {
            $this->print_error_exit("<0>Authentication request failed");
        }

        if (isset($this->client->allow_if)
            && !(new XtParams($this->conf, $this->viewer))->checkf($this->client)) {
            if (!$this->cs) {
                JsonResult::make_minimal(401, [
                    "error" => "invalid_grant",
                    "error_description" => "User not authorized to create access tokens"
                ])->complete();
            }
            $this->conf->feedback_msg(
                MessageItem::error("<0>User {} cannot authorize access by {}", new FmtArg(0, $this->viewer->email, 0), new FmtArg(1, $this->client->title_text(), 0)),
                MessageItem::inform("<0>This site limits which users can authenticate with {}. You may need to use another account.", new FmtArg(0, $this->client->title_text(), 0))
            );
            http_response_code(401);
            $this->print_form();
            throw new PageCompletion;
        }

        if ($this->token->data("cancelled")
            || (friendly_boolean($this->qreq->cancel)
                && $this->token->data("email") === null)) {
            $this->token->change_data("cancelled", true)->update();
            $this->redirect_error("access_denied");
        }

        if (!$this->token->data("email")) {
            $this->token->change_data("email", $this->viewer->email)
                ->change_data("iat", Conf::$now);
            if (isset($this->qreq->scope)) {
                $this->token->change_data("scope", $this->qreq->scope);
            }
            $this->token->set_invalid_in(10 * 60)
                ->update();
        }

        $this->qreq->redirect_uri = $this->token->data("redirect_uri");
        throw new Redirection($this->extend_redirect_uri([
            "code" => $this->token->salt,
            "state" => $this->token->data("state")
        ]));
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
        $this->qreq->print_header("Sign in", "authorize", ["action_bar" => "", "hide_header" => true, "body_class" => "body-error"]);
        $this->conf->error_msg($m);
        $this->qreq->print_footer();
        exit(0);
    }

    /** @param string $uri
     * @return bool */
    private function check_redirect_uri($uri) {
        if (strpos($uri, "#") !== false) {
            return false;
        }
        return str_starts_with($uri, "https://")
            // allow localhost redirect URIs for local development
            || ((str_starts_with($uri, "http://localhost/")
                 || str_starts_with($uri, "http://localhost:"))
                && $this->qreq->navigation()->host === "localhost");
    }

    function go() {
        // handle internal action
        if (friendly_boolean($this->qreq->authconfirm)) {
            $this->handle_authconfirm();
        } else if (isset($this->qreq->code) && $this->lookup_code()) {
            $this->print_form();
            return;
        }

        // look up client
        if (empty($this->clients)) {
            $this->print_error_exit("<0>This site does not support authorization clients");
        } else if (!isset($this->qreq->client_id)) {
            $this->print_error_exit("<0>Authorization client missing");
        }
        $this->client = $this->find_client($this->qreq->client_id);
        if (!$this->client) {
            http_response_code(404);
            $this->print_error_exit("<0>Authorization client not found");
        }

        // `redirect_uri` must be present and match a configured value
        if (!isset($this->qreq->redirect_uri)) {
            $this->print_error_exit("<0>Authorization parameter `redirect_uri` missing");
        } else if (!in_array($this->qreq->redirect_uri, $this->client->redirect_uris, true)
                   || !$this->check_redirect_uri($this->qreq->redirect_uri)) {
            $this->print_error_exit("<0>Invalid authorization parameter `redirect_uri`");
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
            $this->handle_request();
        }
    }


    /** @param mixed $s
     * @param int $default
     * @return int */
    static function parse_expires_in($s, $default) {
        if ($s === "never") {
            return -1;
        } else if (is_string($s) && preg_match('/\A(\d++\.?\d*+|\.\d++)d\z/', $s, $m)) {
            return (int) (floatval($m[1]) * 86400);
        } else if (is_int($s)) {
            return $s;
        }
        return $default;
    }


    static function oauthtoken_api(Contact $user, Qrequest $qreq) {
        $jr = (new Authorize_Page($user, $qreq))->handle_oauthtoken();
        //file_put_contents("/tmp/oauth.txt", json_encode($jr->content, JSON_PRETTY_PRINT  | JSON_UNESCAPED_SLASHES) . "\n\n====\n", FILE_APPEND);
        return $jr;
    }

    private function oauthtoken_error($type) {
        return JsonResult::make_minimal(400, ["error" => $type]);
    }

    private function handle_oauthtoken() {
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
                return $this->oauthtoken_error("invalid_request");
            }
        }
        if (isset($this->qreq->client_id)) {
            $clids[] = $this->qreq->client_id;
            $clsecrets[] = $this->qreq->client_secret ?? "";
        }
        if (count($clids) !== 1) {
            return $this->oauthtoken_error("invalid_request");
        }

        if (!($client = $this->find_client($clids[0]))
            || ($client->client_secret ?? "") !== $clsecrets[0]) {
            $this->conf->www_authenticate_header("invalid_client", $this->qreq);
            return $this->oauthtoken_error("invalid_client");
        }

        $scope = trim($this->qreq->scope ?? "");
        if (!self::check_scope_syntax($scope)) {
            return $this->oauthtoken_error("invalid_scope");
        }

        // handle grant request
        $this->client = $client;
        if ($this->qreq->grant_type === "authorization_code") {
            $jr = $this->handle_oauthtoken_code();
        } else if ($this->qreq->grant_type === "refresh_token") {
            $jr = $this->handle_oauthtoken_refresh($scope);
        } else {
            return $this->oauthtoken_error("unsupported_grant_type");
        }
        if ($jr && $jr->status < 400) {
            header("Cache-Control: no-store");
        }
        return $jr ?? $this->oauthtoken_error("invalid_grant");
    }

    /** @return ?JsonResult */
    private function handle_oauthtoken_code() {
        // find code
        if (!$this->qreq->code
            || !($tok = TokenInfo::find($this->qreq->code, $this->conf))
            || !$tok->is_active(TokenInfo::OAUTHCODE)
            || !$tok->data("email")
            || $tok->data("client_id") !== $this->client->client_id) {
            return null;
        }

        // check arguments
        $redirect_uri = $this->qreq->redirect_uri ?? "";
        $code_challenge = $tok->data("code_challenge") ?? "";
        if ($redirect_uri === ""
            && $code_challenge === ""
            && !$tok->data("nonce")) {
            return null;
        }
        $code_verifier = $this->qreq->code_verifier ?? "";
        if (($code_verifier !== "") !== ($code_challenge !== "")) {
            return $this->oauthtoken_error("invalid_request");
        }
        if ($code_verifier !== "") {
            if ($tok->data("code_challenge_method") === "plain") {
                $code_check = $code_verifier;
            } else {
                $code_check = base64url_encode(hash("sha256", $code_verifier, true));
            }
            if ($code_challenge !== $code_check) {
                return null;
            }
        }
        if ($redirect_uri !== ""
            && $redirect_uri !== $tok->data("redirect_uri")) {
            return null;
        }

        // check replay
        if ($tok->data("used")) {
            $this->oauthtoken_revoke($tok, TokenInfo::BEARER);
            $this->oauthtoken_revoke($tok, TokenInfo::OAUTHREFRESH);
            return null;
        }
        $tok->change_data("used", true)
            ->set_expires_in($this->client->only_openid ? 600 : 86400);

        // check user
        $luser = $this->conf->user_by_email($tok->data("email"));
        $guser = $this->conf->cdb_user_by_email($tok->data("email"));
        $user = $this->client->is_cdb ? $guser : $luser ?? $guser;
        if (!$user
            || $user->is_disabled()) {
            $tok->update();
            return null;
        }

        // create id_token
        $a = [];
        if (TokenScope::scope_str_contains($tok->data("scope"), "openid")) {
            $a["id_token"] = $this->make_id_token($user, $tok);
            $a["scope"] = "openid email profile";
            $tok->change_data("id_token", $a["id_token"]);
        }
        if ($this->client->only_openid) {
            // invalid token fills required elements of the response
            $a["access_token"] = "hct_invalid_token";
            $a["token_type"] = "Bearer";
            $a["access_token_expires_in"] = 0;
        } else {
            $atok = $this->oauthtoken_create_access($tok, $user, "");
            $rtok = $this->oauthtoken_create_refresh($tok, $user, $atok);
            $tok->change_data("access_token", $atok->salt)
                ->change_data("refresh_token", $rtok->salt);
            $this->export_access_token($a, $atok, $rtok);
        }
        $tok->update();
        return JsonResult::make_minimal(200, $a);
    }

    private function make_id_token(Contact $user, TokenInfo $tok) {
        $payload = [
            "iss" => $this->conf->oauth_issuer(),
            "aud" => $this->client->client_id,
            "exp" => Conf::$now + 86400,
            "iat" => Conf::$now
        ];
        if (($nonce = $tok->data("nonce")) !== null) {
            $payload["nonce"] = $nonce;
        }
        $payload["email"] = $user->email;
        $payload["email_verified"] = true; // XXX special users?
        $payload["given_name"] = $user->firstName;
        $payload["family_name"] = $user->lastName;

        return JWTParser::make_mac((object) $payload, $this->client->client_secret);
    }

    private function export_access_token(&$a, TokenInfo $atok, TokenInfo $rtok) {
        $a["access_token"] = $atok->salt;
        $a["token_type"] = "Bearer";
        if ($atok->timeExpires > 0) {
            $a["expires_in"] = $atok->timeExpires - conf::$now;
        }
        $a["refresh_token"] = $rtok->salt;
        $scope = $atok->data("scope");
        $a["scope"] = Ht::add_tokens($a["scope"] ?? null, $scope ?? "all");
    }

    private function oauthtoken_create_access($tok, $user, $scope) {
        // compute new scope
        $ts = null;
        if (($tokscope = $tok->data("scope"))) {
            $ts = TokenScope::parse($tokscope, null);
        }
        if ($this->client->scope) {
            $ts = TokenScope::intersect($ts, $this->client->scope);
        }
        if ($this->client->requested_scope) {
            $ts = TokenScope::intersect($ts, $this->client->requested_scope);
        }
        if ($scope !== "") {
            $ts = TokenScope::intersect($ts, $scope);
        }

        $exp = self::parse_expires_in($this->client->access_token_expires_in ?? null, 3600);
        $atok = Authorization_Token::prepare_bearer($user, $exp);
        $atok->change_data("client_id", $tok->data("client_id"))
            ->change_data("scope", TokenScope::unparse($ts));
        // XXX note
        return $atok->insert();
    }

    private function oauthtoken_create_refresh($tok, $user, $atok) {
        $exp = self::parse_expires_in($this->client->refresh_token_expires_in ?? null, 7 * 86400);
        $rtok = Authorization_Token::prepare_refresh($user, $exp);
        $rtok->change_data("client_id", $tok->data("client_id"))
            ->change_data("scope", $tok->data("scope"))
            ->change_data("access_token", $atok->salt);
        return $rtok->insert();
    }

    /** @param int $type */
    private function oauthtoken_revoke(TokenInfo $codetok, $type) {
        $name = $type === TokenInfo::BEARER ? "access_token" : "refresh_token";
        if (($salt = $codetok->data($name))
            && ($tok = $this->find_token($salt))
            && $tok->is_active($type)) {
            $tok->set_invalid()->update();
        }
    }

    /** @return ?JsonResult */
    private function handle_oauthtoken_refresh($scope) {
        $rsalt = $this->qreq->refresh_token;
        if (!$rsalt
            || (!str_starts_with($rsalt, "hctr_")
                && !str_starts_with($rsalt, "hcTr_"))
            || !($rtok = $this->find_token($rsalt))
            || $rtok->capabilityType !== TokenInfo::OAUTHREFRESH
            || $rtok->data("client_id") !== $this->client->client_id) {
            return null;
        } else if (!$rtok->is_active()) {
            // replay attack: revoke all refresh tokens and access tokens
            $this->oauthtoken_revoke_all($rtok);
            return null;
        }
        $this->oauthtoken_revoke($rtok, TokenInfo::BEARER);
        // check user
        $user = $rtok->is_cdb
            ? $this->conf->cdb_user_by_id($rtok->contactId)
            : $this->conf->user_by_id($rtok->contactId);
        if (!$user
            || $user->is_disabled()) {
            return null;
        }
        $atok1 = $this->oauthtoken_create_access($rtok, $user, $scope);
        $rtok1 = $this->oauthtoken_create_refresh($rtok, $user, $atok1);
        $a = [];
        $this->export_access_token($a, $atok1, $rtok1);
        $rtok->set_invalid()
            ->change_data("next_refresh_token", $rtok1->salt)
            ->update();
        return JsonResult::make_minimal(200, $a);
    }

    private function oauthtoken_revoke_all($rtok) {
        if (!$rtok->has_data("next_refresh_token")) {
            return;
        }
        $i = 0;
        while ($rtok
               && $rtok->capabilityType === TokenInfo::OAUTHREFRESH
               && ($next = $rtok->data("next_refresh_token"))
               && $i < 200) {
            $rtok->change_data("next_refresh_token", null)->update();
            $rtok = $this->find_token($next);
            ++$i;
        }
        if ($rtok) {
            $this->oauthtoken_revoke($rtok, TokenInfo::BEARER);
            $rtok->set_invalid()->update();
        }
    }


    static function oauthregister_api(Contact $user, Qrequest $qreq) {
        if (!$user->conf->opt("oAuthClients")
            || !$user->conf->opt("oAuthDynamicClients")) {
            return JsonResult::make_error(404, "<0>Function not found");
        }
        return (new Authorize_Page($user, $qreq))->handle_oauthregister();
    }

    private function oauthregister_error($type) {
        return JsonResult::make_minimal(400, ["error" => $type]);
    }

    private function handle_oauthregister() {
        // validate content
        if ($this->qreq->body_content_type() !== "application/json"
            || ($reqstr = $this->qreq->body()) === null
            || !is_object(($reqj = json_decode($reqstr)))
            || !is_array($reqj->redirect_uris ?? null)
            || empty($reqj->redirect_uris)) {
            return $this->oauthregister_error("invalid_request");
        }
        $redirect_uris = $reqj->redirect_uris;
        foreach ($redirect_uris as $uri) {
            if (!is_string($uri)
                || !$this->check_redirect_uri($uri)) {
                return $this->oauthregister_error("invalid_redirect_uri");
            }
        }
        //file_put_contents("/tmp/oauth.txt", json_encode($reqj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n---\n\n", FILE_APPEND);

        // find dynamic client with matching redirect uri
        $client = null;
        foreach ($this->clients as $cx) {
            if (($cx->dynamic ?? false)
                && (!isset($cx->redirect_uris)
                    || array_intersect($redirect_uris, $cx->redirect_uris) === $redirect_uris)) {
                $client = new OAuthClient($cx);
                break;
            }
        }
        if (!$client) {
            return JsonResult::make_error(404, "<0>Function not found");
        }
        if ($client->only_openid
            && isset($reqj->grant_types)
            && is_array($reqj->grant_types)
            && in_array("refresh_token", $reqj->grant_types, true)
            && !TokenScope::scope_str_all_openid($reqj->scope ?? "")) {
            return $this->oauthregister_error("invalid_client_metadata");
        }

        // XXX rate limit

        // create client
        $csecret = base48_encode(random_bytes(32));
        $ctok = (new TokenInfo($this->conf, TokenInfo::OAUTHCLIENT))
            ->set_contactdb($client->is_cdb && $this->conf->contactdb())
            ->set_invalid_in(60 * 86400)
            ->set_expires_in(65 * 86400)
            ->change_data("client_secret", $csecret)
            ->change_data("redirect_uris", $redirect_uris);
        if ($client->name !== "dynamic") {
            $ctok->change_data("hotcrp_name", $client->name);
        }
        if (isset($reqj->client_name) && is_string($reqj->client_name)) {
            $ctok->change_data("client_name", $reqj->client_name);
        }
        if (isset($reqj->scope) && is_string($reqj->scope)) {
            $ctok->change_data("requested_scope", $reqj->scope);
        }
        $ctok->set_token_pattern($ctok->is_cdb ? "hcTk_[20]" : "hctk_[20]")
            ->insert();
        header("Cache-Control: no-store");
        return JsonResult::make_minimal(201, [
            "client_id" => $ctok->salt,
            "client_secret" => $csecret,
            "client_id_issued_at" => $ctok->timeCreated,
            "client_secret_expires_at" => $ctok->timeInvalid,
            "redirect_uris" => $redirect_uris,
            "grant_types" => ["authorization_code", "refresh_token"],
            "token_endpoint_auth_method" => "client_secret_basic"
        ]);
    }
}
