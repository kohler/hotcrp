<?php
// src/settings/s_sitecontact.php -- HotCRP settings for site contact
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class SiteContact_SettingParser extends SettingParser {
    private $updated = false;

    static function render_site_contact(SettingValues $sv) {
        $sv->echo_entry_group("site_contact_name", null);
        $sv->echo_entry_group("site_contact_email", null, null, "The site contact is the contact point for users if something goes wrong. It defaults to the chair.");
    }

    function set_oldv(SettingValues $sv, Si $si) {
        $user = $sv->conf->site_contact();
        $sv->set_oldv($si->name, $si->name === "site_contact_email" ? $user->email : $user->name());
        return true;
    }

    function parse_req(SettingValues $sv, Si $si) {
        $sv->save($si->name, $sv->base_parse_req($si));
        $sv->request_store_value($si);
    }

    function store_value(SettingValues $sv, Si $si) {
        if (!$this->updated) {
            $this->updated = true;
            $defuser = $sv->conf->default_site_contact();
            $newemail = $sv->newv("site_contact_email") ?? "";
            $newname = $sv->newv("site_contact_name") ?? "";
            if ($defuser
                && ($newemail === "" || $newemail === $defuser->email)
                && ($newname === "" || $newname === $defuser->name())
                && !isset($sv->conf->opt_override["contactName"])
                && !isset($sv->conf->opt_override["contactEmail"])) {
                $sv->save("site_contact_email", null);
                $sv->save("site_contact_name", null);
            }
        }
    }

    function unparse_json(SettingValues $sv, Si $si) {
        return $si->base_unparse_reqv($sv->newv($si->name));
    }
}
