<?php
// api_completion.php -- HotCRP completion API calls
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

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

        if ((!$category || $category === "ss")
            && $user->isPC) {
            foreach ($conf->named_searches() as $sj) {
                $comp[] = "ss:" . $sj->name;
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
            $comp[] = ["pri" => -1, "nosort" => true, "i" => ["style:any", "style:none", "color:any", "color:none"]];
            $tagmap = $conf->tags();
            foreach ($tagmap->canonical_listed_styles(TagStyle::BG | TagStyle::TEXT) as $ks) {
                $comp[] = "style:{$ks->style}";
                if (($ks->sclass & TagStyle::BG) !== 0) {
                    $comp[] = "color:{$ks->style}";
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
     * @return list<list<Contact|Author>> */
    static function mention_lists($user, $prow, $cvis, $reason) {
        $alist = $rlist = $pclist = [];

        if ($prow
            && $user->can_view_authors($prow)
            && $cvis >= CommentInfo::CTVIS_AUTHOR) {
            $alist = $prow->contact_list();
        }

        if ($prow && $user->can_view_review_assignment($prow, null)) {
            $prow->ensure_reviewer_names();
            $xview = $user->conf->time_some_external_reviewer_view_comment();
            foreach ($prow->reviews_as_display() as $rrow) {
                if ($rrow->reviewType < REVIEW_PC && !$xview) {
                    continue;
                }
                $viewid = $user->can_view_review_identity($prow, $rrow);
                if ($rrow->reviewOrdinal
                    && $user->can_view_review($prow, $rrow)) {
                    $au = new Author;
                    $au->lastName = "Reviewer " . unparse_latin_ordinal($rrow->reviewOrdinal);
                    $au->contactId = $rrow->contactId;
                    $au->status = $viewid ? Author::STATUS_REVIEWER : Author::STATUS_ANONYMOUS_REVIEWER;
                    $rlist[] = $au;
                }
                if ($viewid
                    && $rrow->contactId !== $user->contactId
                    && ($cvis >= CommentInfo::CTVIS_REVIEWER || $rrow->reviewType >= REVIEW_PC)
                    && !$rrow->reviewer()->is_dormant()) {
                    $rlist[] = $rrow->reviewer();
                }
            }
            // XXX todo: list previous commentees in privileged position?
            // XXX todo: list lead and shepherd?
        }

        if ($user->can_view_pc()) {
            if (!$prow
                || $reason === self::MENTION_PARSE
                || !$user->conf->check_track_view_sensitivity()) {
                $pclist = $user->conf->enabled_pc_members();
            } else {
                foreach ($user->conf->pc_members() as $p) {
                    if (!$p->is_dormant()
                        && $p->can_pc_view_paper_track($prow))
                        $pclist[] = $p;
                }
            }
        }

        return [$alist, $rlist, $pclist];
    }

    /** @param Qrequest $qreq
     * @param ?PaperInfo $prow */
    static function mentioncompletion_api(Contact $user, $qreq, $prow) {
        $comp = [];
        $mlists = self::mention_lists($user, $prow, CommentInfo::CTVIS_AUTHOR, self::MENTION_COMPLETION);
        $aunames = [];
        foreach ($mlists as $i => $mlist) {
            $skey = $i === 2 ? "sm1" : "s";
            foreach ($mlist as $au) {
                $n = Text::name($au->firstName, $au->lastName, $au->email, NAME_P);
                $x = [$skey => $n];
                if ($i === 0) {
                    $x["au"] = true;
                    if (in_array($n, $aunames)) { // duplicate contact names are common
                        continue;
                    }
                    $aunames[] = $n;
                }
                if ($i < 2) {
                    $x["pri"] = 1;
                }
                $comp[] = $x;
            }
        }
        return ["ok" => true, "mentioncompletion" => array_values($comp)];
    }
}
