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

    function __construct(Conf $conf) {
        $this->conf = $conf;
        $this->u_chair = $conf->checked_user_by_email("chair@_.com");
        $this->u_lixia = $conf->checked_user_by_email("lixia@cs.ucla.edu");
        $this->u_mjh = $conf->checked_user_by_email("mjh@isi.edu");
        $this->u_floyd = $conf->checked_user_by_email("floyd@ee.lbl.gov");
        $conf->save_refresh_setting("rev_open", 1);
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

    /** @param array<string,string> $exprs
     * @return array{bool,array<string,Formula>} */
    private function formula_set($exprs) {
        $names = array_keys($exprs);
        $formulas = [];
        foreach ($exprs as $name => $expr) {
            $config = Formula::make_config()->set_deferred(true);
            foreach ($names as $other) {
                if ($other !== $name) {
                    $config->add_param($other);
                }
            }
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

    function test_alpha_score_inequality() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);
        // With alpha symbols (A=5 best, E=1 worst), this field has flip_relation
        // so OveMer >= B means score >= 4, i.e. lixia(4) and mjh(5)
        $f = $this->formula("count(OveMer >= B)");
        xassert($f->ok());
        xassert_eqq($f->eval($p19, null), 2);

        // OveMer < C means score < 3, i.e. nobody (scores are 3,4,5)
        $f = $this->formula("count(OveMer < C)");
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
        $f->set_param("x", 10);
        $f->prepare();
        xassert_eqq($f->eval($p19, null), 11);

        // Change param value
        $f->set_param("x", 20);
        xassert_eqq($f->eval($p19, null), 21);
    }

    function test_deferred_formula() {
        $p19 = $this->conf->checked_paper_by_id(19, $this->u_chair);

        // Create a deferred formula
        $config = Formula::make_config()
            ->set_deferred(true)
            ->add_param("x");
        $f = Formula::make($this->u_chair, "x + 1", $config);
        // Before finalize, format is unknown
        xassert_eqq($f->format(), Fexpr::FUNKNOWN);

        // Finalize with numeric format
        $f->set_param_format("x", Fexpr::FNUMERIC);
        $f->finalize();
        xassert($f->ok());
        xassert_eqq($f->format(), Fexpr::FNUMERIC);

        // Set param and eval
        $f->set_param("x", 5);
        $f->prepare();
        xassert_eqq($f->eval($p19, null), 6);
    }

    function test_deferred_unused_param() {
        // Formula that doesn't use its param
        $config = Formula::make_config()
            ->set_deferred(true)
            ->add_param("x")
            ->add_param("y");
        $f = Formula::make($this->u_chair, "x + 1", $config);
        xassert_eqq($f->param_names(), ["x"]);
    }

    function test_deferred_let_shadowing() {
        // let binding shadows param — param should NOT be marked used
        $config = Formula::make_config()
            ->set_deferred(true)
            ->add_param("x");
        $f = Formula::make($this->u_chair, "let x = 5 in x + 1", $config);
        // x is shadowed by let, so the param is NOT used
        xassert_eqq($f->param_names(), []);
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

        $fmap["z"]->set_param("x", $x);
        $z = $fmap["z"]->eval($p19, null);
        xassert_eqq($z, 16.0);

        $fmap["y"]->set_param("z", $z);
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

        $fmap["y"]->set_param("x", $x);
        xassert_eqq($fmap["y"]->eval($p20, null), 7.0);
    }
}
