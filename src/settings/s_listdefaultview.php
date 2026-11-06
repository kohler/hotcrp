<?php
// settings/s_listdefaultview.php -- HotCRP settings > default list view
// Copyright (c) 2026 Eddie Kohler; see LICENSE.

class ListDefaultView_SettingParser extends SettingParser {
    function apply_req(Si $si, SettingValues $sv) {
        $sv->save($si, simplify_whitespace($sv->reqstr($si->name) ?? ""));
        $sv->request_validate($si);
        return true;
    }

    function validate(Si $si, SettingValues $sv) {
        // runs after parsing, so the view can name new fields
        $pl = new PaperList("pl", new PaperSearch($sv->conf->root_user(), "NONE"));
        $pl->parse_view($sv->newv($si->name) ?? "", ViewCommand::ORIGIN_MAX);
        $pl->prepare_table_view();
        foreach ($pl->message_list() as $mi) {
            $sv->append_item_at($si->name, $mi);
        }
    }
}
