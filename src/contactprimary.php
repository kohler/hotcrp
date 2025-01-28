<?php
// contactprimary.php -- HotCRP primary contact links
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class ContactPrimary {
    /** @var Conf */
    private $conf;
    /** @var bool */
    private $cdb;
    /** @var Contact */
    private $sec;
    /** @var ?Contact */
    private $pri;

    function __construct(Contact $sec, ?Contact $pri) {
        $this->conf = $sec->conf;
        $this->cdb = $sec->is_cdb_user();
        $this->sec = $sec;
        $this->pri = $pri;
    }

    // Change the primary account for `$sec` to the primary for `$pri`.
    // If `$pri` is secondary, then it is resolved first to its own primary,
    // unless that primary is `$sec`.
    // If `$sec` is CDB, then `$pri` must be CDB.
    // If `$sec` is local, then `$pri` is made local.
    // If any changes are made, then authorship in `PaperConflict` is also
    // updated.
    static function set_primary_user(Contact $sec, ?Contact $pri) {
        $cp = new ContactPrimary($sec, $pri);
        $cp->run();
    }

    private function run() {
        // resolve pri
        if ($this->pri) {
            $this->_resolve_requested_primary();
        }
        assert(!$this->pri || $this->cdb === $this->pri->is_cdb_user());
        // do not assign to self
        $idk = $this->cdb ? "contactDbId" : "contactId";
        if ($this->sec->primaryContactId === ($this->pri ? $this->pri->$idk : 0)) {
            return;
        }
        // main changes
        if ($this->sec->primaryContactId !== 0) {
            $this->_remove_old_primary();
        } else if (($this->sec->cflags & Contact::CF_PRIMARY) !== 0) {
            $this->_relink_to_new_primary();
        }
        if ($this->pri) {
            $this->sec->set_prop("primaryContactId", $this->pri->$idk);
            Dbl::qe($this->sec->dblink(), "insert into ContactPrimary set contactId=?, primaryContactId=?", $this->sec->$idk, $this->pri->$idk);
            $this->pri->set_prop("cflags", $this->pri->cflags | Contact::CF_PRIMARY);
            $this->pri->set_prop("primaryContactId", 0);
            $this->pri->save_prop();
        }
        $this->sec->save_prop();
        // authorship changes
        if (!$this->cdb) {
            self::_update_author_records($this->sec->conf,
                $this->sec->contactId, $this->pri ? $this->pri->contactId : 0);
        }
    }

    private function _resolve_requested_primary() {
        // resolve `$pri` to its own primary, unless its primary is `$sec`
        if ($this->pri->primaryContactId !== 0) {
            $xpri = $this->pri->similar_user_by_id($this->pri->primaryContactId);
            if ($xpri && strcasecmp($xpri->email, $this->sec->email) !== 0) {
                $this->pri = $xpri;
            }
        }
        // resolve `$pri` to a local user if `$sec` is local
        if (!$this->cdb) {
            $this->pri->ensure_account_here();
        }
        // `null` if same as `$sec`
        if (strcasecmp($this->pri->email, $this->sec->email) === 0) {
            $this->pri = null;
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
        $this->sec->set_prop("cflags", $this->sec->cflags & ~Contact::CF_PRIMARY);
        $result = Dbl::qe($this->sec->dblink(), "select contactId from ContactPrimary where primaryContactId=?", $this->sec->$idk);
        $ids = [];
        while (($row = $result->fetch_row())) {
            $id = (int) $row[0];
            if ($id !== $this->pri->$idk) {
                $this->sec->invalidate_similar_user_by_id($id);
                $ids[] = $id;
            }
        }
        $result->close();
        Dbl::qe($this->sec->dblink(), "update ContactInfo set primaryContactId=? where contactId?a and primaryContactId=?",
            $this->pri->$idk, $ids, $this->sec->$idk);
        Dbl::qe($this->sec->dblink(), "update ContactPrimary set primaryContactId=? where contactId?a and primaryContactId=?",
            $this->pri->$idk, $ids, $this->sec->$idk);
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
                    on duplicate key update conflictType=((conflictType&~?)|?U(conflictType))",
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
