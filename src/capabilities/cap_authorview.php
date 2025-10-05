<?php
// cap_authorview.php -- HotCRP author-view capability management
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class AuthorView_Capability {
    /** @param PaperInfo $prow
     * @return ?string */
    static function make($prow) {
        if ($prow->_author_view_token !== null) {
            return $prow->_author_view_token->salt;
        }
        if ($prow->conf->opt("disableCapabilities")) {
            return null;
        }
        // load already-assigned tokens
        if (count($prow->_row_set) > 5) {
            $lo = "hcav";
            $hi = "hcaw";
        } else {
            $lo = "hcav{$prow->paperId}@";
            $hi = "hcav{$prow->paperId}~";
        }
        $result = $prow->conf->qe("select * from Capability where salt>=? and salt<?", $lo, $hi);
        while (($tok = TokenInfo::fetch($result, $prow->conf, false))) {
            if (($xrow = $prow->_row_set->get($tok->paperId))
                && $tok->capabilityType === TokenInfo::AUTHORVIEW) {
                $xrow->_author_view_token = $tok;
            }
        }
        Dbl::free($result);
        // create new token
        if (!$prow->_author_view_token || !$prow->_author_view_token->salt) {
            $tok = (new TokenInfo($prow->conf, TokenInfo::AUTHORVIEW))
                ->set_paper($prow)
                ->set_token_pattern("hcav{$prow->paperId}[16]")
                ->insert();
            if ($tok->stored()) {
                $prow->_author_view_token = $tok;
            }
        }
        if ($prow->_author_view_token) {
            return $prow->_author_view_token->salt;
        }
        return null;
    }

    static function apply_author_view(Contact $user, $uf) {
        if (($tok = TokenInfo::find($uf->name, $user->conf))
            && $tok->is_active()
            && $tok->capabilityType === TokenInfo::AUTHORVIEW
            && !$user->conf->opt("disableCapabilities")) {
            $user->set_capability("@av{$tok->paperId}", true);
            $user->set_default_cap_param($uf->name, true);
            $tok->update_use(3600)->update();
        }
    }
}
