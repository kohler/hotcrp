<?php
// t_authorize.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Authorize_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_mgbaker;
    /** @var Contact
     * @readonly */
    public $u_empty;
    /** @var ?string */
    private $_failure;
    /** @var ?string */
    private $_last_client_id;
    /** @var ?string */
    private $_last_client_secret;
    /** @var ?string */
    private $_last_refresh_token;
    /** @var array<string,array{int,?string,string}> */
    private $_documents = [];
    /** @var ?string */
    private $_last_error;

    const MDOC_CLIENT_ID = "https://mdoc.example.com/client.json";
    const MDOC_REDIRECT_URI = "http://127.0.0.1:5173/callback";
    const OIDC_CLIENT_ID = "https://oidc.example.com/client.json";

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_mgbaker = $conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $this->u_empty = Contact::make_email_cflags($conf, "", 0);
        $this->conf->set_opt("oAuthClients", [(object) [
            "name" => "dro", "dynamic" => true, "scope" => "read",
            "redirect_uris" => ["https://dro.com/"]
        ], (object) [
            "name" => "dall", "dynamic" => true, "scope" => "all",
            "redirect_uris" => ["https://dall.com/"]
        ], (object) [
            "name" => "dchair", "dynamic" => true, "scope" => "all", "allow_if" => "chair",
            "redirect_uris" => ["https://dchair.com/"]
        ], (object) [
            "name" => "dloop", "dynamic" => true, "scope" => "read",
            "redirect_uris" => ["http://127.0.0.1:5000/cb", "https://dloop.com/"]
        ], (object) [
            "name" => "mdoc", "metadata_document" => true, "scope" => "all",
            "client_id_match" => "https://mdoc.example.com/*"
        ], (object) [
            "name" => "mdoc_openid", "metadata_document" => true,
            "client_id_match" => "https://oidc.example.com/*"
        ]]);
        $this->conf->set_opt("oAuthDynamicClients", true);
        $this->conf->set_opt("oAuthMetadataDocumentClients", true);
        $this->conf->refresh_settings();

        $this->set_document(self::MDOC_CLIENT_ID, [
            "client_id" => self::MDOC_CLIENT_ID,
            "client_name" => "Metadata Document Test Client",
            "client_uri" => "https://mdoc.example.com/",
            "redirect_uris" => [self::MDOC_REDIRECT_URI, "https://mdoc.example.com/cb2"],
            "grant_types" => ["authorization_code", "refresh_token"],
            "response_types" => ["code"],
            "token_endpoint_auth_method" => "none"
        ]);
        $this->set_document(self::OIDC_CLIENT_ID, [
            "client_id" => self::OIDC_CLIENT_ID,
            "client_name" => "Sign-in Only Test Client",
            "redirect_uris" => [self::MDOC_REDIRECT_URI]
        ]);
        $t = $this;
        HotCRP\OAuthClientDocument::$fetch_function = function ($url) use ($t) {
            return $t->document_response($url);
        };
    }

    function finalize() {
        HotCRP\OAuthClientDocument::$fetch_function = null;
        $this->conf->set_opt("oAuthClients", null);
        $this->conf->set_opt("oAuthDynamicClients", null);
        $this->conf->set_opt("oAuthMetadataDocumentClients", null);
        $this->conf->refresh_settings();
    }

    /** @param string $url
     * @param null|string|array<string,mixed> $doc */
    private function set_document($url, $doc, $status = 200, $content_type = "application/json") {
        if ($doc === null) {
            unset($this->_documents[$url]);
        } else {
            $this->_documents[$url] = [$status, $content_type,
                is_string($doc) ? $doc : json_encode($doc)];
        }
    }

    /** @param string $url
     * @return ?array{int,?string,string} */
    function document_response($url) {
        return $this->_documents[$url] ?? null;
    }

    /** @param string $salt
     * @return ?TokenInfo */
    private function find_token($salt) {
        return TokenInfo::find_from($salt, $this->conf, $salt[2] === "T");
    }

    /** @param string $redirect_uri
     * @param Contact $user
     * @return ?object */
    function dynamic_client_result($redirect_uri, $user, $rest = []) {
        $this->_failure = null;
        $this->_last_client_id = null;
        $this->_last_client_secret = null;
        $this->_last_refresh_token = null;

        // Step 1: Register a dynamic client
        $qreq = TestQreq::post_json(["redirect_uris" => [$redirect_uri]]);
        $jr = call_api("=oauthregister", $this->u_empty, $qreq);
        if (!isset($jr->client_id) || !isset($jr->client_secret)) {
            $this->_failure = "Step 1 failed: " . json_encode($jr);
            return null;
        }
        $this->_last_client_id = $jr->client_id;
        $this->_last_client_secret = $jr->client_secret;

        // Step 2: Begin authorization request (Authorize_Page::go without ComponentSet)
        $state = base48_encode(random_bytes(16));
        $qreq = TestQreq::get([
            "client_id" => $this->_last_client_id,
            "redirect_uri" => $redirect_uri,
            "response_type" => "code",
            "state" => $state,
            "scope" => $rest["scope"] ?? "openid"
        ])->set_page("authorize")->set_user($user);
        Qrequest::set_main_request($qreq);

        $code = null;
        try {
            $ap = new HotCRP\Authorize_Page($user, $qreq);
            $ap->go();
        } catch (JsonCompletion $jc) {
            $code = $jc->result->content["code"] ?? null;
        } catch (Redirection $redir) {
            $this->_failure = "Step 2 failed with redirect: " . $redir->url;
            return null;
        }
        if ($code === null) {
            $this->_failure = "Step 2 failed: no code returned";
            return null;
        }

        // Step 3: Confirm authorization request (Authorize_Page::go with authconfirm=1)
        $qreq = TestQreq::post([
            "code" => $code,
            "authconfirm" => "1"
        ])->set_page("authorize")->set_user($user);
        Qrequest::set_main_request($qreq);

        try {
            $ap = new HotCRP\Authorize_Page($user, $qreq);
            $ap->go();
            // Should have redirected
            $this->_failure = "Step 3 failed: no redirect";
            return null;
        } catch (JsonCompletion $jc) {
            $this->_failure = "Step 3 failed: returned " . json_encode($jc->result->content);
            return null;
        } catch (Redirection $redir) {
            // Expected: redirect to redirect_uri with code and state
            $url = $redir->url;
            if (!str_starts_with($url, $redirect_uri)) {
                $this->_failure = "Step 3 failed: redirect to wrong URI: " . $url;
                return null;
            }
            // Parse query parameters from redirect URL
            $query = parse_url($url, PHP_URL_QUERY);
            parse_str($query ?? "", $params);
            xassert_eqq($params["iss"] ?? null, $this->conf->oauth_issuer());
            if (($params["state"] ?? "") !== $state) {
                $this->_failure = "Step 3 failed: state mismatch";
                return null;
            }
            $code = $params["code"] ?? null;
            if ($code === null) {
                $this->_failure = "Step 3 failed: no code in redirect";
                return null;
            }
        }

        // Step 4: Exchange code for token via api/oauthtoken
        $qreq = TestQreq::post([
            "grant_type" => "authorization_code",
            "code" => $code,
            "redirect_uri" => $redirect_uri,
            "client_id" => $this->_last_client_id,
            "client_secret" => $this->_last_client_secret
        ]);
        $jr = call_api("=oauthtoken", $this->u_empty, $qreq);
        if (!isset($jr->access_token)) {
            $this->_failure = "Step 4 failed: " . json_encode($jr);
            return null;
        }
        $jr->_token = $this->find_token($jr->access_token);
        $this->_last_refresh_token = $jr->refresh_token ?? null;
        return $jr;
    }

    /** @return TokenInfo */
    function dynamic_client_token($redirect_uri, $user, $rest = []) {
        $jr = $this->dynamic_client_result($redirect_uri, $user, $rest);
        return $jr->_token;
    }

    /** @return ?TokenInfo */
    function refresh_access_token($rest = []) {
        assert($this->_last_refresh_token !== null);
        $qreq = TestQreq::post([
            "grant_type" => "refresh_token",
            "refresh_token" => $this->_last_refresh_token,
            "client_id" => $this->_last_client_id,
            "client_secret" => $this->_last_client_secret,
            "scope" => $rest["scope"] ?? ""
        ]);
        $jr = call_api("=oauthtoken", $this->u_empty, $qreq);
        if (!isset($jr->access_token)) {
            $this->_failure = "Refresh failed: " . json_encode($jr);
            return null;
        }
        $token = $this->find_token($jr->access_token);
        $this->_last_refresh_token = $jr->refresh_token ?? $this->_last_refresh_token;
        return $token;
    }

    function test_dynamic_client_authorization() {
        $jr = $this->dynamic_client_result("https://dro.com/", $this->u_chair);
        xassert_neqq($jr, null);
        xassert_neqq($jr->access_token, null);
        xassert_neqq($jr->refresh_token, null);
        xassert_eqq($jr->token_type, "Bearer");
        // Bearer tokens start with hct_ (local) or hcT_ (cdb)
        xassert(str_starts_with($jr->access_token, "hct_") || str_starts_with($jr->access_token, "hcT_"));
        // this request asked for `openid` only, so the token gets no API
        // access, even though the client is configured for `read`
        xassert_eqq($jr->_token->data("scope"), "none");

        $jr = call_api_result("whoami", $jr->_token, []);
        xassert_eqq($jr->response_code(), 200);
        xassert_eqq($jr->get("email"), "chair@_.com");
    }

    function test_refresh_token() {
        // Get initial tokens
        $tok1 = $this->dynamic_client_token("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        xassert_neqq($this->_last_refresh_token, null);
        $refresh1 = $this->_last_refresh_token;

        // Use refresh token to get new access token
        $tok2 = $this->refresh_access_token();
        xassert_neqq($tok2->salt, $tok1->salt);
        xassert_neqq($this->_last_refresh_token, null);
        xassert_neqq($this->_last_refresh_token, $refresh1);
        $refresh2 = $this->_last_refresh_token;

        // New access token should work
        $jr = call_api_result("whoami", $tok2, []);
        xassert_eqq($jr->response_code(), 200);
        xassert_eqq($jr->get("email"), "chair@_.com");

        // Scope should be preserved
        xassert_eqq($tok2->data("scope"), "read");

        // Old refresh token should no longer work
        $qreq = TestQreq::post([
            "grant_type" => "refresh_token",
            "refresh_token" => $refresh1,
            "client_id" => $this->_last_client_id,
            "client_secret" => $this->_last_client_secret
        ]);
        $jr = call_api("=oauthtoken", $this->u_empty, $qreq, null);
        xassert_eqq($jr->error ?? null, "invalid_grant");
    }

    function test_dynamic_client_scope() {
        // token from read/write scope has all rights
        $token = $this->dynamic_client_token("https://dall.com/", $this->u_chair, ["scope" => "all"]);
        $jr = call_api_result("whoami", $token, []);
        xassert_eqq($jr->response_code(), 200);
        xassert_eqq($jr->get("email"), "chair@_.com");

        $jr = call_api_result("=account", $token, ["enable" => 1, "email" => "mgbaker@cs.stanford.edu"]);
        xassert_eqq($jr->response_code(), 200);
        xassert_eqq($jr->get("disabled"), false);

        // token from configured read-only scope has limited rights,
        // even if they request more
        $token = $this->dynamic_client_token("https://dro.com/", $this->u_chair, ["scope" => "all"]);
        $jr = call_api_result("whoami", $token, []);
        xassert_eqq($jr->response_code(), 200);
        xassert_eqq($jr->get("email"), "chair@_.com");

        $jr = call_api_result("=account", $token, ["enable" => 1, "email" => "mgbaker@cs.stanford.edu"]);
        xassert_eqq($jr->response_code(), 401);

        // token that requested limited rights from read/write scope
        // has only those rights
        $token = $this->dynamic_client_token("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        $jr = call_api_result("whoami", $token, []);
        xassert_eqq($jr->response_code(), 200);
        xassert_eqq($jr->get("email"), "chair@_.com");

        $jr = call_api_result("=account", $token, ["enable" => 1, "email" => "mgbaker@cs.stanford.edu"]);
        xassert_eqq($jr->response_code(), 401);
    }

    function test_refresh_token_scope() {
        $token = $this->dynamic_client_token("https://dall.com/", $this->u_chair, ["scope" => "read write"]);
        xassert_eqq($token->data("scope"), "write");

        // Refresh with more limited scope
        $token2 = $this->refresh_access_token(["scope" => "read"]);
        xassert_eqq($token2->data("scope"), "read");

        // But the refresh token still allows the original scope
        $token3 = $this->refresh_access_token(["scope" => "all"]);
        xassert_eqq($token3->data("scope"), "write");
    }

    /** A dynamically registered client that receives its code on the loopback
     * interface runs on the user's own computer, so the secret it was issued
     * can be extracted from it and cannot protect the code. PKCE must
     * (RFC 8252 §§8.1, 8.5). */
    function test_dynamic_client_loopback_requires_pkce() {
        foreach ([["http://127.0.0.1:5000/cb", true], ["https://dloop.com/", false]] as [$ruri, $needs_pkce]) {
            $qreq = TestQreq::post_json(["redirect_uris" => [$ruri]]);
            $jr = call_api("=oauthregister", $this->u_empty, $qreq);
            xassert(isset($jr->client_id));
            if (!isset($jr->client_id)) {
                continue;
            }
            $qreq = TestQreq::get([
                "client_id" => $jr->client_id,
                "redirect_uri" => $ruri,
                "response_type" => "code",
                "state" => base48_encode(random_bytes(16)),
                "scope" => "read"
            ])->set_page("authorize")->set_user($this->u_chair);
            Qrequest::set_main_request($qreq);
            $err = $code = null;
            try {
                (new HotCRP\Authorize_Page($this->u_chair, $qreq))->go();
            } catch (JsonCompletion $jc) {
                $code = $jc->result->content["code"] ?? null;
            } catch (Redirection $redir) {
                $err = $this->redirect_error($redir->url);
            }
            if ($needs_pkce) {
                xassert_eqq($code, null);
                xassert_eqq($err, "invalid_request");
            } else {
                xassert_neqq($code, null);
                xassert_eqq($err, null);
            }
        }
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_refresh_token_replay_prevention() {
        // Get initial tokens
        $token1 = $this->dynamic_client_token("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        $refresh1 = $this->_last_refresh_token;

        // Legitimate refresh: refresh1 -> refresh2
        $token2 = $this->refresh_access_token();
        $refresh2 = $this->_last_refresh_token;
        xassert_neqq($refresh2, $refresh1);

        $jr = call_api_result("whoami", $token2, []);
        xassert_eqq($jr->response_code(), 200);

        // Attacker replays refresh1 (already used)
        $qreq = TestQreq::post([
            "grant_type" => "refresh_token",
            "refresh_token" => $refresh1,
            "client_id" => $this->_last_client_id,
            "client_secret" => $this->_last_client_secret
        ]);
        $jr = call_api("=oauthtoken", $this->u_empty, $qreq);
        xassert_eqq($jr->error ?? null, "invalid_grant");

        // Replay detection should have revoked the entire token chain.
        // refresh2 should no longer work.
        $qreq = TestQreq::post([
            "grant_type" => "refresh_token",
            "refresh_token" => $refresh2,
            "client_id" => $this->_last_client_id,
            "client_secret" => $this->_last_client_secret
        ]);
        $jr = call_api("=oauthtoken", $this->u_empty, $qreq);
        xassert_eqq($jr->error ?? null, "invalid_grant");

        // token2 (the access token) should also be revoked
        $token2 = $this->find_token($token2->salt);
        $jr = call_api_result("whoami", $token2, []);
        xassert_eqq($jr->response_code(), 401);
    }

    /** Run an authorization for a client that identifies itself with a client
     * ID metadata document, from authorization request through token exchange.
     * @param string $redirect_uri
     * @param Contact $user
     * @return ?object */
    function metadata_document_result($redirect_uri, $user, $rest = []) {
        $this->_failure = null;
        $this->_last_client_id = $rest["client_id"] ?? self::MDOC_CLIENT_ID;
        $this->_last_client_secret = null;
        $this->_last_refresh_token = null;
        $this->_last_error = null;

        $verifier = base48_encode(random_bytes(32));

        // Step 1: Begin authorization request
        $state = base48_encode(random_bytes(16));
        $args = [
            "client_id" => $this->_last_client_id,
            "redirect_uri" => $redirect_uri,
            "response_type" => "code",
            "state" => $state
        ];
        if (isset($rest["scope"])) {
            $args["scope"] = $rest["scope"];
        }
        if (isset($rest["nonce"])) {
            $args["nonce"] = $rest["nonce"];
        }
        if (!($rest["no_pkce"] ?? false)) {
            $args["code_challenge_method"] = $rest["code_challenge_method"] ?? "S256";
            $args["code_challenge"] = $args["code_challenge_method"] === "plain"
                ? $verifier : base64url_encode(hash("sha256", $verifier, true));
        }
        $qreq = TestQreq::get($args)->set_page("authorize")->set_user($user);
        Qrequest::set_main_request($qreq);

        // test_mode 2 routes page feedback messages into the page, where the
        // output buffer will absorb them
        $code = null;
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        ob_start();
        try {
            (new HotCRP\Authorize_Page($user, $qreq))->go();
        } catch (JsonCompletion $jc) {
            $code = $jc->result->content["code"] ?? null;
        } catch (Redirection $redir) {
            $this->_last_error = $this->redirect_error($redir->url);
            $this->_failure = "Step 1 failed with redirect: " . $redir->url;
        } catch (PageCompletion $pc) {
            $this->_failure = "Step 1 failed with error page";
        } finally {
            ob_end_clean();
            Navigation::$test_mode = $old_test_mode;
        }
        if ($this->_failure !== null) {
            return null;
        }
        if ($code === null) {
            $this->_failure = "Step 1 failed: no code returned";
            return null;
        }

        // Step 2: Confirm authorization request
        $qreq = TestQreq::post(["code" => $code, "authconfirm" => "1"])
            ->set_page("authorize")->set_user($user);
        Qrequest::set_main_request($qreq);

        Navigation::$test_mode = 2;
        try {
            ob_start();
            (new HotCRP\Authorize_Page($user, $qreq))->go();
            ob_end_clean();
            Navigation::$test_mode = $old_test_mode;
            $this->_failure = "Step 2 failed: no redirect";
            return null;
        } catch (JsonCompletion $jc) {
            ob_end_clean();
            Navigation::$test_mode = $old_test_mode;
            $this->_failure = "Step 2 failed: returned " . json_encode($jc->result->content);
            return null;
        } catch (PageCompletion $pc) {
            ob_end_clean();
            Navigation::$test_mode = $old_test_mode;
            $this->_failure = "Step 2 failed with error page";
            return null;
        } catch (Redirection $redir) {
            ob_end_clean();
            Navigation::$test_mode = $old_test_mode;
            if (!str_starts_with($redir->url, $redirect_uri)) {
                $this->_failure = "Step 2 failed: redirect to wrong URI: " . $redir->url;
                return null;
            }
            parse_str(parse_url($redir->url, PHP_URL_QUERY) ?? "", $params);
            xassert_eqq($params["iss"] ?? null, $this->conf->oauth_issuer());
            if (($params["state"] ?? "") !== $state) {
                $this->_failure = "Step 2 failed: state mismatch";
                return null;
            }
            $code = $params["code"] ?? null;
            if ($code === null) {
                $this->_last_error = $params["error"] ?? null;
                $this->_failure = "Step 2 failed: no code in redirect";
                return null;
            }
        }

        // Step 3: Exchange code for token; no client secret, but PKCE
        $args = [
            "grant_type" => "authorization_code",
            "code" => $code,
            "redirect_uri" => $redirect_uri,
            "client_id" => $this->_last_client_id
        ];
        if (!($rest["no_pkce"] ?? false)) {
            $args["code_verifier"] = $verifier;
        }
        if (isset($rest["client_secret"])) {
            $args["client_secret"] = $rest["client_secret"];
        }
        $jr = call_api("=oauthtoken", $this->u_empty, TestQreq::post($args));
        if (!isset($jr->access_token)) {
            $this->_last_error = $jr->error ?? null;
            $this->_failure = "Step 3 failed: " . json_encode($jr);
            return null;
        }
        $jr->_token = $this->find_token($jr->access_token);
        $this->_last_refresh_token = $jr->refresh_token ?? null;
        return $jr;
    }

    /** @param string $url
     * @return ?string */
    private function redirect_error($url) {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? "", $params);
        // an error response identifies its issuer too (RFC 9207)
        xassert_eqq($params["iss"] ?? null, $this->conf->oauth_issuer());
        return $params["error"] ?? null;
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_metadata_document_authorization() {
        $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI, $this->u_chair,
            ["scope" => "read"]);
        xassert_neqq($jr, null);
        if (!$jr) {
            return;
        }
        xassert_neqq($jr->access_token, null);
        xassert_neqq($jr->refresh_token, null);
        xassert_eqq($jr->token_type, "Bearer");
        // no `openid` scope was requested, so no id_token
        xassert_eqq($jr->id_token ?? null, null);
        xassert_eqq($jr->_token->data("scope"), "read");
        xassert_eqq($jr->_token->data("client_id"), self::MDOC_CLIENT_ID);

        $jr2 = call_api_result("whoami", $jr->_token, []);
        xassert_eqq($jr2->response_code(), 200);
        xassert_eqq($jr2->get("email"), "chair@_.com");

        // refresh works, and still requires no client secret
        $token = $this->refresh_access_token();
        xassert_neqq($token, null);
        if ($token) {
            xassert_eqq($token->data("scope"), "read");
            xassert_eqq(call_api_result("whoami", $token, [])->response_code(), 200);
        }
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_metadata_document_scope() {
        // the document’s `scope` limits what the client can be granted
        $this->set_document(self::MDOC_CLIENT_ID, [
            "client_id" => self::MDOC_CLIENT_ID,
            "client_name" => "Metadata Document Test Client",
            "redirect_uris" => [self::MDOC_REDIRECT_URI],
            "scope" => "read"
        ]);
        $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI, $this->u_chair,
            ["scope" => "all"]);
        xassert_neqq($jr, null);
        if ($jr) {
            xassert_eqq($jr->_token->data("scope"), "read");
            xassert_eqq(call_api_result("=account", $jr->_token,
                ["enable" => 1, "email" => "mgbaker@cs.stanford.edu"])->response_code(), 401);
        }

        $this->set_default_document();
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_metadata_document_openid() {
        // A public client gets an unsecured id_token: it shares no secret, so
        // there is no signing key, and the token travels straight from the
        // token endpoint over TLS.
        $nonce = base48_encode(random_bytes(12));
        $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI, $this->u_chair,
            ["scope" => "openid read", "nonce" => $nonce]);
        xassert_neqq($jr, null);
        if (!$jr) {
            return;
        }
        xassert_neqq($jr->id_token ?? null, null);
        xassert_eqq($jr->_token->data("scope"), "read");

        $jwt = new HotCRP\JWTParser;
        $payload = $jwt->validate($jr->id_token);
        xassert_neqq($payload, null);
        if (!$payload) {
            return;
        }
        xassert_eqq($jwt->jose->alg ?? null, "none");
        xassert_eqq($payload->iss ?? null, $this->conf->oauth_issuer());
        xassert_eqq($payload->aud ?? null, self::MDOC_CLIENT_ID);
        xassert_eqq($payload->email ?? null, "chair@_.com");
        xassert_eqq($payload->nonce ?? null, $nonce);
        // OpenID Connect requires `sub`, and a relying party may key an
        // account on it; a HotCRP email is permanent, so it serves
        xassert_eqq($payload->sub ?? null, "chair@_.com");
        xassert_eqq($payload->exp ?? null, ($payload->iat ?? 0) + 86400);
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_metadata_document_openid_only() {
        // a metadata-document component with no `scope` can sign users in,
        // but grants no API access
        $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI,
            $this->u_chair, ["scope" => "openid", "client_id" => self::OIDC_CLIENT_ID]);
        xassert_neqq($jr, null);
        if (!$jr) {
            return;
        }
        xassert_neqq($jr->id_token ?? null, null);
        xassert_eqq($jr->access_token, "hct_invalid_token");
        xassert_eqq($jr->refresh_token ?? null, null);

        // and it cannot be granted API scopes
        $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI,
            $this->u_chair, ["scope" => "read", "client_id" => self::OIDC_CLIENT_ID]);
        xassert_eqq($jr, null);
        xassert_eqq($this->_last_error, "invalid_scope");
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_metadata_document_requires_pkce() {
        $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI, $this->u_chair,
            ["scope" => "read", "no_pkce" => true]);
        xassert_eqq($jr, null);
        xassert_eqq($this->_last_error, "invalid_request");

        // `plain` PKCE is not enough for a public client
        $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI, $this->u_chair,
            ["scope" => "read", "code_challenge_method" => "plain"]);
        xassert_eqq($jr, null);
        xassert_eqq($this->_last_error, "invalid_request");
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_metadata_document_rejects_client_secret() {
        $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI, $this->u_chair,
            ["scope" => "read", "client_secret" => "Dudfield"]);
        xassert_eqq($jr, null);
        xassert_eqq($this->_last_error, "invalid_client");
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_metadata_document_redirect_uri() {
        // an https redirect URI from the document also works
        $jr = $this->metadata_document_result("https://mdoc.example.com/cb2", $this->u_chair,
            ["scope" => "read"]);
        xassert_neqq($jr, null);

        // a redirect URI not in the document does not
        $jr = $this->metadata_document_result("https://mdoc.example.com/nope",
            $this->u_chair, ["scope" => "read"]);
        xassert_eqq($jr, null);

        // a loopback URI registered without a port matches any port, since a
        // native client takes an ephemeral port from the OS (RFC 8252 §7.3)
        $this->set_document(self::MDOC_CLIENT_ID, [
            "client_id" => self::MDOC_CLIENT_ID,
            "client_name" => "Metadata Document Test Client",
            "redirect_uris" => ["http://127.0.0.1/callback", "http://localhost/callback"]
        ]);
        $jr = $this->metadata_document_result("http://127.0.0.1:3118/callback",
            $this->u_chair, ["scope" => "read"]);
        xassert_neqq($jr, null);
        // ...but only that path, on that loopback host
        $jr = $this->metadata_document_result("http://127.0.0.1:3118/other",
            $this->u_chair, ["scope" => "read"]);
        xassert_eqq($jr, null);
        $jr = $this->metadata_document_result("http://[::1]:3118/callback",
            $this->u_chair, ["scope" => "read"]);
        xassert_eqq($jr, null);
        $this->set_default_document();

        // neither does a client ID that does not match `client_id_match`
        $this->set_document("https://other.example.com/client.json", [
            "client_id" => "https://other.example.com/client.json",
            "redirect_uris" => [self::MDOC_REDIRECT_URI]
        ]);
        $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI, $this->u_chair,
            ["scope" => "read", "client_id" => "https://other.example.com/client.json"]);
        xassert_eqq($jr, null);
        $this->set_document("https://other.example.com/client.json", null);
    }

    private function set_default_document() {
        $this->set_document(self::MDOC_CLIENT_ID, [
            "client_id" => self::MDOC_CLIENT_ID,
            "client_name" => "Metadata Document Test Client",
            "client_uri" => "https://mdoc.example.com/",
            "redirect_uris" => [self::MDOC_REDIRECT_URI, "https://mdoc.example.com/cb2"],
            "grant_types" => ["authorization_code", "refresh_token"],
            "response_types" => ["code"],
            "token_endpoint_auth_method" => "none"
        ]);
    }

    /** @param string $client_id
     * @return ?HotCRP\OAuthClientDocument */
    private function client_document($client_id) {
        return HotCRP\OAuthClientDocument::try_make($this->conf, $client_id);
    }

    function test_authorization_server_metadata() {
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        ob_start();
        try {
            WellKnown_Page::oauth_authorization_server(Navigation::get(), $this->conf);
        } catch (PageCompletion $pc) {
        } finally {
            $text = ob_get_clean();
            Navigation::$test_mode = $old_test_mode;
        }
        $j = json_decode($text);
        xassert_neqq($j, null);
        if (!$j) {
            return;
        }
        xassert_eqq($j->client_id_metadata_document_supported ?? null, true);
        xassert(in_array("none", $j->token_endpoint_auth_methods_supported, true));
        xassert(in_array("S256", $j->code_challenge_methods_supported, true));
        // advertised only because every authorization response carries `iss`;
        // a client that sees this and then a response without `iss` rejects it
        xassert_eqq($j->authorization_response_iss_parameter_supported ?? null, true);
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_client_id_url_syntax() {
        xassert(!!$this->client_document("https://ex.example.com/c.json"));
        xassert(!!$this->client_document("https://ex.example.com/"));
        xassert(!!$this->client_document("https://ex.example.com/c.json?v=2"));
        // must be https, must have a path, no fragment, no userinfo
        xassert(!$this->client_document("http://ex.example.com/c.json"));
        xassert(!$this->client_document("https://ex.example.com"));
        xassert(!$this->client_document("https://ex.example.com/c.json#x"));
        xassert(!$this->client_document("https://u:p@ex.example.com/c.json"));
        // no relative path segments, including percent-encoded ones; cURL
        // would resolve them away, so the identifier fetched would not be the
        // identifier given
        xassert(!$this->client_document("https://ex.example.com/./c.json"));
        xassert(!$this->client_document("https://ex.example.com/a/../c.json"));
        xassert(!$this->client_document("https://ex.example.com/a/./b/../c.json"));
        xassert(!$this->client_document("https://ex.example.com/%2e/c.json"));
        xassert(!$this->client_document("https://ex.example.com/%2E%2E/c.json"));
        xassert(!$this->client_document("https://ex.example.com/a/."));
        xassert(!$this->client_document("https://ex.example.com/a/.."));
        // ...but dots that do not form a complete segment are fine
        xassert(!!$this->client_document("https://ex.example.com/...json"));
        xassert(!!$this->client_document("https://ex.example.com/..c.json"));
        xassert(!!$this->client_document("https://ex.example.com/a/..b/c"));
        // no address literals, and no names that cannot resolve publicly
        xassert(!$this->client_document("https://127.0.0.1/c.json"));
        xassert(!$this->client_document("https://[::1]/c.json"));
        xassert(!$this->client_document("https://[2001:db8::1]/c.json"));
        xassert(!$this->client_document("https://server.local/c.json"));
        xassert(!$this->client_document("https://x.internal/c.json"));
        xassert(!$this->client_document("https://x.home.arpa/c.json"));
        // a trailing root dot names the same host, so it cannot be an escape
        xassert(!$this->client_document("https://server.local./c.json"));
        xassert(!$this->client_document("https://127.0.0.1./c.json"));
        // shorthand and non-decimal address literals are still literals:
        // each of these hosts means 127.0.0.1
        xassert(!$this->client_document("https://0x7f.1/c.json"));
        xassert(!$this->client_document("https://127.1/c.json"));
        xassert(!$this->client_document("https://0177.0.0.1/c.json"));
        xassert(!$this->client_document("https://0x7f.0x1/c.json"));
        xassert(!$this->client_document("https://0x7f.0xa/c.json"));
        xassert(!$this->client_document("https://0X7F.0XA/c.json"));
        xassert(!$this->client_document("https://0xdeadbeef/c.json"));
        // ...but digits are fine in a name that is not an address
        xassert(!!$this->client_document("https://3com.example.com/c.json"));
        xassert(!!$this->client_document("https://0xff.example.com/c.json"));
        xassert(!!$this->client_document("https://decafe.com/c.json"));
        xassert(!!$this->client_document("https://a0x1.com/c.json"));
        xassert(!!$this->client_document("https://local.example.com/c.json"));
        xassert(!!$this->client_document("https://home.arpa.example.com/c.json"));
        // no whitespace, control characters, or non-ASCII bytes
        xassert(!$this->client_document("https://ex.example.com/c json"));
        xassert(!$this->client_document("https://ex.example.com/c\x01json"));
        xassert(!$this->client_document("https://ex.example.com/c\x7Fjson"));
        xassert(!$this->client_document("https://ex.example.com/caf\xC3\xA9.json"));
        xassert(!$this->client_document("https://ex.exa\x01mple.com/c.json"));
        xassert(!$this->client_document("https://ex.example.com/c{}.json"));
        // percent-encoding must be well formed, and brackets belong in the host
        xassert(!$this->client_document("https://ex.example.com/c%zz.json"));
        xassert(!$this->client_document("https://ex.example.com/c%4.json"));
        xassert(!$this->client_document("https://ex.example.com/c[1].json"));
        // unreserved characters must not be encoded, but encodings that
        // matter—reserved characters, and characters with no literal form—stay
        xassert(!$this->client_document("https://ex.example.com/c%41.json"));
        xassert(!$this->client_document("https://ex.example.com/c%7Ex.json"));
        xassert(!!$this->client_document("https://ex.example.com/c%2Fx.json"));
        xassert(!!$this->client_document("https://ex.example.com/c%20x.json"));
        xassert(!!$this->client_document("https://ex.example.com/a//b.json"));
        // hex digit case is not worth rejecting an identifier over
        xassert(!!$this->client_document("https://ex.example.com/c%2fx.json"));
        xassert(!!$this->client_document("https://ex.example.com/c%2cx.json"));
    }

    function test_redirect_uri_basic() {
        // a redirect URI configured by this site is trusted, so its characters
        // are not checked; it must still be `https` or loopback
        $ck = function ($uri) {
            return HotCRP\OAuthClient::check_redirect_uri($uri);
        };
        xassert($ck("https://ex.example.com/cb"));
        xassert($ck("https://ex.example.com/cb?x=1"));
        xassert($ck("https://ex.example.com/a b"));
        xassert(!$ck("https://ex.example.com/cb#x"));
        xassert(!$ck("javascript:alert(1)"));
        xassert(!$ck("com.example.app:/cb"));
    }

    /** Redirect URIs supplied by a client—whether by dynamic registration or
     * by a client ID metadata document—are remote input, so their characters
     * are checked. Loopback is allowed either way: the URI is delivered by the
     * user's own browser to a listener on the user's own computer, which is
     * how a program running there receives its code (RFC 8252). */
    function test_redirect_uri_dynamic() {
        $ck = function ($uri) {
            return HotCRP\OAuthClient::check_redirect_uri($uri, HotCRP\OAuthClient::VALIDATION_DYNAMIC);
        };
        xassert($ck("https://ex.example.com/cb"));
        xassert(!$ck("https://ex.example.com/cb#x"));
        // characters that do not belong in a `Location` header
        xassert(!$ck("https://ex.example.com/a b"));
        xassert(!$ck("https://ex.example.com/cb\r\nX-Evil: 1"));
        xassert(!$ck("https://ex.example.com/cb\x01"));
        xassert(!$ck("https://ex.example.com/caf\xC3\xA9"));
        // every loopback spelling, with or without a port or path
        xassert($ck("http://localhost/cb"));
        xassert($ck("http://localhost:5173/cb"));
        xassert($ck("http://127.0.0.1/cb"));
        xassert($ck("http://127.0.0.1:5173/cb"));
        xassert($ck("http://[::1]/cb"));
        xassert($ck("http://[::1]:5173/cb"));
        xassert($ck("http://localhost:5173"));
        xassert($ck("http://localhost:5173?x=1"));
        // ...but no other plaintext host, however it is spelled
        xassert(!$ck("http://ex.example.com/cb"));
        xassert(!$ck("http://localhost.example.com/cb"));
        xassert(!$ck("http://127.0.0.2:5173/cb"));
        xassert(!$ck("http://127.1:5173/cb"));
        xassert(!$ck("http://localhost:80x/cb"));
        // userinfo cannot disguise the host: none of these is loopback
        xassert(!$ck("http://localhost@evil.example.com/cb"));
        xassert(!$ck("http://localhost:pw@evil.example.com/cb"));
        xassert(!$ck("http://127.0.0.1@evil.example.com/cb"));
        xassert(!$ck("http://x@localhost@evil.example.com/cb"));
        // ...but real userinfo before a loopback host is unremarkable
        xassert($ck("http://x@localhost/cb"));
        // the path is the client's business: it is matched exactly against
        // the registered list, so it needs no normalization here
        xassert($ck("https://ex.example.com/./cb"));
        xassert($ck("https://ex.example.com/a/../cb"));
        xassert($ck("https://ex.example.com/cb%41"));
    }

    function test_redirect_uri_loopback_port() {
        // a client that listens on an ephemeral loopback port cannot register
        // that port in advance, so any port matches (RFC 8252 §7.3).
        $c = new HotCRP\OAuthClient((object) []);
        $c->redirect_uris = ["http://localhost/callback", "http://127.0.0.1/callback", "http://[::1]/callback2"];
        xassert($c->has_redirect_uri("http://127.0.0.1/callback"));
        xassert($c->has_redirect_uri("http://127.0.0.1:3118/callback"));
        xassert($c->has_redirect_uri("http://localhost:8020/callback"));
        xassert($c->has_redirect_uri("http://[::1]:8021/callback2"));
        // the path is still exact...
        xassert(!$c->has_redirect_uri("http://127.0.0.1:3118/other"));
        xassert(!$c->has_redirect_uri("http://127.0.0.1:3118/callback?x=1"));
        // ...and so is the host, since `localhost` and `127.0.0.1` differ to a
        // browser
        xassert(!$c->has_redirect_uri("http://[::1]:3118/callback"));
        xassert(!$c->has_redirect_uri("http://127.0.0.2:3118/callback"));
        xassert(!$c->has_redirect_uri("http://localhost.example.com/callback"));
        // a registered port is not a wildcard for non-loopback hosts
        $c->redirect_uris = ["https://ex.example.com/cb"];
        xassert($c->has_redirect_uri("https://ex.example.com/cb"));
        xassert(!$c->has_redirect_uri("https://ex.example.com:8443/cb"));
        // some colon checks
        $c->redirect_uris = ["http://localhost/callback:with:colon"];
        xassert($c->has_redirect_uri("http://localhost:8129/callback:with:colon"));
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_metadata_document_consent_identity() {
        // The consent screen is the only place a user learns which domains are
        // involved, and it is the one page `metadata_document_result` never
        // renders (it returns JSON when there is no ComponentSet).
        $cdoc = HotCRP\OAuthClientDocument::try_make($this->conf, self::MDOC_CLIENT_ID);
        xassert(!!$cdoc);
        $client = HotCRP\OAuthClient::make_metadata_document((object) ["name" => "mdoc"], $cdoc);
        xassert($client->load_document());

        $qreq = TestQreq::get()->set_page("authorize")->set_user($this->u_chair);
        $ap = new HotCRP\Authorize_Page($this->u_chair, $qreq);
        $ap->client = $client;
        $ro = new ReflectionObject($ap);
        $tokp = $ro->getProperty("token");
        $meth = $ro->getMethod("print_self_registered_identity");

        $render = function ($ruri) use ($ap, $tokp, $meth) {
            $tok = (new TokenInfo($this->conf, TokenInfo::OAUTHCODE))
                ->change_data("redirect_uri", $ruri);
            $tokp->setValue($ap, $tok);
            ob_start();
            try {
                $meth->invoke($ap);
            } finally {
                return ob_get_clean();
            }
        };

        // a loopback destination is described as the user's own computer
        $t = $render(self::MDOC_REDIRECT_URI);
        xassert_str_contains($t, "mdoc.example.com");
        xassert_str_contains($t, "your own computer");
        xassert_str_contains($t, htmlspecialchars(self::MDOC_REDIRECT_URI));

        // a remote destination is named by host, as MCP requires
        $t = $render("https://elsewhere.example.net/cb");
        xassert_str_contains($t, "mdoc.example.com");
        xassert_str_contains($t, "elsewhere.example.net");
        xassert(strpos($t, "your own computer") === false);
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_client_id_match_patterns() {
        // a glob must not let `*` span the `/` or `@` that ends the authority,
        // which would put the real host outside the pattern (RFC 9700 §4.1.1)
        $ck = function ($pat, $client_id) {
            return $this->client_document($client_id)->matches((object) ["client_id_match" => $pat]);
        };
        xassert($ck("https://*.example.com/*", "https://good.example.com/c.json"));
        xassert($ck("https://mdoc.example.com/*", "https://mdoc.example.com/c.json"));
        xassert($ck("https://mdoc.example.com/c.json", "https://mdoc.example.com/c.json"));
        xassert(!$ck("https://mdoc.example.com/c.json", "https://mdoc.example.com/other.json"));
        // the host must be the pattern’s host, not merely contain it
        // (do not need to check authority/userinfo, which is disallowed)
        xassert(!$ck("https://*.example.com/*", "https://evil.example.net/x.example.com/y"));
        xassert($ck("https://*.example.com/*", "https://a.b.example.com/c.json"));
        // path is required; end with `/*` if you mean it
        xassert(!$ck("https://*.example.com", "https://a.b.example.com/"));
        // a `*` that ends the pattern absorbs the query too, so `/*` means
        // “anything on this host”...
        xassert($ck("https://mdoc.example.com/*", "https://mdoc.example.com/c.json?v=2"));
        xassert($ck("https://mdoc.example.com/*", "https://mdoc.example.com/a/b?x=1"));
        xassert($ck("https://*.example.com/*", "https://good.example.com/c.json?v=2"));
        // ...but a `*` elsewhere in the path stops at the query
        xassert($ck("https://mdoc.example.com/*/c.json", "https://mdoc.example.com/a/c.json"));
        xassert(!$ck("https://mdoc.example.com/a*b", "https://mdoc.example.com/a-b?x=1"));
        xassert($ck("https://mdoc.example.com/a*b", "https://mdoc.example.com/a-b"));
        // a pattern with its own query matches that query
        xassert($ck("https://mdoc.example.com/c.json?v=*", "https://mdoc.example.com/c.json?v=2"));
        xassert(!$ck("https://mdoc.example.com/c.json?v=*", "https://mdoc.example.com/c.json?w=2"));
        xassert(!$ck("https://mdoc.example.com/c.json?v=*", "https://mdoc.example.com/c.json"));
        // `?*` alone means the query is optional, but it is still a query:
        // the path stays pinned
        xassert($ck("https://mdoc.example.com/c.json?*", "https://mdoc.example.com/c.json"));
        xassert($ck("https://mdoc.example.com/c.json?*", "https://mdoc.example.com/c.json?v=2"));
        xassert($ck("https://mdoc.example.com/c.json?*", "https://mdoc.example.com/c.json?a=1&b=2"));
        xassert(!$ck("https://mdoc.example.com/c.json?*", "https://mdoc.example.com/c.jsonevil"));
        xassert(!$ck("https://mdoc.example.com/c.json?*", "https://mdoc.example.com/c.json/more"));
    }

    /** Run `$qreq` through the authorization page and report whether it
     * redirected. Feedback messages are absorbed rather than printed.
     * @return bool */
    private function authconfirm_redirects(Qrequest $qreq) {
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        ob_start();
        try {
            (new HotCRP\Authorize_Page($this->u_chair, $qreq))->go();
            return false;
        } catch (Redirection $redir) {
            return true;
        } catch (JsonCompletion $jc) {
            return false;
        } catch (PageCompletion $pc) {
            return false;
        } finally {
            ob_end_clean();
            Navigation::$test_mode = $old_test_mode;
        }
    }

    /** A request that is not a valid POST must not confirm an authorization.
     * Otherwise any site could cause a signed-in user's browser to issue one,
     * binding that user's account to a client the attacker controls
     * (RFC 6749 §10.12). */
    function test_authconfirm_requires_valid_post() {
        // an attacker registers a client and obtains an unconfirmed code
        $jr = call_api("=oauthregister", $this->u_empty,
            TestQreq::post_json(["redirect_uris" => ["https://dro.com/"]]));
        xassert(isset($jr->client_id));
        $qreq = TestQreq::get([
            "client_id" => $jr->client_id, "redirect_uri" => "https://dro.com/",
            "response_type" => "code", "state" => "S", "scope" => "read"
        ])->set_page("authorize")->set_user($this->u_empty);
        Qrequest::set_main_request($qreq);
        $code = null;
        try {
            (new HotCRP\Authorize_Page($this->u_empty, $qreq))->go();
        } catch (JsonCompletion $jc) {
            $code = $jc->result->content["code"] ?? null;
        }
        xassert_neqq($code, null);
        if ($code === null) {
            return;
        }

        // a signed-in victim is made to issue a GET: no redirect, no binding
        $vq = TestQreq::get(["authconfirm" => 1, "code" => $code])
            ->set_page("authorize")->set_user($this->u_chair);
        Qrequest::set_main_request($vq);
        xassert(!$this->authconfirm_redirects($vq));
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("email"), null);

        // nor does a POST without the form's post token
        $vq = (new Qrequest("POST", ["authconfirm" => 1, "code" => $code]))
            ->set_navigation(Navigation::get())
            ->set_body(null, "application/x-www-form-urlencoded")
            ->set_page("authorize")->set_user($this->u_chair);
        Qrequest::set_main_request($vq);
        xassert(!$this->authconfirm_redirects($vq));
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("email"), null);

        // the real form, which posts with a token, still works
        $vq = TestQreq::post(["authconfirm" => 1, "code" => $code])
            ->set_page("authorize")->set_user($this->u_chair);
        Qrequest::set_main_request($vq);
        $url = null;
        try {
            (new HotCRP\Authorize_Page($this->u_chair, $vq))->go();
        } catch (Redirection $redir) {
            $url = $redir->url;
        }
        xassert_neqq($url, null);
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("email"), "chair@_.com");
    }

    /** The consent form's scope field limits what the client asked for, as its
     * description says; it cannot widen the grant, and it is user input. */
    function test_authconfirm_scope_limits_only() {
        $mk = function ($form_scope) {
            $jr = call_api("=oauthregister", $this->u_empty,
                TestQreq::post_json(["redirect_uris" => ["https://dall.com/"]]));
            xassert(isset($jr->client_id));
            $qreq = TestQreq::get([
                "client_id" => $jr->client_id, "redirect_uri" => "https://dall.com/",
                "response_type" => "code", "state" => "S", "scope" => "read"
            ])->set_page("authorize")->set_user($this->u_empty);
            Qrequest::set_main_request($qreq);
            $code = null;
            try {
                (new HotCRP\Authorize_Page($this->u_empty, $qreq))->go();
            } catch (JsonCompletion $jc) {
                $code = $jc->result->content["code"] ?? null;
            }
            xassert_neqq($code, null);
            $vq = TestQreq::post(["authconfirm" => 1, "code" => $code, "scope" => $form_scope])
                ->set_page("authorize")->set_user($this->u_chair);
            Qrequest::set_main_request($vq);
            $err = $url = null;
            try {
                (new HotCRP\Authorize_Page($this->u_chair, $vq))->go();
            } catch (Redirection $redir) {
                $url = $redir->url;
                $err = $this->redirect_error($url);
            }
            // the consent form posts no `redirect_uri`, but an error must
            // still reach the client rather than redirecting back here
            if ($url !== null) {
                xassert_str_starts_with($url, "https://dall.com/");
            }
            $tok = TokenInfo::find($code, $this->conf);
            return [$err, $tok ? $tok->data("scope") : null];
        };

        // the request asked for `read`; the form cannot grant more than that
        [$err, $scope] = $mk("all");
        xassert_eqq($err, null);
        xassert(!TokenScope::scope_str_contains($scope, "write"));

        // narrowing works
        [$err, $scope] = $mk("none");
        xassert_eqq($err, null);
        xassert(!TokenScope::scope_str_contains($scope, "read"));

        // and the field is validated like any other request parameter
        [$err, $scope] = $mk("bad\x01scope");
        xassert_eqq($err, "invalid_scope");
    }

    /** Replaying an old refresh token must revoke the live one however long
     * the rotation chain has grown. A bounded walk would stop short and leave
     * the attacker's tokens working, which is the case this exists to stop. */
    function test_refresh_token_replay_long_chain() {
        $tok1 = $this->dynamic_client_token("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        xassert_neqq($this->_last_refresh_token, null);
        $stale = $this->_last_refresh_token;

        // rotate past any plausible iteration bound
        for ($i = 0; $i !== 205; ++$i) {
            xassert_neqq($this->refresh_access_token(), null);
        }
        $live_refresh = $this->_last_refresh_token;
        $live_access = $this->find_token_salt($live_refresh)->data("access_token");
        xassert($this->find_token_salt($live_refresh)->is_active());
        xassert($this->find_token_salt($live_access)->is_active());

        // the stale token is replayed: the whole family must be revoked
        $qreq = TestQreq::post([
            "grant_type" => "refresh_token",
            "refresh_token" => $stale,
            "client_id" => $this->_last_client_id,
            "client_secret" => $this->_last_client_secret
        ]);
        $jr = call_api("=oauthtoken", $this->u_empty, $qreq, null);
        xassert_eqq($jr->error ?? null, "invalid_grant");

        xassert(!$this->find_token_salt($live_refresh)->is_active());
        xassert(!$this->find_token_salt($live_access)->is_active());
    }

    /** @param string $salt
     * @return ?TokenInfo */
    private function find_token_salt($salt) {
        return TokenInfo::find_from($salt, $this->conf, $salt[2] === "T");
    }

    /** A configured client with no `client_secret` cannot prove who it is, so
     * it is a public client: PKCE is required, and an empty secret is not
     * accepted as authentication (RFC 8252 §8.5). */
    function test_client_without_secret_is_public() {
        $old = $this->conf->opt("oAuthClients");
        $this->conf->set_opt("oAuthClients", array_merge($old, [(object) [
            "name" => "nosecret", "client_id" => "nosecret-client",
            "redirect_uris" => ["https://nosecret.example.com/cb"], "scope" => "all"
        ]]));
        $this->conf->refresh_settings();

        $authorize = function ($rest) {
            $qreq = TestQreq::get($rest + [
                "client_id" => "nosecret-client",
                "redirect_uri" => "https://nosecret.example.com/cb",
                "response_type" => "code", "state" => "S", "scope" => "read"
            ])->set_page("authorize")->set_user($this->u_chair);
            Qrequest::set_main_request($qreq);
            $code = $err = null;
            try {
                (new HotCRP\Authorize_Page($this->u_chair, $qreq))->go();
            } catch (JsonCompletion $jc) {
                $code = $jc->result->content["code"] ?? null;
            } catch (Redirection $redir) {
                $err = $this->redirect_error($redir->url);
            }
            if ($code !== null) {
                $vq = TestQreq::post(["authconfirm" => 1, "code" => $code])
                    ->set_page("authorize")->set_user($this->u_chair);
                Qrequest::set_main_request($vq);
                try {
                    (new HotCRP\Authorize_Page($this->u_chair, $vq))->go();
                } catch (Redirection $redir) {
                }
            }
            return [$code, $err];
        };

        // without PKCE the authorization request is refused
        [$code, $err] = $authorize([]);
        xassert_eqq($code, null);
        xassert_eqq($err, "invalid_request");

        // with PKCE it proceeds, and the code redeems with a verifier
        $verifier = base48_encode(random_bytes(32));
        [$code, $err] = $authorize([
            "code_challenge" => base64url_encode(hash("sha256", $verifier, true)),
            "code_challenge_method" => "S256"
        ]);
        xassert_neqq($code, null);
        if ($code === null) {
            $this->conf->set_opt("oAuthClients", $old);
            $this->conf->refresh_settings();
            return;
        }

        // a secret is not accepted from a client that has none
        $jr = call_api("=oauthtoken", $this->u_empty, TestQreq::post([
            "grant_type" => "authorization_code", "code" => $code,
            "client_id" => "nosecret-client", "client_secret" => "anything",
            "redirect_uri" => "https://nosecret.example.com/cb",
            "code_verifier" => $verifier
        ]), null);
        xassert_eqq($jr->error ?? null, "invalid_client");

        // ...and PKCE alone authenticates it
        $jr = call_api("=oauthtoken", $this->u_empty, TestQreq::post([
            "grant_type" => "authorization_code", "code" => $code,
            "client_id" => "nosecret-client",
            "redirect_uri" => "https://nosecret.example.com/cb",
            "code_verifier" => $verifier
        ]), null);
        xassert_neqq($jr->access_token ?? null, null);

        $this->conf->set_opt("oAuthClients", $old);
        $this->conf->refresh_settings();
    }

    /** `secure_uri` decides whether a URL may be trusted without TLS: only a
     * loopback request qualifies, because it never leaves the machine. */
    function test_secure_uri() {
        $ck = function ($uri) { return HotCRP\OAuthClient::secure_uri($uri); };
        xassert($ck("https://idp.example.com/token"));
        xassert($ck("https://idp.example.com:8443/token"));
        // a loopback request has no network for an attacker to sit on
        xassert($ck("http://localhost:19382/token"));
        xassert($ck("http://127.0.0.1:19382/token"));
        xassert($ck("http://[::1]:19382/token"));
        xassert($ck("http://localhost/token"));
        // anything else in plaintext is exposed
        xassert(!$ck("http://idp.example.com/token"));
        xassert(!$ck("http://localhost.evil.example.com/token"));
        xassert(!$ck("http://127.0.0.2:19382/token"));
        xassert(!$ck("http://localhost@evil.example.com/token"));
        xassert(!$ck("ftp://idp.example.com/token"));
        xassert(!$ck(""));
    }

    function test_special_use_address() {
        foreach (["127.0.0.1", "10.1.2.3", "192.168.1.1", "169.254.7.7", "172.16.0.1",
                  "100.64.3.3", "0.0.0.0", "::1", "fe80::1", "fd00::1", "::ffff:127.0.0.1",
                  // deprecated IPv4-compatible form of 127.0.0.1 and 10.0.0.1
                  "::127.0.0.1", "::10.0.0.1", "::",
                  "not an address"] as $ip) {
            xassert(HotCRP\OAuthClientDocument::special_use_address($ip), "{$ip} is special");
        }
        foreach (["8.8.8.8", "104.18.0.1", "2606:4700::1111"] as $ip) {
            xassert(!HotCRP\OAuthClientDocument::special_use_address($ip), "{$ip} is not special");
        }
    }

    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_client_document_checks() {
        $cid = "https://ex.example.com/c.json";
        $base = [
            "client_id" => $cid,
            "client_name" => "Example",
            "redirect_uris" => ["https://ex.example.com/cb"]
        ];
        $doc = $this->client_document($cid);
        xassert($doc->check_document(200, "application/json", json_encode($base)));
        xassert_eqq($doc->document->client_name, "Example");
        xassert_eqq($doc->document->redirect_uris, ["https://ex.example.com/cb"]);

        // `client_id` must match the document URL
        $x = $base;
        $x["client_id"] = "https://ex.example.com/other.json";
        xassert(!$this->client_document($cid)->check_document(200, "application/json", json_encode($x)));

        // no shared secrets
        $x = $base;
        $x["client_secret"] = "Dudfield";
        xassert(!$this->client_document($cid)->check_document(200, "application/json", json_encode($x)));

        $x = $base;
        $x["token_endpoint_auth_method"] = "client_secret_basic";
        xassert(!$this->client_document($cid)->check_document(200, "application/json", json_encode($x)));

        // redirect URIs are required, and must be https or loopback
        $x = $base;
        unset($x["redirect_uris"]);
        xassert(!$this->client_document($cid)->check_document(200, "application/json", json_encode($x)));

        $x = $base;
        $x["redirect_uris"] = ["http://evil.example.com/cb"];
        xassert(!$this->client_document($cid)->check_document(200, "application/json", json_encode($x)));

        // one client serves many platforms, so unusable URIs are dropped
        // rather than rejecting the document that lists them
        $x = $base;
        $x["redirect_uris"] = ["com.example.app:/cb", "http://127.0.0.1:3000/callback",
                               "http://evil.example.com/cb", 17,
                               "https://ex.example.com/cb"];
        $doc3 = $this->client_document($cid);
        xassert($doc3->check_document(200, "application/json", json_encode($x)));
        xassert_eqq($doc3->document->redirect_uris,
            ["http://127.0.0.1:3000/callback", "https://ex.example.com/cb"]);

        // ...but a document with nothing usable is rejected
        $x = $base;
        $x["redirect_uris"] = ["com.example.app:/cb", "http://evil.example.com/cb"];
        $doc4 = $this->client_document($cid);
        xassert(!$doc4->check_document(200, "application/json", json_encode($x)));
        xassert_str_contains($doc4->error_message(), "no usable redirect URI");

        // the grant and response types must be supported
        $x = $base;
        $x["grant_types"] = ["client_credentials"];
        xassert(!$this->client_document($cid)->check_document(200, "application/json", json_encode($x)));

        // an ill-typed `token_endpoint_auth_method` is rejected, not
        // interpolated into a message
        $x = $base;
        $x["token_endpoint_auth_method"] = ["none"];
        $doc2 = $this->client_document($cid);
        xassert(!$doc2->check_document(200, "application/json", json_encode($x)));
        xassert_str_contains($doc2->error_message(), "unsupported authentication method");

        // a long list is truncated, not refused: the accepted list rides on
        // every code and refresh token, so only the first 32 are kept
        $x = $base;
        $x["redirect_uris"] = array_map(function ($i) {
            return "https://ex.example.com/{$i}/cb";
        }, range(2000, 2099));
        $doc5 = $this->client_document($cid);
        xassert($doc5->check_document(200, "application/json", json_encode($x)));
        xassert_eqq(count($doc5->document->redirect_uris), 32);
        xassert_eqq($doc5->document->redirect_uris[0], "https://ex.example.com/2000/cb");
        xassert_eqq($doc5->document->redirect_uris[31], "https://ex.example.com/2031/cb");

        // a usable URI beyond the scan limit is not reached
        $x = $base;
        $x["redirect_uris"] = array_merge(
            array_fill(0, 150, "com.example.app:/cb"),
            ["https://ex.example.com/late/cb"]);
        xassert(!$this->client_document($cid)->check_document(200, "application/json", json_encode($x)));

        // the response must be a JSON object served with status 200
        xassert(!$this->client_document($cid)->check_document(404, "application/json", json_encode($base)));
        xassert(!$this->client_document($cid)->check_document(200, "text/html", json_encode($base)));
        xassert(!$this->client_document($cid)->check_document(200, "application/json", "[]"));
        xassert(!$this->client_document($cid)->check_document(200, "application/json", "not json"));
    }

    function test_allow_if() {
        $token = $this->dynamic_client_token("https://dchair.com/", $this->u_chair, ["scope" => "read write"]);
        xassert_eqq($token->data("scope"), "write");

        $jr = $this->dynamic_client_result("https://dchair.com/", $this->u_mgbaker, ["scope" => "read write"]);
        xassert_eqq($jr, null);
    }
}
