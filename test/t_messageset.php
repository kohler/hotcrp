<?php
// t_messageset.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

#[RequireDb(false)]
class MessageSet_Tester {
    function test_fmt_list_expands_arguments() {
        $mi = MessageItem::error("<0>Field name ‘{}’ is reserved", "zomm_field");
        xassert($mi->need_fmt());
        $ml = MessageSet::make_fmt_list(new Fmt, $mi);
        xassert_eqq(count($ml), 1);
        xassert(!$ml[0]->need_fmt());
        xassert_eqq($ml[0]->message, "<0>Field name ‘zomm_field’ is reserved");
        xassert_eqq(MessageSet::feedback_text($ml), "Field name ‘zomm_field’ is reserved\n");
    }

    function test_message_set_formatter() {
        // a set with a formatter expands arguments on every accessor
        $ms = new MessageSet;
        $ms->set_message_formatter(new Fmt);
        $ms->error_at("zf", "<0>Field name ‘{}’ is reserved", "zomm_field");
        xassert_eqq($ms->message_list()[0]->message, "<0>Field name ‘zomm_field’ is reserved");
        xassert_eqq(iterator_to_array($ms->message_list_at("zf"))[0]->message,
                    "<0>Field name ‘zomm_field’ is reserved");
        xassert_eqq($ms->full_feedback_text(), "Field name ‘zomm_field’ is reserved\n");

        // a set without one hands its raw items to `make_fmt_list`
        $ms2 = new MessageSet;
        $ms2->error_at("zf", "<0>Field name ‘{}’ is reserved", "zomm_field");
        xassert($ms2->need_message_formatter());
        $ml = MessageSet::make_fmt_list(new Fmt, $ms2);
        xassert_eqq($ml[0]->message, "<0>Field name ‘zomm_field’ is reserved");
    }

    function test_ignore_dups_renders_early() {
        // duplicates that differ only in FmtArg identity must still collapse
        $mk = function () {
            return MessageItem::error("<0>Field ‘{keyword}’ is reserved",
                                      new FmtArg("keyword", "zomm", 0));
        };
        $ms = (new MessageSet)->set_ignore_duplicates(true)
            ->set_message_formatter(new Fmt);
        $ms->append_item($mk());
        $ms->append_item($mk());
        xassert_eqq($ms->message_count(), 1);
        xassert_eqq($ms->full_feedback_text(), "Field ‘zomm’ is reserved\n");

        // messages that differ only in their arguments are not duplicates
        $ms->append_item(MessageItem::error("<0>Field ‘{keyword}’ is reserved",
                                            new FmtArg("keyword", "other", 0)));
        xassert_eqq($ms->message_count(), 2);
    }

    function test_back_message_is_formatted() {
        $ms = (new MessageSet)->set_message_formatter(new Fmt);
        $ms->error_at("zf", "<0>Field ‘{}’ is reserved", "zomm");
        xassert_eqq($ms->back_message()->message, "<0>Field ‘zomm’ is reserved");
    }

    function test_append_set_from_unformatted_source() {
        // the destination’s formatter can rescue a source that has none
        $src = new MessageSet;
        $src->error_at("zf", "<0>Field ‘{}’ is reserved", "zomm");
        xassert($src->need_message_formatter());
        $dst = (new MessageSet)->set_message_formatter(new Fmt);
        $dst->append_set($src);
        xassert_eqq($dst->full_feedback_text(), "Field ‘zomm’ is reserved\n");
    }

    function test_landmark_rendering() {
        // an ordinary landmark names a location: escaped, and set off by a colon
        $mi = MessageItem::warning("<0>Entry required");
        $mi->landmark = "<5>weird.txt:39";
        $t = MessageSet::feedback_html([$mi]);
        xassert_str_contains($t, "&lt;5&gt;weird.txt:39:");
        xassert(!str_contains($t, "<5>weird.txt"));
        xassert_eqq(MessageSet::feedback_text([$mi]), "<5>weird.txt:39: Entry required\n");

        // an arrow landmark on an empty message with context marks up that
        // context, so it is emphasized and set off by a space
        $mi = MessageItem::inform_at("q", "");
        $mi->landmark = "→ expanded from";
        $mi->context = "ss:outer";
        $mi->pos1 = 0;
        $mi->pos2 = 8;
        $t = MessageSet::feedback_html([$mi]);
        xassert_str_contains($t, "→ <em>expanded from</em>");
        xassert(!str_contains($t, "expanded from:"));

        // the same landmark without context is just a location
        $mi2 = MessageItem::warning("<0>Bad search");
        $mi2->landmark = "→ expanded from";
        xassert_str_contains(MessageSet::feedback_html([$mi2]), "→ expanded from:");
    }

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
