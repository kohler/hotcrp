<?php
// useractions.php -- HotCRP helpers for user actions
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class UserActions extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $viewer;
    /** @var array<string,list<string>> */
    private $unames;

    function __construct(Contact $viewer) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
    }

    /** @param string $kind
     * @return list<string> */
    function name_list($kind) {
        assert(isset($this->unames[$kind]));
        return $this->unames[$kind] ?? [];
    }

    /** @param string $q
     * @param list<mixed> $qa
     * @return array<int,Contact> */
    private function load_users($q, $qa) {
        $users = [];
        $result = $this->conf->qe_apply($q, $qa);
        while (($u = Contact::fetch($result, $this->conf))) {
            $users[$u->contactId] = $u;
        }
        Dbl::free($result);
        uasort($users, $this->conf->user_comparator());
        return $users;
    }

    /** @param list<int> $ids */
    function disable($ids) {
        $this->unames["disabled"] = [];
        if (!$this->viewer->privChair) {
            $this->error_at(null, "<0>Permission error");
            return;
        }
        $users = $this->load_users("select * from ContactInfo where contactId?a and (cflags&?)=0 and contactId!=?",
            [$ids, Contact::CF_UDISABLED | Contact::CF_DELETED, $this->viewer->contactId]);
        if (empty($users)) {
            return;
        }
        $this->conf->qe("update ContactInfo set cflags=cflags|? where contactId?a and (cflags&?)=0",
            Contact::CF_UDISABLED, array_keys($users), Contact::CF_UDISABLED);
        $this->conf->delay_logs();
        foreach ($users as $u) {
            $this->conf->log_for($this->viewer, $u, "Account disabled");
            $this->unames["disabled"][] = $u->name(NAME_E);
            $u->update_cdb_roles();
        }
        $this->conf->release_logs();
    }

    /** @param list<int> $ids */
    function enable($ids) {
        $this->unames["enabled"] = $this->unames["activated"] = [];
        if (!$this->viewer->privChair) {
            $this->error_at(null, "<0>Permission error");
            return;
        }
        $users = $this->load_users("select * from ContactInfo where contactId?a and (cflags&?)=?",
            [$ids, Contact::CF_UDISABLED | Contact::CF_DELETED, Contact::CF_UDISABLED]);
        if (empty($users)) {
            return;
        }
        $this->conf->qe("update ContactInfo set cflags=(cflags&~?) where contactId?a and (cflags&?)=?",
            Contact::CF_UDISABLED, array_keys($users),
            Contact::CF_UDISABLED | Contact::CF_DELETED, Contact::CF_UDISABLED);
        $this->conf->delay_logs();
        foreach ($users as $u) {
            $this->conf->log_for($this->viewer, $u, "Account enabled");
            $this->unames["enabled"][] = $u->name(NAME_E);
        }
        foreach ($users as $u) {
            if ($u->isPC && !$u->activity_at) {
                $prep = $u->prepare_mail("@newaccount.pc");
                if ($prep->send()) {
                    $this->unames["activated"][] = $u->name(NAME_E);
                } else {
                    $this->append_list($prep->message_list());
                }
            }
            $u->update_cdb_roles();
        }
        $this->conf->release_logs();
    }

    /** @param list<int> $ids */
    function send_account_info($ids) {
        $this->unames["sent"] = $this->unames["skipped"] = [];
        if (!$this->viewer->is_track_manager()) {
            $this->error_at(null, "<0>Permission error");
            return;
        }
        $users = $this->load_users("select * from ContactInfo where contactId?a", [$ids]);
        foreach ($users as $u) {
            if ($u->is_disabled()) {
                $this->unames["skipped"][] = $u->name(NAME_E);
            } else {
                $prep = $u->prepare_mail("@accountinfo");
                if ($prep->send()) {
                    $this->unames["sent"][] = $u->name(NAME_E);
                } else {
                    $this->append_list($prep->message_list());
                }
            }
        }
    }
}
