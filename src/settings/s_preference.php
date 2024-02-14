<?php
// settings/s_preference.php -- HotCRP preference settings
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Preference_SettingParser extends SettingParser {
    function apply_req(Si $si, SettingValues $sv) {
        if ($si->name === "preference_min") {
            $v = $sv->newv($si);
            if ($v < -1000000) {
                $sv->error_at($si->name, "<0>Minimum preference must be at least -1000000");
            } else if ($v > 0) {
                $sv->error_at($si->name, "<0>Minimum preference cannot be greater than 0");
            }
        } else if ($si->name === "preference_max") {
            $v = $sv->newv($si);
            if ($v > 1000000) {
                $sv->error_at($si->name, "<0>Maximum preference must be at most 1000000");
            } else if ($v < 0) {
                $sv->error_at($si->name, "<0>Maximum preference cannot be less than 0");
            }
        }
        return false;
    }
}
