<?php
// test06.php -- HotCRP review and some setting tests
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::run(
    "Reviews_Tester",
    "Comments_Tester",
    "UserAPI_Tester",
    "UploadAPI_Tester",
    "Mailer_Tester",
    "Events_Tester"
);
