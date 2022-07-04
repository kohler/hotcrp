<?php
// test01.php -- HotCRP tests: permissions, assignments, search
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::run(
    "reset_db",
    "Permission_Tester"
);
