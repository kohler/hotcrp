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
        foreach ($conf->response_round_list() as $rrd) {
            if (!in_array("has:response", $comp, true)) {
                $comp[] = "has:response";
            }
            if (!$rrd->unnamed) {
                $sep = strpos($rrd->name, "-") === false ? "" : "-";
                $comp[] = "has:{$rrd->name}{$sep}response";
            }
        }
        if ($user->can_view_some_draft_response()) {
            foreach ($conf->response_round_list() as $rrd) {
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

    /** @param XtParams $xtp
     * @param string $name
     * @param object|list<object> $list
     * @param int $venue
     * @return ?object */
    static function resolve_published($xtp, $name, $list, $venue) {
        if (str_starts_with($name, "?")
            || str_starts_with($name, "__")) {
            return null;
        }
        $j = is_object($list) ? $list : $xtp->search_list($list);
        if (!$j
            || ($j->deprecated ?? false)
            || !Conf::xt_enabled($j)) {
            return null;
        }
        $published = $j->published ?? null;
        if ($published === null && ($j->alias ?? false)) {
            $published = "none";
        }
        if ($published === "none"
            || ($venue === FieldRender::CFSUGGEST
                && ($published === false || $published === "api"))) {
            return null;
        }
        return $j;
    }

    static function paper_column_examples(Contact $user, $venue) {
        $conf = $user->conf;
        $exs = [];
        $xtp = (new XtParams($conf, $user))->set_warn_deprecated(false);
        $pl = new PaperList("empty", new PaperSearch($user, ""));

        foreach ($conf->paper_column_map() as $name => $list) {
            if (!($j = self::resolve_published($xtp, $name, $list, $venue))) {
                continue;
            }
            $c = PaperColumn::make($conf, $j);
            if (!$c || !$c->prepare($pl, $venue)) {
                continue;
            }
            $exs[] = $ex = new SearchExample($name);
            if (isset($j->description)) {
                $ex->set_description($j->description);
            } else if (isset($c->title)) {
                $ex->set_description("<0>" . $c->title);
            }
            $vos = $c->view_option_schema();
            if (!empty($vos)) {
                $ex->add_arg(new FmtArg("view_options", $vos));
            }
        }

        foreach ($conf->paper_column_factories() as $fcj) {
            if (!$xtp->allowed($fcj)
                || !Conf::xt_enabled($fcj)) {
                continue;
            }
            $fn = $fcj->example_function ?? $fcj->completion_function ?? null;
            if (!$fn) {
                continue;
            }
            Conf::xt_resolve_require($fcj);
            foreach (call_user_func($fn, $user, $fcj, $venue) as $x) {
                if (is_string($x)) {
                    $x = new SearchExample($x);
                }
                $exs[] = $x;
            }
        }

        usort($exs, function ($a, $b) {
            return strcasecmp($a->text(), $b->text());
        });

        return $exs;
    }

    /** @return list<string> */
    static function search_completions(Contact $user, $category = "") {
        $conf = $user->conf;
        $comp = [];
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);

        // paper fields
        if (!$category || $category === "sf") {
            foreach ($conf->options()->form_fields() as $opt) {
                if ($user->can_view_some_option($opt)
                    && $opt->search_keyword() !== false) {
                    foreach ($opt->search_examples($user, FieldRender::CFSUGGEST) as $sex) {
                        $comp[] = $sex->text();
                    }
                }
            }
        }

        if (!$category || $category === "has") {
            self::has_search_completion($user, $comp);
        }

        if (!$category || $category === "ss") {
            foreach ($user->viewable_named_searches(false) as $sj) {
                $twiddle = strpos($sj->name, "~");
                $comp[] = "ss:" . ($twiddle > 0 ? substr($sj->name, $twiddle) : $sj->name);
            }
        }

        if ((!$category || $category === "sclass")
            && $conf->has_named_submission_rounds()) {
            foreach ($conf->submission_round_list() as $sr) {
                $comp[] = "sclass:" . ($sr->unnamed ? "unnamed" : $sr->tag);
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

        $cat_show = !$category || $category === "show";
        $cat_hide = !$category || $category === "hide";
        if ($cat_show || $cat_hide) {
            foreach (self::paper_column_examples($user, FieldRender::CFSUGGEST) as $ex) {
                if ($cat_show) {
                    $comp[] = "show:{$ex->text()}";
                }
                if ($cat_hide) {
                    $comp[] = "hide:{$ex->text()}";
                }
            }
            if ($cat_show) {
                $comp[] = "show:facets";
                $comp[] = "show:statistics";
                $comp[] = "show:rownumbers";
            }
        }

        $user->set_overrides($old_overrides);
        return $comp;
    }

    /** @param Qrequest $qreq */
    static function searchcompletion_api(Contact $user, $qreq) {
        return [
            "ok" => true,
            "searchcompletion" => self::search_completions($user, $qreq->category ?? "")
        ];
    }

    /** @param Qrequest $qreq */
    static function displayfields_api(Contact $user, $qreq) {
        $pcx = self::paper_column_examples($user, FieldRender::CFLIST);
        $fs = [];
        foreach ($pcx as $ex) {
            $fs[] = $f = (object) ["name" => $ex->text()];
            if (($t = $ex->description()) !== "") {
                $f->description = $t;
            }
            if (($fa = Fmt::find_arg($ex->args(), "view_options"))) {
                $vox = [];
                foreach ($fa->value as $vo) {
                    if (($vot = ViewOptionType::make($vo))
                        && !isset($vot->alias))
                        $vox[] = $vot->unparse_export();
                }
                if (!empty($vox)) {
                    $f->parameters = $vox;
                }
            }
        }
        return [
            "ok" => true,
            "fields" => $fs
        ];
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
