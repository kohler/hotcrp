<?php
// src/oauthclient.php -- HotCRP OAuth 2.0 client definition
// Copyright (c) 2022-2026 Eddie Kohler; see LICENSE.

namespace HotCRP;
use Conf, Qrequest, TokenScope, TokenInfo;

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


    const VALIDATION_BASIC = 0;
    const VALIDATION_DYNAMIC = 1;

    /** @param string $uri
     * @param ?Qrequest $qreq
     * @param 0|1 $validation_level
     * @return bool */
    static function check_redirect_uri($uri, $qreq = null, $validation_level = self::VALIDATION_BASIC) {
        if (strpos($uri, "#") !== false
            || strlen($uri) > 1024) {
            return false;
        }
        if ($validation_level === 1
            && preg_match('/[^\x21-\x7E]/', $uri)) {
            return false;
        }
        if (str_starts_with($uri, "https://")) {
            return true;
        }
        return str_starts_with($uri, "http://")
            && $qreq
            && $qreq->navigation()->host === "localhost"
            && preg_match('/\Ahttp:\/\/localhost(?::\d++)?+\//', $uri);
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
