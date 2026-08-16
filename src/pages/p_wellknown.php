<?php
// pages/p_wellknown.php -- HotCRP cacheability helper
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class WellKnown_Page {
    static function go_nav(NavigationState $nav) {
        if (isset($_SERVER["HTTP_ORIGIN"])) {
            Navigation::header("Access-Control-Allow-Origin: *");
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
        Navigation::header("Cache-Control: max-age={$age}, public");
        Navigation::header("Expires: " . Navigation::http_date(Conf::$now + $age));
    }

    static function not_found() {
        self::cache_headers(300);
        Navigation::http_response_code(404);
        echo "<html><head><title>404 Not Found</title></head><body><center><h1>404 Not Found</h1></center><hr><center>HotCRP</center></body></html>\n";
    }

    const OAPSTRLEN = 25; /* strlen("/oauth-protected-resource") */
    static function oauth_protected_resource(NavigationState $nav, Conf $conf) {
        if (!$conf->opt("oAuthClients")) {
            self::not_found();
            return;
        }

        // check that this is an API path
        $site = $conf->opt("paperSite");
        $sitenav = NavigationState::make_base($site);
        $bplen = strlen($sitenav->base_path);
        if (substr_compare($nav->path, $sitenav->base_path, self::OAPSTRLEN, $bplen) !== 0
            || !preg_match('/\G(?:u\/\d++\/)?+api(?:\/(?:\d++|new))?+\/?([^\/?]*+)/', $nav->path, $m, 0, self::OAPSTRLEN + $bplen)) {
            self::not_found();
            return;
        }

        // look up scope for requested API
        if ($m[1] === "") {
            $bits = -1;
        } else {
            $funcs = ($conf->api_map())[$m[1]] ?? null;
            if (empty($funcs)) {
                self::not_found();
                return;
            }
            $bits = 0;
            foreach ($funcs as $gj) {
                if (!isset($gj->scope)) {
                    $bits = -1;
                } else if (is_string($gj->scope)) {
                    $bits |= TokenScope::parse_basic($gj->scope);
                } else if ($gj->post ?? false) {
                    $bits |= TokenScope::S_OTH_WRITE;
                } else {
                    $bits |= TokenScope::S_OTH_READ;
                }
            }
        }
        $scopes = ["openid", "email", "profile"];
        if ($bits === -1) {
            $scopes[] = "all";
        } else if ($bits !== 0) {
            array_push($scopes, ...TokenScope::unparse_bits($bits));
        }

        self::cache_headers(604800);
        Navigation::header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "resource" => $sitenav->server . substr($nav->path, self::OAPSTRLEN),
            "authorization_servers" => [$conf->oauth_issuer()],
            "bearer_methods_supported" => ["header"],
            "scopes_supported" => $scopes
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
    }

    static function oauth_authorization_server(NavigationState $nav, Conf $conf) {
        if (!$conf->opt("oAuthClients")) {
            self::not_found();
            return;
        }
        self::cache_headers(604800);
        Navigation::header("Content-Type: application/json; charset=utf-8");
        $site = $conf->opt("paperSite");
        $j = ["issuer" => $conf->oauth_issuer()];
        // enumerate capabilities implied by clients
        $has_dynamic = $has_mdoc = false;
        $scope_bits = 0;
        foreach (HotCRP\OAuthClient::list($conf) as $clj) {
            if ($clj->metadata_document ?? false) {
                $has_mdoc = true;
            } else if ($clj->dynamic ?? false) {
                $has_dynamic = true;
            }
            if ($scope_bits !== ~0
                && ($clj->scope ?? false)) {
                $ts = TokenScope::parse($clj->scope, null);
                $scope_bits = $ts ? $scope_bits | $ts->any_bits() : ~0;
            }
        }
        $j["authorization_endpoint"] = "{$site}/authorize";
        $j["token_endpoint"] = "{$site}/api/oauthtoken";
        $j["revocation_endpoint"] = "{$site}/api/oauthrevoke";
        if ($has_dynamic) {
            $j["registration_endpoint"] = "{$site}/api/oauthregister";
        }
        if ($has_mdoc) {
            $j["client_id_metadata_document_supported"] = true;
        }
        $j["grant_types_supported"] = ["authorization_code"];
        $j["authorization_response_iss_parameter_supported"] = true;
        $j["response_types_supported"] = ["code"];
        $j["token_endpoint_auth_methods_supported"] = ["client_secret_basic", "client_secret_post"];
        if ($has_mdoc) {
            // clients identified by metadata document are public clients
            $j["token_endpoint_auth_methods_supported"][] = "none";
        }
        $j["revocation_endpoint_auth_methods_supported"] = $j["token_endpoint_auth_methods_supported"];
        $j["code_challenge_methods_supported"] = ["S256", "plain"];
        $scopes = ["openid", "email", "profile"];
        if ($scope_bits !== 0) {
            $j["grant_types_supported"][] = "refresh_token";
            if ($scope_bits === ~0) {
                $scopes[] = "all";
            } else {
                array_push($scopes, ...TokenScope::unparse_bits($scope_bits));
            }
        }
        $j["scopes_supported"] = $scopes;
        echo json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
        Navigation::complete();
    }
}
