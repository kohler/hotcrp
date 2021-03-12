<?php
// src/settings/s_users.php -- HotCRP settings > users page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class Users_SettingRenderer {
    static function render(SettingValues $sv) {
        $sv->render_section("Program committee &amp; system administrators");
        echo '<p><a href="', $sv->conf->hoturl("profile", "u=new&amp;role=pc"), '" class="btn">Create PC accounts</a> <span class="barsep">·</span> ',
            "Select a user’s name to edit a profile.</p>\n";
        $pl = new ContactList($sv->user, false);
        echo $pl->table_html("pcadminx", $sv->conf->hoturl("users", "t=pcadmin"));
    }
}
