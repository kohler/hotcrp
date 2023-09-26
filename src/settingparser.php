<?php
// settingparser.php -- HotCRP conference settings parsing interface
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SettingParser {
    /** @return ?list */
    function values(Si $si, SettingValues $sv) {
        return null;
    }

    /** @return ?list */
    function json_values(Si $si, SettingValues $sv) {
        return null;
    }

    /** @return ?string */
    function placeholder(Si $si, SettingValues $sv) {
        return "auto";
    }

    /** @return ?string */
    function default_value(Si $si, SettingValues $sv) {
        return null;
    }

    /** @return void */
    function set_oldv(Si $si, SettingValues $sv) {
    }

    /** @return void */
    function prepare_oblist(Si $si, SettingValues $sv) {
    }

    /** @return ?list<Si> */
    function member_list(Si $si, SettingValues $sv) {
        return null;
    }

    /** @return bool */
    function apply_req(Si $si, SettingValues $sv) {
        return false;
    }

    /** @return void */
    function store_value(Si $si, SettingValues $sv) {
    }


    /** @param string $v
     * @return -1|float|false */
    static function parse_interval($v) {
        $t = 0;
        $v = trim($v);
        if ($v === ""
            || strtoupper($v) === "N/A"
            || strtoupper($v) === "NONE"
            || $v === "0") {
            return -1;
        } else if (ctype_digit($v)) {
            return ((float) $v) * 60;
        } else if (preg_match('/\A\s*([\d]+):(\d+\.?\d*|\.\d+)\s*\z/', $v, $m)) {
            return ((float) $m[1]) * 60 + (float) $m[2];
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*y(?:ears?|rs?|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 3600 * 24 * 365;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*mo(?:nths?|ns?|s|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 3600 * 24 * 30;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*w(?:eeks?|ks?|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 3600 * 24 * 7;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*d(?:ays?|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 3600 * 24;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*h(?:rs?|ours?|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 3600;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*m(?:inutes?|ins?|)(?![a-z])/i', $v, $m)) {
            $t += ((float) $m[1]) * 60;
            $v = substr($v, strlen($m[0]));
        }
        if (preg_match('/\A\s*(\d+\.?\d*|\.\d+)\s*s(?:econds?|ecs?|)(?![a-z])/i', $v, $m)) {
            $t += (float) $m[1];
            $v = substr($v, strlen($m[0]));
        }
        if (trim($v) == "") {
            return $t;
        } else {
            return false;
        }
    }
}
