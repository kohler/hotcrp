<?php
// settings/s_sitecontact.php -- HotCRP settings for site contact
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SiteContact_SettingParser extends SettingParser {
    private $updated = false;

    static function print_site_contact(SettingValues $sv) {
        $sv->print_entry_group("site_contact_name", null);
        $sv->print_entry_group("site_contact_email", null, null, "The site contact is the contact point for users if something goes wrong. It defaults to the chair.");
    }

    /** @param ?string $v
     * @param Si $si */
    static private function cleanstr($v, $si) {
        $iv = $si->name === "site_contact_email" ? "you@example.com" : "Your Name";
        return $v !== $iv ? $v : "";
    }

    function set_oldv(SettingValues $sv, Si $si) {
        $user = $sv->conf->site_contact();
        $s = $si->name === "site_contact_email" ? $user->email : $user->name();
        $sv->set_oldv($si->name, self::cleanstr($s, $si));
    }

    function apply_req(SettingValues $sv, Si $si) {
        $sv->save($si, self::cleanstr($sv->base_parse_req($si), $si));
        $sv->request_store_value($si);
        return true;
    }

    function store_value(SettingValues $sv, Si $si) {
        if (!$this->updated) {
            $this->updated = true;
            $defuser = $sv->conf->default_site_contact();
            $newemail = $sv->newv("site_contact_email") ?? "";
            $ooemail = $sv->conf->opt_override["contactEmail"] ?? "";
            $newname = $sv->newv("site_contact_name") ?? "";
            if ($defuser
                && ($newemail === "" || $newemail === $defuser->email)
                && ($newname === "" || $newname === $defuser->name())
                && ($ooemail === "" || $ooemail === $defuser->email || $ooemail === "you@example.com")) {
                $sv->unsave("site_contact_email");
                $sv->unsave("site_contact_name");
            }
        }
    }
}
