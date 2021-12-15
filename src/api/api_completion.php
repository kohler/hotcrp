<?php
// api_completion.php -- HotCRP completion API calls
// Copyright (c) 2008-2021 Eddie Kohler; see LICENSE.

class Completion_API {
    /** @param list &$comp
     * @param string $prefix
     * @param array $map
     * @param int $flags */
    private static function simple_search_completion(&$comp, $prefix, $map, $flags = 0) {
        foreach ($map as $id => $str) {
            $match = null;
            foreach (preg_split('/[^a-z0-9_]+/', strtolower($str)) as $word)
                if ($word !== ""
                    && ($m = Text::simple_search($word, $map, $flags))
                    && isset($m[$id]) && count($m) == 1
                    && !Text::is_boring_word($word)) {
                    $match = $word;
                    break;
                }
            $comp[] = $prefix . ($match ? : "\"$str\"");
        }
    }

    /** @param list &$comp */
    static function has_search_completion(Contact $user, &$comp) {
        $conf = $user->conf;
        if ((int) $user->conf->opt("noPapers") !== 1) {
            $comp[] = "has:submission";
        }
        if ((int) $user->conf->opt("noAbstract") !== 1) {
            $comp[] = "has:abstract";
        }
        if ($user->isPC
            && $conf->has_any_manager()) {
            $comp[] = "has:admin";
        }
        if ($conf->has_any_lead_or_shepherd()
            && $user->can_view_lead(null)) {
            $comp[] = "has:lead";
        }
        if ($user->can_view_some_decision()) {
            $comp[] = "has:decision";
            if ($conf->setting("final_open")) {
                $comp[] = "has:final";
            }
        }
        if ($conf->has_any_lead_or_shepherd()
            && $user->can_view_shepherd(null)) {
            $comp[] = "has:shepherd";
        }
        if ($user->is_reviewer()) {
            array_push($comp, "has:review", "has:creview", "has:ireview", "has:preview", "has:primary", "has:secondary", "has:external", "has:comment", "has:aucomment");
        } else if ($user->can_view_some_review()) {
            array_push($comp, "has:review", "has:comment");
        }
        if ($user->isPC
            && $conf->ext_subreviews > 1
            && $user->is_requester()) {
            array_push($comp, "has:pending-my-approval");
        }
        if ($user->is_manager()) {
            array_push($comp, "has:proposal");
        }
        foreach ($conf->resp_rounds() as $rrd) {
            if (!in_array("has:response", $comp, true)) {
                $comp[] = "has:response";
            }
            if ($rrd->number) {
                $comp[] = "has:{$rrd->name}response";
            }
        }
        if ($user->can_view_some_draft_response()) {
            foreach ($conf->resp_rounds() as $rrd) {
                if (!in_array("has:draftresponse", $comp, true)) {
                    $comp[] = "has:draftresponse";
                }
                if ($rrd->number) {
                    $comp[] = "has:draft{$rrd->name}response";
                }
            }
        }
        if ($user->can_view_tags()) {
            array_push($comp, "has:color", "has:style");
            if ($conf->tags()->has_badges) {
                $comp[] = "has:badge";
            }
        }
    }

    /** @return list<string> */
    static function search_completions(Contact $user, $category = "") {
        $conf = $user->conf;
        $comp = [];
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);

        self::has_search_completion($user, $comp);

        foreach ($user->user_option_list() as $o) {
            if ($user->can_view_some_option($o)
                && $o->search_keyword() !== false) {
                foreach ($o->search_examples($user, PaperOption::EXAMPLE_COMPLETION) as $sex) {
                    $comp[] = $sex->q;
                }
            }
        }

        if ((!$category || $category === "ss")
            && $user->isPC) {
            foreach ($conf->named_searches() as $k => $v) {
                $comp[] = "ss:" . $k;
            }
        }

        if ((!$category || $category === "dec")
            && $user->can_view_some_decision()) {
            $comp[] = ["pri" => -1, "nosort" => true, "i" => ["dec:any", "dec:none", "dec:yes", "dec:no"]];
            foreach ($conf->decision_map() as $d => $dname) {
                if ($d !== 0) {
                    $comp[] = "dec:" . SearchWord::quote($dname);
                }
            }
        }

        if ((!$category || $category === "round")
            && $user->is_reviewer()
            && $conf->has_rounds()) {
            $comp[] = ["pri" => -1, "nosort" => true, "i" => ["round:any", "round:none"]];
            $rlist = [];
            foreach ($conf->round_list() as $rnum => $round) {
                if ($rnum && $round !== ";") {
                    $rlist[$rnum] = $round;
                }
            }
            self::simple_search_completion($comp, "round:", $rlist);
        }

        if ((!$category || $category === "topic")
            && $conf->has_topics()) {
            $topics = $conf->topic_set();
            foreach ($topics->group_list() as $tg) {
                if ($tg->size() >= 3) {
                    $comp[] = "topic:" . SearchWord::quote($tg->name);
                }
                foreach ($tg->members() as $tid) {
                    if ($tid !== $tg->tid || $tg->size() < 3) {
                        $comp[] = "topic:" . SearchWord::quote($topics[$tid]);
                    }
                }
            }
        }

        if ((!$category || $category === "style")
            && $user->can_view_tags()) {
            $comp[] = ["pri" => -1, "nosort" => true, "i" => ["style:any", "style:none", "color:any", "color:none"]];
            foreach ($conf->tags()->canonical_colors() as $t) {
                $comp[] = "style:$t";
                if ($conf->tags()->is_known_style($t, TagMap::STYLE_BG)) {
                    $comp[] = "color:$t";
                }
            }
        }

        if (!$category || $category === "show" || $category === "hide") {
            $cats = [];
            $pl = new PaperList("empty", new PaperSearch($user, ""));
            foreach ($conf->paper_column_map() as $cname => $cjj) {
                if (!($cjj[0]->deprecated ?? false)
                    && ($cj = $conf->basic_paper_column($cname, $user))
                    && isset($cj->completion)
                    && $cj->completion
                    && !str_starts_with($cj->name, "?")
                    && ($c = PaperColumn::make($conf, $cj))
                    && ($cat = $c->completion_name())
                    && $c->prepare($pl, PaperColumn::PREP_CHECK)) {
                    $cats[$cat] = true;
                }
            }
            foreach ($conf->paper_column_factories() as $fxj) {
                if ($conf->xt_allowed($fxj, $user)
                    && Conf::xt_enabled($fxj)
                    && isset($fxj->completion_function)) {
                    Conf::xt_resolve_require($fxj);
                    foreach (call_user_func($fxj->completion_function, $user, $fxj) as $c) {
                        $cats[$c] = true;
                    }
                }
            }
            foreach (array_keys($cats) as $cat) {
                $comp[] = "show:$cat";
                $comp[] = "hide:$cat";
            }
            $comp[] = "show:kanban";
            $comp[] = "show:statistics";
            $comp[] = "show:rownumbers";
        }

        $user->set_overrides($old_overrides);
        return $comp;
    }

    /** @param Qrequest $qreq */
    static function searchcompletion_api(Contact $user, $qreq) {
        return ["ok" => true, "searchcompletion" => self::search_completions($user, "")];
    }

    /** @param Qrequest $qreq
     * @param ?PaperInfo $prow */
    static function mentioncompletion_api(Contact $user, $qreq, $prow) {
        $compult = [];
        if ($user->can_view_pc()) {
            $pcmap = $user->conf->pc_completion_map();
            foreach ($user->conf->pc_users() as $pc) {
                if (!$pc->is_disabled()
                    && (!$prow || $pc->can_view_new_comment_ignore_conflict($prow))) {
                    $primary = true;
                    foreach ($pc->completion_items() as $k => $level) {
                        if (($pcmap[$k] ?? null) === $pc) {
                            $skey = $primary ? "s" : "sm1";
                            $compult[$k] = [$skey => $k, "d" => $pc->name()];
                            $primary = false;
                        }
                    }
                }
            }
        }
        ksort($compult);
        return ["ok" => true, "mentioncompletion" => array_values($compult)];
    }
}
