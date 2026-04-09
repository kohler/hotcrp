<?php
// settings/s_users.php -- HotCRP settings > users page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Users_SettingRenderer {
    static function print(SettingValues $sv) {
        echo "<p>", $sv->conf->hotlink("Create PC accounts", "profile", ["u" => "new", "role" => "pc"], ["class" => "btn"]),
            " <span class=\"barsep\">·</span> Select a user’s name to edit a profile.</p>\n";
        $pl = new ContactList($sv->user, false);
        echo $pl->table_html("pcadminx", $sv->conf->hoturl_raw("users", ["t" => "pcadmin"]));
    }
}
