<?php
// useractions.php -- HotCRP helpers for user actions
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

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
            [$ids, Contact::CFLAG_UDISABLED, $user->contactId]);
        $j = (object) ["ok" => true, "message_list" => []];
        if (empty($users)) {
            $j->message_list[] = new MessageItem(null, "<0>No changes (those accounts were already disabled)", MessageSet::WARNING_NOTE);
        } else {
            $conf->qe("update ContactInfo set disabled=?, cflags=cflags|? where contactId?a and (cflags&?)=0",
                Contact::CFLAG_UDISABLED, Contact::CFLAG_UDISABLED,
                array_keys($users), Contact::CFLAG_UDISABLED);
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
            [$ids, Contact::CFLAG_UDISABLED]);
        $j = (object) ["ok" => true, "message_list" => []];
        if (empty($users)) {
            $j->message_list[] = new MessageItem(null, "<0>No changes (those accounts were already enabled)", MessageSet::WARNING_NOTE);
        } else {
            $conf->qe("update ContactInfo set disabled=0, cflags=(cflags&~?) where contactId?a and (cflags&?)!=0",
                Contact::CFLAG_UDISABLED, array_keys($users),
                Contact::CFLAG_UDISABLED);
            $conf->delay_logs();
            foreach ($users as $u) {
                $conf->log_for($user, $u, "Account enabled");
                $j->enabled_users[] = $u->name(NAME_E);
            }
            foreach ($users as $u) {
                if ($u->isPC && !$u->activity_at) {
                    if ($u->send_mail("@newaccount.pc", ["quiet" => true])) {
                        $j->activated_users[] = $u->name(NAME_E);
                    } else {
                        $j->message_list[] = new MessageItem(null, "<0>Mail cannot be sent to {$u->email} now", MessageSet::WARNING);
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
            } else if ($u->send_mail("@accountinfo", ["quiet" => true])) {
                $j->sent_users[] = $u->name(NAME_E);
            } else {
                $j->message_list[] = new MessageItem(null, "<0>Mail cannot be sent to {$u->email} now", MessageSet::WARNING);
            }
        }
        return $j;
    }
}
