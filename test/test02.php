<?php
// test02.php -- HotCRP S3 and database unit tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

declare(strict_types=1);
require_once(__DIR__ . '/setup.php');
TestRunner::go(new Unit_Tester(Conf::$main));
TestRunner::go(new XtCheck_Tester(Conf::$main));
TestRunner::go(new Navigation_Tester);
TestRunner::go(new AuthorMatch_Tester);
TestRunner::go(new IntlMsgSet_Tester);
TestRunner::go(new Abbreviation_Tester(Conf::$main));
TestRunner::go(new DocumentBasics_Tester(Conf::$main));
TestRunner::go(new FixCollaborators_Tester);
TestRunner::go(new Mention_Tester(Conf::$main));
TestRunner::go(new Search_Tester(Conf::$main));
TestRunner::go(new Settings_Tester(Conf::$main));
TestRunner::go(new UpdateSchema_Tester(Conf::$main));
xassert_exit();
