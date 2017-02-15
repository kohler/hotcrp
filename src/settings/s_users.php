<?php
// src/settings/s_users.php -- HotCRP settings > users page
// HotCRP is Copyright (c) 2006-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class SettingRenderer_Users extends SettingRenderer {
    function render(SettingValues $sv) {
        global $Me;
        if ($sv->curv("acct_addr"))
            $sv->echo_checkbox("acct_addr", "Collect users’ addresses and phone numbers");
        echo "<h3 class=\"settings g\">Program committee &amp; system administrators</h3>";
        echo "<p><a href='", hoturl("profile", "u=new&amp;role=pc"), "' class='btn'>Create PC account</a> <span class='barsep'>·</span> ",
            "Select a user’s name to edit a profile.</p>\n";
        $pl = new ContactList($Me, false);
        echo $pl->table_html("pcadminx", hoturl("users", "t=pcadmin"));
    }
}

SettingGroup::register("users", "Accounts", 100, new SettingRenderer_Users);
SettingGroup::register_synonym("acc", "users");
