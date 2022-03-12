<?php
// settings/s_shepherds.php -- HotCRP settings > decisions page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Shepherds_SettingParser extends SettingParser {
    static function print_identity(SettingValues $sv) {
        $sv->print_checkbox("shepherd_show", "Show shepherd names to authors");
    }
}
