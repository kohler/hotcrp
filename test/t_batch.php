<?php
// t_batch.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Batch_Tester {
    function test_backupdb() {
        xassert_eqq(BackupDB_Batch::find_eq("'", 0), 1);
        xassert_eqq(BackupDB_Batch::find_eq("''", 0), 1);
        xassert_eqq(BackupDB_Batch::find_eq("'' ", 0), 1);
        xassert_eqq(BackupDB_Batch::find_eq("'\\' ", 0), 4);
        xassert_eqq(BackupDB_Batch::find_eq("'\\\\' ", 0), 3);
        xassert_eqq(BackupDB_Batch::find_eq("'\\'\\' \\\\' ' ", 0), 8);
    }
}
