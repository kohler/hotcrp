<?php
// test08.php -- HotCRP tests run without CDB
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::run(
    "no_cdb",
    "reset_db",
    "Permission_Tester",
    "Unit_Tester",
    "XtCheck_Tester",
    "Abbreviation_Tester",
    "DocumentBasics_Tester",
    "Mention_Tester",
    "Invariants_Tester",
    "Search_Tester",
    "Settings_Tester",
    "Invariants_Tester",
    "UpdateSchema_Tester",
    "Invariants_Tester",
    "Batch_Tester",
    "PaperStatus_Tester",
    "Login_Tester",
    "clear_db",
    "Reviews_Tester",
    "Comments_Tester",
    "UserAPI_Tester"
);
