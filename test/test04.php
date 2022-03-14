<?php
// test04.php -- HotCRP user database tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::reset_db();

if (!Conf::$main->contactdb()) {
    error_log("! Error: The test contactdb has not been initialized.");
    error_log("! You may need to run `lib/createdb.sh -c test/cdb-options.php --no-dbuser --batch`.");
    exit(1);
}

TestRunner::go(new Cdb_Tester(Conf::$main));
xassert_exit();
