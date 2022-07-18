<?php
// cap_bearer.php -- HotCRP bearer tokens
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Bearer_Capability {
    /** @param Conf $conf
     * @param string $header
     * @return ?TokenInfo */
    static function header_token($conf, $header) {
        if (!preg_match('/\A\s*Bearer\s+(hct_[a-zA-Z0-9]+)\s*\z/i', $header, $m)) {
            return null;
        }
        $tok = TokenInfo::find($m[1], $conf, true)
            ?? TokenInfo::find($m[1], $conf, false);
        if ($tok !== null
            && $tok->capabilityType === TokenInfo::BEARER
            && $tok->is_active()) {
            return $tok;
        } else {
            return null;
        }
    }
}
