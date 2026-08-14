<?php
// src/oauthclient.php -- HotCRP OAuth 2.0 client definition
// Copyright (c) 2022-2026 Eddie Kohler; see LICENSE.

namespace HotCRP;
use Conf, TokenScope, TokenInfo;

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
        // check for special characters unless preconfigured
        if ($validation_level !== self::VALIDATION_BASIC
            && preg_match('/[^\x21-\x7E]/', $uri)) {
            return false;
        }
        // `https:` allowed; `http:` to localhost allowed
        return str_starts_with($uri, "https://")
            || self::check_loopback_host($uri);
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


    /** @return array<string,object> */
    static function list(Conf $conf) {
        $clients = $conf->_xtbuild_resolve([], "oAuthClients");
        if (empty($clients) || $conf->opt("oAuthDynamicClients")) {
            return $clients;
        }
        return array_filter($clients, function ($cx) { return !($cx->dynamic ?? false); });
    }
}
