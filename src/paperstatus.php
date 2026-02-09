<?php
// paperstatus.php -- HotCRP helper for reading/storing papers as JSON
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

final class PaperStatus extends MessageSet {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var bool */
    private $ignore_errors = false;
    /** @var bool */
    private $disable_users = false;
    /** @var bool */
    private $any_content_file = false;
    /** @var bool */
    private $ignore_content_file = false;
    /** @var bool */
    private $allow_hash_without_content = false;
    /** @var bool */
    private $notify = true;
    /** @var bool */
    private $notify_authors = true;
    /** @var ?string */
    private $notify_reason;
    /** @var ?bool */
    private $override_json_fields;
    /** @var bool */
    private $json_fields;
    /** @var int */
    private $doc_savef = 0;
    /** @var list<callable> */
    private $_on_document_import = [];

    /** @var ?PaperInfo */
    private $prow;
    /** @var int */
    public $paperId;
    /** @var ?PaperInfo */
    private $saved_prow;
    /** @var ?string */
    public $title;
    /** @var ?list<PaperOption> */
    private $_submitted_problem_fields;
    /** @var ?list<string> */
    private $_unknown_fields;
    /** @var list<PaperOption> */
    private $_fdiffs;
    /** @var list<string> */
    private $_xdiffs;
    /** @var ?list<PaperOption> */
    private $_resave_fields;
    /** @var int */
    private $_conflict_changemask;
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
    /** @var list<DocumentInfo> */
    private $_docs;
    /** @var list<array{string,float|false}> */
    private $_tags_changed;
    /** @var int */
    private $_save_status;

    const SSF_PREPARED = 1;
    const SSF_SAVED = 2;
    const SSF_ABORTED = 4;
    const SSF_NEW = 8;
    const SSF_FINAL_PHASE = 16;
    const SSF_SUBMIT = 32;
    const SSF_NEWSUBMIT = 64;
    const SSF_FINALSUBMIT = 128;
    const SSF_ADMIN_UPDATE = 256;
    const SSF_PIDFAIL = 512;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
        $this->allow_hash_without_content = $user->privChair;
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
    function set_ignore_errors($x) {
        $this->ignore_errors = $x;
        return $this;
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
    function set_notify_authors($x) {
        $this->notify_authors = $x;
        return $this;
    }

    /** @param ?string $x
     * @return $this */
    function set_notify_reason($x) {
        $this->notify_reason = $x;
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
    function set_ignore_content_file($x) {
        $this->ignore_content_file = $x;
        return $this;
    }

    /** @param ?bool $x
     * @return $this */
    function set_json_fields($x) {
        $this->override_json_fields = $x;
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


    /** @param PaperOption $o
     * @return string */
    function option_key($o) {
        return $this->json_fields ? $o->json_key() : $o->field_key();
    }

    /** @param ?string $msg
     * @return MessageItem */
    function error_at_option(PaperOption $o, $msg) {
        return $this->error_at($this->option_key($o), $msg);
    }

    /** @param ?string $msg
     * @return MessageItem */
    function warning_at_option(PaperOption $o, $msg) {
        return $this->warning_at($this->option_key($o), $msg);
    }

    /** @param string $key
     * @return MessageItem */
    function syntax_error_at($key) {
        return $this->error_at($key, "<0>Validation error [{$key}]");
    }

    /** @param PaperValue $ov */
    function append_messages_from($ov) {
        foreach ($ov->message_list() as $mi) {
            if ($this->json_fields && $mi->field) {
                $fk = $ov->option->field_key();
                if (str_starts_with($mi->field, $fk)
                    && (strlen($mi->field) === strlen($fk) || $mi->field[strlen($fk)] === ":")) {
                    $mi = $mi->with_field($ov->option->json_key() . substr($mi->field, strlen($fk)));
                }
            }
            $this->append_item($mi);
        }
    }

    /** @param ?list<int> $oids
     * @return list<MessageItem> */
    function decorated_message_list($oids = null) {
        $ms = [];
        foreach ($this->message_list() as $mi) {
            if (($mi->field ?? "") !== ""
                && (str_ends_with($mi->field, ":context") || $mi->status === MessageSet::INFORM)
                && !str_starts_with($mi->field, "status:")) {
                continue;
            }
            if ($mi->message !== ""
                && ($o = $this->conf->options()->option_by_key($mi->field))) {
                if ($oids !== null && !in_array($o->id, $oids, true)) {
                    continue;
                }
                $link = Ht::link(htmlspecialchars($o->edit_title()), "#" . $o->readable_formid());
                $mi = $mi->with(["message" => "<5>{$link}: " . $mi->message_as(5)]);
            }
            $ms[] = $mi;
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
        if (!($docj instanceof DocumentInfo)
            && isset($docj->content_file)
            && $docj->content_file !== false) {
            if ($this->ignore_content_file) {
                $docj->content_file = null;
            } else if (($problem = $this->check_content_file_first($docj->content_file))) {
                $this->error_at_option($o, $problem);
                return null;
            }
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
                $mi = $this->append_item($mi->with_field($this->option_key($o)));
                $mi->landmark = $doc->error_filename();
            }
            return null;
        }

        $this->_docs[] = $doc;
        assert($doc->paperId === $this->prow->paperId || $doc->paperId === 0 || $doc->paperId === -1);
        $doc->release_redundant_content();
        return $doc;
    }

    /** @param mixed $content_file
     * @return ?string */
    private function check_content_file_first($content_file) {
        if (!is_string($content_file)) {
            return "<0>Invalid `content_file`";
        } else if (!$this->any_content_file
                   && preg_match('/\A\/|(?:\A|\/)\.\.(?:\/|\z)/', $content_file)) {
            return "<0>`content_file` filename violates locality constraints";
        }
        return null;
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
        } else if ($this->ignore_content_file) {
            /* no content */
        } else if (isset($docj->content_file) && is_string($docj->content_file)) {
            if (is_readable($docj->content_file)) {
                $content_file = $docj->content_file;
            } else {
                $this->error_at_option($o, "<0>Could not access `content_file`");
            }
        } else if (isset($docj->content_file) && is_resource($docj->content_file)) {
            if (!($content_file = $this->_upload_content_stream($docj->content_file, $mimetype, $o))) {
                $this->warning_at_option($o, "<0>Could not copy `content_file` to a temporary file");
            }
        }

        // extract filename
        $filename = null;
        if (isset($docj->filename)) {
            if (is_string($docj->filename)) {
                $filename = $docj->filename;
            }
        } else if (isset($docj->content_file) && is_string($docj->content_file)) {
            if (($slash = strrpos($docj->content_file, "/")) > 0) {
                $filename = substr($docj->content_file, $slash + 1);
            } else if (preg_match('/\A[A-Za-z]+:.*+\\\\(.*)\z/', $docj->content_file, $m)) {
                $filename = $m[1];
            } else {
                $filename = $docj->content_file;
            }
        }
        $safe_filename = DocumentInfo::sanitize_filename($filename);

        // extract requested hash
        $ha = $want_algorithm = null;
        if (isset($docj->hash) && is_string($docj->hash)) {
            $ha = new HashAnalysis($docj->hash);
        } else if (isset($docj->sha1) && is_string($docj->sha1)) {
            $ha = new HashAnalysis($docj->sha1);
            $want_algorithm = "sha1";
        }
        if ($ha && (!$ha->complete() || ($want_algorithm && $ha->algorithm() !== $want_algorithm))) {
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
            && $content_ha->complete()
            && $ha->binary() !== $content_ha->binary()) {
            $this->error_at_option($o, "<0>Document corrupt (its content did not match the provided hash)");
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
                $this->error_at_option($o, "<0>Document corrupt (its content did not match the provided checksum)");
                return null;
            }
        }

        // choose a hash
        if ($ha) {
            $hash = $ha->binary();
        } else if ($content_ha->complete()) {
            $hash = $content_ha->binary();
        } else {
            $hash = null;
        }

        // check for existing document
        $docid = -1;
        if (isset($docj->docid)
            && is_int($docj->docid)
            && $docj->docid > 0) {
            $docid = $docj->docid;
        }
        if (!$this->prow->is_new()
            && ($docid > 0 || $hash !== null)) {
            $qf = ["paperId=?", "documentType=?", "filterType is null"];
            $qv = [$this->prow->paperId, $o->id];
            if ($docid > 0) {
                $qf[] = "paperStorageId=?";
                $qv[] = $docj->docid;
            }
            if ($hash !== null) {
                $qf[] = "sha1=?";
                $qv[] = $hash;
            }
            if ($mimetype !== null) {
                $qf[] = "mimetype=?";
                $qv[] = $mimetype;
            }
            if ($safe_filename !== null) {
                $qf[] = "filename=?";
                $qv[] = $safe_filename;
            }
            $result = $this->conf->qe_apply("select " . $this->conf->document_query_fields() . " from PaperStorage where " . join(" and ", $qf), $qv);
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
        if ($safe_filename !== null) {
            $doc->set_filename($safe_filename);
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
        $template = "upf-%s" . Mimetype::extension($mimetype);
        if (($finfo = Filer::create_tempfile($this->conf->docstore_tempdir(), $template))) {
            $ok = stream_copy_to_stream($f, $finfo[1]) !== false;
            fclose($finfo[1]);
            $content_file = $ok ? $finfo[0] : null;
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
                  "modified_at", "if_unmodified_since"] as $k) {
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
        if (in_array($istatusstr, ["submitted", "accepted", "accept", "deskrejected", "desk_reject", "deskreject", "rejected", "reject"], true)) {
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
            if (is_object($ipj->options)
                || (is_array($ipj->options) && !array_is_list($ipj->options))) {
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
                || in_array($k, ["object", "pid", "id", "options", "status", "decision", "reviews", "comments", "tags", "submission_class"], true)
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
        if (!in_array($field, $this->_fdiffs, true)) {
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
        if (!in_array($field, $this->_xdiffs, true)) {
            $this->_xdiffs[] = $field;
        }
    }

    /** @param PaperOption $field */
    function request_resave($field) {
        $this->_resave_fields[] = $field;
    }

    /** @return bool */
    function has_change() {
        return !empty($this->_fdiffs) || !empty($this->_xdiffs);
    }

    /** @param string|PaperOption $field
     * @return bool */
    function has_change_at($field) {
        if (!is_string($field)) {
            return in_array($field, $this->_fdiffs, true);
        }
        if (in_array($field, $this->_xdiffs, true)) {
            return true;
        }
        foreach ($this->conf->find_all_fields($field, Conf::MFLAG_OPTION) as $f) {
            if (in_array($f, $this->_fdiffs, true))
                return true;
        }
        return false;
    }

    /** @param bool $full
     * @return list<string> */
    function changed_keys($full = false) {
        $s = [];
        if ($full && ($this->_save_status & (self::SSF_NEW | self::SSF_PIDFAIL)) === self::SSF_NEW) {
            $s[] = "pid";
        }
        foreach ($this->_fdiffs ?? [] as $field) {
            $s[] = $field->json_key();
        }
        foreach ($this->_xdiffs ?? [] as $field) {
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
            $ov = $opt->parse_json_user($this->prow, $oj, $this->user);
        }
        if ($ov !== null) {
            $opt->value_check($ov, $this->user);
            if ($this->ignore_errors) {
                $ov->append_item(MessageItem::success(null));
            }
            if ($ov->allow_store()) {
                $opt->value_store($ov, $this);
                $this->prow->override_option($ov);
            }
        } else {
            $ov = $this->prow->force_option($opt);
            $opt->value_check($ov, $this->user);
        }
        $this->append_messages_from($ov);
    }

    private function _prepare_revive($pj) {
        if ($this->prow->timeWithdrawn <= 0 || $pj->status->withdrawn) {
            // do nothing
        } else if (($whynot = $this->user->perm_revive_paper($this->prow))) {
            $whynot->append_to($this, "status:withdrawn", 2);
        } else {
            $this->prow->set_prop("timeWithdrawn", 0);
            if ($this->prow->timeSubmitted === -PaperInfo::SUBMITTED_AT_FOR_WITHDRAWN) {
                $this->prow->set_prop("timeSubmitted", Conf::$now);
            } else if ($this->prow->timeSubmitted < 0) {
                $this->prow->set_prop("timeSubmitted", -$this->prow->timeSubmitted);
            }
        }
    }

    private function _check_submit_required() {
        $pj_submitted = true;
        foreach ($this->prow->form_fields() as $opt) {
            if (!$opt->test_exists($this->prow)) {
                continue;
            }
            $ov = $this->prow->force_option($opt);
            $had_error = $ov->has_error();
            if ($opt->value_check_required($ov)) {
                continue;
            }
            if (!$had_error) {
                $this->append_messages_from($ov);
            }
            if ($this->prow->base_prop("timeSubmitted") <= 0) {
                $pj_submitted = false;
            } else if (!in_array($opt, $this->_submitted_problem_fields ?? [], true)) {
                $this->estop_at("status:submitted");
            }
        }
        return $pj_submitted;
    }

    private function _prepare_status($pj) {
        $old_withdrawn = $this->prow->base_prop("timeWithdrawn") > 0;
        $old_submitted = $this->prow->base_prop("timeSubmitted") > 0;
        $old_submitted_at = abs($this->prow->base_prop("timeSubmitted"));
        if ($old_submitted_at === PaperInfo::SUBMITTED_AT_FOR_WITHDRAWN) {
            $old_submitted_at = Conf::$now;
        }

        // check withdraw status
        $pj_withdrawn = $this->prow->timeWithdrawn > 0;
        if (!$old_withdrawn && $pj->status->withdrawn) {
            if (($whynot = $this->user->perm_withdraw_paper($this->prow))) {
                $whynot->append_to($this, "status:withdrawn", 2);
            } else {
                $pj_withdrawn = true;
            }
        }


        // check and change submitted status
        $pj_submitted = $pj_withdrawn
            ? $old_submitted_at > 0
            : $pj->status->submitted && (!$this->has_error() || $old_submitted);

        if ($pj_submitted) {
            $pj_submitted = $this->_check_submit_required();
        }

        if ($pj_submitted !== $old_submitted
            || $this->_noncontacts_changed) {
            $whynot = $this->user->perm_edit_paper($this->prow);
            if ($whynot
                && $pj_submitted
                && !$this->_noncontacts_changed) {
                $whynot = $this->user->perm_finalize_paper($this->prow);
            }
            if (!$whynot
                && $old_submitted
                && !$pj_submitted) {
                $whynot = $this->user->perm_unsubmit_paper($this->prow);
            }
            if ($whynot) {
                $whynot->append_to($this, "status:submitted", 3);
                $pj_submitted = $old_submitted;
            }
        }

        if ($pj_submitted) {
            $submitted_at = $old_submitted_at;
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

        $this->prow->set_prop("timeSubmitted", $submitted_at);


        // set withdrawn status
        if ($pj_withdrawn) {
            if (!$old_withdrawn) {
                $withdrawn_at = 0;
                if (isset($pj->status->withdrawn_at)
                    && $this->user->privChair) {
                    $withdrawn_at = $pj->status->withdrawn_at;
                }
                $this->prow->set_prop("timeWithdrawn", $withdrawn_at > 0 ? $withdrawn_at : Conf::$now);
            }
            if (isset($pj->status->withdrawn_reason)) {
                $this->prow->set_prop("withdrawReason", UnicodeHelper::utf8_truncate_invalid(substr($pj->status->withdraw_reason, 0, 1024)));
            }
        }


        // mark whether submitted, mark diff
        $this->_paper_submitted = $pj_submitted && !$pj_withdrawn;
        if ($pj_withdrawn !== $old_withdrawn || $pj_submitted !== $old_submitted) {
            $this->status_change_at("status");
        }
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
               (!$this->prow->submission_round()->time_edit_final(true)
                && !$this->user->allow_manage($this->prow))) {
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
        $this->_conflict_changemask |= $bit;
        foreach ($this->_conflict_values as &$cv) {
            $cv[1] |= $bit;
            $cv[2] &= ~$bit;
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
            if ($this->user->contactId > 0) {
                $uu = $this->user;
            }
        }
        if (!$uu && $ctype >= CONFLICT_AUTHOR) {
            $j = $au->unparse_nea_json();
            $j["disablement"] = Contact::CF_PLACEHOLDER;
            $uu = Contact::make_keyed($this->conf, $j)->store(0, $this->user);
        }
        if (!$uu) {
            return null;
        }
        if ($uu->is_placeholder() || $uu === $this->user) {
            $uu->set_prop("firstName", $au->firstName, 2);
            $uu->set_prop("lastName", $au->lastName, 2);
            $uu->set_prop("affiliation", $au->affiliation, 2);
            if ($uu->prop_changed()) {
                $uu->save_prop();
                $uu->export_prop(1);
            }
        }
        return $uu;
    }

    /** @param Contact|Author|string $u
     * @param int $mask
     * @param int $new
     * @return void */
    function update_conflict_value($u, $mask, $new) {
        assert(($new & $mask) === $new);
        if (is_string($u) || $u->contactId <= 0) {
            $au = is_string($u) ? Author::make_email($u) : $u;
            $u = $this->_make_user($au, $new);
        }
        if (!$u || $u->contactId <= 0) {
            return;
        }
        $uid = $u->contactId;
        if (!isset($this->_conflict_values[$uid])) {
            $this->_conflict_values[$uid] = [0, 0, 0];
        }
        $cv = &$this->_conflict_values[$uid];
        $old = self::new_conflict_value($cv);
        if ($mask === Conflict::FM_PC) {
            $new = Conflict::apply_pc($old, $new, ($this->_save_status & self::SSF_ADMIN_UPDATE) !== 0);
        }
        if (($old & $mask) !== $new) {
            $cv[1] |= $mask;
            $cv[2] = ($cv[2] & ~$mask) | $new;
            $this->_conflict_changemask |= ($cv[0] ^ $cv[2]) & $mask;
        }
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

    private function _apply_primary_authors() {
        // must apply authors to distinguish actual authors from
        // leftover primaryContactId markers
        if (!in_array(PaperOption::AUTHORSID, $this->prow->overridden_option_ids(), true)) {
            $ov = $this->prow->force_option(PaperOption::AUTHORSID);
            /** @phan-suppress-next-line PhanUndeclaredMethod */
            $ov->option->value_save_conflict_values($ov, $this);
        }

        $pchanges = [];
        foreach ($this->_conflict_values as $uid => &$cv) {
            $ncav = self::new_conflict_value($cv)
                & (CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR);
            if ($ncav === 0) {
                continue;
            }
            if ($ncav === CONFLICT_AUTHOR
                && ($cv[0] & CONFLICT_CONTACTAUTHOR) !== 0) {
                // can’t remove as contact if still on author list
                $cv[2] |= CONFLICT_CONTACTAUTHOR;
                $ncav |= CONFLICT_CONTACTAUTHOR;
            }
            // record upcoming changes to primary
            $uu = $this->conf->user_by_id($uid, USER_SLICE);
            if ($uu && $uu->primaryContactId > 0) {
                $pchanges[] = [$uid, $uu->primaryContactId, $ncav];
            }
        }
        unset($cv);

        foreach ($pchanges as [$uid, $puid, $ncav]) {
            // add authorship to primary
            $cv = &$this->_conflict_values[$uid];
            $pcv = &$this->_conflict_values[$puid];
            $pcv = $pcv ?? [0, 0, 0];
            $pcv[1] |= $ncav;
            $pcv[2] |= $ncav;
            // newly-added secondary contact redirects to primary, unless:
            // 1. added by user with same primary
            // 2. added by chair, and primary is already contact
            if (($cv[0] & CONFLICT_CONTACTAUTHOR) === 0
                && ($ncav & CONFLICT_CONTACTAUTHOR) !== 0
                && $puid !== $this->user->contactId
                && $puid !== $this->user->primaryContactId
                && (!$this->user->privChair
                    || ($pcv[0] & CONFLICT_CONTACTAUTHOR) === 0)) {
                $cv[1] |= CONFLICT_CONTACTAUTHOR;
                $cv[2] &= ~CONFLICT_CONTACTAUTHOR;
            }
            unset($cv, $pcv);
        }

        $this->checkpoint_conflict_values();
    }

    /** @return bool */
    private function _check_conflict_diff() {
        $changes = 0;
        foreach ($this->_conflict_values ?? [] as $cv) {
            $ncv = self::new_conflict_value($cv);
            $changes |= $cv[0] ^ $ncv;
        }
        if (($changes & (CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR)) !== 0) {
            $this->change_at($this->conf->option_by_id(PaperOption::CONTACTSID));
        }
        if (($changes & Conflict::FM_PC) !== 0) {
            $this->change_at($this->conf->option_by_id(PaperOption::PCCONFID));
        }
        return ($changes & (Conflict::FM_PC | CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR)) !== 0;
    }

    /** @return list<int> */
    private function _dids() {
        $dids = [];
        foreach ($this->_docs as $doc) {
            $dids[] = $doc->paperStorageId;
        }
        return $dids;
    }

    private function _invalidate_uploaded_documents() {
        if (empty($this->_docs)) {
            return;
        }
        if ($this->prow->is_new()) {
            $this->conf->qe("update PaperStorage set paperId=-1, inactive=1 where paperStorageId?a", $this->_dids());
            return;
        }
        $inactive_dids = [];
        foreach ($this->_docs as $doc) {
            if ($doc->was_inserted()) {
                $inactive_dids[] = $doc->paperStorageId;
            }
            $doc->abort_prop();
        }
        if (!empty($inactive_dids)) {
            $this->conf->qe("update PaperStorage set inactive=1 where paperId=? and paperStorageId?a", $this->prow->paperId, $inactive_dids);
        }
    }

    private function _check_contacts_last() {
        // if creating a paper, user has account here
        if ($this->user->has_email()) {
            $this->user->ensure_account_here();
        }

        // if creating a paper, user becomes contact;
        // if user removes self from author list, user becomes contact
        if ($this->user->contactId > 0
            && !$this->user->allow_manage($this->prow)) {
            $cv = $this->_conflict_values[$this->user->contactId] ?? null;
            $ncv = self::new_conflict_value($cv);
            if (($ncv & CONFLICT_CONTACTAUTHOR) === 0
                && ($this->prow->is_new()
                    || ($ncv & CONFLICT_AUTHOR) === 0)) {
                $this->update_conflict_value($this->user, CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
                $this->checkpoint_conflict_values();
            }
        }

        // primary contacts require work
        if (($this->_conflict_changemask & (CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR)) !== 0) {
            $this->_apply_primary_authors();
        }

        // exit if no change
        if (!$this->_check_conflict_diff()) {
            return;
        }

        // save diffs if change
        $this->_conflict_ins = $this->_author_change_cids = [];
        foreach ($this->_conflict_values as $uid => $cv) {
            if ($cv[1] === 0) { // no changes requested
                continue;
            }
            $ncv = self::new_conflict_value($cv);
            if (($cv[0] ^ $ncv) & (CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR)) {
                $this->_author_change_cids[] = $uid;
            }
            $this->_conflict_ins[] = [$uid, $cv[1], $cv[2]];
        }
    }

    /** @return bool */
    private function _reset(PaperInfo $prow) {
        assert($prow->paperId !== -1);
        parent::clear();
        $this->prow = $prow;
        $this->paperId = $this->saved_prow = $this->title = null;
        $this->_fdiffs = $this->_xdiffs = [];
        $this->_submitted_problem_fields = null;
        if ($this->prow->timeSubmitted > 0) {
            $this->_prepare_submitted_problem_fields();
        }
        $this->_unknown_fields = $this->_resave_fields = null;
        $this->_conflict_changemask = 0;
        $this->_conflict_values = [];
        $this->_conflict_ins = $this->_created_contacts = null;
        $this->_author_change_cids = null;
        $this->_paper_submitted = false;
        $this->_documents_changed = false;
        $this->_noncontacts_changed = $prow->is_new();
        $this->_docs = $this->_tags_changed = [];
        $this->doc_savef |= DocumentInfo::SAVEF_DELAY_PROP;
        $this->_save_status = 0;
        if ($this->user->can_manage($this->prow)) {
            $this->_save_status |= self::SSF_ADMIN_UPDATE;
        }
        if (!$prow->is_new()) {
            if ($this->user->edit_paper_state($this->prow) === 2) {
                $this->_save_status |= self::SSF_FINAL_PHASE;
            }
            return true;
        }
        $this->_save_status |= self::SSF_NEW;
        if ($prow->paperId === 0) {
            $this->prow->set_prop("paperId", $this->conf->id_randomizer()->reserve(DatabaseIDRandomizer::PAPERID));
        } else if (!$this->user->privChair) {
            $this->user->no_paper_whynot($prow->paperId)->append_to($this, null, MessageSet::ESTOP);
            $this->_save_status |= self::SSF_PIDFAIL;
            return false;
        }
        $this->prow->set_prop_force("title", "");
        $this->prow->set_prop_force("abstract", "");
        $this->prow->set_prop_force("authorInformation", "");
        foreach (Tagger::split_unpack($prow->all_tags_text()) as $tv) {
            $this->_tags_changed[] = $tv;
        }
        return true;
    }

    /** @param ?PaperInfo $prow
     * @return bool */
    function prepare_save_paper_web(Qrequest $qreq, $prow) {
        if (!$this->_reset($prow ?? PaperInfo::make_new($this->user, $qreq->sclass))) {
            return false;
        }
        $this->json_fields = $this->override_json_fields ?? false;
        $pj = (object) [];

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
     * @param ?PaperInfo $prow
     * @return bool */
    function prepare_save_paper_json($pj, $prow = null) {
        assert(is_object($pj));

        if (($pj->object ?? "paper") !== "paper") {
            $this->error_at("object", $this->_("<0>JSON does not represent a {submission}"));
            return false;
        }
        $pid = $pj->pid ?? $pj->id ?? null;
        $pidkey = isset($pj->pid) && isset($pj->id) ? "id" : "pid";
        if ($pid === null && $prow) {
            $pid = $prow->paperId;
        } else if ($pid === "new" || (is_int($pid) && $pid <= 0)) {
            $pid = null;
        }
        if ($pid !== null && (!is_int($pid) || $pid > PaperInfo::PID_MAX)) {
            $this->syntax_error_at($pidkey);
            return false;
        } else if ($prow && $pid !== $prow->paperId) {
            $this->error_at($pidkey, "<0>{Submission} ID does not match");
            return false;
        }

        if (($pj->error ?? null) || ($pj->error_html ?? null)) {
            $this->error_at("error", $this->_("<0>Refusing to save {submission} with error"));
            return false;
        }

        if (!$prow && $pid !== null) {
            $prow = $this->conf->paper_by_id($pid, $this->user, ["topics" => true, "options" => true, "allConflictType" => true]);
        }
        if (!$prow) {
            $prow = PaperInfo::make_new($this->user, $pj->submission_class ?? null);
            if ($pid !== null) {
                $prow->set_prop("paperId", $pid);
            }
        }
        if (!$this->_reset($prow)) {
            return false;
        }
        $this->json_fields = $this->override_json_fields ?? true;
        assert($pid === null || $this->prow->paperId === $pid);

        return $this->_finish_prepare($pj);
    }

    /** @param object $pj
     * @return bool */
    private function _finish_prepare($pj) {
        if ($this->_normalize_and_check($pj)) {
            return true;
        }
        $this->abort_save();
        return false;
    }

    /** @return bool */
    private function _new_paper_is_empty() {
        foreach ($this->_fdiffs as $opt) {
            if ($opt->id === PaperOption::ANONYMITYID) {
                continue;
            }
            if ($opt->id === PaperOption::AUTHORSID
                && (!$this->prow->authorInformation
                    || $this->prow->authorInformation === Author::make_user($this->user)->unparse_tabbed())) {
                continue;
            }
            return false;
        }
        return true;
    }

    private function _prepare_submitted_problem_fields() {
        foreach ($this->prow->form_fields() as $opt) {
            if ($opt->test_exists($this->prow)) {
                $ov = $this->prow->force_option($opt);
                if (!$opt->value_check_required($ov)) {
                    $this->_submitted_problem_fields[] = $opt;
                    $ov->clear_messages();
                }
            }
        }
    }

    /** @param object $pj
     * @return bool */
    private function _normalize_and_check($pj) {
        assert(($this->_save_status & self::SSF_PREPARED) === 0);
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

        // potentially revive, mark updating status
        $this->_prepare_revive($pj);
        $this->prow->set_updating($pj->status->submitted && !$pj->status->withdrawn);

        // check fields
        foreach ($this->prow->form_fields() as $opt) {
            $this->_check_field($pj, $opt);
        }
        if (!empty($this->_unknown_fields)) {
            natcasesort($this->_unknown_fields);
            $this->warning_at("options", $this->_("<0>Ignoring unknown fields {:list}", $this->_unknown_fields));
        }
        if ($this->problem_status() >= MessageSet::ESTOP) {
            $this->prow->clear_updating();
            return false;
        }

        // reconcile fields
        for ($round = 0; $round !== 10; ) {
            $again = false;
            foreach ($this->prow->form_fields() as $opt) {
                $ov = $this->prow->force_option($opt);
                if (($nov = $opt->value_reconcile($ov, $this))) {
                    $opt->value_check($nov, $this->user);
                    assert($nov->allow_store());
                    $opt->value_store($nov, $this);
                    $this->prow->override_option($nov);
                    $again = true;
                }
            }
            $round = $again ? $round + 1 : 10;
        }

        // prepare fields for saving, mark changed fields
        foreach ($this->prow->overridden_option_ids() as $oid) {
            $ov = $this->prow->option($oid);
            if ($ov->allow_store()) {
                $ov->option->value_save($ov, $this);
                if ($oid !== PaperOption::CONTACTSID
                    && $oid !== PaperOption::PCCONFID
                    && !$ov->equals($this->prow->base_option($oid))) {
                    $this->change_at($ov->option);
                }
            }
        }

        // prepare non-fields for saving
        $this->_prepare_status($pj);
        $this->_prepare_decision($pj);
        $this->_prepare_final_status($pj);
        $this->prow->clear_updating();

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
            $this->estop_at("status:if_unmodified_since", $this->_("<5><strong>Edit conflict</strong>: The {submission} has changed"));
        }

        // don't save if not allowed
        if ($this->problem_status() >= MessageSet::ESTOP) {
            $this->_check_conflict_diff(); // to ensure diffs are ok
            return false;
        }

        // don't save if creating a mostly-empty paper
        if ($this->prow->is_new()
            && $this->_new_paper_is_empty()) {
            $this->error_at(null, $this->_("<0>Empty {submission}. Please fill out the fields and try again"));
            return false;
        }

        // validate contacts
        $this->_check_contacts_last();
        $this->_save_status |= self::SSF_PREPARED;
        return true;
    }


    function abort_save() {
        if (($this->_save_status & (self::SSF_SAVED | self::SSF_ABORTED)) === 0) {
            $this->_save_status |= self::SSF_ABORTED;
            if (!$this->ignore_errors) {
                $this->prow->abort_prop();
                $this->prow->remove_option_overrides();
            }
            $this->_invalidate_uploaded_documents();
        }
    }


    private function _update_joindoc() {
        if ($this->prow->finalPaperStorageId > 1) {
            $jointype = DTYPE_FINAL;
            $new_joinid = $this->prow->finalPaperStorageId;
        } else {
            $jointype = DTYPE_SUBMISSION;
            $new_joinid = $this->prow->paperStorageId;
        }

        $old_fpsid = $this->prow->base_prop("finalPaperStorageId");
        $old_psid = $this->prow->base_prop("paperStorageId");
        if ($new_joinid === ($old_fpsid <= 1 ? $old_psid : $old_fpsid)) {
            return;
        }

        $new_joindoc = null;
        foreach ($this->_docs as $doc) {
            if ($doc->documentType === $jointype
                && $doc->paperStorageId === $new_joinid)
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
        if ($this->prow->is_new()) {
            $result = $this->conf->qe_apply("insert into Paper set " . join(", ", $qf) . " on duplicate key update title=title", $qv);
        } else {
            $qv[] = $this->prow->paperId;
            $result = $this->conf->qe_apply("update Paper set " . join(", ", $qf) . " where paperId=?", $qv);
        }
        if ($result->is_error()) {
            $action = $this->prow->is_new() ? "create" : "update";
            $this->error_at(null, $this->_("<0>Could not {$action} {submission}"));
            return false;
        }
        if ($result->affected_rows === 0) {
            if ($this->prow->is_new()) {
                $this->error_at(null, $this->_("<0>Edit conflict"));
                return false;
            } else if (!$this->conf->fetch_ivalue("select exists(select * from Paper where paperId=?) from dual", $this->prow->paperId)) {
                $this->error_at(null, $this->_("<0>{Submission} #{} has been deleted", $this->prow->paperId));
                return false;
            }
        }
        $this->paperId = $this->prow->paperId;
        return true;
    }

    private function _execute_tags() {
        $ins = $del = [];
        foreach ($this->_tags_changed as $tv) {
            if ($tv[1] === false) {
                $del[] = $tv[0];
            } else {
                $ins[] = [$this->paperId, $tv[0], $tv[1]];
            }
        }
        if (!empty($del)) {
            $this->conf->qe("delete from PaperTag where paperId=? and tag?a", $this->paperId, $del);
        }
        if (!empty($ins)) {
            $this->conf->qe("insert into PaperTag (paperId, tag, tagIndex) values ?v", $ins);
        }
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
        $x = [];
        foreach ($this->_fdiffs as $opt) {
            $x[] = $opt->field_key();
        }

        $del = $ins = [];
        foreach ($this->_fdiffs as $opt) {
            if ($opt->id <= 0) {
                continue;
            }
            $ov = $this->prow->option($opt);
            $del[] = $opt->id;
            $dl = $ov->data_list();
            foreach ($ov->value_list() as $i => $v) {
                $qv0 = [$this->paperId, $opt->id, $v, null, null];
                if ($dl[$i] !== null) {
                    $qv0[strlen($dl[$i]) < 32768 ? 3 : 4] = $dl[$i];
                }
                $ins[] = $qv0;
            }
        }
        if (!empty($del) && !$this->prow->is_new()) {
            $this->conf->qe("delete from PaperOption where paperId=? and optionId?a", $this->paperId, $del);
        }
        if (!empty($ins)) {
            $this->conf->qe("insert into PaperOption (paperId, optionId, value, data, dataOverflow) values ?v", $ins);
        }
    }

    private function _execute_conflicts() {
        if (empty($this->_conflict_ins)) {
            return;
        }
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
            if (($ci[1] & Conflict::FM_PC) === Conflict::FM_PCTYPE) {
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

    private function _execute_author_changes() {
        if (empty($this->_author_change_cids)) {
            return;
        }
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
            $ncv = self::new_conflict_value($this->_conflict_values[$u->contactId]);
            if ($u->is_placeholder() && $ncv >= CONFLICT_AUTHOR) {
                if ($this->disable_users) {
                    $u->set_prop("cflags", $u->cflags | Contact::CF_UDISABLED);
                }
                if ($ncv >= CONFLICT_CONTACTAUTHOR
                    || (($cdbu = $u->cdb_user()) && !$cdbu->is_placeholder())) {
                    $u->set_prop("cflags", $u->cflags & ~Contact::CF_PLACEHOLDER);
                }
                if ($u->prop_changed()) {
                    $u->save_prop();
                    $u->log_create($this->user);
                    $this->_created_contacts[] = $u;
                }
            }
            $this->conf->prefetch_cdb_user_by_email($u->email);
        }
        foreach ($us as $u) {
            $u->update_cdb_roles();
        }
    }

    private function _execute_documents() {
        if (empty($this->_docs)) {
            return;
        }
        foreach ($this->_docs as $doc) {
            if ($doc->paperId === 0 || $doc->paperId === -1) {
                $doc->set_prop("paperId", $this->paperId);
            }
            $doc->set_prop("inactive", 0);
            $baseov = $this->prow->base_option($doc->documentType);
            if (!in_array($doc->paperStorageId, $baseov->value_list())
                && ($doc->timeReferenced ?? $doc->timestamp) < $this->prow->timeModified) {
                $doc->set_prop("timeReferenced", $this->prow->timeModified);
            }
            $doc->save_prop();
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
            if (($this->_save_status & self::SSF_ADMIN_UPDATE) !== 0) {
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
        if (($this->_save_status & (self::SSF_PREPARED | self::SSF_SAVED | self::SSF_ABORTED)) !== self::SSF_PREPARED) {
            throw new ErrorException("execute_save called inappropriately");
        }
        assert($this->paperId === null);
        $this->_save_status |= self::SSF_SAVED;

        // call back to fields that need a second store pass
        // (this stage must not error)
        foreach ($this->_resave_fields ?? [] as $opt) {
            $opt->value_save($this->prow->option($opt), $this);
        }

        // update timeModified if submission fields or status fields have changed
        if ($this->prow->user_prop_changed() || !empty($this->_fdiffs)) {
            $modified_at = max(Conf::$now, $this->prow->timeModified + 1);
            $this->prow->set_prop("timeModified", $modified_at);
        }

        if ($this->prow->prop_changed()) {
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

        $this->_execute_tags();
        $this->_execute_topics();
        $this->_execute_options();
        $this->_execute_conflicts();
        $this->_execute_author_changes();
        $this->_execute_documents();

        // maybe update `papersub` settings
        $was_submitted = $this->prow->base_prop("timeWithdrawn") <= 0
            && $this->prow->base_prop("timeSubmitted") > 0;
        if ($this->_paper_submitted !== $was_submitted) {
            $this->conf->update_papersub_setting($this->_paper_submitted ? 1 : -1);
        }

        // track submit-type flags
        if ($this->_paper_submitted) {
            $this->_save_status |= self::SSF_SUBMIT;
            if (!$was_submitted) {
                $this->_save_status |= self::SSF_NEWSUBMIT;
            }
        }
        if ($this->prow->timeFinalSubmitted > 0) {
            $this->_save_status |= self::SSF_FINALSUBMIT;
        }

        // correct ADMIN_UPDATE for new papers: if administrator created the
        // paper and is not an author or contact, it's an admin update
        if (($this->_save_status & (self::SSF_NEW | self::SSF_ADMIN_UPDATE)) === self::SSF_NEW
            && $this->user->allow_manage($this->prow)) {
            $cv = $this->_conflict_values[$this->user->contactId] ?? null;
            if ((self::new_conflict_value($cv) & (CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR)) === 0) {
                $this->_save_status |= self::SSF_ADMIN_UPDATE;
            }
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
        $this->prow->remove_option_overrides();
        $this->prow->invalidate_options();
        $this->prow->invalidate_conflicts();
        $this->prow->invalidate_documents();

        // save new title and clear out memory
        $this->title = $this->prow->title();
        $this->prow = null;
        $this->_docs = null;
        return true;
    }

    /** @return PaperInfo */
    function saved_prow() {
        assert(($this->_save_status & self::SSF_SAVED) !== 0);
        if (!$this->saved_prow) {
            $this->saved_prow = $this->conf->paper_by_id($this->paperId, $this->user, ["topics" => true, "options" => true]);
            if (($this->_save_status & self::SSF_NEW) !== 0) {
                $this->saved_prow->set_is_new(true);
            }
        }
        return $this->saved_prow;
    }

    function log_save_activity($via = null) {
        // log message
        assert(($this->_save_status & self::SSF_SAVED) !== 0);
        $actions = [];
        if (($this->_save_status & self::SSF_NEW) !== 0) {
            $actions[] = "started";
        }
        if (($this->_save_status & self::SSF_NEWSUBMIT) !== 0) {
            $actions[] = "submitted";
        } else if (($this->_save_status & self::SSF_NEW) === 0
                   && (!empty($this->_fdiffs) || !empty($this->_xdiffs))) {
            $actions[] = "edited";
        }
        if (empty($actions)) {
            $actions[] = "saved";
        }
        $logtext = "Paper " . join(", ", $actions);
        if (($this->_save_status & self::SSF_FINAL_PHASE) !== 0) {
            $logtext .= " final";
            $subbit = self::SSF_FINALSUBMIT;
        } else {
            $subbit = self::SSF_SUBMIT;
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
        if (!$this->prepare_save_paper_json($pj)) {
            return false;
        }
        $this->execute_save();
        return $this->paperId;
    }

    /** @param ?PaperInfo $prow
     * @return int|false */
    function save_paper_web(Qrequest $qreq, $prow) {
        if (!$this->prepare_save_paper_web($qreq, $prow)) {
            return false;
        }
        $this->execute_save();
        return $this->paperId;
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

    /** @param int $t
     * @param string $future_msg
     * @param string $past_msg
     * @return string */
    private function time_note($t, $future_msg, $past_msg) {
        if ($t <= 0) {
            return "";
        }
        $msg = $t < Conf::$now ? $past_msg : $future_msg;
        if ($msg !== "") {
            $msg = $this->conf->_($msg, $this->conf->unparse_time_with_local_span($t));
        }
        if ($msg !== "" && $t < Conf::$now) {
            $msg = "<5><strong>" . Ftext::as(5, $msg) . "</strong>";
        }
        return $msg;
    }

    /** @return MessageItem */
    function save_notes_message() {
        // no complex message if save failed
        if ($this->has_error()) {
            return MessageItem::plain("");
        }

        // final version
        $prow = $this->saved_prow();
        $n = [];
        if ($prow->phase() === PaperInfo::PHASE_FINAL) {
            if (($this->_save_status & self::SSF_FINALSUBMIT) === 0) {
                $n[] = $this->conf->_("<0>The final version has not yet been submitted.");
            }
            $n[] = $this->time_note($this->conf->setting("final_soft") ?? 0,
                "<5>You have until {} to make further changes.",
                "<5>The deadline for submitting final versions was {}.");
            return MessageItem::plain(Ftext::join_nonempty(" ", $n));
        }

        // submission
        $sr = $prow->submission_round();
        if (($this->_save_status & self::SSF_SUBMIT) !== 0) {
            $n[] = $this->conf->_("<0>The {submission} is ready for review.");
            if (!$sr->freeze) {
                $n[] = $this->time_note($sr->update,
                    "<5>You have until {} to make further changes.",
                    "");
            }
            return MessageItem::success(Ftext::join_nonempty(" ", $n));
        }

        // draft
        if ($sr->freeze) {
            $n[] = $this->conf->_("<0>This {submission} has not yet been completed.");
        } else if (($missing = PaperTable::missing_required_fields($prow))) {
            $n[] = $this->conf->_("<5>This {submission} is not ready for review. Required fields {:list} are incomplete.", PaperTable::field_title_links($missing, "missing_title"));
        } else {
            $first = $this->conf->_("<5>This {submission} is marked as not ready for review.");
            $n[] = "<5><strong>" . Ftext::as(5, $first) . "</strong>";
        }
        $n[] = $this->time_note($sr->update,
            "<5>You have until {} to make further changes.",
            "<5>The deadline for updating {submissions} was {}.");
        $n[] = $this->time_note($sr->submit,
            "<5>{Submissions} incomplete as of {} will not be considered.",
            "");
        return MessageItem::urgent_note(Ftext::join_nonempty(" ", $n));
    }

    /** @param ?MessageItem $notes_mi */
    function notify_followers($notes_mi = null) {
        assert(($this->_save_status & self::SSF_SAVED) !== 0);
        if (!$this->notify) {
            return;
        }
        if ($this->notify_authors) {
            $this->_notify_authors($notes_mi ?? $this->save_notes_message());
        }
        $this->_notify_others();
    }

    private function _notify_authors($notes_mi) {
        $options = [];
        if (($this->_save_status & self::SSF_ADMIN_UPDATE) !== 0) {
            $options["confirm_message_for"] = $this->user;
            $options["adminupdate"] = true;
        }
        if (($this->notify_reason ?? "") !== "") {
            $options["reason"] = $this->notify_reason;
        }
        if ($notes_mi->message !== "") {
            $options["notes"] = Ftext::as(0, $notes_mi->message);
        }
        if (($this->_save_status & self::SSF_NEW) === 0) {
            $chf = array_map(function ($f) { return $f->edit_title(); }, $this->changed_fields());
            if (!empty($chf)) {
                $options["change"] = $this->conf->_("{:list} were changed.", $chf);
            }
        }
        // confirmation message
        if (($this->_save_status & self::SSF_FINAL_PHASE) !== 0) {
            $template = "@submitfinalpaper";
        } else if (($this->_save_status & self::SSF_NEWSUBMIT) !== 0) {
            $template = "@submitpaper";
        } else if (($this->_save_status & self::SSF_NEW) !== 0) {
            $template = "@registerpaper";
        } else {
            $template = "@updatepaper";
        }
        HotCRPMailer::send_contacts($template, $this->saved_prow(), $options);
    }

    private function _notify_others() {
        $flags = 0;
        $template = null;
        if (($this->_save_status & self::SSF_FINALSUBMIT) !== 0) {
            $flags = Contact::WATCH_FINAL_UPDATE_ALL;
            $template = "@finalsubmitnotify";
        } else {
            if (($this->_save_status & self::SSF_NEW) !== 0) {
                $flags |= Contact::WATCH_PAPER_REGISTER_ALL;
                $template = "@registernotify";
            }
            if (($this->_save_status & self::SSF_NEWSUBMIT) !== 0) {
                $flags |= Contact::WATCH_PAPER_NEWSUBMIT_ALL;
                $template = "@newsubmitnotify";
            }
        }
        if ($flags === 0 || $template === null) {
            return;
        }
        $prow = $this->saved_prow();
        $options = ["prow" => $prow];
        if (($this->_save_status & self::SSF_ADMIN_UPDATE) !== 0) {
            $options["adminupdate"] = true;
        }
        if (($this->notify_reason ?? "") !== "") {
            $options["reason"] = $this->notify_reason;
        }
        $final = ($flags === Contact::WATCH_FINAL_UPDATE_ALL);
        foreach ($prow->generic_followers([], "(defaultWatch&{$flags})!=0 and roles!=0") as $u) {
            if ($u->contactId !== $this->user->contactId
                && ($final ? $u->following_final_update($prow) : $u->following_submission($prow))) {
                HotCRPMailer::send_to($u, $template, $options);
            }
        }
    }

    /** @return int */
    function save_status() {
        return $this->_save_status;
    }

    /** @return bool */
    function save_status_prepared() {
        return ($this->_save_status & self::SSF_PREPARED) !== 0;
    }

    /** @return ?int */
    function saved_pid() {
        if (($this->_save_status & self::SSF_SAVED) !== 0) {
            return $this->paperId;
        } else if ($this->prow->paperId !== 0
                   && (!$this->prow->is_new() || $this->user->privChair)) {
            return $this->prow->paperId;
        }
        return null;
    }
}
