<?php
// cap_manageemail.php -- HotCRP review-acceptor capability management
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ManageEmail_Capability {
    /** @param Contact $viewer
     * @return ?TokenInfo */
    static function prepare($viewer) {
        return (new TokenInfo($viewer->conf, TokenInfo::MANAGEEMAIL))
            ->set_user_id($viewer->contactId)
            ->set_invalid_after(3600 /* 1 hour */)
            ->set_expires_after(7200 /* 2 hours */)
            ->set_token_pattern("hcme_[16]");
    }
}
