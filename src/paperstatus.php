<?php
// paperstatus.php -- HotCRP helper for reading/storing papers as JSON
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class PaperStatus extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var ?PaperInfo */
    private $prow;
    /** @var int */
    public $paperId;
    /** @var bool */
    private $export_ids = false;
    /** @var bool */
    private $hide_docids = false;
    /** @var bool */
    private $export_content = false;
    /** @var bool */
    private $disable_users = false;
    /** @var bool */
    private $allow_any_content_file = false;
    /** @var ?string */
    private $content_file_prefix = null;
    /** @var bool */
    private $add_topics = false;
    /** @var list<callable> */
    private $_on_document_export = [];
    /** @var list<callable> */
    private $_on_document_import = [];
    /** @var ?CheckFormat */
    private $_cf;

    /** @var PaperInfo */
    private $_nnprow;
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
    /** @var ?list<Author> */
    private $_register_users;
    /** @var ?list<Contact> */
    private $_created_contacts;
    /** @var bool */
    private $_paper_submitted;
    /** @var bool */
    private $_documents_changed;
    /** @var int */
    private $_save_status;
    /** @var list<int> */
    private $_update_pid_dids;
    /** @var list<DocumentInfo> */
    private $_joindocs;

    const SAVE_STATUS_ANY = 1;
    const SAVE_STATUS_NEW = 2;
    const SAVE_STATUS_SUBMIT = 4;
    const SAVE_STATUS_NEWSUBMIT = 8;
    const SAVE_STATUS_FINALSUBMIT = 16;

    function __construct(Conf $conf, Contact $user = null, $options = []) {
        $this->conf = $conf;
        $this->user = $user ?? $conf->root_user();
        foreach (["export_ids", "hide_docids", "add_topics",
                  "export_content", "disable_users",
                  "allow_any_content_file", "content_file_prefix"] as $k) {
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        }
        $this->_on_document_import[] = [$this, "document_import_check_filename"];
        $this->clear();
        $this->set_want_ftext(true, 5);
    }

    /** @return PaperStatus */
    static function make_prow(Contact $user, PaperInfo $prow) {
        $ps = new PaperStatus($prow->conf, $user);
        $ps->prow = $prow;
        $ps->paperId = $prow->paperId;
        return $ps;
    }

    function clear() {
        parent::clear();
        $this->set_ignore_duplicates(true);
        $this->prow = null;
        $this->_fdiffs = $this->_xdiffs = [];
        $this->_paper_upd = $this->_paper_overflow_upd = [];
        $this->_topic_ins = null;
        $this->_field_values = $this->_option_delid = $this->_option_ins = [];
        $this->_conflict_values = [];
        $this->_conflict_ins = $this->_register_users = $this->_created_contacts = null;
        $this->_paper_submitted = $this->_documents_changed = false;
        $this->_update_pid_dids = $this->_joindocs = [];
        $this->_save_status = 0;
    }

    /** @param callable(object,DocumentInfo,int,PaperStatus):(?bool) $cb */
    function on_document_export($cb) {
        $this->_on_document_export[] = $cb;
    }

    /** @param callable(object,PaperInfo,PaperStatus):(?bool) $cb */
    function on_document_import($cb) {
        $this->_on_document_import[] = $cb;
    }

    function user() {
        return $this->user;
    }
    function export_ids() {
        return $this->export_ids;
    }
    function add_topics() {
        return $this->add_topics;
    }

    function _($itext, ...$args) {
        return $this->conf->_($itext, ...$args);
    }

    /** @param PaperOption $o
     * @param int|DocumentInfo $docid */
    function document_to_json(PaperOption $o, $docid) {
        if (is_int($docid)) {
            $doc = $this->prow ? $this->prow->document($o->id, $docid) : null;
        } else {
            $doc = $docid;
            $docid = $doc->paperStorageId;
        }
        if (!$doc) {
            return null;
        }
        assert($doc instanceof DocumentInfo);

        $d = (object) array();
        if ($docid && !$this->hide_docids) {
            $d->docid = $docid;
        }
        if ($doc->mimetype) {
            $d->mimetype = $doc->mimetype;
        }
        if ($doc->has_hash()) {
            $d->hash = $doc->text_hash();
        }
        if ($doc->timestamp) {
            $d->timestamp = $doc->timestamp;
        }
        if (($sz = $doc->size()) > 0) {
            $d->size = $sz;
        }
        if ($doc->filename) {
            $d->filename = $doc->filename;
        }
        $meta = null;
        if (isset($doc->infoJson) && is_object($doc->infoJson)) {
            $meta = $doc->infoJson;
        } else if (isset($doc->infoJson) && is_string($doc->infoJson)) {
            $meta = json_decode($doc->infoJson);
        }
        if ($meta) {
            $d->metadata = $meta;
        }
        if ($this->export_content
            && ($content = $doc->content()) !== false) {
            $d->content_base64 = base64_encode($content);
        }
        foreach ($this->_on_document_export as $cb) {
            if (call_user_func($cb, $d, $doc, $o->id, $this) === false)
                return null;
        }
        if (!count(get_object_vars($d))) {
            $d = null;
        }

        // maybe warn about format check
        if ($d
            && $doc->mimetype === "application/pdf"
            && ($spec = $this->conf->format_spec($doc->documentType))
            && !$spec->is_empty()) {
            if (!$this->_cf) {
                $this->_cf = new CheckFormat($this->conf, CheckFormat::RUN_NEVER);
            }
            $this->_cf->check_document($doc);
            if ($this->_cf->has_problem()) {
                $this->msg_at($o->field_key(), null, $this->_cf->problem_status());
            }
        }

        return $d;
    }

    /** @param int|PaperInfo $prow */
    function paper_json($prow) {
        if (is_int($prow)) {
            $prow = $this->conf->paper_by_id($prow, $this->user, ["topics" => true, "options" => true]);
        }
        if (!$prow || !$this->user->can_view_paper($prow)) {
            return null;
        }
        $original_no_msgs = $this->swap_ignore_messages(true);

        $this->prow = $prow;
        $this->paperId = $prow->paperId;

        $pj = (object) [];
        $pj->pid = (int) $prow->paperId;

        foreach ($this->prow->form_fields() as $opt) {
            if ($this->user->can_view_option($this->prow, $opt)) {
                $ov = $prow->force_option($opt);
                $oj = $opt->value_unparse_json($ov, $this);
                if ($oj !== null) {
                    if ($this->export_ids) {
                        $pj->{$opt->field_key()} = $oj;
                    } else {
                        $pj->{$opt->json_key()} = $oj;
                    }
                }
            }
        }

        if ($this->user->can_view_authors($prow)) {
            $pj->authors = [];
            foreach ($prow->author_list() as $au) {
                $pj->authors[] = $au->unparse_nae_json();
            }
        }

        $submitted_status = "submitted";
        if ($prow->outcome != 0 && $this->user->can_view_decision($prow)) {
            $pj->decision = $this->conf->decision_name($prow->outcome);
            if ($pj->decision === false) {
                $pj->decision = (int) $prow->outcome;
            }
            $submitted_status = $prow->outcome > 0 ? "accepted" : "rejected";
        }

        if ($prow->timeWithdrawn > 0) {
            $pj->status = "withdrawn";
            $pj->withdrawn = true;
            $pj->withdrawn_at = (int) $prow->timeWithdrawn;
            if ($prow->withdrawReason) {
                $pj->withdraw_reason = $prow->withdrawReason;
            }
        } else if ($prow->timeSubmitted > 0) {
            $pj->status = $submitted_status;
            $pj->submitted = true;
        } else {
            $pj->status = "draft";
            $pj->draft = true;
        }
        if (($t = $prow->submitted_at())) {
            $pj->submitted_at = $t;
        }

        if ($prow->timeFinalSubmitted > 0) {
            $pj->final_submitted = true;
            $pj->final_submitted_at = $prow->timeFinalSubmitted;
        }

        $this->swap_ignore_messages($original_no_msgs);
        return $pj;
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
    function syntax_error_at($key, $value) {
        error_log($this->conf->dbname . ": PaperStatus: syntax error $key " . gettype($value));
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


    function document_import_check_filename($docj, PaperOption $o, PaperStatus $pstatus) {
        if (isset($docj->content_file)
            && is_string($docj->content_file)
            && !($docj instanceof DocumentInfo)) {
            if (!$this->allow_any_content_file && preg_match(',\A/|(?:\A|/)\.\.(?:/|\z),', $docj->content_file)) {
                $pstatus->error_at_option($o, "<0>Bad content_file: only simple filenames allowed");
                return false;
            }
            if (($this->content_file_prefix ?? "") !== "") {
                $docj->content_file = $this->content_file_prefix . $docj->content_file;
            }
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
            $doc = $dochash = null;
            if (!isset($docj->hash) && isset($docj->sha1) && is_string($docj->sha1)) {
                $dochash = Filer::sha1_hash_as_text($docj->sha1);
            } else if (isset($docj->hash) && is_string($docj->hash)) {
                $dochash = Filer::hash_as_text($docj->hash);
            }

            if ($this->prow
                && ($docid = $docj->docid ?? null)
                && is_int($docid)) {
                $result = $this->conf->qe("select * from PaperStorage where paperId=? and paperStorageId=? and documentType=?", $this->prow->paperId, $docid, $o->id);
                $doc = DocumentInfo::fetch($result, $this->conf, $this->prow);
                Dbl::free($result);
                if (!$doc
                    || ((string) $dochash !== "" && $doc->text_hash() !== $dochash)) {
                    $doc = null;
                }
            }

            if (!$doc) {
                $args = [
                    "paperId" => $this->paperId,
                    "sha1" => (string) $dochash,
                    "documentType" => $o->id
                ];
                foreach (["timestamp", "mimetype", "content", "content_base64",
                          "content_file", "metadata"] as $k) {
                    if (isset($docj->$k))
                        $args[$k] = $docj->$k;
                }
                if (isset($docj->filename)) {
                    $args["filename"] = DocumentInfo::sanitize_filename($docj->filename);
                }
                DocumentInfo::fix_mimetype($args);
                $doc = new DocumentInfo($args, $this->conf, $this->prow);
            }
        }

        // save
        if ($doc->paperStorageId > 1 || $doc->save()) {
            if ($doc->documentType <= 0) {
                $this->_joindocs[] = $doc;
            }
            if ($doc->paperId === 0 || $doc->paperId === -1) {
                $this->_update_pid_dids[] = $doc->paperStorageId;
            } else {
                assert($this->prow && $doc->paperId === $this->prow->paperId);
            }
            return $doc;
        } else {
            error_log($doc->message_set()->full_feedback_text());
            foreach ($doc->message_list() as $mi) {
                $mi = $this->msg_at_option($o, $mi->message, $mi->status);
                $mi->landmark = $doc->export_filename();
            }
            return null;
        }
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
                $this->syntax_error_at("status.$k", $v);
                $v = null;
            }
            $xstatus->$k = $v;
        }
        foreach (["submitted_at", "withdrawn_at", "final_submitted_at"] as $k) {
            $v = $istatus->$k ?? null;
            if (is_numeric($v)) {
                $v = (float) $v;
                if ($v < 0) {
                    $this->error_at("status.$k", "<0>Negative date");
                    $v = null;
                }
            } else if (is_string($v)) {
                $v = $this->conf->parse_time($v, Conf::$now);
                if ($v === false || $v < 0) {
                    $this->error_at("status.$k", "<0>Parse error in date");
                    $v = null;
                } else {
                    $v = (float) $v;
                }
            } else if ($v === false) {
                $v = 0.0;
            } else if ($v !== null) {
                $this->syntax_error_at("status.$k", $v);
            }
            $xstatus->$k = $v;
        }
        if ($istatusstr === "submitted" || $istatusstr === "accepted" || $istatusstr === "rejected") {
            $xstatus->submitted = $xstatus->submitted ?? true;
            $xstatus->draft = $xstatus->draft ?? false;
            $xstatus->withdrawn = $xstatus->withdrawn ?? false;
        } else if ($istatusstr === "draft" || $istatusstr === "inprogress") {
            $xstatus->submitted = $xstatus->submitted ?? false;
            $xstatus->draft = $xstatus->draft ?? true;
            $xstatus->withdrawn = $xstatus->withdrawn ?? false;
        } else if ($istatusstr === "withdrawn") {
            $xstatus->withdrawn = $xstatus->withdrawn ?? true;
        }
        $xstatus->submitted = $xstatus->submitted ?? ($this->prow && $this->prow->timeSubmitted != 0);
        $xstatus->draft = $xstatus->draft ?? !$xstatus->submitted;
        $xstatus->withdrawn = $xstatus->withdrawn ?? ($this->prow && $this->prow->timeWithdrawn != 0);
        if ($xstatus->submitted !== !$xstatus->draft) {
            $this->error_at("status.draft", "<0>Draft status conflicts with submitted status");
        }
        $xpj->status = $xstatus;

        // Decision
        $idecision = $ipj->decision ?? null;
        if ($idecision !== null) {
            $decision_map = $this->conf->decision_map();
            if (is_int($idecision) && isset($decision_map[$idecision])) {
                $xpj->decision = $idecision;
            } else if (is_string($idecision)) {
                foreach ($decision_map as $d => $dname) {
                    if (strcasecmp($dname, $idecision) === 0)
                        $xpj->decision = $d;
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

        // Features
        $xpj->_bad_options = [];
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
        foreach ($this->_nnprow->form_fields() as $o) {
            $k = $o->json_key();
            if (($j = $ipj->$k ?? $ioptions->$k ?? null) !== null) {
                $xpj->$k = $j;
            } else if (($j = $ipj->{$o->field_key()} ?? $ioptions->{$o->field_key()} ?? null) !== null) {
                $xpj->$k = $j;
                $ikeys[$o->field_key()] = true;
            } else if (($j = $ipj->{(string) $o->id} ?? null)) {
                $xpj->$k = $j;
                $ikeys[(string) $o->id] = true;
            }
        }
        if (isset($ipj->options)) {
            foreach ((array) $ioptions as $k => $v) {
                if (!isset($xpj->$k) && !isset($ikeys[$k])) {
                    $matches = $this->conf->options()->find_all($k);
                    if (count($matches) === 1) {
                        $o = current($matches);
                        $xpj->{$o->json_key()} = $v;
                    } else {
                        $xpj->_bad_options[] = $k;
                    }
                }
            }
        }
        foreach ((array) $ipj as $k => $v) {
            if (!isset($xpj->$k) && !isset($ikeys[$k]) && !isset($xstatus->$k)
                && !in_array($k, ["pid", "id", "options", "status", "decision"])
                && $k[0] !== "_" && $k[0] !== "\$") {
                $matches = $this->conf->options()->find_all($k);
                if (count($matches) === 1) {
                    $o = current($matches);
                    $xpj->{$o->json_key()} = $v;
                } else {
                    $xpj->_bad_options[] = $k;
                }
            }
        }

        // load previous conflicts
        // old conflicts
        if ($this->prow) {
            foreach ($this->prow->conflicts(true) as $cflt) {
                $this->_conflict_values[strtolower($cflt->email)] = [$cflt->conflictType, 0, 0];
            }
            if (!$this->user->allow_administer($this->prow)
                && $this->prow->conflict_type($this->user) === CONFLICT_AUTHOR) {
                $this->update_conflict_value(strtolower($this->user->email), CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
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
    function change_keys() {
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
    function change_fields() {
        return $this->_fdiffs;
    }

    private function _check_status($pj) {
        $pj_withdrawn = $pj->status->withdrawn;
        $pj_submitted = $pj->status->submitted;
        $pj_draft = $pj->status->draft;

        if ($this->has_error()
            && $pj_submitted
            && !$pj_withdrawn
            && (!$this->prow || $this->prow->timeSubmitted == 0)) {
            $pj_submitted = false;
            $pj_draft = true;
        }

        if (isset($pj->status->submitted_at)) {
            $submitted_at = $pj->status->submitted_at;
        } else if ($this->prow) {
            $submitted_at = $this->prow->submitted_at();
        } else {
            $submitted_at = 0;
        }

        if ($pj_withdrawn) {
            if ($pj_submitted && $submitted_at <= 0) {
                $submitted_at = -100;
            } else if (!$pj_submitted) {
                $submitted_at = 0;
            } else {
                $submitted_at = -$submitted_at;
            }
            if (!$this->prow || $this->prow->timeWithdrawn <= 0) {
                $this->save_paperf("timeWithdrawn", ($pj->status->withdrawn_at ?? null) ? : Conf::$now);
                $this->save_paperf("timeSubmitted", $submitted_at);
                $this->status_change_at("status");
            } else if (($this->prow->submitted_at() > 0) !== $pj_submitted) {
                $this->save_paperf("timeSubmitted", $submitted_at);
                $this->status_change_at("status");
            }
        } else if ($pj_submitted) {
            if (!$this->prow || $this->prow->timeSubmitted <= 0) {
                if ($submitted_at <= 0
                    || $submitted_at === PaperInfo::SUBMITTED_AT_FOR_WITHDRAWN) {
                    $submitted_at = Conf::$now;
                }
                $this->save_paperf("timeSubmitted", $submitted_at);
                $this->status_change_at("status");
            }
            if ($this->prow && $this->prow->timeWithdrawn != 0) {
                $this->save_paperf("timeWithdrawn", 0);
                $this->status_change_at("status");
            }
        } else if ($this->prow && ($this->prow->timeWithdrawn > 0 || $this->prow->timeSubmitted > 0)) {
            $this->save_paperf("timeSubmitted", 0);
            $this->save_paperf("timeWithdrawn", 0);
            $this->status_change_at("status");
        }

        $this->_paper_submitted = $pj_submitted && !$pj_withdrawn;
    }

    private function _check_final_status($pj) {
        if (isset($pj->status->final_submitted)) {
            if ($pj->status->final_submitted) {
                $time = ($pj->status->final_submitted_at ?? null) ? : Conf::$now;
            } else {
                $time = 0;
            }
            if (!$this->prow || $this->prow->timeFinalSubmitted != $time) {
                $this->save_paperf("timeFinalSubmitted", $time);
                $this->status_change_at("final_status");
            }
        }
    }

    private function _check_decision($pj) {
        if (isset($pj->decision)) {
            if (($this->prow ? $this->prow->outcome : 0) !== $pj->decision) {
                $this->save_paperf("outcome", $pj->decision);
                $this->status_change_at("decision");
            }
        }
    }

    private function _check_fields($pj) {
        foreach ($this->_nnprow->form_fields() as $opt) {
            if ($this->user->can_edit_option($this->_nnprow, $opt)
                || ($this->user->is_site_contact
                    && isset($pj->{$opt->json_key()}))) {
                $oj = $pj->{$opt->json_key()} ?? null;
                if ($oj === null) {
                    $ov = null;
                } else if ($oj instanceof PaperValue) {
                    $ov = $oj;
                } else {
                    $ov = $opt->parse_json($this->_nnprow, $oj);
                }
                if ($ov !== null) {
                    $opt->value_check($ov, $this->user);
                    if (!$ov->has_error()) {
                        $opt->value_store($ov, $this);
                        $this->_nnprow->override_option($ov);
                        $this->_field_values[$opt->id] = $ov;
                    }
                } else {
                    $ov = $this->_nnprow->force_option($opt);
                    $opt->value_check($ov, $this->user);
                }
                $ov->append_messages_to($this);
            }
        }
        if (!empty($pj->_bad_options)) {
            $this->warning_at("options", $this->_("<0>Unknown options ignored (%#s)", $pj->_bad_options));
        }
    }

    private function _save_fields() {
        foreach ($this->_field_values ?? [] as $ov) {
            if ($ov->has_error()) { // XXX should never have errors here
                continue;
            }
            $v1 = $ov->value_list();
            $d1 = $ov->data_list();
            $oldv = $this->_nnprow->base_option($ov->option);
            if ($v1 !== $oldv->value_list() || $d1 !== $oldv->data_list()) {
                if (!$ov->option->value_save($ov, $this)) {
                    // normal option
                    $this->change_at($ov->option);
                    $this->_option_delid[] = $ov->id;
                    for ($i = 0; $i < count($v1); ++$i) {
                        $qv0 = [-1, $ov->id, $v1[$i], null, null];
                        if ($d1[$i] !== null) {
                            $qv0[strlen($d1[$i]) < 32768 ? 3 : 4] = $d1[$i];
                        }
                        $this->_option_ins[] = $qv0;
                    }
                }
                if ($ov->option->has_document()) {
                    $this->_documents_changed = true;
                }
            }
        }
    }

    /** @param int $bit */
    function clear_conflict_values($bit) {
        foreach ($this->_conflict_values as $lemail => &$cv) {
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

    function register_user(Author $c) {
        foreach ($this->_register_users ?? [] as $au) {
            if (strcasecmp($au->email, $c->email) === 0) {
                $au->merge($c);
                $au->author_index = $au->author_index ?? $c->author_index;
                return;
            }
        }
        $this->_register_users[] = clone $c;
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
        $j = $au->unparse_nae_json();
        $j->disabled = !!$this->disable_users;
        $conflictType = self::new_conflict_value($this->_conflict_values[strtolower($j->email)] ?? null);
        $flags = $conflictType & CONFLICT_CONTACTAUTHOR ? 0 : Contact::SAVE_IMPORT;
        $u = Contact::make_keyed($this->conf, (array) $j)->store($flags, $this->user);
        if ($u) {
            $this->_created_contacts[] = $u;
        } else if (!($flags & Contact::SAVE_IMPORT)) {
            if ($au->author_index >= 0) {
                $key = "contacts:" . $au->author_index;
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
        $lemail_to_cid = $pricid_to_lemail = [];
        $result = $this->conf->qe("select contactId, email, primaryContactId from ContactInfo where email?a", array_keys($this->_conflict_values));
        while (($row = $result->fetch_row())) {
            $lemail_to_cid[strtolower($row[1])] = (int) $row[0];
            if ($row[2]) {
                $pricid_to_lemail[(int) $row[2]][] = strtolower($row[1]);
            }
        }
        Dbl::free($result);

        // update for primaryContactId
        if (!empty($pricid_to_lemail)) {
            $result = $this->conf->qe("select contactId, email from ContactInfo where contactId?a", array_keys($pricid_to_lemail));
            while (($row = $result->fetch_row())) {
                $pcid = (int) $row[0];
                $plemail = strtolower($row[1]);
                $lemail_to_cid[$plemail] = $pcid;
                foreach ($pricid_to_lemail[$pcid] as $lemail) {
                    $cv = $this->_conflict_values[$lemail];
                    $npcv = self::new_conflict_value($this->_conflict_values[$plemail] ?? null);
                    foreach ([CONFLICT_PCMASK, CONFLICT_AUTHOR, CONFLICT_CONTACTAUTHOR] as $ct) {
                        if (($cv[1] & $ct) !== 0 && ($npcv & $ct) === 0) {
                            $this->update_conflict_value($plemail, $cv[1] & $ct, $cv[2] & $ct);
                        }
                    }
                    $this->update_conflict_value($lemail, CONFLICT_PCMASK | CONFLICT_CONTACTAUTHOR, 0);
                }
            }
            Dbl::free($result);
        }

        // save diffs if change
        if ($this->has_conflict_diff($lemail_to_cid)) {
            $this->_conflict_ins = [];
            $ds = 0;
            foreach ($this->_conflict_values as $lemail => $cv) {
                if (($cid = $lemail_to_cid[$lemail] ?? null)) {
                    $ncv = self::new_conflict_value($cv);
                    if (($cv[0] ^ $ncv) & CONFLICT_PCMASK) {
                        $ds |= 1;
                    }
                    if (($cv[0] >= CONFLICT_AUTHOR) !== ($ncv >= CONFLICT_AUTHOR)) {
                        $ds |= 2;
                    }
                    $this->_conflict_ins[] = [$cid, $cv[1], $cv[2]];
                }
            }
            if ($ds & 2) {
                $this->change_at($this->conf->option_by_id(PaperOption::CONTACTSID));
            }
            if ($ds & 1) {
                $this->change_at($this->conf->option_by_id(PaperOption::PCCONFID));
            }
        }
    }

    private function _ensure_creator_contact() {
        // if creating a paper, user must always be contact
        if (!$this->_nnprow->paperId
            && $this->user->contactId > 0) {
            // NB ok to have multiple inserters for same user
            $this->_conflict_ins = $this->_conflict_ins ?? [];
            $this->_conflict_ins[] = [$this->user->contactId, CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR];
            $this->change_at($this->conf->option_by_id(PaperOption::CONTACTSID));
        }
    }

    /** @param ?PaperInfo $prow
     * @param string $action
     * @return bool */
    function prepare_save_paper_web(Qrequest $qreq, $prow, $action) {
        $pj = (object) [];
        $pj->pid = $prow && $prow->paperId > 0 ? $prow->paperId : -1;

        // Status
        $updatecontacts = $action === "updatecontacts";
        if ($action === "submit"
            || ($action === "update" && $qreq->submitpaper)) {
            $pj->submitted = true;
            $pj->draft = false;
        } else if ($action === "final") {
            $pj->final_submitted = $pj->submitted = true;
            $pj->draft = false;
        } else if (!$updatecontacts) {
            $pj->submitted = false;
            $pj->draft = true;
        }

        // Fields
        $nnprow = $prow ?? PaperInfo::make_new($this->user);
        foreach ($nnprow->form_fields() as $o) {
            if (($qreq["has_{$o->formid}"] || isset($qreq[$o->formid]))
                && (!$o->final || $action === "final")
                && (!$updatecontacts || $o->id === PaperOption::CONTACTSID)) {
                // XXX test_editable
                $pj->{$o->json_key()} = $o->parse_qreq($nnprow, $qreq);
            }
        }

        return $this->prepare_save_paper_json($pj);
    }

    /** @param object $pj
     * @return bool */
    function prepare_save_paper_json($pj) {
        assert(!$this->hide_docids);
        assert(is_object($pj));

        $paperid = $pj->pid ?? $pj->id ?? null;
        if ($paperid === "new" || (is_int($paperid) && $paperid <= 0)) {
            $paperid = null;
        } else if ($paperid !== null && !is_int($paperid)) {
            $key = isset($pj->pid) ? "pid" : "id";
            $this->syntax_error_at($key, $paperid);
            return false;
        }

        if (($pj->error ?? null) || ($pj->error_html ?? null)) {
            $this->error_at("error", $this->_("<0>Refusing to save submission with error"));
            return false;
        }

        $this->clear();
        $this->paperId = $paperid ? : -1;
        if ($paperid) {
            $this->prow = $this->conf->paper_by_id($paperid, $this->user, ["topics" => true, "options" => true]);
        }
        if ($this->prow && $paperid !== $this->prow->paperId) {
            $this->error_at("pid", $this->_("<0>Saving submission with different ID"));
            return false;
        }
        $this->_nnprow = $this->prow ?? PaperInfo::make_new($this->user);

        // normalize and check format
        $pj = $this->_normalize($pj);
        if ($this->has_error()) {
            return false;
        }

        // save parts and track diffs
        $conf = $this->conf;
        $this->_check_fields($pj);
        $this->_check_status($pj);
        $this->_check_final_status($pj);
        $this->_check_decision($pj);

        // prepare changes for saving
        $ok = $this->problem_status() < MessageSet::ESTOP;
        if ($ok) {
            $this->_save_fields();
        }

        // correct blindness setting
        if ($ok && $this->conf->submission_blindness() !== Conf::BLIND_OPTIONAL) {
            $want_blind = $this->conf->submission_blindness() !== Conf::BLIND_NEVER;
            if (!$this->prow || $this->prow->blind !== $want_blind) {
                $this->save_paperf("blind", $want_blind ? 1 : 0);
                if ($this->prow) {
                    $this->change_at($this->conf->option_by_id(PaperOption::ANONYMITYID));
                }
            }
        }

        // don't save if creating a mostly-empty paper
        if ($ok && $this->paperId <= 0) {
            if (!array_diff(array_keys($this->_paper_upd), ["authorInformation", "blind"])
                && (!isset($this->_paper_upd["authorInformation"])
                    || $this->_paper_upd["authorInformation"] === (new Author($this->user))->unparse_tabbed())
                && empty($this->_topic_ins)
                && empty($this->_option_ins)) {
                $this->error_at(null, "<0>Empty submission. Please fill out the submission fields and try again");
                $ok = false;
            }
        }

        // validate contacts
        if ($ok) {
            $this->_check_contacts_last($pj);
            $this->_ensure_creator_contact();
        }

        $this->_nnprow->remove_option_overrides();
        return $ok;
    }


    /** @return int */
    private function unused_random_pid() {
        $n = max(100, 3 * $this->conf->fetch_ivalue("select count(*) from Paper"));
        while (true) {
            $pids = [];
            while (count($pids) < 10) {
                $pids[] = mt_rand(1, $n);
            }

            $result = $this->conf->qe("select paperId from Paper where paperId?a", $pids);
            while (($row = $result->fetch_row())) {
                $pids = array_values(array_diff($pids, [(int) $row[0]]));
            }
            Dbl::free($result);

            if (!empty($pids)) {
                return $pids[0];
            }
        }
    }

    private function _preexecute_set_default_columns() {
        foreach (["title" => "", "abstract" => "", "authorInformation" => "",
                  "paperStorageId" => 0, "finalPaperStorageId" => 0] as $f => $v) {
            if (!isset($this->_paper_upd[$f])) {
                $this->_paper_upd[$f] = $v;
            }
        }
    }

    private function _update_joindoc() {
        $old_joindoc = $this->prow ? $this->prow->primary_document() : null;
        $old_joinid = $old_joindoc ? $old_joindoc->paperStorageId : 0;

        $new_joinid = $this->_paper_upd["finalPaperStorageId"] ?? $this->_nnprow->finalPaperStorageId;
        $new_dtype = DTYPE_FINAL;
        if ($new_joinid <= 1) {
            $new_joinid = $this->_paper_upd["paperStorageId"] ?? $this->_nnprow->paperStorageId;
            $new_dtype = DTYPE_SUBMISSION;
        }

        if ($new_joinid == $old_joinid && $this->prow) {
            return;
        }

        $new_joindoc = null;
        foreach ($this->_joindocs as $doc) {
            if ($doc->paperStorageId == $new_joinid)
                $new_joindoc = $doc;
        }

        if ($new_joindoc) {
            if ($new_joindoc->ensure_size()) {
                $this->save_paperf("size", $new_joindoc->size);
            } else {
                $this->save_paperf("size", 0);
            }
            $this->save_paperf("mimetype", $new_joindoc->mimetype);
            $this->save_paperf("sha1", $new_joindoc->binary_hash());
            $this->save_paperf("timestamp", $new_joindoc->timestamp);
            $this->save_paperf("pdfFormatStatus", 0);
        } else {
            $this->save_paperf("size", 0);
            $this->save_paperf("mimetype", "");
            $this->save_paperf("sha1", "");
            $this->save_paperf("timestamp", 0);
            $this->save_paperf("pdfFormatStatus", 0);
        }
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
        if ($this->_conflict_ins !== null) {
            $cfltf = Dbl::make_multi_query_stager($this->conf->dblink, Dbl::F_ERROR);
            $auflags = CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR;
            foreach ($this->_conflict_ins as $ci) {
                if (($ci[1] & CONFLICT_PCMASK) === (CONFLICT_PCMASK & ~1)) {
                    $cfltf("insert into PaperConflict set paperId=?, contactId=?, conflictType=? on duplicate key update conflictType=if(conflictType&1,((conflictType&~?)|?),((conflictType&~?)|?))",
                        [$this->paperId, $ci[0], $ci[2],
                         $ci[1] & $auflags, $ci[2] & $auflags, $ci[1], $ci[2]]);
                } else {
                    $cfltf("insert into PaperConflict set paperId=?, contactId=?, conflictType=? on duplicate key update conflictType=((conflictType&~?)|?)",
                        [$this->paperId, $ci[0], $ci[2], $ci[1], $ci[2]]);
                }
            }
            $cfltf("delete from PaperConflict where paperId=? and conflictType=0", [$this->paperId]);
            $cfltf(null);
        }
        if ($this->_created_contacts !== null) {
            $rest = ["prow" => $this->conf->paper_by_id($this->paperId)];
            if ($this->user->can_administer($rest["prow"])
                && !$rest["prow"]->has_author($this->user)) {
                $rest["adminupdate"] = true;
            }
            foreach ($this->_created_contacts as $u) {
                if ($u->password_unset()
                    && !$u->activity_at
                    && !$u->isPC
                    && !$u->is_disabled()) {
                    $u->send_mail("@newaccount.paper", $rest);
                }
            }
        }
    }

    private function _postexecute_check_required_options() {
        $prow = null;
        $required_failure = false;
        foreach ($this->_nnprow->form_fields() as $o) {
            if (!$o->required) {
                continue;
            }
            if (!$prow) {
                $prow = $this->conf->paper_by_id($this->paperId, $this->user, ["options" => true]);
            }
            if ($this->user->can_edit_option($prow, $o)
                && $o->test_required($prow)
                && !$o->value_present($prow->force_option($o))) {
                $this->error_at_option($o, "<0>Entry required");
                $required_failure = true;
            }
        }
        if ($required_failure
            && (!$this->prow || $this->prow->timeSubmitted == 0)) {
            // Some required option was missing and the paper was not submitted
            // before, so it shouldn't be submitted now.
            $this->conf->qe("update Paper set timeSubmitted=0 where paperId=?", $this->paperId);
            $this->_paper_submitted = false;
        }
    }

    /** @return bool */
    function execute_save() {
        assert($this->_save_status === 0);
        $this->_save_status = self::SAVE_STATUS_ANY;

        $dataOverflow = $this->prow ? $this->prow->dataOverflow : null;
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

        if (!empty($this->_paper_upd)) {
            $this->save_paperf("timeModified", Conf::$now);
            if (isset($this->_paper_upd["paperStorageId"])
                || isset($this->_paper_upd["finalPaperStorageId"])) {
                $this->_update_joindoc();
            }

            $need_insert = $this->paperId <= 0;
            if (!$need_insert) {
                $qv = array_values($this->_paper_upd);
                $qv[] = $this->paperId;
                $result = $this->conf->qe_apply("update Paper set " . join("=?, ", array_keys($this->_paper_upd)) . "=? where paperId=?", $qv);
                if ($result
                    && $result->affected_rows === 0
                    && !$this->conf->fetch_value("select paperId from Paper where paperId=?", $this->paperId)) {
                    $this->_paper_upd["paperId"] = $this->paperId;
                    $need_insert = true;
                }
            }

            if ($need_insert) {
                $this->_preexecute_set_default_columns();
                if (($random_pids = $this->conf->setting("random_pids"))) {
                    $this->conf->qe("lock tables Paper write");
                    $this->_paper_upd["paperId"] = $this->unused_random_pid();
                }
                $result = $this->conf->qe_apply("insert into Paper set " . join("=?, ", array_keys($this->_paper_upd)) . "=?", array_values($this->_paper_upd));
                if ($random_pids) {
                    $this->conf->qe("unlock tables");
                }
                if (Dbl::is_error($result) || !$result->insert_id) {
                    $this->error_at(null, $this->_("<0>Could not create paper"));
                    return false;
                }
                $this->paperId = (int) $result->insert_id;
                $this->_save_status |= self::SAVE_STATUS_NEW;
            }
        }

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
        $was_submitted = $this->prow
            && $this->prow->timeWithdrawn <= 0
            && $this->prow->timeSubmitted > 0;
        if ($this->_paper_submitted != $was_submitted) {
            $this->conf->update_papersub_setting($this->_paper_submitted ? 1 : -1);
        }

        // track submit-type flags
        if ($this->_paper_submitted) {
            $this->_save_status |= self::SAVE_STATUS_SUBMIT | ($was_submitted ? 0 : self::SAVE_STATUS_NEWSUBMIT);
        }
        if (isset($this->_paper_upd["timeFinalSubmitted"])
            ? $this->_paper_upd["timeFinalSubmitted"] > 0
            : $this->prow && $this->prow->timeFinalSubmitted > 0) {
            $this->_save_status |= self::SAVE_STATUS_FINALSUBMIT;
        }

        // update automatic tags
        $this->conf->update_automatic_tags($this->paperId, "paper");

        // update document inactivity
        if ($this->_documents_changed
            && ($prow = $this->conf->paper_by_id($this->paperId, null, ["options" => true]))) {
            $prow->mark_inactive_documents();
        }

        return true;
    }

    function log_save_activity(Contact $user, $action, $via = null) {
        // log message
        assert($this->_save_status !== 0);
        $actions = [];
        if (($this->_save_status & self::SAVE_STATUS_NEW) !== 0) {
            $actions[] = "started";
        }
        if (($this->_save_status & self::SAVE_STATUS_NEWSUBMIT) !== 0) {
            $actions[] = "submitted";
        } else if (($this->_save_status & self::SAVE_STATUS_NEW) === 0
                   && !empty($this->_xdiffs)) {
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
            $logtext .= ": " . join(", ", $this->change_keys());
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
