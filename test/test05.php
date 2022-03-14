<?php
// test05.php -- HotCRP paper submission tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::reset_db();
TestRunner::go(new PaperStatus_Tester(Conf::$main));
xassert_exit();
