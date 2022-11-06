<?php
// t_batch.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Batch_Tester {
    function test_backuppattern() {
        $bp = new BackupPattern("%{dbname}/%U.sql.gz");
        xassert($bp->match("barforama/1020340000.sql.gz"));
        xassert_eqq($bp->dbname, "barforama");
        xassert_eqq($bp->timestamp, 1020340000);
        xassert(!$bp->match("fsart_10202029292.sql.gz"));
        xassert(!$bp->match("barforama/1020340000.sql.g"));

        xassert_eqq($bp->expand("mydb", "myconf", 1), "mydb/1.sql.gz");

        $bp = new BackupPattern("%{dbname}/%{confid}-%Y%m%d-%H%i%s.sql.gz");
        xassert_eqq($bp->expand("mydb", "myconf", 1667736000), "mydb/myconf-20221106-120000.sql.gz");

        $bp = new BackupPattern("%{dbname}/%{confid}-%Y%m%d-%H%i%s.sql.gz");
        xassert($bp->match("mydb/myconf-20221106-120000.sql.gz"));
        xassert_eqq($bp->dbname, "mydb");
        xassert_eqq($bp->confid, "myconf");
        xassert_eqq($bp->timestamp, 1667736000);
        xassert(!$bp->match("mydb/myconf-20221106-120000.sal.gz"));
    }
}
