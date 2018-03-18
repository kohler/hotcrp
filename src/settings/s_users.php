<?php
// src/settings/s_users.php -- HotCRP settings > users page
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class Users_SettingRenderer {
    static function render(SettingValues $sv) {
        if ($sv->curv("acct_addr"))
            $sv->echo_checkbox("acct_addr", "Collect users’ addresses and phone numbers");
        echo "<h3 class=\"settings g\">Program committee &amp; system administrators</h3>";
        echo "<p><a href='", hoturl("profile", "u=new&amp;role=pc"), "' class='btn'>Create PC accounts</a> <span class='barsep'>·</span> ",
            "Select a user’s name to edit a profile.</p>\n";
        $pl = new ContactList($sv->user, false);
        echo $pl->table_html("pcadminx", hoturl("users", "t=pcadmin"));
    }
}
