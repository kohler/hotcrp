<?php
// src/oauthprovider.php -- HotCRP OAuth provider definition
// Copyright (c) 2022-2026 Eddie Kohler; see LICENSE.

namespace HotCRP;
use Conf;

class OAuthProvider {
    /** @var string */
    public $name;
    /** @var ?string */
    public $title;
    /** @var ?string */
    public $scope;
    /** @var string */
    public $client_id;
    /** @var string */
    public $client_secret;
    /** @var ?string */
    public $issuer;
    /** Audiences other than `client_id` that this site accepts in an ID
     * token, or `true` to accept any. An ID token that names an audience
     * outside this set was issued to someone else as well, and is rejected
     * (OpenID Connect Core §3.1.3.7 item 3).
     * @var true|list<string> */
    public $trusted_audiences = [];
    /** Send PKCE (RFC 7636) with authorization requests. Set false only for a
     * provider that rejects the extra parameters.
     * @var bool */
    public $pkce = true;
    /** @var string */
    public $auth_uri;
    /** @var string */
    public $redirect_uri;
    /** @var string */
    public $token_uri;
    /** @var ?string */
    public $token_function;
    /** @var ?bool */
    public $roles;
    /** @var ?object */
    public $group_roles;
    /** @var ?bool */
    public $reset_roles;

    /** @var ?string */
    public $nonce;
    public $require;

    function __construct($name) {
        $this->name = $name;
    }

    /** @return string */
    function title() {
        return $this->title ?? $this->name;
    }

    /** @param Conf $conf
     * @return array<string,object>
     * @suppress PhanAccessReadOnlyPropertty */
    static function list($conf) {
        if ($conf->_oauth_providers === null) {
            $conf->_oauth_providers = array_filter(
                $conf->_xtbuild_resolve([], "oAuthProviders"),
                function ($j) { return !($j->disabled ?? false); }
            );
        }
        return $conf->_oauth_providers;
    }

    /** @param Conf $conf
     * @param ?string $name
     * @return ?OAuthProvider */
    static function find($conf, $name) {
        $authinfo = self::list($conf);
        // null `$name` means find first match
        $name = (array_keys($authinfo))[0] ?? null;
        if ($name === null
            || !($authdata = $authinfo[$name] ?? null)) {
            return null;
        }
        $instance = new OAuthProvider($name);
        $instance->title = $authdata->title ?? null;
        $instance->issuer = $authdata->issuer ?? null;
        $instance->scope = $authdata->scope ?? null;
        $instance->client_id = $authdata->client_id ?? null;
        $instance->client_secret = $authdata->client_secret ?? null;
        $instance->auth_uri = $authdata->auth_uri ?? null;
        $instance->token_uri = $authdata->token_uri ?? null;
        $instance->redirect_uri = $authdata->redirect_uri
            ?? $conf->hoturl("oauth", null, Conf::HOTURL_ABSOLUTE);
        $instance->token_function = $authdata->token_function ?? null;
        $instance->require = $authdata->require ?? null;
        $instance->roles = $authdata->roles ?? false;
        $instance->group_roles = $authdata->group_roles ?? $authdata->group_mappings /* XXX */ ?? null;
        $instance->reset_roles = $authdata->reset_roles ?? $authdata->remove_groups /* XXX */ ?? false;
        $instance->pkce = $authdata->pkce ?? true;
        foreach (["title", "issuer", "scope"] as $k) {
            if ($instance->$k !== null && !is_string($instance->$k))
                return null;
        }
        foreach (["client_id", "client_secret", "auth_uri", "token_uri", "redirect_uri"] as $k) {
            if (!is_string($instance->$k))
                return null;
        }
        $ta = $authdata->trusted_audiences ?? null;
        if ($ta === null || $ta === false) {
            // strict: `client_id` is the only audience this site accepts
        } else if ($ta === true) {
            $instance->trusted_audiences = true;
        } else if (is_string($ta)) {
            $instance->trusted_audiences = [$ta];
        } else if (is_list($ta) && count(array_filter($ta, "is_string")) === count($ta)) {
            $instance->trusted_audiences = $ta;
        } else {
            return null;
        }
        // The ID token returned by the token endpoint is accepted without
        // checking its signature, which OpenID Connect allows only if the
        // token arrives over a trusted channel.
        if (!OAuthClient::secure_uri($instance->token_uri)) {
            return null;
        }
        return $instance;
    }
}
