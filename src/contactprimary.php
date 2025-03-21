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
        }

        // resolve pri
        if ($this->pri && !$this->cdb) {
            $this->pri->ensure_account_here();
        }
        assert(!$this->pri || $this->cdb === $this->pri->is_cdb_user());
        // do not assign to self
        $idk = $this->cdb ? "contactDbId" : "contactId";
        if ($this->sec->primaryContactId === ($this->pri ? $this->pri->$idk : 0)) {
            return;
        }
        // main changes
        $this->conf->delay_logs();
        if ($this->sec->primaryContactId !== 0) {
            $this->_remove_old_primary();
        } else {
            $this->_relink_to_new_primary();
        }
        if ($this->pri) {
            $this->sec->set_prop("primaryContactId", $this->pri->$idk);
            Dbl::qe($this->sec->dblink(), "insert into ContactPrimary set contactId=?, primaryContactId=?", $this->sec->$idk, $this->pri->$idk);
            $this->pri->set_prop("cflags", $this->pri->cflags | Contact::CF_PRIMARY);
            $this->pri->set_prop("primaryContactId", 0);
            $this->pri->save_prop();
        }
        if (!$this->cdb && $this->actor) {
            $this->conf->log_for($this->actor, $this->sec, "Primary account" . ($this->pri ? " set to {$this->pri->email}" : " removed"));
        }
        $this->sec->save_prop();
        $this->conf->release_logs();
        // authorship changes
        if (!$this->cdb) {
            self::_update_author_records($this->sec->conf,
                $this->sec->contactId, $this->pri ? $this->pri->contactId : 0);
        }
    }

    private function _remove_old_primary() {
        $idk = $this->cdb ? "contactDbId" : "contactId";
        $oldid = $this->sec->primaryContactId;
        $this->sec->set_prop("primaryContactId", 0);
        Dbl::qe($this->sec->dblink(), "delete from ContactPrimary where contactId=? and primaryContactId=?", $this->sec->$idk, $oldid);
        if (!Dbl::fetch_ivalue($this->sec->dblink(), "select exists(select * from ContactPrimary where primaryContactId=?) from dual", $oldid)
            && ($xpri = $this->sec->similar_user_by_id($oldid))) {
            $xpri->set_prop("cflags", $xpri->cflags & ~Contact::CF_PRIMARY);
            $xpri->save_prop();
        }
    }

    private function _relink_to_new_primary() {
        $idk = $this->cdb ? "contactDbId" : "contactId";
        $redir = [];
        if (($this->sec->cflags & Contact::CF_PRIMARY) !== 0) {
            $this->sec->set_prop("cflags", $this->sec->cflags & ~Contact::CF_PRIMARY);
            $redir[] = $this->sec->$idk;
        }
        $old_primary = $this->pri ? $this->pri->primaryContactId : 0;
        if ($old_primary > 0) {
            $redir[] = $old_primary;
        }
        assert(empty($redir) || $this->pri);
        if (empty($redir)) {
            return;
        }

        $result = Dbl::qe($this->sec->dblink(), "select contactId from ContactPrimary where primaryContactId?a", $redir);
        $ids = [];
        while (($row = $result->fetch_row())) {
            $id = (int) $row[0];
            if ($id !== $this->pri->$idk) {
                $this->sec->prefetch_similar_user_by_id($id);
                $ids[] = $id;
            }
        }
        if ($old_primary > 0 && $old_primary !== $this->sec->$idk) {
            $this->sec->prefetch_similar_user_by_id($old_primary);
            $ids[] = $old_primary;
        }
        $result->close();

        foreach ($ids as $id) {
            if (($u = $this->sec->similar_user_by_id($id))) {
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
        if ($old_primary > 0) {
            Dbl::qe($this->sec->dblink(), "delete from ContactPrimary where contactId=?",
                $this->pri->$idk);
            if ($old_primary !== $this->sec->$idk) {
                Dbl::qe($this->sec->dblink(), "insert into ContactPrimary set contactId=?, primaryContactId=?",
                        $old_primary, $this->pri->$idk);
                if (!$this->cdb && $this->actor) {
                    $this->conf->log_for($this->actor, $old_primary, "Primary account set to {$this->pri->email}");
                }
            }
        }
    }

    static private function _update_author_records(Conf $conf, ...$ids) {
        $rowset = $conf->paper_set(["minimal" => true, "authorInformation" => true, "allConflictType" => true, "where" => "paperId in (select paperId from PaperConflict where contactId>0 and contactId" . sql_in_int_list($ids) . " and conflictType>=" . CONFLICT_AUTHOR . ")"]);

        // prefetch authors and contact authors
        foreach ($rowset as $prow) {
            foreach ($prow->author_list() as $auth) {
                $conf->prefetch_user_by_email($auth->email);
            }
            foreach ($prow->conflict_type_list() as $pci) {
                if ($pci->conflictType & CONFLICT_CONTACTAUTHOR)
                    $conf->prefetch_user_by_id($pci->contactId);
            }
        }

        // make changes
        $cfltf = Dbl::make_multi_qe_stager($conf->dblink);
        $changes = [];
        foreach ($rowset as $prow) {
            $cfltv = [];
            // record current conflicts and new contacts
            foreach ($prow->conflict_type_list() as $pci) {
                $ct = $pci->conflictType & (CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
                if ($ct !== 0) {
                    $cfltv[$pci->contactId] = $cfltv[$pci->contactId] ?? [0, 0];
                    $cfltv[$pci->contactId][0] |= $ct;
                }
                if (($ct & CONFLICT_CONTACTAUTHOR) === 0
                    || !($u = $conf->user_by_id($pci->contactId, USER_SLICE))) {
                    continue;
                }
                $id = $u->primaryContactId ? : $u->contactId;
                $cfltv[$id] = $cfltv[$id] ?? [0, 0];
                $cfltv[$id][1] |= CONFLICT_CONTACTAUTHOR;
            }
            // record new authors
            foreach ($prow->author_list() as $auth) {
                if (!($u = $conf->user_by_email($auth->email, USER_SLICE))) {
                    continue;
                }
                $cfltv[$u->contactId] = $cfltv[$u->contactId] ?? [0, 0];
                $cfltv[$u->contactId][1] |= CONFLICT_AUTHOR;
                if ($u->primaryContactId !== 0) {
                    $cfltv[$u->primaryContactId] = $cfltv[$u->primaryContactId] ?? [0, 0];
                    $cfltv[$u->primaryContactId][1] |= CONFLICT_AUTHOR;
                }
            }
            // save changes
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
        if ($conf->contactdb()) {
            foreach (array_keys($changes) as $uid) {
                $u = $conf->user_by_id($uid);
                $u->update_my_rights();
                $u->update_cdb_roles();
            }
        }
    }
}
