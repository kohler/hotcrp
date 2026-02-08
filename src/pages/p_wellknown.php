<?php
// pages/p_wellknown.php -- HotCRP cacheability helper
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class WellKnown_Page {
    static function go_nav(NavigationState $nav) {
        if (isset($_SERVER["HTTP_ORIGIN"])) {
            header("Access-Control-Allow-Origin: *");
        }
        $conf = initialize_conf();
        if (!$conf) {
            self::not_found();
            return;
        }
        $pc = $nav->path_component(0);
        if ($pc === "oauth-protected-resource") {
            self::oauth_protected_resource($nav, $conf);
        } else if ($pc === "oauth-authorization-server") {
            self::oauth_authorization_server($nav, $conf);
        } else {
            self::not_found();
        }
    }

    /** @param int $age */
    static function cache_headers($age) {
        header("Cache-Control: max-age={$age}, public");
        header("Expires: " . Navigation::http_date(Conf::$now + $age));
    }

    static function not_found() {
        self::cache_headers(300);
        http_response_code(404);
        echo "<html><head><title>404 Not Found</title></head><body><center><h1>404 Not Found</h1></center><hr><center>HotCRP</center></body></html>\n";
    }

    static function oauth_protected_resource(NavigationState $nav, Conf $conf) {
        self::cache_headers(604800);
        header("Content-Type: application/json; charset=utf-8");
        $site = $conf->opt("paperSite");
        echo json_encode([
            "resource" => "{$site}/api",
            "authorization_servers" => [$site],
            "bearer_methods_supported" => ["header"]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }

    static function oauth_authorization_server(NavigationState $nav, Conf $conf) {
        if (!$conf->opt("oAuthClients")) {
            self::not_found();
            return;
        }
        self::cache_headers(604800);
        header("Content-Type: application/json; charset=utf-8");
        $site = $conf->opt("paperSite");
        $j = ["issuer" => $conf->oauth_issuer()];
        // enumerate capabilities implied by clients
        $has_dynamic = false;
        $any_scopes = null;
        foreach (HotCRP\Authorize_Page::oauth_clients($conf) as $clj) {
            if (!$has_dynamic
                && ($clj->dynamic ?? false)) {
                $has_dynamic = true;
            }
            if ($clj->scope ?? false) {
                $ts = TokenScope::parse($clj->scope, null);
                $any_scopes = $ts ? ($any_scopes ?? 0) | $ts->any_bits() : ~0;
            }
        }
        $j["authorization_endpoint"] = "{$site}/authorize";
        $j["token_endpoint"] = "{$site}/api/oauthtoken";
        if ($has_dynamic) {
            $j["registration_endpoint"] = "{$site}/api/oauthregister";
        }
        $j["grant_types_supported"] = ["authorization_code"];
        $j["response_types_supported"] = ["code"];
        $j["token_endpoint_auth_methods_supported"] = ["client_secret_basic", "client_secret_post"];
        $j["code_challenge_methods_supported"] = ["S256", "plain"];
        $scopes = ["openid", "email", "profile"];
        if (($any_scopes ?? 0) !== 0) {
            $j["grant_types_supported"][] = "refresh_token";
            if (($any_scopes ?? ~0) === ~0) {
                $scopes[] = "all";
            } else {
                array_push($scopes, ...explode(" ", TokenScope::unparse(new TokenScope($any_scopes))));
            }
        }
        $j["scopes_supported"] = $scopes;
        echo json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }
}
