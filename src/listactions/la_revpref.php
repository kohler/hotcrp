<?php
// listactions/la_revpref.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class Revpref_ListAction extends ListAction {
    /** @var string */
    private $name;
    function __construct($conf, $fj) {
        $this->name = $fj->name;
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->isPC;
    }
    static function render_upload(PaperList $pl, Qrequest $qreq, $plft) {
        $plft->label_expansion = " preference file:";
        return "<input class=\"want-focus js-autosubmit\" type=\"file\" name=\"preffile\" accept=\"text/plain,text/csv\" size=\"20\" data-submit-fn=\"tryuploadpref\" />"
            . $pl->action_submit("tryuploadpref", ["class" => "can-submit-all"]);
    }
    static function render_set(PaperList $pl, Qrequest $qreq, $plft) {
        $plft->label_expansion = " preferences:";
        return Ht::entry("pref", "", ["class" => "want-focus js-autosubmit", "size" => 4, "data-submit-fn" => "setpref"])
            . $pl->action_submit("setpref");
    }
    /** @param ?string $reviewer
     * @return ?Contact */
    static function lookup_reviewer(Contact $user, $reviewer) {
        if (($reviewer ?? "") === "" || $reviewer === "0") {
            return $user;
        } else if (ctype_digit($reviewer)) {
            return $user->conf->pc_member_by_id((int) $reviewer);
        }
        return $user->conf->pc_member_by_email($reviewer);
    }

    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        // maybe download preferences for someone else
        $reviewer = self::lookup_reviewer($user, $qreq->reviewer);
        if (!$reviewer) {
            return JsonResult::make_not_found_error("reviewer", "<0>Reviewer ‘{$qreq->reviewer}’ not found");
        } else if (!$reviewer->isPC) {
            return JsonResult::make_permission_error();
        } else if ($this->name === "get/revpref") {
            return $this->run_get($user, $qreq, $ssel, $reviewer, false);
        } else if ($this->name === "get/revprefx") {
            return $this->run_get($user, $qreq, $ssel, $reviewer, true);
        } else if ($this->name === "setpref") {
            return $this->run_setpref($user, $qreq, $ssel, $reviewer);
        } else if ($this->name === "uploadpref"
                   || $this->name === "tryuploadpref"
                   || $this->name === "applyuploadpref") {
            return $this->run_uploadpref($user, $qreq, $ssel, $reviewer);
        }
        return parent::run($user, $qreq, $ssel);
    }

    function run_get(Contact $user, Qrequest $qreq, SearchSelection $ssel,
                     Contact $reviewer, $extended) {
        $not_me = $user->contactId !== $reviewer->contactId;
        $fields = [
            "paper" => true, "title" => true, "email" => $not_me, "preference" => true,
            "notes" => false, "authors" => false, "abstract" => !!$extended, "topics" => false
        ];
        $texts = [];
        foreach ($ssel->paper_set($user, ["topics" => 1, "reviewerPreference" => 1]) as $prow) {
            if ($not_me && !$user->allow_admin($prow)) {
                continue;
            }
            $item = ["paper" => $prow->paperId, "title" => $prow->title];
            if ($not_me) {
                $item["email"] = $reviewer->email;
            }
            $item["preference"] = $prow->preference($reviewer)->unparse();
            if ($prow->has_conflict($reviewer)) {
                $item["notes"] = "conflict";
                $fields["notes"] = true;
            }
            if ($extended) {
                if ($reviewer->can_view_authors($prow)) {
                    $aus = array_map(function ($a) { return $a->name(NAME_P|NAME_A); }, $prow->author_list());
                    $item["authors"] = join("\n", $aus);
                    $fields["authors"] = true;
                }
                $item["abstract"] = $prow->abstract();
                if ($prow->topicIds !== "") {
                    $item["topics"] = $prow->unparse_topics_text();
                    $fields["topics"] = true;
                }
            }
            $texts[] = $item;
        }
        $title = "revprefs";
        if ($not_me) {
            $title .= "-" . (preg_replace('/@.*|[^\w@.]/', "", $reviewer->email) ? : "user");
        }
        return $user->conf->make_csvg($title, CsvGenerator::FLAG_ITEM_COMMENTS)
            ->select(array_keys(array_filter($fields)))
            ->append($texts);
    }

    function run_setpref(Contact $user, Qrequest $qreq, SearchSelection $ssel,
                         Contact $reviewer) {
        $header = ["paper", "email", "preference"];
        $data = [0, $reviewer->email, $qreq->preference ?? $qreq->pref];
        if (isset($qreq->expertise)) {
            $header[] = "expertise";
            $data[] = $qreq->expertise;
        }

        $csvg = new CsvGenerator;
        $csvg->select($header);
        foreach ($ssel->selection() as $p) {
            $data[0] = $p;
            $csvg->add_row($data);
        }

        $aset = new AssignmentSet($user);
        if (friendly_boolean($qreq->forceShow) !== false) {
            $aset->set_override_conflicts(true);
        }
        $aset->parse($csvg->unparse());

        if ($qreq->page() === "api") {
            return Assign_API::complete($aset, $qreq);
        }

        $aset->execute();
        $aset->feedback_msg(AssignmentSet::FEEDBACK_CHANGE);
        if ($aset->has_error()) {
            return;
        }
        return new Redirection($user->conf->selfurl($qreq, null, Conf::HOTURL_RAW | Conf::HOTURL_REDIRECTABLE));
    }

    function run_uploadpref(Contact $user, Qrequest $qreq, SearchSelection $ssel,
                            Contact $reviewer) {
        $reviewer_arg = $user->contactId === $reviewer->contactId ? null : $reviewer->email;
        $conf = $user->conf;
        if ($qreq->cancel && $qreq->page() !== "api") {
            return new Redirection($user->conf->selfurl($qreq, null, Conf::HOTURL_RAW | Conf::HOTURL_REDIRECTABLE));
        }

        if (($qf = $qreq->file("preffile"))) {
            $qf = $qf->content_or_docstore("prefassign-%s.csv", $user->conf);
        } else if ($qreq->preffile) {
            $qf = QrequestFile::make_string($qreq->preffile, $qreq->filename);
        } else if ($qreq->data_source
                   && ($ds = $user->conf->docstore())
                   && ($f = $ds->open_tempfile($qreq->data_source, "prefassign-%s.csv"))) {
            $qf = QrequestFile::make_stream($f, $qreq->filename);
        } else if ($qreq->upload) {
            if (!($updoc = DocumentInfo::make_capability($user->conf, $qreq->upload))
                || !($qf = QrequestFile::make_document($updoc))) {
                return JsonResult::make_missing_error("upload", "<0>Upload not found");
            }
        } else {
            return JsonResult::make_missing_error("preffile", "<0>File upload required");
        }
        if (!$qf) {
            return JsonResult::make_parameter_error("preffile", "<0>Uploaded file too big to process");
        }
        $qf->convert_to_utf8();

        $csv = new CsvParser($qf->stream ?? $qf->content, CsvParser::TYPE_GUESS);
        $csv->add_comment_prefix("#")->add_comment_prefix("==-== ");
        $csv->set_filename($qf->name);
        $line = $csv->peek_list();
        if ($line === null) {
            // do nothing
        } else if (preg_grep('/\A(?:paper|pid|paper[\s_]*id|id)\z/i', $line)) {
            $csv->set_header($line);
            $csv->next_list();
        } else if (count($line) >= 2 && ctype_digit($line[0])) {
            if (preg_match('/\A\s*\d+\s*[XYZ]?\s*\z/i', $line[1])) {
                $csv->set_header(["paper", "preference"]);
            } else {
                $csv->set_header(["paper", "title", "preference"]);
            }
        }

        $aset = new AssignmentSet($user);
        if (friendly_boolean($qreq->forceShow) !== false) {
            $aset->set_override_conflicts(true);
        }
        $aset->set_search_type("editpref");
        $aset->set_reviewer($reviewer);
        $aset->enable_actions("pref");
        if ($this->name === "applyuploadpref"
            || ($qreq->page() === "api"
                && ($qreq->q !== "" || !$ssel->is_default()))) {
            $aset->enable_papers($ssel->selection());
        }
        $aset->parse($csv);

        if ($qreq->page() === "api") {
            return Assign_API::complete($aset, $qreq);
        }

        $execute = $this->name === "applyuploadpref" || $this->name === "uploadpref";
        if ($execute) {
            $aset->execute();
        }
        if ($execute || $aset->is_empty()) {
            $aset->feedback_msg(AssignmentSet::FEEDBACK_CHANGE);
            return new Redirection($user->conf->selfurl($qreq, null, Conf::HOTURL_RAW | Conf::HOTURL_REDIRECTABLE));
        }

        $qreq->print_header("Review preferences", "revpref");
        $aset->feedback_msg(AssignmentSet::FEEDBACK_CHANGE_IGNORE);

        echo Ht::form($conf->hoturl("=reviewprefs", ["reviewer" => $reviewer_arg]),
            ["class" => "ui-submit js-selector-summary differs need-unload-protection"]),
            Ht::hidden("fn", "applyuploadpref");
        if ($aset->assignment_count() < 5000) {
            echo Ht::hidden("preffile", $aset->make_acsv()->unparse(), ["data-default-value" => ""]);
        } else if (is_string($qf->content)) {
            echo Ht::hidden("preffile", $qf->content, ["data-default-value" => ""]);
        } else {
            echo Ht::hidden("data_source", $qf->docstore_tmp_name, ["data-default-value" => ""]);
        }
        echo Ht::hidden("filename", $csv->filename());

        echo '<h3>Proposed preference assignment</h3>';
        echo '<p>The uploaded file requests the following preference changes.</p>';
        $aset->print_unparse_display();

        echo Ht::actions([
            Ht::submit("Apply changes", ["class" => "btn-success"]),
            Ht::submit("cancel", "Cancel", ["formnovalidate" => true])
        ], ["class" => "aab aabig"]), "</form>\n";
        $qreq->print_footer();
        exit(0);
    }
}
