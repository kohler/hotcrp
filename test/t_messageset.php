<?php
// t_messageset.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

#[RequireDb(false)]
class MessageSet_Tester {
    function test_under_no_separator() {
        $ms = new MessageSet;
        $ms->error_at("aubergine", "<0>Error");
        $ms->warning_at("au", "<0>Warning");

        xassert_eqq($ms->problem_status_under("au"), MessageSet::ERROR);
        xassert($ms->has_error_under("au"));
        xassert($ms->has_problem_under("au"));
        xassert($ms->has_error_under("auberg"));
        xassert(!$ms->has_error_under("b"));
        xassert(!$ms->has_problem_under("b"));
    }

    function test_under_separator() {
        $ms = new MessageSet;
        $ms->error_at("au:bergine", "<0>Error");
        $ms->warning_at("au", "<0>Warning");

        // exact match on the prefix itself counts
        xassert_eqq($ms->problem_status_under("au", ":"), MessageSet::ERROR);
        xassert($ms->has_error_under("au", ":"));
        xassert($ms->has_problem_under("au", ":"));

        // prefix not followed by the separator does not count
        xassert_eqq($ms->problem_status_under("a", ":"), 0);
        xassert(!$ms->has_error_under("a", ":"));
        xassert(!$ms->has_problem_under("a", ":"));
        xassert_eqq($ms->problem_status_under("au:", ":"), 0);
        xassert(!$ms->has_error_under("au:", ":"));

        // but it does without a separator
        xassert_eqq($ms->problem_status_under("a"), MessageSet::ERROR);
        xassert_eqq($ms->problem_status_under("au:"), MessageSet::ERROR);
    }

    function test_under_separator_status() {
        $ms = new MessageSet;
        $ms->warning_at("x:1", "<0>Warning");
        $ms->error_at("xy:2", "<0>Error");

        // maximum status over matching fields only
        xassert_eqq($ms->problem_status_under("x", ":"), MessageSet::WARNING);
        xassert(!$ms->has_error_under("x", ":"));
        xassert($ms->has_problem_under("x", ":"));
        xassert_eqq($ms->problem_status_under("x"), MessageSet::ERROR);
        xassert($ms->has_error_under("x"));

        xassert_eqq($ms->problem_status_under("xy", ":"), MessageSet::ERROR);
        xassert($ms->has_error_under("xy", ":"));

        // ESTOP is reported too
        $ms->estop_at("x:3", "<0>Estop");
        xassert_eqq($ms->problem_status_under("x", ":"), MessageSet::ESTOP);
        xassert($ms->has_error_under("x", ":"));
    }

    function test_under_multichar_separator() {
        $ms = new MessageSet;
        $ms->error_at("opt/1/name", "<0>Error");
        $ms->error_at("opt2", "<0>Error");

        xassert($ms->has_error_under("opt", "/"));
        xassert(!$ms->has_error_under("op", "/"));
        xassert($ms->has_error_under("opt/1", "/"));
        xassert($ms->has_error_under("opt/1/name", "/"));

        // a multicharacter separator must match in full
        xassert($ms->has_error_under("opt/1", "/name"));
        xassert(!$ms->has_error_under("opt/1", "/xxxx"));

        // a field equal to the prefix always matches, whatever the separator
        xassert($ms->has_error_under("opt2", "/xxxxxxxx"));
        // the separator is matched literally, not treated as a class
        xassert($ms->has_error_under("opt", "2"));
        xassert(!$ms->has_error_under("opt", "3"));
    }

    function test_under_empty_messages() {
        $ms = new MessageSet;
        xassert_eqq($ms->problem_status_under("x"), 0);
        xassert_eqq($ms->problem_status_under("x", ":"), 0);
        xassert(!$ms->has_problem_under("x", ":"));
        xassert(!$ms->has_error_under("x", ":"));

        // non-problem statuses are not problems
        $ms->append_item(MessageItem::success_at("x:1", "<0>Success"));
        xassert_eqq($ms->problem_status_under("x", ":"), 0);
        xassert(!$ms->has_problem_under("x", ":"));
    }
}
