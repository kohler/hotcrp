<?php
// api_sharing.php -- HotCRP sharing API call
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Sharing_API extends MessageSet {
    static function run(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$prow->has_author($user)
            && ($qreq->is_get() ? !$user->is_admin($prow) : !$user->can_manage($prow))) {
            return JsonResult::make_permission_error();
        }
        if (!$qreq->is_get()) {
            if ($qreq->method() === "DELETE") {
                $share = false;
            } else if (!isset($qreq->share)) {
                return JsonResult::make_missing_error("share");
            } else if ($qreq->share === "new" || $qreq->share === "reset") {
                $share = $qreq->share;
            } else if (($share = friendly_boolean($qreq->share)) === null) {
                return JsonResult::make_parameter_error("share");
            }
            if (!isset($qreq->expires_in)) {
                $invalid_at = 0;
            } else if (($ei = SettingParser::parse_duration($qreq->expires_in)) !== null) {
                $invalid_at = $ei < 0 ? 0 : Conf::$now + (int) round($ei);
            } else {
                return JsonResult::make_parameter_error("expires_in");
            }
            if ($share === false || $share === "new") {
                AuthorView_Capability::remove($prow);
            }
            if ($share) {
                $av = $share === "reset" ? AuthorView_Capability::AV_RESET : AuthorView_Capability::AV_CREATE;
                AuthorView_Capability::make($prow, $av, $invalid_at);
            }
        }
        $tok = AuthorView_Capability::find($prow);
        $jr = new JsonResult(["ok" => true]);
        if ($tok) {
            $jr["token"] = $tok->salt;
            $jr["token_type"] = "author_view";
            if ($tok->timeInvalid > 0) {
                $jr["expires_at"] = $tok->timeInvalid;
            }
            $jr["url"] = $prow->hoturl(["cap" => $tok->salt], Conf::HOTURL_ABSOLUTE | Conf::HOTURL_RAW);
        } else {
            $jr["token"] = null;
        }
        return $jr;
    }
}
