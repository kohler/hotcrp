<?php
// settings/s_siteurl.php -- HotCRP settings > basics > site URL
// Copyright (c) 2026 Eddie Kohler; see LICENSE.

class SiteURL_SettingParser extends SettingParser {
    /** Return true if the `site_url` setting (`observedPaperSite`) can set
     * this site’s URL for email. Otherwise the site must configure
     * `$Opt["paperSite"]` or `$Opt["defaultPaperSite"]`. Chairs control this
     * setting, so it is unavailable when a password link could reach beyond
     * this conference (a contactdb password works at every conference) or
     * when chairs may not manage passwords (`chairHidePasswords`).
     * @return bool */
    static function site_url_configurable(Conf $conf) {
        return ($conf->opt("paperSite") ?? "") === ""
            && ($conf->opt("defaultPaperSite") ?? "") === ""
            && !$conf->contactdb()
            && !$conf->opt("chairHidePasswords");
    }

    /** @param string $u
     * @return string */
    static private function clean($u) {
        $u = trim($u);
        while (str_ends_with($u, "/")) {
            $u = substr($u, 0, -1);
        }
        return $u;
    }

    /** Return true if `$u` is valid as a site URL: an http or https URL with
     * no query, fragment, spaces, or control characters.
     * @param string $u
     * @return bool */
    static function is_valid_site_url($u) {
        return preg_match('/\Ahttps?+:\/\/[^\/?#\x00-\x20\x7F]+(?:\/[^?#\x00-\x20\x7F]*)?\z/i', $u) === 1;
    }

    /** Record this request’s URL as the `site_url` setting, unless the site
     * has a URL for email. Call only for requests from administrators. */
    static function record_site_url(Contact $user) {
        $conf = $user->conf;
        if ($conf->opt("observedPaperSite") === null
            && $conf === Conf::$main
            && self::site_url_configurable($conf)
            && ($nav = Navigation::get())->server !== null
            && self::is_valid_site_url(($u = self::clean($nav->server . $nav->base_path)))) {
            $conf->save_refresh_setting("opt.observedPaperSite", 1, $u);
            $conf->log_for($user, null, "Site URL set to {$u}");
        }
    }

    static function print_site_url(SettingValues $sv) {
        $conf = $sv->conf;
        if (!self::site_url_configurable($conf)) {
            echo '<div class="f-i"><label for="site_url">Site URL</label><p id="site_url">';
            if (($u = $conf->safe_baseurl() ?? "") === "") {
                echo "None (<em>configure in</em> <code>conf/options.php</code>)";
            } else {
                echo htmlspecialchars($u);
            }
            echo '</div>';
            return;
        }
        $sv->print_entry_group("site_url", null, [
            "hint" => "This site’s public URL (used for links in email). Example: “https://hotcrp.example.org”"
        ]);
    }

    function set_oldv(Si $si, SettingValues $sv) {
        // a site URL that is not configurable is not exported
        if (!self::site_url_configurable($sv->conf)) {
            $sv->set_oldv($si, null);
        }
    }

    function apply_req(Si $si, SettingValues $sv) {
        $v = self::clean($sv->reqstr($si->name) ?? "");
        if (!self::site_url_configurable($sv->conf)) {
            // ignore
        } else if ($v !== ""
                   && !self::is_valid_site_url($v)) {
            $sv->error_at($si, "<0>Site URL must start with “http://” or “https://” and have no query or fragment");
        } else {
            $sv->update($si, $v);
        }
        return true;
    }

    static function crosscheck(SettingValues $sv) {
        $conf = $sv->conf;
        if (!$sv->has_interest("site_url")
            || !self::site_url_configurable($sv->conf)) {
            return;
        }
        $site = self::clean($sv->oldv("site_url") ?? "");
        $nav = Navigation::get();
        if ($site === "") {
            $sv->warning_at("site_url", "<0>Users can’t create accounts, reset passwords, or follow links in email until the site URL is set.");
        } else if ($nav->server !== null
                   && strcasecmp($site, self::clean($nav->server . $nav->base_path)) !== 0) {
            $sv->warning_at("site_url", "<0>The site URL, {0}, differs from the URL you are using to access the site, {1}. Email uses the site URL.",
                new FmtArg(0, $site, 0), new FmtArg(1, self::clean($nav->server . $nav->base_path), 0));
        }
    }
}
