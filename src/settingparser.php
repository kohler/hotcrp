<?php
// settingparser.php -- HotCRP conference settings parsing interface
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

// A SettingParser implements custom logic for a setting or group of
// settings; `Si::$parser_class` names the relevant subclass.
// See `devel/manual/settings.md`.
class SettingParser {
    /** Return allowed values when `$si` has `"values": "auto"`.
     * @return ?list */
    function values(Si $si, SettingValues $sv) {
        return null;
    }

    /** Return allowed JSON values when `$si` has `"json_values": "auto"`.
     * @return ?list */
    function json_values(Si $si, SettingValues $sv) {
        return null;
    }

    /** Return the placeholder when `$si` has `"placeholder": "auto"`.
     * @return ?string */
    function placeholder(Si $si, SettingValues $sv) {
        return "auto";
    }

    /** Return the default value when `$si` has `"default_value": "auto"`.
     * @return ?string */
    function default_value(Si $si, SettingValues $sv) {
        return null;
    }

    /** Compute the old value of `$si` and store it with
     * `$sv->set_oldv`. Required for `object` settings.
     * @return void */
    function set_oldv(Si $si, SettingValues $sv) {
    }

    /** Populate object-list setting `$si` by calling `$sv->append_oblist`.
     * @return void */
    function prepare_oblist(Si $si, SettingValues $sv) {
    }

    /** Return the members of `object` setting `$si` for JSON export
     * (`null` means use the members defined in `settinginfo.json`).
     * @return ?list<Si> */
    function member_list(Si $si, SettingValues $sv) {
        return null;
    }

    /** Parse this request’s value for `$si` and record changes with
     * `$sv->save` and friends. Return `true` if the request was handled;
     * `false` falls through to default parsing.
     * @return bool */
    function apply_req(Si $si, SettingValues $sv) {
        return false;
    }

    /** Check pending values after all parsing; scheduled by
     * `$sv->request_validate($si)`. Runs with pending values visible
     * through `$sv->conf`.
     * @return void */
    function validate(Si $si, SettingValues $sv) {
    }

    /** Apply database side effects during the locked save; scheduled by
     * `$sv->request_store_value($si)`.
     * @return void */
    function store_value(Si $si, SettingValues $sv) {
    }


    /** @param string $v
     * @return null|float */
    static function parse_duration($v) {
        $v = trim($v);
        if ($v === ""
            || strtoupper($v) === "N/A"
            || strtoupper($v) === "NONE"
            || $v === "0") {
            return -1.0;
        } else if (is_numeric($v)) {
            return floatval($v);
        } else if (preg_match('/\A\s*([\d]+):(\d+\.?\d*|\.\d+)\s*\z/', $v, $m)) {
            return ((float) $m[1]) * 60 + (float) $m[2];
        }
        $t = 0.0;
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
        if (trim($v) === "") {
            return $t;
        }
        return null;
    }

    /** @param string $v
     * @return -1|float|false */
    static function parse_interval($v) {
        return self::parse_duration($v);
    }
}
