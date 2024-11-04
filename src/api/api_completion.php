<?php
// api_completion.php -- HotCRP completion API calls
// Copyright (c) 2008-2024 Eddie Kohler; see LICENSE.

class Completion_API {
    /** @param list &$comp
     * @param string $prefix
     * @param array $map
     * @param int $flags */
    static private function simple_search_completion(&$comp, $prefix, $map, $flags = 0) {
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
            $comp[] = $prefix . ($match ? : "\"{$str}\"");
        }
    }

    /** @param list &$comp */
    static private function has_search_completion(Contact $user, &$comp) {
        $conf = $user->conf;
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
        foreach ($conf->response_rounds() as $rrd) {
            if (!in_array("has:response", $comp, true)) {
                $comp[] = "has:response";
            }
            if (!$rrd->unnamed) {
                $sep = strpos($rrd->name, "-") === false ? "" : "-";
                $comp[] = "has:{$rrd->name}{$sep}response";
            }
        }
        if ($user->can_view_some_draft_response()) {
            foreach ($conf->response_rounds() as $rrd) {
                if (!in_array("has:draftresponse", $comp, true)) {
                    $comp[] = "has:draftresponse";
                }
                if (!$rrd->unnamed) {
                    $sep = strpos($rrd->name, "-") === false ? "" : "-";
                    $comp[] = "has:draft{$sep}{$rrd->name}{$sep}response";
                }
            }
        }
        if ($user->can_view_tags()) {
            array_push($comp, "has:color", "has:style");
            if ($conf->tags()->has(TagInfo::TF_BADGE)) {
                $comp[] = "has:badge";
            }
        }
    }

    /** @return list<string> */
    static function search_completions(Contact $user, $category = "") {
        $conf = $user->conf;
        $comp = [];
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);

        // paper fields
        foreach ($conf->options()->form_fields() as $opt) {
            if ($user->can_view_some_option($opt)
                && $opt->search_keyword() !== false) {
                foreach ($opt->search_examples($user, SearchExample::COMPLETION) as $sex) {
                    $comp[] = $sex->q;
                }
            }
        }

        self::has_search_completion($user, $comp);

        if (!$category || $category === "ss") {
            foreach ($user->viewable_named_searches(false) as $sj) {
                $twiddle = strpos($sj->name, "~");
                $comp[] = "ss:" . ($twiddle > 0 ? substr($sj->name, $twiddle) : $sj->name);
            }
        }

        if ((!$category || $category === "dec")
            && $user->can_view_some_decision()) {
            $comp[] = ["pri" => -1, "nosort" => true, "i" => ["dec:any", "dec:none", "dec:yes", "dec:no"]];
            foreach ($conf->decision_set() as $dec) {
                if ($dec->id !== 0) {
                    $comp[] = "dec:" . SearchWord::quote($dec->name);
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
            $comp[] = ["pri" => -1, "nosort" => true, "i" => ["style:any", "style:none"]];
            $tagmap = $conf->tags();
            foreach ($tagmap->listed_style_names(TagStyle::BG | TagStyle::TEXT) as $name) {
                $comp[] = "style:{$name}";
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
            $xtp = new XtParams($conf, $user);
            foreach ($conf->paper_column_factories() as $fxj) {
                if ($xtp->allowed($fxj)
                    && Conf::xt_enabled($fxj)
                    && isset($fxj->completion_function)) {
                    Conf::xt_resolve_require($fxj);
                    foreach (call_user_func($fxj->completion_function, $user, $fxj) as $c) {
                        $cats[$c] = true;
                    }
                }
            }
            foreach (array_keys($cats) as $cat) {
                $comp[] = "show:{$cat}";
                $comp[] = "hide:{$cat}";
            }
            $comp[] = "show:facets";
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

    const MENTION_PARSE = 0;
    const MENTION_COMPLETION = 1;

    /** @param Contact $user
     * @param ?PaperInfo $prow
     * @param int $cvis
     * @param 0|1 $reason
     * @return list<list<Contact|Author>>
     * @deprecated */
    static function mention_lists($user, $prow, $cvis, $reason) {
        $mlister = new MentionLister($user, $prow, $cvis, $reason);
        return $mlister->list_values();
    }

    /** @param Qrequest $qreq
     * @param ?PaperInfo $prow
     * @deprecated */
    static function mentioncompletion_api(Contact $user, $qreq, $prow) {
        return MentionLister::mentioncompletion_api($user, $qreq, $prow);
    }
}
