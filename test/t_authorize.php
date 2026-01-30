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
        ]]);
        $this->conf->set_opt("oAuthDynamicClients", true);
        $this->conf->refresh_settings();
    }

    function finalize() {
        $this->conf->set_opt("oAuthClients", null);
        $this->conf->set_opt("oAuthDynamicClients", null);
        $this->conf->refresh_settings();
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
        xassert_eqq($jr->_token->data("scope"), "read");

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

    function test_allow_if() {
        $token = $this->dynamic_client_token("https://dchair.com/", $this->u_chair, ["scope" => "read write"]);
        xassert_eqq($token->data("scope"), "write");

        $jr = $this->dynamic_client_result("https://dchair.com/", $this->u_mgbaker, ["scope" => "read write"]);
        xassert_eqq($jr, null);
    }
}
