<?php
// test02.php -- HotCRP S3 and database unit tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::run(
    "Unit_Tester",
    "XtCheck_Tester",
    "Navigation_Tester",
    "AuthorMatch_Tester",
    "Ht_Tester",
    "Fmt_Tester",
    "Abbreviation_Tester",
    "DocumentBasics_Tester",
    "FixCollaborators_Tester",
    "Mention_Tester",
    "Search_Tester",
    "Settings_Tester",
    "UpdateSchema_Tester",
    "Batch_Tester",
    "Mimetype_Tester"
);
