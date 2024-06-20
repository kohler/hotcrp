<?php
// apihelpers.php -- HotCRP API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class APIHelpers {
    /** @param ?string $text
     * @param ?string $field
     * @return Contact */
    static function parse_user($text, Contact $viewer, $field = null) {
        if (!isset($text)
            || $text === ""
            || strcasecmp($text, "me") === 0
            || ($viewer->contactId > 0 && $text === (string) $viewer->contactId)
            || ($viewer->has_email() && strcasecmp($text, $viewer->email) === 0)) {
            return $viewer;
        }
        if (ctype_digit($text)) {
            $u = $viewer->conf->user_by_id(intval($text), USER_SLICE);
        } else {
            $u = $viewer->conf->user_by_email($text, USER_SLICE);
        }
        if ($u) {
            return $u;
        } else if ($viewer->isPC) {
            JsonResult::make_not_found_error($field, "<0>User not found")->complete();
        } else {
            JsonResult::make_permission_error()->complete();
        }
    }

    /** @param ?string $text
     * @param ?PaperInfo $prow
     * @return Contact */
    static function parse_reviewer_for($text, Contact $viewer, $prow) {
        $u = self::parse_user($text, $viewer);
        if ($u->contactId === $viewer->contactId
            || ($prow ? $viewer->can_administer($prow) : $viewer->privChair)) {
            return $u;
        } else {
            JsonResult::make_permission_error()->complete();
        }
    }
}
