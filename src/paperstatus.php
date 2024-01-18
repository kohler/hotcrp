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
    private $disable_users = false;
    /** @var bool */
    private $any_content_file = false;
    /** @var bool */
    private $allow_hash_without_content = false;
    /** @var bool */
    private $notify = true;
    /** @var int */
    private $doc_savef = 0;
    /** @var list<callable> */
    private $_on_document_import = [];

    /** @var ?PaperInfo */
    private $prow;
    /** @var int */
    public $paperId;
    /** @var ?string */
    public $title;
    /** @var ?list<int> */
    private $_potential_pids;
    /** @var ?list<string> */
    private $_unknown_fields;
    /** @var list<PaperOption> */
    private $_fdiffs;
    /** @var list<string> */
    private $_xdiffs;
    /** @var associative-array<int,PaperValue> */
    private $_field_values;
    /** @var ?list<int> */
    private $_option_delid;
    /** @var ?list<list> */
    private $_option_ins;
    /** @var associative-array<int,array{int,int,int}> */
    private $_conflict_values;
    /** @var ?list<array{int,int,int}> */
    private $_conflict_ins;
    /** @var ?list<int> */
    private $_author_change_cids;
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
    const SAVE_STATUS_WASFINAL = 64;

    function __construct(Contact $user, $options = []) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->allow_hash_without_content = $user->privChair;
        $this->set_want_ftext(true, 5);
        $this->set_ignore_duplicates(true);
    }

    /** @return PaperStatus */
    static function make_prow(Contact $user, PaperInfo $prow) {
        $ps = new PaperStatus($user);
        $ps->prow = $prow;
        return $ps;
    }

    /** @param bool $x
     * @return $this */
    function set_disable_users($x) {
        $this->disable_users = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_notify($x) {
        $this->notify = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_any_content_file($x) {
        $this->any_content_file = $x;
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_skip_document_verify($x) {
        if ($x) {
            $this->doc_savef |= DocumentInfo::SAVEF_SKIP_VERIFY;
        } else {
            $this->doc_savef &= ~DocumentInfo::SAVEF_SKIP_VERIFY;
        }
        return $this;
    }

    /** @param bool $x
     * @return $this */
    function set_skip_document_content($x) {
        if ($x) {
            $this->doc_savef |= DocumentInfo::SAVEF_SKIP_CONTENT;
        } else {
            $this->doc_savef &= ~DocumentInfo::SAVEF_SKIP_CONTENT;
        }
        return $this;
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

    /** @param string $in
     * @return string */
    function _($in, ...$args) {
        return $this->conf->_($in, ...$args);
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
     * @return MessageItem */
    function syntax_error_at($key) {
        return $this->error_at($key, "<0>Validation error [{$key}]");
    }

    /** @param ?list<int> $oids
     * @return list<MessageItem> */
    function decorated_message_list($oids = null) {
        $ms = [];
        foreach ($this->message_list() as $mi) {
            if (($mi->field ?? "") !== ""
                && (str_ends_with($mi->field, ":context") || $mi->status === MessageSet::INFORM)
                && !str_starts_with($mi->field, "status:")) {
                // do not report in decorated list
            } else if ($mi->field
                       && $mi->message !== ""
                       && ($o = $this->conf->options()->option_by_field_key($mi->field))) {
                if ($oids === null || in_array($o->id, $oids)) {
                    $link = Ht::link(htmlspecialchars($o->edit_title()), "#" . $o->readable_formid());
                    $ms[] = $mi->with(["message" => "<5>{$link}: " . $mi->message_as(5)]);
                }
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


    /** @return ?DocumentInfo */
    function upload_document($docj, PaperOption $o) {
        // $docj can be a DocumentInfo or a JSON.
        // If it is a JSON, its format is set by document_to_json.
        if (is_array($docj) && count($docj) === 1 && isset($docj[0])) {
            $docj = $docj[0];
        }
        if (!is_object($docj)) {
            $this->syntax_error_at($o->field_key());
            return null;
        } else if (($docj->error ?? false) || ($docj->error_html ?? false)) {
            $this->error_at_option($o, "<5>" . ($docj->error_html ?? "Upload error"));
            return null;
        }
        assert(!isset($docj->filter));

        // check content_file
        if (!$this->any_content_file
            && !($docj instanceof DocumentInfo)
            && isset($docj->content_file)
            && is_string($docj->content_file)
            && preg_match('/\A\/|(?:\A|\/)\.\.(?:\/|\z)/', $docj->content_file)) {
            $this->error_at_option($o, "<0>Bad content_file: only simple filenames allowed");
            return null;
        }

        // check on_document_import
        foreach ($this->_on_document_import as $cb) {
            if (call_user_func($cb, $docj, $o, $this) === false)
                return null;
        }

        // validate JSON
        if ($docj instanceof DocumentInfo) {
            $doc = $docj;
        } else if (!($doc = $this->_upload_json_document($docj, $o))) {
            return null;
        }

        // save
        if ($doc->paperStorageId === 0
            && ($doc->has_error() || !$doc->save($this->doc_savef))) {
            foreach ($doc->message_list() as $mi) {
                $mi = $this->msg_at_option($o, $mi->message, $mi->status);
                $mi->landmark = $doc->error_filename();
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

    /** @param object $docj
     * @return ?DocumentInfo */
    private function _upload_json_document($docj, PaperOption $o) {
        // extract mimetype
        $mimetype = null;
        if (isset($docj->mimetype) && is_string($docj->mimetype)) {
            $mimetype = $docj->mimetype;
        }

        // extract content
        $content = $content_file = null;
        if (isset($docj->content) && is_string($docj->content)) {
            $content = $docj->content;
        } else if (isset($docj->content_base64) && is_string($docj->content_base64)) {
            $content = base64_decode($docj->content_base64);
        } else if (isset($docj->content_file) && is_string($docj->content_file)) {
            $content_file = $docj->content_file;
        } else if (isset($docj->content_file) && is_resource($docj->content_file)) {
            if (!($content_file = $this->_upload_content_stream($docj->content_file, $mimetype, $o))) {
                $this->warning_at_option($o, "<0>Could not copy `content_file` to a temporary file");
            }
        }

        // extract requested hash
        $ha = $want_algorithm = null;
        if (isset($docj->hash) && is_string($docj->hash)) {
            $ha = new HashAnalysis($docj->hash);
        } else if (isset($docj->sha1) && is_string($docj->sha1)) {
            $ha = new HashAnalysis($docj->sha1);
            $want_algorithm = "sha1";
        }
        if ($ha && (!$ha->ok() || ($want_algorithm && $ha->algorithm() !== $want_algorithm))) {
            $this->warning_at_option($o, "<0>Invalid `hash` ignored");
            $ha = null;
        }

        // compute content hash
        $content_ha = HashAnalysis::make_algorithm($this->conf, $ha ? $ha->algorithm() : null);
        if (($this->doc_savef & DocumentInfo::SAVEF_SKIP_VERIFY) !== 0) {
            // do not compute content hash
        } else if ($content !== null) {
            $content_ha->set_hash($content);
        } else if ($content_file !== null) {
            $content_ha->set_hash_file($content_file);
        }

        // compare content hash with user-provided hash; error if different
        if ($ha
            && $content_ha->ok()
            && $ha->binary() !== $content_ha->binary()) {
            $this->error_at_option($o, "<0>Corrupt document ignored (its content did not match the provided hash)");
            return null;
        }

        // also check CRC32 if provided
        $crc32 = null;
        if (isset($docj->crc32) && is_string($docj->crc32)) {
            if (strlen($docj->crc32) === 8 && ctype_xdigit($docj->crc32)) {
                $crc32 = hex2bin($docj->crc32);
            } else if (strlen($docj->crc32) === 4 && $docj->crc32 !== "\0\0\0\0") {
                $crc32 = $docj->crc32;
            } else {
                $this->warning_at_option($o, "<0>Invalid `crc32` ignored");
            }
        }
        if ($crc32 !== null) {
            $content_crc32 = false;
            if (($this->doc_savef & DocumentInfo::SAVEF_SKIP_VERIFY) !== 0) {
                // do not compute content hash
            } else if ($content !== null) {
                $content_crc32 = hash("crc32b", $content, true);
            } else if ($content_file !== null) {
                $content_crc32 = hash_file("crc32b", $content_file, true);
            }
            if ($content_crc32 !== false
                && $crc32 !== $content_crc32) {
                $this->error_at_option($o, "<0>Corrupt document ignored (its content did not match the provided checksum)");
                return null;
            }
        }

        // choose a hash
        if ($ha) {
            $hash = $ha->binary();
        } else if ($content_ha->ok()) {
            $hash = $content_ha->binary();
        } else {
            $hash = null;
        }

        // check for existing document
        if (!$this->prow->is_new()
            && isset($docj->docid)
            && is_int($docj->docid)
            && $docj->docid > 0) {
            $qx = [
                "paperId=?" => $this->prow->paperId,
                "documentType=?" => $o->id,
                "paperStorageId=?" => $docj->docid
            ];
            if ($hash !== null) {
                $qx["sha1=?"] = $hash;
            }
            if ($mimetype !== null) {
                $qx["mimetype=?"] = $mimetype;
            }
            $result = $this->conf->qe_apply("select * from PaperStorage where " . join(" and ", array_keys($qx)), array_values($qx));
            $edoc = DocumentInfo::fetch($result, $this->conf, $this->prow);
            Dbl::free($result);
            if ($edoc) {
                return $edoc;
            }
        }

        // content required from here on; fail if it's not available
        if ($content === null
            && $content_file === null
            && (!$this->allow_hash_without_content
                || $hash === null
                || $mimetype === null)) {
            $this->error_at_option($o, "<0>Ignored attempt to upload document without any content");
            return null;
        }

        // make new document
        $doc = DocumentInfo::make($this->conf)
            ->set_paper($this->prow)
            ->set_document_type($o->id);
        if ($mimetype !== null) {
            $doc->set_mimetype($mimetype);
        }
        if (isset($docj->timestamp) && is_int($docj->timestamp)) {
            $doc->set_timestamp($docj->timestamp);
        }
        if (isset($docj->filename) && is_string($docj->filename)) {
            $doc->set_filename(DocumentInfo::sanitize_filename($docj->filename));
        }
        if ($content !== null) {
            $doc->set_simple_content($content);
        } else if ($content_file !== null) {
            $doc->set_simple_content_file($content_file);
        }
        if ($hash !== null) {
            $doc->set_hash($hash);
        }
        if ($crc32 !== null) {
            $doc->set_crc32($crc32);
        }
        if (isset($docj->size)
            && is_int($docj->size)
            && ($this->doc_savef & DocumentInfo::SAVEF_SKIP_CONTENT) !== 0) {
            $doc->set_size($docj->size);
        }

        // analyze content, complain if not available
        if ($doc->content_available() || $doc->ensure_content()) {
            $doc->analyze_content();
        } else {
            $doc->error("<0>Document has no content");
        }

        return $doc;
    }

    /** @return ?string */
    private function _upload_content_stream($f, $mimetype, PaperOption $o) {
        $content_file = null;
        $fp = "upf-" . time() . "-%08d" . Mimetype::extension($mimetype);
        if (($x = Filer::tempfile($fp, $this->conf))) {
            $ok = stream_copy_to_stream($f, $x[0]) !== false;
            fclose($x[0]);
            $content_file = $ok ? $x[1] : null;
        }
        fclose($f);
        return $content_file;
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
                $this->syntax_error_at("status:{$k}");
                $v = null;
            }
            $xstatus->$k = $v;
        }
        if ($xstatus->submitted !== null || $xstatus->draft !== null) {
            $xstatus->draft = $xstatus->draft ?? !$xstatus->submitted;
            $xstatus->submitted = $xstatus->submitted ?? !$xstatus->draft;
        }
        foreach (["submitted_at", "withdrawn_at", "final_submitted_at",
                  "if_unmodified_since"] as $k) {
            $v = $istatus->$k ?? null;
            if (is_numeric($v)) {
                $v = (float) $v;
                if ($v < 0) {
                    $this->error_at("status:{$k}", "<0>Negative date");
                    $v = null;
                }
            } else if (is_string($v)) {
                $v = $this->conf->parse_time($v, Conf::$now);
                if ($v === false || $v < 0) {
                    $this->error_at("status:{$k}", "<0>Parse error in date");
                    $v = null;
                } else {
                    $v = (float) $v;
                }
            } else if ($v === false) {
                $v = 0.0;
            } else if ($v !== null) {
                $this->syntax_error_at("status:{$k}");
            }
            $xstatus->$k = $v;
        }
        $v = $istatus->withdraw_reason ?? null;
        if (is_string($v)) {
            $xstatus->withdraw_reason = $v;
        } else if ($v !== null) {
            $this->syntax_error_at("status:withdraw_reason");
        }
        if (in_array($istatusstr, ["submitted", "accepted", "accept", "deskrejected", "desk_reject", "deskreject", "rejected", "reject"])) {
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
            $this->error_at("status:draft", "<0>Draft status conflicts with submitted status");
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
                    $this->syntax_error_at("decision");
                }
            }
        }

        // XXX Submission class
        // XXX Tags

        // Ignore edit conditions during normalization
        $old_overrides = $this->user->add_overrides(Contact::OVERRIDE_EDIT_CONDITIONS);

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
                $this->syntax_error_at("options");
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
                || in_array($k, ["pid", "id", "options", "status", "decision", "reviews", "comments", "tags", "submission_class"])
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
        if (!$this->prow->is_new()) {
            foreach ($this->prow->conflict_types() as $uid => $ctype) {
                $this->_conflict_values[$uid] = [$ctype, 0, 0];
            }
        }

        $this->user->set_overrides($old_overrides);
        return $xpj;
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
        if ($ov->equals($this->prow->base_option($ov->option))) {
            return;
        }
        // option may know how to save itself
        if ($ov->option->value_save($ov, $this)) {
            return;
        }
        // otherwise, save option normal way
        $this->change_at($ov->option);
        $oid = $ov->option_id();
        $this->_option_delid[] = $oid;
        $d1 = $ov->data_list();
        foreach ($ov->value_list() as $i => $v) {
            $qv0 = [-1, $oid, $v, null, null];
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
                $whynot->append_to($this, "status:withdrawn", 2);
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
            if ($this->prow->is_new()) {
                $whynot = $this->user->perm_start_paper($this->prow);
            } else {
                $whynot = $this->user->perm_edit_paper($this->prow);
            }
            if ($whynot
                && $pj_submitted
                && !$this->_noncontacts_changed) {
                $whynot = $this->user->perm_finalize_paper($this->prow);
            }
            if ($whynot) {
                $whynot->append_to($this, "status:submitted", 3);
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

        $this->prow->set_prop("timeWithdrawn", $withdrawn_at);
        $this->prow->set_prop("timeSubmitted", $submitted_at);
        if ($pj_withdrawn
            && isset($pj->status->withdraw_reason)) {
            $this->prow->set_prop("withdrawReason", UnicodeHelper::utf8_truncate_invalid(substr($pj->status->withdraw_reason, 0, 1024)));
        }
        $this->status_change_at("status");
    }

    private function _prepare_decision($pj) {
        if (!isset($pj->decision)
            || !$this->user->can_set_decision($this->prow)) {
            return;
        }
        if ($this->prow->outcome !== $pj->decision) {
            $this->prow->set_prop("outcome", $pj->decision);
            $this->status_change_at("decision");
        }
    }

    private function _prepare_final_status($pj) {
        if (!isset($pj->status->final_submitted)
            || !$this->conf->allow_final_versions()
            || $this->prow->outcome <= 0
            || !$this->user->can_view_decision($this->prow)
            || /* XXX not exactly the same check as override_deadlines */
               (!$this->conf->time_edit_final_paper()
                && !$this->user->allow_administer($this->prow))) {
            return;
        }

        $old_finalsub = ($this->prow->timeFinalSubmitted ?? 0) > 0;
        $pj_finalsub = $pj->status->final_submitted;
        if ($pj_finalsub !== $old_finalsub
            && ($whynot = $this->user->perm_edit_paper($this->prow))) {
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
        $this->prow->set_prop("timeFinalSubmitted", $finalsub_at);
        $this->status_change_at("final_status");
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

    /** @param Author|Contact $au
     * @param int $ctype
     * @return ?Contact */
    private function _make_user($au, $ctype) {
        $uu = $this->conf->user_by_email($au->email, USER_SLICE);
        if (!$uu
            && $ctype >= CONFLICT_AUTHOR
            && strcasecmp($au->email, $this->user->email) === 0) {
            $this->user->ensure_account_here();
            $uu = $this->conf->user_by_email($au->email, USER_SLICE);
        }
        if (!$uu && $ctype >= CONFLICT_AUTHOR) {
            $j = $au->unparse_nea_json();
            $j["disablement"] = ($this->disable_users ? Contact::CF_UDISABLED : 0)
                | ($ctype >= CONFLICT_CONTACTAUTHOR ? 0 : Contact::CF_PLACEHOLDER);
            $uu = Contact::make_keyed($this->conf, $j)->store(0, $this->user);
            if ($uu) {
                $this->_created_contacts[] = $uu;
            }
        }
        if ($uu && $uu->is_placeholder()) {
            foreach (["firstName", "lastName", "affiliation"] as $nprop) {
                if ($au->$nprop !== ""
                    && ($uu->$nprop === "" || $ctype >= CONFLICT_CONTACTAUTHOR)) {
                    $uu->set_prop($nprop, $au->$nprop);
                }
            }
            $uu->save_prop();
        }
        return $uu;
    }

    /** @param Contact|Author|string $u
     * @param int $mask
     * @param int $new
     * @return bool */
    function update_conflict_value($u, $mask, $new) {
        assert(($new & $mask) === $new);
        if (is_string($u) || $u->contactId <= 0) {
            $au = is_string($u) ? Author::make_email($u) : $u;
            $u = $this->_make_user($au, $new);
        }
        if (!$u || $u->contactId <= 0) {
            return false;
        }
        $uid = $u->contactId;
        if (!isset($this->_conflict_values[$uid])) {
            $this->_conflict_values[$uid] = [0, 0, 0];
        }
        $cv = &$this->_conflict_values[$uid];
        if ($mask !== (CONFLICT_PCMASK & ~1)
            || ((($cv[0] & ~$cv[1]) | $cv[2]) & 1) === 0) {
            $cv[1] |= $mask;
            $cv[2] = ($cv[2] & ~$mask) | $new;
        }
        // return true iff `$mask` bits have changed
        return ($cv[0] & $mask) !== ((($cv[0] & ~$cv[1]) | $cv[2]) & $mask);
    }

    function checkpoint_conflict_values() {
        $a = [];
        foreach ($this->_conflict_values as $uid => $cv) {
            if (($nv = self::new_conflict_value($cv)) !== 0)
                $a[] = "{$uid} {$nv}";
        }
        $s = join(",", $a);
        if ($s !== $this->prow->allConflictType) {
            $this->prow->set_prop("allConflictType", $s);
        }
    }

    /** @param ?array{int,int,int} $cv
     * @return int */
    static private function new_conflict_value($cv) {
        return $cv ? ($cv[0] & ~$cv[1]) | $cv[2] : 0;
    }

    /** @return bool */
    private function check_conflict_diff() {
        $changes = 0;
        foreach ($this->_conflict_values ?? [] as $cv) {
            $ncv = self::new_conflict_value($cv);
            $changes |= $cv[0] ^ $ncv;
        }
        if (($changes & (CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR)) !== 0) {
            $this->change_at($this->conf->option_by_id(PaperOption::CONTACTSID));
        }
        if (($changes & CONFLICT_PCMASK) !== 0) {
            $this->change_at($this->conf->option_by_id(PaperOption::PCCONFID));
        }
        return ($changes & (CONFLICT_PCMASK | CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR)) !== 0;
    }

    private function _check_contacts_last($pj) {
        // if creating a paper, user has account here
        if ($this->user->has_email()) {
            $this->user->ensure_account_here();
        }

        // if creating a paper, user becomes contact;
        // if user removes self from author list, user becomes contact
        if ($this->user->contactId > 0
            && !$this->user->allow_administer($this->prow)) {
            $cv = $this->_conflict_values[$this->user->contactId] ?? null;
            $ncv = self::new_conflict_value($cv);
            if (($ncv & CONFLICT_CONTACTAUTHOR) === 0
                && ($this->prow->is_new()
                    || ($ncv & CONFLICT_AUTHOR) === 0)) {
                $this->update_conflict_value($this->user, CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
                $this->checkpoint_conflict_values();
            }
        }

        // exit if no change
        if (!$this->check_conflict_diff()) {
            return;
        }

        // save diffs if change
        $this->_conflict_ins = $this->_author_change_cids = [];
        foreach ($this->_conflict_values as $uid => $cv) {
            $ncv = self::new_conflict_value($cv);
            if (($cv[0] ^ $ncv) & (CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR)) {
                $this->_author_change_cids[] = $uid;
            }
            $this->_conflict_ins[] = [$uid, $cv[1], $cv[2]];
        }
    }

    /** @param ?int $pid */
    private function _reset(PaperInfo $prow, $pid) {
        assert($prow->paperId !== -1);
        parent::clear();
        $this->prow = $prow;
        $this->paperId = $this->title = null;
        $this->_fdiffs = $this->_xdiffs = [];
        $this->_unknown_fields = null;
        $this->_field_values = $this->_option_delid = $this->_option_ins = [];
        $this->_conflict_values = [];
        $this->_conflict_ins = $this->_created_contacts = null;
        $this->_author_change_cids = null;
        $this->_paper_submitted = $this->_documents_changed = false;
        $this->_noncontacts_changed = $prow->is_new();
        $this->_update_pid_dids = $this->_joindocs = [];
        $this->_save_status = 0;
        if ($prow->is_new()) {
            $this->allocate_pid($pid);
        }
    }

    /** @return int */
    private function potential_random_pid() {
        $this->_potential_pids = $this->_potential_pids ?? [];
        while (true) {
            if (!empty($this->_potential_pids)) {
                return array_pop($this->_potential_pids);
            }

            $n = max(100, 3 * $this->conf->fetch_ivalue("select count(*) from Paper"));
            for ($i = 0; $i !== 20; ++$i) {
                $this->_potential_pids[] = mt_rand(1, $n);
            }

            $result = $this->conf->qe("select paperId from Paper where paperId?a", $this->_potential_pids);
            while (($row = $result->fetch_row())) {
                $pid = (int) $row[0];
                while (($i = array_search($pid, $this->_potential_pids, true)) !== false) {
                    array_splice($this->_potential_pids, $i, 1);
                }
            }
            $result->close();
        }
    }

    /** @param ?int $pid */
    private function allocate_pid($pid) {
        $random = $this->conf->setting("random_pids");
        while (true) {
            if ($pid === null && $random) {
                $pid = $this->potential_random_pid();
            }
            if ($pid === null) {
                $result = $this->conf->qe("insert into Paper set title='', abstract='', authorInformation=''");
            } else {
                $result = $this->conf->qe("insert into Paper set paperId=?, title='', abstract='', authorInformation='' on duplicate key update timeSubmitted=timeSubmitted", $pid);
            }
            if ($result->affected_rows > 0) {
                $this->prow->set_prop("paperId", $result->insert_id);
                return;
            }
            $pid = null;
        }
    }

    /** @param ?PaperInfo $prow
     * @return bool */
    function prepare_save_paper_web(Qrequest $qreq, $prow) {
        $this->_reset($prow ?? PaperInfo::make_new($this->user, $qreq->sclass), null);
        $pj = (object) [];

        // Backward compatibility XXX
        if (isset($qreq->submitpaper) && !isset($qreq["status:submit"])) {
            $qreq["status:submit"] = $qreq->submitpaper;
        }
        if (isset($qreq->has_submitpaper) && !isset($qreq["has_status:submit"])) {
            $qreq["has_status:submit"] = $qreq["has_submitpaper"];
        }

        // Status
        $pjs = $pj->status = (object) [];
        $phase = $qreq["status:phase"];
        if ($phase === "contacts") {
            // do not change submitted/draft state
        } else if ($phase === "final") {
            $pjs->phase = "final";
            $pjs->final_submitted = $pjs->submitted = true;
            $pjs->draft = false;
        } else if ($qreq["status:submit"]) {
            $pjs->submitted = true;
            $pjs->draft = false;
        } else if ($qreq["has_status:submit"]) {
            $pjs->submitted = false;
            $pjs->draft = true;
        }
        if (($s = $qreq["status:if_unmodified_since"]) !== null) {
            $s = is_string($s) && ctype_digit($s) ? intval($s) : $s;
            $pjs->if_unmodified_since = $s;
        }

        // Fields
        foreach ($this->prow->form_fields() as $o) {
            if (($qreq["has_{$o->formid}"] || isset($qreq[$o->formid]))
                && (!$o->is_final() || $phase === "final")
                && ($o->id === PaperOption::CONTACTSID || $phase !== "contacts")) {
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
            $this->syntax_error_at($key);
            return false;
        }
        if ($pid === "new" || (is_int($pid) && $pid <= 0)) {
            $pid = null;
        }

        if (($pj->error ?? null) || ($pj->error_html ?? null)) {
            $this->error_at("error", $this->_("<0>Refusing to save {submission} with error"));
            return false;
        }

        $prow = null;
        if ($pid !== null) {
            $prow = $this->conf->paper_by_id($pid, $this->user, ["topics" => true, "options" => true]);
        }
        $this->_reset($prow ?? PaperInfo::make_new($this->user, $pj->submission_class ?? null), $pid);
        assert($pid === null || $this->prow->paperId === $pid);

        return $this->_finish_prepare($pj);
    }

    /** @param object $pj
     * @return bool */
    private function _finish_prepare($pj) {
        $ok = $this->_normalize_and_check($pj);
        $this->prow->remove_option_overrides();
        if ($ok) {
            return true;
        } else {
            $this->prow->abort_prop();
            return false;
        }
    }

    /** @param object $pj
     * @return bool */
    private function _normalize_and_check($pj) {
        assert($this->_save_status === 0);
        $pid = $this->prow->is_new() ? "new" : $this->prow->paperId;
        if (($perm = $this->user->perm_view_paper($this->prow, false, $pid))) {
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
        $this->_prepare_decision($pj);
        $this->_prepare_final_status($pj);

        // correct blindness setting
        if ($this->conf->submission_blindness() !== Conf::BLIND_OPTIONAL) {
            $want_blind = $this->conf->submission_blindness() !== Conf::BLIND_NEVER;
            if ($this->prow->blind !== $want_blind) {
                $this->prow->set_prop("blind", $want_blind ? 1 : 0);
                $this->change_at($this->conf->option_by_id(PaperOption::ANONYMITYID));
            }
        }

        // don't save if transaction required
        if (isset($pj->status->if_unmodified_since)
            && $pj->status->if_unmodified_since < $this->prow->timeModified) {
            $this->estop_at("status:if_unmodified_since", $this->_("<5><strong>Edit conflict</strong>: You were editing an old version of the {submission}, so your changes have not been saved."));
        }

        // don't save if not allowed
        if ($this->problem_status() >= MessageSet::ESTOP) {
            $this->check_conflict_diff(); // to ensure diffs are ok
            return false;
        }

        // don't save if creating a mostly-empty paper
        if ($this->prow->is_new()
            && !array_diff(array_keys($this->prow->_old_prop), ["authorInformation", "blind"])
            && (!$this->prow->authorInformation
                || $this->prow->authorInformation === (new Author($this->user))->unparse_tabbed())
            && empty($this->_option_ins)) {
            $this->error_at(null, $this->_("<0>Empty {submission}. Please fill out the fields and try again"));
            $this->conf->qe("delete from Paper where paperId=?", $this->prow->paperId);
            $this->conf->qe("update PaperStorage set paperId=-1 where paperId=?", $this->prow->paperId);
            return false;
        }

        // validate contacts
        $this->_check_contacts_last($pj);
        $this->_save_status |= self::SAVE_STATUS_PREPARED;
        return true;
    }


    private function _update_joindoc() {
        $new_joinid = $this->prow->finalPaperStorageId;
        if ($new_joinid <= 1) {
            $new_joinid = $this->prow->paperStorageId;
        }

        $old_fpsid = $this->prow->base_prop("finalPaperStorageId");
        $old_psid = $this->prow->base_prop("paperStorageId");
        if ($new_joinid === ($old_fpsid <= 1 ? $old_psid : $old_fpsid)) {
            return;
        }

        $new_joindoc = null;
        foreach ($this->_joindocs as $doc) {
            if ($doc->paperStorageId == $new_joinid)
                $new_joindoc = $doc;
        }

        if ($new_joindoc) {
            $this->prow->set_prop("size", $new_joindoc->size());
            $this->prow->set_prop("mimetype", $new_joindoc->mimetype);
            $this->prow->set_prop("sha1", $new_joindoc->binary_hash());
            $this->prow->set_prop("timestamp", $new_joindoc->timestamp);
        } else {
            $this->prow->set_prop("size", -1);
            $this->prow->set_prop("mimetype", "");
            $this->prow->set_prop("sha1", "");
            $this->prow->set_prop("timestamp", 0);
        }
        $this->prow->set_prop("pdfFormatStatus", 0);
    }

    /** @return array{list<string>,list<null|int|float|string>} */
    private function _sql_prop() {
        $qf = $qv = [];
        foreach ($this->prow->_old_prop as $prop => $v) {
            if ($prop === "topicIds" || $prop === "allConflictType") {
                continue;
            }
            $qf[] = "{$prop}=?";
            $qv[] = $this->prow->prop($prop);
        }
        return [$qf, $qv];
    }

    /** @return bool */
    private function _execute_update() {
        list($qf, $qv) = $this->_sql_prop();
        $qv[] = $this->prow->paperId;
        $result = $this->conf->qe_apply("update Paper set " . join(", ", $qf) . " where paperId=?", $qv);
        if ($result->is_error()) {
            $action = $this->prow->is_new() ? "create" : "update";
            $this->error_at(null, $this->_("<0>Could not {$action} {submission}"));
            return false;
        } else if ($result->affected_rows === 0
                   && !$this->conf->fetch_ivalue("select exists(select * from Paper where paperId=?) from dual", $this->prow->paperId)) {
            $this->error_at(null, $this->_("<0>{Submission} #{} has been deleted", $this->prow->paperId));
            return false;
        }
        $this->paperId = $this->prow->paperId;
        if ($this->prow->is_new()) {
            $this->_save_status |= self::SAVE_STATUS_NEW;
            // save initial tags
            if (($t = $this->prow->all_tags_text()) !== "") {
                $qv = [];
                foreach (Tagger::split_unpack($t) as $ti) {
                    $qv[] = [$this->paperId, $ti[0], $ti[1]];
                }
                $this->conf->qe("insert into PaperTag (paperId, tag, tagIndex) values ?v", $qv);
            }
        }
        return true;
    }

    private function _execute_topics() {
        if (!$this->prow->prop_changed("topicIds")) {
            return;
        }
        $ti = [];
        foreach ($this->prow->topic_list() as $tid) {
            $ti[] = [$this->paperId, $tid];
        }
        $this->conf->qe("delete from PaperTopic where paperId=?", $this->paperId);
        if (!empty($ti)) {
            $this->conf->qe("insert into PaperTopic (paperId, topicId) values ?v", $ti);
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
            $auflags = CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR;
            $clears = 0;
            $qxa = [];
            foreach ($this->_conflict_ins as $ci) {
                $ci1 = $ci[1] & ~$ci[2];
                if ($ci1 !== 0) {
                    $ci1x = "(PaperConflict.conflictType&~{$ci1})";
                } else {
                    $ci1x = "PaperConflict.conflictType";
                }
                $clears |= $ci1;
                if (($ci[1] & CONFLICT_PCMASK) === (CONFLICT_PCMASK & ~1)) {
                    $ci1a = $ci[1] & ~$ci[2] & $auflags;
                    $ci2a = $ci[2] & $auflags;
                    if ($ci1a !== 0) {
                        $ci1ax = "(PaperConflict.conflictType&~{$ci1a})";
                    } else {
                        $ci1ax = "PaperConflict.conflictType";
                    }
                    $k = "if(PaperConflict.conflictType&1,({$ci1ax}|{$ci2a}),({$ci1x}|{$ci[2]}))";
                } else {
                    $k = "({$ci1x}|?U(conflictType))";
                }
                $qxa[$k][] = [$this->paperId, $ci[0], $ci[2]];
            }
            $cfltf = Dbl::make_multi_query_stager($this->conf->dblink, Dbl::F_ERROR);
            foreach ($qxa as $k => $qv) {
                $cfltf("insert into PaperConflict (paperId,contactId,conflictType) values ?v ?U on duplicate key update conflictType={$k}", $qv);
            }
            if ($clears !== 0) {
                $cfltf("delete from PaperConflict where paperId=? and conflictType=0", $this->paperId);
            }
            $cfltf(null);
        }

        if (!empty($this->_author_change_cids)) {
            // enable placeholder users that are now contacts;
            // update author records in contactdb
            $this->conf->prefetch_users_by_id($this->_author_change_cids);
            $us = [];
            foreach ($this->_author_change_cids as $uid) {
                $u = $this->conf->user_by_id($uid);
                if ($u === null) {
                    continue;
                }
                $us[] = $u;
                if (self::new_conflict_value($this->_conflict_values[$u->contactId]) >= CONFLICT_CONTACTAUTHOR
                    && $u->activate_placeholder_prop(false)) {
                    $u->save_prop();
                    $this->_created_contacts[] = $u;
                }
            }
            if ($this->conf->contactdb()) {
                foreach ($us as $u) {
                    $this->conf->prefetch_cdb_user_by_email($u->email);
                }
                foreach ($us as $u) {
                    $u->update_cdb_roles();
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

    private function _postexecute_notify() {
        $need_docinval = $this->_documents_changed && !$this->prow->is_new();
        $need_mail = [];
        if ($this->notify && $this->_created_contacts) {
            foreach ($this->_created_contacts as $u) {
                if ($u->password_unset()
                    && !$u->activity_at
                    && !$u->isPC
                    && !$u->is_dormant()) {
                    $need_mail[] = $u;
                }
            }
        }

        if (!$need_docinval && !$need_mail) {
            return;
        }

        $prow = $this->conf->paper_by_id($this->paperId, null, ["options" => true]);
        if ($need_docinval) {
            $prow->mark_inactive_documents();
        }
        if ($need_mail) {
            $rest = ["prow" => $prow];
            if ($this->user->can_administer($prow)
                && !$prow->has_author($this->user)) {
                $rest["adminupdate"] = true;
            }
            foreach ($need_mail as $u) {
                $u->prepare_mail("@newaccount.paper", $rest)->send();
            }
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

        if ($this->prow->prop_changed()) {
            $modified_at = max(Conf::$now, $this->prow->timeModified + 1);
            $this->prow->set_prop("timeModified", $modified_at);
            if ($this->prow->prop_changed("paperStorageId")
                || $this->prow->prop_changed("finalPaperStorageId")) {
                $this->_update_joindoc();
            }
            if (!$this->_execute_update()) {
                return false;
            }
        } else {
            assert(!$this->prow->is_new());
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
        $was_submitted = $this->prow->base_prop("timeWithdrawn") <= 0
            && $this->prow->base_prop("timeSubmitted") > 0;
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
        if ($this->prow->timeFinalSubmitted > 0) {
            $this->_save_status |= self::SAVE_STATUS_FINALSUBMIT;
        }
        if ($this->user->edit_paper_state($this->prow) === 2) {
            $this->_save_status |= self::SAVE_STATUS_WASFINAL;
        }

        // update automatic tags
        $this->conf->update_automatic_tags($this->paperId, "paper");

        // after tags set, update document inactivity and send mail to
        // newly created users
        $this->_postexecute_notify();

        // The caller should not use `$this->prow` any more, but in case they
        // do (e.g. in old tests), invalidate it now when it's convenient
        // to do so. XXX It would be useful to keep `$this->prow` around...
        $this->prow->commit_prop();
        $this->prow->invalidate_options();
        $this->prow->invalidate_conflicts();
        $this->prow->invalidate_documents();

        // save new title and clear out memory
        $this->title = $this->prow->title();
        $this->prow = null;
        $this->_field_values = null;
        $this->_update_pid_dids = $this->_joindocs = null;
        return true;
    }

    function log_save_activity($via = null) {
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
        if (($this->_save_status & self::SAVE_STATUS_WASFINAL) !== 0) {
            $logtext .= " final";
            $subbit = self::SAVE_STATUS_FINALSUBMIT;
        } else {
            $subbit = self::SAVE_STATUS_SUBMIT;
        }
        if (($this->_save_status & $subbit) === 0) {
            $logtext .= " draft";
        }
        if ($via) {
            $logtext .= " " . trim($via);
        }
        if (!empty($this->_fdiffs) || !empty($this->_xdiffs)) {
            $logtext .= ": " . join(", ", $this->changed_keys());
        }
        $this->user->log_activity($logtext, $this->paperId);
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
     * @return int|false */
    function save_paper_web(Qrequest $qreq, $prow) {
        if ($this->prepare_save_paper_web($qreq, $prow)) {
            $this->execute_save();
            return $this->paperId;
        } else {
            return false;
        }
    }

    /** @return list<PaperOption> */
    function strip_unchanged_fields_qreq(Qrequest $qreq, PaperInfo $prow) {
        $fcs = (new FieldChangeSet)->mark_unchanged($qreq["status:unchanged"])
            ->mark_changed($qreq["status:changed"])
            ->mark_synonym("pc_conflicts", "pcconf"); /* XXX special case */
        $fl = [];
        foreach ($prow->form_fields() as $o) {
            if ($o->is_final() && $qreq["status:phase"] !== "final") {
                continue;
            }
            // if option appears completely unchanged from save time, reset
            $chs = $fcs->test($o->formid);
            if ($chs === FieldChangeSet::UNCHANGED) {
                unset($qreq["has_{$o->formid}"], $qreq[$o->formid]);
                $fl[] = $o;
            } else if (($chs & FieldChangeSet::UNCHANGED) !== 0) {
                $o->strip_unchanged_qreq($prow, $qreq, $fcs);
            }
        }
        return $fl;
    }

    /** @return list<PaperOption> */
    function changed_fields_qreq(Qrequest $qreq, PaperInfo $prow) {
        $fl = [];
        foreach ($prow->form_fields() as $o) {
            if ($this->has_change_at($o)
                && (isset($qreq["has_{$o->formid}"]) || isset($qreq[$o->formid]))
                && (!$o->is_final() || $qreq["status:phase"] === "final")) {
                $fl[] = $o;
            }
        }
        return $fl;
    }

    /** @return int */
    function save_status() {
        return $this->_save_status;
    }
}
