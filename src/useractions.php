<?php
// useractions.php -- HotCRP helpers for user actions
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class UserActions {
    /** @param Conf $conf
     * @param string $q
     * @param list<mixed> $qa
     * @return array<int,Contact> */
    static function load_users($conf, $q, $qa) {
        $users = [];
        $result = $conf->qe_apply($q, $qa);
        while (($u = Contact::fetch($result, $conf))) {
            $users[$u->contactId] = $u;
        }
        Dbl::free($result);
        uasort($users, $conf->user_comparator());
        return $users;
    }

    /** @param list<int> $ids
     * @return object */
    static function disable(Contact $user, $ids) {
        $conf = $user->conf;
        $users = self::load_users($conf, "select * from ContactInfo where contactId?a and (cflags&?)=0 and contactId!=?",
            [$ids, Contact::CF_UDISABLED, $user->contactId]);
        $j = (object) ["ok" => true, "message_list" => []];
        if (empty($users)) {
            $j->message_list[] = new MessageItem(null, "<0>No changes (those accounts were already disabled)", MessageSet::WARNING_NOTE);
        } else {
            $conf->qe("update ContactInfo set disabled=?, cflags=cflags|? where contactId?a and (cflags&?)=0",
                Contact::CF_UDISABLED, Contact::CF_UDISABLED,
                array_keys($users), Contact::CF_UDISABLED);
            $conf->delay_logs();
            foreach ($users as $u) {
                $conf->log_for($user, $u, "Account disabled");
                $j->disabled_users[] = $u->name(NAME_E);
            }
            $conf->release_logs();
        }
        return $j;
    }

    /** @param list<int> $ids
     * @return object */
    static function enable(Contact $user, $ids) {
        $conf = $user->conf;
        $users = self::load_users($conf, "select * from ContactInfo where contactId?a and (cflags&?)!=0",
            [$ids, Contact::CF_UDISABLED]);
        $j = (object) ["ok" => true, "message_list" => []];
        if (empty($users)) {
            $j->message_list[] = new MessageItem(null, "<0>No changes (those accounts were already enabled)", MessageSet::WARNING_NOTE);
        } else {
            $conf->qe("update ContactInfo set disabled=0, cflags=(cflags&~?) where contactId?a and (cflags&?)!=0",
                Contact::CF_UDISABLED, array_keys($users), Contact::CF_UDISABLED);
            $conf->delay_logs();
            foreach ($users as $u) {
                $conf->log_for($user, $u, "Account enabled");
                $j->enabled_users[] = $u->name(NAME_E);
            }
            foreach ($users as $u) {
                if ($u->isPC && !$u->activity_at) {
                    $prep = $u->prepare_mail("@newaccount.pc");
                    if ($prep->send()) {
                        $j->activated_users[] = $u->name(NAME_E);
                    } else {
                        array_push($j->message_list, ...$prep->message_list());
                    }
                }
            }
            $conf->release_logs();
        }
        return $j;
    }

    /** @param list<int> $ids
     * @return object */
    static function send_account_info(Contact $user, $ids) {
        $conf = $user->conf;
        $users = self::load_users($conf, "select * from ContactInfo where contactId?a", [$ids]);
        $j = (object) ["ok" => true, "message_list" => []];
        foreach ($users as $u) {
            if ($u->is_disabled()) {
                $j->skipped_users[] = $u->name(NAME_E);
            } else {
                $prep = $u->prepare_mail("@accountinfo");
                if ($prep->send()) {
                    $j->sent_users[] = $u->name(NAME_E);
                } else {
                    array_push($j->message_list, ...$prep->message_list());
                }
            }
        }
        return $j;
    }
}
