<?php
// t_manageemail.php -- HotCRP tests for the manage-email page / API
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class ManageEmail_Tester {
    /** @var Conf
     * @readonly */
    public $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    /** @param string|list<string> $emails
     * @param array<string,mixed> $req
     * @return array{Contact,Qrequest} */
    private function make_qreq_for($emails, $req = []) {
        $emails = is_array($emails) ? $emails : [$emails];
        $u = $this->conf->fresh_user_by_email($emails[0]);
        $qreq = (new Qrequest("POST", $req))->approve_token();
        $qreq->set_qsession(new MemoryQsession);
        foreach ($emails as $email) {
            UserSecurityEvent::session_user_add($qreq->qsession(), $email);
            UserSecurityEvent::make($email)
                ->set_reason(UserSecurityEvent::REASON_REAUTH)
                ->store($qreq);
        }
        $u = $u->activate($qreq, true);
        $qreq->set_user($u);
        return [$u, $qreq];
    }


    // ManageEmail "Transfer reviews": conflict/role/review transfer basics,
    // plus the recheck that refuses a transfer prepared against a now-stale
    // snapshot. The live multi-worker race is exercised by the reproducer, not
    // here; these tests drive the same serialization safety sequentially.

    /** @param string $email
     * @param int $roles
     * @return Contact */
    private function me_make_user($email, $roles = 0) {
        $this->conf->qe("insert into ContactInfo (firstName, lastName, email, affiliation, password, cflags, roles) values ('Test', 'Xfer', ?, 'Nowhere', '', 0, ?)", $email, $roles);
        $this->conf->invalidate_caches("users", "pc");
        return $this->conf->checked_user_by_email($email);
    }

    /** @param string $like */
    private function me_delete_users($like) {
        $ids = Dbl::fetch_first_columns($this->conf->dblink, "select contactId from ContactInfo where email like ?", $like);
        foreach ($ids as $id) {
            foreach (["PaperReview", "PaperConflict", "PaperComment"] as $t) {
                $this->conf->qe("delete from {$t} where contactId=?", $id);
            }
            $this->conf->qe("delete from ContactInfo where contactId=?", $id);
        }
        $this->conf->invalidate_caches("users", "pc");
    }

    /** @param string $email
     * @return int */
    private function me_pc_roles($email) {
        return ((int) $this->conf->fetch_ivalue("select roles from ContactInfo where email=?", $email)) & Contact::ROLE_PCLIKE;
    }

    /** @param Contact $src
     * @param string $dstemail
     * @param list<string> $session
     * @return JsonResult */
    private function me_transfer($src, $dstemail, $session) {
        list($u, $qreq) = $this->make_qreq_for($session);
        return (new ManageEmail_API($u, $qreq))
            ->set_dstuser($this->conf->fresh_user_by_email($dstemail))
            ->transferreview();
    }

    function test_transferreview_moves_reviews_and_conflicts() {
        $this->me_delete_users("mxfer%");
        $a = $this->me_make_user("mxfer0@_.com");
        $b = $this->me_make_user("mxfer1@_.com");
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        // source reviews #1 and is conflicted with #3; destination has neither
        xassert_assign($chair, "action,paper,email\nreview,1,mxfer0@_.com\n");
        xassert_assign($chair, "paper,action,user,conflict\n3,conflict,mxfer0@_.com,collaborator\n");
        xassert_eqq((int) $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=1 and contactId=?", $a->contactId), 1);

        $jr = $this->me_transfer($a, "mxfer1@_.com", ["mxfer0@_.com", "mxfer1@_.com"]);
        xassert($jr->ok(), json_encode($jr->content ?? []));

        // the review is now the destination's, not the source's
        xassert_eqq((int) $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=1 and contactId=?", $a->contactId), 0);
        xassert_eqq((int) $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=1 and contactId=?", $b->contactId), 1);
        // the conflict is now the destination's, with the exact (collaborator) type
        xassert_eqq((int) $this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=3 and contactId=?", $b->contactId), 2);

        $cl = $jr->get("change_list") ?? [];
        xassert(in_array("conflicts", $cl, true));
        xassert(!empty(array_filter($cl, function ($x) { return strpos($x, "review") !== false; })));

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_merges_conflict_and_pc_role() {
        $this->me_delete_users("mxfer%");
        $c = $this->me_make_user("mxfer2@_.com", Contact::ROLE_PC);
        $d = $this->me_make_user("mxfer3@_.com");
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        // source (a PC member) must be a reviewer to transfer
        xassert_assign($chair, "action,paper,email\nreview,4,mxfer2@_.com\n");
        // source has a PC conflict (advisor) with #5; destination is already a
        // contact author of #5, so its conflict must be merged, not clobbered
        xassert_assign($chair, "paper,action,user,conflict\n5,conflict,mxfer2@_.com,advisor\n");
        $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values (5, ?, ?)", $d->contactId, CONFLICT_CONTACTAUTHOR);
        $this->conf->invalidate_caches("users");

        $jr = $this->me_transfer($c, "mxfer3@_.com", ["mxfer2@_.com", "mxfer3@_.com"]);
        xassert($jr->ok(), json_encode($jr->content ?? []));

        // destination becomes a PC member
        xassert(((int) $this->conf->fetch_ivalue("select roles from ContactInfo where contactId=?", $d->contactId) & Contact::ROLE_PCLIKE) !== 0);
        // destination's #5 conflict keeps its contact-author bit and gains the
        // exact PC part (advisor), rather than being clobbered
        $ct = (int) $this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=5 and contactId=?", $d->contactId);
        xassert_eqq($ct, CONFLICT_CONTACTAUTHOR | 4);

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_conflict_author_becomes_contact_author() {
        // Conflict::merge turns a source *author* conflict into a *contact
        // author* bit on the destination, keeping the destination's own PC part
        $this->me_delete_users("mxfer%");
        $a = $this->me_make_user("mxfer0@_.com");
        $b = $this->me_make_user("mxfer1@_.com");
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        xassert_assign($chair, "action,paper,email\nreview,1,mxfer0@_.com\n"); // source is a reviewer
        // source is an author of #6; destination has a collaborator conflict
        // there. Both are written directly: an assignment on #6 would normalize
        // author conflicts and drop the source's (it isn't a real author).
        $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values (6, ?, ?)", $a->contactId, CONFLICT_AUTHOR);
        $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values (6, ?, ?)", $b->contactId, 2);
        $this->conf->invalidate_caches("users");

        $jr = $this->me_transfer($a, "mxfer1@_.com", ["mxfer0@_.com", "mxfer1@_.com"]);
        xassert($jr->ok(), json_encode($jr->content ?? []));

        // destination gains the contact-author bit, keeps its collaborator part
        $ct = (int) $this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=6 and contactId=?", $b->contactId);
        xassert_eqq($ct, CONFLICT_CONTACTAUTHOR | 2);

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_source_keeps_nonpc_conflict() {
        // A non-PC (contact-author) conflict on the source is left on the
        // source; the destination receives the merged conflict. (What happens
        // to the source's *PC* part is HC-009 territory and is not asserted.)
        $this->me_delete_users("mxfer%");
        $a = $this->me_make_user("mxfer0@_.com");
        $b = $this->me_make_user("mxfer1@_.com");
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        xassert_assign($chair, "action,paper,email\nreview,1,mxfer0@_.com\n");
        // source has both a contact-author bit and a collaborator PC part on #7
        $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values (7, ?, ?)", $a->contactId, CONFLICT_CONTACTAUTHOR | 2);
        $this->conf->invalidate_caches("users");

        $jr = $this->me_transfer($a, "mxfer1@_.com", ["mxfer0@_.com", "mxfer1@_.com"]);
        xassert($jr->ok(), json_encode($jr->content ?? []));

        // the source keeps its contact-author (non-PC) conflict
        $srcct = (int) $this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=7 and contactId=?", $a->contactId);
        xassert_eqq($srcct & CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
        // the destination receives both the contact-author bit and the PC part
        $dstct = (int) $this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=7 and contactId=?", $b->contactId);
        xassert_eqq($dstct & CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
        xassert_eqq($dstct & Conflict::FM_PC, 2);

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_dst_author_keeps_contact_bit() {
        // BUG DEMONSTRATION: when the destination is already a declared author
        // of a paper and the source is a contact author of it, the transfer
        // drops the source's contact-author bit instead of merging it in. The
        // `if((conflictType & CONFLICT_AUTHOR)=0, ...)` guard in
        // transfer_conflicts_first suppresses the contact-author bit whenever
        // the destination has the author bit, but Conflict::merge keeps both.
        $this->me_delete_users("mxfer%");
        $a = $this->me_make_user("mxfer0@_.com");
        $b = $this->me_make_user("mxfer1@_.com");
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        xassert_assign($chair, "action,paper,email\nreview,1,mxfer0@_.com\n"); // source is a reviewer
        // source is a contact author of #9; destination is a declared author there
        $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values (9, ?, ?)", $a->contactId, CONFLICT_CONTACTAUTHOR);
        $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values (9, ?, ?)", $b->contactId, CONFLICT_AUTHOR);
        $this->conf->invalidate_caches("users");

        $jr = $this->me_transfer($a, "mxfer1@_.com", ["mxfer0@_.com", "mxfer1@_.com"]);
        xassert($jr->ok(), json_encode($jr->content ?? []));

        // Conflict::merge(CONFLICT_AUTHOR, CONFLICT_CONTACTAUTHOR) === author|contactauthor
        $ct = (int) $this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=9 and contactId=?", $b->contactId);
        xassert_eqq($ct, CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_both_pc_conflicts_source_wins() {
        // When both accounts hold an (unpinned) PC conflict on the same paper,
        // the source's wins -- the source is the PC account at transfer time.
        // (This is what the inline comment in transfer_conflicts_first says;
        // the older header comment's "dst wins" is stale.)
        $this->me_delete_users("mxfer%");
        $a = $this->me_make_user("mxfer0@_.com");
        $b = $this->me_make_user("mxfer1@_.com");
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        xassert_assign($chair, "action,paper,email\nreview,1,mxfer0@_.com\n");
        // source: advisor (4) on #10; destination: collaborator (2) on #10; both unpinned
        $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values (10, ?, ?)", $a->contactId, 4);
        $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values (10, ?, ?)", $b->contactId, 2);
        $this->conf->invalidate_caches("users");

        $jr = $this->me_transfer($a, "mxfer1@_.com", ["mxfer0@_.com", "mxfer1@_.com"]);
        xassert($jr->ok(), json_encode($jr->content ?? []));

        // destination ends with the source's PC type (advisor), not its own
        xassert_eqq((int) $this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=10 and contactId=?", $b->contactId), 4);

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_source_unpinned_pc_conflicts_removed() {
        // transfer_conflicts_last cleans the source: its unpinned PC conflicts
        // are removed (their PC part; the row goes if nothing else remains),
        // but pinned PC conflicts and contact-author bits stay.
        $this->me_delete_users("mxfer%");
        $a = $this->me_make_user("mxfer0@_.com", Contact::ROLE_PC);
        $b = $this->me_make_user("mxfer1@_.com");
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        xassert_assign($chair, "action,paper,email\nreview,3,mxfer0@_.com\n"); // source is a reviewer
        // source: unpinned collaborator on #1, pinned collaborator on #2
        $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values (1, ?, ?)", $a->contactId, 2);
        $this->conf->qe("insert into PaperConflict (paperId, contactId, conflictType) values (2, ?, ?)", $a->contactId, 2 | Conflict::F_PIN);
        $this->conf->invalidate_caches("users");

        $jr = $this->me_transfer($a, "mxfer1@_.com", ["mxfer0@_.com", "mxfer1@_.com"]);
        xassert($jr->ok(), json_encode($jr->content ?? []));

        // destination received both conflicts
        xassert_eqq((int) $this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=1 and contactId=?", $b->contactId), 2);
        xassert_eqq((int) $this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=2 and contactId=?", $b->contactId), 2 | Conflict::F_PIN);
        // the source's unpinned conflict is gone; the pinned one is kept
        xassert_eqq($this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=1 and contactId=?", $a->contactId), null);
        xassert_eqq((int) $this->conf->fetch_ivalue("select conflictType from PaperConflict where paperId=2 and contactId=?", $a->contactId), 2 | Conflict::F_PIN);

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_error_both_pc() {
        $this->me_delete_users("mxfer%");
        $c = $this->me_make_user("mxfer2@_.com", Contact::ROLE_PC);
        $this->me_make_user("mxfer3@_.com", Contact::ROLE_PC);
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        xassert_assign($chair, "action,paper,email\nreview,4,mxfer2@_.com\n"); // source is a reviewer

        $jr = $this->me_transfer($c, "mxfer3@_.com", ["mxfer2@_.com", "mxfer3@_.com"]);
        xassert(!$jr->ok());
        xassert_eqq($jr->get("error_code"), "pc_conflict");

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_error_not_reviewer() {
        $this->me_delete_users("mxfer%");
        $a = $this->me_make_user("mxfer0@_.com"); // no reviews, no outstanding request
        $this->me_make_user("mxfer1@_.com");

        $jr = $this->me_transfer($a, "mxfer1@_.com", ["mxfer0@_.com", "mxfer1@_.com"]);
        xassert(!$jr->ok());
        xassert_eqq($jr->get("error_code"), "not_reviewer");

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_error_both_reviewed() {
        $this->me_delete_users("mxfer%");
        $a = $this->me_make_user("mxfer0@_.com");
        $this->me_make_user("mxfer1@_.com");
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        // both accounts review #1
        xassert_assign($chair, "action,paper,email\nreview,1,mxfer0@_.com\nreview,1,mxfer1@_.com\n");

        $jr = $this->me_transfer($a, "mxfer1@_.com", ["mxfer0@_.com", "mxfer1@_.com"]);
        xassert(!$jr->ok());
        xassert_eqq($jr->get("error_code"), "review_conflict");

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_stale_pc_roles() {
        // Two "Transfer reviews" requests prepared from the same PC-account
        // snapshot must not both apply: lock_ec rechecks live roles and refuses
        // a transfer whose snapshot no longer matches the database.
        $this->me_delete_users("mxfer%");
        $this->me_make_user("mxfer0@_.com", Contact::ROLE_PC);
        $this->me_make_user("mxfer1@_.com");
        $this->me_make_user("mxfer2@_.com");

        // both requests load the source PC account before either runs
        list($src1, $qreq1) = $this->make_qreq_for(["mxfer0@_.com", "mxfer1@_.com"]);
        list($src2, $qreq2) = $this->make_qreq_for(["mxfer0@_.com", "mxfer2@_.com"]);
        xassert_eqq($src1->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC);
        xassert_eqq($src2->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC);
        $dst1 = $this->conf->fresh_user_by_email("mxfer1@_.com");
        $dst2 = $this->conf->fresh_user_by_email("mxfer2@_.com");

        // first transfer moves PC status to mxfer1
        $jr = (new ManageEmail_API($src1, $qreq1))->set_dstuser($dst1)->transferreview();
        xassert($jr->ok(), json_encode($jr->content ?? []));
        xassert_in_eqq("PC status", $jr->get("change_list") ?? []);
        xassert_eqq($this->me_pc_roles("mxfer0@_.com"), 0);
        xassert_eqq($this->me_pc_roles("mxfer1@_.com"), Contact::ROLE_PC);

        // second transfer runs from a stale snapshot of mxfer0 and is refused,
        // so PC status is not duplicated onto mxfer2
        $jr = (new ManageEmail_API($src2, $qreq2))->set_dstuser($dst2)->transferreview();
        xassert(!$jr->ok());
        xassert_eqq($jr->get("error_code"), "conflict");
        xassert_not_in_eqq("PC status", $jr->get("change_list") ?? []);
        xassert_eqq($this->me_pc_roles("mxfer2@_.com"), 0);

        // a transfer working from a stale snapshot of the destination is
        // refused too: mxfer2 gains PC status after the snapshot is taken
        list($src, $qreq) = $this->make_qreq_for(["mxfer1@_.com", "mxfer2@_.com"]);
        $dst = $this->conf->fresh_user_by_email("mxfer2@_.com");
        $this->conf->qe("update ContactInfo set roles=? where email='mxfer2@_.com'", Contact::ROLE_PC);
        $this->conf->invalidate_caches("users", "pc");
        $jr = (new ManageEmail_API($src, $qreq))->set_dstuser($dst)->transferreview();
        xassert(!$jr->ok());
        xassert_eqq($jr->get("error_code"), "conflict");
        xassert_eqq($this->me_pc_roles("mxfer1@_.com"), Contact::ROLE_PC);
        xassert_eqq($this->me_pc_roles("mxfer2@_.com"), Contact::ROLE_PC);

        // an up-to-date transfer still works
        $this->conf->qe("update ContactInfo set roles=0 where email='mxfer2@_.com'");
        $this->conf->invalidate_caches("users", "pc");
        list($src, $qreq) = $this->make_qreq_for(["mxfer1@_.com", "mxfer2@_.com"]);
        $dst = $this->conf->fresh_user_by_email("mxfer2@_.com");
        $jr = (new ManageEmail_API($src, $qreq))->set_dstuser($dst)->transferreview();
        xassert($jr->ok(), json_encode($jr->content ?? []));
        xassert_in_eqq("PC status", $jr->get("change_list") ?? []);
        xassert_eqq($this->me_pc_roles("mxfer1@_.com"), 0);
        xassert_eqq($this->me_pc_roles("mxfer2@_.com"), Contact::ROLE_PC);

        $this->me_delete_users("mxfer%");
    }

    function test_transferreview_stale_review_conflict() {
        // A transfer whose both-reviewed check passed at dry-run time is
        // refused if, by the time it runs, the destination has acquired a
        // review on one of the source's papers: the check reruns as a live
        // read under the lock.
        $this->me_delete_users("mxfer%");
        $this->me_make_user("mxfer3@_.com");
        $this->me_make_user("mxfer4@_.com");
        $this->me_make_user("mxfer5@_.com");
        $chair = $this->conf->checked_user_by_email("chair@_.com");
        xassert_assign($chair, "paper,action,user\n1,review,mxfer3@_.com\n1,review,mxfer4@_.com\n");

        // two requests, prepared before either completes, transfer different
        // reviewers of paper 1 into mxfer5; both pass the dry-run
        list($src1, $qreq1) = $this->make_qreq_for(["mxfer3@_.com", "mxfer5@_.com"]);
        list($src2, $qreq2) = $this->make_qreq_for(["mxfer4@_.com", "mxfer5@_.com"]);
        $dst1 = $this->conf->fresh_user_by_email("mxfer5@_.com");
        $dst2 = $this->conf->fresh_user_by_email("mxfer5@_.com");
        foreach ([[$src1, $qreq1, $dst1], [$src2, $qreq2, $dst2]] as $x) {
            $jr = (new ManageEmail_API($x[0], $x[1]))->set_dstuser($x[2])->set_dry_run(true)->transferreview();
            xassert($jr->ok(), json_encode($jr->content ?? []));
            xassert_in_eqq("1 review", $jr->get("change_list") ?? []);
        }

        // first transfer moves mxfer3's review to mxfer5
        $jr = (new ManageEmail_API($src1, $qreq1))->set_dstuser($dst1)->transferreview();
        xassert($jr->ok(), json_encode($jr->content ?? []));
        xassert_in_eqq("1 review", $jr->get("change_list") ?? []);

        // second transfer must now be refused: mxfer5 already reviews paper 1
        $jr = (new ManageEmail_API($src2, $qreq2))->set_dstuser($dst2)->transferreview();
        xassert(!$jr->ok());
        xassert_eqq($jr->get("error_code"), "review_conflict");
        xassert_eqq((int) $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=1 and contactId=?", $dst1->contactId), 1);
        xassert_eqq((int) $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=1 and contactId=?", $src2->contactId), 1);
        xassert_eqq((int) $this->conf->fetch_ivalue("select count(*) from PaperReview where paperId=1 and contactId=?", $src1->contactId), 0);

        $this->me_delete_users("mxfer%");
    }
}
