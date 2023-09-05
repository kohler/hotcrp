<?php
// pages/manualassign.php -- HotCRP chair's paper assignment page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ManualAssign_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $viewer;
    /** @var Qrequest */
    public $qreq;
    /** @var list<string> */
    private $limits;

    function __construct(Contact $viewer, Qrequest $qreq) {
        $this->conf = $viewer->conf;
        $this->viewer = $viewer;
        $this->qreq = $qreq;
    }


    private function save(Contact $reviewer) {
        $rcid = $reviewer->contactId;

        $pids = [];
        foreach ($this->qreq as $k => $v) {
            if (str_starts_with($k, "assrev")
                && str_ends_with($k, "u" . $rcid)) {
                $pids[] = intval(substr($k, 6));
            }
        }

        $confset = $this->conf->conflict_set();
        $assignments = [];
        foreach ($this->viewer->paper_set(["paperId" => $pids, "reviewSignatures" => true]) as $row) {
            $name = "assrev{$row->paperId}u{$rcid}";
            if (!isset($this->qreq[$name])
                || ($assrev = stoi($this->qreq[$name])) === null) {
                continue;
            }

            $ct = $row->conflict_type($reviewer);
            $rt = $row->review_type($reviewer);
            if (!$this->viewer->can_administer($row)
                || Conflict::is_author($ct)) {
                continue;
            }

            if ($assrev < 0) {
                $newct = Conflict::is_conflicted($ct) ? $ct : Conflict::set_pinned(Conflict::GENERAL, true);
            } else {
                $newct = Conflict::is_conflicted($ct) ? 0 : $ct;
            }
            if ($ct !== $newct) {
                $assignments[] = [
                    $row->paperId,
                    $reviewer->email,
                    "conflict",
                    "",
                    $confset->unparse_assignment($newct)
                ];
            }

            $newrt = max($assrev, 0);
            if ($rt !== $newrt
                && ($newrt == 0 || $reviewer->can_accept_review_assignment_ignore_conflict($row))) {
                $assignments[] = [
                    $row->paperId,
                    $reviewer->email,
                    ReviewInfo::unparse_assigner_action($newrt),
                    $this->qreq->rev_round
                ];
            }
        }

        if (!empty($assignments)) {
            $text = "paper,email,action,round,conflicttype\n";
            foreach ($assignments as $line) {
                $text .= join(",", $line) . "\n";
            }
            $aset = new AssignmentSet($this->viewer);
            $aset->parse($text);
            $aset->execute(true);
        }

        $this->conf->redirect_self($this->qreq);
    }


    /** @param PaperList $pl
     * @return string */
    function show_ass_element($pl, $name, $text, $extra = []) {
        return '<li class="' . rtrim("checki " . ($extra["item_class"] ?? ""))
            . '"><span class="checkc">'
            . Ht::checkbox("show$name", 1, $pl->viewing($name), [
                "class" => "uich js-plinfo ignore-diff" . (isset($extra["fold_target"]) ? " js-foldup" : ""),
                "data-fold-target" => $extra["foldup"] ?? null
            ]) . "</span>" . Ht::label($text) . '</li>';
    }

    /** @param PaperList $pl
     * @return list<string> */
    function show_ass_elements($pl) {
        $show_data = [];
        if ($pl->has("abstract")) {
            $show_data[] = $this->show_ass_element($pl, "abstract", "Abstract");
        }
        if (($vat = $pl->viewable_author_types()) !== 0) {
            if (($vat & 1) !== 0) {
                $show_data[] = $this->show_ass_element($pl, "anonau", "Authors (deanonymized)", ["fold_target" => 10]);
            } else {
                $show_data[] = $this->show_ass_element($pl, "au", "Authors", ["fold_target" => 10]);
            }
            $show_data[] = $this->show_ass_element($pl, "aufull", "Full author info", ["item_class" => "fx10"]);
        }
        if ($pl->conf->has_topics()) {
            $show_data[] = $this->show_ass_element($pl, "topics", "Topics");
        }
        $show_data[] = $this->show_ass_element($pl, "tags", "Tags");
        return $show_data;
    }

    private function print_reviewer(Contact $reviewer) {
        // search outline from old CRP, done here in a very different way
        $hlsearch = [];
        foreach ($reviewer->aucollab_matchers() as $matcher) {
            $text = "match:\"" . str_replace("\"", "", $matcher->name(NAME_P|NAME_A)) . "\"";
            $hlsearch[] = "au" . $text;
            if (!$matcher->is_nonauthor() && $this->conf->setting("sub_collab")) {
                $hlsearch[] = "co" . $text;
            }
        }

        // Topic links
        $interest = [[], []];
        foreach ($reviewer->topic_interest_map() as $topic => $ti) {
            $interest[$ti > 0 ? 1 : 0][$topic] = $ti;
        }
        if (!empty($interest[1])) {
            echo '<div class="f-i"><label>High-interest topics</label>',
                $this->conf->topic_set()->unparse_list_html(array_keys($interest[1]), $interest[1]),
                "</div>";
        }
        if (!empty($interest[0])) {
            echo '<div class="f-i"><label>Low-interest topics</label>',
                $this->conf->topic_set()->unparse_list_html(array_keys($interest[0]), $interest[0]),
                "</div>";
        }

        // Conflict information
        $any = false;
        foreach ($reviewer->collaborator_generator() as $m) {
            echo ($any ? "" : "<div class=\"f-i\"><label>Collaborators</label><ul class=\"semi\">"),
                '<li>', $m->name_h(NAME_A), '</li>';
            $any = true;
        }
        echo $any ? '</ul></div>' : '';

        $show = " show:au" . ($this->conf->setting("sub_collab") ? " show:co" : "");
        echo '<div class="f-i">',
            '<a href="', $this->conf->hoturl("search", "q=" . urlencode(join(" OR ", $hlsearch) . " OR conf:" . $reviewer->email . $show) . '&amp;linkto=assign&amp;reviewer=' . urlencode($reviewer->email)),
            '">Search for current and potential conflicts</a></div>';

        // main assignment form
        $search = (new PaperSearch($this->viewer, [
            "t" => $this->qreq->t,
            "q" => $this->qreq->q,
            "reviewer" => $reviewer
        ]))->set_urlbase("manualassign");
        if (!empty($hlsearch)) {
            $search->set_field_highlighter_query(join(" OR ", $hlsearch));
        }
        $pl = new PaperList("reviewAssignment", $search, ["sort" => true], $this->qreq);
        $pl->apply_view_session($this->qreq);
        $pl->apply_view_qreq($this->qreq);
        echo Ht::form($this->conf->hoturl("=manualassign", ["reviewer" => $reviewer->email, "sort" => $this->qreq->sort]), ["class" => "need-diff-check assignpc ignore-diff"]),
            Ht::hidden("t", $this->qreq->t),
            Ht::hidden("q", $this->qreq->q);
        $rev_rounds = $this->conf->round_selector_options(false);
        $expected_round = $this->conf->assignment_round_option(false);

        echo '<div class="tlcontainer mb-3 has-fold fold10', $pl->viewing("authors") ? "o" : "c", '">';
        if (count($rev_rounds) > 1) {
            echo '<div class="entryi"><label for="assrevround">Review round</label><div class="entry">',
                Ht::select("rev_round", $rev_rounds, $this->qreq->rev_round ? : $expected_round, ["id" => "assrevround", "class" => "ignore-diff"]), ' <span class="barsep">·</span> ';
        } else if ($expected_round !== "unnamed") {
            echo '<div class="entryi"><label>Review round</label><div class="entry">',
                $expected_round, ' <span class="barsep">·</span> ';
        } else {
            echo '<div class="entryi"><label></label><div class="entry">';
        }
        echo '<label class="d-inline-block checki"><span class="checkc">',
            Ht::checkbox("autosave", "", true, ["id" => "assrevimmediate", "class" => "ignore-diff uich js-assignment-autosave"]),
            '</span>Automatically save assignments</label></div></div>';
        $show_data = $this->show_ass_elements($pl);
        if (!empty($show_data)) {
            echo '<div class="entryi"><label>Show</label>',
                '<ul class="entry inline">', join('', $show_data), '</ul></div>';
        }
        echo Ht::hidden("forceShow", 1, ["id" => "showforce"]); // search API must override conflicts
        echo '<div class="entryi autosave-hidden hidden"><label></label><div class="entry">',
            Ht::submit("update", "Save assignments", ["class" => "btn-primary big"]), '</div></div>';
        echo '</div>';

        $pl->set_table_id_class("pl", null);
        $pl->set_table_decor(PaperList::DECOR_HEADER | PaperList::DECOR_LIST | PaperList::DECOR_FULLWIDTH);
        echo '<div class="pltable-fullw-container demargin">';
        $pl->print_table_html();
        echo '</div>';

        echo '<div class="aab aabr aabig mt-2"><div class="aabut">',
            Ht::submit("update", "Save assignments", ["class" => "btn-primary"]),
            "</div></div></form>\n";
        Ht::stash_script('$("form.assignpc").awaken();$("#assrevimmediate").trigger("change");'
            . "$(\"#showau\").on(\"change\", function () { hotcrp.foldup.call(this, null, {n:10}) })");
    }


    function print(Contact $reviewer = null) {
        $this->qreq->print_header("Assignments", "manualassign", ["subtitle" => "Manual"]);
        echo '<nav class="papmodes mb-5 clearfix"><ul>',
            '<li class="papmode"><a href="', $this->conf->hoturl("autoassign"), '">Automatic</a></li>',
            '<li class="papmode active"><a href="', $this->conf->hoturl("manualassign"), '">Manual</a></li>',
            '<li class="papmode"><a href="', $this->conf->hoturl("conflictassign"), '">Conflicts</a></li>',
            '<li class="papmode"><a href="', $this->conf->hoturl("bulkassign"), '">Bulk update</a></li>',
            '</ul></nav>';

        // Help list
        echo '<div class="helpside"><div class="helpinside">
<p>Assignment methods:</p>
<ul><li><a href="', $this->conf->hoturl("autoassign"), '">Automatic</a></li>
 <li><a href="', $this->conf->hoturl("manualassign"), '" class="q"><strong>Manual by PC member</strong></a></li>
 <li><a href="', $this->conf->hoturl("assign"), '">Manual by paper</a></li>
 <li><a href="', $this->conf->hoturl("conflictassign"), '">Potential conflicts</a></li>
 <li><a href="', $this->conf->hoturl("bulkassign"), '">Bulk update</a></li>
</ul>
<hr>
<p>Types of PC review:</p>
<dl><dt>', review_type_icon(REVIEW_PRIMARY), ' Primary</dt><dd>Mandatory review</dd>
  <dt>', review_type_icon(REVIEW_SECONDARY), ' Secondary</dt><dd>May be delegated to external reviewers</dd>
  <dt>', review_type_icon(REVIEW_PC), ' Optional</dt><dd>May be declined</dd>
  <dt>', review_type_icon(REVIEW_META), ' Metareview</dt><dd>Can view all other reviews before completing their own</dd></dl>
<hr>
<dl><dt>Potential conflicts</dt><dd>Matches between PC member collaborators and paper authors, or between PC member and paper authors or collaborators</dd>
  <dt>Preference</dt><dd><a href="', $this->conf->hoturl("reviewprefs"), '">Review preference</a></dd>
  <dt>Topic score</dt><dd>High value means PC member has interest in many paper topics</dd>
  <dt>Desirability</dt><dd>High values mean many PC members want to review the paper</dd></dl>
<p>Click a heading to sort.</p></div></div>';

        echo '<h2 class="mt-3">Assignments ';
        if ($reviewer) {
            echo 'for ', $this->viewer->reviewer_html_for($reviewer),
                $reviewer->affiliation ? " (" . htmlspecialchars($reviewer->affiliation) . ")" : "";
        } else {
            echo 'by PC member';
        }
        echo "</h2>\n";

        // Change PC member
        echo "<table><tr><td><div class=\"assignpc_pcsel\">",
            Ht::form($this->conf->hoturl("manualassign"), ["method" => "get", "id" => "selectreviewerform", "class" => "need-diff-check"]);
        Ht::stash_script('$("#selectreviewerform").awaken()');

        $acs = AssignmentCountSet::load($this->viewer, AssignmentCountSet::HAS_REVIEW);
        $rev_opt = [];
        if (!$reviewer) {
            $rev_opt[0] = "(Select a PC member)";
        }
        foreach ($this->conf->pc_members() as $pc) {
            $rev_opt[$pc->email] = htmlspecialchars($pc->name(NAME_P|NAME_S)) . " ("
                . plural($acs->get($pc->contactId)->rev, "assignment") . ")";
        }

        echo "<table><tr><td><strong>PC member:</strong> &nbsp;</td>",
            "<td>", Ht::select("reviewer", $rev_opt, $reviewer ? $reviewer->email : 0), "</td></tr>",
            "<tr><td colspan=\"2\"><hr class=\"g\"></td></tr>\n";

        // Paper selection
        echo "<tr><td>Paper selection: &nbsp;</td><td>",
            Ht::entry("q", $this->qreq->q, [
                "id" => "manualassignq", "size" => 40, "placeholder" => "(All)",
                "class" => "papersearch want-focus need-suggest", "aria-label" => "Search",
                "spellcheck" => false, "autocomplete" => "off"
            ]), " &nbsp;in &nbsp;",
            PaperSearch::limit_selector($this->conf, $this->limits, $this->qreq->t),
            "</td></tr>\n",
            "<tr><td colspan=\"2\"><hr class=\"g\">\n";

        echo '<tr><td colspan="2"><div class="aab aabr">',
            '<div class="aabut">', Ht::submit("Go", ["class" => "btn-primary"]), '</div>',
            '</div></td></tr>',
            "</table>\n</form></div></td></tr></table>\n";


        // Current PC member information
        if ($reviewer) {
            $this->print_reviewer($reviewer);
        }

        echo '<hr class="c">';
        $this->qreq->print_footer();
    }


    function run() {
        $overrides = $this->viewer->add_overrides(Contact::OVERRIDE_CONFLICT);

        $this->limits = PaperSearch::viewable_manager_limits($this->viewer);
        if (!$this->qreq->t || !in_array($this->qreq->t, $this->limits)) {
            $this->qreq->t = $this->limits[0];
        }
        if (!$this->qreq->q || trim($this->qreq->q) == "(All)") {
            $this->qreq->q = "";
        }
        $this->qreq->rev_round = (string) $this->conf->sanitize_round_name($this->qreq->rev_round);

        $reviewer = $this->viewer;
        if (isset($this->qreq->reviewer)) {
            $this->conf->ensure_cached_user_collaborators();
            $reviewer = ctype_digit($this->qreq->reviewer)
                ? $this->conf->user_by_id(intval($this->qreq->reviewer), USER_SLICE)
                : $this->conf->user_by_email($this->qreq->reviewer, USER_SLICE);
        }
        if ($reviewer && ($reviewer->roles & Contact::ROLE_PC) === 0) {
            $reviewer = null;
        }

        if ($this->qreq->update
            && $this->qreq->valid_post()
            && $reviewer) {
            $this->save($reviewer);
        } else {
            if ($this->qreq->update && $this->qreq->valid_post()) {
                $this->conf->error_msg("<0>Please select a reviewer");
            }
            $this->print($reviewer);
        }

        $this->viewer->set_overrides($overrides);
    }


    static function go(Contact $user, Qrequest $qreq) {
        if ($user->is_manager()) {
            (new ManualAssign_Page($user, $qreq))->run();
        } else {
            $user->escape();
        }
    }
}
