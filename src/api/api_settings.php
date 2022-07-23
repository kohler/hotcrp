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
            $sv->parse();
            $dry_run = $qreq->dryrun || $qreq->dry_run;
            if ($dry_run) {
                $content["dry_run"] = true;
            } else {
                $sv->execute();
            }
            $content["ok"] = !$sv->has_error();
            $content["message_list"] = $sv->message_list();
            $content["updates"] = $sv->updated_fields();
            if ($dry_run || $sv->has_error()) {
                return new JsonResult($content);
            }
        }
        $sv = new SettingValues($user);
        if (!$sv->viewable_by_user()) {
            return JsonResult::make_permission_error();
        }
        $content["settings"] = $sv->json_allv();
        return new JsonResult($content);
    }

    static function descriptions(Contact $user, Qrequest $qreq) {
        $sv = new SettingValues($user);
        if (!$sv->viewable_by_user()) {
            return JsonResult::make_permission_error();
        }
        $m = [];
        foreach ($sv->conf->si_set()->top_list() as $si) {
            if ($si->json_export()
                && ($si->has_title() || $si->description)) {
                $o = ["name" => $si->name];
                if (($t = $si->title($sv))) {
                    $o["title"] = "<0>{$t}";
                }
                $o["type"] = $si->type;
                if ($si->subtype) {
                    $o["subtype"] = $si->subtype;
                }
                if (($je = $si->json_examples($sv)) !== null) {
                    $o["values"] = $je;
                }
                if (($dv = $si->initial_value($sv)) !== null) {
                    $o["default_value"] = $si->base_unparse_jsonv($dv, $sv);
                }
                if ($si->description) {
                    $o["description"] = Ftext::ensure($si->description, 0);
                }
                $m[] = $o;
            }
        }
        return new JsonResult(["ok" => true, "setting_descriptions" => $m]);
    }
}
