<?php
// test05.php -- HotCRP paper submission tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::run(
    "no_cdb",
    "PaperStatus_Tester",
    "Login_Tester"
);
