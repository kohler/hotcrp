<?php
// oauth-servertest.php -- test HotCRP’s OAuth authorization server.
//
// `oauth-provider.php` is an authentication provider that HotCRP signs users in
// *through*; the clients it serves are listed in `db.json`’s `clients`. This
// file is the mirror: it signs in *through* HotCRP, so that HotCRP’s
// authorization server is exercised by an implementation that shares no code
// with it. The servers it tests are listed in `db.json`’s `servers`.
//
// That independence is the point — a round trip through HotCRP’s own JWT code
// cannot notice when HotCRP and the specification disagree, since both halves
// agree on the same wrong thing. ID token signatures here are checked by
// lcobucci/jwt, which arrives as a league/oauth2-server dependency.
//
// Routes (all under /servers):
//   /servers                index: the servers under test and the last report
//   /servers/start?mode=M   begin an authorization against one of them;
//                           M is configured|dynamic|document
//   /servers/callback       the redirect_uri; runs the checks and reports
//   /servers/metadata.json  this client’s ID metadata document (mode `document`)

use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use Psr\Http\Message\ServerRequestInterface;

const SERVER_STATE_FILE = __DIR__ . "/servertest-state.json";
const SERVER_CALLBACK = "/servers/callback";


/** Transactions in flight, plus the last report, persisted across requests
 * (each request to `php -S` is a fresh process). */
class ServerTestState {
    /** @var list<object> */
    public $txns = [];
    /** @var ?object */
    public $report;
    /** @var ServerTestState */
    static public $main;

    static function load() {
        self::$main = new ServerTestState;
        $j = json_decode(@file_get_contents(SERVER_STATE_FILE) ? : "null");
        if (is_object($j)) {
            $now = time();
            foreach ($j->txns ?? [] as $t) {
                if (($t->exp ?? 0) >= $now)
                    self::$main->txns[] = $t;
            }
            self::$main->report = $j->report ?? null;
        }
    }

    static function save() {
        file_put_contents(SERVER_STATE_FILE, json_encode([
            "txns" => self::$main->txns, "report" => self::$main->report
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** @param string $state
     * @return ?object */
    static function take_txn($state) {
        foreach (self::$main->txns as $i => $t) {
            if (hash_equals($t->state, $state)) {
                array_splice(self::$main->txns, $i, 1);
                return $t;
            }
        }
        return null;
    }
}


/** A list of named checks with a verdict apiece. */
class ServerTestReport {
    /** @var list<object> */
    public $checks = [];
    /** @var string */
    public $mode;
    /** @var string */
    public $server;

    function __construct($server, $mode) {
        $this->server = $server;
        $this->mode = $mode;
    }

    /** @param bool $ok
     * @param string $name
     * @param string $detail
     * @return bool */
    function check($ok, $name, $detail = "") {
        $this->checks[] = (object) ["ok" => !!$ok, "name" => $name, "detail" => $detail];
        return !!$ok;
    }

    /** @param string $name */
    function skip($name, $detail = "") {
        $this->checks[] = (object) ["ok" => null, "name" => $name, "detail" => $detail];
    }

    /** @return object */
    function finish() {
        $n = $bad = 0;
        foreach ($this->checks as $c) {
            if ($c->ok !== null) {
                ++$n;
                $bad += $c->ok ? 0 : 1;
            }
        }
        return (object) [
            "server" => $this->server, "mode" => $this->mode, "at" => time(), "checks" => $this->checks,
            "ntests" => $n, "nfailed" => $bad
        ];
    }
}


/** Return the servers this installation is configured to test.
 *
 * A server is described the way HotCRP describes its own authentication
 * providers in `$Opt["oAuthProviders"]` — `auth_uri` and `token_uri` are the
 * endpoints that matter, plus `client_id`, `client_secret`, `scope`, and an
 * optional `issuer`. As a shorthand for a HotCRP site, `hotcrp_uri` names the
 * site and the endpoints are discovered from it.
 * @return list<object> */
function server_list() {
    $a = [];
    foreach (My::$dbj->servers ?? [] as $s) {
        if (is_object($s)
            && (isset($s->hotcrp_uri)
                || (isset($s->auth_uri) && isset($s->token_uri))))
            $a[] = $s;
    }
    return $a;
}

/** Return a label for `$cf`.
 * @return string */
function server_label($cf) {
    return $cf->name ?? $cf->hotcrp_uri ?? $cf->auth_uri;
}

/** Resolve the endpoints to use for `$cf`.
 *
 * Explicit `auth_uri` and `token_uri` win over discovery, exactly as they do
 * in `$Opt["oAuthProviders"]`; discovery fills in whatever they leave out, and
 * the conventional HotCRP locations are the last resort so a misconfigured
 * site still produces a report rather than a dead end.
 * @return array{?object,string,object} [metadata, metadata URL, endpoints] */
function server_endpoints($cf) {
    $meta = null;
    $meta_url = "";
    $base = $cf->hotcrp_uri ?? null;
    if ($base !== null) {
        if (!str_ends_with($base, "/")) {
            $base .= "/";
        }
        [$meta, $meta_url] = server_discover($base);
    }
    $e = (object) [
        "authorization" => $cf->auth_uri ?? $meta->authorization_endpoint
            ?? ($base === null ? null : "{$base}authorize"),
        "token" => $cf->token_uri ?? $meta->token_endpoint
            ?? ($base === null ? null : "{$base}api/oauthtoken"),
        "registration" => $meta->registration_endpoint ?? null,
        "issuer" => $cf->issuer ?? $meta->issuer ?? null,
        "api" => null
    ];
    // The API base is needed only to prove the access token works. A HotCRP
    // token endpoint sits beside the rest of the API, so it names the base
    // even when `token_uri` was the only thing configured.
    if ($base !== null) {
        $e->api = "{$base}api/";
    } else if ($e->token !== null && str_ends_with($e->token, "/oauthtoken")) {
        $e->api = substr($e->token, 0, -10);
    }
    return [$meta, $meta_url, $e];
}

/** Return the server named `$name`, or the first configured one.
 * @param ?string $name
 * @return ?object */
function server_config($name = null) {
    $a = server_list();
    foreach ($a as $s) {
        if ($name !== null && ($s->name ?? "") === $name)
            return $s;
    }
    return $name === null ? ($a[0] ?? null) : null;
}

/** Return this server’s own base URI, as seen by the browser and by HotCRP.
 * @return string */
function self_uri(ServerRequestInterface $req) {
    $u = $req->getUri();
    $s = $u->getScheme() . "://" . $u->getHost();
    if (($port = $u->getPort())) {
        $s .= ":{$port}";
    }
    return $s;
}

/** Make an HTTP request. Returns [status, headers, body]; status 0 on failure.
 * @return array{int,array<string,string>,string} */
function server_http($method, $url, $opt = []) {
    $curlh = curl_init();
    curl_setopt($curlh, CURLOPT_URL, $url);
    curl_setopt($curlh, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curlh, CURLOPT_TIMEOUT, 15);
    curl_setopt($curlh, CURLOPT_FOLLOWLOCATION, false);
    if ($method === "POST") {
        curl_setopt($curlh, CURLOPT_POST, true);
        curl_setopt($curlh, CURLOPT_POSTFIELDS, http_build_query($opt["form"] ?? [], "", "&"));
    }
    $hdr = [];
    foreach ($opt["headers"] ?? [] as $k => $v) {
        $hdr[] = "{$k}: {$v}";
    }
    if ($hdr) {
        curl_setopt($curlh, CURLOPT_HTTPHEADER, $hdr);
    }
    $rhdr = [];
    curl_setopt($curlh, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$rhdr) {
        if (($colon = strpos($line, ":")) !== false) {
            $rhdr[strtolower(substr($line, 0, $colon))] = trim(substr($line, $colon + 1));
        }
        return strlen($line);
    });
    $body = curl_exec($curlh);
    $status = curl_errno($curlh) ? 0 : curl_getinfo($curlh, CURLINFO_RESPONSE_CODE);
    return [$status, $rhdr, is_string($body) ? $body : ""];
}

/** @return ?object */
function server_http_json($method, $url, $opt = []) {
    [$status, $hdr, $body] = server_http($method, $url, $opt);
    $j = json_decode($body);
    return is_object($j) ? (object) ["status" => $status, "headers" => $hdr, "body" => $j] : null;
}

/** Fetch HotCRP’s authorization server metadata.
 *
 * RFC 8414 puts the well-known segment after the host, before any site path;
 * HotCRP also answers the older form with the segment appended to the site
 * URL. Try both, then fall back to the endpoints’ conventional locations so a
 * misconfigured site still produces a report rather than a dead end.
 * @param string $base site URL, ending in `/`
 * @return array{?object,string} */
function server_discover($base) {
    $u = parse_url($base);
    $origin = "{$u['scheme']}://{$u['host']}" . (isset($u["port"]) ? ":{$u['port']}" : "");
    $path = rtrim($u["path"] ?? "", "/");
    foreach (["{$origin}/.well-known/oauth-authorization-server{$path}",
              "{$base}.well-known/oauth-authorization-server"] as $url) {
        $r = server_http_json("GET", $url);
        if ($r && $r->status === 200 && isset($r->body->authorization_endpoint)) {
            return [$r->body, $url];
        }
    }
    return [null, ""];
}

/** @return string */
function base64url_decode($t) {
    return base64_decode(strtr($t, "-_", "+/"), false) ? : "";
}


// ---- starting an authorization ----------------------------------------

/** Obtain a client_id (and secret, if any) to use against `$cf` in `$mode`.
 * @return array{?object,?string} [client, error] */
function server_client_identity($cf, $mode, $endpoints, $self_uri) {
    if ($mode === "configured") {
        if (!isset($cf->client_id)) {
            return [null, 'No `client_id` for this server in db.json; register one in $Opt["oAuthClients"] first.'];
        }
        return [(object) [
            "client_id" => $cf->client_id,
            "client_secret" => $cf->client_secret ?? null
        ], null];
    } else if ($mode === "document") {
        // a client identified by a URL serving its own metadata; always public
        return [(object) [
            "client_id" => "{$self_uri}/servers/metadata.json",
            "client_secret" => null
        ], null];
    } else if ($mode === "dynamic") {
        if ($endpoints->registration === null) {
            return [null, "This server does not advertise a `registration_endpoint`. Dynamic registration and metadata-document clients are found through discovery, so this mode needs a `hotcrp_uri`."];
        }
        // dynamic registration takes a JSON body, which `server_http` does not send
        $curlh = curl_init();
        curl_setopt($curlh, CURLOPT_URL, $endpoints->registration);
        curl_setopt($curlh, CURLOPT_POST, true);
        curl_setopt($curlh, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($curlh, CURLOPT_POSTFIELDS, json_encode([
            "client_name" => "HotCRP OAuth server test",
            "redirect_uris" => ["{$self_uri}" . SERVER_CALLBACK],
            "grant_types" => ["authorization_code", "refresh_token"],
            "scope" => $cf->scope ?? "openid"
        ]));
        curl_setopt($curlh, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlh, CURLOPT_TIMEOUT, 15);
        $body = curl_exec($curlh);
        $status = curl_errno($curlh) ? 0 : curl_getinfo($curlh, CURLINFO_RESPONSE_CODE);
        $j = json_decode(is_string($body) ? $body : "null");
        if ($status !== 201 || !is_object($j) || !isset($j->client_id)) {
            return [null, "Registration failed (HTTP {$status}): " . htmlspecialchars((string) $body)];
        }
        return [(object) [
            "client_id" => $j->client_id,
            "client_secret" => $j->client_secret ?? null
        ], null];
    }
    return [null, "Unknown mode."];
}

function server_handle_start(ServerRequestInterface $req, Response $res) {
    $q = $req->getQueryParams();
    $mode = $q["mode"] ?? "configured";
    $cf = server_config($q["server"] ?? null);
    if (!$cf) {
        return server_error_page($res, isset($q["server"])
            ? "No server named `{$q['server']}` in db.json (or localdb.json)."
            : "No `servers` are configured in db.json (or localdb.json).");
    }
    $self_uri = self_uri($req);
    [$meta, $meta_url, $endpoints] = server_endpoints($cf);
    if ($endpoints->authorization === null || $endpoints->token === null) {
        return server_error_page($res, "Could not find this server’s endpoints. Give it `auth_uri` and `token_uri`, or a `hotcrp_uri` that serves authorization server metadata.");
    }

    [$client, $error] = server_client_identity($cf, $mode, $endpoints, $self_uri);
    if (!$client) {
        return server_error_page($res, $error);
    }

    $verifier = rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
    $txn = (object) [
        "state" => bin2hex(random_bytes(16)),
        "nonce" => bin2hex(random_bytes(16)),
        "verifier" => $verifier,
        "mode" => $mode,
        "server" => server_label($cf),
        "client_id" => $client->client_id,
        "client_secret" => $client->client_secret,
        "api" => $endpoints->api,
        "meta_url" => $meta_url,
        "issuer" => $endpoints->issuer,
        "meta" => $meta,
        "token_endpoint" => $endpoints->token,
        "redirect_uri" => "{$self_uri}" . SERVER_CALLBACK,
        "scope" => $cf->scope ?? "openid",
        "exp" => time() + 900
    ];
    ServerTestState::$main->txns[] = $txn;
    ServerTestState::save();

    return redirection($res, $endpoints->authorization, [
        "response_type" => "code",
        "client_id" => $txn->client_id,
        "redirect_uri" => $txn->redirect_uri,
        "scope" => $txn->scope,
        "state" => $txn->state,
        "nonce" => $txn->nonce,
        "code_challenge" => rtrim(strtr(base64_encode(hash("sha256", $verifier, true)), "+/", "-_"), "="),
        "code_challenge_method" => "S256"
    ]);
}


// ---- the callback, and the checks -------------------------------------

/** Verify an ID token with lcobucci/jwt, an implementation that shares no code
 * with HotCRP. Returns a human-readable verdict, or null if the token could
 * not even be parsed. */
function server_verify_id_token(ServerTestReport $rep, $id_token, $txn) {
    $parts = explode(".", $id_token);
    if (count($parts) !== 3) {
        $rep->check(false, "id_token is a three-part JWS", "got " . count($parts) . " parts");
        return null;
    }
    $jose = json_decode(base64url_decode($parts[0]));
    $claims = json_decode(base64url_decode($parts[1]));
    if (!is_object($jose) || !is_object($claims)) {
        $rep->check(false, "id_token header and payload are JSON");
        return null;
    }
    $alg = $jose->alg ?? "";

    if ($txn->client_secret) {
        // A confidential client shares a secret, so the token is signed and
        // the signature is the interoperability question: JWS encodes the MAC
        // octets, not their hexadecimal spelling (RFC 7515 §5.1).
        $rep->check($alg === "HS256", "id_token is signed", "alg={$alg}");
        $expect = rtrim(strtr(base64_encode(
            hash_hmac("sha256", "{$parts[0]}.{$parts[1]}", $txn->client_secret, true)
        ), "+/", "-_"), "=");
        $rep->check(hash_equals($expect, $parts[2]),
            "id_token signature is the base64url of the MAC octets",
            strlen(base64url_decode($parts[2])) . "-byte signature");
        try {
            $parser = new \Lcobucci\JWT\Token\Parser(new \Lcobucci\JWT\Encoding\JoseEncoder);
            $token = $parser->parse($id_token);
            $validator = new \Lcobucci\JWT\Validation\Validator;
            $validator->assert($token, new \Lcobucci\JWT\Validation\Constraint\SignedWith(
                new \Lcobucci\JWT\Signer\Hmac\Sha256,
                \Lcobucci\JWT\Signer\Key\InMemory::plainText($txn->client_secret)
            ));
            $rep->check(true, "id_token verifies under lcobucci/jwt (independent implementation)");
        } catch (\Throwable $ex) {
            $rep->check(false, "id_token verifies under lcobucci/jwt (independent implementation)",
                get_class($ex) . ": " . $ex->getMessage());
        }
    } else {
        // A public client shares no secret, so there is no key to sign with;
        // OpenID Connect allows an unsecured token here because it travels
        // straight from the token endpoint over TLS.
        $rep->check($alg === "none", "public client’s id_token is unsecured", "alg={$alg}");
        $rep->skip("id_token signature", "no client secret, nothing to verify");
    }

    $rep->check(($claims->iss ?? null) === ($txn->issuer ?? $claims->iss ?? null),
        "id_token `iss` matches the advertised issuer", (string) ($claims->iss ?? "(none)"));
    $rep->check(($claims->aud ?? null) === $txn->client_id,
        "id_token `aud` is this client", (string) ($claims->aud ?? "(none)"));
    $rep->check(($claims->nonce ?? null) === $txn->nonce,
        "id_token `nonce` matches the request");
    $rep->check(isset($claims->sub) && $claims->sub !== "",
        "id_token has `sub`", (string) ($claims->sub ?? "(none)"));
    $rep->check(isset($claims->exp) && $claims->exp > time(),
        "id_token is unexpired");
    return $claims;
}

/** @return array{int,?object} */
function server_token_request($txn, $form) {
    $opt = ["form" => $form];
    if ($txn->client_secret) {
        $opt["headers"] = ["Authorization" =>
            "Basic " . base64_encode("{$txn->client_id}:{$txn->client_secret}")];
    } else {
        $opt["form"]["client_id"] = $txn->client_id;
    }
    [$status, $hdr, $body] = server_http("POST", $txn->token_endpoint, $opt);
    $j = json_decode($body);
    return [$status, is_object($j) ? $j : null, $hdr];
}

/** Call an API endpoint on the server under test with a bearer token.
 * @return ?object */
function server_api($txn, $fn, $access_token) {
    return server_http_json("GET", "{$txn->api}{$fn}", [
        "headers" => ["Authorization" => "Bearer {$access_token}"]
    ]);
}

function server_handle_callback(ServerRequestInterface $req, Response $res) {
    $q = $req->getQueryParams();
    $txn = isset($q["state"]) ? ServerTestState::take_txn($q["state"]) : null;
    if (!$txn) {
        // A `state` is good once — a client that accepted the same
        // authorization response twice would have no CSRF defence left
        // (RFC 9700 §4.7.1) — so reloading this page or coming back to it is
        // expected to land here. It says nothing about the server under test.
        return server_error_page($res, "This authorization response was already handled, so there is nothing left to check: a `state` is good once. Reloading the callback lands here, which is the client behaving correctly. Start another run to test again.");
    }
    $rep = new ServerTestReport($txn->server ?? "?", $txn->mode);

    // discovery
    $rep->check($txn->meta_url !== "", "authorization server metadata found",
        $txn->meta_url ? : "fell back to conventional endpoints");
    if ($txn->meta) {
        $rep->check(($txn->meta->authorization_response_iss_parameter_supported ?? false) === true,
            "metadata advertises RFC 9207 `iss` in responses");
        $rep->check(in_array("S256", $txn->meta->code_challenge_methods_supported ?? [], true),
            "metadata advertises PKCE `S256`");
    }

    // authorization response
    if (isset($q["error"])) {
        $rep->check(false, "authorization succeeded",
            $q["error"] . " " . ($q["error_description"] ?? ""));
        $rep->check(($q["state"] ?? null) === $txn->state, "error response carries `state`");
        return server_report_page($res, $rep);
    }
    $rep->check(isset($q["code"]) && $q["code"] !== "", "authorization response carries a code");
    $rep->check(($q["iss"] ?? null) === ($txn->issuer ?? ($q["iss"] ?? null)),
        "authorization response `iss` matches the metadata issuer (RFC 9207)",
        (string) ($q["iss"] ?? "(none)"));
    if (!isset($q["code"])) {
        return server_report_page($res, $rep);
    }

    // token request
    [$status, $tok, $hdr] = server_token_request($txn, [
        "grant_type" => "authorization_code",
        "code" => $q["code"],
        "redirect_uri" => $txn->redirect_uri,
        "code_verifier" => $txn->verifier
    ]);
    if ($status !== 200 || !$tok || !isset($tok->access_token)) {
        $rep->check(false, "token endpoint returned a grant",
            "HTTP {$status}: " . json_encode($tok));
        return server_report_page($res, $rep);
    }
    $rep->check(true, "token endpoint returned a grant");
    $rep->check(($tok->token_type ?? "") === "Bearer", "token_type is Bearer");
    $rep->check(str_contains(strtolower($hdr["cache-control"] ?? ""), "no-store"),
        "token response is `Cache-Control: no-store`");

    // id_token
    $claims = null;
    if (str_contains(" {$txn->scope} ", " openid ")) {
        if (isset($tok->id_token)) {
            $rep->check(true, "token response carries an id_token");
            $claims = server_verify_id_token($rep, $tok->id_token, $txn);
        } else {
            $rep->check(false, "token response carries an id_token");
        }
    } else {
        $rep->skip("id_token", "`openid` was not requested");
    }

    // the access token actually works. This needs an API to call, which a
    // server described only by `auth_uri` and `token_uri` may not name.
    $email = null;
    if ($txn->api !== null) {
        $whoami = server_api($txn, "whoami", $tok->access_token);
        $email = $whoami->body->email ?? null;
        $rep->check($whoami && $whoami->status === 200 && $email,
            "access token is accepted by the API", (string) ($email ?? "no email"));
        if ($claims && $email) {
            $rep->check(strcasecmp((string) ($claims->sub ?? ""), $email) === 0
                        || strcasecmp((string) ($claims->email ?? ""), $email) === 0,
                "id_token identifies the same user as the API");
        }
    } else {
        $rep->skip("access token is accepted by the API",
            "no API base; configure `hotcrp_uri` to check this");
    }

    // refresh rotation, and replay of the token it replaced
    if (isset($tok->refresh_token)) {
        [$status2, $tok2, ] = server_token_request($txn, [
            "grant_type" => "refresh_token",
            "refresh_token" => $tok->refresh_token
        ]);
        $ok2 = $status2 === 200 && $tok2 && isset($tok2->access_token);
        $rep->check($ok2, "refresh token yields a new grant", "HTTP {$status2}");
        if ($ok2) {
            $rep->check(($tok2->refresh_token ?? null) !== $tok->refresh_token,
                "refresh token rotates (RFC 9700 §4.14.2)");
            if ($txn->api !== null) {
                $w2 = server_api($txn, "whoami", $tok2->access_token);
                $rep->check($w2 && $w2->status === 200, "the refreshed access token works");
            }

            // replaying the spent refresh token must fail *and* revoke the chain
            [$status3, $tok3, ] = server_token_request($txn, [
                "grant_type" => "refresh_token",
                "refresh_token" => $tok->refresh_token
            ]);
            $rep->check($status3 >= 400 && ($tok3->error ?? "") === "invalid_grant",
                "replaying a spent refresh token is refused", "HTTP {$status3}");
            if ($txn->api !== null) {
                $w3 = server_api($txn, "whoami", $tok2->access_token);
                $rep->check($w3 && $w3->status === 401,
                    "replay revokes the live token too", "HTTP " . ($w3->status ?? 0));
            }
        }
    } else {
        $rep->skip("refresh token", "none issued");
    }

    // replaying the authorization code must fail and revoke what it produced
    [$status4, $tok4, ] = server_token_request($txn, [
        "grant_type" => "authorization_code",
        "code" => $q["code"],
        "redirect_uri" => $txn->redirect_uri,
        "code_verifier" => $txn->verifier
    ]);
    $rep->check($status4 >= 400 && ($tok4->error ?? "") === "invalid_grant",
        "replaying the authorization code is refused (RFC 9700 §4.2.4)", "HTTP {$status4}");

    return server_report_page($res, $rep);
}


// ---- pages ------------------------------------------------------------

const PAGE_STYLE = '<style>body { font: 15px/1.5 system-ui, sans-serif; margin: 2rem auto; max-width: 54rem; padding: 0 1rem }
code, pre { font-family: ui-monospace, monospace; font-size: 90% }
li { margin: .2rem 0 } .pass::marker { content: "PASS " } .fail::marker { content: "FAIL " }
.skip::marker { content: "SKIP " } ul { list-style-position: inside; padding-left: 0 }
li.fail { color: #b00 } li.skip { color: #777 } li.pass { color: #060 }
.detail { color: #666 } .sum-bad { color: #b00; font-weight: bold } .sum-ok { color: #060; font-weight: bold }
a.start { display: inline-block; margin-right: 1rem; padding: .4rem .8rem; border: 1px solid #ccc; border-radius: 5px; text-decoration: none }</style>';

function server_error_page(Response $res, $message) {
    $body = "<html><head><title>OAuth server test</title>" . PAGE_STYLE . "</head><body>"
        . "<h1>OAuth server test</h1><p class=\"sum-bad\">" . htmlspecialchars($message) . "</p>"
        . "<p><a href=\"/servers\">Back</a></p></body></html>";
    return $res->withStatus(400)->withBody(Stream::create($body));
}

function server_report_page(Response $res, ServerTestReport $rep) {
    ServerTestState::$main->report = $rep->finish();
    ServerTestState::save();
    return redirection($res, "/servers", []);
}

function server_handle_index(ServerRequestInterface $req, Response $res) {
    $self = self_uri($req);
    $servers = server_list();
    ob_start();
    echo '<html><head><title>OAuth server test</title>', PAGE_STYLE, '</head><body>',
        '<h1>OAuth server test</h1>',
        '<p>This half of the test server signs in <em>through</em> a HotCRP site, ',
        'so that site’s authorization server is checked by code that shares nothing ',
        'with it.</p>',
        '<p>Redirect URI to register: <code>', htmlspecialchars($self . SERVER_CALLBACK), '</code><br>',
        'Client ID metadata document: <code>', htmlspecialchars("{$self}/servers/metadata.json"), '</code></p>';
    if (empty($servers)) {
        echo '<p class="sum-bad">No <code>servers</code> configured in <code>db.json</code>.</p>';
    }
    foreach ($servers as $s) {
        $sel = isset($s->name) ? "&amp;server=" . urlencode($s->name) : "";
        echo '<h2>', htmlspecialchars(server_label($s)), '</h2>',
            '<p><code>', htmlspecialchars($s->hotcrp_uri ?? $s->auth_uri), '</code></p>',
            '<p><a class="start" href="/servers/start?mode=configured', $sel, '">Configured client</a>',
            '<a class="start" href="/servers/start?mode=dynamic', $sel, '">Dynamic registration</a>',
            '<a class="start" href="/servers/start?mode=document', $sel, '">Metadata document</a></p>';
    }
    if (($r = ServerTestState::$main->report)) {
        $cls = $r->nfailed ? "sum-bad" : "sum-ok";
        echo '<h2>Last run — <code>', htmlspecialchars($r->server ?? "?"), '</code>, mode <code>',
            htmlspecialchars($r->mode), '</code>, ', date("Y-m-d H:i:s", $r->at), '</h2>',
            '<p class="', $cls, '">', $r->ntests - $r->nfailed, '/', $r->ntests, ' checks passed</p><ul>';
        foreach ($r->checks as $c) {
            $cls = $c->ok === null ? "skip" : ($c->ok ? "pass" : "fail");
            echo '<li class="', $cls, '">', htmlspecialchars($c->name);
            if (($c->detail ?? "") !== "") {
                echo ' <span class="detail">— ', htmlspecialchars($c->detail), '</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '</body></html>';
    return $res->withStatus(200)->withBody(Stream::create(ob_get_clean()));
}

function server_handle_metadata_document(ServerRequestInterface $req, Response $res) {
    $self = self_uri($req);
    $j = [
        "client_id" => "{$self}/servers/metadata.json",
        "client_name" => "HotCRP OAuth server test",
        "client_uri" => "{$self}/servers",
        "redirect_uris" => ["{$self}" . SERVER_CALLBACK],
        "grant_types" => ["authorization_code", "refresh_token"],
        "response_types" => ["code"],
        "token_endpoint_auth_method" => "none",
        "scope" => server_config()->scope ?? "openid"
    ];
    return $res->withStatus(200)
        ->withHeader("Content-Type", "application/json")
        ->withBody(Stream::create(json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"));
}


/** @return ?Response */
function server_handle_req(ServerRequestInterface $req, Response $res) {
    $path = $req->getUri()->getPath();
    if ($path !== "/servers" && !str_starts_with($path, "/servers/")) {
        return null;
    }
    ServerTestState::load();
    if ($path === "/servers" || $path === "/servers/") {
        return server_handle_index($req, $res);
    } else if ($path === "/servers/start") {
        return server_handle_start($req, $res);
    } else if ($path === SERVER_CALLBACK) {
        return server_handle_callback($req, $res);
    } else if ($path === "/servers/metadata.json") {
        return server_handle_metadata_document($req, $res);
    }
    return server_error_page($res, "No such server-test route.");
}
