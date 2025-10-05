<?php
// api_sharing.php -- HotCRP sharing API call
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Sharing_API extends MessageSet {
    static function run(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        if (!$user->can_administer($prow)
            && !$prow->has_author($user)) {
            return JsonResult::make_permission_error();
        }
        if ($qreq->method() !== "GET") {
            if (!isset($qreq->share)) {
                return JsonResult::make_missing_error("share");
            } else if ($qreq->share === "new" || $qreq->share === "refresh") {
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
                $av = $share === "refresh" ? AuthorView_Capability::AV_REFRESH : AuthorView_Capability::AV_CREATE;
                AuthorView_Capability::make($prow, $av, $invalid_at);
            }
        }
        $cap = AuthorView_Capability::make($prow, AuthorView_Capability::AV_EXISTING);
        $jr = new JsonResult([
            "ok" => true,
            "author_view_capability" => $cap
        ]);
        if ($cap !== null) {
            $jr["author_view_link"] = $prow->hoturl(["cap" => $cap], Conf::HOTURL_ABSOLUTE | Conf::HOTURL_RAW);
        }
        return $jr;
    }
}
