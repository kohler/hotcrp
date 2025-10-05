<?php
// cap_authorview.php -- HotCRP author-view capability management
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class AuthorView_Capability {
    const AV_DEFAULT = 0;
    const AV_EXISTING = 1;
    const AV_CREATE = 2;
    const AV_REFRESH = 3;

    const REFRESHABLE_TIME = 86400 * 14;

    /** @param PaperInfo $prow
     * @param int $reqtype
     * @param ?int $invalid_at
     * @return ?string */
    static function make($prow, $reqtype = 0, $invalid_at = null) {
        $sharing = $prow->conf->opt("authorSharing") ?? 0;
        if ($sharing < 0) {
            return null;
        }
        if ($prow->_author_view_token) {
            self::update_expiration($prow->_author_view_token, $invalid_at, true);
            return $prow->_author_view_token->salt;
        } else if ($prow->_author_view_token === false
                   && ($reqtype === self::AV_EXISTING
                       || ($sharing === 0 && $reqtype === self::AV_DEFAULT))) {
            return null;
        }
        // load already-assigned tokens
        if (count($prow->_row_set) > 5
            && $reqtype !== self::AV_REFRESH) {
            $lo = "hcav";
            $hi = "hcaw";
            foreach ($prow->_row_set as $xrow) {
                $xrow->_author_view_token = false;
            }
        } else {
            $lo = "hcav{$prow->paperId}@";
            $hi = "hcav{$prow->paperId}~";
            $prow->_author_view_token = false;
        }
        $invalid_allowance = $reqtype === self::AV_REFRESH ? self::REFRESHABLE_TIME : 0;
        $result = $prow->conf->qe("select * from Capability
            where salt>=? and salt<? and capabilityType=?
            and (timeInvalid=0 or timeInvalid>?) and (timeExpires=0 or timeExpires>?)",
            $lo, $hi, TokenInfo::AUTHORVIEW,
            Conf::$now - $invalid_allowance, Conf::$now);
        while (($tok = TokenInfo::fetch($result, $prow->conf, false))) {
            if (($xrow = $prow->_row_set->get($tok->paperId))) {
                $xrow->_author_view_token = $tok;
            }
        }
        Dbl::free($result);
        // create new token
        if ($prow->_author_view_token === false
            && $reqtype !== self::AV_EXISTING
            && ($sharing > 0 || $reqtype >= self::AV_CREATE)) {
            $tok = (new TokenInfo($prow->conf, TokenInfo::AUTHORVIEW))
                ->set_paper($prow)
                ->set_token_pattern("hcav{$prow->paperId}[16]");
            self::update_expiration($tok, $invalid_at ?? 0, false);
            $tok->insert();
            if ($tok->stored()) {
                $prow->_author_view_token = $tok;
            }
        }
        if ($prow->_author_view_token) {
            self::update_expiration($prow->_author_view_token, $invalid_at, true);
            return $prow->_author_view_token->salt;
        }
        return null;
    }

    /** @param TokenInfo $token
     * @param ?int $invalid_at
     * @param bool $save */
    static private function update_expiration($token, $invalid_at, $save) {
        if ($invalid_at !== null) {
            $token->set_invalid_at($invalid_at)
                ->set_expires_at($invalid_at ? $invalid_at + self::REFRESHABLE_TIME : 0);
            if ($save) {
                $token->update();
            }
        }
    }

    /** @param PaperInfo $prow */
    static function remove($prow) {
        $lo = "hcav{$prow->paperId}@";
        $hi = "hcav{$prow->paperId}~";
        $result = $prow->conf->qe("update Capability
            set timeInvalid=if(timeInvalid=0,?,least(timeInvalid,?)),
            timeExpires=if(timeExpires=0,?,least(timeExpires,?))
            where salt>=? and salt<? and capabilityType=?",
            Conf::$now - self::REFRESHABLE_TIME, Conf::$now - self::REFRESHABLE_TIME,
            Conf::$now + self::REFRESHABLE_TIME, Conf::$now + self::REFRESHABLE_TIME,
            $lo, $hi, TokenInfo::AUTHORVIEW);
        Dbl::free($result);
        $prow->_author_view_token = false;
    }

    static function apply_author_view(Contact $user, $uf) {
        if (($tok = TokenInfo::find($uf->name, $user->conf))
            && $tok->is_active()
            && $tok->capabilityType === TokenInfo::AUTHORVIEW
            && ($user->conf->opt("authorSharing") ?? 0) >= 0) {
            $user->set_capability("@av{$tok->paperId}", true);
            $user->set_default_cap_param($uf->name, true);
            $tok->update_use(3600)->update();
        }
    }
}
