<?php
// cap_authorization.php -- HotCRP authorization tokens
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Authorization_Token {
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
    /** @param Contact $user
     * @param int $expires_in
     * @return TokenInfo */
    static function prepare_bearer($user, $expires_in) {
        $tok = new TokenInfo($user->conf, TokenInfo::BEARER);
        self::set_user_token_pattern($tok, $user, "hct_[30]", "hcT_[30]");
        self::set_expires_in($tok, $expires_in, 86400);
        return $tok;
    }
    /** @param Contact $user
     * @param int $expires_in
     * @return TokenInfo */
    static function prepare_refresh($user, $expires_in) {
        $tok = new TokenInfo($user->conf, TokenInfo::OAUTHREFRESH);
        self::set_user_token_pattern($tok, $user, "hctr_[36]", "hcTr_[36]");
        self::set_expires_in($tok, $expires_in, 604800);
        return $tok;
    }
}
