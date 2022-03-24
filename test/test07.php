<?php
// test07.php -- HotCRP diff_match_patch test runner
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::go(new DiffMatchPatch_Tester);
xassert_exit();
