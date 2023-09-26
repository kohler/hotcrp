<?php
// settings/s_sitecontact.php -- HotCRP settings for site contact
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class SiteContact_SettingParser extends SettingParser {
    private $updated = false;

    static function print_site_contact(SettingValues $sv) {
        $sv->print_entry_group("site_contact_email", null, [
            "hint" => "The site contact is the contact point for users if something goes wrong. It defaults to the chair."
        ]);
        $sv->print_entry_group("site_contact_name", null);
    }

    /** @param string $v
     * @param Si $si */
    static private function cleanstr($v, $si) {
        $iv = $si->name === "site_contact_email" ? "you@example.com" : "Your Name";
        return $v !== $iv ? $v : "";
    }

    /** @param SettingValues $sv
     * @param string|Si $id
     * @return string */
    static private function basev($sv, $id) {
        $si = is_string($id) ? $sv->si($id) : $id;
        $opt = substr($si->storage_name(), 4);
        if (array_key_exists($opt, $si->conf->opt_override)) {
            return $si->conf->opt_override[$opt];
        } else {
            return $si->conf->opt($opt) ?? "";
        }
    }

    function set_oldv(Si $si, SettingValues $sv) {
        $user = $sv->conf->site_contact();
        $s = $si->name === "site_contact_email" ? $user->email : $user->name();
        $sv->set_oldv($si->name, self::cleanstr($s, $si));
    }

    function apply_req(Si $si, SettingValues $sv) {
        if (($creqv = $sv->base_parse_req($si)) !== null) {
            $creqv = self::cleanstr($creqv, $si);
            if ($creqv === "") {
                $creqv = self::basev($sv, $si) ?? "";
            }
            $sv->save($si, $creqv);
            $sv->request_store_value($si);
        }
        return true;
    }

    function store_value(Si $si, SettingValues $sv) {
        if ($this->updated) {
            return;
        }
        $this->updated = true;
        // try to avoid saving $Opt["contactName"/"contactEmail"]:
        // If email is default contact email
        $defuser = $sv->conf->default_site_contact();
        if (!$defuser) {
            return;
        }
        $newemail = $sv->newv("site_contact_email") ?? "";
        $oldemail = self::basev($sv, "site_contact_email");
        if ($newemail !== ""
            && $newemail !== $oldemail
            && strcasecmp($newemail, $defuser->email) !== 0) {
            return;
        }
        // and name is default contact name,
        $newname = $sv->newv("site_contact_name") ?? "";
        $oldname = self::basev($sv, "site_contact_name");
        if ($newname !== ""
            && $newname !== $oldname
            && $newname !== $defuser->name()) {
            return;
        }
        // save contactName and contactEmail as empty strings
        if ($oldemail === "you@example.com") {
            $sv->save("site_contact_email", $oldemail);
        } else {
            $sv->save("site_contact_email", "");
        }
        $sv->save("site_contact_name", $oldname);
    }
}
