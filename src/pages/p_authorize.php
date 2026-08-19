<?php
// pages/p_authorize.php -- HotCRP OAuth 2.0 authorization provider page
// Copyright (c) 2022-2026 Eddie Kohler; see LICENSE.

namespace HotCRP;
use Conf, Contact, Navigation, Ht, JsonResult, Qrequest, Redirection,
    MessageItem, MessageSet, FmtArg, UnicodeHelper, ComponentSet, XtParams,
    PageCompletion, TokenInfo, TokenScope, Authorization_Token, SettingParser,
    BotContact, Signin_Page;

class Authorize_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var ComponentSet */
    public $cs;
    /** @var ?OAuthClient */
    public $client;
    /** @var array<string,object> */
    private $clients = [];
    /** @var ?TokenInfo */
    private $token;
    /** True once the consent form has been posted, which is the only user
     * action that authorizes a bounce to a self-registered `redirect_uri`.
     * @var bool */
    private $authconfirmed = false;
    /** @var ?list<Contact> */
    private $_bot_choices;

    /** Maximum length of a `state` or `nonce`; RFC 6749 sets no limit,
     * but avoid DoS */
    const MAXOPAQUE = 4096;

    function __construct(Contact $viewer, Qrequest $qreq, ?ComponentSet $cs = null) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
        $this->cs = $cs;
        $this->clients = OAuthClient::list($this->conf);
    }


    /** @return array<string,object>
     * @deprecated */
    static function oauth_clients(Conf $conf) {
        return OAuthClient::list($conf);
    }

    /** @param string $salt
     * @return ?TokenInfo */
    private function find_token($salt) {
        return TokenInfo::find_from($salt, $this->conf, $salt[2] === "T");
    }

    /** Look up the client named by `$client_id`, if any.
     * @return ?OAuthClient */
    private function find_client($client_id) {
        if ($this->conf->opt("oAuthMetadataDocumentClients")
            && ($ocd = OAuthClientDocument::try_make($this->conf, $client_id))) {
            foreach ($this->clients as $cx) {
                if (($cx->metadata_document ?? false)
                    && $ocd->matches($cx)) {
                    return OAuthClient::make_metadata_document($cx, $ocd);
                }
            }
        }
        $dynamic = [];
        foreach ($this->clients as $cx) {
            if ($cx->metadata_document ?? false) {
                // skip
            } else if ($cx->dynamic ?? false) {
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

    /** Apply the client ID metadata document stored in `$tok` to `$client`.
     * @return bool */
    private function apply_token_document(TokenInfo $tok, OAuthClient $client) {
        if (!$client->client_document) {
            return true;
        } else if (!is_object(($docj = $tok->data("client_document")))) {
            return false;
        }
        $client->client_document->document = $docj;
        return $client->load_document();
    }

    /** @param array<string,mixed> $param
     * @return string */
    private function extend_redirect_uri($param) {
        $uri = $this->qreq->redirect_uri;
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
            // RFC 6749 §3.3: a request that omits `scope` gets this server’s
            // default
            $scope = "openid email profile";
            if (!$this->client->only_openid) {
                [, $rest] = TokenScope::scope_str_split_openid($this->client->scope);
                $scope .= " " . $rest;
            }
        }
        if ($this->client->only_openid
            && !TokenScope::scope_str_contains($scope, "openid")) {
            $this->redirect_error("invalid_scope", "Scope `openid` required");
        }
        if ($this->client->is_cdb
            && ($ts = TokenScope::parse($scope, null))
            && $ts->has_selector()) {
            // A token for a cdb client works at every conference on the contact
            // database, but a selector means whatever it means at the site
            // presenting it: `#12` names a different submission at each.
            $this->redirect_error("invalid_scope", "Submission selectors not allowed for this client");
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
        if ($this->client->public_client($this->qreq->redirect_uri)
            && $code_challenge_method !== "S256") {
            // public clients must use PKCE (OAuth 2.1, RFC 8252 §8.1)
            $this->redirect_error("invalid_request", "Code challenge with method `S256` required");
        }

        // XXX max_age
        // XXX prompt login vs. select_account vs. consent
        // XXX record consent for future use?

        $this->print_form([
            "scope" => $scope,
            "code_challenge" => $code_challenge,
            "code_challenge_method" => $code_challenge_method
        ]);
    }

    private function authorized_emails() {
        return array_filter(Contact::session_emails($this->qreq),
            function ($e) { return $e !== ""; });
    }

    private function signin_url() {
        $nav = $this->qreq->navigation();
        if ($this->token) {
            $param = ["code" => $this->token->salt];
        } else {
            $param = $this->qreq->subset_as_array("client_id", "redirect_uri",
                "response_type", "state", "scope", "nonce", "code_challenge",
                "code_challenge_method", "prompt");
        }
        return $this->conf->hoturl("signin", [
            "redirect" => "authorize{$nav->php_suffix}?" . http_build_query($param)
        ]);
    }

    function print_form($token_params = null) {
        // redirect to signin if we have no available accounts
        if (!$this->authorized_emails()) {
            throw new Redirection($this->signin_url());
        }

        // create token if desired
        if ($token_params) {
            $token = (new TokenInfo($this->conf, TokenInfo::OAUTHCODE))
                ->set_token_pattern("hcoc[36]")
                ->set_invalid_in(3600)
                ->set_expires_in(86400)
                ->change_data("state", $this->qreq->state)
                ->change_data("nonce", $this->qreq->nonce)
                ->change_data("scope", $token_params["scope"])
                ->change_data("client_id", $this->client->client_id)
                // the name the consent page is about to show, so that the name
                // the user later revokes is the name they agreed to
                ->change_data("client_name", $this->client->title_text())
                ->change_data("redirect_uri", $this->qreq->redirect_uri);
            if ($token_params["code_challenge"] !== null) {
                $token->change_data("code_challenge", $token_params["code_challenge"])
                    ->change_data("code_challenge_method", $token_params["code_challenge_method"]);
            }
            if ($this->client->client_document) {
                // remember relevant subset of the client’s metadata document
                $token->change_data("client_document", $this->client->token_document());
            }
            $this->token = $token->insert();
        }

        if (!$this->cs) {
            JsonResult::make_minimal(200, ["code" => $this->token->salt])->complete();
        }

        $this->qreq->print_header("Sign in", "authorize", [
            "action_bar" => "", "hide_header" => true,
            "save_messages" => true, "body_class" => "body-signin"
        ]);
        Signin_Page::print_form_start_for($this->qreq, "=signin");
        $this->cs->print_members("authorize/form");
        echo '</form>';
        $this->qreq->print_footer();
    }

    function print_form_title() {
        echo '<h1 id="h-title">Sign in</h1>';
        $clt = $this->client->title_html();
        if ($this->client->client_uri) {
            // a client that registered itself chose this URL, and this page's
            // own URL can carry a code, so the link carries nothing along
            $clt = Ht::link($clt, $this->client->client_uri, ["rel" => "noopener noreferrer"]);
        }
        echo '<div class="mb-4">to continue to ', $clt, '</div>';
        $this->conf->report_saved_messages();
    }

    /** Print the domains involved for a client that registered itself. Such a client is not registered with this site, and its name
     * is whatever it chose to call itself, so show the user the domains
     * actually involved. */
    private function print_self_registered_identity() {
        $ruri = $this->token->data("redirect_uri") ?? "";
        $rhost = parse_url($ruri, PHP_URL_HOST) ?? $ruri;
        echo '<div class="msg msg-warning mt-4">';
        if ($this->client->client_document) {
            echo '<p>This request comes from <strong>',
                htmlspecialchars($this->client->client_document->host()),
                '</strong>.</p>';
        }
        if ($rhost === "localhost" || $rhost === "127.0.0.1" || $rhost === "[::1]") {
            echo '<p class="mb-0">Your authorization will be sent to a program running on your own computer (<code>',
                htmlspecialchars($ruri), '</code>).</p>';
        } else {
            echo '<p class="mb-0">Your authorization will be sent to <strong>',
                htmlspecialchars($rhost), '</strong>.</p>';
        }
        echo '</div>';
    }

    function print_form_annotation() {
        if ($this->client->self_registered) {
            $this->print_self_registered_identity();
        }
        $clt = $this->client->title_html();
        echo '<p class="mt-4 mb-0 hint">If you continue, HotCRP will share your name, email address, affiliation, and other profile information with ', $clt, '.</p>';
        if ($this->client->only_openid) {
            return;
        }
        [, $reqscope] = TokenScope::scope_str_split_openid($this->token->data("scope"));
        if ($reqscope === "") {
            $reqscope = "none";
        }
        $open = friendly_boolean($this->qreq->bots);
        echo '<div class="has-fold ', $open ? "foldo" : "foldc", ' mt-3 js-fold-focus">';
        if ($reqscope !== "none") {
            echo '<p class="hint">HotCRP will also allow ', $clt, ' to act on your behalf using an API. <strong>Do not approve this request</strong> unless you trust ', $clt, '.</p>';
        }
        echo '<p class="fn hint mb-0">', Ht::button("Edit scopes (advanced)", ["class" => "link ui js-foldup"]), '</p>',
            '<div class="f-i fx mb-0">',
            '<label for="k-scope">Scope</label>',
            Ht::entry("scope", $reqscope, ["id" => "k-scope", "spellcheck" => false, "class" => "w-99 want-focus"]),
            '<p class="mt-1 mb-0 hint">This space-separated list limits the rights available for API access. Examples: <code>read</code> (read-only access), <code>submission:admin#r1</code> (access to submissions tagged #r1), <code>none</code> (no API access)</p>',
            '</div></div>';
    }

    /** Bot accounts this request may authorize a grant for, or `[]`.
     *
     * A bot has no session of its own — it cannot sign in — so a grant that
     * speaks as one is made by a chair on its behalf, from the chair’s own
     * session slot. Two kinds of client are excluded: a cdb client, because a
     * bot has no contact-database identity and never gets one, and an
     * OpenID-only client, because a bot grant exists to carry API access and
     * there is no person for an `id_token` to name.
     * @return list<Contact> */
    private function bot_choices() {
        if ($this->_bot_choices !== null) {
            return $this->_bot_choices;
        }
        $this->_bot_choices = [];
        if ($this->client
            && !$this->client->is_cdb
            && !$this->client->only_openid
            && $this->viewer->privChair
            && !$this->viewer->is_actas_user()
            && $this->viewer->session_index() >= 0
            && !$this->viewer->is_bearer_authorized()) {
            foreach (BotContact::users($this->conf) as $u) {
                if (!$u->is_disabled())
                    $this->_bot_choices[] = $u;
            }
        }
        return $this->_bot_choices;
    }

    /** @param list<Contact> $bots */
    private function print_form_bots($bots) {
        $nav = $this->qreq->navigation();
        $uindex = $this->viewer->session_index();
        $buttons = [];
        $top = "";
        $any_disabled = false;
        echo '<p class="mb-4">A bot account is driven by a program rather than a person, and it cannot sign in on its own. Authorizing here lets ',
            $this->client->title_html(),
            ' act as the account you choose; everything it does is recorded as that account, and its reviews are marked AI.</p>';
        foreach ($bots as $bot) {
            $enabled = (new XtParams($this->conf, $bot))->checkf($this->client);
            $any_disabled = $any_disabled || !$enabled;
            // `bots=1` rides along so a post that fails its checks comes back
            // to this list rather than to the account list
            $url = $nav->base_absolute() . "u/{$uindex}/authorize{$nav->php_suffix}?code=" . urlencode($this->token->salt)
                . "&authconfirm=1&bots=1&authemail=" . urlencode($this->viewer->email)
                . "&authbot=" . urlencode($bot->email);
            $buttons[] = Ht::button("Sign in as " . htmlspecialchars($bot->email), ["type" => "submit", "formaction" => $url, "formmethod" => "post", "class" => "btn-primary{$top} w-100 flex-grow-1", "disabled" => !$enabled]);
            $top = " mt-2";
        }
        $buttons[] = Ht::link("<span class=\"arrow\">←</span> Back", $this->conf->hoturl("authorize", ["code" => $this->token->salt]),
            ["class" => "btn{$top} w-100 flex-grow-1"]);
        if ($any_disabled) {
            echo MessageSet::feedback_html([MessageItem::warning($this->conf->_("<0>This site limits which users can authenticate with {}", new FmtArg(0, $this->client->title_text(), 0)))]);
        }
        echo '<div class="mb-5">', join("", $buttons), '</div>';
    }

    function print_form_main() {
        $bots = $this->bot_choices();
        if (!empty($bots) && friendly_boolean($this->qreq->bots)) {
            $this->print_form_bots($bots);
            return;
        }
        $buttons = [];
        $nav = $this->qreq->navigation();
        $top = "";
        if ($this->client) {
            $this->conf->prefetch_users_by_email(array_values($this->authorized_emails()));
        }
        $any_disabled = false;
        // `u/{$uindex}` is a session slot, and slots can be reused between this
        // render and the post; name the account too, so the request that comes
        // back is for the account whose button the user pressed
        foreach ($this->authorized_emails() as $uindex => $email) {
            if ($this->client) {
                $user = $this->conf->user_by_email($email)
                    ?? $this->conf->cdb_user_by_email($email);
                $enabled = $user
                    && (new XtParams($this->conf, $user))->checkf($this->client);
            } else {
                $enabled = true;
            }
            $any_disabled = $any_disabled || !$enabled;
            $url = $nav->base_absolute() . "u/{$uindex}/authorize{$nav->php_suffix}?code=" . urlencode($this->token->salt) . "&authconfirm=1&authemail=" . urlencode($email);
            $buttons[] = Ht::button("Sign in as " . htmlspecialchars($email), ["type" => "submit", "formaction" => $url, "formmethod" => "post", "class" => "btn-primary{$top} w-100 flex-grow-1", "disabled" => !$enabled]);
            $top = " mt-2";
        }

        $buttons[] = Ht::link("Use another account", $this->signin_url(),
            ["class" => "btn{$top} w-100 flex-grow-1"]);
        if (!empty($bots)) {
            $buttons[] = Ht::link("Sign in as a bot <span class=\"arrow\">→</span>",
                $this->conf->hoturl("authorize", ["code" => $this->token->salt, "bots" => 1]),
                ["class" => "btn btn-success mt-2 w-100 flex-grow-1"]);
        }
        if ($any_disabled) {
            echo MessageSet::feedback_html([MessageItem::warning($this->conf->_("<0>This site limits which users can authenticate with {}", new FmtArg(0, $this->client->title_text(), 0)))]);
        }
        echo '<div class="mb-5">', join("", $buttons), '</div>';
    }

    /** Look up the code named by `$this->qreq->code`, setting `$this->token`
     * and `$this->client` if it is one this request may act on.
     *
     * A redeemed code is retained so that a later redemption is recognized as
     * a replay, but it is spent: this page must not offer it again.
     * @return bool */
    private function lookup_code() {
        if ($this->qreq->code
            && ($tok = TokenInfo::find($this->qreq->code, $this->conf))
            && $tok->is_active(TokenInfo::OAUTHCODE)
            && $tok->useCount === 0
            && ($client = $this->find_client($tok->data("client_id")))
            && $this->apply_token_document($tok, $client)) {
            $this->token = $tok;
            $this->client = $client;
            return true;
        }
        return false;
    }

    /** @return never */
    private function handle_authconfirm() {
        $this->authconfirmed = true;
        if (!$this->lookup_code()) {
            $this->print_error_exit("<0>Invalid or expired authentication request");
        }
        // `redirect_error` requires this
        $this->qreq->redirect_uri = $this->token->data("redirect_uri");

        if (!$this->viewer->has_email()
            || $this->viewer->is_actas_user()
            || $this->viewer->is_bearer_authorized()
            || (isset($this->qreq->authemail)
                && strcasecmp($this->qreq->authemail, $this->viewer->email) !== 0)) {
            $this->print_error_exit("<0>Authentication request failed");
        }

        // A grant may speak as a bot account. The bot has no session, so the
        // viewer stays the chair who is authorizing on its behalf.
        $grantee = $this->viewer;
        if (isset($this->qreq->authbot)) {
            $bot = $this->conf->user_by_email($this->qreq->authbot);
            if (!$this->viewer->privChair
                || !$bot
                || !$bot->is_bot()
                || $bot->is_disabled()
                || $this->client->is_cdb
                || $this->client->only_openid) {
                $this->print_error_exit("<0>Authentication request failed");
            }
            $grantee = $bot;
        }

        if (isset($this->client->allow_if)
            && !(new XtParams($this->conf, $grantee))->checkf($this->client)) {
            if (!$this->cs) {
                JsonResult::make_minimal(401, [
                    "error" => "invalid_grant",
                    "error_description" => "User not authorized to create access tokens"
                ])->complete();
            }
            $this->conf->feedback_msg(
                MessageItem::error($grantee !== $this->viewer ? "<0>User {} cannot be authorized to access {}" : "<0>User {} cannot authorize access by {}", new FmtArg(0, $grantee->email, 0), new FmtArg(1, $this->client->title_text(), 0)),
                MessageItem::inform("<0>This site limits which users can authenticate with {}. You may need to use another account.", new FmtArg(0, $this->client->title_text(), 0))
            );
            Navigation::http_response_code(401);
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
            $this->token->change_data("email", $grantee->email)
                ->change_data("iat", Conf::$now);
            if ($grantee !== $this->viewer) {
                // the grant speaks as the bot, but a person made it: record who
                $this->token->change_data("authorized_by", $this->viewer->email);
            }
            // Log every authorization, naming the grant
            $this->conf->log_for($this->viewer, $grantee,
                "OAuth authorization for " . $this->client->title_text()
                . " [" . (Authorization_Token::grant_id($this->token) ?? "?") . "]");
            $tokscope = $this->token->data("scope");
            $reqscope = isset($this->qreq->scope) ? trim($this->qreq->scope) : "";
            if ($reqscope !== "") {
                // the consent form’s field limits what was requested, as it
                // says it does; it is user input, so it gets the same syntax
                // check as the authorization request
                if (!self::check_scope_syntax($reqscope)) {
                    $this->redirect_error("invalid_scope");
                }
                $granted = TokenScope::unparse(TokenScope::intersect(
                    TokenScope::parse($tokscope, null),
                    $reqscope
                ));
                // carry over OpenID scopes from the request
                [$result, ] = TokenScope::scope_str_split_openid($tokscope);
                if ($granted !== "none" || $result === "") {
                    $result .= $result === "" ? $granted : " " . $granted;
                }
                $this->token->change_data("scope", $result);
            }
            $this->token->set_invalid_in(10 * 60)
                ->update();
        }

        throw new Redirection($this->extend_redirect_uri([
            "code" => $this->token->salt,
            "state" => $this->token->data("state"),
            "iss" => $this->conf->oauth_issuer()
        ]), 303 /* RFC 9700 §4.12: MUST NOT use 307 */);
    }

    /** @param string $error
     * @param ?string $error_description
     * @return never */
    private function redirect_error($error, $error_description = null) {
        $p = ["error" => $error];
        if ($error_description !== null) {
            $p["error_description"] = $error_description;
        }
        // The error answers the request that created the code, so it carries
        // that request's `state` (RFC 6749 §4.1.2.1). The confirmation form
        // posts no `state` of its own.
        $state = $this->token ? $this->token->data("state") : $this->qreq->state;
        if ($state !== null) {
            $p["state"] = $state;
        }
        $p["iss"] = $this->conf->oauth_issuer();
        $uri = $this->extend_redirect_uri($p);

        // A client that registered itself chose its own `redirect_uri`, so an
        // automatic bounce there makes this endpoint an open redirector for
        // anyone willing to register (RFC 9700 §4.11.2). Redirect only when
        // this site vouched for the destination, or when the user did by
        // posting the consent form; otherwise offer the destination as a link,
        // which shows where it goes and takes a click. Every error reachable
        // before that post is a malformed request, so no working client loses
        // an automatic error response.
        if ($this->client->self_registered && !$this->authconfirmed) {
            $t = $error_description ?? "Authorization request failed";
            $host = parse_url($this->qreq->redirect_uri, PHP_URL_HOST)
                ? : $this->qreq->redirect_uri;
            $link = Ht::link("Return to " . htmlspecialchars($host), $uri,
                ["rel" => "noopener noreferrer"]);
            $this->print_error_exit("<0>{$t} ({$error})", "<5>{$link}");
        }
        throw new Redirection($uri, 303 /* RFC 9700 §4.12: MUST NOT use 307 */);
    }

    /** @param string $m
     * @param ?string $inform
     * @return never */
    private function print_error_exit($m, $inform = null) {
        if (Navigation::http_response_code() === 200) {
            Navigation::http_response_code(400);
        }
        $this->qreq->print_header("Sign in", "authorize", ["action_bar" => "", "hide_header" => true, "body_class" => "body-error"]);
        if ($inform === null) {
            $this->conf->error_msg($m);
        } else {
            $this->conf->feedback_msg(MessageItem::error($m), MessageItem::inform($inform));
        }
        $this->qreq->print_footer();
        Navigation::complete();
    }

    function go() {
        $this->conf->emit_credential_page_headers();

        // handle internal action
        if (friendly_boolean($this->qreq->authconfirm)) {
            // need CSRF protection
            if ($this->qreq->valid_post()) {
                $this->handle_authconfirm();  // does not return
            }
            $this->conf->warning_msg($this->conf->_i($this->qreq->post_retry ? "session_failed_error" : "badpost"));
        }
        if (isset($this->qreq->code)) {
            if ($this->lookup_code()) {
                $this->print_form();
                return;
            } else if (!isset($this->qreq->client_id)) {
                // A code that is spent, expired, or unknown, and no client to
                // start over with. Usually this is a reload of a consent page
                // whose authorization already finished.
                $this->print_error_exit("<0>This authorization request has expired. Return to the application and sign in again.");
            }
        }

        // look up client
        if (empty($this->clients)) {
            $this->print_error_exit("<0>This site does not support authorization clients");
        } else if (!isset($this->qreq->client_id)) {
            $this->print_error_exit("<0>Authorization client missing");
        }
        $this->client = $this->find_client($this->qreq->client_id);
        if (!$this->client) {
            Navigation::http_response_code(404);
            $this->print_error_exit("<0>Authorization client not found");
        }

        // a client identified by a metadata document URL describes itself
        // in a JSON document served by that URL
        if ($this->client->client_document) {
            // That document is fetched from a URL the requester chose, so
            // require a user first: otherwise anyone can make this site issue
            // arbitrary outbound requests, holding a worker for each.
            if (!$this->authorized_emails()) {
                throw new Redirection($this->signin_url());
            }
            if (!$this->client->load_document()) {
                $this->print_error_exit("<0>{$this->client->client_document->error}");
            }
        }

        // `redirect_uri` must be present and match a configured value
        if (!isset($this->qreq->redirect_uri)) {
            $this->print_error_exit("<0>Authorization parameter `redirect_uri` missing");
        } else if (!$this->client->has_redirect_uri($this->qreq->redirect_uri)
                   || !OAuthClient::check_redirect_uri($this->qreq->redirect_uri)) {
            $this->print_error_exit("<0>Invalid authorization parameter `redirect_uri`");
        } else if (strlen($this->qreq->state ?? "") > self::MAXOPAQUE
                   || strlen($this->qreq->nonce ?? "") > self::MAXOPAQUE) {
            // reported here rather than at `redirect_uri`: the error response
            // would have to echo the oversized `state`
            $this->print_error_exit("<0>Authorization parameter `state` or `nonce` too long");
        }

        // From here on, all errors should be sent to `redirect_uri`
        // (if it is trusted).

        if ($this->conf->external_login()
            || ((($lt = $this->conf->login_type()) === "none" || $lt === "oauth")
                && !OAuthProvider::list($this->conf))) {
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
        } else if (is_int($s)) {
            return $s;
        } else if (is_string($s) && ($v = SettingParser::parse_duration($s)) !== null) {
            return (int) round($v);
        }
        return $default;
    }


    static function oauthtoken_api(Contact $user, Qrequest $qreq) {
        $jr = (new Authorize_Page($user, $qreq))->handle_oauthtoken();
        //file_put_contents("/tmp/oauth.txt", json_encode($jr->content, JSON_PRETTY_PRINT  | JSON_UNESCAPED_SLASHES) . "\n\n====\n", FILE_APPEND);
        return $jr;
    }

    /** @param string $type
     * @param ?string $description
     * @return JsonResult */
    private function oauthtoken_error($type, $description = null) {
        $j = ["error" => $type];
        if ($description !== null) {
            // RFC 6749 §5.2 restricts this to %x20-21 / %x23-5B / %x5D-7E
            $j["error_description"] = $description;
        }
        return JsonResult::make_minimal(400, $j);
    }

    /** Report a failed grant; private clients also get an error_description
     * (public clients do not, as it would be an oracle).
     * @param string $description
     * @param string $type
     * @return JsonResult */
    private function grant_error($description, $type = "invalid_grant") {
        if ($this->client->client_secret === null) {
            return $this->oauthtoken_error("invalid_grant", "Grant not valid");
        }
        return $this->oauthtoken_error($type, $description);
    }

    /** RFC 6749 §5.2: a client that authenticated via the `Authorization`
     * header gets 401 and a challenge; anything else gets 400.
     * @param bool $via_header
     * @return JsonResult */
    private function invalid_client_error($via_header) {
        return JsonResult::make_minimal($via_header ? 401 : 400, ["error" => "invalid_client"])
            ->set_header($this->conf->www_authenticate_header("invalid_client", $this->qreq));
    }

    /** Credentials and grant material belong in the request body; in the URI
     * they reach logs, `Referer`, and history (RFC 6749 §2.3.1, §3.2). */
    const SECRET_PARAMS = ["client_id", "client_secret", "code", "code_verifier", "refresh_token", "token"];

    /** Authenticate the client of a token-endpoint-style request.
     *
     * Returns the client, or a `JsonResult` naming the reason it could not be
     * authenticated. RFC 7009 §2.1 requires revocation to authenticate exactly
     * as the token endpoint does, so both go through here.
     * @return OAuthClient|JsonResult */
    private function authenticate_client() {
        $clids = $clsecrets = [];
        $clbasic = false;
        if (($auth = $this->qreq->header("Authorization"))) {
            if (preg_match('/\A\s*Basic\s+(\S+)\s*\z/i', $auth, $m)
                && ($d = base64_decode($m[1], true)) !== false
                && ($p = strpos($d, ":")) !== false) {
                $clbasic = true;
                // RFC 6749 §2.3.1 form-urlencodes each half before base64.
                // That encoding is what lets a `client_id` contain the `:` this
                // splits on. A client that skipped it is still accepted.
                $rawid = substr($d, 0, $p);
                $rawsecret = substr($d, $p + 1);
                $clids[] = urldecode($rawid);
                $clsecrets[] = urldecode($rawsecret);
                if ($rawid !== $clids[0] || $rawsecret !== $clsecrets[0]) {
                    $clids[] = $rawid;
                    $clsecrets[] = $rawsecret;
                }
            } else {
                return $this->oauthtoken_error("invalid_request", "Unparseable Authorization header");
            }
        }
        if ($clbasic) {
            // A client that authenticated in the header usually repeats its
            // `client_id` in the body anyway; RFC 6749 §4.1.3 asks for it when
            // the client does not authenticate, and clients send it either
            // way. The repetition is redundant, not a second authentication
            // method, so it is accepted — but it must name the same client, or
            // the request would authenticate as one and act as another.
            if (isset($this->qreq->client_id)
                && !in_array($this->qreq->client_id, $clids, true)) {
                return $this->oauthtoken_error("invalid_request", "client_id disagrees with Authorization header");
            }
            // RFC 6749 §2.3 allows one authentication method per request. A
            // repeated secret is redundant rather than ambiguous, so only a
            // second secret that differs from the first is a conflict.
            if (($clsecret = $this->qreq->client_secret) !== null) {
                $found = false;
                foreach ($clsecrets as $s) {
                    $found = $found || hash_equals($s, $clsecret);
                }
                if (!$found) {
                    return $this->oauthtoken_error("invalid_request", "client_secret disagrees with Authorization header");
                }
            }
        } else if (isset($this->qreq->client_id)) {
            $clids[] = $this->qreq->client_id;
            $clsecrets[] = $this->qreq->client_secret ?? "";
        } else {
            return $this->invalid_client_error(false);
        }

        $client = null;
        foreach ($clids as $i => $clid) {
            if (($c = $this->find_client($clid))
                && ($c->client_secret === null
                    // a public client has no secret; accept none from it
                    ? $clsecrets[$i] === ""
                    : hash_equals($c->client_secret, $clsecrets[$i]))) {
                $client = $c;
                break;
            }
        }
        if (!$client) {
            return $this->invalid_client_error($clbasic);
        }
        return $client;
    }

    /** @return ?JsonResult */
    private function check_secret_params() {
        foreach (self::SECRET_PARAMS as $k) {
            if ($this->qreq->from_query($k)) {
                return $this->oauthtoken_error("invalid_request", "Parameter {$k} invalid in URL");
            }
        }
        return null;
    }

    /** @return JsonResult */
    private function handle_oauthtoken() {
        // RFC 6749 §5.1 and §5.2: every response from here, error included
        Navigation::header("Cache-Control: no-store");
        if (($jr = $this->check_secret_params())) {
            return $jr;
        }
        $client = $this->authenticate_client();
        if ($client instanceof JsonResult) {
            return $client;
        }

        $scope = trim($this->qreq->scope ?? "");
        if (!self::check_scope_syntax($scope)) {
            return $this->oauthtoken_error("invalid_scope", "Unparseable scope");
        }

        // handle grant request
        $this->client = $client;
        if ($this->qreq->grant_type === "authorization_code") {
            $jr = $this->handle_oauthtoken_code();
        } else if ($this->qreq->grant_type === "refresh_token") {
            $jr = $this->handle_oauthtoken_refresh($scope);
        } else {
            return $this->oauthtoken_error("unsupported_grant_type",
                "This endpoint supports grant_type authorization_code and refresh_token");
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
            || $tok->data("client_id") !== $this->client->client_id
            || !$this->apply_token_document($tok, $this->client)) {
            return $this->grant_error("Unknown, expired, or reassigned code");
        }

        // check arguments
        $redirect_uri = $tok->data("redirect_uri");
        $qreq_redirect_uri = $this->qreq->redirect_uri ?? "";
        $code_challenge = $tok->data("code_challenge") ?? "";
        if ($code_challenge === ""
            && ($this->client->public_client($redirect_uri)
                || ($qreq_redirect_uri === "" && !$tok->data("nonce")))) {
            return $this->grant_error("Code was authorized without PKCE");
        }
        $code_verifier = $this->qreq->code_verifier ?? "";
        if (($code_verifier !== "") !== ($code_challenge !== "")) {
            return $this->grant_error($code_verifier === ""
                ? "Code was authorized with a code_challenge, so code_verifier is required"
                : "Code was authorized without a code_challenge, so code_verifier does not apply",
                "invalid_request");
        }
        if ($code_verifier !== "") {
            if ($tok->data("code_challenge_method") === "plain") {
                $code_check = $code_verifier;
            } else {
                $code_check = base64url_encode(hash("sha256", $code_verifier, true));
            }
            if ($code_challenge !== $code_check) {
                return $this->grant_error("code_verifier does not match code_challenge");
            }
        }
        if ($qreq_redirect_uri !== ""
            && $qreq_redirect_uri !== $redirect_uri) {
            return $this->grant_error("redirect_uri does not match the authorization request");
        }

        // Claim the code. A code is good once; a second redemption means it
        // reached someone else, so the tokens it produced have to go
        // (RFC 9700 §4.2.4). The code stays active for as long as it is
        // retained, so a late replay is still recognized as a replay rather
        // than dismissed as an expired code — silently rejecting it would
        // leave the leaked tokens live.
        $retain = $this->client->only_openid ? 600 : 86400;
        if (!$tok->consume()) {
            $this->oauthtoken_revoke($tok, TokenInfo::BEARER);
            $this->oauthtoken_revoke($tok, TokenInfo::OAUTHREFRESH);
            // The code names only the first pair it produced. Whoever redeemed
            // it first may have rotated since, so follow the chain: revoking
            // just the first link leaves the live tokens working, which is the
            // outcome this revocation exists to stop.
            if (($rsalt = $tok->data("refresh_token"))
                && ($rtok = $this->find_token($rsalt))) {
                $this->oauthtoken_revoke_all($rtok);
            }
            return $this->grant_error("Code was already redeemed; the tokens it issued have been revoked");
        }
        $tok->set_invalid_in($retain)
            ->set_expires_in($retain);

        // check user
        $luser = $this->conf->user_by_email($tok->data("email"));
        $guser = $this->conf->cdb_user_by_email($tok->data("email"));
        if ($this->client->is_cdb) {
            $user = $guser;
        } else {
            $user = $luser ?? ($guser ? $guser->ensure_account_here() : null);
        }
        if (!$user
            || $user->is_disabled()) {
            $tok->update();
            return $this->grant_error("The authorized account is unavailable here");
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

    /** @return string */
    private function make_id_token(Contact $user, TokenInfo $tok) {
        $payload = [
            "iss" => $this->conf->oauth_issuer(),
            "sub" => $user->email,
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

        if ($this->client->client_secret === null) {
            // A public client shares no secret, so there is no key with which
            // to sign. The ID token travels directly from this endpoint to the
            // client over TLS, so it may be unsecured (OpenID Connect Core
            // §3.1.3.7 item 6 and the `id_token_signed_response_alg` value
            // `none`, which applies to the authorization code flow).
            return JWTParser::make_plaintext((object) $payload);
        }
        return JWTParser::make_mac((object) $payload, $this->client->client_secret);
    }

    private function export_access_token(&$a, TokenInfo $atok, TokenInfo $rtok) {
        $a["access_token"] = $atok->salt;
        $a["token_type"] = "Bearer";
        // `timeExpires` is when the row is dropped, `timeInvalid` when the
        // token stops working; a client that refreshes on the former holds a
        // dead token for the retention period
        if (($inactive = $atok->inactive_at()) > 0) {
            $a["expires_in"] = $inactive - Conf::$now;
        }
        $a["refresh_token"] = $rtok->salt;
        $scope = $atok->data("scope");
        $a["scope"] = Ht::add_tokens($a["scope"] ?? null, $scope ?? "all");
    }

    /** @param TokenInfo $tok
     * @param Contact $user
     * @param ?string $scope
     * @return TokenInfo */
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
        if ($this->client->is_cdb && $ts) {
            // a selector cannot travel between conferences; `handle_request`
            // rejects one, and this is the invariant it exists to hold
            $ts = $ts->without_selectors();
        }

        $exp = self::parse_expires_in($this->client->access_token_expires_in ?? null, 3600);
        $atok = Authorization_Token::prepare_bearer($user, $exp, $tok);
        $atok->change_data("client_host", $tok->data("client_host")
                ?? $this->client->identity_host($tok))
            ->change_data("client_id", $tok->data("client_id"))
            ->change_data("client_name", $tok->data("client_name"))
            ->change_data("scope", TokenScope::unparse($ts));
        if (isset($this->client->allow_if)) {
            $atok->change_data("allow_if", $this->client->allow_if);
        }
        $user->mark_has_app();
        // XXX no way to specify a note
        return $atok->insert();
    }

    /** @param TokenInfo $tok
     * @param Contact $user
     * @param TokenInfo $atok
     * @return TokenInfo */
    private function oauthtoken_create_refresh($tok, $user, $atok) {
        $exp = self::parse_expires_in($this->client->refresh_token_expires_in ?? null, 7 * 86400);
        $rtok = Authorization_Token::prepare_refresh($user, $exp, $tok);
        $rtok->change_data("client_host", $tok->data("client_host")
                ?? $this->client->identity_host($tok))
            ->change_data("client_id", $tok->data("client_id"))
            ->change_data("client_name", $tok->data("client_name"))
            ->change_data("scope", $tok->data("scope"))
            ->change_data("access_token", $atok->salt);
        if (($docj = $tok->data("client_document")) !== null) {
            $rtok->change_data("client_document", $docj);
        }
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
            || $rtok->data("client_id") !== $this->client->client_id
            || !$this->apply_token_document($rtok, $this->client)) {
            return $this->grant_error("Unknown or reassigned refresh_token");
        } else if (!$rtok->is_active()
                   || !$rtok->consume()) {
            // replay attack: revoke all refresh tokens and access tokens;
            // `consume` catches the case of two redemptions in flight at once
            $this->oauthtoken_revoke_all($rtok);
            return $this->grant_error("refresh_token was already used; the grant has been revoked");
        }
        $this->oauthtoken_revoke($rtok, TokenInfo::BEARER);
        // check user
        $user = $rtok->is_cdb
            ? $this->conf->cdb_user_by_id($rtok->contactId)
            : $this->conf->user_by_id($rtok->contactId);
        if (!$user
            || $user->is_disabled()
            // `allow_if` limits who may hold this client's tokens; rotation
            // would otherwise renew a grant forever past the role that
            // justified it. A cdb client cannot have one (`OAuthClient::list`).
            || !(new XtParams($this->conf, $user))->checkf($this->client)
            // and a client narrowed to OpenID Connect since has no API grant
            // left to refresh
            || $this->client->only_openid) {
            return $this->grant_error("The account is no longer authorized to hold this grant");
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
        // Walk to the live token. The loop clears each link before following
        // it, so it cannot visit a token twice and needs no iteration bound;
        // a bound would silently leave the live token active on a long chain,
        // which is the compromise this revocation exists to stop.
        while ($rtok
               && $rtok->capabilityType === TokenInfo::OAUTHREFRESH
               && ($next = $rtok->data("next_refresh_token"))) {
            $rtok->change_data("next_refresh_token", null)->update();
            $rtok = $this->find_token($next);
        }
        if ($rtok) {
            $this->oauthtoken_revoke($rtok, TokenInfo::BEARER);
            $rtok->set_invalid()->update();
        }
    }


    static function oauthrevoke_api(Contact $user, Qrequest $qreq) {
        if (!$user->conf->opt("oAuthClients")) {
            return JsonResult::make_error(404, "<0>Function not found");
        }
        return (new Authorize_Page($user, $qreq))->handle_oauthrevoke();
    }

    /** Revoke a token at its client's request (RFC 7009).
     * @return JsonResult */
    private function handle_oauthrevoke() {
        Navigation::header("Cache-Control: no-store");
        if (($jr = $this->check_secret_params())) {
            return $jr;
        }
        $client = $this->authenticate_client();
        if ($client instanceof JsonResult) {
            return $client;
        }
        $this->client = $client;

        $salt = $this->qreq->token;
        if (($salt ?? "") === "") {
            return $this->oauthtoken_error("invalid_request", "Parameter token missing");
        }
        $hint = $this->qreq->token_type_hint ?? null;
        if ($hint !== null
            && $hint !== "access_token"
            && $hint !== "refresh_token") {
            // the hint is advisory; a nonsensical one is still a bad request
            return $this->oauthtoken_error("unsupported_token_type",
                "token_type_hint must be access_token or refresh_token");
        }

        // RFC 7009 §2.2: a token this client cannot revoke is a success —
        // unknown, malformed, the wrong type, or issued to another client.
        // Anything else would make this endpoint an oracle for token
        // existence.
        if (strlen($salt) < 20
            || $salt[0] !== "h"
            || $salt[1] !== "c"
            || ($salt[2] !== "t" && $salt[2] !== "T")
            || ($salt[3] !== "_" && ($salt[3] !== "r" || $salt[4] !== "_"))
            || !($tok = $this->find_token($salt))
            || ($tok->capabilityType !== TokenInfo::BEARER
                && $tok->capabilityType !== TokenInfo::OAUTHREFRESH)
            // §2.1: the token must have been issued to the client asking
            || $tok->data("client_id") !== $this->client->client_id) {
            return JsonResult::make_minimal(200, []);
        }

        // Keep the rows: a revoked refresh token presented later is the replay
        // signal, and the rotation chain is walked through them.
        if ($tok->capabilityType === TokenInfo::OAUTHREFRESH) {
            // §2.1: revoking a refresh token SHOULD revoke what it minted
            $this->oauthtoken_revoke($tok, TokenInfo::BEARER);
            $this->oauthtoken_revoke_all($tok);
        }
        if ($tok->is_active()) {
            $tok->set_invalid()
                ->set_expires_in(Authorization_Token::BEARER_RETENTION)
                ->update();
        }
        return JsonResult::make_minimal(200, []);
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
                || !OAuthClient::check_redirect_uri($uri, OAuthClient::VALIDATION_DYNAMIC)) {
                return $this->oauthregister_error("invalid_redirect_uri");
            }
        }
        //file_put_contents("/tmp/oauth.txt", json_encode($reqj, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n---\n\n", FILE_APPEND);

        // find dynamic client with matching redirect uri
        $client = null;
        foreach ($this->clients as $cx) {
            if (!($cx->dynamic ?? false)) {
                continue;
            }
            $cxc = new OAuthClient($cx);
            if ((!isset($cx->redirect_uris) && !isset($cx->redirect_uri))
                || !array_diff($redirect_uris, $cxc->redirect_uris)) {
                $client = $cxc;
                break;
            }
        }
        if (!$client) {
            return JsonResult::make_error(404, "<0>Function not found");
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
            $ctok->change_data("client_name", UnicodeHelper::utf8_truncate(
                simplify_whitespace($reqj->client_name), OAuthClientDocument::MAXNAME));
        }
        if (isset($reqj->scope)) {
            if (!is_string($reqj->scope)
                || !self::check_scope_syntax($reqj->scope)) {
                return $this->oauthregister_error("invalid_client_metadata");
            }
            $ctok->change_data("requested_scope", $reqj->scope);
        }
        $ctok->set_token_pattern($ctok->is_cdb ? "hcTk_[20]" : "hctk_[20]")
            ->insert();
        Navigation::header("Cache-Control: no-store");
        return JsonResult::make_minimal(201, [
            "client_id" => $ctok->salt,
            "client_secret" => $csecret,
            "client_id_issued_at" => $ctok->timeCreated,
            "client_secret_expires_at" => $ctok->timeInvalid,
            "redirect_uris" => $redirect_uris,
            // report what this client can use, not what it asked for: an
            // OpenID-Connect-only client is never issued a refresh token
            // (RFC 7591 §3.2.1 allows the registered metadata to differ)
            "grant_types" => $client->only_openid
                ? ["authorization_code"]
                : ["authorization_code", "refresh_token"],
            "token_endpoint_auth_method" => "client_secret_basic"
        ]);
    }
}
