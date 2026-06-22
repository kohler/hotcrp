<?php
// t_batch.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Batch_Tester {
    /** @var Conf */
    private $conf;

    function __construct(Conf $conf) {
        $this->conf = $conf;
    }

    function test_backuppattern() {
        $bp = new BackupPattern("%{dbname}/%U.sql.gz");
        xassert($bp->match("barforama/1020340000.sql.gz"));
        xassert_eqq($bp->dbname(), "barforama");
        xassert_eqq($bp->timestamp(), 1020340000);
        xassert(!$bp->match("fsart_10202029292.sql.gz"));
        xassert(!$bp->match("barforama/1020340000.sql.g"));

        $bp->clear()
            ->set_dbname("mydb")
            ->set_confid("myconf")
            ->set_timestamp(1);
        xassert_eqq($bp->expansion(), "mydb/1.sql.gz");

        $bp = (new BackupPattern("%{dbname}/%{confid}-%Y%m%d-%H%i%s.sql.gz"))
            ->set_dbname("mydb")
            ->set_confid("myconf")
            ->set_timestamp(1667736000);
        xassert_eqq($bp->expansion(), "mydb/myconf-20221106-120000.sql.gz");

        $bp = new BackupPattern("%{dbname}/%{confid}-%Y%m%d-%H%i%s.sql.gz");
        xassert($bp->match("mydb/myconf-20221106-120000.sql.gz"));
        xassert_eqq($bp->dbname(), "mydb");
        xassert_eqq($bp->confid(), "myconf");
        xassert_eqq($bp->timestamp(), 1667736000);
        xassert(!$bp->match("mydb/myconf-20221106-120000.sal.gz"));

        $bp = new BackupPattern("%{dbname}/%{confid}-%{filename}");
        xassert($bp->match("mydb/myconf-20221106-120000.sql.gz"));
        xassert_eqq($bp->dbname(), "mydb");
        xassert_eqq($bp->confid(), "myconf");
        xassert_eqq($bp->filename(), "20221106-120000.sql.gz");
        xassert($bp->match("mydb/myconf-20221106-120000.sal.gz"));
        xassert_eqq($bp->dbname(), "mydb");
        xassert_eqq($bp->confid(), "myconf");
        xassert_eqq($bp->filename(), "20221106-120000.sal.gz");
        xassert(!$bp->match("mydb/myconf-20221106-120000.sal.gz/"));

        $bp->clear()
            ->set_dbname("foo")
            ->set_confid("bar")
            ->set_filename("amazing.txt");
        xassert_eqq($bp->full_expansion(), "foo/bar-amazing.txt");

        $bp = new BackupPattern("%{dbname}/%{confid}-%{filename}");
        $bp->clear()
            ->set_dbname("foo");
        xassert_eqq($bp->expansion(), "foo/");
        xassert_eqq($bp->full_expansion(), null);

        $bp->clear()
            ->set_dbname("foo")
            ->set_confid("bar")
            ->set_filename_from_path("/var/bool/xxxxx.txt");
        xassert_eqq($bp->full_expansion(), "foo/bar-xxxxx.txt");
    }

    function test_saveusers() {
        $this->conf->qe("delete from ContactInfo where email='addeduser@_.com'");
        xassert(!$this->conf->fresh_user_by_email("addeduser@_.com"));

        $su = new SaveUsers_Batch($this->conf->root_user(), [
            "expression" => ["{\"email\": \"addeduser@_.com\", \"roles\": \"chair\"}"],
            "quiet" => false
        ]);
        xassert_eqq($su->run(), 0);

        $u = $this->conf->fresh_user_by_email("addeduser@_.com");
        xassert_neqq($u, null);
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_CHAIR | Contact::ROLE_PC);
        xassert(!$u->is_disabled());

        // don't disable or change roles for locked users
        $this->conf->qe("update ContactInfo set cflags=cflags|? where email=?", Contact::CF_SECURITYLOCK, "addeduser@_.com");

        $su = new SaveUsers_Batch($this->conf->root_user(), [
            "expression" => ["{\"email\": \"addeduser@_.com\", \"roles\": \"pc\", \"disabled\": true}"],
            "quiet" => false
        ]);
        xassert_eqq($su->run(), 0);

        $u = $this->conf->fresh_user_by_email("addeduser@_.com");
        xassert_neqq($u, null);
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_CHAIR | Contact::ROLE_PC);
        xassert(!$u->is_disabled());

        $this->conf->qe("update ContactInfo set cflags=cflags&~? where email=?", Contact::CF_SECURITYLOCK, "addeduser@_.com");

        $su = new SaveUsers_Batch($this->conf->root_user(), [
            "expression" => ["{\"email\": \"addeduser@_.com\", \"roles\": \"pc\", \"disabled\": true}"],
            "quiet" => false
        ]);
        xassert_eqq($su->run(), 0);

        $u = $this->conf->fresh_user_by_email("addeduser@_.com");
        xassert_neqq($u, null);
        xassert_eqq($u->roles & Contact::ROLE_PCLIKE, Contact::ROLE_PC);
        xassert($u->is_disabled());

        $this->conf->qe("delete from ContactInfo where email='addeduser@_.com'");
        $this->conf->invalidate_user($u);
    }
}
