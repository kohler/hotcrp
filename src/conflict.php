<?php
// conflict.php -- HotCRP conflict type class
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class Conflict {
    static $type_descriptions = array(0 => "No conflict",
                                      3 => "Advisor/student",
                                      2 => "Recent collaborator",
                                      4 => "Institutional",
                                      5 => "Personal",
                                      6 => "Other");
    static $type_names = array(0 => false,
                               1 => true,
                               2 => "collaborator",
                               3 => "advisor",
                               4 => "institution",
                               5 => "personal",
                               6 => "other",
                               7 => true,
                               CONFLICT_CHAIRMARK => "confirmed",
                               CONFLICT_AUTHOR => "author",
                               CONFLICT_CONTACTAUTHOR => "author");

    static function is_author_mark($ct) {
        return $ct >= CONFLICT_AUTHORMARK && $ct <= CONFLICT_MAXAUTHORMARK;
    }
    static function constrain_editable($ct, $admin) {
        if (is_string($ct))
            $ct = cvtint($ct, 0);
        if ($ct > 0) {
            $max = $admin ? CONFLICT_CHAIRMARK : CONFLICT_MAXAUTHORMARK;
            return max(min($ct, $max), CONFLICT_AUTHORMARK);
        } else
            return 0;
    }
    static function parse($text, $default_yes) {
        if (is_bool($text))
            return $text ? $default_yes : 0;
        $text = strtolower(trim($text));
        if ($text === "none")
            return 0;
        else if (($b = friendly_boolean($text)) !== null)
            return $b ? $default_yes : 0;
        else if ($text === "conflict")
            return $default_yes;
        else if ($text === "collab" || $text === "collaborator" || $text === "recent collaborator")
            return CONFLICT_AUTHORMARK /* 2 */;
        else if ($text === "advisor" || $text === "student" || $text === "advisor/student")
            return 3;
        else if ($text === "institution" || $text === "institutional")
            return 4;
        else if ($text === "personal")
            return 5;
        else if ($text === "other")
            return 6;
        else if ($text === "confirmed" || $text === "chair-confirmed")
            return CONFLICT_CHAIRMARK;
        else
            return false;
    }
}
