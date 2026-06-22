<?php
// cap_manageemail.php -- HotCRP tokens used during email management
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ManageEmail_Token {
    /** @param Contact $viewer
     * @return ?TokenInfo */
    static function prepare($viewer) {
        return (new TokenInfo($viewer->conf, TokenInfo::MANAGEEMAIL))
            ->set_user_from($viewer, false)
            ->set_invalid_in(3600 /* 1 hour */)
            ->set_expires_in(7200 /* 2 hours */)
            ->set_token_pattern("hcme_[16]");
    }
}
