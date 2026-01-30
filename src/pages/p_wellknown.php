<?php
// pages/p_wellknown.php -- HotCRP cacheability helper
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class WellKnown_Page {
    static function go_nav(NavigationState $nav) {
        $conf = initialize_conf();
        if (!$conf) {
            http_response_code(404);
            return;
        }
        $pc = $nav->path_component(0);
        if ($pc === "oauth-protected-resource") {
            self::oauth_protected_resource($nav, $conf);
        } else if ($pc === "oauth-authorization-server") {
            self::oauth_authorization_server($nav, $conf);
        }
        http_response_code(404);
        return;
    }

    static function headers($age) {
        header("Cache-Control: max-age={$age}, public");
        header("Expires: " . Navigation::http_date(Conf::$now + $age));
        header("Content-Type: application/json");
    }

    static function oauth_protected_resource(NavigationState $nav, Conf $conf) {
        self::headers(604800);
        $site = $conf->opt("paperSite");
        echo json_encode([
            "resource" => "{$site}/api",
            "authorization_servers" => [$site],
            "bearer_methods_supported" => ["header"]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }

    static function oauth_authorization_server(NavigationState $nav, Conf $conf) {
        self::headers(604800);
        $site = $conf->opt("paperSite");
        $j = ["issuer" => $conf->oauth_issuer()];
        if ($conf->opt("oAuthClients")) {
            // enumerate capabilities implied by clients
            $has_dynamic = false;
            $any_scopes = null;
            foreach (HotCRP\Authorize_Page::oauth_clients($conf) as $clj) {
                if (!$has_dynamic
                    && ($clj->dynamic ?? false)
                    && $conf->opt("oAuthDynamicClients")) {
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
            $j["token_endpoint_auth_methods_supported"] = ["client_secret_basic"];
            $j["code_challenge_methods_supported"] = ["S256"];
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
        }
        echo json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        exit(0);
    }
}
