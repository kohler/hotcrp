<?php
// test09.php -- HotCRP API tests
// Copyright (c) 2024 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::run(
    "reset_db",
    "PaperAPI_Tester"
);
