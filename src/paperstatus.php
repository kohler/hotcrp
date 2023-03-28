<?php
// paperstatus.php -- HotCRP helper for reading/storing papers as JSON
// Copyright (c) 2008-2023 Eddie Kohler; see LICENSE.

class PaperStatus extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var bool */
    private $disable_users;
    /** @var bool */
    private $allow_any_content_file = false;
    /** @var bool */
    private $add_topics;
    /** @var list<callable> */
    private $_on_document_import = [];

    /** @var ?PaperInfo */
    private $prow;
    /** @var int */
    public $paperId;
    /** @var ?string */
    public $title;
    /** @var ?int */
    private $_desired_pid;
    /** @var ?list<string> */
    private $_unknown_fields;
    /** @var list<PaperOption> */
    private $_fdiffs;
    /** @var list<string> */
    private $_xdiffs;
    /** @var array<string,null|int|string> */
    private $_paper_upd;
    /** @var array<string,null|int|string> */
    private $_paper_overflow_upd;
    /** @var ?list<int> */
    public $_topic_ins; // set by Topics_PaperOption
    /** @var associative-array<int,PaperValue> */
    private $_field_values;
    /** @var ?list<int> */
    private $_option_delid;
    /** @var ?list<list> */
    private $_option_ins;
    /** @var associative-array<string,array{int,int,int}> */
    private $_conflict_values;
    /** @var ?list<array{int,int,int}> */
    private $_conflict_ins;
    /** @var ?list<int> */
    private $_author_change_cids;
    /** @var ?list<Author> */
    private $_register_users;
    /** @var ?list<Contact> */
    private $_created_contacts;
    /** @var bool */
    private $_paper_submitted;
    /** @var bool */
    private $_documents_changed;
    /** @var bool */
    private $_noncontacts_changed;
    /** @var list<int> */
    private $_update_pid_dids;
    /** @var list<DocumentInfo> */
    private $_joindocs;
    /** @var int */
    private $_save_status;

    const SAVE_STATUS_PREPARED = 1;
    const SAVE_STATUS_SAVED = 2;
    const SAVE_STATUS_NEW = 4;
    const SAVE_STATUS_SUBMIT = 8;
    const SAVE_STATUS_NEWSUBMIT = 16;
    const SAVE_STATUS_FINALSUBMIT = 32;

    function __construct(Contact $user, $options = []) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->disable_users = $options["disable_users"] ?? false;
        $this->add_topics = $options["add_topics"] ?? false;
        if (($options["check_content_file"] ?? null) !== false) {
            $this->_on_document_import[] = [$this, "document_import_check_filename"];
        }
        $this->set_want_ftext(true, 5);
        $this->set_ignore_duplicates(true);
    }

    /** @return PaperStatus */
    static function make_prow(Contact $user, PaperInfo $prow) {
        $ps = new PaperStatus($user);
        $ps->prow = $prow;
        return $ps;
    }

    /** @param callable(object,PaperOption,PaperStatus):(?bool) $cb
     * @return $this */
    function on_document_import($cb) {
        $this->_on_document_import[] = $cb;
        return $this;
    }

    /** @return Contact */
    function user() {
        return $this->user;
    }

    /** @return bool */
    function add_topics() {
        return $this->add_topics;
    }

    function _($itext, ...$args) {
        return $this->conf->_($itext, ...$args);
    }


    /** @param ?string $msg
     * @param int $status
     * @return MessageItem */
    function msg_at_option(PaperOption $o, $msg, $status) {
        return $this->msg_at($o->field_key(), $msg, $status);
    }

    /** @param ?string $msg
     * @return MessageItem */
    function error_at_option(PaperOption $o, $msg) {
        return $this->error_at($o->field_key(), $msg);
    }

    /** @param ?string $msg
     * @return MessageItem */
    function warning_at_option(PaperOption $o, $msg) {
        return $this->warning_at($o->field_key(), $msg);
    }

    /** @param string $key
     * @param mixed $value
     * @return MessageItem */
    function syntax_error_at($key, $value) {
        return $this->error_at($key, "<0>Validation error [{$key}]");
    }

    /** @return list<MessageItem> */
    function decorated_message_list() {
        $ms = [];
        foreach ($this->message_list() as $mi) {
            if (str_ends_with($mi->field ?? "", ":context")
                || $mi->status === MessageSet::INFORM) {
                // do not report in decorated list
            } else if ($mi->field
                       && $mi->message !== ""
                       && ($o = $this->conf->options()->option_by_field_key($mi->field))) {
                $link = Ht::link(htmlspecialchars($o->edit_title()), "#" . $o->readable_formid());
                $ms[] = $mi->with(["message" => "<5>{$link}: " . $mi->message_as(5)]);
            } else {
                $ms[] = $mi;
            }
        }
        return $ms;
    }

    /** @return string */
    function decorated_feedback_text() {
        return MessageSet::feedback_text($this->decorated_message_list());
    }


    /** @return bool */
    function will_insert() {
        return $this->prow->paperId === 0;
    }


    function document_import_check_filename($docj, PaperOption $o, PaperStatus $pstatus) {
        if (is_string($docj->content_file ?? null)
            && !($docj instanceof DocumentInfo)
            && preg_match('/\A\/|(?:\A|\/)\.\.(?:\/|\z)/', $docj->content_file)) {
            $pstatus->error_at_option($o, "<0>Bad content_file: only simple filenames allowed");
            return false;
        }
    }

    /** @return ?DocumentInfo */
    function upload_document($docj, PaperOption $o) {
        // $docj can be a DocumentInfo or a JSON.
        // If it is a JSON, its format is set by document_to_json.
        if (is_array($docj) && count($docj) === 1 && isset($docj[0])) {
            $docj = $docj[0];
        }
        if (!is_object($docj)) {
            $this->syntax_error_at($o->field_key(), $docj);
            return null;
        } else if (($docj->error ?? false) || ($docj->error_html ?? false)) {
            $this->error_at_option($o, "<5>" . ($docj->error_html ?? "Upload error"));
            return null;
        }
        assert(!isset($docj->filter));

        // check on_document_import
        foreach ($this->_on_document_import as $cb) {
            if (call_user_func($cb, $docj, $o, $this) === false)
                return null;
        }

        // validate JSON
        if ($docj instanceof DocumentInfo) {
            $doc = $docj;
        } else {
            $doc = $this->_upload_json_document($docj, $o);
        }

        // save
        if ($doc->paperStorageId === 0
            && ($doc->has_error() || !$doc->save())) {
            foreach ($doc->message_list() as $mi) {
                $mi = $this->msg_at_option($o, $mi->message, $mi->status);
                $mi->landmark = $doc->export_filename();
            }
            return null;
        }

        if ($doc->documentType <= 0) {
            $this->_joindocs[] = $doc;
        }
        if ($doc->paperId === 0 || $doc->paperId === -1) {
            $this->_update_pid_dids[] = $doc->paperStorageId;
        } else {
            assert($doc->paperId === $this->prow->paperId);
        }
        return $doc;
    }

    /** @return DocumentInfo */
    private function _upload_json_document($docj, PaperOption $o) {
        $hash = null;
        if (isset($docj->hash) && is_string($docj->hash)) {
            $hash = Filer::hash_as_text($docj->hash);
        } else if (!isset($docj->hash) && isset($docj->sha1) && is_string($docj->sha1)) {
            $hash = Filer::sha1_hash_as_text($docj->sha1);
        }
        $docid = $docj->docid ?? null;

        // make new document
        $args = [
            "paperId" => $this->_desired_pid ?? -1,
            "hash" => $hash, "documentType" => $o->id
        ];
        foreach (["timestamp", "mimetype",
                  "content", "content_base64", "content_file"] as $k) {
            if (isset($docj->$k))
                $args[$k] = $docj->$k;
        }
        if (isset($docj->filename)) {
            $args["filename"] = DocumentInfo::sanitize_filename($docj->filename);
        }
        $doc = new DocumentInfo($args, $this->conf, $this->prow);

        // check for existing document with same did and/or hash
        if (!$this->will_insert()
            && (($hash && !$doc->content_available())
                || (is_int($docid) && $docid > 0))) {
            $qx = ["paperId=?" => $this->prow->paperId, "documentType=?" => $o->id];
            if (is_int($docid) && $docid > 0) {
                $qx["paperStorageId=?"] = $docid;
            }
            if ($hash) {
                $qx["sha1=?"] = Filer::hash_as_binary($hash);
            }
            if (isset($docj->mimetype)) {
                $qx["mimetype=?"] = $docj->mimetype;
            }
            $result = $this->conf->qe_apply("select * from PaperStorage where " . join(" and ", array_keys($qx)), array_values($qx));
            $edoc = DocumentInfo::fetch($result, $this->conf, $this->prow);
            Dbl::free($result);
            if ($edoc) {
                return $edoc;
            }
        }

        // document upload requires available content
        // Chair users can upload using *only* a hash; other users must
        // provide the relevant content.
        if ($doc->content_available()
            || ($hash
                && isset($docj->mimetype)
                && $this->user->privChair
                && $doc->ensure_content())) {
            $doc->analyze_content();
        } else {
            $doc->error("<0>Document has no content");
        }

        return $doc;
    }


    private function _normalize($ipj) {
        // Errors prevent saving
        $xpj = (object) [];

        // Status
        $xstatus = (object) [];
        if (isset($ipj->status) && is_object($ipj->status)) {
            $istatusstr = null;
            $istatus = $ipj->status;
        } else {
            if (isset($ipj->status) && is_string($ipj->status)) {
                $istatusstr = $ipj->status;
            } else {
                $istatusstr = null;
            }
            $istatus = $ipj;
        }
        foreach (["submitted", "draft", "withdrawn", "final_submitted"] as $k) {
            $v = $istatus->$k ?? null;
            if ($v !== null && !is_bool($v)) {
                $this->syntax_error_at("status.{$k}", $v);
                $v = null;
            }
            $xstatus->$k = $v;
        }
        if ($xstatus->submitted !== null || $xstatus->draft !== null) {
            $xstatus->draft = $xstatus->draft ?? !$xstatus->submitted;
            $xstatus->submitted = $xstatus->submitted ?? !$xstatus->draft;
        }
        foreach (["submitted_at", "withdrawn_at", "final_submitted_at"] as $k) {
            $v = $istatus->$k ?? null;
            if (is_numeric($v)) {
                $v = (float) $v;
                if ($v < 0) {
                    $this->error_at("status.{$k}", "<0>Negative date");
                    $v = null;
                }
            } else if (is_string($v)) {
                $v = $this->conf->parse_time($v, Conf::$now);
                if ($v === false || $v < 0) {
                    $this->error_at("status.{$k}", "<0>Parse error in date");
                    $v = null;
                } else {
                    $v = (float) $v;
                }
            } else if ($v === false) {
                $v = 0.0;
            } else if ($v !== null) {
                $this->syntax_error_at("status.{$k}", $v);
            }
            $xstatus->$k = $v;
        }
        $v = $istatus->withdraw_reason ?? null;
        if (is_string($v)) {
            $xstatus->withdraw_reason = $v;
        } else if ($v !== null) {
            $this->syntax_error_at("status.withdraw_reason", $v);
        }
        if ($istatusstr === "submitted"
            || $istatusstr === "accepted"
            || $istatusstr === "deskrejected"
            || $istatusstr === "rejected") {
            $xstatus->submitted = $xstatus->submitted ?? true;
            $xstatus->draft = $xstatus->draft ?? false;
            $xstatus->withdrawn = $xstatus->withdrawn ?? false;
        } else if ($istatusstr === "draft"
                   || $istatusstr === "inprogress") {
            $xstatus->submitted = $xstatus->submitted ?? false;
            $xstatus->draft = $xstatus->draft ?? true;
            $xstatus->withdrawn = $xstatus->withdrawn ?? false;
        } else if ($istatusstr === "withdrawn") {
            $xstatus->withdrawn = $xstatus->withdrawn ?? true;
        }
        $xstatus->submitted = $xstatus->submitted ?? $this->prow->timeSubmitted !== 0;
        $xstatus->draft = $xstatus->draft ?? !$xstatus->submitted;
        $xstatus->withdrawn = $xstatus->withdrawn ?? $this->prow->timeWithdrawn > 0;
        if ($xstatus->submitted !== !$xstatus->draft) {
            $this->error_at("status.draft", "<0>Draft status conflicts with submitted status");
        }
        $xpj->status = $xstatus;

        // Decision
        $idecision = $ipj->decision ?? null;
        if ($idecision !== null) {
            $decision_set = $this->conf->decision_set();
            if (is_int($idecision) && $decision_set->contains($idecision)) {
                $xpj->decision = $idecision;
            } else if (is_string($idecision)) {
                foreach ($decision_set as $dec) {
                    if (strcasecmp($dec->name, $idecision) === 0)
                        $xpj->decision = $dec->id;
                }
                if (!isset($xpj->decision)
                    && preg_match('/\A(?:unknown|undecided|none|\?|)\z/i', $idecision)) {
                    $xpj->decision = 0;
                }
            }
            if (!isset($xpj->decision)) {
                if (is_string($idecision) || is_int($idecision)) {
                    $this->warning_at("decision", "<0>Unknown decision ‘{$idecision}’");
                } else {
                    $this->syntax_error_at("decision", $idecision);
                }
            }
        }

        // Fields
        $ioptions = (object) [];
        if (isset($ipj->options)) {
            if (is_associative_array($ipj->options) || is_object($ipj->options)) {
                $ioptions = (object) $ipj->options;
            } else if (is_array($ipj->options)
                       && count($ipj->options) == 1
                       && is_object($ipj->options[0])) {
                $ioptions = $ipj->options[0];
            } else {
                $this->syntax_error_at("options", $ipj->options);
            }
        }

        $ikeys = [];
        foreach ($this->prow->form_fields() as $o) {
            if (!$this->user->allow_view_option($this->prow, $o)) {
                continue;
            }
            $k = $xk = $o->json_key();
            $j = $ipj->$xk ?? $ioptions->$xk ?? null;
            if ($j === null) {
                $xk = $o->field_key();
                $j = $ipj->$xk ?? $ioptions->$xk ?? null;
                if ($j === null) {
                    $xk = (string) $o->id;
                    $j = $ipj->$xk ?? null;
                }
            }
            if ($j !== null) {
                $xpj->$k = $j;
                $ikeys[$xk] = true;
            }
        }

        foreach ((array) $ioptions as $k => $v) {
            if (isset($xpj->$k)
                || isset($ikeys[$k])) {
                continue;
            }
            $omatch = $this->conf->options()->find($k);
            if ($omatch
                && $this->user->allow_view_option($this->prow, $omatch)
                && !isset($xpj->{$omatch->json_key()})) {
                $xpj->{$omatch->json_key()} = $v;
            } else {
                $this->_unknown_fields[] = $k;
            }
        }

        foreach ((array) $ipj as $k => $v) {
            if (isset($xpj->$k)
                || isset($ikeys[$k])
                || isset($xstatus->$k)
                || in_array($k, ["pid", "id", "options", "status", "decision", "reviews", "submission_class"])
                || $k[0] === "_"
                || $k[0] === "\$") {
                continue;
            }
            $omatch = $this->conf->options()->find($k);
            if ($omatch
                && $this->user->allow_view_option($this->prow, $omatch)
                && !isset($xpj->{$omatch->json_key()})) {
                $xpj->{$omatch->json_key()} = $v;
            } else {
                $this->_unknown_fields[] = $k;
            }
        }

        // load previous conflicts
        // old conflicts
        if (!$this->will_insert()) {
            foreach ($this->prow->conflicts(true) as $cflt) {
                $this->_conflict_values[strtolower($cflt->email)] = [$cflt->conflictType, 0, 0];
            }
            if (!$this->user->allow_administer($this->prow)
                && $this->prow->conflict_type($this->user) === CONFLICT_AUTHOR) {
                $this->update_conflict_value($this->user->email, CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
            }
        }

        return $xpj;
    }

    /** @param string $f
     * @param null|int|string $v */
    function save_paperf($f, $v) {
        assert(!isset($this->_paper_upd[$f]));
        $this->_paper_upd[$f] = $v;
    }

    /** @param string $f
     * @param null|int|string $v */
    function update_paperf($f, $v) {
        $this->_paper_upd[$f] = $v;
    }

    /** @param string $f
     * @param null|int|string $v */
    function update_paperf_overflow($f, $v) {
        $this->_paper_overflow_upd[$f] = $v;
    }

    /** @param PaperOption $field */
    function change_at($field) {
        if (!in_array($field, $this->_fdiffs)) {
            $this->_fdiffs[] = $field;
        }
        if (!$this->_documents_changed && $field->has_document()) {
            $this->_documents_changed = true;
        }
        if ($field->id !== PaperOption::CONTACTSID) {
            $this->_noncontacts_changed = true;
        }
    }

    /** @param 'status'|'final_status'|'decision' $field */
    private function status_change_at($field) {
        if (!in_array($field, $this->_xdiffs)) {
            $this->_xdiffs[] = $field;
        }
    }

    /** @return bool */
    function has_change() {
        return !empty($this->_fdiffs) || !empty($this->_xdiffs);
    }

    /** @param string|PaperOption $field
     * @return bool */
    function has_change_at($field) {
        if (is_string($field)) {
            if (in_array($field, $this->_xdiffs)) {
                return true;
            }
            foreach ($this->conf->find_all_fields($field, Conf::MFLAG_OPTION) as $f) {
                if (in_array($f, $this->_fdiffs))
                    return true;
            }
            return false;
        } else {
            return in_array($field, $this->_fdiffs);
        }
    }

    /** @return list<string> */
    function changed_keys() {
        $s = [];
        foreach ($this->_fdiffs as $field) {
            $s[] = $field->json_key();
        }
        foreach ($this->_xdiffs as $field) {
            $s[] = $field;
        }
        return $s;
    }

    /** @return list<PaperOption> */
    function changed_fields() {
        return $this->_fdiffs;
    }

    /** @param PaperOption $opt */
    private function _check_field($pj, $opt) {
        if (!$this->user->can_edit_option($this->prow, $opt)
            && (!$this->user->is_root_user()
                || !isset($pj->{$opt->json_key()}))) {
            return;
        }
        $oj = $pj->{$opt->json_key()} ?? null;
        if ($oj === null) {
            $ov = null;
        } else if ($oj instanceof PaperValue) {
            assert($oj->prow === $this->prow);
            $ov = $oj;
        } else {
            $ov = $opt->parse_json($this->prow, $oj);
        }
        if ($ov !== null) {
            $opt->value_check($ov, $this->user);
            if (!$ov->has_error()) {
                $opt->value_store($ov, $this);
                $this->prow->override_option($ov);
                $this->_field_values[$opt->id] = $ov;
            }
        } else {
            $ov = $this->prow->force_option($opt);
            $opt->value_check($ov, $this->user);
        }
        $ov->append_messages_to($this);
    }

    /** @param PaperValue $ov */
    private function _prepare_save_field($ov) {
        // erroneous values shouldn't be saved
        if ($ov->has_error()) { // XXX should never have errors here
            return;
        }
        // return if no change
        $v1 = $ov->value_list();
        $d1 = $ov->data_list();
        $oldv = $this->prow->base_option($ov->option);
        if ($v1 === $oldv->value_list() && $d1 === $oldv->data_list()) {
            return;
        }
        // option may know how to save itself
        if ($ov->option->value_save($ov, $this)) {
            return;
        }
        // otherwise, save option normal way
        $oid = $ov->option_id();
        $this->change_at($ov->option);
        $this->_option_delid[] = $oid;
        for ($i = 0; $i < count($v1); ++$i) {
            $qv0 = [-1, $oid, $v1[$i], null, null];
            if ($d1[$i] !== null) {
                $qv0[strlen($d1[$i]) < 32768 ? 3 : 4] = $d1[$i];
            }
            $this->_option_ins[] = $qv0;
        }
    }

    private function _prepare_status($pj) {
        // check whether it’s ok to change withdrawn status
        $old_withdrawn = $this->prow->timeWithdrawn > 0;
        $pj_withdrawn = $pj->status->withdrawn;
        if ($pj_withdrawn !== $old_withdrawn) {
            if ($pj_withdrawn) {
                $whynot = $this->user->perm_withdraw_paper($this->prow);
            } else {
                $whynot = $this->user->perm_revive_paper($this->prow);
            }
            if ($whynot) {
                $whynot->append_to($this, "status.withdrawn", 2);
                $pj_withdrawn = $old_withdrawn;
            }
        }

        // check whether it’s ok to change submitted status
        $old_submitted = $this->prow->submitted_at() > 0;
        $pj_submitted = $pj_withdrawn
            ? $old_submitted
            : $pj->status->submitted && (!$this->has_error() || $old_submitted);
        if ($pj_submitted !== $old_submitted
            || $this->_noncontacts_changed) {
            $whynot = $this->user->perm_edit_paper($this->prow);
            if ($whynot
                && $pj_submitted
                && !$this->_noncontacts_changed) {
                $whynot = $this->user->perm_finalize_paper($this->prow);
            }
            if ($whynot) {
                $whynot->append_to($this, "status.submitted", 3);
                $pj_submitted = $old_submitted;
            }
        }

        // mark whether submitted
        $this->_paper_submitted = $pj_submitted && !$pj_withdrawn;

        // return if no change
        if ($pj_withdrawn === $old_withdrawn
            && $pj_submitted === $old_submitted) {
            return;
        }

        // check times
        if ($pj_withdrawn) {
            $withdrawn_at = $this->prow->timeWithdrawn;
            if (!$old_withdrawn
                && isset($pj->status->withdrawn_at)
                && $this->user->privChair) {
                $withdrawn_at = $pj->status->withdrawn_at;
            }
            if ($withdrawn_at <= 0) {
                $withdrawn_at = Conf::$now;
            }
        } else {
            $withdrawn_at = 0;
        }

        if ($pj_submitted) {
            $submitted_at = $this->prow->submitted_at();
            if (!$old_submitted
                && isset($pj->status->submitted_at)
                && $this->user->privChair) {
                $submitted_at = $pj->status->submitted_at;
            }
            if ($submitted_at <= 0
                || $submitted_at === PaperInfo::SUBMITTED_AT_FOR_WITHDRAWN) {
                $submitted_at = Conf::$now;
            }
        } else {
            $submitted_at = 0;
        }
        if ($pj_withdrawn) {
            $submitted_at = -$submitted_at;
        }

        $this->save_paperf("timeWithdrawn", $withdrawn_at);
        $this->save_paperf("timeSubmitted", $submitted_at);
        if ($pj_withdrawn
            && isset($pj->status->withdraw_reason)) {
            $this->save_paperf("withdrawReason", UnicodeHelper::utf8_truncate_invalid(substr($pj->status->withdraw_reason, 0, 1024)));
        }
        $this->status_change_at("status");
    }

    private function _prepare_final_status($pj) {
        if (!isset($pj->status->final_submitted)) {
            return;
        }

        $old_finalsub = ($this->prow->timeFinalSubmitted ?? 0) > 0;
        $pj_finalsub = $pj->status->final_submitted;
        if ($pj_finalsub !== $old_finalsub
            && ($whynot = $this->user->perm_edit_final_paper($this->prow))) {
            $whynot->append_to($this, "final_status", 3);
            $finalsub = $old_finalsub;
        }

        if ($pj_finalsub === $old_finalsub) {
            return;
        }

        if ($pj_finalsub) {
            $finalsub_at = $this->prow->timeFinalSubmitted ?? 0;
            if (!$old_finalsub
                && isset($pj->status->final_submitted_at)
                && $this->user->privChair) {
                $finalsub_at = $pj->status->final_submitted_at;
            }
            if ($finalsub_at <= 0) {
                $finalsub_at = Conf::$now;
            }
        } else {
            $finalsub_at = 0;
        }
        $this->save_paperf("timeFinalSubmitted", $finalsub_at);
        $this->status_change_at("final_status");
    }

    private function _prepare_decision($pj) {
        if (!isset($pj->decision)
            || !$this->user->can_set_decision($this->prow)) {
            return;
        }
        if ($this->prow->outcome !== $pj->decision) {
            $this->save_paperf("outcome", $pj->decision);
            $this->status_change_at("decision");
        }
    }

    /** @param int $bit */
    function clear_conflict_values($bit) {
        foreach ($this->_conflict_values as &$cv) {
            if (((($cv[0] & ~$cv[1]) | $cv[2]) & $bit) !== 0) {
                $cv[1] |= $bit;
                $cv[2] &= ~$bit;
            }
        }
    }

    /** @param string $email
     * @param int $mask
     * @param int $new
     * @return bool */
    function update_conflict_value($email, $mask, $new) {
        $lemail = strtolower($email);
        assert(($new & $mask) === $new);
        if (!isset($this->_conflict_values[$lemail])) {
            $this->_conflict_values[$lemail] = [0, 0, 0];
        }
        $cv = &$this->_conflict_values[$lemail];
        if ($mask !== (CONFLICT_PCMASK & ~1)
            || ((($cv[0] & ~$cv[1]) | $cv[2]) & 1) === 0) {
            $cv[1] |= $mask;
            $cv[2] = ($cv[2] & ~$mask) | $new;
        }
        // return true iff `$mask` bits have changed
        return ($cv[0] & $mask) !== ((($cv[0] & ~$cv[1]) | $cv[2]) & $mask);
    }

    function register_user(Author $au) {
        foreach ($this->_register_users ?? [] as $rau) {
            if (strcasecmp($rau->email, $au->email) === 0) {
                $rau->merge($au);
                $rau->author_index = $rau->author_index ?? $au->author_index;
                return;
            }
        }
        $this->_register_users[] = clone $au;
    }

    /** @param ?array{int,int,int} $cv
     * @return int */
    static private function new_conflict_value($cv) {
        return $cv ? ($cv[0] & ~$cv[1]) | $cv[2] : 0;
    }

    /** @param ?array<string,int> $lemail_to_cid
     * @return bool */
    private function has_conflict_diff($lemail_to_cid = null) {
        foreach ($this->_conflict_values ?? [] as $lemail => $cv) {
            if ($cv[0] !== self::new_conflict_value($cv)
                && ($lemail_to_cid === null || isset($lemail_to_cid[$lemail]))) {
                return true;
            }
        }
        return false;
    }

    /** @param Author $au */
    private function create_user($au) {
        $conflictType = self::new_conflict_value($this->_conflict_values[strtolower($au->email)] ?? null);
        $j = $au->unparse_nea_json();
        $j["disablement"] = ($this->disable_users ? Contact::DISABLEMENT_USER : 0)
            | ($conflictType & CONFLICT_CONTACTAUTHOR ? 0 : Contact::DISABLEMENT_PLACEHOLDER);
        $u = Contact::make_keyed($this->conf, $j)->store(0, $this->user);
        if ($u) {
            $this->_created_contacts[] = $u;
            if (($conflictType & CONFLICT_CONTACTAUTHOR) !== 0) {
                $this->change_at($this->conf->option_by_id(PaperOption::CONTACTSID));
            }
        } else if (($conflictType & CONFLICT_CONTACTAUTHOR) !== 0) {
            if ($au->author_index >= 0) {
                $key = "contacts:{$au->author_index}";
            } else {
                $key = "contacts";
            }
            $this->error_at($key, $this->_("<0>Could not create an account for contact %s", Text::nameo_h($au, NAME_E)));
            $this->error_at("contacts", null);
        }
    }

    private function _check_contacts_last($pj) {
        // create new contacts
        if (!$this->has_error_at("authors")
            && !$this->has_error_at("contacts")) {
            foreach ($this->_register_users ?? [] as $au) {
                $this->create_user($au);
            }
        }

        // exit if no change
        if ($this->has_error_at("authors")
            || $this->has_error_at("contacts")
            || !$this->has_conflict_diff()) {
            return;
        }

        // load email => cid map
        // NB: callers must have taken care of primaryContactId resolution
        $lemail_to_cid = [];
        $result = $this->conf->qe("select contactId, email from ContactInfo where email?a", array_keys($this->_conflict_values));
        while (($row = $result->fetch_row())) {
            $lemail_to_cid[strtolower($row[1])] = (int) $row[0];
        }
        Dbl::free($result);

        // save diffs if change
        if ($this->has_conflict_diff($lemail_to_cid)) {
            $this->_conflict_ins = $this->_author_change_cids = [];
            $pcc_changed = false;
            foreach ($this->_conflict_values as $lemail => $cv) {
                if (($cid = $lemail_to_cid[$lemail] ?? null)) {
                    $ncv = self::new_conflict_value($cv);
                    if (($cv[0] ^ $ncv) & CONFLICT_PCMASK) {
                        $pcc_changed = true;
                    }
                    if (($cv[0] >= CONFLICT_AUTHOR) !== ($ncv >= CONFLICT_AUTHOR)) {
                        $this->_author_change_cids[] = $cid;
                    }
                    $this->_conflict_ins[] = [$cid, $cv[1], $cv[2]];
                }
            }
            if (!empty($this->_author_change_cids)) {
                $this->change_at($this->conf->option_by_id(PaperOption::CONTACTSID));
            }
            if ($pcc_changed) {
                $this->change_at($this->conf->option_by_id(PaperOption::PCCONFID));
            }
        }
    }

    private function _ensure_creator_contact() {
        // if creating a paper, user must always be contact
        if ($this->will_insert() && $this->user->contactId > 0) {
            // NB ok to have multiple inserters for same user
            $this->_conflict_ins = $this->_conflict_ins ?? [];
            $this->_conflict_ins[] = [$this->user->contactId, CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR];
            $this->change_at($this->conf->option_by_id(PaperOption::CONTACTSID));
        }
    }

    private function _reset(PaperInfo $prow) {
        assert($prow->paperId !== -1);
        parent::clear();
        $this->prow = $prow;
        $this->paperId = $this->title = null;
        $this->_desired_pid = $prow->paperId !== 0 ? $prow->paperId : null;
        $this->_fdiffs = $this->_xdiffs = [];
        $this->_paper_upd = $this->_paper_overflow_upd = [];
        $this->_unknown_fields = null;
        $this->_topic_ins = null;
        $this->_field_values = $this->_option_delid = $this->_option_ins = [];
        $this->_conflict_values = [];
        $this->_conflict_ins = $this->_register_users = $this->_created_contacts = null;
        $this->_author_change_cids = null;
        $this->_paper_submitted = $this->_documents_changed = $this->_noncontacts_changed = false;
        $this->_update_pid_dids = $this->_joindocs = [];
        $this->_save_status = 0;
    }

    /** @param ?PaperInfo $prow
     * @param 'submit'|'update'|'final'|'updatecontacts' $action
     * @return bool */
    function prepare_save_paper_web(Qrequest $qreq, $prow, $action) {
        $this->_reset($prow ?? PaperInfo::make_new($this->user, $qreq->sclass));
        $pj = (object) [];

        // Status
        $updatecontacts = $action === "updatecontacts";
        if ($action === "submit"
            || ($action === "update" && $qreq->submitpaper)) {
            $pj->submitted = true;
            $pj->draft = false;
        } else if ($action === "final") {
            $pj->final_submitted = $pj->submitted = true;
            $pj->draft = false;
        } else if (!$updatecontacts && $qreq->has_submitpaper) {
            $pj->submitted = false;
            $pj->draft = true;
        }

        // Fields
        foreach ($this->prow->form_fields() as $o) {
            if (($qreq["has_{$o->formid}"] || isset($qreq[$o->formid]))
                && (!$o->final || $action === "final")
                && (!$updatecontacts || $o->id === PaperOption::CONTACTSID)) {
                // XXX test_editable
                $pj->{$o->json_key()} = $o->parse_qreq($this->prow, $qreq);
            }
        }

        return $this->_finish_prepare($pj);
    }

    /** @param object $pj
     * @return bool */
    function prepare_save_paper_json($pj) {
        assert(is_object($pj));

        $pid = $pj->pid ?? $pj->id ?? null;
        if ($pid !== null && !is_int($pid) && $pid !== "new") {
            $key = isset($pj->pid) ? "pid" : "id";
            $this->syntax_error_at($key, $pid);
            return false;
        }
        if ($pid === "new" || (is_int($pid) && $pid <= 0)) {
            $pid = null;
        }

        if (($pj->error ?? null) || ($pj->error_html ?? null)) {
            $this->error_at("error", $this->_("<0>Refusing to save submission with error"));
            return false;
        }

        $prow = null;
        if ($pid !== null) {
            $prow = $this->conf->paper_by_id($pid, $this->user, ["topics" => true, "options" => true]);
        }
        $this->_reset($prow ?? PaperInfo::make_new($this->user, $pj->submission_class ?? null));
        $this->_desired_pid = $pid;
        assert($this->prow->paperId === 0 || $this->prow->paperId === $pid);

        return $this->_finish_prepare($pj);
    }

    /** @param object $pj
     * @return bool */
    private function _finish_prepare($pj) {
        $ok = $this->_normalize_and_check($pj);
        $this->prow->remove_option_overrides();
        return $ok;
    }

    /** @param object $pj
     * @return bool */
    private function _normalize_and_check($pj) {
        assert($this->_save_status === 0);
        if (($perm = $this->user->perm_view_paper($this->prow, false, $this->_desired_pid ?? "new"))) {
            $perm->append_to($this, null, MessageSet::ESTOP);
            return false;
        }

        // normalize and check format
        $pj = $this->_normalize($pj);
        if ($this->has_error()) {
            return false;
        }
        $this->prow->set_want_submitted($pj->status->submitted && !$pj->status->withdrawn);

        // check fields
        foreach ($this->prow->form_fields() as $opt) {
            $this->_check_field($pj, $opt);
        }
        if (!empty($this->_unknown_fields)) {
            natcasesort($this->_unknown_fields);
            $this->warning_at("options", $this->_("<0>Ignoring unknown fields {:list}", $this->_unknown_fields));
        }
        if ($this->problem_status() >= MessageSet::ESTOP) {
            return false;
        }

        // prepare fields for saving, mark which fields have changed
        foreach ($this->_field_values ?? [] as $ov) {
            $this->_prepare_save_field($ov);
        }

        // prepare non-fields for saving
        $this->_prepare_status($pj);
        $this->_prepare_final_status($pj);
        $this->_prepare_decision($pj);
        if ($this->problem_status() >= MessageSet::ESTOP) {
            return false;
        }

        // correct blindness setting
        if ($this->conf->submission_blindness() !== Conf::BLIND_OPTIONAL) {
            $want_blind = $this->conf->submission_blindness() !== Conf::BLIND_NEVER;
            if ($this->prow->blind !== $want_blind) {
                $this->save_paperf("blind", $want_blind ? 1 : 0);
                $this->change_at($this->conf->option_by_id(PaperOption::ANONYMITYID));
            }
        }

        // don't save if creating a mostly-empty paper
        if ($this->will_insert()
            && !array_diff(array_keys($this->_paper_upd), ["authorInformation", "blind"])
            && (!isset($this->_paper_upd["authorInformation"])
                || $this->_paper_upd["authorInformation"] === (new Author($this->user))->unparse_tabbed())
            && empty($this->_topic_ins)
            && empty($this->_option_ins)) {
            $this->error_at(null, "<0>Empty submission. Please fill out the submission fields and try again");
            return false;
        }

        // validate contacts
        $this->_check_contacts_last($pj);
        $this->_ensure_creator_contact();
        $this->_save_status |= self::SAVE_STATUS_PREPARED;
        return true;
    }


    private function _update_joindoc() {
        $old_joindoc = $this->prow->primary_document();
        $old_joinid = $old_joindoc ? $old_joindoc->paperStorageId : 0;

        $new_joinid = $this->_paper_upd["finalPaperStorageId"] ?? $this->prow->finalPaperStorageId;
        if ($new_joinid <= 1) {
            $new_joinid = $this->_paper_upd["paperStorageId"] ?? $this->prow->paperStorageId;
        }

        if ($new_joinid === $old_joinid) {
            return;
        }

        $new_joindoc = null;
        foreach ($this->_joindocs as $doc) {
            if ($doc->paperStorageId == $new_joinid)
                $new_joindoc = $doc;
        }

        if ($new_joindoc) {
            $this->save_paperf("size", $new_joindoc->size());
            $this->save_paperf("mimetype", $new_joindoc->mimetype);
            $this->save_paperf("sha1", $new_joindoc->binary_hash());
            $this->save_paperf("timestamp", $new_joindoc->timestamp);
        } else {
            $this->save_paperf("size", -1);
            $this->save_paperf("mimetype", "");
            $this->save_paperf("sha1", "");
            $this->save_paperf("timestamp", 0);
        }
        $this->save_paperf("pdfFormatStatus", 0);
    }

    /** @return int */
    private function unused_random_pid() {
        $n = max(100, 3 * $this->conf->fetch_ivalue("select count(*) from Paper"));
        while (true) {
            $pids = [];
            while (count($pids) < 10) {
                $pids[mt_rand(1, $n)] = true;
            }

            $result = $this->conf->qe("select paperId from Paper where paperId?a", array_keys($pids));
            while (($row = $result->fetch_row())) {
                unset($pids[(int) $row[0]]);
            }
            Dbl::free($result);

            if (!empty($pids)) {
                return (array_keys($pids))[0];
            }
        }
    }

    /** @return bool */
    private function _execute_insert() {
        assert($this->prow->paperId === 0);
        // set default columns
        foreach (["title", "authorInformation", "abstract"] as $f) {
            if (!isset($this->_paper_upd[$f]))
                $this->_paper_upd[$f] = "";
        }
        // prepare pid
        $random_pids = $this->_desired_pid === null && $this->conf->setting("random_pids");
        if ($random_pids) {
            $this->conf->qe("lock tables Paper write");
            $this->_desired_pid = $this->unused_random_pid();
        }
        if ($this->_desired_pid !== null) {
            $this->_paper_upd["paperId"] = $this->_desired_pid;
        }
        // insert
        $result = $this->conf->qe_apply("insert into Paper set " . join("=?, ", array_keys($this->_paper_upd)) . "=?", array_values($this->_paper_upd));
        if ($random_pids) {
            $this->conf->qe("unlock tables");
        }
        if ($result->is_error() || !$result->insert_id) {
            $this->error_at(null, $this->_("<0>Could not create submission"));
            return false;
        }
        $this->paperId = (int) $result->insert_id;
        $this->_save_status |= self::SAVE_STATUS_NEW;
        // save initial tags
        if (($t = $this->prow->all_tags_text()) !== "") {
            $qv = [];
            foreach (Tagger::split_unpack($t) as $ti) {
                $qv[] = [$this->paperId, $ti[0], $ti[1]];
            }
            $this->conf->qe("insert into PaperTag (paperId, tag, tagIndex) values ?v", $qv);
        }
        return true;
    }

    /** @return bool */
    private function _execute_update() {
        assert($this->prow->paperId !== 0
               && $this->prow->paperId !== -1
               && $this->prow->paperId === $this->_desired_pid);
        $qv = array_values($this->_paper_upd);
        $qv[] = $this->_desired_pid;
        $result = $this->conf->qe_apply("update Paper set " . join("=?, ", array_keys($this->_paper_upd)) . "=? where paperId=?", $qv);
        if ($result->is_error()) {
            $this->error_at(null, $this->_("<0>Could not create submission"));
            return false;
        } else if ($result->affected_rows === 0
                   && !$this->conf->fetch_ivalue("select exists(select * from Paper where paperId=?) from dual", $this->_desired_pid)) {
            $this->error_at(null, $this->_("<0>Submission #{} has been deleted", $this->_desired_pid));
            return false;
        }
        $this->paperId = $this->_desired_pid;
        return true;
    }

    private function _execute_topics() {
        if (isset($this->_topic_ins)) {
            $this->conf->qe("delete from PaperTopic where paperId=?", $this->paperId);
            if (!empty($this->_topic_ins)) {
                $ti = [];
                foreach ($this->_topic_ins as $tid) {
                    $ti[] = [$this->paperId, $tid];
                }
                $this->conf->qe("insert into PaperTopic (paperId,topicId) values ?v", $ti);
            }
        }
    }

    private function _execute_options() {
        if (!empty($this->_option_delid)) {
            $this->conf->qe("delete from PaperOption where paperId=? and optionId?a", $this->paperId, $this->_option_delid);
        }
        if (!empty($this->_option_ins)) {
            foreach ($this->_option_ins as &$x) {
                $x[0] = $this->paperId;
            }
            $this->conf->qe("insert into PaperOption (paperId, optionId, value, data, dataOverflow) values ?v", $this->_option_ins);
        }
    }

    private function _execute_conflicts() {
        if (!empty($this->_conflict_ins)) {
            // insert conflicts
            $cfltf = Dbl::make_multi_query_stager($this->conf->dblink, Dbl::F_ERROR);
            $auflags = CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR;
            foreach ($this->_conflict_ins as $ci) {
                if (($ci[1] & CONFLICT_PCMASK) === (CONFLICT_PCMASK & ~1)) {
                    $cfltf("insert into PaperConflict set paperId=?, contactId=?, conflictType=? on duplicate key update conflictType=if(conflictType&1,((conflictType&~?)|?),((conflictType&~?)|?))",
                        $this->paperId, $ci[0], $ci[2],
                        $ci[1] & $auflags, $ci[2] & $auflags, $ci[1], $ci[2]);
                } else {
                    $cfltf("insert into PaperConflict set paperId=?, contactId=?, conflictType=? on duplicate key update conflictType=((conflictType&~?)|?)",
                        $this->paperId, $ci[0], $ci[2], $ci[1], $ci[2]);
                }
            }
            $cfltf("delete from PaperConflict where paperId=? and conflictType=0", $this->paperId);
            $cfltf(null);
        }

        if (!empty($this->_author_change_cids)
            && $this->conf->contactdb()) {
            // update author records in contactdb
            $this->conf->prefetch_users_by_id($this->_author_change_cids);
            $emails = [];
            foreach ($this->_author_change_cids as $cid) {
                if (($u = $this->conf->user_by_id($cid, USER_SLICE)))
                    $emails[] = $u->email;
            }
            $this->conf->prefetch_cdb_users_by_email($emails);
            foreach ($this->_author_change_cids as $cid) {
                if (($u = $this->conf->user_by_id($cid, USER_SLICE)))
                    $u->update_cdb_roles();
            }
        }

        if ($this->_created_contacts !== null) {
            // send mail to new contacts
            $rest = ["prow" => $this->conf->paper_by_id($this->paperId)];
            if ($this->user->can_administer($rest["prow"])
                && !$rest["prow"]->has_author($this->user)) {
                $rest["adminupdate"] = true;
            }
            foreach ($this->_created_contacts as $u) {
                if ($u->password_unset()
                    && !$u->activity_at
                    && !$u->isPC
                    && !$u->is_dormant()) {
                    $u->send_mail("@newaccount.paper", $rest);
                }
            }
        }
    }

    private function _postexecute_check_required_options() {
        $prow = $this->conf->paper_by_id($this->paperId, $this->user, ["options" => true]);
        $required_failure = false;
        foreach ($prow->form_fields() as $o) {
            if ($o->required
                && $this->user->can_edit_option($prow, $o)) {
                $ov = $prow->force_option($o);
                if (!$o->value_check_required($ov)) {
                    $ov->append_messages_to($this);
                    $required_failure = true;
                }
            }
        }
        if ($required_failure && $this->prow->timeSubmitted <= 0) {
            // Some required option was missing and the paper was not submitted
            // before, so it shouldn't be submitted now.
            $this->conf->qe("update Paper set timeSubmitted=? where paperId=?",
                            $this->prow->timeSubmitted, $this->paperId);
            $this->_paper_submitted = false;
        }
    }

    /** @return bool */
    function execute_save() {
        // refuse to save if not prepared
        if ($this->_save_status !== self::SAVE_STATUS_PREPARED) {
            throw new ErrorException("Refusing to save paper with errors");
        }
        assert($this->paperId === null);
        $this->_save_status = self::SAVE_STATUS_SAVED;

        $dataOverflow = $this->prow->dataOverflow;
        if (!empty($this->_paper_overflow_upd)) {
            $dataOverflow = $dataOverflow ?? [];
            $old_value = empty($dataOverflow) ? null : json_encode_db($dataOverflow);
            foreach ($this->_paper_overflow_upd as $k => $v) {
                if ($v === null) {
                    unset($dataOverflow[$k]);
                } else {
                    $dataOverflow[$k] = $v;
                }
            }
            $new_value = empty($dataOverflow) ? null : json_encode_db($dataOverflow);
            if ($new_value !== $old_value) {
                $this->_paper_upd["dataOverflow"] = $new_value;
            }
        }

        if ($this->will_insert() || !empty($this->_paper_upd)) {
            $this->save_paperf("timeModified", Conf::$now);
            if (isset($this->_paper_upd["paperStorageId"])
                || isset($this->_paper_upd["finalPaperStorageId"])) {
                $this->_update_joindoc();
            }
            if ($this->will_insert()
                ? !$this->_execute_insert()
                : !$this->_execute_update()) {
                return false;
            }
        } else {
            assert($this->prow->paperId === $this->_desired_pid);
            $this->paperId = $this->prow->paperId;
        }
        assert($this->paperId !== null);

        $this->_execute_topics();
        $this->_execute_options();
        $this->_execute_conflicts();

        if ($this->_paper_submitted) {
            $this->_postexecute_check_required_options();
        }
        if (!empty($this->_update_pid_dids)) {
            $this->conf->qe("update PaperStorage set paperId=? where paperStorageId?a", $this->paperId, $this->_update_pid_dids);
        }

        // maybe update `papersub` settings
        $was_submitted = $this->prow->timeWithdrawn <= 0
            && $this->prow->timeSubmitted > 0;
        if ($this->_paper_submitted != $was_submitted) {
            $this->conf->update_papersub_setting($this->_paper_submitted ? 1 : -1);
        }

        // track submit-type flags
        if ($this->_paper_submitted) {
            $this->_save_status |= self::SAVE_STATUS_SUBMIT;
            if (!$was_submitted) {
                $this->_save_status |= self::SAVE_STATUS_NEWSUBMIT;
            }
        }
        if (isset($this->_paper_upd["timeFinalSubmitted"])
            ? $this->_paper_upd["timeFinalSubmitted"] > 0
            : $this->prow->timeFinalSubmitted > 0) {
            $this->_save_status |= self::SAVE_STATUS_FINALSUBMIT;
        }

        // update automatic tags
        $this->conf->update_automatic_tags($this->paperId, "paper");

        // update document inactivity
        if ($this->_documents_changed
            && ($prow = $this->conf->paper_by_id($this->paperId, null, ["options" => true]))) {
            $prow->mark_inactive_documents();
        }

        // The caller should not use `$this->prow` any more, but in case they
        // do (e.g. in old tests), invalidate it when convenient.
        $this->prow->invalidate_options();
        $this->prow->invalidate_conflicts();
        $this->title = $this->_paper_upd["title"] ?? $this->prow->title;
        $this->prow = null;
        return true;
    }

    function log_save_activity(Contact $user, $action, $via = null) {
        // log message
        assert(($this->_save_status & self::SAVE_STATUS_SAVED) !== 0);
        $actions = [];
        if (($this->_save_status & self::SAVE_STATUS_NEW) !== 0) {
            $actions[] = "started";
        }
        if (($this->_save_status & self::SAVE_STATUS_NEWSUBMIT) !== 0) {
            $actions[] = "submitted";
        } else if (($this->_save_status & self::SAVE_STATUS_NEW) === 0
                   && (!empty($this->_fdiffs) || !empty($this->_xdiffs))) {
            $actions[] = "edited";
        }
        if (empty($actions)) {
            $actions[] = "saved";
        }
        $logtext = "Paper " . join(", ", $actions);
        if ($action === "final") {
            $logtext .= " final";
            if (($this->_save_status & self::SAVE_STATUS_FINALSUBMIT) === 0) {
                $logtext .= " draft";
            }
        } else if (($this->_save_status & self::SAVE_STATUS_SUBMIT) === 0) {
            $logtext .= " draft";
        }
        if ($via) {
            $logtext .= " " . trim($via);
        }
        if (!empty($this->_fdiffs) || !empty($this->_xdiffs)) {
            $logtext .= ": " . join(", ", $this->changed_keys());
        }
        $user->log_activity($logtext, $this->paperId);
    }

    /** @param object $pj
     * @return int|false */
    function save_paper_json($pj) {
        if ($this->prepare_save_paper_json($pj)) {
            $this->execute_save();
            return $this->paperId;
        } else {
            return false;
        }
    }

    /** @param ?PaperInfo $prow
     * @param string $action
     * @return int|false */
    function save_paper_web(Qrequest $qreq, $prow, $action) {
        if ($this->prepare_save_paper_web($qreq, $prow, $action)) {
            $this->execute_save();
            return $this->paperId;
        } else {
            return false;
        }
    }

    /** @return int */
    function save_status() {
        return $this->_save_status;
    }
}
