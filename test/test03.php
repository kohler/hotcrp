<?php
// test03.php -- HotCRP min-cost max-flow tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::go(new MinCostMaxFlow_Tester);
xassert_exit();
