<?php
// src/oauthclient.php -- HotCRP OAuth 2.0 client definition
// Copyright (c) 2022-2026 Eddie Kohler; see LICENSE.

namespace HotCRP;
use Conf, TokenScope, TokenInfo, UnicodeHelper;

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
    /** @var ?OAuthClientDocument */
    public $client_document;
    /** @var ?bool */
    public $is_cdb;
    /** @var null|int|string */
    public $access_token_expires_in;
    /** @var null|int|string */
    public $refresh_token_expires_in;
    /** @var ?non-empty-string */
    public $scope;
    /** @var bool */
    public $only_openid;
    /** @var mixed */
    public $allow_if;
    /** @var list<string> */
    public $redirect_uris = [];

    /** @var ?string */
    public $requested_scope;
    /** True if the client registered itself rather than being configured by
     * this site, so its name is whatever it chose to call itself.
     * @var bool */
    public $self_registered = false;
    /** @var ?TokenInfo */
    public $token;

    /** @param object $x */
    function __construct($x) {
        $this->name = $x->name ?? null;
        $this->title = $x->title ?? null;
        $this->client_id = $x->client_id ?? null;
        // `client_secret` is either a shared secret or absent; an empty one
        // is absent, so that "public client" means the same thing to the token
        // endpoint's authentication check and to `make_id_token`, which would
        // otherwise sign with the empty key
        $csec = $x->client_secret ?? null;
        $this->client_secret = is_string($csec) && $csec !== "" ? $csec : null;
        $this->client_uri = $x->client_uri ?? null;
        $this->is_cdb = $x->is_cdb ?? false;
        $this->access_token_expires_in = $x->access_token_expires_in ?? null;
        $this->refresh_token_expires_in = $x->refresh_token_expires_in ?? null;
        $this->scope = $x->scope ?? null;
        if ($this->scope !== null && trim($this->scope) === "") {
            $this->scope = null;
        }
        $this->only_openid = $this->scope === null
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
        $oac->self_registered = true;
        $oac->client_id = $ctok->salt;
        $csec = $ctok->data("client_secret");
        $oac->client_secret = is_string($csec) && $csec !== "" ? $csec : null;
        $oac->redirect_uris = $ctok->data("redirect_uris");
        $oac->requested_scope = $ctok->data("requested_scope");
        return $oac;
    }

    /** Make a client whose `client_id` is a client ID metadata document URL.
     * The client’s metadata is filled in later by `load_document`.
     * @param object $x
     * @param OAuthClientDocument $cdoc
     * @return OAuthClient */
    static function make_metadata_document($x, $cdoc) {
        $oac = new OAuthClient($x);
        $oac->client_id = $cdoc->client_id;
        $oac->self_registered = true;
        $oac->client_document = $cdoc;
        $oac->client_secret = null;
        $oac->client_uri = null;
        $oac->redirect_uris = [];
        return $oac;
    }

    /** @return array<string,object> */
    static function list(Conf $conf) {
        $clients = $conf->_xtbuild_resolve([], "oAuthClients");
        if (empty($clients)) {
            return $clients;
        }
        // A metadata document client is identified by a URL this site must
        // check before fetching, so without `OAuthClientDocument::supported`
        // there is no such client—and, in particular, nothing to advertise.
        $flags = ($conf->opt("oAuthDynamicClients") ? 1 : 0)
            | ($conf->opt("oAuthMetadataDocumentClients")
               && OAuthClientDocument::supported() ? 2 : 0);
        return array_filter($clients, function ($cx) use ($flags) {
            return (($flags & 1) !== 0 || !($cx->dynamic ?? false))
                && (($flags & 2) !== 0 || !($cx->metadata_document ?? false))
                && !($cx->disabled ?? false);
        });
    }

    /** Fetch this client's metadata document and apply it.
     * @return bool */
    function load_document() {
        assert(!!$this->client_document);
        if (!$this->client_document->load()) {
            return false;
        }
        $docj = $this->client_document->document;
        $this->title = $docj->client_name ?? $this->title;
        $this->client_uri = $docj->client_uri ?? null;
        $this->redirect_uris = $docj->redirect_uris ?? [];
        $this->requested_scope = $docj->scope ?? null;
        return true;
    }

    /** @return ?object */
    function token_document() {
        $x = [];
        if (($doc = $this->client_document->document ?? null)) {
            foreach (["client_name", "client_uri", "redirect_uris", "scope"] as $k) {
                if (isset($doc->$k))
                    $x[$k] = $doc->$k;
            }
        }
        return empty($x) ? null : (object) $x;
    }


    /** @return string */
    function title_text() {
        if ($this->title !== null) {
            // a self-registered client chose this string; bidi controls in it
            // would reorder the one page whose job is naming who is asking
            return UnicodeHelper::strip_bidi($this->title);
        } else if ($this->client_document) {
            return $this->client_document->host();
        }
        return $this->name;
    }

    /** @return string */
    function title_html() {
        return htmlspecialchars($this->title_text());
    }

    /** @param string $redirect_uri
     * @return bool */
    function public_client($redirect_uri) {
        // A client with no secret cannot prove who it is, so PKCE must protect
        // the code instead (RFC 8252 §8.5). Metadata document clients never
        // have a secret; neither does a configured client whose
        // `client_secret` was left out.
        return $this->client_secret === null
            || str_starts_with($redirect_uri, "http://") /* => localhost b/c of check_redirect_uri() */;
    }


    /** Redirect URI configured by this site’s administrator. */
    const VALIDATION_BASIC = 0;
    /** Redirect URI supplied by a client registering dynamically. */
    const VALIDATION_DYNAMIC = 1;

    /** @param string $uri
     * @param 0|1 $validation_level
     * @return bool */
    static function check_redirect_uri($uri, $validation_level = self::VALIDATION_BASIC) {
        if (strpos($uri, "#") !== false
            || strlen($uri) > 1024) {
            return false;
        }
        // Check for special characters unless preconfigured. 0x5C `\` is
        // excluded: browsers read it as the `/` that ends an authority, so the
        // host of `http://evil.com\@localhost/` is `evil.com` to a browser and
        // `localhost` to `parse_url` and `check_loopback_host`.
        if ($validation_level !== self::VALIDATION_BASIC
            && preg_match('/[^\x21-\x5B\x5D-\x7E]/', $uri)) {
            return false;
        }
        return self::secure_uri($uri);
    }

    /** Return true if `$uri` is an `https` URL, or an `http` URL to the
     * loopback interface. A loopback request never leaves the machine, so it
     * has no network for an attacker to sit on and needs no TLS
     * (RFC 8252, section 8.3).
     * @param string $uri
     * @return bool */
    static function secure_uri($uri) {
        return str_starts_with($uri, "https://")
            || self::check_loopback_host($uri) !== null;
    }

    /** If `$uri` is an `http://` URL to one of the precise loopback spellings
     * `localhost`, `127.0.0.1`, and `[::1]`, possibly with a port, then
     * return an array of two positions: the first position after the hostname,
     * and the first position after the port.
     * @param string $uri
     * @return ?array{int,int} */
    static private function check_loopback_host($uri) {
        if (!str_starts_with($uri, "http://")) {
            return null;
        }
        $delim = 7 + strcspn($uri, "/?@", 7);
        $len = strlen($uri);
        $hpos = ($delim !== $len && $uri[$delim] === "@" ? $delim + 1 : 7);
        if (substr_compare($uri, "localhost", $hpos, 9, true) === 0
            || substr_compare($uri, "127.0.0.1", $hpos, 9) === 0) {
            $ppos = $hpos + 9;
        } else if (substr_compare($uri, "[::1]", $hpos, 5) === 0) {
            $ppos = $hpos + 5;
        } else {
            return null;
        }
        $xpos = $ppos;
        if ($xpos !== $len && $uri[$xpos] === ":") {
            $xpos += 1 + strspn($uri, "0123456789", $xpos + 1);
        }
        if ($xpos !== $len && ($ch = $uri[$xpos]) !== "/" && $ch !== "?") {
            return null;
        }
        return [$ppos, $xpos];
    }

    /** The host that identifies this client for policy purposes, or null.
     * For metadata-document clients, this is that metadata document’s host;
     * for other clients, it’s taken from the first redirect_uri. Localhost
     * spellings are normalized to `localhost`.
     * @param ?string $redirect_uri
     * @return ?string */
    function identity_host(?TokenInfo $tok = null) {
        if ($this->client_document) {
            return strtolower($this->client_document->host());
        }
        $redirect_uri = $tok ? $tok->data("redirect_uri") : null;
        $uri = $redirect_uri ?? ($this->redirect_uris[0] ?? null);
        if ($uri === null || $uri === "") {
            return null;
        }
        $host = parse_url($uri, PHP_URL_HOST) ?? $uri;
        if ($host === "localhost" || $host === "127.0.0.1" || $host === "[::1]") {
            return "localhost";
        }
        return strtolower($host);
    }

    /** Return true if `$uri` is one of this client's registered redirect URIs.
     *
     * A loopback redirect URI matches whatever port the request uses. A
     * program running on the user's computer takes an ephemeral port from the
     * operating system when it opens its listener, so it cannot know that port
     * in advance and register it; an authorization server must allow any port
     * for a loopback URI (RFC 8252, section 7.3). The host still matters:
     * `localhost` and `127.0.0.1` are different origins to a browser.
     * @param string $uri
     * @return bool */
    function has_redirect_uri($uri) {
        return in_array($uri, $this->redirect_uris, true)
            || (($pr = self::check_loopback_host($uri))
                && in_array(substr($uri, 0, $pr[0]) . substr($uri, $pr[1]), $this->redirect_uris, true));
    }
}
