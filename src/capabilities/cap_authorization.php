<?php
// cap_authorization.php -- HotCRP authorization tokens
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Authorization_Token {
    /** Time an invalid bearer or refresh token’s row is retained */
    const BEARER_RETENTION = 604800; // 7 days

    /** Length of the random, but non-secret, grant identifier carried in a
     * prefix of the token’s salt (after `hc[Tt]r?_|hcoc`). The grant ID appears
     * in HotCRP logs and can be extracted from Authorization headers by web
     * servers, so it can correlate actions; but even knowing the grant ID
     * leaves 200 bits of entropy in an access token’s salt (248 in a refresh
     * token’s), too hard to guess. */
    const GRANT_ID_LENGTH = 7;

    /** The grant identifier carried in `$tok->salt`, or null.
     * @param TokenInfo $tok
     * @return ?string */
    static function grant_id($tok) {
        // Only match log-enough real salts (& not `hct_invalid_token`)
        if ($tok && preg_match('/\A(?:hc[Tt]r?+_|hcoc_?+)([A-Za-z]{' . self::GRANT_ID_LENGTH . '})[A-Za-z]{16}/', $tok->salt, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Random bytes left for a token salt that also carries `$grant_id`
     * @param int $bytes
     * @param string $grant_id
     * @return int */
    static private function salt_random_bytes($bytes, $grant_id) {
        return max(16, $bytes - (int) ceil(strlen($grant_id ?? "") * 11 / 16));
    }

    /** @param TokenInfo $token
     * @param Contact $user
     * @param string $pattern
     * @param ?string $cdb_pattern */
    static function set_user_token_pattern($token, $user, $pattern, $cdb_pattern = null) {
        $token->set_user_from($user, $user->is_cdb_user());
        $token->set_token_pattern($token->is_cdb ? $cdb_pattern ?? $pattern : $pattern);
    }
    /** @param TokenInfo $token
     * @param int $exp */
    static function set_expires_in($token, $exp, $delta) {
        if ($exp >= 0) {
            $token->set_invalid_in($exp)->set_expires_in($exp + $delta);
        } else {
            $token->set_invalid_at(0)->set_expires_at(0);
        }
    }
    /** Return true if `$user` still satisfies the `allow_if` that was in force
     * when `$tok` was granted.
     *
     * A client’s `allow_if` limits who may hold its tokens, not merely who may
     * press Approve, so it is checked on every use. The expression is recorded
     * on the token: the user-side terms it names — roles, tags — are what
     * change, and they are evaluated live here, while looking the client up
     * per request would cost a query for a dynamically registered one.
     * @param TokenInfo $tok
     * @param Contact $user
     * @return bool */
    static function check_allow_if($tok, $user) {
        $allow_if = $tok->data("allow_if");
        if ($allow_if === null) {
            return true;
        }
        $xtp = new XtParams($user->conf, $user);
        $xtp->token = $tok;
        return $xtp->check($allow_if);
    }

    /** @param Contact $user
     * @param int $expires_in
     * @param ?TokenInfo $grant_source
     * @return TokenInfo */
    static function prepare_bearer($user, $expires_in, $grant_source = null) {
        $tok = new TokenInfo($user->conf, TokenInfo::BEARER);
        $grant_id = self::grant_id($grant_source) ?? "";
        $n = self::salt_random_bytes(30, $grant_id);
        self::set_user_token_pattern($tok, $user, "hct_{$grant_id}[{$n}]", "hcT_{$grant_id}[{$n}]");
        self::set_expires_in($tok, $expires_in, self::BEARER_RETENTION);
        return $tok;
    }
    /** @param Contact $user
     * @param int $expires_in
     * @param ?TokenInfo $grant_source
     * @return TokenInfo */
    static function prepare_refresh($user, $expires_in, $grant_source = null) {
        $tok = new TokenInfo($user->conf, TokenInfo::OAUTHREFRESH);
        $grant_id = self::grant_id($grant_source) ?? "";
        $n = self::salt_random_bytes(36, $grant_id);
        self::set_user_token_pattern($tok, $user, "hctr_{$grant_id}[{$n}]", "hcTr_{$grant_id}[{$n}]");
        self::set_expires_in($tok, $expires_in, self::BEARER_RETENTION);
        return $tok;
    }
}
