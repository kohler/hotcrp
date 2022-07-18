<?php
// api_settings.php -- HotCRP settings API
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Settings_API {
    static function run(Contact $user, Qrequest $qreq) {
        $content = ["ok" => true];
        if ($qreq->valid_post()) {
            if (!isset($qreq->settings)) {
                return JsonResult::make_missing_error("settings");
            }
            $sv = (new SettingValues($user))->set_use_req(true);
            if (!$sv->viewable_by_user()) {
                return JsonResult::make_permission_error();
            }
            $sv->add_json_string($qreq->settings, $qreq->filename);
            if (!$sv->execute()) {
                return new JsonResult(["ok" => false, "message_list" => $sv->message_list()]);
            }
            $content["message_list"] = $sv->message_list();
            $content["updates"] = $sv->updated_fields();
        }
        $sv = new SettingValues($user);
        if (!$sv->viewable_by_user()) {
            return JsonResult::make_permission_error();
        }
        $content["settings"] = $sv->json_allv();
        return new JsonResult($content);
    }
}
