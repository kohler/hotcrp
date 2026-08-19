<?php
// botcontact.php -- HotCRP class for managing bot contacts
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class BotContact {
    /** All bot accounts, in creation order, including disabled ones
     * (but not deleted ones).
     * @param 0|1 $sliced
     * @return list<Contact> */
    static function users(Conf $conf, $sliced = 0) {
        $botids = $conf->setting_data("bots") ?? "";
        if ($botids === ""
            || !preg_match('/\A\d++(?:,\d++)*+\z/', $botids)) {
            return [];
        }
        $ids = explode(",", $botids);
        foreach ($ids as $id) {
            $conf->prefetch_user_by_id((int) $id);
        }
        $us = [];
        foreach ($ids as $id) {
            if (($u = $conf->user_by_id((int) $id, $sliced)))
                $us[] = $u;
        }
        return $us;
    }

    static function register_bot_change(Contact $user) {
        $user->conf->register_shutdown_function("BotContact::enumerate_bots");
    }

    static function enumerate_bots(Conf $conf) {
        $conf->qe("insert into Settings (name,value,data) select 'bots', bots.count, bots.ids from (select count(*) count, group_concat(contactId order by contactId) ids from ContactInfo where (cflags&?)=?) as bots on duplicate key update value=bots.count, `data`=bots.ids",
            Contact::CF_BOT | Contact::CF_DELETED, Contact::CF_BOT);
    }
}
