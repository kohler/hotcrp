<?php
// t_formulas.php -- HotCRP tests
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

#[RequireDb("fresh")]
class Formulas_Tester {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $u_chair;
    /** @var Contact
     * @readonly */
    public $u_lixia;
    /** @var Contact
     * @readonly */
    public $u_mjh;
    /** @var Contact
     * @readonly */
    public $u_floyd;
    /** @var ?string
     * @readonly */
    public $saved_options;

    function __construct(Conf $conf) {
        $this->conf = $conf;
        Conf::$blocked_time = 0.0;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_lixia = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        $this->u_mjh = $conf->checked_user_by_email("mjh@isi.edu");
        $this->u_floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
        $conf->save_refresh_setting("rev_open", 1);

        // Install the submission fields used by the option-visibility tests,
        // with values stored on paper 1. Individual tests adjust the fields'
        // presence and visibility; `finalize` removes them.
        $this->saved_options = $conf->setting_data("options");
        $this->install_option_fields();
        $ps = new PaperStatus($conf->root_user());
        xassert($ps->save_paper_json(json_decode('{"id":1,"calories":350,"weight":72.5,"vegan":true}')));
        xassert_paper_status($ps);
    }

    /** Define the Calories (numeric), Weight (real-number), and Vegan
     * (checkbox) submission fields, merging $extra into each definition.
     * @param array<string,mixed> $extra */
    private function install_option_fields($extra = []) {
        $defs = [
            ["id" => 1, "name" => "Calories", "abbr" => "calories", "type" => "numeric", "position" => 1, "display" => "default"],
            ["id" => 2, "name" => "Weight", "abbr" => "weight", "type" => "realnumber", "position" => 2, "display" => "default"],
            ["id" => 3, "name" => "Vegan", "abbr" => "vegan", "type" => "checkbox", "position" => 3, "display" => "default"]
        ];
        foreach ($defs as &$d) {
            $d += $extra;
        }
        unset($d);
        $this->conf->save_refresh_setting("options", 1, json_encode($defs));
    }

    function finalize() {
        $this->conf->qe("delete from PaperOption where paperId=1 and optionId in (1,2,3)");
        if ($this->saved_options === null) {
            $this->conf->save_refresh_setting("options", null);
        } else {
            $this->conf->save_refresh_setting("options", 1, $this->saved_options);
        }
    }

    /** @param string $expr
     * @return Formula */
    private function formula($expr) {
        $f = Formula::make($this->u_chair, $expr);
        if ($f && $f->ok()) {
            $f->prepare()->prepare_json();
        }
        return $f;
    }

    /** @param string $expr
     * @return Formula */
    private function formula_as(Contact $user, $expr) {
        $f = Formula::make($user, $expr);
        xassert($f && $f->ok());
        return $f->prepare();
    }

    /** @param array<string,string> $exprs
     * @return array{bool,array<string,Formula>} */
    private function formula_set($exprs) {
        $names = array_keys($exprs);
        $formulas = [];
        foreach ($exprs as $name => $expr) {
            $config = Formula::make_config()->set_deferred(true);
            $formulas[$name] = Formula::make($this->u_chair, $expr, $config);
        }

        $deps = [];
        foreach ($formulas as $name => $f) {
            $deps[$name] = $f->param_names();
        }
        $order = Toposort::sort($deps);
        $ok = count($order) === count($formulas);

        $fmap = $formats = [];
        foreach ($order as $name) {
            $f = $formulas[$name];
            foreach ($fmap as $pname => $pf) {
                $f->set_param_format($pname, $pf->format(), $pf->format_detail());
            }
            $f->finalize();
            if ($f->ok()) {
                $f->prepare()->prepare_json();
                $fmap[$name] = $f;
            } else {
                $ok = false;
            }
        }
        return [$ok, $fmap];
    }

    function test_numeric_constants() {
        $f = $this->formula("3");
        xassert($f->ok());
        $f = $this->formula("3.5");
        xassert($f->ok());
        $f = $this->formula(".5");
        xassert($f->ok());
        $f = $this->formula("0");
        xassert($f->ok());
    }

    function test_numeric_constant_values() {
        $prow = $this->conf->checked_paper_by_id(1, $this->u_chair);
        $f = $this->formula("3");
        xassert_eqq($f->eval($prow, null), 3);
        $f = $this->formula("3.5");
        xassert_eqq($f->eval($prow, null), 3.5);
        $f = $this->formula(".5");
        xassert_eqq($f->eval($prow, null), 0.5);
    }

    function test_boolean_null_constants() {
        $prow = $this->conf->checked_paper_by_id(1, $this->u_chair);
        $f = $this->formula("true");
        xassert($f->ok());
        xassert_eqq($f->eval($prow, null), true);
        $f = $this->formula("false");
        xassert($f->ok());
        xassert_eqq($f->eval($prow, null), false);
        $f = $this->formula("null");
        xassert($f->ok());
        xassert_eqq($f->eval($prow, null), null);
    }

    function test_arithmetic() {
        $prow = $this->conf->checked_paper_by_id(1, $this->u_chair);
        xassert_eqq($this->formula("3 + 5")->prepare()->eval($prow, null), 8);
        xassert_eqq($this->formula("10 - 3")->prepare()->eval($prow, null), 7);
        xassert_eqq($this->formula("2 * 4")->prepare()->eval($prow, null), 8);
        xassert_eqq($this->formula("9 / 3")->prepare()->eval($prow, null), 3);
        xassert_eqq($this->formula("3+5")->prepare()->eval($prow, null), 8);
    }

    function test_number_not_split_from_alpha() {
        // "3x" should NOT parse as number 3 then identifier x;
        // the whole token should be treated as a single unknown identifier
        $f = $this->formula("3x");
        xassert(!$f->ok());
        $f = $this->formula("3.5x");
        xassert(!$f->ok());
        $f = $this->formula(".5x");
        xassert(!$f->ok());
        $f = $this->formula("10abc");
        xassert(!$f->ok());
    }

    function test_number_space_separation() {
        // Numbers separated from identifiers by space/operator should work
        $prow = $this->conf->checked_paper_by_id(1, $this->u_chair);
        $f = $this->formula("3 + 5");
        xassert($f->ok());
        xassert_eqq($f->eval($prow, null), 8);
    }

    function test_setup_reviews() {
        // Use papers 19 and 20 which have no pre-existing assignments
        save_review(19, $this->u_lixia, [
            "ovemer" => 4, "revexp" => 2, "ready" => true
        ]);
        save_review(19, $this->u_mjh, [
            "ovemer" => 5, "revexp" => 3, "ready" => true
        ]);
        save_review(19, $this->u_floyd, [
            "ovemer" => 3, "revexp" => 1, "ready" => true
        ]);
        save_review(20, $this->u_lixia, [
            "ovemer" => 2, "revexp" => 1, "ready" => true
        ]);
        save_review(20, $this->u_mjh, [
            "ovemer" => 5, "revexp" => 3, "ready" => true
        ]);
    }

    function test_field_reference() {
        // ovemer is the search keyword for "Overall merit" (s01)
        $f = $this->formula("avg(ovemer)");
        xassert($f->ok());
        $prow = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // Paper 19 ovemer scores: 4, 5, 3 => avg 4.0
        xassert_eqq($f->eval($prow, null), 4.0);
    }

    function test_aggregate_functions() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        $p20 = $this->conf->checked_paper_by_id(20, $this->u_chair);

        // avg: paper 19 scores 4,5,3 => 4.0
        xassert_eqq($this->formula("avg(ovemer)")->prepare()->eval($p19, null), 4.0);

        // max: 5
        xassert_eqq($this->formula("max(ovemer)")->prepare()->eval($p19, null), 5);

        // min: 3
        xassert_eqq($this->formula("min(ovemer)")->prepare()->eval($p19, null), 3);

        // count: 3
        xassert_eqq($this->formula("count(ovemer)")->prepare()->eval($p19, null), 3);

        // paper 20 scores 2,5 => avg 3.5, count 2
        xassert_eqq($this->formula("avg(ovemer)")->prepare()->eval($p20, null), 3.5);
        xassert_eqq($this->formula("count(ovemer)")->prepare()->eval($p20, null), 2);
    }

    function test_formula_arithmetic_with_aggregates() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        // avg(ovemer) + 1 => 4.0 + 1.0 = 5.0
        xassert_eqq($this->formula("avg(ovemer) + 1")->prepare()->eval($p19, null), 5.0);

        // max(ovemer) - min(ovemer) => 5 - 3 = 2
        xassert_eqq($this->formula("max(ovemer) - min(ovemer)")->prepare()->eval($p19, null), 2);

        // avg(ovemer) * 2 => 4.0 * 2 = 8.0
        xassert_eqq($this->formula("avg(ovemer) * 2")->prepare()->eval($p19, null), 8.0);
    }

    function test_count_with_condition() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // Paper 19 ovemer scores: 4, 5, 3. Scores >= 4: two (4 and 5)
        $f = $this->formula("count(ovemer >= 4)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 2);
    }

    function test_let_binding() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        $f = $this->formula("let x = avg(ovemer) in x + 1");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 5.0);

        $f = $this->formula("let y = max(ovemer) in let z = min(ovemer) in y - z");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 2);

        // Multiple bindings with comma
        $prow = $this->conf->checked_paper_by_id(1, $this->u_chair);
        $f = $this->formula("let x = 2, y = 3 in x + y");
        xassert($f->ok());
        xassert_eqq($f->eval($prow, null), 5);

        $f = $this->formula("let x = 2, y = 3 in x * y");
        xassert($f->ok());
        xassert_eqq($f->eval($prow, null), 6);

        // Later bindings can reference earlier ones
        $f = $this->formula("let x = 2, y = x + 1 in x * y");
        xassert($f->ok());
        xassert_eqq($f->eval($prow, null), 6);

        // Multiple bindings with aggregates
        $f = $this->formula("let a = max(ovemer), b = min(ovemer) in a - b");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 2);

        // Three bindings
        $f = $this->formula("let x = 1, y = 2, z = 3 in x + y + z");
        xassert($f->ok());
        xassert_eqq($f->eval($prow, null), 6);
    }

    function test_ternary() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        $p20 = $this->conf->checked_paper_by_id(20, $this->u_chair);

        // avg(ovemer)=4.0 >= 4 => true => 1
        xassert_eqq($this->formula("avg(ovemer) >= 4 ? 1 : 0")->prepare()->eval($p19, null), 1);
        // avg(ovemer)=3.5 >= 4 => false => 0
        xassert_eqq($this->formula("avg(ovemer) >= 4 ? 1 : 0")->prepare()->eval($p20, null), 0);
    }

    function test_xor_operator() {
        $prow = $this->conf->checked_paper_by_id(1, $this->u_chair);

        // ^^ returns null when both truthy, the truthy value when one is truthy,
        // false when both falsy
        xassert_eqq($this->formula("true ^^ false")->eval($prow, null), true);
        xassert_eqq($this->formula("false ^^ true")->eval($prow, null), true);
        xassert_eqq($this->formula("false ^^ false")->eval($prow, null), false);
        xassert_eqq($this->formula("true ^^ true")->eval($prow, null), null);

        // "xor" keyword works the same as ^^
        xassert_eqq($this->formula("true xor false")->eval($prow, null), true);
        xassert_eqq($this->formula("false xor true")->eval($prow, null), true);
        xassert_eqq($this->formula("false xor false")->eval($prow, null), false);
        xassert_eqq($this->formula("true xor true")->eval($prow, null), null);

        // With numeric values: returns the truthy one, null if both truthy
        xassert_eqq($this->formula("1 ^^ 0")->eval($prow, null), 1);
        xassert_eqq($this->formula("0 ^^ 1")->eval($prow, null), 1);
        xassert_eqq($this->formula("0 ^^ 0")->eval($prow, null), 0);
        xassert_eqq($this->formula("1 ^^ 1")->eval($prow, null), null);

        // In expressions
        xassert_eqq($this->formula("(3 > 2) ^^ (1 > 2)")->eval($prow, null), true);
        xassert_eqq($this->formula("(3 > 2) ^^ (1 < 2)")->eval($prow, null), null);
    }

    function test_unary_operators() {
        $prow = $this->conf->checked_paper_by_id(1, $this->u_chair);
        xassert_eqq($this->formula("-3")->prepare()->eval($prow, null), -3);
        xassert_eqq($this->formula("+3")->prepare()->eval($prow, null), 3);
        xassert_eqq($this->formula("!true")->prepare()->eval($prow, null), false);
        xassert_eqq($this->formula("not true")->prepare()->eval($prow, null), false);
    }

    function test_parenthesized_expressions() {
        $prow = $this->conf->checked_paper_by_id(1, $this->u_chair);
        xassert_eqq($this->formula("(3 + 5) * 2")->prepare()->eval($prow, null), 16);
    }

    function test_identifier_separator_rules() {
        // Trailing dots: "ovemer." should parse as "ovemer" then "."
        // causing a parse error
        $f = $this->formula("avg(ovemer.)");
        xassert(!$f->ok());

        // Consecutive dots should not form a valid identifier
        $f = $this->formula("avg(ove..mer)");
        xassert(!$f->ok());
    }

    function test_review_keywords() {
        // Review keywords handled by early branches in the parser
        $f = $this->formula("count(revtype)");
        xassert($f->ok());
    }

    function test_let_binding_various_names() {
        $prow = $this->conf->checked_paper_by_id(1, $this->u_chair);
        // Single letter
        xassert_eqq($this->formula("let x = 3 in x + 2")->prepare()->eval($prow, null), 5);
        // Multi-letter
        xassert_eqq($this->formula("let abc = 3 in abc + 2")->prepare()->eval($prow, null), 5);
        // Alphanumeric
        xassert_eqq($this->formula("let x1 = 10 in x1 * 2")->prepare()->eval($prow, null), 20);
        // Underscored
        xassert_eqq($this->formula("let my_var = 7 in my_var - 1")->prepare()->eval($prow, null), 6);
        // Nested let
        xassert_eqq($this->formula("let a = 1 in let b = 2 in a + b")->prepare()->eval($prow, null), 3);
    }

    function test_let_binding_with_aggregates() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // Bind an aggregate result
        $f = $this->formula("let m = avg(ovemer) in m * 10");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 40.0);
    }

    function test_number_in_expressions() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // Number followed by operator then function call
        xassert_eqq($this->formula("3 + avg(ovemer)")->prepare()->eval($p19, null), 7.0);
        xassert_eqq($this->formula("3+avg(ovemer)")->prepare()->eval($p19, null), 7.0);
        xassert_eqq($this->formula("10 - avg(ovemer)")->prepare()->eval($p19, null), 6.0);
        // Decimal in expression
        xassert_eqq($this->formula(".5 * avg(ovemer)")->prepare()->eval($p19, null), 2.0);
        xassert_eqq($this->formula("3.5 + .5")->prepare()->eval($p19, null), 4.0);
    }

    function test_dot_hyphen_separator() {
        // Dot followed by hyphen should not be valid within an identifier
        $f = $this->formula("avg(foo.-bar)");
        xassert(!$f->ok());
    }

    function test_quoted_field_reference() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        $f = $this->formula("avg(\"Overall merit\")");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 4.0);
    }

    function test_any_all_aggregates() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // Paper 19 scores: 4, 5, 3. All >= 3? yes. All >= 4? no.
        $f = $this->formula("all(ovemer >= 3)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), true);

        $f = $this->formula("all(ovemer >= 4)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), false);

        // Any >= 5? yes (score 5).
        $f = $this->formula("any(ovemer >= 5)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), true);

        // Any >= 6? no.
        $f = $this->formula("any(ovemer >= 6)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), false);
    }

    function test_all_aggregate_counts_valueless_elements() {
        // `all` counts an element with no value against the result: most PC
        // members express no preference, so `all.pc(pref > 0)` is false. That
        // must not depend on where the valueless elements fall in the loop --
        // an accumulator that stays null until the first element with a value
        // silently skips every null ahead of it.
        $conf = $this->conf;
        $cids = array_keys($conf->viewable_pc_members($this->u_chair));
        xassert_gt(count($cids), 2);
        $vs = [];
        foreach ([$cids[0], $cids[count($cids) - 1]] as $cid) {
            $conf->qe("delete from PaperReviewPreference where paperId=1");
            $conf->qe("insert into PaperReviewPreference set paperId=1, contactId=?, preference=5", $cid);
            $p1 = $conf->checked_paper_by_id(1, $this->u_chair);
            $vs[] = $this->formula_as($this->u_chair, "all.pc(pref > 0)")->eval($p1, null);
        }
        // sole preference first in the loop, then last: same answer
        xassert_eqq($vs[0], $vs[1]);
        xassert_eqq($vs[0], false);

        // ...while over the preference collection every element has a value
        $p1 = $conf->checked_paper_by_id(1, $this->u_chair);
        xassert_eqq($this->formula_as($this->u_chair, "all.pref(pref > 0)")->eval($p1, null), true);

        $conf->qe("delete from PaperReviewPreference where paperId=1");
    }

    function test_sum_aggregate() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // Paper 19 scores: 4, 5, 3 => sum 12
        $f = $this->formula("sum(ovemer)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 12);
    }

    function test_second_review_field() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // revexp scores: 2, 3, 1 => avg 2.0
        $f = $this->formula("avg(revexp)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 2.0);
    }

    function test_camelcase_field_reference() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // OveMer is the CamelCase search keyword
        $f = $this->formula("avg(OveMer)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 4.0);
    }

    function test_no_reviews() {
        // Paper 21 has no reviews
        $p21 = $this->conf->checked_paper_by_id(21, $this->u_chair);
        $f = $this->formula("avg(ovemer)");
        xassert($f->ok());
        xassert_eqq($f->eval($p21, null), null);
        xassert_eqq($this->formula("count(ovemer)")->prepare()->eval($p21, null), 0);
    }

    function test_my_review_values() {
        // `my(X)` compiles the IDX_MY path, which resolves the viewer's own
        // review row directly rather than by iterating the review collection.
        $conf = $this->conf;
        $p19 = $conf->checked_paper_by_id(19, $this->u_lixia);
        xassert_eqq($this->formula_as($this->u_lixia, "my(re)")->eval($p19, null), true);
        xassert_eqq($this->formula_as($this->u_lixia, "my(cre)")->eval($p19, null), true);
        xassert_eqq($this->formula_as($this->u_lixia, "my(revround)")->eval($p19, null), 0);
        xassert_eqq($this->formula_as($this->u_lixia, "my(rewords)")->eval($p19, null), 0);

        // With no review of one's own the result is null, not some other
        // reviewer's value: paper 19 has three reviews, none of them the
        // chair's, and paper 21 has none at all.
        $p19c = $conf->checked_paper_by_id(19, $this->u_chair);
        xassert_eqq($this->formula_as($this->u_chair, "my(re)")->eval($p19c, null), false);
        xassert_eqq($this->formula_as($this->u_chair, "my(rewords)")->eval($p19c, null), null);
        $p21 = $conf->checked_paper_by_id(21, $this->u_lixia);
        xassert_eqq($this->formula_as($this->u_lixia, "my(re)")->eval($p21, null), false);
        xassert_eqq($this->formula_as($this->u_lixia, "my(revround)")->eval($p21, null), null);
        xassert_eqq($this->formula_as($this->u_lixia, "my(rewords)")->eval($p21, null), null);
    }

    function test_my_preference() {
        // `my(pref)` is the viewer's own preference, so it needs neither
        // aggregate nor individual preference rights.
        $conf = $this->conf;
        $this->seed_paper1_preferences();     // lixia 5, estrin 4
        $u_estrin = $conf->checked_user_by_email("estrin@usc.edu");
        $u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $p1l = $conf->checked_paper_by_id(1, $this->u_lixia);
        $p1e = $conf->checked_paper_by_id(1, $u_estrin);
        $p1m = $conf->checked_paper_by_id(1, $u_marina);
        xassert_eqq($this->formula_as($this->u_lixia, "my(pref)")->eval($p1l, null), 5);
        // ...including for a PC member conflicted with the paper, who has no
        // aggregate preference right at all
        xassert(!$u_estrin->can_view_preference($p1e, true));
        xassert_eqq($this->formula_as($u_estrin, "my(pref)")->eval($p1e, null), 4);
        // ...and 0 for someone who expressed none
        xassert_eqq($this->formula_as($u_marina, "my(pref)")->eval($p1m, null), 0);

        $conf->qe("delete from PaperReviewPreference where paperId=1");
    }

    function test_colon_reviewer_decoration() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        // OveMer:reviewer filters to a specific reviewer's score
        // lixia gave score 4, mjh gave 5, floyd gave 3
        $f = $this->formula("OveMer:lixia");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 4);

        $f = $this->formula("OveMer:mjh");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 5);

        $f = $this->formula("OveMer:floyd");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 3);

        // Nonexistent reviewer returns null
        $f = $this->formula("OveMer:nobody");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), null);
    }

    function test_colon_reviewer_in_aggregate() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        $f = $this->formula("count(OveMer:lixia)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 1);

        $f = $this->formula("avg(OveMer:lixia)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 4.0);
    }

    function test_colon_score_value() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        $p21 = $this->conf->checked_paper_by_id(21, $this->u_chair);

        // OveMer:5 — colon followed by a number is a reviewer decoration
        // (no reviewer matches "5"), so evaluates to null
        $f = $this->formula("OveMer:5");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), null);
        xassert_eqq($f->eval($p21, null), null);
    }

    function test_setup_tags() {
        // Add tags for tag formula tests
        $this->conf->qe("insert into PaperTag (paperId, tag, tagIndex) values (19,'testtag',0), (20,'testtag',1), (19,'scored',7), (20,'scored',3)");
    }

    function test_tag_formula() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        $p20 = $this->conf->checked_paper_by_id(20, $this->u_chair);
        $p21 = $this->conf->checked_paper_by_id(21, $this->u_chair);

        // tag:TAGNAME returns truthy/falsy for tag presence
        $f = $this->formula("tag:testtag");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), true);
        xassert_eqq($f->eval($p20, null), 1.0);
        xassert_eqq($f->eval($p21, null), false);

        // Nonexistent tag
        $f = $this->formula("tag:nonexistent");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), false);

        // #tagname returns the tag value (tagIndex)
        $f = $this->formula("#scored");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 7.0);
        xassert_eqq($f->eval($p20, null), 3.0);
        xassert_eqq($f->eval($p21, null), false);
    }

    function test_hyphenated_field_aggregates() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        $p20 = $this->conf->checked_paper_by_id(20, $this->u_chair);

        // "overall-merit" is the hyphenated form of "Overall merit" (s01)
        $f = $this->formula("avg(overall-merit)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 4.0);
        xassert_eqq($f->eval($p20, null), 3.5);

        xassert_eqq($this->formula("max(overall-merit)")->eval($p19, null), 5);
        xassert_eqq($this->formula("min(overall-merit)")->eval($p19, null), 3);
        xassert_eqq($this->formula("count(overall-merit)")->eval($p19, null), 3);
        xassert_eqq($this->formula("sum(overall-merit)")->eval($p19, null), 12);
    }

    function test_hyphenated_field_with_condition() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // Scores 4, 5, 3: two >= 4
        $f = $this->formula("count(overall-merit >= 4)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 2);
    }

    function test_hyphenated_field_arithmetic() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        // max - min using hyphenated field name
        $f = $this->formula("max(overall-merit) - min(overall-merit)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 2);

        // Ternary with hyphenated field
        $f = $this->formula("avg(overall-merit) >= 4 ? 1 : 0");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 1);
    }

    function test_hyphenated_field_let_binding() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        $f = $this->formula("let x = avg(overall-merit) in x + 1");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 5.0);
    }

    function test_hyphenated_field_reviewer_decoration() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        // overall-merit:reviewer filters to a specific reviewer
        $f = $this->formula("overall-merit:lixia");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 4);

        $f = $this->formula("overall-merit:mjh");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 5);
    }

    function test_setup_alpha_scores() {
        // Change OveMer to alphabetical symbols: E=1, D=2, C=3, B=4, A=5
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s01",
            "rf/1/values_text" => "E. Reject\nD. Weak reject\nC. Weak accept\nB. Accept\nA. Strong accept\n"
        ]);
        xassert($sv->execute());
    }

    function test_alpha_score_equality() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // Paper 19 scores: lixia=B(4), mjh=A(5), floyd=C(3)
        // count(OveMer == B) should find 1 review (lixia's score of 4=B)
        $f = $this->formula("count(OveMer == B)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 1);

        // count(OveMer == A) should find 1 review (mjh's score of 5=A)
        $f = $this->formula("count(OveMer == A)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 1);

        // count(OveMer == C) should find 1 review (floyd's score of 3=C)
        $f = $this->formula("count(OveMer == C)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 1);

        // count(OveMer == E) should find 0 reviews
        $f = $this->formula("count(OveMer == E)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 0);
    }

    function test_alpha_score_unknown_value() {
        // XYZ is not a valid score symbol — should error
        $f = $this->formula("count(OveMer == XYZ)");
        xassert(!$f->ok());
        xassert_str_contains($f->full_feedback_text(), "XYZ");
    }

    function test_alpha_score_inequality() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // With alpha symbols (A=5 best, E=1 worst), this field has flip_relation
        // so OveMer >= B means score >= 4, i.e. lixia(4) and mjh(5)
        $f = $this->formula("count(OveMer >= B)");
        xassert($f->ok());
        xassert_eqq($f->param_names(), []);
        xassert_eqq($f->eval($p19, null), 2);

        // OveMer < C means score < 3, i.e. nobody (scores are 3,4,5)
        $f = $this->formula("count(OveMer < C)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 0);

        // Quoted symbols work too
        $f = $this->formula("count(OveMer >= \"B\")");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 2);

        // OveMer < C means score < 3, i.e. nobody (scores are 3,4,5)
        $f = $this->formula("count(OveMer < \"C\")");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 0);
    }

    function test_alpha_score_aggregates() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // avg/min/max still return numeric values
        xassert_eqq($this->formula("avg(OveMer)")->prepare()->eval($p19, null), 4.0);
        xassert_eqq($this->formula("max(OveMer)")->prepare()->eval($p19, null), 5);
        xassert_eqq($this->formula("min(OveMer)")->prepare()->eval($p19, null), 3);
    }

    function test_restore_numeric_scores() {
        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s01",
            "rf/1/values_text" => "1. Reject\n2. Weak reject\n3. Weak accept\n4. Accept\n5. Strong accept\n"
        ]);
        xassert($sv->execute());
    }

    function test_toposort_independent() {
        // No dependencies: all nodes in sorted order
        $order = Toposort::sort([
            "a" => [], "b" => [], "c" => []
        ]);
        xassert_eqq($order, ["a", "b", "c"]);
    }

    function test_toposort_chain() {
        // a depends on b, b depends on c => order: c, b, a
        $order = Toposort::sort([
            "a" => ["b"], "b" => ["c"], "c" => []
        ]);
        xassert_eqq($order, ["c", "b", "a"]);
    }

    function test_toposort_diamond() {
        // d depends on b and c; b and c depend on a
        $order = Toposort::sort([
            "a" => [], "c" => ["a"], "b" => ["a"], "d" => ["b", "c"]
        ]);
        xassert_eqq($order, ["a", "c", "b", "d"]);
    }

    function test_toposort_cycle() {
        // a -> b -> a is a cycle
        $order = Toposort::sort([
            "a" => ["b"], "b" => ["a"]
        ]);
        xassert_eqq($order, []);
    }

    function test_toposort_partial_cycle() {
        // c is independent, a and b form a cycle
        $order = Toposort::sort([
            "a" => ["b"], "b" => ["a"], "c" => []
        ]);
        xassert_eqq($order, ["c"]);
    }

    function test_toposort_external_dep() {
        // dependency on name not in the graph is ignored
        $order = Toposort::sort([
            "a" => ["external"], "b" => []
        ]);
        xassert_eqq($order, ["a", "b"]);
    }

    function test_param_basic() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        // Create a formula with a param
        $config = Formula::make_config()
            ->add_param("x", Fexpr::FNUMERIC);
        $f = Formula::make($this->u_chair, "x + 1", $config);
        xassert($f->ok());
        $f->bind("x", 10);
        $f->prepare();
        xassert_eqq($f->eval($p19, null), 11);

        // Change param value
        $f->bind("x", 20);
        xassert_eqq($f->eval($p19, null), 21);
    }

    function test_deferred_formula() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        // Create a deferred formula
        $config = Formula::make_config()->set_deferred(true);
        $f = Formula::make($this->u_chair, "x + 1", $config);
        // Before finalize, format is unknown
        xassert_eqq($f->format(), Fexpr::FUNKNOWN);

        // Finalize with numeric format
        $f->set_param_format("x", Fexpr::FNUMERIC);
        $f->finalize();
        xassert($f->ok());
        xassert_eqq($f->format(), Fexpr::FNUMERIC);

        // Set param and eval
        $f->bind("x", 5);
        $f->prepare();
        xassert_eqq($f->eval($p19, null), 6);
    }

    function test_formulaset_basic() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        // x = avg(OveMer) (no deps)
        // z = x ** 2      (depends on x)
        // y = z + 1       (depends on z)
        list($ok, $fmap) = $this->formula_set([
            "x" => "avg(OveMer)",
            "y" => "z + 1",
            "z" => "x ** 2"
        ]);
        xassert($ok);
        // Eval order: x first (no deps), then z (depends on x), then y (depends on z)
        xassert_eqq(array_keys($fmap), ["x", "z", "y"]);

        // Evaluate in order, threading results
        $x = $fmap["x"]->eval($p19, null);
        xassert_eqq($x, 4.0);

        $fmap["z"]->bind("x", $x);
        $z = $fmap["z"]->eval($p19, null);
        xassert_eqq($z, 16.0);

        $fmap["y"]->bind("z", $z);
        $y = $fmap["y"]->eval($p19, null);
        xassert_eqq($y, 17.0);
    }

    function test_formulaset_cycle() {
        // a depends on b, b depends on a => cycle
        list($ok, $fmap) = $this->formula_set([
            "a" => "b + 1",
            "b" => "a + 1"
        ]);
        xassert(!$ok);
        xassert_eqq(array_keys($fmap), []);
    }

    function test_formulaset_independent() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        // All independent formulas
        list($ok, $fmap) = $this->formula_set([
            "a" => "avg(OveMer)",
            "b" => "max(OveMer)",
            "c" => "3 + 5"
        ]);
        xassert($ok);
        xassert_eqq($fmap["a"]->eval($p19, null), 4.0);
        xassert_eqq($fmap["b"]->eval($p19, null), 5);
        xassert_eqq($fmap["c"]->eval($p19, null), 8);
    }

    function test_formulaset_different_paper() {
        $p20 = $this->conf->checked_paper_by_id(20, $this->u_chair);

        list($ok, $fmap) = $this->formula_set([
            "x" => "avg(OveMer)",
            "y" => "x * 2"
        ]);
        xassert($ok);

        $x = $fmap["x"]->eval($p20, null);
        xassert_eqq($x, 3.5);

        $fmap["y"]->bind("x", $x);
        xassert_eqq($fmap["y"]->eval($p20, null), 7.0);
    }

    function test_graph_parse_datasets_t() {
        // a global `t` applies to every series
        $ds = FormulaGraph::parse_datasets(TestQreq::get(["q" => "1", "t" => "s"]));
        xassert_eqq(count($ds), 1);
        xassert_eqq($ds[0]->t, "s");

        // a per-series `t{i}` overrides the global `t`; series without one fall
        // back to the global value
        $ds = FormulaGraph::parse_datasets(TestQreq::get([
            "q1" => "1", "t1" => "all", "q2" => "2", "t" => "s"
        ]));
        xassert_eqq(count($ds), 2);
        xassert_eqq($ds[0]->t, "all");
        xassert_eqq($ds[1]->t, "s");

        // no `t` leaves the collection unset (null), so PaperSearch picks its default
        $ds = FormulaGraph::parse_datasets(TestQreq::get(["q" => "1"]));
        xassert_eqq($ds[0]->t, null);
    }

    /** @param string $fx
     * @param string $fy
     * @param ?string $t
     * @return int number of plotted values */
    private function graph_point_count($fx, $fy, $t) {
        $fg = new FormulaGraph($this->u_chair, "scatter", $fx, $fy);
        $fg->add_dataset(new FormulaGraphDataset("", $t, "", ""));
        $data = $fg->graph_json([])["data"];
        $n = 0;
        array_walk_recursive($data, function () use (&$n) { ++$n; });
        return $n;
    }

    /** @return int number of plotted submissions */
    private function graph_pid_count($t) {
        return $this->graph_point_count("pid", "pid", $t);
    }

    function test_graph_t_filters_results() {
        // Unsubmit paper 1 so it leaves the `s` (submitted) collection but stays
        // in `all`; the plotted set then differs by exactly that paper, proving
        // the dataset's `t` reaches the underlying search.
        $saved = $this->conf->fetch_ivalue("select timeSubmitted from Paper where paperId=1");
        $this->conf->qe("update Paper set timeSubmitted=0 where paperId=1");

        $all = $this->graph_pid_count("all");
        $submitted = $this->graph_pid_count("s");
        xassert($submitted > 0);
        xassert_eqq($submitted, $all - 1);

        $this->conf->qe("update Paper set timeSubmitted=? where paperId=1", $saved);
    }

    function test_graph_type_prefix() {
        foreach (["dot", "dots", "dotplot"] as $s) {
            xassert_eqq(FormulaGraph::graph_type_prefix($s), [FormulaGraph::DOT, $s]);
        }
        foreach (["ldot", "ldots", "ldotplot", "dotlabel", "dotlabels",
                  "dotlabelplot", "numdot", "numdots", "numdotplot"] as $s) {
            xassert_eqq(FormulaGraph::graph_type_prefix($s), [FormulaGraph::LDOT, $s]);
        }
        // `ldot` is a distinct type, but shares DOT's bit so that everything
        // keyed on DOT (highlighting, for one) applies to it too
        xassert_neqq(FormulaGraph::LDOT, FormulaGraph::DOT);
        xassert(FormulaGraph::LDOT & FormulaGraph::DOT);
        xassert_eqq(FormulaGraph::LDOT & (FormulaGraph::CDF | FormulaGraph::BARCHART | FormulaGraph::BOXPLOT | FormulaGraph::SCATTER), 0);
        xassert_eqq(FormulaGraph::graph_type_prefix("ldotty"), null);
        xassert_eqq(FormulaGraph::graph_type_prefix("numdotty"), null);
    }

    function test_graph_ldot() {
        // `ldot` plots like `dot`; the JS labels each dot with its pid.
        // `numdot` is the old spelling and still works
        foreach ([["dot", "dot"], ["ldot", "ldot"], ["ldots", "ldot"],
                  ["dotlabel", "ldot"], ["numdot", "ldot"]] as $st) {
            $fg = new FormulaGraph($this->u_chair, $st[0], "pid", "pid");
            $fg->add_dataset(new FormulaGraphDataset("", "all", "", ""));
            $j = $fg->graph_json([]);
            xassert_eqq($j["type"], $st[1]);
            xassert_eqq($j["data_format"], "style_xyi");
            $n = 0;
            array_walk_recursive($j["data"], function () use (&$n) { ++$n; });
            xassert($n > 0);
        }

        // the type may also arrive as a prefix on the Y axis formula
        $fg = new FormulaGraph($this->u_chair, null, "pid", "ldot pid");
        $fg->add_dataset(new FormulaGraphDataset("", "all", "", ""));
        xassert_eqq($fg->graph_json([])["type"], "ldot");
        xassert_eqq($fg->fy->expression, "pid");
    }

    function test_graph_explains_empty_data() {
        // A search that matches nothing plots nothing; say so rather than
        // rendering a blank chart.
        $fg = new FormulaGraph($this->u_chair, "scatter", "pid", "pid");
        $fg->add_dataset(new FormulaGraphDataset("12345", "all", "", ""));
        xassert_eqq($fg->graph_json([])["data"], []);
        xassert_eqq($fg->full_feedback_text(), "No data to graph\n");
    }

    function test_graph_explains_axes_that_never_coexist() {
        // Split the scored reviews between rounds R1 and R2, then restrict
        // OveMer to R1 and RevExp to R2. No review is in both rounds, so a
        // scatterplot of one field against the other is necessarily empty --
        // the situation a conference hits when it reviews in rounds with
        // different forms. Restricting a field also clears its out-of-round
        // scores, so save the review rows and put them back afterwards.
        $saved = Dbl::fetch_rows($this->conf->dblink,
            "select reviewId, reviewRound, s01, s02 from PaperReview");
        $scored = array_values(array_filter($saved, function ($row) {
            return $row[2] !== "0" && $row[3] !== "0";
        }));
        xassert(count($scored) >= 2);
        $rids = array_map(function ($row) { return (int) $row[0]; }, $scored);
        $half = intdiv(count($rids), 2);
        $this->conf->qe("update PaperReview set reviewRound=1 where reviewId?a", array_slice($rids, 0, $half));
        $this->conf->qe("update PaperReview set reviewRound=2 where reviewId?a", array_slice($rids, $half));

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s01",
            "rf/1/presence" => "round:R1",
            "rf/2/id" => "s02",
            "rf/2/presence" => "round:R2"
        ]);
        xassert($sv->execute());

        // each field has values on its own
        xassert($this->graph_point_count("OveMer", "OveMer", "all") > 0);
        xassert($this->graph_point_count("RevExp", "RevExp", "all") > 0);

        // but never on the same review, and the graph explains why it is empty
        $fg = new FormulaGraph($this->u_chair, "scatter", "OveMer", "RevExp");
        $fg->add_dataset(new FormulaGraphDataset("", "all", "", ""));
        xassert_eqq($fg->graph_json([])["data"], []);
        xassert_eqq($fg->full_feedback_text(),
            "No review has values for both ‘OveMer’ and ‘RevExp’\n    Try ‘avg(OveMer)’ and ‘avg(RevExp)’ to compare per-submission averages.\n");

        $sv = SettingValues::make_request($this->u_chair, [
            "has_rf" => 1,
            "rf/1/id" => "s01",
            "rf/1/presence" => "all",
            "rf/2/id" => "s02",
            "rf/2/presence" => "all"
        ]);
        xassert($sv->execute());
        xassert_eqq($this->conf->checked_review_field("s01")->exists_condition(), null);
        xassert_eqq($this->conf->checked_review_field("s02")->exists_condition(), null);

        foreach ($saved as $row) {
            $this->conf->qe("update PaperReview set reviewRound=?, s01=?, s02=? where reviewId=?",
                (int) $row[1], (int) $row[2], (int) $row[3], (int) $row[0]);
        }
    }

    /** @param Contact $user
     * @param int $pid
     * @return mixed */
    private function pagecount_eval($user, $pid) {
        $f = Formula::make($user, "pagecount");
        $f->prepare();
        return $f->eval($this->conf->checked_paper_by_id($pid, $user), null);
    }

    function test_pagecount_respects_option_visibility() {
        // A user who cannot view the submission document must not be able to
        // learn its page count, either through the `pagecount` formula or the
        // `pages:` search keyword.
        $u_mgbaker = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");

        // Give paper 1 a 50-page submission PDF and cache its page count.
        $ps = new PaperStatus($this->conf->root_user());
        $ps->on_document_import(function ($dj, $dt, $pstatus) {
            if (is_string($dj->content_file ?? null) && !($dj instanceof DocumentInfo)) {
                $dj->content_file = SiteLoader::$root . "/" . $dj->content_file;
            }
        });
        xassert($ps->save_paper_json(json_decode("{\"id\":1,\"submission\":{\"content_file\":\"test/sample50pg.pdf\",\"type\":\"application/pdf\"}}")));
        xassert_paper_status($ps);
        $paper1 = $this->conf->checked_paper_by_id(1, $u_mgbaker);
        xassert_eqq($paper1->document(DTYPE_SUBMISSION)->npages(), 50);

        // mgbaker is a non-conflicted PC member, so she can normally read the
        // page count.
        xassert_eqq($this->pagecount_eval($u_mgbaker, 1), 50);
        xassert_search($u_mgbaker, "pages:>40", "1");

        // Hide the submission field behind a presence condition that paper 1
        // does not satisfy. mgbaker can still view the paper’s PDF in general,
        // but the submission option is no longer visible to her.
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/id" => "submission",
            "sf/1/presence" => "custom",
            "sf/1/condition" => "#secret"
        ]);
        xassert($sv->execute());

        $subopt = $this->conf->option_by_id(DTYPE_SUBMISSION);
        $paper1 = $this->conf->checked_paper_by_id(1, $u_mgbaker);
        xassert($u_mgbaker->can_view_pdf($paper1));
        xassert(!$u_mgbaker->can_view_option($paper1, $subopt));

        // The page count must no longer leak, through the formula...
        xassert_eqq($this->pagecount_eval($u_mgbaker, 1), null);
        // ...or through search.
        xassert_search($u_mgbaker, "pages:>40", "");

        // The document and its page count still exist; they are merely hidden.
        xassert_eqq($paper1->document(DTYPE_SUBMISSION)->npages(), 50);

        // Restore the submission field.
        $sv = SettingValues::make_request($this->u_chair, [
            "has_sf" => 1,
            "sf/1/id" => "submission",
            "sf/1/presence" => "all"
        ]);
        xassert($sv->execute());
    }

    function test_formulas_respect_option_presence() {
        // The formula terms that read submission-field values must not leak a
        // value the viewer cannot see: OptionValue_Fexpr (numeric fields),
        // RealNumberOption_Fexpr (real-number fields), and OptionPresent_Fexpr
        // (checkbox fields).
        $u_mgbaker = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $f_calories = Formula::make($u_mgbaker, "opt:calories")->prepare();
        $f_weight = Formula::make($u_mgbaker, "opt:weight")->prepare();
        $f_vegan = Formula::make($u_mgbaker, "opt:vegan")->prepare();

        // Baseline: with the fields present on every submission, mgbaker (a
        // non-conflicted PC member) can read the values through all three terms.
        $this->install_option_fields();
        $paper1 = $this->conf->checked_paper_by_id(1, $u_mgbaker);
        xassert($u_mgbaker->can_view_option($paper1, $this->conf->option_by_id(1)));
        xassert_eqq($f_calories->eval($paper1, null), 350); // OptionValue_Fexpr
        xassert_eqq($f_weight->eval($paper1, null), 72.5);  // RealNumberOption_Fexpr
        xassert_eqq($f_vegan->eval($paper1, null), true);   // OptionPresent_Fexpr

        // Hide all three behind a presence condition paper 1 does not satisfy.
        $this->install_option_fields(["exists_if" => "#shown"]);

        // The fields remain generally visible — so they still resolve in
        // formulas — but they are no longer present on paper 1 for mgbaker.
        $paper1 = $this->conf->checked_paper_by_id(1, $u_mgbaker);
        foreach ([1, 2, 3] as $oid) {
            $opt = $this->conf->option_by_id($oid);
            xassert($u_mgbaker->can_view_some_option($opt));
            xassert(!$u_mgbaker->can_view_option($paper1, $opt));
        }

        // The stored values must no longer leak through any of the three terms.
        xassert_eqq($f_calories->eval($paper1, null), null); // OptionValue_Fexpr
        xassert_eqq($f_weight->eval($paper1, null), null);   // RealNumberOption_Fexpr
        xassert_eqq($f_vegan->eval($paper1, null), false);   // OptionPresent_Fexpr
    }

    function test_formulas_respect_option_visibility() {
        // The same three formula terms must also honor a field's `visibility`
        // setting, as opposed to a presence condition. Here the fields are
        // restricted to reviewers who can see a paper's reviews; an
        // administrator can still read them.
        $u_mgbaker = $this->conf->checked_user_by_email("mgbaker@cs.stanford.edu");
        $f_calories = Formula::make($u_mgbaker, "opt:calories")->prepare();
        $f_weight = Formula::make($u_mgbaker, "opt:weight")->prepare();
        $f_vegan = Formula::make($u_mgbaker, "opt:vegan")->prepare();

        // Baseline: with the fields visible to everyone who can see the paper,
        // mgbaker can read the values through all three terms.
        $this->install_option_fields();
        $paper1 = $this->conf->checked_paper_by_id(1);
        xassert_eqq($f_calories->eval($paper1, null), 350); // OptionValue_Fexpr
        xassert_eqq($f_weight->eval($paper1, null), 72.5);  // RealNumberOption_Fexpr
        xassert_eqq($f_vegan->eval($paper1, null), true);   // OptionPresent_Fexpr

        // Restrict all three to reviewers who can view a paper's reviews.
        $this->install_option_fields(["visibility" => "review"]);

        // mgbaker is a reviewer (so the fields still resolve in formulas) but
        // cannot see paper 1's reviews, so the fields are not visible to her
        // on paper 1.
        $paper1 = $this->conf->checked_paper_by_id(1, $u_mgbaker);
        foreach ([1, 2, 3] as $oid) {
            $opt = $this->conf->option_by_id($oid);
            xassert($u_mgbaker->can_view_some_option($opt));
            xassert(!$u_mgbaker->can_view_option($paper1, $opt));
        }

        // The values must not leak to mgbaker through any of the three terms.
        xassert_eqq($f_calories->eval($paper1, null), null); // OptionValue_Fexpr
        xassert_eqq($f_weight->eval($paper1, null), null);   // RealNumberOption_Fexpr
        xassert_eqq($f_vegan->eval($paper1, null), false);   // OptionPresent_Fexpr

        // The administrator can read the values: a `visibility` restriction,
        // unlike a presence condition, does not hide the field from admins.
        $paper1c = $this->conf->checked_paper_by_id(1, $this->u_chair);
        foreach ([1, 2, 3] as $oid) {
            xassert($this->u_chair->can_view_option($paper1c, $this->conf->option_by_id($oid)));
        }
        $f_calories = Formula::make($this->u_chair, "opt:calories")->prepare();
        $f_weight = Formula::make($this->u_chair, "opt:weight")->prepare();
        $f_vegan = Formula::make($this->u_chair, "opt:vegan")->prepare();
        xassert_eqq($f_calories->eval($paper1c, null), 350); // OptionValue_Fexpr
        xassert_eqq($f_weight->eval($paper1c, null), 72.5);  // RealNumberOption_Fexpr
        xassert_eqq($f_vegan->eval($paper1c, null), true);   // OptionPresent_Fexpr
    }

    function test_review_meta_hidden_from_pc_author() {
        // A PC member who authors a paper may read that paper's submitted
        // reviews (au_seerev), but a review's *type* and *round* are metadata
        // gated by can_view_review_meta — false for a conflicted PC-author. So
        // `revtype`/`reround` must not let them recover it, even though the
        // formulas compile (they are is_reviewer() globally).
        $conf = $this->conf;
        $mjh = $this->u_mjh;        // PC, contact-author of paper 17
        $lixia = $this->u_lixia;    // PC reviewer on paper 17 (round 1)
        $p17 = $conf->checked_paper_by_id(17);
        xassert($mjh->isPC && $mjh->is_reviewer());
        xassert_ge($p17->conflict_type($mjh), CONFLICT_AUTHOR);

        $old_ausr = $conf->setting("au_seerev");
        $conf->save_refresh_setting("au_seerev", Conf::AUSEEREV_YES);

        // submit lixia's review with author-visible content (Overall merit +
        // Comments for authors), so the author can see the review itself
        $text = "==+== Paper #17\n\n==+== Review Readiness\nReady\n\n"
            . "==+== A. Overall merit\n4\n\n==+== B. Reviewer expertise\n2\n\n"
            . "==+== D. Comments for authors\nGood work.\n";
        $tf = (new ReviewValues($lixia))->set_text($text, "r17.txt");
        xassert($tf->parse_text());
        xassert($tf->check_and_save(null));

        $p17 = $conf->checked_paper_by_id(17);
        $rrow = $p17->fresh_review_by_user($lixia);
        xassert_gt($rrow->reviewType, 0);

        // precondition — the author CAN see the review content but NOT its
        // metadata, so a null formula result means "gated," not "invisible"
        xassert($mjh->can_view_review($p17, $rrow));
        xassert(!$mjh->can_view_review_meta($p17, $rrow));

        // the leak: neither type nor round may be recovered
        xassert_eqq($this->formula_as($mjh, "max(revtype)")->eval($p17, null), null);
        xassert_eqq($this->formula_as($mjh, "max(reround)")->eval($p17, null), null);
        xassert_eqq($this->formula_as($mjh, "count(revtype>0)")->eval($p17, null), 0);

        // ...while an administrator, who may view review metadata, sees them —
        // proving the data is present and only the gate hides it
        $maxtype = $maxround = 0;
        foreach ($p17->viewable_reviews_as_display($this->u_chair) as $rr) {
            $maxtype = max($maxtype, $rr->reviewType);
            $maxround = max($maxround, $rr->reviewRound);
        }
        xassert_gt($maxtype, 0);
        xassert_eqq($this->formula_as($this->u_chair, "max(revtype)")->eval($p17, null), $maxtype);
        xassert_eqq($this->formula_as($this->u_chair, "max(reround)")->eval($p17, null), $maxround);

        $conf->save_refresh_setting("au_seerev", $old_ausr);
    }

    /** @param list<string> $ids
     * @return list<string> */
    static private function ordinal_ids($ids) {
        $x = [];
        foreach ($ids as $id) {
            if (!ctype_digit($id)) {
                $x[] = $id;
            }
        }
        sort($x);
        return $x;
    }

    /** @param Contact $user
     * @param string $fx
     * @param string $fy
     * @param string $q
     * @return list<string> */
    private function graph_point_ids($user, $fx, $fy, $q) {
        $fg = new FormulaGraph($user, "scatter", $fx, $fy);
        $fg->add_dataset(new FormulaGraphDataset($q, "all", "", ""));
        xassert(!$fg->has_error());
        $ids = [];
        foreach ($fg->graph_json([])["data"] as $points) {
            foreach ($points as $pt) {
                $ids[] = (string) $pt->id;
            }
        }
        return $ids;
    }

    function test_graph_hides_review_ids_from_unprivileged() {
        // A review-indexed scatterplot plots one point per review and labels it
        // with the review's ordinal; a PC-indexed one plots one point per PC
        // member, and those points are people, not reviews. Neither may tell a
        // viewer who cannot see a paper's reviews how many there are or --
        // since the points arrive in pc_members() order -- who wrote them.
        $conf = $this->conf;
        $p19 = $conf->checked_paper_by_id(19);

        // control: the chair's review-indexed plot is labeled, so the ordinals
        // exist and the labeling path is live
        xassert_eqq(count($p19->viewable_reviews_as_display($this->u_chair)), 3);
        xassert_eqq($this->graph_point_ids($this->u_chair, "ovemer", "ovemer", "19"),
                    ["19A", "19B", "19C"]);
        // ...while the same plot is empty for a non-PC author of paper 19
        $u_suvo = $conf->checked_user_by_email("suvo@cs.stanford.edu");
        xassert(!$u_suvo->isPC);
        xassert_ge($p19->conflict_type($u_suvo), CONFLICT_AUTHOR);
        xassert_eqq(count($p19->viewable_reviews_as_display($u_suvo)), 0);
        xassert_eqq($this->graph_point_ids($u_suvo, "ovemer", "ovemer", "19"), []);

        // `conf` is PC-indexed and uncensored, so it plots a point per PC
        // member for any viewer. No point may carry a review ordinal: not for
        // an author...
        $ids = $this->graph_point_ids($u_suvo, "conf", "conf", "19");
        xassert(count($ids) > 0);
        xassert_eqq(self::ordinal_ids($ids), []);

        // ...not for a conflicted PC member who is not an administrator...
        xassert_assign($this->u_chair, "paper,action,user\n19,conflict,marina@poema.ru\n");
        $u_marina = $conf->checked_user_by_email("marina@poema.ru");
        xassert(!$u_marina->privChair);
        $ids = $this->graph_point_ids($u_marina, "conf", "conf", "19");
        xassert(count($ids) > 0);
        xassert_eqq(self::ordinal_ids($ids), []);
        xassert_assign($this->u_chair, "paper,action,user\n19,noconflict,marina@poema.ru\n");

        // ...and not for the chair either, who may see every review: a
        // person-indexed point is not a review, whatever the viewer may read.
        $ids = $this->graph_point_ids($this->u_chair, "conf", "conf", "19");
        xassert(count($ids) > 0);
        xassert_eqq(self::ordinal_ids($ids), []);
    }

    /** @param list<string> $qs
     * @return array{list,?array} */
    private function graph_axis_buckets(Contact $user, $fx, $qs) {
        $fg = new FormulaGraph($user, "bars", $fx, "");
        foreach ($qs as $i => $q) {
            $fg->add_dataset(new FormulaGraphDataset($q, "all", "", (string) ($i + 1)));
        }
        xassert(!$fg->has_error());
        $j = $fg->graph_json([]);
        $buckets = [];
        foreach ($j["data"] as $bar) {
            $buckets[] = [$bar->x, $bar->ids];
        }
        return [$buckets, $j["x"]["scale"]->range ?? null];
    }

    function test_graph_tag_and_query_axes() {
        // `tag` and `query` name an axis rather than a formula: each compiles
        // to a constant whose format has nothing to do with the axis's own
        // units, so the "same units" check must not be applied to it.
        $conf = $this->conf;

        // one bar per tag, holding the papers that carry it
        [$buckets, $range] = $this->graph_axis_buckets($this->u_chair, "tag", ["19 20"]);
        xassert_eqq($range, ["scored", "testtag"]);
        xassert_eqq($buckets, [[0, [19, 20]], [1, [19, 20]]]);

        // one bar per dataset search, holding that search's papers
        [$buckets, $range] = $this->graph_axis_buckets($this->u_chair, "query", ["19", "20"]);
        xassert_eqq($range, ["19", "20"]);
        xassert_eqq($buckets, [[0, [19]], [1, [20]]]);

        // Neither axis escapes the usual gates: a non-PC author sees no tags
        // at all, and only their own paper in a search.
        $u_van = $conf->checked_user_by_email("van@ee.lbl.gov");
        xassert(!$u_van->isPC);
        [$buckets, $range] = $this->graph_axis_buckets($u_van, "tag", ["19 20"]);
        xassert_eqq($range, []);
        xassert_eqq($buckets, []);
        [$buckets, ] = $this->graph_axis_buckets($u_van, "query", ["19", "20"]);
        xassert_eqq($buckets, []);
    }

    /** @param Contact $user
     * @param string $fx
     * @param string $q
     * @return list<array{mixed,list<int|string>}> */
    private function graph_bar_buckets($user, $fx, $q) {
        // an empty Y axis makes this a `sum(1)` bar chart, so each bar's `ids`
        // list names the data that landed in the bucket
        $fg = new FormulaGraph($user, "bars", $fx, "");
        $fg->add_dataset(new FormulaGraphDataset($q, "all", "", ""));
        xassert(!$fg->has_error());
        $buckets = [];
        foreach ($fg->graph_json([])["data"] as $bar) {
            $buckets[] = [$bar->x, $bar->ids];
        }
        return $buckets;
    }

    function test_graph_bar_ids_carry_review_ordinals() {
        // A review-indexed bar chart buckets one datum per review, so the ids
        // that identify a bucket's contents name reviews, not just papers.
        // Papers 19 and 20 have OveMer 4/5/3 and 2/5.
        xassert_eqq($this->graph_bar_buckets($this->u_chair, "ovemer", "19 20"), [
            [2, ["20A"]], [3, ["19C"]], [4, ["19A"]], [5, ["19B", "20B"]]
        ]);

        // A PC-indexed bar chart buckets one datum per PC member. Those data
        // are people, not reviews, so their ids stay paper ids -- this is the
        // rule that keeps review ordinals out of a chart indexed by person.
        $buckets = $this->graph_bar_buckets($this->u_chair, "pcconf", "19");
        xassert_eqq(count($buckets), 1);
        xassert_eqq(self::ordinal_ids(array_map("strval", $buckets[0][1])), []);

        // an author of paper 19 cannot see its scores, so the review-indexed
        // chart has nothing to bucket
        $u_suvo = $this->conf->checked_user_by_email("suvo@cs.stanford.edu");
        xassert_eqq($this->graph_bar_buckets($u_suvo, "ovemer", "19"), []);
    }

    /** @param Contact $user
     * @param string $fx
     * @param string $q
     * @return list<mixed> */
    private function graph_cdf_values($user, $fx, $q) {
        $fg = new FormulaGraph($user, "cdf", $fx, "");
        $fg->add_dataset(new FormulaGraphDataset($q, "all", "", ""));
        xassert(!$fg->has_error());
        $vs = [];
        foreach ($fg->graph_json([])["data"] as $d) {
            foreach ($d->d as $v) {
                $vs[] = $v;
            }
        }
        sort($vs);
        return $vs;
    }

    function test_graph_cdf_respects_review_visibility() {
        // The CDF path indexes by review just as the scatter path does. Whether
        // a review contributes its score must follow can_view_review, not
        // can_view_review_identity: an anonymous review still has a score.
        $conf = $this->conf;
        $old_ausr = $conf->setting("au_seerev");
        $conf->save_refresh_setting("au_seerev", Conf::AUSEEREV_YES);

        // lixia's review of paper 17 was submitted by
        // test_review_meta_hidden_from_pc_author; mjh, a PC member, is a
        // contact author of that paper, so may read the review but not
        // identify its author
        $p17 = $conf->checked_paper_by_id(17, $this->u_mjh);
        $rrow = $p17->fresh_review_by_user($this->u_lixia);
        xassert($this->u_mjh->can_view_review($p17, $rrow));
        xassert(!$this->u_mjh->can_view_review_identity($p17, $rrow));

        // control: the score is there
        xassert_eqq($this->graph_cdf_values($this->u_chair, "ovemer", "17"), [4]);
        // and blind reviewing does not remove it from the author's own CDF
        xassert_eqq($this->graph_cdf_values($this->u_mjh, "ovemer", "17"), [4]);

        // a conflicted PC member, who may not read the review at all,
        // contributes nothing
        xassert_assign($this->u_chair, "paper,action,user\n17,conflict,marina@poema.ru\n");
        $u_marina = $conf->checked_user_by_email("marina@poema.ru");
        xassert_eqq($this->graph_cdf_values($u_marina, "ovemer", "17"), []);
        xassert_assign($this->u_chair, "paper,action,user\n17,noconflict,marina@poema.ru\n");

        $conf->save_refresh_setting("au_seerev", $old_ausr);
    }


    /** @param Contact $user
     * @param string $fx
     * @param string $fy
     * @param string $q
     * @return array<int|string,mixed> */
    private function graph_bar_values($user, $fx, $fy, $q) {
        $fg = new FormulaGraph($user, "bars", $fx, $fy);
        $fg->add_dataset(new FormulaGraphDataset($q, "all", "", ""));
        xassert(!$fg->has_error());
        $bars = [];
        foreach ($fg->graph_json([])["data"] as $bar) {
            $bars[$bar->x] = $bar->y;
        }
        return $bars;
    }

    function test_graph_bar_with_boolean_y_axis() {
        // A bar chart whose Y axis is boolean is rewritten to `sum(...)`, and
        // the rewritten formula must be able to answer `extractor_indexed()`
        // before the data pass prepares it. The X axis must be unindexed, or
        // `_indexed()` short-circuits before it consults Y.
        $p1 = $this->conf->checked_paper_by_id(1);
        $nconf = 0;
        foreach ($this->conf->pc_members() as $p) {
            if ($p1->has_conflict($p)) {
                ++$nconf;
            }
        }
        xassert_gt($nconf, 0);

        xassert_eqq($this->graph_bar_values($this->u_chair, "pid", "conf", "1 19"),
                    [1 => $nconf, 19 => 0]);
    }


    function test_graph_bar_respects_review_visibility() {
        // The bar path evaluates Y through an extractor compiled against the
        // external index type. A stale extractor resolves reviews the strict
        // way, so an identity-blind viewer's review drops out of the aggregate
        // while the same review still reaches the CDF and scatter paths.
        $conf = $this->conf;
        $old_ausr = $conf->setting("au_seerev");
        $conf->save_refresh_setting("au_seerev", Conf::AUSEEREV_YES);

        // mjh is a PC member and a contact author of paper 17, so he may read
        // lixia's review but not identify her (test_review_meta_hidden_from_pc_author
        // submits that review)
        $p17 = $conf->checked_paper_by_id(17, $this->u_mjh);
        $rrow = $p17->fresh_review_by_user($this->u_lixia);
        xassert($this->u_mjh->can_view_review($p17, $rrow));
        xassert(!$this->u_mjh->can_view_review_identity($p17, $rrow));

        // the score reaches the bar aggregate for both viewers...
        xassert_eqq($this->graph_bar_values($this->u_chair, "pid", "avg(ovemer)", "17"), [17 => 4.0]);
        xassert_eqq($this->graph_bar_values($this->u_mjh, "pid", "avg(ovemer)", "17"), [17 => 4.0]);
        // ...and agrees with the other two data paths
        xassert_eqq($this->graph_cdf_values($this->u_mjh, "ovemer", "17"), [4]);
        xassert_eqq($this->graph_point_ids($this->u_mjh, "ovemer", "ovemer", "17"), ["17A"]);

        $conf->save_refresh_setting("au_seerev", $old_ausr);
    }


    function test_graph_pref_dataset_filtering() {
        // A preference-indexed graph splits its data across datasets like any
        // other graph. `_filter_queries` must resolve each preference element
        // to its preferrer's review -- via `Formula::indexer_to_rrow` -- so a
        // per-review dataset search can refine which preferences it contains.
        // Regression: the element arrives as a `[cid, preference]` pair, so a
        // raw `review_by_user()` saw an array, every review lookup returned
        // null, and every dataset matched every preference.
        $conf = $this->conf;
        // Reviews on paper 19 score 4 (lixia), 5 (mjh), 3 (floyd); give each of
        // those reviewers a preference on the same paper. (Created here so the
        // test stands alone, not only after test_setup_reviews.)
        save_review(19, $this->u_lixia, ["ovemer" => 4, "revexp" => 2, "ready" => true]);
        save_review(19, $this->u_mjh, ["ovemer" => 5, "revexp" => 3, "ready" => true]);
        save_review(19, $this->u_floyd, ["ovemer" => 3, "revexp" => 1, "ready" => true]);
        $conf->qe("delete from PaperReviewPreference where paperId=19");
        foreach (["lixia@cs.ucla.edu" => 1, "mjh@isi.edu" => 2, "floyd@ee.lbl.gov" => 3] as $email => $pv) {
            $conf->qe("insert into PaperReviewPreference set paperId=19, contactId=?, preference=?",
                $conf->checked_user_by_email($email)->contactId, $pv);
        }

        // total preference count per dataset group (bar `sx`)
        $totals = function ($q1, $q2) {
            $fg = new FormulaGraph($this->u_chair, "bars", "pref", "sum(1)");
            $fg->add_dataset(new FormulaGraphDataset($q1, null, "", "1"));
            $fg->add_dataset(new FormulaGraphDataset($q2, null, "", "2"));
            xassert(!$fg->has_error());
            $t = [];
            foreach ($fg->graph_json([])["data"] as $bar) {
                $sx = $bar->sx ?? 0;
                $t[$sx] = ($t[$sx] ?? 0) + $bar->y;
            }
            ksort($t);
            return array_values($t);
        };

        // dataset 1 holds every preference on paper 19; dataset 2 restricts to
        // preferences whose preferrer's review scores 5 -- mjh alone. Under the
        // bug both datasets held all three.
        xassert_eqq($totals("19", "19 ovemer:5"), [3, 1]);

        // and two disjoint papers partition their preferences by paper
        $conf->qe("delete from PaperReviewPreference where paperId=20");
        $conf->qe("insert into PaperReviewPreference set paperId=20, contactId=?, preference=9",
            $conf->checked_user_by_email("lixia@cs.ucla.edu")->contactId);
        xassert_eqq($totals("19", "20"), [3, 1]);

        $conf->qe("delete from PaperReviewPreference where paperId in (19, 20)");
    }

    function test_pref_aggregate_hides_individual_preferences() {
        // `can_view_preference` splits two ways: any unconflicted PC member may
        // compute *aggregate* preference statistics, but only an administrator
        // may see who holds which preference. An aggregate that carries the
        // loop's contact id out of the loop -- `argmax(reviewer, pref)` -- must
        // not bridge the two.
        $conf = $this->conf;
        $conf->qe("delete from PaperReviewPreference where paperId=1");
        $prefs = ["lixia@cs.ucla.edu" => 5, "mgbaker@cs.stanford.edu" => -7,
                  "jj@cse.ucsc.edu" => 3, "estrin@usc.edu" => 4];
        foreach ($prefs as $email => $pv) {
            $conf->qe("insert into PaperReviewPreference set paperId=1, contactId=?, preference=?",
                $conf->checked_user_by_email($email)->contactId, $pv);
        }

        $u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $p1 = $conf->checked_paper_by_id(1, $u_marina);
        xassert($u_marina->isPC && !$u_marina->privChair);
        xassert(!$p1->has_conflict($u_marina));
        xassert($u_marina->can_view_preference($p1, true));    // aggregates: yes
        xassert(!$u_marina->can_view_preference($p1, false));  // individuals: no

        // the chair may attribute a preference to a person...
        $lixia = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        xassert_eqq($this->formula_as($this->u_chair, "argmax.pc(reviewer, pref)")->eval($p1, null),
                    $lixia->contactId);
        // ...an ordinary PC member may not, in either direction
        xassert_eqq($this->formula_as($u_marina, "argmax.pc(reviewer, pref)")->eval($p1, null), null);
        xassert_eqq($this->formula_as($u_marina, "argmin.pc(reviewer, pref)")->eval($p1, null), null);
        // ...though the aggregates themselves still work for them
        xassert_eqq($this->formula_as($u_marina, "max(pref)")->eval($p1, null), 5);
        xassert_eqq($this->formula_as($u_marina, "min(pref)")->eval($p1, null), -7);
        xassert_eqq($this->formula_as($u_marina, "count(pref)")->eval($p1, null), 4);
        xassert_eqq($this->formula_as($u_marina, "max.pc(pref)")->eval($p1, null), 5);
        xassert_eqq($this->formula_as($u_marina, "min.pc(pref)")->eval($p1, null), -7);
        xassert_eqq($this->formula_as($u_marina, "count.pc(pref)")->eval($p1, null), 4);

        $conf->qe("delete from PaperReviewPreference where paperId=1");
    }


    function test_topicscore_respects_column_visibility() {
        // Topicscore_PaperColumn shows another user's topic score only to a
        // manager. The formula must agree -- and because a per-person score
        // partitions the PC, it must also mark the loop as identifying, or an
        // ordinary PC member can select a single person out of an aggregate
        // and read that person's review preference.
        $conf = $this->conf;
        $u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $u_lixia = $this->u_lixia;
        xassert($u_marina->isPC && !$u_marina->is_manager());

        $old_has_topics = $conf->setting("has_topics");
        $conf->qe("insert into TopicArea set topicName='Formula Test Topic'");
        $topicid = $conf->dblink->insert_id;
        $conf->qe("insert into PaperTopic set paperId=1, topicId=?", $topicid);
        $conf->qe("insert into TopicInterest set contactId=?, topicId=?, interest=2", $u_lixia->contactId, $topicid);
        $conf->qe("delete from PaperReviewPreference where paperId=1");
        $conf->qe("insert into PaperReviewPreference set paperId=1, contactId=?, preference=5", $u_lixia->contactId);
        $conf->save_refresh_setting("has_topics", 1);

        $p1c = $conf->checked_paper_by_id(1, $this->u_chair);
        $p1m = $conf->checked_paper_by_id(1, $u_marina);
        $lixia_ts = $p1c->topic_interest_score($u_lixia);
        xassert_gt($lixia_ts, 0);
        xassert_eqq($p1c->topic_interest_score($u_marina), 0);

        // a manager sees another PC member's topic score; an ordinary PC
        // member sees only their own
        xassert_eqq($this->formula_as($this->u_chair, "max.pc(topicscore)")->eval($p1c, null), $lixia_ts);
        xassert_eqq($this->formula_as($u_marina, "max.pc(topicscore)")->eval($p1m, null), 0);

        // and the score cannot be used to pick one person out of the
        // preference aggregate
        $sel = "max.pc(topicscore == {$lixia_ts} ? pref : null)";
        xassert_eqq($this->formula_as($this->u_chair, $sel)->eval($p1c, null), 5);
        xassert_eqq($this->formula_as($u_marina, $sel)->eval($p1m, null), null);
        // ...though the aggregate itself still works
        xassert_eqq($this->formula_as($u_marina, "max.pc(pref)")->eval($p1m, null), 5);

        $conf->qe("delete from PaperReviewPreference where paperId=1");
        $conf->qe("delete from TopicInterest where topicId=?", $topicid);
        $conf->qe("delete from PaperTopic where topicId=?", $topicid);
        $conf->qe("delete from TopicArea where topicId=?", $topicid);
        $conf->save_refresh_setting("has_topics", $old_has_topics);
    }


    /** Seed distinct review preferences on paper 1, including one from a PC
     * member who is conflicted with the paper.
     * @return array<string,int> */
    private function seed_paper1_preferences() {
        $prefs = ["lixia@cs.ucla.edu" => 5, "mgbaker@cs.stanford.edu" => -7,
                  "jj@cse.ucsc.edu" => 3, "estrin@usc.edu" => 4];
        $this->conf->qe("delete from PaperReviewPreference where paperId=1");
        foreach ($prefs as $email => $pv) {
            $this->conf->qe("insert into PaperReviewPreference set paperId=1, contactId=?, preference=?",
                $this->conf->checked_user_by_email($email)->contactId, $pv);
        }
        return $prefs;
    }

    function test_pref_aggregate_requires_unconflicted_pc() {
        // Aggregate preference access is `can_view_preference($prow, true)`,
        // which requires an *unconflicted* PC member. A conflicted PC member
        // must see only their own preference, however the aggregate is spelled.
        $conf = $this->conf;
        $this->seed_paper1_preferences();

        $u_estrin = $conf->checked_user_by_email("estrin@usc.edu");
        $p1e = $conf->checked_paper_by_id(1, $u_estrin);
        xassert($u_estrin->isPC);
        xassert($p1e->has_conflict($u_estrin));
        xassert(!$u_estrin->can_view_preference($p1e, true));
        xassert(!$u_estrin->can_view_preference($p1e, false));

        // control: an unconflicted PC member sees the whole distribution
        $u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $p1m = $conf->checked_paper_by_id(1, $u_marina);
        xassert($u_marina->can_view_preference($p1m, true));
        xassert_eqq($this->formula_as($u_marina, "max.pref(pref)")->eval($p1m, null), 5);
        xassert_eqq($this->formula_as($u_marina, "count.pref(1)")->eval($p1m, null), 4);

        // the conflicted PC member sees only their own preference of 4
        xassert_eqq($this->formula_as($u_estrin, "max.pref(pref)")->eval($p1e, null), 4);
        xassert_eqq($this->formula_as($u_estrin, "min.pref(pref)")->eval($p1e, null), 4);
        xassert_eqq($this->formula_as($u_estrin, "count.pref(1)")->eval($p1e, null), 1);
        xassert_eqq($this->formula_as($u_estrin, "max.pc(pref)")->eval($p1e, null), 4);

        $conf->qe("delete from PaperReviewPreference where paperId=1");
    }

    function test_pref_aggregate_hides_membership() {
        // Aggregate rights cover the preference multiset and its size. They do
        // not cover *whose* preference it is: an ordinary PC member must not be
        // able to learn which PC members expressed a preference, even without
        // reading any preference value.
        $conf = $this->conf;
        $this->seed_paper1_preferences();
        $old_pcconfvis = $conf->setting("sub_pcconfvis");
        $conf->save_refresh_setting("sub_pcconfvis", 2);   // conflicts visible to all PC

        $u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $u_lixia = $this->u_lixia;                                     // has a preference (5)
        $u_vera = $conf->checked_user_by_email("vera@bombay.com");     // has none
        $p1m = $conf->checked_paper_by_id(1, $u_marina);
        $p1c = $conf->checked_paper_by_id(1, $this->u_chair);
        xassert($u_marina->can_view_preference($p1m, true));
        xassert(!$u_marina->can_view_preference($p1m, false));
        xassert($u_marina->can_view_conflicts($p1m));

        // control: the aggregate itself still works for marina...
        xassert_eqq($this->formula_as($u_marina, "max.pref(pref)")->eval($p1m, null), 5);
        xassert_eqq($this->formula_as($u_marina, "count.pref(1)")->eval($p1m, null), 4);
        // ...and an administrator may attribute preferences to people
        xassert_eqq($this->formula_as($this->u_chair, "argmax.pref(reviewer, pref)")->eval($p1c, null),
                    $u_lixia->contactId);
        xassert_eqq($this->formula_as($this->u_chair, "count.pref(reviewer == {$u_lixia->contactId})")->eval($p1c, null), 1);
        xassert_eqq($this->formula_as($this->u_chair, "max.pref(conf ? pref : null)")->eval($p1c, null), 4);

        // marina may not, in any spelling: not by naming the extreme...
        xassert_eqq($this->formula_as($u_marina, "argmax.pref(reviewer, pref)")->eval($p1m, null), null);
        xassert_eqq($this->formula_as($u_marina, "argmin.pref(reviewer, pref)")->eval($p1m, null), null);
        xassert_eqq($this->formula_as($u_marina, "argmax.pref(reviewer, 1)")->eval($p1m, null), null);
        // ...not by testing membership, which needs no preference value at all...
        xassert_eqq($this->formula_as($u_marina, "count.pref(reviewer == {$u_lixia->contactId})")->eval($p1m, null), 0);
        xassert_eqq($this->formula_as($u_marina, "count.pref(reviewer == {$u_vera->contactId})")->eval($p1m, null), 0);
        // ...and not by selecting a subpopulation with another per-person value
        xassert_eqq($this->formula_as($u_marina, "max.pref(conf ? pref : null)")->eval($p1m, null), null);
        xassert_eqq($this->formula_as($u_marina, "count.pref(conf)")->eval($p1m, null), 0);

        $conf->save_refresh_setting("sub_pcconfvis", $old_pcconfvis);
        $conf->qe("delete from PaperReviewPreference where paperId=1");
    }

    function test_pref_aggregate_pc_relaxes_when_pref_controls_null() {
        // `AGG.pc(body)` may iterate the preference collection instead of the
        // PC when a null preference nulls the whole body: the two populations
        // then agree, so the rewrite cannot change an answer -- and it gives an
        // ordinary PC member the aggregate that is theirs by right.
        $conf = $this->conf;
        $this->seed_paper1_preferences();   // lixia 5, mgbaker -7, jj 3, estrin 4
        $u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $p1c = $conf->checked_paper_by_id(1, $this->u_chair);
        $p1m = $conf->checked_paper_by_id(1, $u_marina);
        xassert($u_marina->can_view_preference($p1m, true));
        xassert(!$u_marina->can_view_preference($p1m, false));

        // The relaxation fires only where the populations agree, so `.pc` and
        // `.pref` must give the same answer for every such body -- to a viewer
        // who sees every preference as much as to one who sees none.
        $pairs = [["max.pc(pref * 2)", "max.pref(pref * 2)"],
                  ["min.pc(pref + 1)", "min.pref(pref + 1)"],
                  ["count.pc(pref == 5)", "count.pref(pref == 5)"],
                  ["sum.pc(pref > 0)", "sum.pref(pref > 0)"],
                  ["avg.pc(round(pref))", "avg.pref(round(pref))"],
                  ["max.pc(greatest(pref, 0))", "max.pref(greatest(pref, 0))"],
                  ["max.pc(sqrt(pref))", "max.pref(sqrt(pref))"],
                  ["argmax.pc(1, pref)", "argmax.pref(1, pref)"]];
        foreach ($pairs as $pr) {
            xassert_eqq($this->formula_as($this->u_chair, $pr[0])->eval($p1c, null),
                        $this->formula_as($this->u_chair, $pr[1])->eval($p1c, null));
            xassert_eqq($this->formula_as($u_marina, $pr[0])->eval($p1m, null),
                        $this->formula_as($u_marina, $pr[1])->eval($p1m, null));
        }

        // ...and those answers are the real aggregate, not a vacuous null:
        // `max.pc(pref * 2)` used to be null for an ordinary PC member, since
        // only a literal `pref` reached the preference collection.
        xassert_eqq($this->formula_as($u_marina, "max.pc(pref * 2)")->eval($p1m, null), 10);
        xassert_eqq($this->formula_as($u_marina, "min.pc(pref + 1)")->eval($p1m, null), -6);
        xassert_eqq($this->formula_as($u_marina, "count.pc(pref == 5)")->eval($p1m, null), 1);
        xassert_eqq($this->formula_as($u_marina, "sum.pc(pref > 0)")->eval($p1m, null), 3);
        xassert_eqq($this->formula_as($u_marina, "avg.pc(round(pref))")->eval($p1m, null), 1.25);

        // A body that names a second collection is not a pure preference body,
        // so it keeps the PC population -- and with it the *individual*
        // preference gate, which is what stops a selector from bridging.
        xassert_eqq($this->formula_as($this->u_chair, "count.pc(pref == 5 || conf)")->eval($p1c, null), 5);
        xassert_eqq($this->formula_as($u_marina, "count.pc(pref == 5 || conf)")->eval($p1m, null), 0);

        $conf->qe("delete from PaperReviewPreference where paperId=1");
    }

    function test_pref_aggregate_pc_keeps_pc_when_pref_absorbed() {
        // When a null preference does *not* null the body -- the body supplies
        // a value for the members who expressed none -- `.pc` must iterate the
        // whole PC. Rewriting to `.pref` would silently drop those members.
        $conf = $this->conf;
        $this->seed_paper1_preferences();
        $u_marina = $conf->checked_user_by_email("marina@poema.ru");
        $p1c = $conf->checked_paper_by_id(1, $this->u_chair);
        $p1m = $conf->checked_paper_by_id(1, $u_marina);
        $npc = count($conf->viewable_pc_members($this->u_chair));
        xassert_gt($npc, 4);
        xassert_eqq(count($conf->viewable_pc_members($u_marina)), $npc);

        // one datum per PC member, not one per preference
        foreach (["count.pc(coalesce(pref, 2))", "count.pc(pref ? 1 : 0)",
                  "count.pc(-pref)", "count.pc(isnull(pref) ? 1 : 0)"] as $expr) {
            xassert_eqq($this->formula_as($this->u_chair, $expr)->eval($p1c, null), $npc);
            xassert_eqq($this->formula_as($u_marina, $expr)->eval($p1m, null), $npc);
        }
        // ...while the `.pref` spelling still names the smaller population
        xassert_eqq($this->formula_as($this->u_chair, "count.pref(coalesce(pref, 2))")->eval($p1c, null), 4);
        xassert_eqq($this->formula_as($u_marina, "count.pref(coalesce(pref, 2))")->eval($p1m, null), 4);

        // The PC population applies the *individual* preference gate, so these
        // bodies tell an ordinary PC member only that they may see nothing.
        xassert_eqq($this->formula_as($this->u_chair, "max.pc(coalesce(pref, 2))")->eval($p1c, null), 5);
        xassert_eqq($this->formula_as($u_marina, "max.pc(coalesce(pref, 2))")->eval($p1m, null), 2);
        xassert_eqq($this->formula_as($this->u_chair, "count.pc(!pref)")->eval($p1c, null), $npc - 4);
        xassert_eqq($this->formula_as($u_marina, "count.pc(!pref)")->eval($p1m, null), $npc);
        xassert_eqq($this->formula_as($this->u_chair, "sum.pc(isnull(pref))")->eval($p1c, null), 4);
        xassert_eqq($this->formula_as($u_marina, "sum.pc(isnull(pref))")->eval($p1m, null), 0);

        // `all` is never rewritten, whatever its body: a valueless element
        // counts against it, so the two populations give different answers.
        xassert_eqq($this->formula_as($this->u_chair, "all.pc(pref)")->eval($p1c, null), false);
        xassert_eqq($this->formula_as($this->u_chair, "all.pref(pref)")->eval($p1c, null), true);
        xassert_eqq($this->formula_as($u_marina, "all.pref(pref)")->eval($p1m, null), true);

        $conf->qe("delete from PaperReviewPreference where paperId=1");
    }

    function test_aggregate_pc_collection_not_discarded() {
        // `.pc` means "over the PC" even when the body mentions no one in
        // particular; the `.pc` -> `.pref` convenience must not fire on an
        // expression that has no preference in it.
        $conf = $this->conf;
        $npc = count($conf->viewable_pc_members($this->u_chair));
        xassert_gt($npc, 1);
        $p1c = $conf->checked_paper_by_id(1, $this->u_chair);
        xassert_eqq($this->formula_as($this->u_chair, "count.pc(1)")->eval($p1c, null), $npc);
        xassert_eqq($this->formula_as($this->u_chair, "count.pc(true)")->eval($p1c, null), $npc);
        xassert_eqq($this->formula_as($this->u_chair, "count.pc(pid)")->eval($p1c, null), $npc);

        // ...while an aggregate that *is* about preferences still routes to
        // the preference collection
        $this->seed_paper1_preferences();
        xassert_eqq($this->formula_as($this->u_chair, "count.pc(pref)")->eval($p1c, null), 4);
        $conf->qe("delete from PaperReviewPreference where paperId=1");
    }

}
