<?php
// contactprimary.php -- HotCRP primary contact links
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ContactPrimary {
    /** @var ?Contact */
    private $actor;
    /** @var Conf */
    private $conf;
    /** @var bool */
    private $cdb;
    /** @var Contact */
    private $sec;
    /** @var ?Contact */
    private $pri;
    /** @var list<int> */
    private $uids;

    function __construct(?Contact $actor = null) {
        $this->actor = $actor;
    }

    // Change the primary account for `$sec` to `$pri`.
    // If `$pri` is currently secondary, then its primary, and any other
    // secondaries of it, are retargeted to `$pri`.
    // If `$sec` is CDB, then `$pri` must be CDB.
    // If `$sec` is local, then `$pri` is made local.
    // If any changes are made, then authorship in `PaperConflict` is also
    // updated.
    function link(Contact $sec, ?Contact $pri) {
        $this->conf = $sec->conf;
        $this->cdb = $sec->is_cdb_user();
        $this->sec = $sec;
        if ($pri && strcasecmp($pri->email, $sec->email) !== 0) {
            $this->pri = $pri;
        } else {
            $this->pri = null;
        }
        $this->uids = [];
        if (!$this->cdb) {
            $this->uids[] = $this->sec->contactId;
        }

        // resolve pri
        if (!$this->cdb && $this->pri && !$this->pri->has_account_here()) {
            // Newly-created primary user is disabled if secondary was disabled
            $cflags = Contact::CF_UNCONFIRMED
                | ($sec->disabled_flags() & Contact::CF_UDISABLED);
            // Don't use ensure_account_here: that changes CDBness of
            // `$this->pri`, which caller might be depending on
            $this->pri = $this->conf->ensure_user_by_email($this->pri->email, $cflags);
        }
        assert(!$this->pri || $this->cdb === $this->pri->is_cdb_user());
        if ($this->pri && $this->cdb !== $this->pri->is_cdb_user()) {
            error_log(json_encode([$this->cdb, $this->pri->is_cdb_user(), $this->pri->contactDbId, $sec->email]) . ": " . debug_string_backtrace());
        }
        // do not assign to self
        $idk = $this->cdb ? "contactDbId" : "contactId";
        if ($this->sec->primaryContactId === ($this->pri ? $this->pri->$idk : 0)) {
            return;
        }
        // main changes
        $this->conf->pause_log();
        if ($this->pri && $this->pri->primaryContactId !== 0) {
            $this->_remove_old_primary($this->pri);
        }
        if ($this->sec->primaryContactId !== 0) {
            $this->_remove_old_primary($this->sec);
        } else if (($this->sec->cflags & Contact::CF_PRIMARY) !== 0) {
            assert(!!$this->pri);
            $this->_redirect_to_new_primary();
        }
        if ($this->pri) {
            $this->sec->set_prop("cflags", $this->sec->cflags & ~Contact::CF_PRIMARY);
            $this->sec->set_prop("primaryContactId", $this->pri->$idk);
            Dbl::qe($this->sec->dblink(), "insert into ContactPrimary set contactId=?, primaryContactId=?", $this->sec->$idk, $this->pri->$idk);
            $this->pri->set_prop("cflags", $this->pri->cflags | Contact::CF_PRIMARY);
            $this->pri->set_prop("primaryContactId", 0);
            $this->pri->save_prop();
            if (!$this->cdb) {
                $this->uids[] = $this->pri->contactId;
            }
        }
        if (!$this->cdb && $this->actor) {
            $this->conf->log_for($this->actor, $this->sec, "Primary account" . ($this->pri ? " set to {$this->pri->email}" : " removed"));
        }
        $this->sec->save_prop();
        $this->conf->resume_log();
        $this->conf->invalidate_caches("linked_users");
        // authorship changes
        if (!$this->cdb) {
            $this->_update_author_records();
        }
    }

    private function prefetch_user_by_id($id) {
        if ($this->cdb) {
            $this->conf->prefetch_cdb_user_by_id($id);
        } else {
            $this->conf->prefetch_user_by_id($id);
        }
    }

    private function user_by_id($id) {
        $idk = $this->cdb ? "contactDbId" : "contactId";
        if ($this->sec->$idk === $id) {
            return $this->sec;
        } else if ($this->pri && $this->pri->$idk === $id) {
            return $this->pri;
        } else if ($this->cdb) {
            return $this->conf->cdb_user_by_id($id);
        }
        return $this->conf->user_by_id($id);
    }

    private function _remove_old_primary(Contact $u) {
        $idk = $this->cdb ? "contactDbId" : "contactId";
        $oldid = $u->primaryContactId;
        $u->set_prop("primaryContactId", 0);
        Dbl::qe($u->dblink(), "delete from ContactPrimary where contactId=? and primaryContactId=?", $u->$idk, $oldid);
        if (!Dbl::fetch_ivalue($u->dblink(), "select exists(select * from ContactPrimary where primaryContactId=?) from dual", $oldid)
            && ($xpri = $this->user_by_id($oldid))) {
            $xpri->set_prop("cflags", $xpri->cflags & ~Contact::CF_PRIMARY);
            $xpri->save_prop();
        }
        if (!$this->cdb) {
            $this->uids[] = $oldid;
        }
    }

    private function _redirect_to_new_primary() {
        $idk = $this->cdb ? "contactDbId" : "contactId";
        $result = Dbl::qe($this->sec->dblink(), "select contactId from ContactPrimary where primaryContactId=?", $this->sec->$idk);
        $ids = [];
        while (($row = $result->fetch_row())) {
            $id = (int) $row[0];
            $ids[] = $id;
            $this->prefetch_user_by_id($id);
            if (!$this->cdb) {
                $this->uids[] = $id;
            }
        }
        $result->close();

        foreach ($ids as $id) {
            if (($u = $this->user_by_id($id))) {
                $u->set_prop("primaryContactId", $this->pri->$idk);
                $u->set_prop("cflags", $u->cflags & ~Contact::CF_PRIMARY);
                $u->save_prop();
                if (!$this->cdb && $this->actor) {
                    $this->conf->log_for($this->actor, $u, "Primary account set to {$this->pri->email}");
                }
            }
        }
        if (!empty($ids)) {
            Dbl::qe($this->sec->dblink(), "update ContactPrimary set primaryContactId=? where contactId?a",
                $this->pri->$idk, $ids);
        }
    }

    private function _update_author_records() {
        $rowset = $this->conf->paper_set(["minimal" => true, "authorInformation" => true, "allConflictType" => true, "where" => "paperId in (select paperId from PaperConflict where contactId>0 and contactId" . sql_in_int_list($this->uids) . " and conflictType>=" . CONFLICT_AUTHOR . ")"]);

        // prefetch authors and contact authors
        foreach ($rowset as $prow) {
            foreach ($prow->author_list() as $auth) {
                $this->conf->prefetch_user_by_email($auth->email);
            }
            foreach ($prow->conflict_type_list() as $pci) {
                if (($pci->conflictType & CONFLICT_CONTACTAUTHOR) !== 0)
                    $this->conf->prefetch_user_by_id($pci->contactId);
            }
        }

        // make changes
        $cfltf = Dbl::make_multi_qe_stager($this->conf->dblink);
        $changes = [];
        foreach ($rowset as $prow) {
            $cfltv = [];
            // - load current conflicts, resetting authorship temporarily
            // - transfer contact authorship to primary
            $newcontacts = [];
            foreach ($prow->conflict_type_list() as $pci) {
                $cav = $pci->conflictType & (CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
                if (($cav & CONFLICT_CONTACTAUTHOR) !== 0
                    && ($u = $this->conf->user_by_id($pci->contactId, USER_SLICE))
                    && $u->primaryContactId > 0) {
                    $newcontacts[] = $u->primaryContactId;
                    $cfltv[$pci->contactId] = [$cav, 0];
                } else {
                    $cfltv[$pci->contactId] = [$cav, $cav & CONFLICT_CONTACTAUTHOR];
                }
            }
            foreach ($newcontacts as $uid) {
                $cfltv[$uid] = $cfltv[$uid] ?? [0, 0];
                $cfltv[$uid][1] |= CONFLICT_CONTACTAUTHOR;
            }
            // - record listed authors
            foreach ($prow->author_list() as $auth) {
                if ($auth->email === ""
                    || !($u = $this->conf->user_by_email($auth->email, USER_SLICE))) {
                    continue;
                }
                $cfltv[$u->contactId] = $cfltv[$u->contactId] ?? [0, 0];
                $cfltv[$u->contactId][1] |= CONFLICT_AUTHOR;
                if ($u->primaryContactId > 0) {
                    $cfltv[$u->primaryContactId] = $cfltv[$u->primaryContactId] ?? [0, 0];
                    $cfltv[$u->primaryContactId][1] |= CONFLICT_AUTHOR;
                }
            }
            // - save changes
            $qv = [];
            $del = false;
            foreach ($cfltv as $uid => $cv) {
                if ($cv[0] !== $cv[1]) {
                    $qv[] = [$prow->paperId, $uid, $cv[1]];
                    $changes[$uid] = true;
                    $del = $del || $cv[1] === 0;
                }
            }
            if (empty($qv)) {
                continue;
            }
            $cfltf("insert into PaperConflict (paperId, contactId, conflictType) values ?v ?U
                    on duplicate key update conflictType=((PaperConflict.conflictType&~?)|?U(conflictType))",
                $qv, CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
            if ($del) {
                $cfltf("delete from PaperConflict where paperId=? and conflictType=0",
                    $prow->paperId);
            }
        }
        $cfltf(null);

        // maybe update cdb roles
        if ($this->conf->contactdb()) {
            foreach (array_keys($changes) as $uid) {
                $u = $this->conf->user_by_id($uid);
                $u->update_my_rights();
                $u->update_cdb_roles();
            }
        }
    }
}
