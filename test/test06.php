<?php
// test06.php -- HotCRP review and some setting tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.
/** @phan-file-suppress PhanUndeclaredProperty */

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::reset_db();
TestRunner::go(new Reviews_Tester(Conf::$main));
TestRunner::go(new Comments_Tester(Conf::$main));
xassert_exit();
