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
        $users = $this->load_users("select *, 0 _slice from ContactInfo where contactId?a and (cflags&?)=0 and contactId!=?",
            [$ids, Contact::CF_UDISABLED | Contact::CF_DELETED, $this->viewer->contactId]);
        if (empty($users)) {
            return;
        }
        $this->conf->qe("update ContactInfo set cflags=cflags|? where contactId?a and (cflags&?)=0",
            Contact::CF_UDISABLED, array_keys($users), Contact::CF_UDISABLED);
        $this->conf->pause_log();
        foreach ($users as $u) {
            $u->set_prop("cflags", $u->cflags | Contact::CF_UDISABLED);
            $u->commit_prop();
            $this->conf->invalidate_user($u, true);
            $this->conf->log_for($this->viewer, $u, "Account disabled");
            $this->unames["disabled"][] = $u->name(NAME_E);
            $u->update_cdb_roles();
        }
        $this->conf->resume_log();
    }

    /** @param list<int> $ids */
    function enable($ids) {
        $this->unames["enabled"] = $this->unames["activated"] = [];
        if (!$this->viewer->privChair) {
            $this->error_at(null, "<0>Permission error");
            return;
        }
        $users = $this->load_users("select *, 0 _slice from ContactInfo where contactId?a and (cflags&?)=?",
            [$ids, Contact::CF_UDISABLED | Contact::CF_DELETED, Contact::CF_UDISABLED]);
        if (empty($users)) {
            return;
        }
        $this->conf->qe("update ContactInfo set cflags=(cflags&~?) where contactId?a and (cflags&?)=?",
            Contact::CF_UDISABLED, array_keys($users),
            Contact::CF_UDISABLED | Contact::CF_DELETED, Contact::CF_UDISABLED);
        $this->conf->pause_log();
        foreach ($users as $u) {
            $u->set_prop("cflags", $u->cflags & ~Contact::CF_UDISABLED);
            $u->commit_prop();
            $this->conf->invalidate_user($u, true);
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
        $this->conf->resume_log();
    }

    /** @param list<int> $ids */
    function send_account_info($ids) {
        $this->unames["sent"] = $this->unames["skipped"] = [];
        if (!$this->viewer->is_track_manager()) {
            $this->error_at(null, "<0>Permission error");
            return;
        }
        $users = $this->load_users("select *, 0 _slice from ContactInfo where contactId?a", [$ids]);
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

    /** @param list<Contact> $users
     * @param int $radd
     * @param int $rremove
     * @param string $key */
    private function change_roles($users, $radd, $rremove, $key) {
        $this->conf->pause_log();
        foreach ($users as $u) {
            $old_roles = $u->roles;
            $u->set_prop("roles", ($u->roles & ~$rremove) | $radd);
            $u->commit_prop();
            $this->conf->invalidate_user($u, true);
            $d = UserStatus::unparse_roles_diff($old_roles, $u->roles);
            $this->conf->log_for($this->viewer, $u, "Account edited: roles [{$d}]");
            $this->unames[$key][] = $u->name(NAME_E);
            $u->update_cdb_roles();
        }
        $this->conf->resume_log();
    }

    /** @param list<int> $ids */
    function add_pc($ids) {
        $this->unames["add_pc"] = [];
        if (!$this->viewer->privChair) {
            $this->error_at(null, "<0>Permission error");
            return;
        }
        $users = $this->load_users("select *, 0 _slice from ContactInfo where contactId?a and (roles&?)=0",
            [$ids, Contact::ROLE_PCLIKE]);
        if (empty($users)) {
            return;
        }
        $this->conf->qe("update ContactInfo set roles=roles|? where contactId?a and (roles&?)=0",
            Contact::ROLE_PC, array_keys($users), Contact::ROLE_PCLIKE);
        $this->change_roles($users, Contact::ROLE_PC, 0, "add_pc");
    }

    /** @param list<int> $ids */
    function remove_pc($ids) {
        $this->unames["remove_pc"] = [];
        if (!$this->viewer->privChair) {
            $this->error_at(null, "<0>Permission error");
            return;
        }
        $users = $this->load_users("select *, 0 _slice from ContactInfo where contactId?a and (roles&?)!=0 and contactId!=?",
            [$ids, Contact::ROLE_PC, $this->viewer->contactId]);
        if (empty($users)) {
            return;
        }
        $this->conf->qe("update ContactInfo set roles=roles&~? where contactId?a and (roles&?)!=0",
            Contact::ROLE_PC | Contact::ROLE_CHAIR, array_keys($users), Contact::ROLE_PC);
        $this->change_roles($users, 0, Contact::ROLE_PC | Contact::ROLE_CHAIR, "remove_pc");
    }

    private function check_delete(Contact $user) {
        if (!$this->viewer->privChair) {
            $this->error_at(null, "<0>Only administrators can delete accounts");
            return false;
        }
        if ($user === $this->viewer) {
            $this->error_at(null, "<0>You can’t delete your own account");
            return false;
        }
        if ($user->is_anonymous_user()) {
            $this->append_item(MessageItem::error("<0>Account {} cannot be deleted", $user->email));
            return false;
        }
        if (!$user->has_account_here()) {
            $this->append_item(MessageItem::marked_note("<0>Account {} is not active on this site", $user->email));
            return false;
        }
        if ($user->security_locked_here()) {
            $this->append_item(MessageItem::error("<0>Account {} is locked and can’t be deleted", $user->email));
            return false;
        }
        if (($user->cflags & Contact::CF_PRIMARY) !== 0) {
            $links = Dbl::fetch_first_columns($this->conf->dblink,
                "select email from ContactInfo join ContactPrimary using (contactId)
                where ContactPrimary.primaryContactId=?", $user->contactId);
            if (!empty($links)) {
                $this->append_item(MessageItem::error("<0>Account {} can’t be deleted because it has linked accounts", $user->email));
                $this->append_item(MessageItem::inform("<0>You will be able to delete the account after deleting {:list}.", $links));
                return false;
            }
        }
        if (($tracks = UserStatus::user_paper_info($this->conf, $user->contactId))
            && !empty($tracks->soleAuthor)) {
            $this->append_item(MessageItem::error("<5>Account {} can’t be deleted because it is sole contact for " . UserStatus::render_paper_link($this->conf, $tracks->soleAuthor), new FmtArg(0, $user->email, 0)));
            $this->append_item(MessageItem::inform("<0>You will be able to delete the account after deleting those papers or adding additional paper contacts."));
            return false;
        }
        return true;
    }

    function delete(Contact $user) {
        $this->unames["deleted"] = [];
        if (!$this->check_delete($user)) {
            return;
        }

        // insert deletion marker
        $this->conf->qe("insert into DeletedContactInfo set contactId=?, firstName=?, lastName=?, unaccentedName=?, email=?, affiliation=?", $user->contactId, $user->firstName, $user->lastName, $user->unaccentedName, $user->email, $user->affiliation);

        // change cflags to mark user as deleted
        // also change roles (do not log roles change, as we will shortly log deletion)
        // and delete password
        $user->set_prop("cflags", $user->cflags | Contact::CF_DELETED);
        $user->set_prop("roles", 0);
        $user->set_prop("contactTags", null);
        $user->set_prop("password", "");
        $user->set_prop("passwordTime", 0);
        $user->set_prop("passwordUseTime", 0);
        $user->set_prop("lastLogin", 0);
        $user->set_prop("defaultWatch", 2);
        $user->clear_data_prop();
        $user->save_prop();

        // unlink from primary
        if ($user->primaryContactId > 0) {
            (new ContactPrimary($this->viewer))->link($user, null);
        }

        // load paper set for reviews and comments
        $prows = $this->conf->paper_set([
            "where" => "paperId in (select paperId from PaperReview where contactId={$user->contactId} union select paperId from PaperComment where contactId={$user->contactId})"
        ]);

        // delete reviews (needs to be logged, might update other information)
        $result = $this->conf->qe("select * from PaperReview where contactId=?",
            $user->contactId);
        while (($rrow = ReviewInfo::fetch($result, $prows, $this->conf))) {
            $rrow->delete($this->viewer, ["no_autosearch" => true]);
        }
        Dbl::free($result);

        // delete comments (needs to be logged; do not delete responses)
        $result = $this->conf->qe("select * from PaperComment where contactId=? and (commentType&?)=0",
            $user->contactId, CommentInfo::CT_RESPONSE);
        while (($crow = CommentInfo::fetch($result, $prows, $this->conf))) {
            $crow->delete($this->viewer, ["no_autosearch" => true]);
        }
        Dbl::free($result);

        // delete conflicts except for author conflicts
        $this->conf->qe("delete from PaperConflict where contactId=? and conflictType!=?",
            $user->contactId, CONFLICT_AUTHOR);
        $this->conf->qe("update PaperConflict set conflictType=? where contactId=?",
            CONFLICT_AUTHOR, $user->contactId);

        // delete from other database tables
        foreach (["PaperWatch", "PaperReviewPreference", "PaperReviewRefused", "ReviewRating", "TopicInterest"] as $table) {
            $this->conf->qe_raw("delete from {$table} where contactId={$user->contactId}");
        }

        // delete twiddle tags
        $assigner = new AssignmentSet($this->viewer);
        $assigner->set_override_conflicts(true);
        $assigner->parse("paper,tag\nall,{$user->contactId}~all#clear\n");
        $assigner->execute();

        // automatic tags may have changed
        $this->conf->update_automatic_tags();

        // clear caches
        if ($user->isPC || $user->privChair) {
            $this->conf->invalidate_caches("pc");
        }

        // done
        $user->update_cdb_roles();
        $this->viewer->log_activity_for($user, "Account deleted {$user->email}");
        $this->unames["deleted"][] = $user->name(NAME_E);
    }
}
