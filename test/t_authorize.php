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
    private $_last_page_html;
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
            // `u_chair` cannot be demoted (a site keeps one chair), so
            // `allow_if` re-checks need a role a test user can actually lose
            "name" => "dpc", "dynamic" => true, "scope" => "all", "allow_if" => "pc",
            "redirect_uris" => ["https://dpc.com/"]
        ], (object) [
            "name" => "dloop", "dynamic" => true, "scope" => "read",
            "redirect_uris" => ["http://127.0.0.1:5000/cb", "https://dloop.com/"]
        ], (object) [
            // registered by this site's administrator, so its redirect URI is
            // one this site chose to trust
            "name" => "conf1", "title" => "Configured Client",
            "client_id" => "confclient", "client_secret" => "confsecret",
            "scope" => "read", "redirect_uris" => ["https://conf1.example.com/cb"]
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
        $args = [
            "client_id" => $this->_last_client_id,
            "redirect_uri" => $redirect_uri,
            "response_type" => "code",
            "state" => $state
        ];
        // omitted unless asked for: that is what a client that takes the
        // server's default sends
        if (isset($rest["scope"])) {
            $args["scope"] = $rest["scope"];
        }
        $qreq = TestQreq::user_get($user, $args)->set_page("authorize");
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
        $confirm = ["code" => $code, "authconfirm" => "1"];
        if (isset($rest["authbot"])) {
            // the consent page's bot list posts this beside the chair's own
            // account: the grant speaks as the bot, the chair authorizes it
            $confirm["authbot"] = $rest["authbot"];
        }
        $qreq = TestQreq::user_post($user, $confirm)->set_page("authorize");
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
        $args = [
            "grant_type" => "authorization_code",
            "code" => $code,
            "redirect_uri" => $redirect_uri,
            "client_id" => $this->_last_client_id
        ];
        $qreq = TestQreq::post($args + ["client_secret" => $this->_last_client_secret]);
        if ($rest["client_secret_basic"] ?? false) {
            // the shape `client_secret_basic` clients actually send: the secret
            // moves to the header, `client_id` stays in the body
            $qreq = TestQreq::post($args)->set_header("Authorization", "Basic "
                . base64_encode(rawurlencode($this->_last_client_id) . ":"
                    . rawurlencode($this->_last_client_secret)));
        }
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
        $jr = $this->dynamic_client_result("https://dro.com/", $this->u_chair,
            ["scope" => "openid"]);
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

    /** `client_secret_basic` clients repeat `client_id` in the body — the MCP
     * reference client does, and so does anything built on `requests-oauthlib`
     * — while the secret travels only in the header. That is one
     * authentication method with a redundant hint, not two, and refusing it
     * locks out the clients that matter. */
    function test_client_secret_basic_with_client_id_in_body() {
        $jr = $this->dynamic_client_result("https://dro.com/", $this->u_chair,
            ["scope" => "read", "client_secret_basic" => true]);
        xassert_neqq($jr, null);
        if (!$jr) {
            error_log($this->_failure ?? "");
            return;
        }
        xassert_eqq(call_api_result("whoami", $jr->_token, [])->response_code(), 200);

        // but a body `client_id` naming a different client is malformed: the
        // request would authenticate as one client and act as another
        $post = function ($param, $hdr) {
            $qq = TestQreq::post($param)->set_conf($this->conf)->set_page("api")
                ->set_header("Authorization", $hdr);
            Qrequest::set_main_request($qq);
            return HotCRP\Authorize_Page::oauthtoken_api($this->u_empty, $qq);
        };
        $basic = "Basic " . base64_encode($this->_last_client_id . ":" . $this->_last_client_secret);
        $r = $post(["grant_type" => "refresh_token",
                    "refresh_token" => $this->_last_refresh_token,
                    "client_id" => "hctk_nosuchclient0000000000000000"], $basic);
        xassert_eqq([$r->status, $r->content["error"] ?? null], [400, "invalid_request"]);

        // as is a body secret that disagrees with the header
        $r = $post(["grant_type" => "refresh_token",
                    "refresh_token" => $this->_last_refresh_token,
                    "client_secret" => "wrong"], $basic);
        xassert_eqq([$r->status, $r->content["error"] ?? null], [400, "invalid_request"]);

        // neither attempt spent the refresh token
        xassert_neqq($this->refresh_access_token(), null);
    }

    /** A grant failure names the check that failed, which is what a client
     * developer needs and a stranger must not have: it says whether a token
     * exists and whether someone else has already spent it. Only a client that
     * authenticated with a secret may hear it. */
    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_grant_error_descriptions_need_client_authentication() {
        $post = function ($param) {
            $qq = TestQreq::post($param)->set_conf($this->conf)->set_page("api");
            Qrequest::set_main_request($qq);
            return HotCRP\Authorize_Page::oauthtoken_api($this->u_empty, $qq);
        };
        /** @return list<?string> */
        $failures = function ($param) use ($post) {
            $stale = $this->_last_refresh_token;
            xassert_neqq($this->refresh_access_token(), null);
            $ds = [];
            foreach ([$stale, "hctr_" . str_repeat("z", 36), "garbage"] as $rt) {
                $r = $post($param + ["grant_type" => "refresh_token", "refresh_token" => $rt]);
                xassert_eqq($r->content["error"] ?? null, "invalid_grant", $rt);
                $ds[] = $r->content["error_description"] ?? null;
            }
            return $ds;
        };

        // a confidential client asking about its own grant hears which check
        // failed, so a developer can tell a replay from a token this server
        // never issued
        $jr = $this->dynamic_client_result("https://dro.com/", $this->u_chair,
            ["scope" => "read"]);
        xassert_neqq($jr, null);
        $ds = $failures(["client_id" => $this->_last_client_id,
                         "client_secret" => $this->_last_client_secret]);
        xassert_str_contains($ds[0] ?? "", "already used");
        xassert_neqq($ds[0], $ds[1]);
        // an unissued token and an unparseable one stay indistinguishable
        xassert_eqq($ds[1], $ds[2]);

        // a metadata document client authenticates on a `client_id` that is a
        // public URL, so every failure has to read the same
        $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI, $this->u_chair,
            ["scope" => "read"]);
        xassert_neqq($jr, null);
        $ds = $failures(["client_id" => self::MDOC_CLIENT_ID]);
        xassert_eqq($ds, ["Grant not valid", "Grant not valid", "Grant not valid"]);
    }

    /** The contactdb cannot enumerate the conferences where a user has
     * authorized a client, so each conference records the fact on the user and
     * `cdb_roles()` carries it across. It is set when a grant is made and
     * never cleared, including by a writer that computes `roles` whole. */
    function test_grant_marks_has_app() {
        $roles_of = function ($email) {
            $this->conf->invalidate_caches("users");
            return $this->conf->checked_user_by_email($email)->roles;
        };
        $email = "estrin@usc.edu";
        $user = $this->conf->checked_user_by_email($email);
        $uid = $user->contactId;
        Dbl::qe($this->conf->dblink, "update ContactInfo set roles=roles&? where contactId=?",
                ~Contact::ROLE_HASAPP, $uid);
        $before = $roles_of($email);
        xassert_eqq($before & Contact::ROLE_HASAPP, 0);
        xassert(!$this->conf->checked_user_by_email($email)->has_app());

        $nlog = (int) Dbl::fetch_ivalue($this->conf->dblink,
            "select count(*) from ActionLog where (contactId=? or destContactId=?) and action like 'Account edited: roles%'",
            $uid, $uid);
        $user = $this->conf->checked_user_by_email($email);
        xassert_neqq($this->dynamic_client_result("https://dro.com/", $user,
            ["scope" => "read"]), null);

        // recorded, persisted, and the other roles are untouched
        $after = $roles_of($email);
        xassert_eqq($after, $before | Contact::ROLE_HASAPP);
        xassert($this->conf->checked_user_by_email($email)->has_app());

        // and it is not an account edit: no role the chair granted changed,
        // so nothing is logged against the user
        xassert_eqq((int) Dbl::fetch_ivalue($this->conf->dblink,
            "select count(*) from ActionLog where (contactId=? or destContactId=?) and action like 'Account edited: roles%'",
            $uid, $uid), $nlog);

        // a second grant does not write again
        $user = $this->conf->checked_user_by_email($email);
        $mtime = Dbl::fetch_ivalue($this->conf->dblink,
            "select updateTime from ContactInfo where contactId=?", $uid);
        $user->mark_has_app();
        xassert_eqq(Dbl::fetch_ivalue($this->conf->dblink,
            "select updateTime from ContactInfo where contactId=?", $uid), $mtime);

        // `cdb_roles()` echoes it, which is how the contactdb row gets it
        $user = $this->conf->checked_user_by_email($email);
        $cdbr = $user->cdb_roles();
        xassert_eqq($cdbr & Contact::ROLE_HASAPP, Contact::ROLE_HASAPP);
        // and carries the granted roles alongside, unchanged
        xassert_eqq($cdbr & Contact::ROLE_PCLIKE, $before & Contact::ROLE_PCLIKE);

        // A chair editing the profile must not drop it. The profile form
        // sets roles absolutely — nothing in the request mentions `hasapp` —
        // so only the settable roles may be reset.
        $user = $this->conf->checked_user_by_email($email);
        xassert_eqq($user->roles & Contact::ROLE_PC, Contact::ROLE_PC);
        $us = new UserStatus($this->u_chair);
        xassert_neqq($us->save_user((object) ["email" => $email, "roles" => ["none"]]), null);
        xassert(!$us->has_error());
        $roles = $roles_of($email);
        xassert_eqq($roles & Contact::ROLE_PC, 0);
        xassert_eqq($roles & Contact::ROLE_HASAPP, Contact::ROLE_HASAPP);

        // put the role back the same way
        $us = new UserStatus($this->u_chair);
        xassert_neqq($us->save_user((object) ["email" => $email, "roles" => ["pc"]]), null);
        xassert_eqq($roles_of($email), $before | Contact::ROLE_HASAPP);
    }

    /** A client that holds a secret gets a signed ID token, and the signature
     * has to be the one every other JWS implementation computes: the base64url
     * encoding of the MAC's octets, not of its hexadecimal spelling
     * (RFC 7515 §5.1). A round trip through this code alone would not catch
     * that, since both halves would agree on the wrong thing. */
    function test_id_token_signature() {
        $jr = $this->dynamic_client_result("https://dro.com/", $this->u_chair,
            ["scope" => "openid"]);
        xassert_neqq($jr, null);
        if (!$jr || !isset($jr->id_token)) {
            xassert(false);
            return;
        }
        $secret = $this->_last_client_secret;
        [$h, $p, $sig] = explode(".", $jr->id_token);
        xassert_eqq($sig, base64url_encode(hash_hmac("sha256", "{$h}.{$p}", $secret, true)));
        xassert_eqq(strlen(base64url_decode($sig)), 32);
        xassert_eqq(json_decode(base64url_decode($h))->alg ?? null, "HS256");

        // and this code verifies what it produces
        $jwt = new HotCRP\JWTParser;
        $jwt->verify_key = $secret;
        xassert_neqq($jwt->validate($jr->id_token), null);
        xassert_eqq($jwt->errcode, 0);

        // a token signed with the wrong key does not verify
        $jwt = new HotCRP\JWTParser;
        $jwt->verify_key = $secret . "x";
        xassert_eqq($jwt->validate($jr->id_token), null);
        xassert_eqq($jwt->errcode, 1111);

        // nor does one whose payload was edited under the original signature
        $forged = base64url_encode(str_replace("chair@_.com", "chair@x.com",
            base64url_decode($p)));
        $jwt = new HotCRP\JWTParser;
        $jwt->verify_key = $secret;
        xassert_eqq($jwt->validate("{$h}.{$forged}.{$sig}"), null);
        xassert_eqq($jwt->errcode, 1111);
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
            [$how, $detail] = $this->authorize_outcome([
                "client_id" => $jr->client_id,
                "redirect_uri" => $ruri,
                "response_type" => "code",
                "state" => base48_encode(random_bytes(16)),
                "scope" => "read"
            ], $this->u_chair);
            if ($needs_pkce) {
                // a self-registered client's request error is reported on the
                // page, not bounced to the URI the client chose
                xassert_eqq($how, "page");
                xassert_str_contains($detail ?? "", "invalid_request");
            } else {
                xassert_eqq($how, "code");
                xassert_neqq($detail, null);
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
        $qreq = TestQreq::user_get($user, $args)->set_page("authorize");
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
            // a self-registered client's request error is reported on the page,
            // with the protocol's error response behind a link
            if (preg_match('/<a[^>]*href="([^"]*[?&]error=[^"]*)"/', ob_get_contents(), $m)) {
                $this->_last_error = $this->redirect_error(html_entity_decode($m[1]));
            }
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
        $qreq = TestQreq::user_post($user, ["code" => $code, "authconfirm" => "1"])
            ->set_page("authorize");
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

    /** Run an authorization request and report how it ended.
     * @param array<string,mixed> $param
     * @param Contact $user
     * @return array{'code'|'redirect'|'page'|'none',?string} */
    private function authorize_outcome($param, $user) {
        $qreq = TestQreq::user_get($user, $param)->set_page("authorize");
        Qrequest::set_main_request($qreq);
        // test_mode 2 routes page feedback into the page, where the output
        // buffer absorbs it
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        ob_start();
        try {
            (new HotCRP\Authorize_Page($user, $qreq))->go();
            return ["none", null];
        } catch (JsonCompletion $jc) {
            return ["code", $jc->result->content["code"] ?? null];
        } catch (Redirection $redir) {
            return ["redirect", $redir->url];
        } catch (PageCompletion $pc) {
            // the page itself, so a test can say which error it expected;
            // `_last_page_html` keeps the markup for a test that needs a link
            $this->_last_page_html = ob_get_contents();
            return ["page", trim(preg_replace('/\s+/', " ", strip_tags($this->_last_page_html)))];
        } finally {
            ob_end_clean();
            Navigation::$test_mode = $old_test_mode;
        }
    }

    /** @return int */
    private function count_codes() {
        return (int) Dbl::fetch_ivalue($this->conf->dblink,
            "select count(*) from Capability where capabilityType=?",
            TokenInfo::OAUTHCODE);
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

    /** An ID token is proof for this site only if this site is its whole
     * audience. An extra audience means it was issued to that party too, so it
     * is rejected unless the provider is configured to expect one. */
    function test_id_token_audience() {
        $base = [
            "iss" => "https://idp.example.com", "exp" => Conf::$now + 600,
            "iat" => Conf::$now, "email" => "x@example.com"
        ];
        /** @param array<string,mixed> $over
         * @param true|list<string> $trusted
         * @return ?int */
        $errcode = function ($over, $trusted = []) use ($base) {
            $authi = (object) [
                "name" => "probe", "client_id" => "CLIENT",
                "issuer" => "https://idp.example.com", "nonce" => null,
                "trusted_audiences" => $trusted
            ];
            '@phan-var-force \HotCRP\OAuthProvider $authi';
            $jwt = new HotCRP\JWTParser;
            return $jwt->validate_id_token((object) ($over + $base), $authi)
                ? null : $jwt->errcode;
        };

        // one audience, naming this client
        xassert_eqq($errcode(["aud" => "CLIENT"]), null);
        xassert_eqq($errcode(["aud" => ["CLIENT"]]), null);
        // an audience that is not this client
        xassert_eqq($errcode(["aud" => "other"]), 1204);
        xassert_eqq($errcode(["aud" => ["other"]]), 1204);
        // `aud` is required, and has to be a string or a list
        xassert_eqq($errcode(["aud" => 17]), 1203);
        // ...and `iss` still has to match
        xassert_eqq($errcode(["iss" => "https://evil.example.com", "aud" => "CLIENT"]), 1202);

        // an extra audience this site was not told to expect
        xassert_eqq($errcode(["aud" => ["CLIENT", "other"]]), 1205);
        // ...and the same token once the provider declares that audience
        xassert_eqq($errcode(["aud" => ["CLIENT", "other"]], ["other"]), null);
        xassert_eqq($errcode(["aud" => ["CLIENT", "other"]], ["nope"]), 1205);
        xassert_eqq($errcode(["aud" => ["CLIENT", "other"]], true), null);
        // trusting an audience does not excuse this client's absence
        xassert_eqq($errcode(["aud" => ["other"]], ["other"]), 1204);
        xassert_eqq($errcode(["aud" => ["other"]], true), 1204);
    }

    /** `trusted_audiences` is admin configuration, so it is normalized and
     * type-checked where the provider is built, not where it is used. */
    function test_provider_trusted_audiences_config() {
        $mk = function ($ta) {
            $base = [
                "name" => "p", "client_id" => "C", "client_secret" => "S",
                "auth_uri" => "https://idp.example.com/auth",
                "token_uri" => "https://idp.example.com/token",
                "redirect_uri" => "https://conf.example.com/oauth"
            ];
            if ($ta !== null) {
                $base["trusted_audiences"] = $ta;
            }
            $this->conf->set_opt("oAuthProviders", [(object) $base]);
            $this->conf->refresh_settings();
            $authi = HotCRP\OAuthProvider::find($this->conf, "p");
            return $authi ? $authi->trusted_audiences : false;
        };

        try {
            xassert_eqq($mk(null), []);
            xassert_eqq($mk("one"), ["one"]);
            xassert_eqq($mk(["one", "two"]), ["one", "two"]);
            xassert_eqq($mk(true), true);
            xassert_eqq($mk(false), []);
            // malformed configuration disables the provider rather than
            // silently widening or narrowing what it accepts
            xassert_eqq($mk(17), false);
            xassert_eqq($mk(["one", 17]), false);
            xassert_eqq($mk((object) ["a" => "b"]), false);
        } finally {
            $this->conf->set_opt("oAuthProviders", null);
            $this->conf->refresh_settings();
        }
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
        // metadata document clients exist only where client identifier URLs
        // can be checked, so both directions are asserted: a capability this
        // installation cannot honor must not be advertised
        $mdoc = HotCRP\OAuthClientDocument::supported();
        xassert_eqq($j->client_id_metadata_document_supported ?? false, $mdoc);
        xassert_eqq(in_array("none", $j->token_endpoint_auth_methods_supported, true), $mdoc);
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

        $qreq = TestQreq::user_get($this->u_chair)->set_page("authorize");
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
        // a pattern names one scheme, and matches only that scheme
        xassert(!$ck("https://localhost/*", "http://localhost/c.json"));
        xassert(!$ck("http://localhost/*", "https://mdoc.example.com/c.json"));

        // a host with no port matches no port; a program that takes an
        // ephemeral port from the OS cannot know it in advance, so `:*` ends
        // a host to mean any port or none (RFC 8252 §7.3)
        xassert($ck("https://mdoc.example.com:*/c.json", "https://mdoc.example.com/c.json"));
        xassert($ck("https://mdoc.example.com:*/c.json", "https://mdoc.example.com:8443/c.json"));
        xassert($ck("https://*.example.com:*/c.json", "https://a.example.com:8443/c.json"));
        xassert($ck("https://*.example.com:*/c.json", "https://a.example.com/c.json"));
        xassert(!$ck("https://mdoc.example.com/c.json", "https://mdoc.example.com:8443/c.json"));
        xassert(!$ck("https://mdoc.example.com:8443/c.json", "https://mdoc.example.com/c.json"));
        // the port wildcard stays inside the host: it neither swallows the
        // path nor lets the host run on
        xassert(!$ck("https://mdoc.example.com:*/c.json", "https://mdoc.example.com:8443/other"));
        xassert(!$ck("https://mdoc.example.com:*/c.json", "https://mdoc.example.com.evil.com/c.json"));
        xassert(!$ck("https://mdoc.example.com:*/*", "https://evil.com/mdoc.example.com:8443/c.json"));

        // a host wildcard stops at the `:` that introduces a port: a different
        // port is a different origin, and only `:*` opts into any of them
        xassert(!$ck("https://mdoc.example.*/c.json", "https://mdoc.example.com:8443/c.json"));
        xassert($ck("https://mdoc.example.*/c.json", "https://mdoc.example.com/c.json"));
        xassert(!$ck("https://*.example.com/c.json", "https://a.example.com:8443/c.json"));
        // a pattern with no path matches nothing, since a client_id has one
        xassert(!$ck("https://*.example.com", "https://a.example.com/c.json"));
        xassert($ck("https://*.example.com/*", "https://a.example.com/c.json"));
    }

    /** A plaintext `http` client identifier is a development convenience, so
     * it may name only this machine — and it is accepted only where a
     * `client_id_match` names the `http` scheme itself. Nothing weaker can say
     * an administrator meant to trust a document fetched over a channel anyone
     * can rewrite. */
    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_plaintext_client_id_needs_an_explicit_scheme() {
        // only loopback hosts are even well formed over plaintext
        xassert(!!$this->client_document("http://localhost/c.json"));
        xassert(!!$this->client_document("http://127.0.0.1:5173/c.json"));
        xassert(!!$this->client_document("http://[::1]/c.json"));
        xassert(!$this->client_document("http://ex.example.com/c.json"));
        xassert(!$this->client_document("http://10.0.0.1/c.json"));

        /** @param null|string|list<string> $pat */
        $ck = function ($pat, $client_id) {
            $cx = $pat === null ? (object) [] : (object) ["client_id_match" => $pat];
            return $this->client_document($client_id)->matches($cx);
        };
        // a pattern naming `http` accepts it...
        xassert($ck("http://localhost/*", "http://localhost/c.json"));
        xassert($ck("http://localhost:*/c.json", "http://localhost:5173/c.json"));
        xassert($ck("http://localhost/c.json", "http://localhost/c.json"));
        xassert($ck(["https://mdoc.example.com/*", "http://localhost/*"], "http://localhost/c.json"));
        // ...and nothing else does, however permissive
        xassert(!$ck("*", "http://localhost/c.json"));
        xassert(!$ck(null, "http://localhost/c.json"));
        xassert(!$ck("https://*/*", "http://localhost/c.json"));
        xassert(!$ck("http://127.0.0.1/*", "http://localhost/c.json"));

        // an `https` identifier is unaffected by all of this
        xassert($ck("*", "https://mdoc.example.com/c.json"));
        xassert($ck(null, "https://mdoc.example.com/c.json"));

        // the loopback connection a plaintext identifier needs is refused
        // unless that identifier was the one a pattern named
        xassert(HotCRP\OAuthClientDocument::special_use_address("127.0.0.1"));
        xassert(!HotCRP\OAuthClientDocument::special_use_address("127.0.0.1", true));
        xassert(!HotCRP\OAuthClientDocument::special_use_address("::1", true));
        // ...and the exemption is loopback alone, not every private range
        xassert(HotCRP\OAuthClientDocument::special_use_address("10.0.0.1", true));
        xassert(HotCRP\OAuthClientDocument::special_use_address("192.168.1.1", true));
        xassert(HotCRP\OAuthClientDocument::special_use_address("169.254.1.1", true));
        xassert(HotCRP\OAuthClientDocument::special_use_address("fd00::1", true));
    }

    /** Run `$qreq` through the authorization page and report whether it
     * redirected. Feedback messages are absorbed rather than printed.
     * @return bool */
    private function authconfirm_redirects(Qrequest $qreq, ?Contact $user = null) {
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        ob_start();
        try {
            (new HotCRP\Authorize_Page($user ?? $this->u_chair, $qreq))->go();
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
        // an attacker registers a client and, from their own account, obtains
        // a code that is not yet bound to anyone
        $jr = call_api("=oauthregister", $this->u_empty,
            TestQreq::post_json(["redirect_uris" => ["https://dro.com/"]]));
        xassert(isset($jr->client_id));
        $qreq = TestQreq::user_get($this->u_mgbaker, [
            "client_id" => $jr->client_id, "redirect_uri" => "https://dro.com/",
            "response_type" => "code", "state" => "S", "scope" => "read"
        ])->set_page("authorize");
        Qrequest::set_main_request($qreq);
        $code = null;
        try {
            (new HotCRP\Authorize_Page($this->u_mgbaker, $qreq))->go();
        } catch (JsonCompletion $jc) {
            $code = $jc->result->content["code"] ?? null;
        }
        xassert_neqq($code, null);
        if ($code === null) {
            return;
        }

        // a signed-in victim is made to issue a GET: no redirect, no binding
        $vq = TestQreq::user_get($this->u_chair, ["authconfirm" => 1, "code" => $code])
            ->set_page("authorize");
        Qrequest::set_main_request($vq);
        xassert(!$this->authconfirm_redirects($vq));
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("email"), null);

        // nor does a POST without the form's post token
        $vq = TestQreq::apply_user($this->u_chair, TestQreq::unapproved_post(["authconfirm" => 1, "code" => $code]))
            ->set_page("authorize");
        Qrequest::set_main_request($vq);
        xassert(!$this->authconfirm_redirects($vq));
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("email"), null);

        // the real form, which posts with a token, still works
        $vq = TestQreq::user_post($this->u_chair, ["authconfirm" => 1, "code" => $code])
            ->set_page("authorize");
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
            $qreq = TestQreq::user_get($this->u_mgbaker, [
                "client_id" => $jr->client_id, "redirect_uri" => "https://dall.com/",
                "response_type" => "code", "state" => "S", "scope" => "read"
            ])->set_page("authorize");
            Qrequest::set_main_request($qreq);
            $code = null;
            try {
                (new HotCRP\Authorize_Page($this->u_mgbaker, $qreq))->go();
            } catch (JsonCompletion $jc) {
                $code = $jc->result->content["code"] ?? null;
            }
            xassert_neqq($code, null);
            $vq = TestQreq::user_post($this->u_chair, ["authconfirm" => 1, "code" => $code, "scope" => $form_scope])
                ->set_page("authorize");
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

    /** @param string $redirect_uri
     * @return string */
    private function register_client($redirect_uri) {
        $jr = call_api("=oauthregister", $this->u_empty,
            TestQreq::post_json(["redirect_uris" => [$redirect_uri]]));
        xassert(isset($jr->client_id));
        $this->_last_client_id = $jr->client_id ?? null;
        $this->_last_client_secret = $jr->client_secret ?? null;
        return $this->_last_client_id;
    }

    /** A client that registered itself chose its own `redirect_uri`, so this
     * endpoint must not bounce an anonymous visitor there just because the
     * request was malformed: that would make it an open redirector for anyone
     * willing to register (RFC 9700 §4.11.2). A signed-in user is in a real
     * authorization flow, and still gets the protocol's error response. */
    function test_self_registered_error_is_not_an_open_redirect() {
        $param = [
            "client_id" => $this->register_client("https://dro.com/"),
            "redirect_uri" => "https://dro.com/", "response_type" => "code",
            "state" => "S", "scope" => "read", "prompt" => "none"
        ];

        // signed in or not, a request error is reported on this page
        [$how, $detail] = $this->authorize_outcome($param, $this->u_empty);
        xassert_eqq($how, "page");

        [$how, $detail] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "page");
        // the destination is offered as a link, which shows where it goes and
        // takes a click, and it still carries the protocol's error response
        $href = null;
        if (preg_match('/<a[^>]*href="(https:\/\/dro\.com\/[^"]*)"/', $this->_last_page_html ?? "", $m)) {
            $href = html_entity_decode($m[1]);
        }
        xassert_neqq($href, null);
        xassert_eqq($this->redirect_error($href ?? ""), "interaction_required");

        // a client this site's administrator registered names a redirect URI
        // this site chose to trust, so it is answered either way
        $cparam = [
            "client_id" => "confclient",
            "redirect_uri" => "https://conf1.example.com/cb",
            "response_type" => "code", "state" => "S", "scope" => "read",
            "prompt" => "none"
        ];
        [$how, $detail] = $this->authorize_outcome($cparam, $this->u_empty);
        xassert_eqq($how, "redirect");
        xassert_str_starts_with($detail ?? "", "https://conf1.example.com/cb");
        xassert_eqq($this->redirect_error($detail), "interaction_required");

        // and the user can authorize the bounce themselves: Cancel on the
        // consent form is a CSRF-protected POST, so it answers the client
        unset($param["prompt"]);
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "code");
        $vq = TestQreq::user_post($this->u_chair,
            ["authconfirm" => 1, "code" => $code, "cancel" => 1])
            ->set_page("authorize");
        Qrequest::set_main_request($vq);
        $url = null;
        try {
            (new HotCRP\Authorize_Page($this->u_chair, $vq))->go();
        } catch (Redirection $redir) {
            $url = $redir->url;
        }
        xassert_str_starts_with($url ?? "", "https://dro.com/");
        xassert_eqq($this->redirect_error($url ?? ""), "access_denied");
    }

    /** An authorization code is a database row, so an anonymous request must
     * not be able to create one. The visitor signs in first and the request is
     * replayed on the way back. */
    function test_authorization_request_needs_a_user() {
        $client_id = $this->register_client("https://dro.com/");
        $param = [
            "client_id" => $client_id, "redirect_uri" => "https://dro.com/",
            "response_type" => "code", "state" => "S", "scope" => "read"
        ];

        $n0 = $this->count_codes();
        [$how, $detail] = $this->authorize_outcome($param, $this->u_empty);
        xassert_eqq($how, "redirect");
        xassert_str_contains($detail ?? "", "signin");
        // the request survives the round trip
        xassert_str_contains($detail ?? "", $client_id);
        xassert_eqq($this->count_codes(), $n0);

        // the same request from a signed-in user does create one
        [$how, $detail] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "code");
        xassert_eqq($this->count_codes(), $n0 + 1);
    }

    /** `state` and `nonce` are stored and echoed, so they are bounded. The
     * complaint stays on this site: the error response would have to echo the
     * oversized `state`. */
    function test_opaque_parameter_length_limit() {
        $param = [
            "client_id" => $this->register_client("https://dro.com/"),
            "redirect_uri" => "https://dro.com/", "response_type" => "code",
            "scope" => "read", "state" => str_repeat("A", 6000)
        ];
        $n0 = $this->count_codes();
        [$how, $detail] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "page");
        xassert_eqq($this->count_codes(), $n0);

        $param["state"] = "S";
        $param["nonce"] = str_repeat("A", 6000);
        [$how, $detail] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "page");
        xassert_eqq($this->count_codes(), $n0);
    }

    /** An authorization response answers a POST — the consent form — so its
     * redirect must not invite the user agent to repeat that POST at the
     * client. 307 would (RFC 9700 §4.12 forbids it) and 302 is only
     * conventionally understood not to; 303 says so. */
    function test_authorization_response_is_303() {
        $ruri = "https://dall.com/";
        $param = [
            "client_id" => $this->register_client($ruri), "redirect_uri" => $ruri,
            "response_type" => "code", "state" => "S", "scope" => "read"
        ];
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "code");

        $status = function ($vq) {
            try {
                (new HotCRP\Authorize_Page($this->u_chair, $vq))->go();
            } catch (Redirection $redir) {
                return $redir->status;
            }
            return null;
        };

        // the grant
        $vq = TestQreq::user_post($this->u_chair, ["authconfirm" => 1, "code" => $code])
            ->set_page("authorize");
        Qrequest::set_main_request($vq);
        xassert_eqq($status($vq), 303);

        // and the error responses that answer the same form
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        $vq = TestQreq::user_post($this->u_chair, ["authconfirm" => 1, "code" => $code, "cancel" => 1])
            ->set_page("authorize");
        Qrequest::set_main_request($vq);
        xassert_eqq($status($vq), 303);
    }

    /** An authorization URL can carry a code, and the consent page links to a
     * URL the client chose for itself, so the Referer must not follow the user
     * off-site (RFC 9700 §4.2.4). The header is site-wide; it is tested here
     * because this is what asks for it. */
    function test_referrer_policy() {
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        $emit = function () {
            Navigation::headers_reset();
            $this->conf->emit_browser_security_headers(TestQreq::get());
            foreach (Navigation::headers_list() as $h) {
                if (str_starts_with($h, "Referrer-Policy:"))
                    return trim(substr($h, 16));
            }
            return null;
        };
        try {
            // same-origin keeps the full URL for this site, which it reads to
            // mark a preferred account index, and sends nothing anywhere else
            xassert_eqq($emit(), "same-origin");
            $this->conf->set_opt("httpReferrerPolicy", "no-referrer");
            xassert_eqq($emit(), "no-referrer");
            $this->conf->set_opt("httpReferrerPolicy", false);
            xassert_eqq($emit(), null);
        } finally {
            $this->conf->set_opt("httpReferrerPolicy", null);
            Navigation::headers_reset();
            Navigation::$test_mode = $old_test_mode;
        }
    }

    /** The consent page links to the client's own URL, which a self-registered
     * client picked. The link carries nothing along: no Referer, and no handle
     * on this window. */
    function test_client_link_is_inert() {
        $client = HotCRP\OAuthClient::make((object) [
            "name" => "x", "title" => "Test Client", "client_id" => "cid",
            "client_uri" => "https://client.example.com/",
            "redirect_uris" => ["https://client.example.com/cb"]
        ]);
        xassert_neqq($client, null);
        $qreq = TestQreq::user_get($this->u_chair, [])->set_page("authorize");
        Qrequest::set_main_request($qreq);
        $ap = new HotCRP\Authorize_Page($this->u_chair, $qreq);
        $ap->client = $client;
        ob_start();
        try {
            $ap->print_form_title();
        } finally {
            $html = ob_get_clean();
        }
        xassert_str_contains($html, 'href="https://client.example.com/"');
        xassert_str_contains($html, 'rel="noopener noreferrer"');
    }

    /** Cancelling is an error response to the client's request, so it carries
     * that request's `state` (RFC 6749 §4.1.2.1). The confirmation form posts
     * no `state` of its own, so taking it from the request would drop it. */
    function test_cancel_reports_state() {
        [$how, $code] = $this->authorize_outcome([
            "client_id" => $this->register_client("https://dro.com/"),
            "redirect_uri" => "https://dro.com/", "response_type" => "code",
            "state" => "STATE123", "scope" => "read"
        ], $this->u_chair);
        xassert_eqq($how, "code");

        $vq = TestQreq::user_post($this->u_chair, ["authconfirm" => 1, "code" => $code, "cancel" => 1])
            ->set_page("authorize");
        Qrequest::set_main_request($vq);
        $url = null;
        try {
            (new HotCRP\Authorize_Page($this->u_chair, $vq))->go();
        } catch (Redirection $redir) {
            $url = $redir->url;
        }
        xassert_neqq($url, null);
        parse_str(parse_url($url ?? "", PHP_URL_QUERY) ?? "", $p);
        xassert_eqq($p["error"] ?? null, "access_denied");
        xassert_eqq($p["state"] ?? null, "STATE123");
    }

    /** Redeem an authorization code and return the token response.
     * @param string $code
     * @return object */
    private function redeem_code($code, $redirect_uri) {
        return call_api("=oauthtoken", $this->u_empty, TestQreq::post([
            "grant_type" => "authorization_code", "code" => $code,
            "redirect_uri" => $redirect_uri,
            "client_id" => $this->_last_client_id,
            "client_secret" => $this->_last_client_secret
        ]));
    }

    /** Confirm an authorization request and return the code it delivers.
     * @param string $code
     * @return ?string */
    private function confirm_code($code, $form_scope = null) {
        $param = ["authconfirm" => 1, "code" => $code];
        if ($form_scope !== null) {
            $param["scope"] = $form_scope;
        }
        $vq = TestQreq::user_post($this->u_chair, $param)
            ->set_page("authorize");
        Qrequest::set_main_request($vq);
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        ob_start();
        try {
            (new HotCRP\Authorize_Page($this->u_chair, $vq))->go();
            return null;
        } catch (Redirection $redir) {
            parse_str(parse_url($redir->url, PHP_URL_QUERY) ?? "", $p);
            return $p["code"] ?? null;
        } catch (PageCompletion $pc) {
            return null;
        } finally {
            ob_end_clean();
            Navigation::$test_mode = $old_test_mode;
        }
    }

    /** A code redeemed twice means the code reached someone else, so the
     * tokens it produced are revoked — however long after the first redemption
     * the replay arrives. Dismissing a late replay as an expired code would
     * leave the attacker's tokens live (RFC 9700 §4.2.4). */
    function test_authorization_code_replay_revokes_late() {
        $ruri = "https://dall.com/";
        $this->register_client($ruri);
        $param = [
            "client_id" => $this->_last_client_id, "redirect_uri" => $ruri,
            "response_type" => "code", "state" => "S", "scope" => "read"
        ];

        // redeemed, then replayed at once: revoked
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "code");
        $rcode = $this->confirm_code($code);
        $jr = $this->redeem_code($rcode, $ruri);
        xassert(isset($jr->access_token));
        $atok = $jr->access_token;
        xassert_eqq($this->redeem_code($rcode, $ruri)->error ?? null, "invalid_grant");
        xassert(!$this->find_token($atok)->is_active());

        // and a spent code is no longer offered by the consent page
        xassert_eqq($this->confirm_code($code), null);

        // redeemed, then replayed long after the code stopped being offerable:
        // still revoked
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "code");
        $rcode = $this->confirm_code($code);
        $jr = $this->redeem_code($rcode, $ruri);
        xassert(isset($jr->access_token));
        $atok = $jr->access_token;
        xassert(!!$this->find_token($atok)->is_active());

        $now = Conf::$now;
        Conf::set_current_time($now + 900);
        try {
            $jr = $this->redeem_code($rcode, $ruri);
            xassert_eqq($jr->error ?? null, "invalid_grant");
            xassert(!$this->find_token($atok)->is_active());
        } finally {
            Conf::set_current_time($now);
        }
    }

    /** The consent form’s scope field narrows API access — that is what its
     * label says, and it is prefilled with the client’s API scope, so a user
     * who submits the form unchanged posts a value with no `openid` in it. The
     * OpenID Connect scopes come from the authorization request and name no API
     * rights, so they must survive the narrowing; `TokenScope` models API bits
     * alone and cannot reproduce them from the bits. Losing them silently
     * withholds the `id_token` the client asked for. */
    function test_authconfirm_keeps_openid_scope() {
        $ruri = "https://dall.com/";
        $this->register_client($ruri);
        $param = [
            "client_id" => $this->_last_client_id, "redirect_uri" => $ruri,
            "response_type" => "code", "state" => "S", "scope" => "openid read"
        ];

        // the browser form posts the client's API scope, without `openid`
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "code");
        $rcode = $this->confirm_code($code, "read");
        xassert_neqq($rcode, null);
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("scope"), "openid read");
        $jr = $this->redeem_code($rcode, $ruri);
        xassert(isset($jr->id_token));
        xassert_eqq($jr->_token ?? null, null);

        // narrowing to nothing leaves sign-in alone, and does not leave a
        // stray `none` beside it
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        $rcode = $this->confirm_code($code, "none");
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("scope"), "openid");
        $jr = $this->redeem_code($rcode, $ruri);
        xassert(isset($jr->id_token));

        // a request with no OpenID Connect scope still narrows as before
        $param["scope"] = "read write";
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        $rcode = $this->confirm_code($code, "read");
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("scope"), "read");
        $jr = $this->redeem_code($rcode, $ruri);
        xassert(!isset($jr->id_token));

        // and the field only limits: `write` covers the read bits, so
        // intersecting it with a `read` request still leaves `read`
        $param["scope"] = "openid read";
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        $rcode = $this->confirm_code($code, "write");
        $scope = TokenInfo::find($code, $this->conf)->data("scope");
        xassert_eqq($scope, "openid read");
        xassert(!TokenScope::scope_str_contains($scope, "write"));
        $jr = $this->redeem_code($rcode, $ruri);
        xassert_eqq($this->find_token($jr->access_token)->data("scope"), "read");
    }

    /** Users reload and go back. None of that may cost them a working grant.
     *
     * A code is single-use at the token endpoint, so the consent page must
     * stop offering one that has been redeemed: re-delivering it would make
     * the client redeem it twice, and the second redemption revokes the tokens
     * of the first. Everything before redemption stays idempotent, so a
     * double-clicked or resubmitted consent form is harmless. */
    function test_reload_does_not_break_a_grant() {
        $ruri = "https://dall.com/";
        $this->register_client($ruri);
        $param = [
            "client_id" => $this->_last_client_id, "redirect_uri" => $ruri,
            "response_type" => "code", "state" => "S", "scope" => "openid read"
        ];
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "code");

        // before redemption everything repeats: the form re-delivers the same
        // code, and the consent page still renders
        $rcode = $this->confirm_code($code, "read");
        xassert_eqq($this->confirm_code($code, "read"), $rcode);
        xassert_eqq($this->authorize_outcome(["code" => $code], $this->u_chair)[0], "code");

        $jr = $this->redeem_code($rcode, $ruri);
        xassert(isset($jr->access_token));
        xassert(isset($jr->id_token));
        $atok = $jr->access_token;

        // after redemption the page refuses, and blames the request rather
        // than falling through to complain about a missing client
        [$how, $detail] = $this->authorize_outcome(["code" => $code], $this->u_chair);
        xassert_eqq($how, "page");
        xassert_str_contains($detail ?? "", "authorization request");
        xassert(!str_contains($detail ?? "", "client missing"));
        xassert_eqq($this->confirm_code($code, "read"), null);

        // ...and none of it disturbed the grant the user actually has
        xassert(!!$this->find_token($atok)->is_active());

        // returning to the original authorization URL starts a fresh request,
        // which is what a back button most often lands on
        [$how, $code2] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "code");
        xassert_neqq($code2, $code);
        xassert(!!$this->find_token($atok)->is_active());
    }

    /** Changing conference settings is administration, not writing, so it
     * needs `settings:admin`. `write` keeps meaning “write anything I can
     * write”, and still reads settings. */
    function test_settings_post_needs_admin_scope() {
        $wtok = $this->dynamic_client_token("https://dall.com/", $this->u_chair, ["scope" => "write"]);
        xassert_eqq(call_api_result("settings", $wtok, [])->response_code(), 200);
        $jr = call_api_result("=settings", $wtok, []);
        xassert_eqq($jr->response_code(), 401);
        // the refusal names the scope to ask for
        xassert_str_contains($jr->header("WWW-Authenticate") ?? "", 'scope="settings:admin"');

        $atok = $this->dynamic_client_token("https://dall.com/", $this->u_chair, ["scope" => "settings:admin"]);
        xassert_neqq(call_api_result("=settings", $atok, [])->response_code(), 401);

        // settings is its own family: administering everything else is not
        // enough, and reading is a separate grant from `read`
        $otok = $this->dynamic_client_token("https://dall.com/", $this->u_chair, ["scope" => "other:admin"]);
        xassert_eqq(call_api_result("=settings", $otok, [])->response_code(), 401);
        $rtok = $this->dynamic_client_token("https://dall.com/", $this->u_chair, ["scope" => "settings:read"]);
        xassert_eqq(call_api_result("settings", $rtok, [])->response_code(), 200);
        xassert_eqq(call_api_result("=settings", $rtok, [])->response_code(), 401);
    }

    /** A 401 has to say how to authenticate (RFC 6750 §3). This is the 401 a
     * client gets when its token expires, and the `resource_metadata` in the
     * header is how it finds the authorization server again (RFC 9728), so a
     * silent 401 leaves an expired client with nowhere to go. */
    function test_expired_bearer_401_says_how_to_authenticate() {
        $qreq = TestQreq::get([])->set_conf($this->conf)
            ->set_page("api")->set_path("/whoami")
            ->set_header("Authorization", "Bearer hct_" . str_repeat("z", 30));
        Qrequest::set_main_request($qreq);
        $jr = null;
        try {
            initialize_user($qreq, ["bearer" => true]);
        } catch (JsonCompletion $jc) {
            $jr = $jc->result;
        }
        xassert_neqq($jr, null);
        xassert_eqq($jr->response_code(), 401);
        $h = $jr->header("WWW-Authenticate") ?? "";
        xassert_str_contains($h, "Bearer ");
        xassert_str_contains($h, 'error="invalid_token"');
        xassert_str_contains($h, "resource_metadata=");
    }

    /** HotCRP signs in through a provider with PKCE (RFC 9700 §2.1.1). The
     * verifier stays here; only its hash travels. */
    function test_outbound_pkce() {
        $start = function ($pkce) {
            $base = [
                "name" => "p", "client_id" => "C", "client_secret" => "S",
                "auth_uri" => "https://idp.example.com/auth",
                "token_uri" => "https://idp.example.com/token",
                "redirect_uri" => "https://conf.example.com/oauth"
            ];
            if ($pkce !== null) {
                $base["pkce"] = $pkce;
            }
            $this->conf->set_opt("oAuthProviders", [(object) $base]);
            $this->conf->refresh_settings();
            $qreq = TestQreq::user_get($this->u_chair, ["authtype" => "p"])->set_page("oauth");
            Qrequest::set_main_request($qreq);
            try {
                (new HotCRP\OAuth_Page($this->u_chair, $qreq))->start();
            } catch (Redirection $redir) {
                parse_str(parse_url($redir->url, PHP_URL_QUERY) ?? "", $p);
                return $p;
            }
            return null;
        };

        try {
            $p = $start(null) ?? [];
            xassert_eqq($p["code_challenge_method"] ?? null, "S256");
            // the challenge is the hash of the verifier this site kept
            $tok = TokenInfo::find_from($p["state"], $this->conf, !!$this->conf->contactdb());
            $cv = $tok->data("code_verifier");
            xassert_eqq(strlen($cv), 43);
            xassert_eqq($p["code_challenge"], base64url_encode(hash("sha256", $cv, true)));
            // ...and the verifier itself never leaves
            xassert(!str_contains(join(" ", $p), $cv));

            // a provider that cannot take it can be excused
            $p = $start(false) ?? [];
            xassert(!isset($p["code_challenge"]));
            xassert(!isset($p["code_challenge_method"]));
            $tok = TokenInfo::find_from($p["state"], $this->conf, !!$this->conf->contactdb());
            xassert_eqq($tok->data("code_verifier"), null);
        } finally {
            $this->conf->set_opt("oAuthProviders", null);
            $this->conf->refresh_settings();
        }
    }

    /** Sign in through a provider, from the redirect out to the account that
     * comes back. The token endpoint is stubbed, so this covers the half of
     * `OAuth_Page` that talks to the network: the `code_verifier` it sends and
     * the ID token it accepts. */
    function test_inbound_signin() {
        $this->conf->set_opt("oAuthProviders", [(object) [
            "name" => "p", "client_id" => "C", "client_secret" => "S",
            "issuer" => "https://idp.example.com",
            "auth_uri" => "https://idp.example.com/auth",
            "token_uri" => "https://idp.example.com/token",
            "redirect_uri" => "https://conf.example.com/oauth"
        ]]);
        $this->conf->refresh_settings();
        $email = "oauthy@hotcrp-oauth.org";
        $seen = null;

        try {
            // 1. start: keep the session, and learn the state
            $q1 = TestQreq::user_get($this->u_empty, ["authtype" => "p"])
                ->set_page("oauth");
            $qs = $q1->qsession();
            $old_qsid = $qs->sid;
            Qrequest::set_main_request($q1);
            $auth = null;
            try {
                (new HotCRP\OAuth_Page($this->u_empty, $q1))->start();
            } catch (Redirection $redir) {
                $auth = $redir->url;
            }
            xassert_neqq($auth, null);
            parse_str(parse_url($auth ?? "", PHP_URL_QUERY) ?? "", $ap);
            $tok = TokenInfo::find_from($ap["state"], $this->conf, !!$this->conf->contactdb());
            xassert_neqq($tok, null);

            // 2. the provider answers, and we record what it was asked
            HotCRP\OAuth_Page::$fetch_function = function ($authi, $param) use (&$seen, $ap, $email) {
                $seen = $param;
                $idt = HotCRP\JWTParser::make_plaintext((object) [
                    "iss" => "https://idp.example.com", "aud" => "C",
                    "exp" => Conf::$now + 600, "iat" => Conf::$now,
                    "nonce" => $ap["nonce"], "sub" => "u1",
                    "email" => $email, "email_verified" => true,
                    "given_name" => "Oona", "family_name" => "Authy"
                ]);
                return [200, json_encode(["id_token" => $idt])];
            };

            // 3. the callback, on the same session and with the nonce cookie
            $_COOKIE["hotcrp-oauth-nonce-" . $ap["nonce"]] = "1";
            $q2 = TestQreq::user_get($this->u_empty,
                    ["code" => "CODE", "state" => $ap["state"]], $qs)
                ->set_page("oauth");
            Qrequest::set_main_request($q2);
            $oap = new HotCRP\OAuth_Page($this->u_empty, $q2);
            $ml = $oap->response();

            xassert($oap->success);
            xassert_eqq($oap->email, $email);
            // a session that predates the login must not carry the identity it
            // produced, as `LoginHelper::login_complete` ensures elsewhere
            xassert($qs->sid !== $old_qsid);
            // the verifier it kept is the verifier it sent
            xassert_eqq($seen["code_verifier"] ?? null, $tok->data("code_verifier"));
            xassert_eqq($seen["grant_type"] ?? null, "authorization_code");
            // and the account exists now
            $u = $this->conf->fresh_user_by_email($email);
            xassert_neqq($u, null);
            xassert_eqq($u->firstName, "Oona");

            // a replayed authorization response finds no request
            Qrequest::set_main_request($q2);
            $ml = (new HotCRP\OAuth_Page($this->u_empty, $q2))->response();
            xassert_str_contains(join(" ", array_map(function ($mi) { return $mi->message; },
                is_array($ml) ? $ml : [$ml])), "not found");
        } finally {
            HotCRP\OAuth_Page::$fetch_function = null;
            unset($_COOKIE["hotcrp-oauth-nonce-" . ($ap["nonce"] ?? "")]);
            $this->conf->set_opt("oAuthProviders", null);
            $this->conf->refresh_settings();
        }
    }

    /** Pages that authenticate a user or authorize a client must not be
     * framable (RFC 9700 §4.16). A site that sets its own
     * `httpContentSecurityPolicy` — for a `script-src`, say — must not thereby
     * lose the frame protection on them. */
    /** Render page `$page` as `$user` does, and return the headers it set.
     * @return list<string> */
    private function page_headers($page, $user) {
        $qreq = TestQreq::user_get($user, [])->set_page($page);
        Qrequest::set_main_request($qreq);
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        Navigation::headers_reset();
        ob_start();
        try {
            $pc = $this->conf->page_components($user, $qreq);
            $pagej = $pc->get($page);
            xassert_neqq($pagej, null);
            $pc->print_body_members($pagej->group);
        } catch (Redirection $redir) {
        } catch (JsonCompletion $jc) {
        } catch (PageCompletion $pcx) {
        } finally {
            ob_end_clean();
            $h = Navigation::headers_list();
            Navigation::headers_reset();
            Navigation::$test_mode = $old_test_mode;
        }
        return $h;
    }

    function test_no_frame_pages() {
        // every page that takes a credential or grants access says so itself
        foreach (["authorize", "signin", "oauth", "newaccount",
                  "forgotpassword", "resetpassword"] as $page) {
            $h = $this->page_headers($page, $this->u_empty);
            xassert(in_array("X-Frame-Options: DENY", $h, true), "page {$page}");
            xassert(in_array("Referrer-Policy: same-origin", $h, true), "page {$page}");
        }
        $h = $this->page_headers("manageemail", $this->u_chair);
        xassert(in_array("X-Frame-Options: DENY", $h, true));
        $h = $this->page_headers("index", $this->u_chair);
        xassert(!in_array("X-Frame-Options: DENY", $h, true));

        // and the headers bind whatever the site policy is
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        try {
            foreach ([null, "script-src 'self'", false] as $csp) {
                $this->conf->set_opt("httpContentSecurityPolicy", $csp);
                // these URLs carry `code=`, so the site policy does not get to
                // loosen the referrer either
                $this->conf->set_opt("httpReferrerPolicy", "unsafe-url");
                Navigation::headers_reset();
                $this->conf->emit_browser_security_headers(TestQreq::get()->set_page("authorize"));
                $this->conf->emit_credential_page_headers();
                $h = Navigation::headers_list();
                xassert(in_array("Content-Security-Policy: frame-ancestors 'none'", $h, true));
                xassert(in_array("X-Frame-Options: DENY", $h, true));
                xassert(in_array("Referrer-Policy: same-origin", $h, true));
            }
        } finally {
            $this->conf->set_opt("httpContentSecurityPolicy", null);
            $this->conf->set_opt("httpReferrerPolicy", null);
            Navigation::headers_reset();
            Navigation::$test_mode = $old_test_mode;
        }
    }

    /** A backslash is not an RFC 3986 character, and a browser reads it as the
     * `/` that ends an authority — so `http://evil.com\@localhost/` is
     * `localhost` to `strcspn` and `parse_url`, and `evil.com` to the browser
     * that follows the redirect. Everything downstream, including the host the
     * consent page shows the user, would be about the wrong host. */
    function test_redirect_uri_rejects_backslash() {
        // every client-supplied redirect URI enters at `VALIDATION_DYNAMIC`,
        // which is where the character check lives
        $ck = function ($u) {
            return HotCRP\OAuthClient::check_redirect_uri($u, HotCRP\OAuthClient::VALIDATION_DYNAMIC);
        };
        // the browser's host is not HotCRP's host for any of these
        xassert(!$ck("http://evil.example\\@localhost/cb"));
        xassert(!$ck("http://evil.example\\@127.0.0.1:5173/cb"));
        xassert(!$ck("https://evil.example\\@good.example/cb"));
        xassert(!$ck("https://good.example/cb\\x"));
        // as are the other characters that check excludes
        xassert(!$ck("https://good.example/c b"));
        xassert(!$ck("https://good.example/cb\x7f"));
        xassert(!$ck("https://good.example/cb\u{00e9}"));
        // ...and the ordinary forms still pass, at either level
        foreach (["http://localhost/cb", "http://127.0.0.1:5173/cb",
                  "https://good.example/cb", "https://good.example/cb?x=1",
                  "https://good.example/[cb]"] as $u) {
            xassert($ck($u), $u);
            xassert(HotCRP\OAuthClient::check_redirect_uri($u), $u);
        }
    }

    /** Redeeming a code twice means it reached someone else, so everything it
     * produced is revoked — including whatever the first pair has rotated into.
     * Revoking only the first link leaves the live tokens working, which is the
     * outcome the revocation exists to stop (RFC 9700 §4.2.4). */
    function test_code_replay_revokes_the_whole_chain() {
        $ruri = "https://dall.com/";
        $this->register_client($ruri);
        $param = [
            "client_id" => $this->_last_client_id, "redirect_uri" => $ruri,
            "response_type" => "code", "state" => "S", "scope" => "read"
        ];
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "code");
        $rcode = $this->confirm_code($code, "read");

        // whoever holds the code redeems it, then rotates once
        $t0 = $this->redeem_code($rcode, $ruri);
        xassert(isset($t0->access_token));
        $t1 = call_api("=oauthtoken", $this->u_empty, TestQreq::post([
            "grant_type" => "refresh_token", "refresh_token" => $t0->refresh_token,
            "client_id" => $this->_last_client_id,
            "client_secret" => $this->_last_client_secret]));
        xassert(isset($t1->access_token));
        xassert(!!$this->find_token($t1->access_token)->is_active());

        // the other party redeems the same code: the rotated pair dies too
        xassert_eqq($this->redeem_code($rcode, $ruri)->error ?? null, "invalid_grant");
        xassert(!$this->find_token($t1->access_token)->is_active());
        xassert(!$this->find_token($t1->refresh_token)->is_active());
        xassert_eqq(call_api_result("whoami", $this->find_token($t1->access_token), [])->response_code(), 401);
    }

    /** An OpenID Connect scope names no API rights, so a selector on one adds
     * none. The `-2` sentinel is `~1`, so storing it as a bitmask would grant
     * every right but `S_SUB_READ` on the selected papers — a token whose
     * scope reads as identity-only. */
    function test_openid_scope_with_selector_grants_nothing() {
        foreach (["openid#1", "email#12", "profile?q=tag:x", "address#1", "phone#1"] as $s) {
            $ts = TokenScope::parse($s, null);
            xassert_eqq(TokenScope::unparse($ts), "none", $s);
            xassert(!$ts->allows_some(TokenScope::S_SUB_ADMIN));
            xassert(!$ts->allows_some(TokenScope::S_REV_READ));
        }
        // a selector cannot widen through intersection either
        xassert_eqq(TokenScope::unparse(
            TokenScope::intersect(TokenScope::parse("all#1", null), "openid#1")), "none");
        // ...and real selectors are unaffected
        xassert_eqq(TokenScope::unparse(TokenScope::parse("all#12", null)), "all#12");
        xassert_eqq(TokenScope::unparse(TokenScope::parse("submission:admin#r1", null)), "submission:admin#r1");
        xassert_eqq(TokenScope::unparse(TokenScope::parse("openid read#3", null)), "read#3");
    }

    /** A disabled provider hides its sign-in button. It must not still
     * authenticate for anyone who names it in the URL. */
    function test_disabled_provider() {
        $mk = function ($disabled, $extra = null) {
            $p = ["name" => "p", "client_id" => "C", "client_secret" => "S",
                  "auth_uri" => "https://idp.example.com/auth",
                  "token_uri" => "https://idp.example.com/token",
                  "redirect_uri" => "https://conf.example.com/oauth"];
            if ($disabled) {
                $p["disabled"] = true;
            }
            $list = $extra ? [(object) $p, (object) $extra] : [(object) $p];
            $this->conf->set_opt("oAuthProviders", $list);
            $this->conf->refresh_settings();
        };
        try {
            $mk(false);
            xassert_neqq(HotCRP\OAuthProvider::find($this->conf, "p"), null);
            $mk(true);
            xassert_eqq(HotCRP\OAuthProvider::find($this->conf, "p"), null);
            // nor may it be the default for a bare /oauth
            xassert_eqq(HotCRP\OAuthProvider::find($this->conf, null), null);
            // ...while a live provider later in the list still is
            $mk(true, ["name" => "q", "client_id" => "C", "client_secret" => "S",
                       "auth_uri" => "https://q.example.com/auth",
                       "token_uri" => "https://q.example.com/token",
                       "redirect_uri" => "https://conf.example.com/oauth"]);
            $q = HotCRP\OAuthProvider::find($this->conf, null);
            xassert_neqq($q, null);
            xassert_eqq($q->name ?? null, "q");
        } finally {
            $this->conf->set_opt("oAuthProviders", null);
            $this->conf->refresh_settings();
        }
    }

    /** `expires_in` must be how long the token works, not how long its row is
     * retained; a client that refreshes on the latter holds a dead token for
     * the retention period. */
    function test_expires_in_is_the_usable_lifetime() {
        $ruri = "https://dall.com/";
        $this->register_client($ruri);
        [$how, $code] = $this->authorize_outcome([
            "client_id" => $this->_last_client_id, "redirect_uri" => $ruri,
            "response_type" => "code", "state" => "S", "scope" => "read"
        ], $this->u_chair);
        xassert_eqq($how, "code");
        $jr = $this->redeem_code($this->confirm_code($code, "read"), $ruri);
        xassert(isset($jr->access_token));
        $tok = $this->find_token($jr->access_token);
        xassert_eqq($jr->expires_in, $tok->timeInvalid - Conf::$now);
        xassert(($jr->expires_in ?? 0) <= 3600);
    }

    /** The client metadata document is fetched from a URL the requester chose,
     * so an anonymous request must not be able to make this site issue it. */
    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_metadata_fetch_needs_a_user() {
        $fetched = 0;
        $old = HotCRP\OAuthClientDocument::$fetch_function;
        HotCRP\OAuthClientDocument::$fetch_function = function ($url) use (&$fetched, $old) {
            ++$fetched;
            return $old ? $old($url) : null;
        };
        try {
            $param = ["client_id" => self::MDOC_CLIENT_ID,
                      "redirect_uri" => self::MDOC_REDIRECT_URI,
                      "response_type" => "code", "state" => "S", "scope" => "read"];
            [$how, $detail] = $this->authorize_outcome($param, $this->u_empty);
            xassert_eqq($how, "redirect");
            xassert_str_contains($detail ?? "", "signin");
            xassert_eqq($fetched, 0);

            // a signed-in user still gets the document fetched
            $this->authorize_outcome($param, $this->u_chair);
            xassert_gt($fetched, 0);
        } finally {
            HotCRP\OAuthClientDocument::$fetch_function = $old;
        }
    }

    /** Confirming an account means the user proved it just now. The provider
     * must say when it authenticated them, and the window must be fixed when
     * the request starts — otherwise a silent SSO round trip, with a `max_age`
     * of the attacker's choosing, satisfies the gate that guards email changes
     * and password changes. */
    function test_reauth_requires_a_fresh_auth_time() {
        $this->conf->set_opt("oAuthProviders", [(object) [
            "name" => "p", "client_id" => "C", "client_secret" => "S",
            "issuer" => "https://idp.example.com",
            "auth_uri" => "https://idp.example.com/auth",
            "token_uri" => "https://idp.example.com/token",
            "redirect_uri" => "https://conf.example.com/oauth"
        ]]);
        $this->conf->refresh_settings();
        $old = HotCRP\OAuth_Page::$fetch_function;

        /** @return array{bool,array} */
        $run = function ($startargs, $auth_time) {
            $q1 = TestQreq::user_get($this->u_chair, $startargs + ["reauth" => 1])
                ->set_page("oauth");
            $qs = $q1->qsession();
            Qrequest::set_main_request($q1);
            $auth = null;
            try {
                (new HotCRP\OAuth_Page($this->u_chair, $q1))->start();
            } catch (Redirection $redir) {
                $auth = $redir->url;
            }
            parse_str(parse_url($auth ?? "", PHP_URL_QUERY) ?? "", $ap);
            HotCRP\OAuth_Page::$fetch_function = function ($authi, $param) use ($ap, $auth_time) {
                $c = ["iss" => "https://idp.example.com", "aud" => "C",
                      "exp" => Conf::$now + 600, "iat" => Conf::$now,
                      "nonce" => $ap["nonce"], "sub" => "u1",
                      "email" => "chair@_.com", "email_verified" => true];
                if ($auth_time !== null) {
                    $c["auth_time"] = $auth_time;
                }
                return [200, json_encode(["id_token" => HotCRP\JWTParser::make_plaintext((object) $c)])];
            };
            $_COOKIE["hotcrp-oauth-nonce-" . $ap["nonce"]] = "1";
            $q2 = TestQreq::user_get($this->u_chair, ["code" => "C", "state" => $ap["state"]], $qs)
                ->set_page("oauth");
            Qrequest::set_main_request($q2);
            $oap = new HotCRP\OAuth_Page($this->u_chair, $q2);
            $oap->response();
            unset($_COOKIE["hotcrp-oauth-nonce-" . $ap["nonce"]]);
            return [$oap->success, $ap];
        };

        try {
            // the request asks the provider to authenticate again and to say when
            [$ok, $ap] = $run(["max_age" => "600"], Conf::$now);
            xassert($ok);
            xassert_eqq($ap["prompt"] ?? null, "login");
            xassert_eqq($ap["max_age"] ?? null, "600");

            // a provider that omits `auth_time` confirms nothing
            [$ok, ] = $run(["max_age" => "600"], null);
            xassert(!$ok);

            // nor does a stale one
            [$ok, ] = $run(["max_age" => "600"], Conf::$now - 4000);
            xassert(!$ok);

            // and the window cannot be widened from the request
            [$ok, $ap] = $run(["max_age" => "999999"], Conf::$now - 100000);
            xassert_eqq($ap["max_age"] ?? null, "3600");
            xassert(!$ok);

            // omitting `max_age` gives the tightest window, not the loosest
            [$ok, $ap] = $run([], Conf::$now - 100000);
            xassert_eqq($ap["max_age"] ?? null, "0");
            xassert(!$ok);
        } finally {
            HotCRP\OAuth_Page::$fetch_function = $old;
            $this->conf->set_opt("oAuthProviders", null);
            $this->conf->refresh_settings();
        }
    }

    /** A code is good once. The check has to be atomic: two redemptions in
     * flight at the same time would both read an unconsumed code and both
     * succeed, and the second one is the signal that the code leaked. */
    function test_token_consume_is_atomic() {
        $tok = (new TokenInfo($this->conf, TokenInfo::OAUTHCODE))
            ->set_token_pattern("hcoc[36]")
            ->set_expires_in(3600)
            ->insert();
        xassert(!!$tok->salt);
        // two requests holding the same row, neither aware of the other
        $t1 = TokenInfo::find($tok->salt, $this->conf);
        $t2 = TokenInfo::find($tok->salt, $this->conf);
        xassert($t1->consume());
        xassert(!$t2->consume());
        xassert(!$t1->consume());
        $tok->delete();
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
            $qreq = TestQreq::user_get($this->u_chair, $rest + [
                "client_id" => "nosecret-client",
                "redirect_uri" => "https://nosecret.example.com/cb",
                "response_type" => "code", "state" => "S", "scope" => "read"
            ])->set_page("authorize");
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
                $vq = TestQreq::user_post($this->u_chair, ["authconfirm" => 1, "code" => $code])
                    ->set_page("authorize");
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

    /** A dynamic component's redirect-URI allowlist may be spelled either way,
     * as `OAuthClient` accepts both. Reading only the plural turns a component
     * written with the singular into an unrestricted registration endpoint. */
    function test_dynamic_registration_allowlist_spellings() {
        $old = $this->conf->opt("oAuthClients");
        $try = function ($cx, $uri) {
            $this->conf->set_opt("oAuthClients", [(object) $cx]);
            $this->conf->refresh_settings();
            $jr = call_api("=oauthregister", $this->u_empty,
                TestQreq::post_json(["redirect_uris" => [$uri]]));
            return isset($jr->client_id);
        };
        foreach ([["redirect_uris" => ["https://ok.example.com/cb"]],
                  ["redirect_uris" => "https://ok.example.com/cb"],
                  ["redirect_uri" => "https://ok.example.com/cb"],
                  ["redirect_uri" => ["https://ok.example.com/cb"]]] as $allow) {
            $cx = $allow + ["name" => "d1", "dynamic" => true, "scope" => "read"];
            xassert($try($cx, "https://ok.example.com/cb"));
            xassert(!$try($cx, "https://evil.example.com/cb"));
        }

        // an allowlist this site cannot parse restricts to nothing
        xassert(!$try(["name" => "d1", "dynamic" => true, "scope" => "read",
                       "redirect_uris" => 17], "https://evil.example.com/cb"));
        // only an absent key means "no restriction"
        xassert($try(["name" => "d1", "dynamic" => true, "scope" => "read"],
                     "https://anywhere.example.com/cb"));

        $this->conf->set_opt("oAuthClients", $old);
        $this->conf->refresh_settings();
    }

    /** The consent form's scope box starts out holding the scope this request
     * asked for, so that leaving it alone grants what it displays. */
    function test_consent_scope_box_shows_the_request() {
        // read the box's value attribute out of the rendered consent page
        $html = function ($client_id, $redirect_uri, $scope) {
            $qreq = TestQreq::user_get($this->u_chair, [
                "client_id" => $client_id, "redirect_uri" => $redirect_uri,
                "response_type" => "code", "state" => "S", "scope" => $scope
            ])->set_page("authorize");
            Qrequest::set_main_request($qreq);
            $old_test_mode = Navigation::$test_mode;
            Navigation::$test_mode = 2;
            ob_start();
            try {
                // The consent page renders only with a component set; without
                // one `print_form` short-circuits to the JSON code response.
                // The form's own components resolve through the component set,
                // so they must find this instance, not a fresh one.
                $cs = $this->conf->page_components($this->u_chair, $qreq);
                $ap = new HotCRP\Authorize_Page($this->u_chair, $qreq, $cs);
                $cs->set_callable("HotCRP\\Authorize_Page", $ap);
                $ap->go();
            } catch (PageCompletion $pc) {
            } finally {
                $t = ob_get_clean();
                Navigation::$test_mode = $old_test_mode;
            }
            $this->_last_page_html = $t;
            if (preg_match('/<input[^>]*name="scope"[^>]*>/', $t, $m)
                && preg_match('/value="([^"]*)"/', $m[0], $m2)) {
                return html_entity_decode($m2[1]);
            }
            return null;
        };

        // a hand-registered client's request scope was never displayed before
        xassert_eqq($html("confclient", "https://conf1.example.com/cb", "openid read"),
                    "read");
        // the consent form closes, with or without the scope block
        xassert_eqq(substr_count($this->_last_page_html ?? "", "<form"), 1);
        xassert_eqq(substr_count($this->_last_page_html ?? "", "</form>"), 1);
        xassert_eqq($html("confclient", "https://conf1.example.com/cb", "openid"), "none");
        xassert_eqq(substr_count($this->_last_page_html ?? "", "</form>"), 1);
        // a dynamic client's registration scope is not this request's scope
        xassert_eqq($html($this->register_client("https://dall.com/"),
                          "https://dall.com/", "write"), "write");
        // and the seeded value round-trips: granting it changes nothing
        $code = null;
        [$how, $code] = $this->authorize_outcome([
            "client_id" => $this->register_client("https://dall.com/"),
            "redirect_uri" => "https://dall.com/", "response_type" => "code",
            "state" => "S", "scope" => "openid read"
        ], $this->u_chair);
        xassert_eqq($how, "code");
        $vq = TestQreq::user_post($this->u_chair,
            ["authconfirm" => 1, "code" => $code, "scope" => "openid read"])
            ->set_page("authorize");
        Qrequest::set_main_request($vq);
        try {
            (new HotCRP\Authorize_Page($this->u_chair, $vq))->go();
        } catch (Redirection $redir) {
        }
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("scope"), "openid read");
    }

    /** `validate` must not take its algorithm from the message it is checking
     * (RFC 8725 §2.1, §3.1). */
    function test_jwt_algorithm_confusion() {
        $payload = (object) ["iss" => "https://idp.example.com", "sub" => "u1"];
        $secret = "sekrit";
        $mac = HotCRP\JWTParser::make_mac($payload, $secret);
        $plain = HotCRP\JWTParser::make_plaintext($payload);

        // with no key, an unsecured message is accepted: this client trusts TLS
        $jwt = new HotCRP\JWTParser;
        xassert_neqq($jwt->validate($plain), null);

        // with a key, it is not: a key means a signature is required
        $jwt = new HotCRP\JWTParser;
        $jwt->verify_key = $secret;
        xassert_eqq($jwt->validate($plain), null);
        xassert_neqq($jwt->validate($mac), null);

        // a PEM public key is not an HMAC secret; anyone holding it could
        // otherwise sign a token this site would believe
        $pem = (new HotCRP\JWTParser)->jwk_to_pem((object) [
            "kty" => "RSA", "n" => base64url_encode(str_repeat("\xc5", 256)),
            "e" => base64url_encode("\x01\x00\x01")
        ]);
        xassert_neqq($pem, null);
        $jwt = new HotCRP\JWTParser;
        $jwt->verify_key = $pem;
        xassert_eqq($jwt->validate(HotCRP\JWTParser::make_mac($payload, $pem)), null);

        // and a caller that knows what it expects can say so
        $jwt = (new HotCRP\JWTParser)->set_algorithms(["RS256"]);
        $jwt->verify_key = $secret;
        xassert_eqq($jwt->validate($mac), null);
        $jwt = (new HotCRP\JWTParser)->set_algorithms(["HS256"]);
        $jwt->verify_key = $secret;
        xassert_neqq($jwt->validate($mac), null);
    }

    /** The `email` claim becomes an account key, so it gets the same
     * normalization as every other sign-in path. */
    function test_oauth_email_is_normalized() {
        $this->conf->set_opt("oAuthProviders", [(object) [
            "name" => "p", "client_id" => "C", "client_secret" => "S",
            "issuer" => "https://idp.example.com",
            "auth_uri" => "https://idp.example.com/auth",
            "token_uri" => "https://idp.example.com/token",
            "redirect_uri" => "https://conf.example.com/oauth"
        ]]);
        $this->conf->refresh_settings();
        $old = HotCRP\OAuth_Page::$fetch_function;

        /** @return array{bool,?string} */
        $run = function ($claim) {
            $q1 = TestQreq::user_get($this->u_empty, ["authtype" => "p"])
                ->set_page("oauth");
            $qs = $q1->qsession();
            Qrequest::set_main_request($q1);
            $auth = null;
            try {
                (new HotCRP\OAuth_Page($this->u_empty, $q1))->start();
            } catch (Redirection $redir) {
                $auth = $redir->url;
            }
            parse_str(parse_url($auth ?? "", PHP_URL_QUERY) ?? "", $ap);
            HotCRP\OAuth_Page::$fetch_function = function ($authi, $param) use ($ap, $claim) {
                return [200, json_encode(["id_token" => HotCRP\JWTParser::make_plaintext((object) [
                    "iss" => "https://idp.example.com", "aud" => "C",
                    "exp" => Conf::$now + 600, "iat" => Conf::$now,
                    "nonce" => $ap["nonce"], "sub" => "u1",
                    "email" => $claim, "email_verified" => true
                ])])];
            };
            $_COOKIE["hotcrp-oauth-nonce-" . $ap["nonce"]] = "1";
            $q2 = TestQreq::user_get($this->u_empty, ["code" => "C", "state" => $ap["state"]], $qs)
                ->set_page("oauth");
            Qrequest::set_main_request($q2);
            $oap = new HotCRP\OAuth_Page($this->u_empty, $q2);
            $oap->response();
            unset($_COOKIE["hotcrp-oauth-nonce-" . $ap["nonce"]]);
            return [$oap->success, $oap->email];
        };

        try {
            // surrounding whitespace names the same account, not a new one
            [$ok, $email] = $run("  oauthnorm@hotcrp-oauth.org\t");
            xassert($ok);
            xassert_eqq($email, "oauthnorm@hotcrp-oauth.org");
            xassert_neqq($this->conf->fresh_user_by_email("oauthnorm@hotcrp-oauth.org"), null);

            // an email with an interior space is not a key
            [$ok, ] = $run("oauth norm@hotcrp-oauth.org");
            xassert(!$ok);

            // nor is one the `email` column would have to truncate
            [$ok, ] = $run(str_repeat("a", 115) . "@hotcrp-oauth.org");
            xassert(!$ok);

            [$ok, ] = $run("");
            xassert(!$ok);
        } finally {
            HotCRP\OAuth_Page::$fetch_function = $old;
            $this->conf->set_opt("oAuthProviders", null);
            $this->conf->refresh_settings();
        }
    }

    /** A token for a cdb client works at every conference on the contact
     * database, so a selector cannot travel with it: `#12` names a different
     * submission at each site. */
    function test_cdb_client_rejects_subset_scopes() {
        if (!$this->conf->contactdb()) {
            return;
        }
        $old = $this->conf->opt("oAuthClients");
        $this->conf->set_opt("oAuthClients", array_merge($old, [(object) [
            "name" => "cdbc", "client_id" => "cdbclient", "client_secret" => "s",
            "is_cdb" => true, "scope" => "all",
            "redirect_uris" => ["https://cdbc.example.com/cb"]
        ]]));
        $this->conf->refresh_settings();

        $go = function ($scope) {
            return $this->authorize_outcome([
                "client_id" => "cdbclient",
                "redirect_uri" => "https://cdbc.example.com/cb",
                "response_type" => "code", "state" => "S", "scope" => $scope
            ], $this->u_chair);
        };

        // a scope naming no submission subset is fine
        [$how, ] = $go("read");
        xassert_eqq($how, "code");

        // one that names a paper, a tag, or a search is not
        foreach (["submission:admin#12", "read submission:admin#hot",
                  "submission:read?q=au%3Ame"] as $scope) {
            [$how, $detail] = $go($scope);
            xassert_eqq($how, "redirect");
            xassert_eqq($this->redirect_error($detail ?? ""), "invalid_scope");
        }

        // and the mint-time invariant holds even if a request got through
        $ts = TokenScope::parse("read submission:admin#hot", null);
        xassert($ts->has_selector());
        xassert(!$ts->without_selectors()->has_selector());
        xassert_eqq(TokenScope::unparse($ts->without_selectors()), "read");

        $this->conf->set_opt("oAuthClients", $old);
        $this->conf->refresh_settings();
    }

    /** @return list<object> */
    private function grants_for($user) {
        $us = (new UserStatus($user))->set_user($user);
        return (new Developer_UserInfo($us))->recent_grants();
    }

    /** @return list<TokenInfo> */
    private function bearer_tokens_for($user) {
        $us = (new UserStatus($user))->set_user($user);
        return (new Developer_UserInfo($us))->recent_bearer_tokens();
    }

    /** Make a bearer token for `$user` with scope `$scope`.
     * @return TokenInfo */
    private function bearer_token($user, $scope) {
        $tok = Authorization_Token::prepare_bearer($user, 3600);
        return $tok->change_data("scope", $scope)->insert();
    }

    /** `client_secret: ""` is no secret at all. The token endpoint already
     * read it that way; `make_id_token` did not, and signed HS256 with the
     * empty key — a signature anyone can produce. */
    function test_empty_client_secret_is_no_secret() {
        $c = new HotCRP\OAuthClient((object) [
            "name" => "x", "client_id" => "x", "client_secret" => "",
            "redirect_uris" => ["https://x.example.com/cb"]
        ]);
        xassert_eqq($c->client_secret, null);
        xassert($c->public_client("https://x.example.com/cb"));

        $old = $this->conf->opt("oAuthClients");
        $this->conf->set_opt("oAuthClients", array_merge($old, [(object) [
            "name" => "emptysec", "client_id" => "emptysec", "client_secret" => "",
            "scope" => "read", "redirect_uris" => ["https://emptysec.example.com/cb"]
        ]]));
        $this->conf->refresh_settings();

        // a public client must use PKCE, which is what makes the empty secret
        // safe to treat as absent
        [$how, $detail] = $this->authorize_outcome([
            "client_id" => "emptysec",
            "redirect_uri" => "https://emptysec.example.com/cb",
            "response_type" => "code", "state" => "S", "scope" => "openid"
        ], $this->u_chair);
        xassert_eqq($how, "redirect");
        xassert_eqq($this->redirect_error($detail ?? ""), "invalid_request");

        $this->conf->set_opt("oAuthClients", $old);
        $this->conf->refresh_settings();
    }

    /** Credentials and grant material belong in the body; in the URI they
     * reach logs, `Referer`, and history (RFC 6749 §2.3.1, §3.2). */
    function test_token_endpoint_refuses_query_credentials() {
        foreach (HotCRP\Authorize_Page::SECRET_PARAMS as $k) {
            $qreq = TestQreq::user_post($this->u_empty, ["grant_type" => "authorization_code", $k => "v"])
                ->set_page("api")->set_query_keys([$k]);
            Qrequest::set_main_request($qreq);
            $jr = HotCRP\Authorize_Page::oauthtoken_api($this->u_empty, $qreq);
            xassert_eqq($jr->content["error"] ?? null, "invalid_request", "param {$k}");
        }
        // the same parameter in the body is fine
        $qreq = TestQreq::user_post($this->u_empty, ["grant_type" => "authorization_code", "client_id" => "nope"])
            ->set_page("api");
        Qrequest::set_main_request($qreq);
        // and an error response is no more cacheable than a success (RFC 6749 §5.2)
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        try {
            Navigation::headers_reset();
            $jr = HotCRP\Authorize_Page::oauthtoken_api($this->u_empty, $qreq);
            xassert_eqq($jr->content["error"] ?? null, "invalid_client");
            xassert(in_array("Cache-Control: no-store", Navigation::headers_list(), true));
        } finally {
            Navigation::headers_reset();
            Navigation::$test_mode = $old_test_mode;
        }
    }

    /** RFC 6749 §2.3.1 form-urlencodes each half of the Basic credentials
     * before base64. That encoding is what lets a `client_id` contain the
     * `:` the header is split on. */
    function test_basic_credentials_are_urldecoded() {
        $post = function ($clid, $secret) {
            $qreq = TestQreq::user_post($this->u_empty, ["grant_type" => "refresh_token"])
                ->set_page("api")
                ->set_header("Authorization", "Basic " . base64_encode("{$clid}:{$secret}"));
            Qrequest::set_main_request($qreq);
            return HotCRP\Authorize_Page::oauthtoken_api($this->u_empty, $qreq)
                ->content["error"] ?? null;
        };
        // `confclient`/`confsecret` are configured; encoded or raw, both work
        xassert_neqq($post("confclient", "confsecret"), "invalid_client");
        xassert_neqq($post("confclient", rawurlencode("confsecret")), "invalid_client");
        xassert_eqq($post("confclient", "wrong"), "invalid_client");
        // a client_id containing `:` is reachable only through the encoding
        xassert_eqq($post("https://mdoc.example.com/client.json", ""), "invalid_client");
        if (class_exists("Uri\\Rfc3986\\Uri", false)) {
            xassert_neqq($post(rawurlencode(self::MDOC_CLIENT_ID), ""), "invalid_client");
        }
    }

    /** An OpenID-Connect-only client is never issued a refresh token, so a
     * registration that asks for the grant is answered with what the client
     * can actually use rather than refused (RFC 7591 §3.2.1). */
    function test_registration_reports_usable_grant_types() {
        $old = $this->conf->opt("oAuthClients");
        $this->conf->set_opt("oAuthClients", array_merge($old, [(object) [
            // no `scope`, so this component is OpenID Connect only
            "name" => "doidc", "dynamic" => true,
            "redirect_uris" => ["https://doidc.example.com/cb"]
        ]]));
        $this->conf->refresh_settings();

        $reg = function ($uri, $rest = []) {
            return call_api("=oauthregister", $this->u_empty,
                TestQreq::post_json(["redirect_uris" => [$uri]] + $rest));
        };
        // asking for `refresh_token` is not an error, with or without a scope
        $jr = $reg("https://doidc.example.com/cb",
            ["grant_types" => ["authorization_code", "refresh_token"]]);
        xassert_neqq($jr->client_id ?? null, null);
        xassert_eqq($jr->grant_types ?? null, ["authorization_code"]);
        $jr = $reg("https://doidc.example.com/cb",
            ["grant_types" => ["authorization_code", "refresh_token"], "scope" => "read"]);
        xassert_neqq($jr->client_id ?? null, null);
        xassert_eqq($jr->grant_types ?? null, ["authorization_code"]);
        // a component that grants API access does report the refresh grant
        $jr = $reg("https://dall.com/", ["grant_types" => ["authorization_code", "refresh_token"]]);
        xassert_eqq($jr->grant_types ?? null, ["authorization_code", "refresh_token"]);

        $this->conf->set_opt("oAuthClients", $old);
        $this->conf->refresh_settings();
    }

    /** A registered `scope` is user input like any other scope input. */
    function test_registration_scope_is_checked() {
        $reg = function ($scope) {
            $jr = call_api("=oauthregister", $this->u_empty, TestQreq::post_json([
                "redirect_uris" => ["https://dall.com/"], "scope" => $scope
            ]));
            return $jr->client_id ?? ($jr->error ?? "??");
        };
        xassert_neqq($reg("read"), "invalid_client_metadata");
        xassert_eqq($reg(str_repeat("read ", 400)), "invalid_client_metadata");
        xassert_eqq($reg("read\n\nwrite"), "invalid_client_metadata");
        xassert_eqq($reg(17), "invalid_client_metadata");
    }

    /** `GET /api/share` returns a bearer credential for the paper, so it is
     * an admin action, not a read. */
    function test_share_token_needs_admin_scope() {
        $prow = $this->conf->checked_paper_by_id(1);
        $jr = call_api_result("share", $this->bearer_token($this->u_chair, "read"),
            TestQreq::get(["p" => 1]), $prow);
        xassert_eqq($jr->status, 403);
        $jr = call_api_result("=share", $this->bearer_token($this->u_chair, "submission:admin"),
            TestQreq::post(["p" => 1, "share" => 1]), $prow);
        xassert_eqq($jr->status ?? 200, 200);
        xassert_neqq($jr->content["token"] ?? null, null);
        // the credential now exists, and a read-scoped token still cannot see it
        $jr = call_api_result("share", $this->bearer_token($this->u_chair, "read"),
            TestQreq::get(["p" => 1]), $prow);
        xassert_eqq($jr->status, 403);
        $jr = call_api_result("share", $this->bearer_token($this->u_chair, "submission:admin"),
            TestQreq::get(["p" => 1]), $prow);
        xassert_neqq($jr->content["token"] ?? null, null);
    }

    /** A review token is session state. A bearer request gets a fresh session
     * each time, so the 5-failure lockout never accumulates and what is left
     * is an unlimited guessing oracle. */
    function test_reviewtoken_refuses_bearer() {
        $jr = call_api_result("=reviewtoken", $this->bearer_token($this->u_chair, "all"),
            TestQreq::post(["token" => "AAAAAAAA"]));
        xassert_eqq($jr->status, 403);
    }

    /** A consent button names a session slot, and slots can be reused between
     * render and post; it names the account too. */
    function test_authconfirm_checks_the_named_account() {
        [$how, $code] = $this->authorize_outcome([
            "client_id" => $this->register_client("https://dall.com/"),
            "redirect_uri" => "https://dall.com/", "response_type" => "code",
            "state" => "S", "scope" => "read"
        ], $this->u_chair);
        xassert_eqq($how, "code");

        // the slot now resolves to someone else than the button named
        $vq = TestQreq::user_post($this->u_chair, [
            "authconfirm" => 1, "code" => $code, "authemail" => $this->u_mgbaker->email
        ])->set_page("authorize");
        Qrequest::set_main_request($vq);
        xassert(!$this->authconfirm_redirects($vq));
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("email"), null);

        // the button the user actually pressed still works
        $vq = TestQreq::user_post($this->u_chair, [
            "authconfirm" => 1, "code" => $code, "authemail" => "CHAIR@_.com"
        ])->set_page("authorize");
        Qrequest::set_main_request($vq);
        xassert($this->authconfirm_redirects($vq));
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("email"), "chair@_.com");
    }

    /** The consent page's one job is telling the user who is asking, so a
     * name cannot reorder itself. */
    function test_client_name_is_not_reorderable() {
        $c = new HotCRP\OAuthClient((object) [
            "name" => "x", "client_id" => "x",
            "title" => "hotcrp\u{202E}moc.live\u{202C}",
            "redirect_uris" => ["https://x.example.com/cb"]
        ]);
        xassert_eqq($c->title_text(), "hotcrpmoc.live");
        xassert_eqq($c->title_html(), "hotcrpmoc.live");
    }

    /** RFC 8414 §3.3 obliges a client to reject a resource whose
     * `authorization_servers` does not match the server's own `issuer`. */
    /** Fetch protected resource metadata as if `$url` had been requested.
     * @return string */
    private function well_known($url) {
        $base = NavigationState::make_base($this->conf->opt("paperSite"));
        // `make_base` splits `$rest` at its first "/", so it must not start with one
        $nav = NavigationState::make_base($base->server . "/",
            substr($url, strlen($base->server) + 1));
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        ob_start();
        try {
            WellKnown_Page::oauth_protected_resource($nav, $this->conf);
        } catch (PageCompletion $pc) {
        } finally {
            $t = ob_get_clean();
            Navigation::$test_mode = $old_test_mode;
        }
        return $t;
    }

    function test_protected_resource_metadata() {
        $site = $this->conf->opt("paperSite");
        $base = NavigationState::make_base($site);
        $wk = $base->server . "/.well-known/oauth-protected-resource";
        $get = function ($suffix = "") use ($wk, $base) {
            return $this->well_known($wk . rtrim($base->base_path, "/") . $suffix);
        };
        $this->conf->set_opt("oAuthIssuer", "https://issuer.example.com");
        $j = json_decode($get("/api/whoami"));
        xassert_neqq($j, null);
        xassert_eqq($j->authorization_servers ?? null, [$this->conf->oauth_issuer()]);
        xassert_eqq($j->authorization_servers ?? null, ["https://issuer.example.com"]);
        $this->conf->set_opt("oAuthIssuer", null);

        // the scope advertised is what that endpoint needs, not what the site
        // is willing to grant somebody
        $j = json_decode($get("/api/settings"));
        xassert_neqq($j, null);
        xassert_eqq($j->resource ?? null, rtrim($site, "/") . "/api/settings");
        // GET wants settings:read, POST settings:admin; the union is admin
        xassert(in_array("settings:admin", $j->scopes_supported ?? [], true));
        xassert(!in_array("all", $j->scopes_supported ?? [], true));
        // sign-in scopes ride along, since a client uses this list verbatim
        foreach (["openid", "email", "profile"] as $sc) {
            xassert(in_array($sc, $j->scopes_supported ?? [], true));
        }
        // an endpoint with no scope key is not scope-gated, so nothing is ruled out
        $j = json_decode($get("/api/whoami"));
        xassert(in_array("all", $j->scopes_supported ?? [], true));

        // The URL a 401 sends clients to must be one this endpoint answers. It
        // names the API, not the endpoint that failed: the token the client
        // goes on to get is used across the API, so a scope adequate for one
        // function would leave it short everywhere else. The request must
        // navigate under `paperSite`, or the URL it advertises names a
        // different host than the one serving the metadata.
        $qreq = TestQreq::get([])->set_conf($this->conf)
            ->set_navigation(NavigationState::make_base($site, "api/settings"));
        Qrequest::set_main_request($qreq);
        $h = $this->conf->www_authenticate_header("invalid_token", $qreq);
        xassert(preg_match('/resource_metadata="([^"]*)"/', $h, $m) === 1);
        // no empty path component, whatever the site's base path
        xassert(strpos(substr($m[1] ?? "", 8), "//") === false);
        $j = json_decode($this->well_known($m[1] ?? ""));
        xassert_neqq($j, null);
        xassert_eqq($j->resource ?? null, rtrim($site, "/") . "/api");
        xassert(in_array("all", $j->scopes_supported ?? [], true));

        // an endpoint that does not exist has no metadata
        xassert_str_contains($get("/api/nonesuch"), "404 Not Found");

        // and it describes nothing when this site is no authorization server
        $old = $this->conf->opt("oAuthClients");
        $this->conf->set_opt("oAuthClients", null);
        $this->conf->refresh_settings();
        xassert_str_contains($get("/api/whoami"), "404 Not Found");
        $this->conf->set_opt("oAuthClients", $old);
        $this->conf->refresh_settings();
    }

    /** A `roles` or `groups` claim is whatever the provider sent. */
    function test_role_claim_ignores_non_strings() {
        $this->conf->set_opt("oAuthProviders", [(object) [
            "name" => "p", "client_id" => "C", "client_secret" => "S",
            "issuer" => "https://idp.example.com", "roles" => true,
            "auth_uri" => "https://idp.example.com/auth",
            "token_uri" => "https://idp.example.com/token",
            "redirect_uri" => "https://conf.example.com/oauth"
        ]]);
        $this->conf->refresh_settings();
        $old = HotCRP\OAuth_Page::$fetch_function;
        $email = "oauthroles@hotcrp-oauth.org";

        try {
            $q1 = TestQreq::user_get($this->u_empty, ["authtype" => "p"])
                ->set_page("oauth");
            $qs = $q1->qsession();
            Qrequest::set_main_request($q1);
            $auth = null;
            try {
                (new HotCRP\OAuth_Page($this->u_empty, $q1))->start();
            } catch (Redirection $redir) {
                $auth = $redir->url;
            }
            parse_str(parse_url($auth ?? "", PHP_URL_QUERY) ?? "", $ap);
            HotCRP\OAuth_Page::$fetch_function = function ($authi, $param) use ($ap, $email) {
                return [200, json_encode(["id_token" => HotCRP\JWTParser::make_plaintext((object) [
                    "iss" => "https://idp.example.com", "aud" => "C",
                    "exp" => Conf::$now + 600, "iat" => Conf::$now,
                    "nonce" => $ap["nonce"], "sub" => "u1",
                    "email" => $email, "email_verified" => true,
                    "roles" => [17, null, ["pc"], "pc"]
                ])])];
            };
            $_COOKIE["hotcrp-oauth-nonce-" . $ap["nonce"]] = "1";
            $q2 = TestQreq::user_get($this->u_empty, ["code" => "C", "state" => $ap["state"]], $qs)
                ->set_page("oauth");
            Qrequest::set_main_request($q2);
            $oap = new HotCRP\OAuth_Page($this->u_empty, $q2);
            $oap->response();
            unset($_COOKIE["hotcrp-oauth-nonce-" . $ap["nonce"]]);

            // the junk is skipped and the one real role still applies
            xassert($oap->success);
            $u = $this->conf->fresh_user_by_email($email);
            xassert_neqq($u, null);
            xassert($u && $u->isPC);
        } finally {
            HotCRP\OAuth_Page::$fetch_function = $old;
            $this->conf->set_opt("oAuthProviders", null);
            $this->conf->refresh_settings();
        }
    }

    /** The `/oauth` state token is claimed atomically and dropped however the
     * callback ends; two callbacks in flight must not both proceed, and a
     * request that fails early must not leave the state usable. */
    function test_oauth_state_is_single_use() {
        $this->conf->set_opt("oAuthProviders", [(object) [
            "name" => "p", "client_id" => "C", "client_secret" => "S",
            "issuer" => "https://idp.example.com",
            "auth_uri" => "https://idp.example.com/auth",
            "token_uri" => "https://idp.example.com/token",
            "redirect_uri" => "https://conf.example.com/oauth"
        ]]);
        $this->conf->refresh_settings();

        try {
            $q1 = TestQreq::user_get($this->u_empty, ["authtype" => "p"])
                ->set_page("oauth");
            $qs = $q1->qsession();
            Qrequest::set_main_request($q1);
            $auth = null;
            try {
                (new HotCRP\OAuth_Page($this->u_empty, $q1))->start();
            } catch (Redirection $redir) {
                $auth = $redir->url;
            }
            parse_str(parse_url($auth ?? "", PHP_URL_QUERY) ?? "", $ap);
            $state = $ap["state"] ?? "";
            xassert_neqq($state, "");

            // this callback fails early: it has no `code`, and its nonce cookie
            // was never set. The state must still be spent.
            $q2 = TestQreq::user_get($this->u_empty, ["state" => $state], $qs)
                ->set_page("oauth");
            Qrequest::set_main_request($q2);
            (new HotCRP\OAuth_Page($this->u_empty, $q2))->response();
            xassert_eqq(TokenInfo::find_from($state, $this->conf, !!$this->conf->contactdb()), null);

            // and a second callback with the same state finds nothing
            $q3 = TestQreq::user_get($this->u_empty, ["code" => "C", "state" => $state], $qs)
                ->set_page("oauth");
            Qrequest::set_main_request($q3);
            $oap = new HotCRP\OAuth_Page($this->u_empty, $q3);
            $oap->response();
            xassert(!$oap->success);
        } finally {
            $this->conf->set_opt("oAuthProviders", null);
            $this->conf->refresh_settings();
        }
    }

    /** A loopback client identifier has three spellings, and `try_make` accepts
     * all three. A host wildcard must therefore cover all three: an IPv6
     * literal is one host, and the colons inside its brackets are part of that
     * host, not a port separator. */
    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_client_id_match_ipv6_host() {
        $ck = function ($pat, $client_id) {
            $doc = $this->client_document($client_id);
            xassert_neqq($doc, null);
            return $doc && $doc->matches((object) ["client_id_match" => $pat]);
        };

        // a bare host wildcard covers every loopback spelling
        xassert($ck("http://*/cb", "http://localhost/cb"));
        xassert($ck("http://*/cb", "http://127.0.0.1/cb"));
        xassert($ck("http://*/cb", "http://[::1]/cb"));

        // so does one that also accepts any port
        xassert($ck("http://*:*/cb", "http://localhost:5000/cb"));
        xassert($ck("http://*:*/cb", "http://127.0.0.1:5000/cb"));
        xassert($ck("http://*:*/cb", "http://[::1]:5000/cb"));
        xassert($ck("http://*:*/cb", "http://[::1]/cb"));

        // and naming the address literally still works, with and without a port
        xassert($ck("http://[::1]:*/cb", "http://[::1]:5000/cb"));
        xassert($ck("http://[::1]:*/cb", "http://[::1]/cb"));
        xassert($ck("http://[::1]/cb", "http://[::1]/cb"));

        // the port is still outside the host: naming no port means no port
        xassert(!$ck("http://[::1]/cb", "http://[::1]:5000/cb"));
        xassert(!$ck("http://*/cb", "http://[::1]:5000/cb"));
        xassert(!$ck("http://*/cb", "http://localhost:5000/cb"));
    }

    /** `allow_if` limits who may hold a client's tokens, not just who may press
     * Approve. Losing the role must stop the token — otherwise refresh rotation
     * renews the grant forever past the rule that justified it. */
    function test_allow_if_rechecked_after_grant() {
        $jr = $this->dynamic_client_result("https://dpc.com/", $this->u_mgbaker, ["scope" => "read"]);
        xassert_neqq($jr, null);
        xassert_neqq($this->_last_refresh_token, null);
        $atok = $jr->_token;
        xassert_neqq($atok, null);
        // the very first access token names its client, like every later one
        xassert_neqq($atok->data("client_name"), null);
        // the grant records the rule it was made under
        xassert_eqq($atok->data("allow_if"), "pc");
        // while the user is still PC, both the API and refresh work
        xassert_eqq(call_api_result("whoami", $atok, [])->response_code(), 200);
        xassert_neqq(($atok2 = $this->refresh_access_token()), null);

        $roles = $this->u_mgbaker->roles;
        try {
            $this->u_mgbaker->save_roles(0, $this->u_chair);
            $this->conf->invalidate_caches("pc");
            // the live access token stops working...
            xassert_eqq(call_api_result("whoami", $atok2, [])->response_code(), 401);
            // ...and refresh cannot mint a new one
            xassert_eqq($this->refresh_access_token(), null);
        } finally {
            $this->u_mgbaker->save_roles($roles, $this->u_chair);
            $this->conf->invalidate_caches("pc");
        }

        // restoring the role does not resurrect anything: the refresh token was
        // spent by the failed attempt
        xassert_eqq($this->refresh_access_token(), null);
    }

    /** Revoking a grant must take the whole chain: the access token the user
     * can see renews itself from a refresh token they cannot, so deleting the
     * visible half achieves nothing. */
    function test_grant_revocation() {
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        xassert_neqq($jr, null);
        $atok = $jr->_token;
        $client_id = $this->_last_client_id;

        // an OAuth token is not an "API token": it belongs to a grant, and the
        // hand-made token list must not offer a deletion that refresh undoes
        foreach ($this->bearer_tokens_for($this->u_chair) as $t) {
            xassert_neqq($t->salt, $atok->salt);
        }

        $grants = $this->grants_for($this->u_chair);
        $g = null;
        foreach ($grants as $gx) {
            if ($gx->client_id === $client_id)
                $g = $gx;
        }
        xassert_neqq($g, null);
        if (!$g) {
            return;
        }
        xassert_eqq($g->scopes, ["read"]);
        // the grant knows its client by name, so it stays revocable after the
        // client is removed from the configuration
        xassert_neqq($g->name, null);
        // both halves are present: revoking only the access token would leave
        // the refresh token to mint another
        $types = [];
        foreach ($g->tokens as $t) {
            $types[$t->capabilityType] = true;
        }
        xassert(isset($types[TokenInfo::BEARER]));
        xassert(isset($types[TokenInfo::OAUTHREFRESH]));

        // revoke, the way the profile page does
        foreach ($g->tokens as $t) {
            if ($t->is_active()) {
                $t->set_invalid()->set_expires_in(Authorization_Token::BEARER_RETENTION)->update();
            }
        }

        // the access token is dead, and refresh cannot resurrect the grant
        xassert_eqq(call_api_result("whoami", $this->find_token($atok->salt), [])->response_code(), 401);
        xassert_eqq($this->refresh_access_token(), null);
        // and the grant is gone from the list, which shows only live ones
        foreach ($this->grants_for($this->u_chair) as $gx) {
            xassert_neqq($gx->client_id, $client_id);
        }
    }

    /** A client that omits `scope` gets this server's default, which asks for
     * everything and is then capped by the client's configuration — the
     * administrator's decision. Defaulting to `openid` minted an access token
     * good for nothing, since an OpenID Connect scope names no API rights. */
    function test_omitted_scope_defaults_to_client_scope() {
        // `dall` is configured "all", `dro` is configured "read"
        foreach ([["https://dall.com/", "all"], ["https://dro.com/", "read"]] as [$uri, $want]) {
            $jr = $this->dynamic_client_result($uri, $this->u_chair);
            xassert_neqq($jr, null);
            if (!$jr) {
                continue;
            }
            // the request is recorded as asking for everything...
            xassert_eqq($jr->_token->data("client_id"), $this->_last_client_id);
            // ...and the access token is capped to what the client may have
            xassert_eqq($jr->_token->data("scope"), $want, $uri);
            xassert_neqq($jr->id_token ?? null, null);
            xassert_neqq($jr->refresh_token ?? null, null);
        }

        // an explicit identity-only request is still honored as identity-only:
        // a client that asks for nothing must not be handed API rights
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair,
            ["scope" => "openid"]);
        xassert_neqq($jr, null);
        xassert_eqq($jr->_token->data("scope"), "none");
        // and one that names a scope gets that scope, not the default
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair,
            ["scope" => "openid tag:read"]);
        xassert_eqq($jr->_token->data("scope"), "tag:read");
    }

    /** A dynamically registered client declares a `scope` of its own, and it
     * caps every token the client is issued — the consent field limits within
     * it rather than reaching past it. */
    function test_registration_scope_caps_the_grant() {
        $ruri = "https://dall.com/";
        // the client registers itself as wanting `read` only
        $jr = call_api("=oauthregister", $this->u_empty, TestQreq::post_json([
            "redirect_uris" => [$ruri], "scope" => "read"
        ]));
        xassert_neqq($jr->client_id ?? null, null);
        $this->_last_client_id = $jr->client_id;
        $this->_last_client_secret = $jr->client_secret;
        // ...then asks for more than that in the authorization request. `dall`
        // is configured "all", so the registration scope is the only thing
        // that can narrow this.
        $param = [
            "client_id" => $jr->client_id, "redirect_uri" => $ruri,
            "response_type" => "code", "state" => "S", "scope" => "openid write"
        ];
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        xassert_eqq($how, "code");
        $rjr = $this->redeem_code($this->confirm_code($code), $ruri);
        xassert_eqq($this->find_token($rjr->access_token)->data("scope"), "read");

        // and the consent field does not reach past it either
        [$how, $code] = $this->authorize_outcome($param, $this->u_chair);
        $rjr = $this->redeem_code($this->confirm_code($code, "write"), $ruri);
        xassert_eqq($this->find_token($rjr->access_token)->data("scope"), "read");
        // and the cap holds across rotation
        $this->_last_refresh_token = $rjr->refresh_token;
        $atok = $this->refresh_access_token();
        xassert_neqq($atok, null);
        xassert_eqq($atok->data("scope"), "read");
    }

    /** What a grant's row says about scope. An access token records the scope
     * granted; once its row is gone — which happens days before the refresh
     * token's — only the requested scope survives, and that is an upper bound,
     * not the grant. Neither may be reported as "full scope", which is what
     * `all` means. */
    function test_grant_scope_display() {
        $mk = function ($uri, $scope) {
            $jr = $this->dynamic_client_result($uri, $this->u_chair, ["scope" => $scope]);
            xassert_neqq($jr, null);
            $cid = $this->_last_client_id;
            foreach ($this->grants_for($this->u_chair) as $g) {
                if ($g->client_id === $cid)
                    return $g;
            }
            return null;
        };

        // an access token is present, so the granted scope is known exactly.
        // Each of these registers a fresh dynamic client, so each is its own
        // row rather than accumulating.
        $g = $mk("https://dall.com/", "openid read");
        xassert_neqq($g, null);
        xassert_eqq($g->scopes, ["read"]);
        xassert_eqq(count($g->tokens), 2);   // access + refresh
        $g = $mk("https://dall.com/", "openid all");
        xassert_eqq($g->scopes, ["all"]);
        // an identity-only request grants no API access — and that is not the
        // same as not knowing
        $g = $mk("https://dall.com/", "openid");
        xassert_eqq($g->scopes, ["none"]);

        // now retire the access token the way time does, leaving the refresh
        // token: the grant is still listed, and its scope is still described
        $g = $mk("https://dall.com/", "openid read");
        xassert_eqq($g->scopes, ["read"]);
        $cid = $g->client_id;
        foreach ($g->tokens as $t) {
            if ($t->capabilityType === TokenInfo::BEARER) {
                $t->set_invalid_at(Conf::$now - 6 * 86400)->update();
            }
        }
        $g2 = null;
        foreach ($this->grants_for($this->u_chair) as $gx) {
            if ($gx->client_id === $cid)
                $g2 = $gx;
        }
        xassert_neqq($g2, null);
        // the exact scope is gone with the row, but the request bound remains,
        // and it must not read as "full scope"
        xassert_eqq($g2->scopes, []);
        xassert_eqq($g2->max_scopes, ["read"]);
        xassert_eqq(count($g2->tokens), 1);
    }

    /** A metadata-document client keeps one `client_id` — a URL — across every
     * authorization, so several authorizations are one connected application.
     * The row must describe all of them, and describe them the same way each
     * time it is built. */
    #[RequireClass("Uri\\Rfc3986\\Uri")]
    function test_grants_merge_authorizations_of_one_client() {
        $doc = [
            "client_id" => self::MDOC_CLIENT_ID,
            "client_name" => "Metadata Document Test Client",
            "client_uri" => "https://mdoc.example.com/",
            "redirect_uris" => [self::MDOC_REDIRECT_URI, "https://mdoc.example.com/cb2"],
            "grant_types" => ["authorization_code", "refresh_token"],
            "response_types" => ["code"],
            "token_endpoint_auth_method" => "none"
        ];
        // other tests authorize this same client, and that is the point: they
        // all land in one row. Measure the change rather than the total.
        $before = 0;
        foreach ($this->grants_for($this->u_chair) as $gx) {
            if ($gx->client_id === self::MDOC_CLIENT_ID)
                $before = count($gx->tokens);
        }
        try {
            $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI, $this->u_chair,
                ["scope" => "openid read"]);
            xassert_neqq($jr, null);
            // the document renames the client and it is authorized again,
            // genuinely later each time — the test clock does not advance by
            // itself, and "most recent" is meaningless while it stands still
            foreach (["Renamed Client", "Renamed Again"] as $name) {
                Conf::advance_current_time(Conf::$now + 2);
                $this->set_document(self::MDOC_CLIENT_ID,
                    ["client_name" => $name] + $doc);
                $jr = $this->metadata_document_result(self::MDOC_REDIRECT_URI,
                    $this->u_chair, ["scope" => "openid all"]);
                xassert_neqq($jr, null);
                // the row still names the client as of its newest authorization
                $g = null;
                foreach ($this->grants_for($this->u_chair) as $gx) {
                    if ($gx->client_id === self::MDOC_CLIENT_ID) {
                        // one row, however many authorizations
                        xassert_eqq($g, null);
                        $g = $gx;
                    }
                }
                xassert_neqq($g, null);
                xassert_eqq($g ? $g->name : null, $name);
            }
            if (!$g) {
                return;
            }
            // every authorization's access and refresh tokens
            xassert_eqq(count($g->tokens) - $before, 6);
            // and every scope, so no authorization is misdescribed
            xassert(in_array("read", $g->scopes, true));
            xassert(in_array("all", $g->scopes, true));
        } finally {
            $this->set_document(self::MDOC_CLIENT_ID, $doc);
        }
    }

    /** Build a reauthenticated POST `UserStatus` for `$user`, as
     * Profile > Developer runs under.
     * @return array{UserStatus,Qrequest} */
    private function developer_us($user, $req = []) {
        $qreq = (new Qrequest("POST", $req))->approve_token();
        $qreq->set_conf($this->conf)->set_qsession(new MemoryQsession);
        UserSecurityEvent::session_user_add($qreq->qsession(), $user->email);
        UserSecurityEvent::make($user->email)
            ->set_reason(UserSecurityEvent::REASON_REAUTH)
            ->store($qreq);
        $u = $user->activate($qreq, true);
        $qreq->set_user($u);
        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update()->set_user($u);
        return [$us, $qreq];
    }

    /** Disconnecting an application goes through the profile form, so the
     * field the page renders and the field the save handler reads have to be
     * the same field. Revoking the tokens by hand tests neither. */
    function test_grant_revocation_through_the_form() {
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        xassert_neqq($jr, null);
        $client_id = $this->_last_client_id;
        $atok = $jr->_token;

        // what the page renders
        [$us, ] = $this->developer_us($this->u_chair);
        xassert($us->is_auth_self());
        xassert($us->has_recent_authentication());
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        ob_start();
        try {
            (new Developer_UserInfo($us))->print_grants($us);
        } finally {
            $html = ob_get_clean();
            Navigation::$test_mode = $old_test_mode;
        }
        // the user may have authorized other clients, and the grant list is
        // ordered by use, so pick this client's row rather than the first
        $row = "";
        foreach (preg_split('/(?=<div class="f-i)/', $html) as $r) {
            if (str_contains($r, "value=\"L.{$client_id}\""))
                $row = $r;
        }
        // the row names the client, counts its live tokens, and states its scope
        xassert_neqq($row, "");
        xassert_str_contains($row, "2 tokens");
        xassert_str_contains($row, "scope read");
        // and carries the identifier the save handler matches on
        xassert(preg_match('/name="(grant\/\d+\/id)"/', $row, $m) === 1);
        $idfield = $m[1];
        $delfield = str_replace("/id", "/delete", $idfield);

        // the browser submits every rendered row, and the save handler stops
        // at the first index it does not see, so post them all
        preg_match_all('/name="(grant\/\d+\/id)" value="([^"]*)"/', $html, $ms, PREG_SET_ORDER);
        $form = [];
        foreach ($ms as $mx) {
            $form[$mx[1]] = $mx[2];
        }
        xassert_eqq($form[$idfield] ?? null, "L.{$client_id}");

        // a request that names the row but does not ask to delete it
        [$us, ] = $this->developer_us($this->u_chair, $form);
        $d = new Developer_UserInfo($us);
        $d->request_delete_grants($us);
        $d->save_delete_grants($us);
        xassert($this->find_token($atok->salt)->is_active());

        // a request naming some other grant leaves this one alone
        [$us, ] = $this->developer_us($this->u_chair,
            [$idfield => "L.hctk_nonesuch", $delfield => "1"] + $form);
        $d = new Developer_UserInfo($us);
        $d->request_delete_grants($us);
        $d->save_delete_grants($us);
        xassert($this->find_token($atok->salt)->is_active());

        // and the real thing revokes the whole grant
        [$us, ] = $this->developer_us($this->u_chair,
            [$delfield => "1"] + $form);
        $d = new Developer_UserInfo($us);
        $d->request_delete_grants($us);
        $d->save_delete_grants($us);
        xassert(isset($us->diffs["connected applications"]));
        xassert(!$this->find_token($atok->salt)->is_active());
        $this->_last_refresh_token = $jr->refresh_token;
        xassert_eqq($this->refresh_access_token(), null);
        foreach ($this->grants_for($this->u_chair) as $g) {
            xassert_neqq($g->client_id, $client_id);
        }
    }

    /** Post a revocation request as the last dynamic client registered.
     * @return JsonResult */
    private function revoke_token($token, $rest = [], $auth = true) {
        $param = $rest;
        if ($token !== null) {
            $param["token"] = $token;
        }
        if ($auth) {
            $param["client_id"] = $this->_last_client_id;
            $param["client_secret"] = $this->_last_client_secret;
        }
        $qreq = TestQreq::post($param)->set_conf($this->conf)->set_page("api");
        Qrequest::set_main_request($qreq);
        return HotCRP\Authorize_Page::oauthrevoke_api($this->u_empty, $qreq);
    }

    /** RFC 7009: a client can hand back a token it no longer needs. */
    function test_token_revocation_endpoint() {
        // revoking an access token stops it, and only it
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        xassert_neqq($jr, null);
        $atok = $jr->_token;
        $this->_last_refresh_token = $jr->refresh_token;
        xassert_eqq(call_api_result("whoami", $atok, [])->response_code(), 200);
        xassert_eqq($this->revoke_token($jr->access_token)->status ?? 200, 200);
        xassert_eqq(call_api_result("whoami", $this->find_token($jr->access_token), [])->response_code(), 401);
        // the grant survives: §2.1 makes that cascade a MAY, and we do not
        xassert_neqq($this->refresh_access_token(), null);

        // revoking a refresh token takes the grant with it
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        $this->_last_refresh_token = $jr->refresh_token;
        xassert_eqq($this->revoke_token($jr->refresh_token)->status ?? 200, 200);
        xassert_eqq(call_api_result("whoami", $this->find_token($jr->access_token), [])->response_code(), 401);
        xassert_eqq($this->refresh_access_token(), null);
        // and it leaves the user's connected-application list
        foreach ($this->grants_for($this->u_chair) as $g) {
            xassert_neqq($g->client_id, $this->_last_client_id);
        }

        // an unknown or malformed token is a success, not an oracle
        foreach (["hct_" . str_repeat("z", 30), "hctr_" . str_repeat("z", 36),
                  "garbage", "hct_short", ""] as $bad) {
            $r = $this->revoke_token($bad);
            xassert_eqq($r->status ?? 200, $bad === "" ? 400 : 200, $bad);
        }

        // a token issued to some other client is left alone, and reported the
        // same way an unknown one is: registration is open, so an error here
        // would tell anyone willing to spend one request whether a token exists
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        $victim = $jr->access_token;
        $this->register_client("https://dall.com/");   // a different client
        $r = $this->revoke_token($victim);
        $unknown = $this->revoke_token("hct_" . str_repeat("z", 30));
        xassert_eqq([$r->status ?? 200, $r->content], [200, []]);
        xassert_eqq([$r->status ?? 200, $r->content],
                    [$unknown->status ?? 200, $unknown->content]);
        xassert_eqq(call_api_result("whoami", $this->find_token($victim), [])->response_code(), 200);

        // Client authentication is required, as at the token endpoint, and
        // RFC 6749 §5.2 governs how it fails: "no client authentication
        // included" is `invalid_client`, and a client that tried via the
        // `Authorization` header gets 401 with a challenge rather than 400.
        $r = $this->revoke_token($victim, [], false);
        xassert_eqq($r->content["error"] ?? null, "invalid_client");
        xassert_eqq($r->status, 400);
        xassert_str_contains($r->header("WWW-Authenticate") ?? "", "Bearer");

        // unknown client and wrong secret are the same answer, so the endpoint
        // does not enumerate clients
        $post = function ($param, $hdr = null) {
            $qq = TestQreq::post($param)->set_conf($this->conf)->set_page("api");
            if ($hdr !== null) {
                $qq->set_header("Authorization", $hdr);
            }
            Qrequest::set_main_request($qq);
            return HotCRP\Authorize_Page::oauthrevoke_api($this->u_empty, $qq);
        };
        $r = $post(["token" => $victim, "client_id" => "hctk_nosuchclient0000000000000000"]);
        xassert_eqq([$r->status, $r->content["error"] ?? null], [400, "invalid_client"]);
        $r = $post(["token" => $victim, "client_id" => $this->_last_client_id,
                    "client_secret" => "wrong"]);
        xassert_eqq([$r->status, $r->content["error"] ?? null], [400, "invalid_client"]);

        // the same failure over the Authorization header is a 401
        $r = $post(["token" => $victim],
            "Basic " . base64_encode($this->_last_client_id . ":wrong"));
        xassert_eqq([$r->status, $r->content["error"] ?? null], [401, "invalid_client"]);
        xassert_str_contains($r->header("WWW-Authenticate") ?? "", "Bearer");

        // repeating the credentials in the body is redundant, not a second
        // authentication method: the request is well formed and authenticates,
        // where a malformed one is 400 and an unauthenticated one 401
        $basic = "Basic " . base64_encode($this->_last_client_id . ":" . $this->_last_client_secret);
        $r = $post(["token" => $victim, "client_id" => $this->_last_client_id], $basic);
        xassert_eqq([$r->status ?? 200, $r->content], [200, []]);
        $r = $post(["token" => $victim, "client_id" => $this->_last_client_id,
                    "client_secret" => $this->_last_client_secret], $basic);
        xassert_eqq([$r->status ?? 200, $r->content], [200, []]);

        // a body credential that disagrees with the header is malformed
        $r = $post(["token" => $victim, "client_id" => "hctk_nosuchclient0000000000000000"], $basic);
        xassert_eqq([$r->status, $r->content["error"] ?? null], [400, "invalid_request"]);
        $r = $post(["token" => $victim, "client_secret" => "wrong"], $basic);
        xassert_eqq([$r->status, $r->content["error"] ?? null], [400, "invalid_request"]);

        // the token may not travel in the query string
        $qreq = TestQreq::post(["token" => $victim, "client_id" => $this->_last_client_id,
                                "client_secret" => $this->_last_client_secret])
            ->set_conf($this->conf)->set_page("api")->set_query_keys(["token"]);
        Qrequest::set_main_request($qreq);
        $r = HotCRP\Authorize_Page::oauthrevoke_api($this->u_empty, $qreq);
        xassert_eqq($r->content["error"] ?? null, "invalid_request");

        // a nonsense type hint is rejected
        $r = $this->revoke_token($victim, ["token_type_hint" => "banana"]);
        xassert_eqq($r->content["error"] ?? null, "unsupported_token_type");

        // and the endpoint is advertised
        $old_test_mode = Navigation::$test_mode;
        Navigation::$test_mode = 2;
        ob_start();
        try {
            WellKnown_Page::oauth_authorization_server(Navigation::get(), $this->conf);
        } catch (PageCompletion $pc) {
        } finally {
            $t = ob_get_clean();
            Navigation::$test_mode = $old_test_mode;
        }
        $j = json_decode($t);
        xassert_str_contains($j->revocation_endpoint ?? "", "/api/oauthrevoke");
        xassert_neqq($j->revocation_endpoint_auth_methods_supported ?? null, null);
    }

    function test_allow_if() {
        $token = $this->dynamic_client_token("https://dchair.com/", $this->u_chair, ["scope" => "read write"]);
        xassert_eqq($token->data("scope"), "write");

        $jr = $this->dynamic_client_result("https://dchair.com/", $this->u_mgbaker, ["scope" => "read write"]);
        xassert_eqq($jr, null);
    }

    /** @param string $email
     * @return Contact */
    private function make_bot($email, $name = "Grantbot") {
        $this->conf->qe("delete from ContactInfo where email=?", $email);
        $this->conf->invalidate_caches("users", "pc");
        $us = new UserStatus($this->conf->root_user());
        $u = $us->save_user((object) ["email" => $email, "bot" => true, "name" => $name]);
        xassert(!!$u, $us->full_feedback_text());
        $this->flush_bots();
        return $u;
    }

    /** The bot list is a setting recomputed by a shutdown function, so a
     * process that makes a bot and then reads the list — a test, or a batch
     * script — must run that function and reload settings itself. */
    private function flush_bots() {
        $this->conf->call_shutdown_function("BotContact::enumerate_bots");
        $this->conf->load_settings();
    }

    /** @param Contact $u */
    private function delete_bot($u) {
        $this->conf->qe("delete from Capability where contactId=?", $u->contactId);
        $this->conf->qe("delete from ContactInfo where contactId=?", $u->contactId);
        $this->conf->invalidate_caches("users", "pc");
        $this->flush_bots();
    }

    /** Obtain an authorization code for `$client_id` as `$user`.
     * @return ?string */
    private function authorization_code($client_id, $redirect_uri, $user, $scope = "read") {
        $qreq = TestQreq::user_get($user, [
            "client_id" => $client_id, "redirect_uri" => $redirect_uri,
            "response_type" => "code", "state" => "S", "scope" => $scope
        ])->set_page("authorize");
        Qrequest::set_main_request($qreq);
        try {
            (new HotCRP\Authorize_Page($user, $qreq))->go();
        } catch (JsonCompletion $jc) {
            return $jc->result->content["code"] ?? null;
        }
        return null;
    }

    /** Every token of a grant carries the same identifier inside its salt, so
     * the web server's access log — which sees only a prefix of whatever
     * credential was presented, and cannot consult the database — names the
     * grant rather than the credential of the hour. */
    function test_grant_id_survives_truncation_and_rotation() {
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        xassert_neqq($jr, null, $this->_failure ?? "");
        if (!$jr) {
            return;
        }
        $atok = $jr->_token;
        $gid = Authorization_Token::grant_id($atok);
        xassert_eqq(strlen($gid ?? ""), Authorization_Token::GRANT_ID_LENGTH);

        // the access log keeps the first 12 characters of the credential
        // (`accesslogger.php`), and the identifier has to survive that for
        // both kinds of token: `hct_` + 7 fits with room, `hctr_` + 7 exactly
        $rtok = $this->find_token($this->_last_refresh_token);
        xassert_neqq($rtok, null);
        xassert_eqq(Authorization_Token::grant_id($rtok), $gid);
        foreach ([$atok, $rtok] as $tok) {
            xassert_str_contains(substr($tok->salt, 0, 12), $gid);
        }
        // the identifier gives back the randomness it costs, so a grant token is
        // the length a personal one is — the identifier is printed in logs and
        // is no part of the secret
        $plain = Authorization_Token::prepare_bearer($this->u_chair, 86400);
        xassert($plain->insert() && $plain->stored());
        xassert_eqq(strlen($atok->salt), strlen($plain->salt));
        $this->conf->qe("delete from Capability where salt=?", $plain->salt);
        // and the prefixes are still the ones the endpoint routes on
        xassert(str_starts_with($atok->salt, "hct_") || str_starts_with($atok->salt, "hcT_"));
        xassert(str_starts_with($rtok->salt, "hctr_") || str_starts_with($rtok->salt, "hcTr_"));

        // rotation replaces the credential and keeps the identifier
        $atok2 = $this->refresh_access_token();
        xassert_neqq($atok2, null, $this->_failure ?? "");
        xassert_neqq($atok2->salt, $atok->salt);
        xassert_eqq(Authorization_Token::grant_id($atok2), $gid);
        xassert_str_contains(substr($atok2->salt, 0, 12), $gid);
        $rtok2 = $this->find_token($this->_last_refresh_token);
        xassert_neqq($rtok2->salt, $rtok->salt);
        xassert_eqq(Authorization_Token::grant_id($rtok2), $gid);
        xassert_str_contains(substr($rtok2->salt, 0, 12), $gid);

        // a second authorization of the same client is a different grant
        $jr2 = $this->dynamic_client_result("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        xassert_neqq($jr2, null, $this->_failure ?? "");
        xassert_neqq(Authorization_Token::grant_id($jr2->_token), $gid);

        // and the action log ties the identifier to the person who approved it
        $row = Dbl::fetch_first_row($this->conf->qe("select contactId, action from ActionLog where action like ? order by logId desc limit 1",
                                                    "%[{$gid}]%"));
        xassert_neqq($row, null);
        xassert_eqq((int) $row[0], $this->u_chair->contactId);
        xassert_str_contains($row[1], "OAuth authorization");
    }

    /** The identifier is not minted: it is the authorization code's own leading
     * characters, so the consent request is traceable in an access log too — a
     * code rides in the redirect URL — and the entry logged at consent names
     * what the tokens will carry. */
    function test_grant_id_starts_at_the_code() {
        $jr = call_api("=oauthregister", $this->u_empty,
            TestQreq::post_json(["redirect_uris" => ["https://dall.com/"]]));
        xassert_neqq($jr->client_id ?? null, null);
        $code = $this->authorization_code($jr->client_id, "https://dall.com/", $this->u_chair);
        xassert_neqq($code, null);

        // the code itself carries it, right after its own prefix
        $codetok = TokenInfo::find($code, $this->conf);
        xassert_neqq($codetok, null);
        $gid = Authorization_Token::grant_id($codetok);
        xassert_eqq(strlen($gid ?? ""), Authorization_Token::GRANT_ID_LENGTH);
        xassert_eqq($gid, substr($codetok->salt, 4, Authorization_Token::GRANT_ID_LENGTH));

        // consent logs that identifier
        $vq = TestQreq::user_post($this->u_chair, ["authconfirm" => 1, "code" => $code])
            ->set_page("authorize");
        Qrequest::set_main_request($vq);
        xassert($this->authconfirm_redirects($vq));
        $row = Dbl::fetch_first_row($this->conf->qe("select action from ActionLog where contactId=? order by logId desc limit 1",
                                                    $this->u_chair->contactId));
        xassert_str_contains($row[0] ?? "", "[{$gid}]");

        // and the credentials the code produces carry the same one
        $qreq = TestQreq::post([
            "grant_type" => "authorization_code", "code" => $code,
            "redirect_uri" => "https://dall.com/", "client_id" => $jr->client_id,
            "client_secret" => $jr->client_secret
        ]);
        $tr = call_api("=oauthtoken", $this->u_empty, $qreq);
        xassert_neqq($tr->access_token ?? null, null);
        xassert_eqq(Authorization_Token::grant_id($this->find_token($tr->access_token)), $gid);
        xassert_eqq(Authorization_Token::grant_id($this->find_token($tr->refresh_token)), $gid);
    }

    /** The salt is the only copy of the identifier: it is what an access log
     * records, and a token cannot be renamed after issue. */
    function test_grant_id_comes_from_the_salt() {
        // a personal token names itself — it never rotates, so its own leading
        // characters are as stable as any grant identifier
        $plain = Authorization_Token::prepare_bearer($this->u_chair, 86400);
        xassert($plain->insert() && $plain->stored());
        $pid = Authorization_Token::grant_id($plain);
        xassert_eqq(strlen($pid ?? ""), Authorization_Token::GRANT_ID_LENGTH);
        xassert_str_contains($plain->abbreviation(), $pid);
        // and the twelve characters shown are the twelve the access log keeps
        xassert_eqq($plain->abbreviation(), substr($plain->salt, 0, 12));
        $this->conf->qe("delete from Capability where salt=?", $plain->salt);

        // every credential of one authorization carries the same identifier,
        // starting with the code that consent produced
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        xassert_neqq($jr, null, $this->_failure ?? "");
        if (!$jr) {
            return;
        }
        $gid = Authorization_Token::grant_id($jr->_token);
        xassert_eqq(strlen($gid ?? ""), Authorization_Token::GRANT_ID_LENGTH);
        $rtok = $this->find_token($this->_last_refresh_token);
        xassert_eqq(Authorization_Token::grant_id($rtok), $gid);

        // nothing is stored beside it, so rotation can only read the salt
        xassert_eqq($rtok->data("grant_id"), null);
        $atok2 = $this->refresh_access_token();
        xassert_neqq($atok2, null, $this->_failure ?? "");
        xassert_eqq(Authorization_Token::grant_id($atok2), $gid);
        xassert_str_contains($atok2->abbreviation(), $gid);
    }

    /** What is *not* a grant identifier. The pattern reads a fixed number of
     * letters after a fixed set of prefixes, so the shapes it must decline are
     * worth pinning: they are one edit away from being read as names. */
    function test_grant_id_declines_other_shapes() {
        $mk = function ($salt) {
            $tok = new TokenInfo($this->conf, TokenInfo::BEARER);
            $tok->salt = $salt;
            return Authorization_Token::grant_id($tok);
        };
        // the placeholder an OpenID-only client is handed in place of an access
        // token: “invalid” is seven letters, and only the tail requirement
        // keeps it from being read as a grant
        xassert_eqq($mk("hct_invalid_token"), null);
        // a client registration token is a credential of a client, not a grant
        xassert_eqq($mk("hctk_ABCDEFGHIJKLMNOPQRSTUVWX"), null);
        // and a session or reset capability shares none of the prefixes
        xassert_eqq($mk("hcses_ABCDEFGHIJKLMNOPQRSTUVWX"), null);
        // no token at all is not an error
        xassert_eqq(Authorization_Token::grant_id(null), null);
        // while the four shapes that do carry one all read the same way
        foreach (["hct_", "hcT_", "hctr_", "hcTr_", "hcoc"] as $pfx) {
            xassert_eqq($mk($pfx . "ABCDEFG" . str_repeat("x", 20)), "ABCDEFG");
        }
    }

    /** Revoking a grant logs which grant went away, by the same identifier the
     * access log shows — otherwise “connected applications” is all a later
     * reader gets. */
    function test_grant_revocation_names_the_grant() {
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair, ["scope" => "read"]);
        xassert_neqq($jr, null, $this->_failure ?? "");
        if (!$jr) {
            return;
        }
        $gid = Authorization_Token::grant_id($jr->_token);

        // a request with recent authentication, as the profile screen requires
        $email = $this->u_chair->email;
        $qreq = (new Qrequest("POST", [
            "grant/1/id" => "L." . $this->_last_client_id,
            "grant/1/delete" => 1
        ]))->approve_token();
        $qreq->set_qsession(new MemoryQsession);
        UserSecurityEvent::session_user_add($qreq->qsession(), $email);
        UserSecurityEvent::make($email)
            ->set_reason(UserSecurityEvent::REASON_REAUTH)
            ->store($qreq);
        $u = $this->conf->fresh_user_by_email($email)->activate($qreq, true);
        $qreq->set_user($u);

        $us = (new UserStatus($u))->set_qreq($qreq);
        $us->start_update();
        $us->set_user($u);
        xassert($us->has_recent_authentication());
        $us->request_group("");
        xassert($us->execute_update(), $us->full_feedback_text());

        // a self-edit logs with the actor alone, so look there
        $row = Dbl::fetch_first_row($this->conf->qe("select action from ActionLog where contactId=? order by logId desc limit 1",
                                                    $u->contactId));
        xassert_neqq($row, null);
        xassert_eqq($row[0] ?? "", "Account edited: connected applications [revoked {$gid}]");
        // and the credential really is dead
        $atok = $this->find_token($jr->access_token);
        xassert(!$atok || !$atok->is_active());
    }

    /** A bot cannot sign in, so a chair authorizes a grant on its behalf. The
     * grant speaks as the bot; the chair is recorded as the one who made it. */
    function test_bot_oauth_authorization() {
        $bot = $this->make_bot("grantbot@" . Contact::BOT_EMAIL_DOMAIN);
        $jr = call_api("=oauthregister", $this->u_empty,
            TestQreq::post_json(["redirect_uris" => ["https://dall.com/"]]));
        xassert_neqq($jr->client_id ?? null, null);

        $code = $this->authorization_code($jr->client_id, "https://dall.com/", $this->u_chair);
        xassert_neqq($code, null);
        $nlog = $this->conf->fetch_ivalue("select count(*) from ActionLog where destContactId=? and contactId=?",
                                          $bot->contactId, $this->u_chair->contactId);

        $vq = TestQreq::user_post($this->u_chair, [
            "authconfirm" => 1, "code" => $code, "authbot" => $bot->email
        ])->set_page("authorize");
        Qrequest::set_main_request($vq);
        xassert($this->authconfirm_redirects($vq));

        $tok = TokenInfo::find($code, $this->conf);
        // the grant is the bot's, and it remembers who made it
        xassert_eqq($tok->data("email"), $bot->email);
        xassert_eqq($tok->data("authorized_by"), $this->u_chair->email);
        // and the chair's act is logged against the bot
        $nlog2 = $this->conf->fetch_ivalue("select count(*) from ActionLog where destContactId=? and contactId=?",
                                           $bot->contactId, $this->u_chair->contactId);
        xassert_eqq($nlog2, $nlog + 1);
        xassert_str_contains($this->conf->fetch_value("select action from ActionLog where destContactId=? order by logId desc limit 1", $bot->contactId),
                             "OAuth authorization");

        $this->delete_bot($bot);
    }

    /** The access token a bot grant produces belongs to the bot, and rotation
     * keeps it: the refresh path re-checks `allow_if` against the account the
     * grant speaks as, which is the same account the consent page checked. */
    function test_bot_oauth_grant_end_to_end() {
        $bot = $this->make_bot("e2ebot@" . Contact::BOT_EMAIL_DOMAIN);
        $jr = $this->dynamic_client_result("https://dall.com/", $this->u_chair,
            ["scope" => "read", "authbot" => $bot->email]);
        xassert_neqq($jr, null, $this->_failure ?? "");
        if ($jr) {
            xassert_eqq($jr->_token->contactId, $bot->contactId);
            xassert($this->conf->user_by_id($jr->_token->contactId)->is_bot());
            $rtok = $this->refresh_access_token();
            xassert_neqq($rtok, null, $this->_failure ?? "");
            xassert_eqq($rtok ? $rtok->contactId : null, $bot->contactId);
        }
        $this->delete_bot($bot);
    }

    /** What the consent page offers and what it accepts are decided
     * separately, so a request that never saw the page gets nothing. */
    function test_bot_oauth_authorization_refusals() {
        $bot = $this->make_bot("refusebot@" . Contact::BOT_EMAIL_DOMAIN);
        $jr = call_api("=oauthregister", $this->u_empty,
            TestQreq::post_json(["redirect_uris" => ["https://dall.com/"]]));
        xassert_neqq($jr->client_id ?? null, null);

        $try = function ($user, $authbot) use ($jr) {
            $code = $this->authorization_code($jr->client_id, "https://dall.com/", $user);
            xassert_neqq($code, null);
            $vq = TestQreq::user_post($user, [
                "authconfirm" => 1, "code" => $code, "authbot" => $authbot
            ])->set_page("authorize");
            Qrequest::set_main_request($vq);
            $redirected = $this->authconfirm_redirects($vq, $user);
            return [$redirected, TokenInfo::find($code, $this->conf)->data("email")];
        };

        // a PC member is not a chair, so they cannot authorize for a bot --
        // and the request does not silently fall back to their own account
        xassert_eqq($try($this->u_mgbaker, $bot->email), [false, null]);
        // an ordinary account named as a bot is not one
        xassert_eqq($try($this->u_chair, $this->u_mgbaker->email), [false, null]);
        // nor is an address with no account
        xassert_eqq($try($this->u_chair, "nobody@" . Contact::BOT_EMAIL_DOMAIN), [false, null]);
        // a disabled bot cannot act, so it cannot be granted to
        $this->conf->qe("update ContactInfo set cflags=cflags|? where contactId=?",
                        Contact::CF_UDISABLED, $bot->contactId);
        $this->conf->invalidate_caches("users", "pc");
        xassert_eqq($try($this->u_chair, $bot->email), [false, null]);
        // enabled again, the same request works: the refusals above are about
        // the account, not about the form
        $this->conf->qe("update ContactInfo set cflags=cflags&~? where contactId=?",
                        Contact::CF_UDISABLED, $bot->contactId);
        $this->conf->invalidate_caches("users", "pc");
        xassert_eqq($try($this->u_chair, $bot->email), [true, $bot->email]);

        // `allow_if` is evaluated against the account the grant speaks as: a
        // chair-only client cannot be granted to a bot, even by a chair, since
        // the refresh path would refuse to rotate that grant on its first use
        $jr2 = call_api("=oauthregister", $this->u_empty,
            TestQreq::post_json(["redirect_uris" => ["https://dchair.com/"]]));
        xassert_neqq($jr2->client_id ?? null, null);
        $code = $this->authorization_code($jr2->client_id, "https://dchair.com/", $this->u_chair);
        xassert_neqq($code, null);
        $vq = TestQreq::user_post($this->u_chair, [
            "authconfirm" => 1, "code" => $code, "authbot" => $bot->email
        ])->set_page("authorize");
        Qrequest::set_main_request($vq);
        xassert(!$this->authconfirm_redirects($vq));
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("email"), null);
        // and the chair's own authorization of that client still works
        $vq = TestQreq::user_post($this->u_chair, ["authconfirm" => 1, "code" => $code])
            ->set_page("authorize");
        Qrequest::set_main_request($vq);
        xassert($this->authconfirm_redirects($vq));
        xassert_eqq(TokenInfo::find($code, $this->conf)->data("email"), $this->u_chair->email);

        $this->delete_bot($bot);
    }

    /** The bot list is a second view of the consent page: a chair reaches it
     * from the account list, and each bot is offered or refused by the same
     * `allow_if` the grant will be held to. */
    function test_consent_form_offers_bots() {
        $bot = $this->make_bot("viewbot@" . Contact::BOT_EMAIL_DOMAIN);
        $render = function ($u, $client_id, $redirect_uri, $rest = []) {
            $q = TestQreq::user_get($u, array_merge([
                "client_id" => $client_id, "redirect_uri" => $redirect_uri,
                "response_type" => "code", "state" => "x", "scope" => "read"
            ], $rest))->set_page("authorize");
            Qrequest::set_main_request($q);
            $cs = $this->conf->page_components($u, $q);
            $ap = new HotCRP\Authorize_Page($u, $q, $cs);
            $cs->set_callable("HotCRP\\Authorize_Page", $ap);
            $old = Navigation::$test_mode;
            Navigation::$test_mode = 2;
            ob_start();
            try {
                $ap->go();
            } catch (PageCompletion $pc) {
            } finally {
                $out = ob_get_clean();
                Navigation::$test_mode = $old;
            }
            return $out;
        };

        $jr = call_api("=oauthregister", $this->u_empty,
            TestQreq::post_json(["redirect_uris" => ["https://dall.com/"]]));
        xassert_neqq($jr->client_id ?? null, null);

        // a chair is offered the list; a PC member is not
        $chair = $render($this->u_chair, $jr->client_id, "https://dall.com/");
        xassert_str_contains($chair, "Sign in as a bot");
        $pc = $render($this->u_mgbaker, $jr->client_id, "https://dall.com/");
        xassert(!str_contains($pc, "Sign in as a bot"));
        // the entry point does not name the accounts it leads to
        xassert(!str_contains($chair, $bot->email));

        // the list itself names them, and posts the bot beside the chair
        $code = $this->authorization_code($jr->client_id, "https://dall.com/", $this->u_chair);
        xassert_neqq($code, null);
        $list = $render($this->u_chair, $jr->client_id, "https://dall.com/",
                        ["code" => $code, "bots" => 1]);
        xassert_str_contains($list, "Sign in as " . $bot->email);
        xassert(preg_match('/authbot=' . preg_quote(urlencode($bot->email), "/") . '/', $list));
        xassert(preg_match('/authemail=' . preg_quote(urlencode($this->u_chair->email), "/") . '/', $list));
        xassert(!preg_match('/<button[^>]*\bdisabled\b[^>]*>Sign in as ' . preg_quote($bot->email, "/") . '/', $list));

        // a client `allow_if` excludes offers the bot but does not enable it:
        // `allow_if` is evaluated against the account the grant speaks as
        $jr2 = call_api("=oauthregister", $this->u_empty,
            TestQreq::post_json(["redirect_uris" => ["https://dchair.com/"]]));
        xassert_neqq($jr2->client_id ?? null, null);
        $code2 = $this->authorization_code($jr2->client_id, "https://dchair.com/", $this->u_chair);
        xassert_neqq($code2, null);
        $list2 = $render($this->u_chair, $jr2->client_id, "https://dchair.com/",
                         ["code" => $code2, "bots" => 1]);
        xassert_str_contains($list2, "Sign in as " . $bot->email);
        xassert(preg_match('/<button[^>]*\bdisabled\b[^>]*>Sign in as ' . preg_quote($bot->email, "/") . '/', $list2));
        xassert_str_contains($list2, "limits which users");

        // a client that can only sign a person in is not offered bots: the
        // grant would carry no API access, and there is no person to name
        $old_clients = $this->conf->opt("oAuthClients");
        $this->conf->set_opt("oAuthClients", array_merge($old_clients, [(object) [
            "name" => "oidconly", "client_id" => "oidconly", "client_secret" => "s",
            "redirect_uris" => ["https://oidconly.example.com/cb"]
        ]]));
        $this->conf->refresh_settings();
        $oidc = $render($this->u_chair, "oidconly", "https://oidconly.example.com/cb",
                        ["scope" => "openid"]);
        xassert_str_contains($oidc, "Sign in as " . $this->u_chair->email);
        xassert(!str_contains($oidc, "Sign in as a bot"));
        $this->conf->set_opt("oAuthClients", $old_clients);
        $this->conf->refresh_settings();

        $this->delete_bot($bot);
        // with no bots at all the option is gone
        $chair2 = $render($this->u_chair, $jr->client_id, "https://dall.com/");
        xassert(!str_contains($chair2, "Sign in as a bot"));
    }

    /** The consent form is rendered before an account is chosen, so an account
     * the client's `allow_if` excludes is shown but not offered — otherwise
     * the only way to learn is to click and be refused. */
    function test_consent_form_marks_ineligible_accounts() {
        $qreq = TestQreq::post_json(["redirect_uris" => ["https://dchair.com/"]]);
        $jr = call_api("=oauthregister", $this->u_empty, $qreq);
        xassert_neqq($jr->client_id ?? null, null);
        $args = ["client_id" => $jr->client_id, "redirect_uri" => "https://dchair.com/",
                 "response_type" => "code", "state" => "x", "scope" => "read"];

        // this client is registered `allow_if: chair`
        $render = function ($u) use ($args) {
            $q = TestQreq::user_get($u, $args)->set_page("authorize");
            Qrequest::set_main_request($q);
            $cs = $this->conf->page_components($u, $q);
            $ap = new HotCRP\Authorize_Page($u, $q, $cs);
            $cs->set_callable("HotCRP\\Authorize_Page", $ap);
            $old = Navigation::$test_mode;
            Navigation::$test_mode = 2;
            ob_start();
            try {
                $ap->go();
            } catch (PageCompletion $pc) {
            } finally {
                $out = ob_get_clean();
                Navigation::$test_mode = $old;
            }
            return $out;
        };

        $chair = $render($this->u_chair);
        xassert_str_contains($chair, "Sign in as " . $this->u_chair->email);
        xassert(!preg_match('/<button[^>]*\bdisabled\b/', $chair));
        xassert(strpos($chair, "limits which users") === false);

        $pc = $render($this->u_mgbaker);
        // the account is still listed — it vanishing would be its own puzzle —
        // but the button is dead and the page says why
        xassert(preg_match('/<button[^>]*\bdisabled\b[^>]*>Sign in as '
            . preg_quote($this->u_mgbaker->email, "/") . '/', $pc)
            || preg_match('/<button[^>]*>Sign in as '
                . preg_quote($this->u_mgbaker->email, "/") . '/', $pc) === 0);
        xassert_str_contains($pc, "disabled");
        xassert_str_contains($pc, "limits which users");
        // and the way forward is still offered
        xassert_str_contains($pc, "Use another account");
    }
}
