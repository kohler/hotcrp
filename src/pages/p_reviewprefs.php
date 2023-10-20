<?php
// pages/p_reviewprefs.php -- HotCRP review preference global settings page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ReviewPrefs_Page {
    // Update preferences
    /** @param Contact $user
     * @param Contact $reviewer
     * @param Qrequest $qreq */
    static private function save_preferences($user, $reviewer, $qreq) {
        $csvg = new CsvGenerator;
        $csvg->select(["paper", "email", "preference"]);
        $suffix = "u" . $reviewer->contactId;
        foreach ($qreq as $k => $v) {
            if (strlen($k) > 7 && substr($k, 0, 7) == "revpref") {
                if (str_ends_with($k, $suffix)) {
                    $k = substr($k, 0, -strlen($suffix));
                }
                if (($p = stoi(substr($k, 7)) ?? -1) > 0) {
                    $csvg->add_row([$p, $reviewer->email, $v]);
                }
            }
        }
        if ($csvg->is_empty()) {
            $user->conf->feedback_msg([new MessageItem(null, "<0>No changes", MessageSet::WARNING_NOTE)]);
            return;
        }

        $aset = (new AssignmentSet($user))->set_override_conflicts(true);
        $aset->parse($csvg->unparse());
        $ok = $aset->execute();
        $ok && $aset->prepend_msg("<0>Preferences saved", MessageSet::SUCCESS);
        $user->conf->feedback_msg($aset->message_list());
        $ok && $user->conf->redirect_self($qreq);
    }

    /** @param PaperList $pl
     * @return string */
    static private function pref_element($pl, $name, $text, $extra = []) {
        return '<li class="' . rtrim("checki " . ($extra["item_class"] ?? ""))
            . '"><span class="checkc">'
            . Ht::checkbox("show$name", 1, $pl->viewing($name), [
                "class" => "uich js-plinfo ignore-diff" . (isset($extra["fold_target"]) ? " js-foldup" : ""),
                "data-fold-target" => $extra["fold_target"] ?? null
            ]) . "</span>" . Ht::label($text) . '</span>';
    }

    /** @param Contact $user
     * @param Contact $reviewer
     * @param Qrequest $qreq */
    static function print($user, $reviewer, $qreq) {
        $conf = $user->conf;

        $qreq->print_header("Review preferences", "reviewprefs");

        $targ = new FmtArg("topics", $conf->has_topics());
        if (($prefdesc = $conf->_i("revprefdescription", $targ))) {
            echo '<div class="msg demargin remargin-left remargin-right">',
                Ftext::as(5, $prefdesc), '</div>';
        }

        $search = (new PaperSearch($user, [
            "t" => $qreq->t, "q" => $qreq->q, "reviewer" => $reviewer
        ]))->set_urlbase("reviewprefs");
        $pl = new PaperList("pf", $search, ["sort" => true], $qreq);
        $pl->apply_view_report_default();
        $pl->apply_view_session($qreq);
        $pl->apply_view_qreq($qreq);
        $pl->set_table_id_class("pl", null, "p#");
        $pl->set_table_decor(PaperList::DECOR_HEADER | PaperList::DECOR_FOOTER | PaperList::DECOR_LIST | PaperList::DECOR_FULLWIDTH);
        $pl->set_table_fold_session("pfdisplay.");

        // display options
        echo Ht::form($conf->hoturl("reviewprefs"), [
            "method" => "get", "id" => "f-search",
            "class" => "tlcontainer mb-3 has-fold fold10" . ($pl->viewing("authors") ? "o" : "c")
        ]);

        if ($user->privChair) {
            echo '<div class="entryi"><label for="k-prefs-user">User</label>';

            $prefcount = [];
            $result = $conf->qe_raw("select contactId, count(*) from PaperReviewPreference where preference!=0 or expertise is not null group by contactId");
            while (($row = $result->fetch_row())) {
                $prefcount[(int) $row[0]] = (int) $row[1];
            }
            Dbl::free($result);

            $sel = [];
            foreach ($conf->pc_members() as $p) {
                $sel[$p->email] = $p->name_h(NAME_P|NAME_S) . " &nbsp; [" . plural($prefcount[$p->contactId] ?? 0, "pref") . "]";
            }
            if (!isset($sel[$reviewer->email])) {
                $sel[$reviewer->email] = $reviewer->name_h(NAME_P|NAME_S) . " &nbsp; [" . ($prefcount[$reviewer->contactId] ?? 0) . "; not on PC]";
            }

            echo Ht::select("reviewer", $sel, $reviewer->email, ["id" => "k-prefs-user"]), '</div>';
            Ht::stash_script('$("#f-search select[name=reviewer]").on("change", function () { $$("f-search").submit() })');
        }

        echo '<div class="entryi"><label for="k-prefs-q">Search</label><div class="entry">',
            Ht::entry("q", $qreq->q, [
                "id" => "k-prefs-q", "size" => 32, "placeholder" => "(All)",
                "class" => "papersearch want-focus need-suggest",
                "spellcheck" => false, "autocomplete" => "off"
            ]), '  ', Ht::submit("redisplay", "Redisplay"), '</div></div>';

        $show_data = [];
        if ($pl->has("abstract")) {
            $show_data[] = self::pref_element($pl, "abstract", "Abstract");
        }
        if (($vat = $pl->viewable_author_types()) !== 0) {
            $extra = ["fold_target" => 10];
            if (($vat & 2) !== 0) {
                $show_data[] = self::pref_element($pl, "au", "Authors", $extra);
                $extra = ["item_class" => "fx10"];
            }
            if (($vat & 1) !== 0) {
                $show_data[] = self::pref_element($pl, "anonau", "Authors (deanonymized)", $extra);
                $extra = ["item_class" => "fx10"];
            }
            $show_data[] = self::pref_element($pl, "aufull", "Full author info", $extra);
        }
        if ($conf->has_topics()) {
            $show_data[] = self::pref_element($pl, "topics", "Topics");
        }
        if (!empty($show_data) && !$pl->is_empty()) {
            echo '<div class="entryi"><label>Show</label>',
                '<ul class="entry inline">', join('', $show_data), '</ul></div>';
        }
        echo "</form>";
        Ht::stash_script("$(\"#showau\").on(\"change\", function () { hotcrp.foldup.call(this, null, {n:10}) })");

        // main form
        $hoturl_args = [];
        if ($reviewer->contactId !== $user->contactId) {
            $hoturl_args["reviewer"] = $reviewer->email;
        }
        if ($qreq->q) {
            $hoturl_args["q"] = $qreq->q;
        }
        if ($qreq->sort) {
            $hoturl_args["sort"] = $qreq->sort;
        }
        echo Ht::form($conf->hoturl("=reviewprefs", $hoturl_args), ["id" => "sel", "class" => "ui-submit js-submit-list assignpc"]),
            Ht::hidden("defaultfn", ""),
            Ht::hidden_default_submit("default", 1);
        if ($search->has_message()) {
            echo '<div class="msgs-wide">',
                Ht::msg($search->full_feedback_html(), min($search->problem_status(), MessageSet::WARNING)),
                '</div>';
        }
        echo '<noscript><div style="text-align:center">',
            Ht::submit("fn", "Save changes", ["value" => "saveprefs", "class" => "btn-primary"]),
            '</div></noscript><div class="pltable-fullw-container demargin">';
        $pl->print_table_html();
        echo "</div></form>\n";

        $qreq->print_footer();
    }

    /** @param Contact $user
     * @param Qrequest $qreq */
    static function go($user, $qreq) {
        $conf = $user->conf;
        if (!$user->privChair && !$user->isPC) {
            $user->escape();
        }

        if (isset($qreq->default) && $qreq->defaultfn) {
            $qreq->fn = $qreq->defaultfn;
        } else if (isset($qreq->default)) {
            $qreq->fn = "saveprefs";
        }

        // set reviewer
        $reviewer = $user;
        $correct_reviewer = true;
        if ($qreq->reviewer
            && $user->privChair
            && $qreq->reviewer !== $user->email
            && $qreq->reviewer !== $user->contactId) {
            $correct_reviewer = false;
            $conf->ensure_cached_user_collaborators();
            $u = ctype_digit($qreq->reviewer)
                ? $conf->user_by_id(intval($qreq->reviewer), USER_SLICE)
                : $conf->user_by_email($qreq->reviewer, USER_SLICE);
            if ($u && ($u->roles & Contact::ROLE_PC) !== 0) {
                $reviewer = $u;
                $correct_reviewer = true;
                $qreq->reviewer = $u->email;
            }
        } else if (!$qreq->reviewer && !($user->roles & Contact::ROLE_PC)) {
            foreach ($conf->pc_members() as $pcm) {
                $conf->redirect_self($qreq, ["reviewer" => $pcm->email]);
                // in case redirection fails:
                $reviewer = $pcm;
                break;
            }
        }
        if (!$correct_reviewer) {
            $conf->feedback_msg([new MessageItem(null, "<0>Requested reviewer ‘{$qreq->reviewer}’ is not on the PC", MessageSet::ERROR)]);
        }

        // cancel action
        if ($qreq->cancel) {
            $conf->redirect_self($qreq);
        }

        // backwards compat
        if ($qreq->fn
            && strpos($qreq->fn, "/") === false
            && isset($qreq[$qreq->fn . "fn"])) {
            $qreq->fn .= "/" . $qreq[$qreq->fn . "fn"];
        }
        if (isset($qreq->fn)
            && !str_starts_with($qreq->fn, "get/")
            && !in_array($qreq->fn, ["uploadpref", "tryuploadpref", "applyuploadpref", "setpref", "saveprefs"])) {
            unset($qreq->fn);
        }

        // paper selection, search actions
        $ssel = SearchSelection::make($qreq, $user);
        SearchSelection::clear_request($qreq);
        $qreq->q = $qreq->q ?? "";
        $qreq->t = "editpref";
        if ($qreq->fn === "saveprefs") {
            if ($qreq->valid_post()) {
                if ($correct_reviewer) {
                    self::save_preferences($user, $reviewer, $qreq);
                } else {
                    $conf->feedback_msg([new MessageItem(null, "<0>Changes not saved", MessageSet::ERROR)]);
                }
            }
        } else if ($qreq->fn !== null) {
            ListAction::call($qreq->fn, $user, $qreq, $ssel);
        }

        // set options to view
        if (isset($qreq->redisplay)) {
            $pfd = " ";
            foreach ($qreq as $k => $v) {
                if (substr($k, 0, 4) == "show" && $v)
                    $pfd .= substr($k, 4) . " ";
            }
            $qreq->set_csession("pfdisplay", $pfd);
            $conf->redirect_self($qreq);
        }

        self::print($user, $reviewer, $qreq);
    }
}
