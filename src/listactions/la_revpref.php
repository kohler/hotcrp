<?php
// listactions/la_revpref.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Revpref_ListAction extends ListAction {
    /** @var string */
    private $name;
    function __construct($conf, $fj) {
        $this->name = $fj->name;
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->isPC;
    }
    static function render_upload(PaperList $pl) {
        return ["<b>&nbsp;preference file:</b> &nbsp;"
                . "<input class=\"want-focus js-autosubmit\" type=\"file\" name=\"fileupload\" accept=\"text/plain,text/csv\" size=\"20\" data-submit-fn=\"tryuploadpref\" />"
                . $pl->action_submit("tryuploadpref", ["class" => "can-submit-all"])];
    }
    static function render_set(PaperList $pl) {
        return ["<b> preferences:</b> &nbsp;"
            . Ht::entry("pref", "", ["class" => "want-focus js-autosubmit", "size" => 4, "data-submit-fn" => "setpref"])
            . $pl->action_submit("setpref")];
    }
    /** @param ?string $reviewer
     * @return ?Contact */
    static function lookup_reviewer(Contact $user, $reviewer) {
        if (($reviewer ?? "") === "" || $reviewer === "0") {
            return $user;
        } else if (ctype_digit($reviewer)) {
            return $user->conf->pc_member_by_id((int) $reviewer);
        } else {
            return $user->conf->pc_member_by_email($reviewer);
        }
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        // maybe download preferences for someone else
        $reviewer = self::lookup_reviewer($user, $qreq->reviewer);
        if (!$reviewer) {
            return $user->conf->error_msg("<0>Reviewer ‘{$qreq->reviewer}’ not found");
        } else if (!$reviewer->isPC) {
            return self::eperm();
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
        } else {
            return self::enoent();
        }
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
            if ($not_me && !$user->allow_administer($prow)) {
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
        $csvg = new CsvGenerator;
        $csvg->select(["paper", "email", "preference"]);
        foreach ($ssel->selection() as $p) {
            $csvg->add_row([$p, $reviewer->email, $qreq->pref]);
        }
        $aset = (new AssignmentSet($user))->set_override_conflicts(true);
        $aset->parse($csvg->unparse());
        $ok = $aset->execute();
        if ($qreq->ajax) {
            return $aset->json_result();
        } else if ($ok) {
            if ($aset->is_empty()) {
                $aset->prepend_msg("<0>No changes", MessageSet::WARNING_NOTE);
            } else {
                $aset->prepend_msg("<0>Preference changes saved", MessageSet::SUCCESS);
            }
            $user->conf->feedback_msg($aset->message_list());
            return new Redirection($user->conf->selfurl($qreq, null, Conf::HOTURL_RAW | Conf::HOTURL_REDIRECTABLE));
        } else {
            $user->conf->feedback_msg($aset->message_list());
        }
    }
    /** @return CsvParser */
    static function preference_file_csv($text, $filename) {
        $text = preg_replace('/^==-== /m', '#', cleannl($text));
        $csv = new CsvParser($text, CsvParser::TYPE_GUESS);
        $csv->set_comment_chars("#");
        $csv->set_filename($filename);
        $line = $csv->next_list();
        if ($line !== null) {
            if (preg_grep('/\A(?:paper|pid|paper[\s_]*id|id)\z/i', $line)) {
                $csv->set_header($line);
            } else {
                if (count($line) >= 2 && ctype_digit($line[0])) {
                    if (preg_match('/\A\s*\d+\s*[XYZ]?\s*\z/i', $line[1])) {
                        $csv->set_header(["paper", "preference"]);
                    } else {
                        $csv->set_header(["paper", "title", "preference"]);
                    }
                }
                $csv->unshift($line);
            }
        }
        return $csv;
    }
    function run_uploadpref(Contact $user, Qrequest $qreq, SearchSelection $ssel,
                            Contact $reviewer) {
        $reviewer_arg = $user->contactId === $reviewer->contactId ? null : $reviewer->email;
        $conf = $user->conf;
        if ($qreq->cancel) {
            return new Redirection($user->conf->selfurl($qreq, null, Conf::HOTURL_RAW | Conf::HOTURL_REDIRECTABLE));
        } else if ($qreq->file) {
            $csv = self::preference_file_csv($qreq->file, $qreq->filename);
        } else if ($qreq->has_file("fileupload")) {
            $csv = self::preference_file_csv($qreq->file_contents("fileupload"), $qreq->file_filename("fileupload"));
        } else {
            return MessageItem::error("<0>File upload required");
        }

        $aset = (new AssignmentSet($user))->set_override_conflicts(true);
        $aset->set_search_type("editpref");
        $aset->set_reviewer($reviewer);
        $aset->enable_actions("pref");
        if ($this->name === "applyuploadpref") {
            $aset->enable_papers($ssel->selection());
        }
        $aset->parse($csv);
        if ($aset->is_empty()) {
            if ($aset->has_error()) {
                $aset->prepend_msg("<0>Changes not saved; please correct these errors and try again", 2);
            } else {
                $aset->prepend_msg("<0>No changes", MessageSet::WARNING_NOTE);
            }
            $conf->feedback_msg($aset->message_list());
            return new Redirection($user->conf->selfurl($qreq, null, Conf::HOTURL_RAW | Conf::HOTURL_REDIRECTABLE));
        } else if ($this->name === "applyuploadpref" || $this->name === "uploadpref") {
            $aset->execute(true);
            return new Redirection($user->conf->selfurl($qreq, null, Conf::HOTURL_RAW | Conf::HOTURL_REDIRECTABLE));
        } else {
            $qreq->print_header("Review preferences", "revpref");
            $conf->feedback_msg($aset->message_list());

            echo Ht::form($conf->hoturl("=reviewprefs", ["reviewer" => $reviewer_arg]), ["class" => "differs need-unload-protection"]),
                Ht::hidden("fn", "applyuploadpref"),
                Ht::hidden("file", $aset->make_acsv()->unparse(), ["data-default-value" => ""]),
                Ht::hidden("filename", $csv->filename());

            echo '<h3>Proposed preference assignment</h3>';
            echo '<p>The uploaded file requests the following preference changes.</p>';
            $aset->print_unparse_display();

            echo Ht::actions([
                Ht::submit("Apply changes", ["class" => "btn-success"]),
                Ht::submit("cancel", "Cancel", ["formnovalidate" => true])
            ], ["class" => "aab aabig"]), "</form>\n";
            $qreq->print_footer();
            exit;
        }
    }
}
