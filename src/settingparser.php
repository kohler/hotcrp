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
     * @return array{?int,?int,?float} */
    static function parse_duration_yms($v) {
        $v = trim($v);
        if ($v === "") {
            return [null, null, null];
        } else if (strcasecmp($v, "N/A") === 0
                   || strcasecmp($v, "never") === 0) {
            return [null, null, -1.0];
        } else if (strcasecmp($v, "none") === 0) {
            return [null, null, 0.0];
        } else if (is_numeric($v)) {
            return [0, 0, floatval($v)];
        } else if (preg_match('/\A(\d++):(\d++\.?+\d*+|\.\d++)\z/', $v, $m)) {
            return [0, 0, floatval($m[1]) * 60 + floatval($m[2])];
        }
        $lastu = -1;
        $yr = $mo = 0;
        $sec = 0.0;
        $pos = 0;
        $nparsed = 0;
        if (($iso = str_starts_with($v, "P"))) {
            $pos = 1;
        }
        $multipliers = [86400 * 365, 86400 * 30, 86400 * 7, 86400, 3600, 60, 1];
        $unitprefixes = ["y", "mo", "w", "d", "h", "m", "s"];
        while (preg_match('/\G(\s*+|[-:])(\d++\.?+\d*+|\.\d++)\s*+(y(?:ear|r)?+s?+|mo(?:n(?:th)?+)?+s?+|w(?:(?:ee)?+k)?+s?+|da?+y?+s?+|h(?:(?:ou)?+r)?+s?+|m(?:in(?:ute)?+)?+s?+|s(?:ec(?:ond)?+)?+s?+)(?![a-su-z])|\G\s*+(T)/i', $v, $m, 0, $pos)) {
            $pos += strlen($m[0]);
            if (!empty($m[4])) {
                if (!$iso || $lastu > 3) {
                    return [null, null, null];
                }
                $lastu = 3.5;
                continue;
            }
            if (($m[1] === "-" || $m[1] === ":") && !$iso) {
                return [null, null, null];
            }
            $unit = strtolower($m[3]);
            $u = 0;
            while (!str_starts_with($unit, $unitprefixes[$u])) {
                ++$u;
            }
            if ($iso && $u === 5 && $lastu <= 0) {
                $u = 1;
            }
            if ($u <= $lastu) {
                return [null, null, null];
            }
            $lastu = $u;
            $amt = floatval($m[2]);
            if ($amt > 0 && $amt < PHP_INT_MAX) {
                $amti = (int) $amt;
                if ($u === 0) {
                    $yr += $amti;
                    $amt = ($amt - $amti) * 12;
                    $amti = (int) $amt;
                    $u = 1;
                }
                if ($u === 1) {
                    $mo += $amti;
                    $amt = ($amt - $amti) * 30;
                    $u = 3;
                }
            }
            $sec += $amt * $multipliers[$u];
            ++$nparsed;
        }
        if ($nparsed > 0
            && $pos + strspn($v, " \n\r\t\x0C", $pos) === strlen($v)) {
            return [$yr, $mo, $sec];
        }
        return [null, null, null];
    }

    /** @param string $v
     * @return null|float */
    static function parse_duration($v) {
        [$y, $m, $s] = self::parse_duration_yms($v);
        if ($y > 0 || $m > 0) {
            $s += ($y * 365 + $m * 30) * 86400;
        }
        return $s;
    }
}
