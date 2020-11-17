<?php
// cap_authorview.php -- HotCRP author-view capability management
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class AuthorView_Capability {
    /** @param PaperInfo $prow
     * @return string|false */
    static function make($prow) {
        // A capability has the following representation (. is concatenation):
        //    capFormat . paperId . capType . hashPrefix
        // capFormat -- Character denoting format (currently 0).
        // paperId -- Decimal representation of paper number.
        // capType -- Capability type (e.g. "a" for author view).
        // To create hashPrefix, calculate a SHA-1 hash of:
        //    capFormat . paperId . capType . paperCapVersion . capKey
        // where paperCapVersion is a decimal representation of the paper's
        // capability version (usually 0, but could allow conference admins
        // to disable old capabilities paper-by-paper), and capKey
        // is a random string specific to the conference, stored in Settings
        // under cap_key (created in load_settings).  Then hashPrefix
        // is the base-64 encoding of the first 8 bytes of this hash, except
        // that "+" is re-encoded as "-", "/" is re-encoded as "_", and
        // trailing "="s are removed.
        //
        // Any user who knows the conference's cap_key can construct any
        // capability for any paper.  Longer term, one might set each paper's
        // capVersion to a random value; but the only way to get cap_key is
        // database access, which would give you all the capVersions anyway.

        $key = $prow->conf->setting_data("cap_key");
        if (!$key) {
            $key = base64_encode(random_bytes(16));
            if (!$key || !$prow->conf->save_setting("cap_key", 1, $key)) {
                return false;
            }
        }
        $start = "0" . $prow->paperId . "a";
        $hash = sha1($start . $prow->capVersion . $key, true);
        $suffix = str_replace(array("+", "/", "="), array("-", "_", ""),
                              base64_encode(substr($hash, 0, 8)));
        return $start . $suffix;
    }

    static function apply_old_author_view(Contact $user, $uf) {
        if (($prow = $user->conf->paper_by_id((int) $uf->match_data[1]))
            && ($uf->name === self::make($prow))
            && !$user->conf->opt("disableCapabilities")) {
            $user->set_capability("@av{$prow->paperId}", true);
            if ($user->is_activated()) {
                CapabilityInfo::set_default_cap_param($uf->name, true);
            }
        }
    }
}
