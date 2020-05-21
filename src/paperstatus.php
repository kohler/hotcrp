<?php
// paperstatus.php -- HotCRP helper for reading/storing papers as JSON
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class PaperStatus extends MessageSet {
    /** @var Conf */
    public $conf;
    // public $user; -- inherited from MessageSet
    /** @var ?PaperInfo */
    private $prow;
    /** @var int */
    public $paperId;
    private $uploaded_documents;
    /** @var bool */
    private $no_notify = false;
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
    /** @var string|false */
    private $content_file_prefix = false;
    /** @var bool */
    private $add_topics = false;
    /** @var list<callable> */
    private $_on_document_export = [];
    /** @var list<callable> */
    private $_on_document_import = [];
    /** @var ?CheckFormat */
    private $_cf;

    /** @var associative-array<string,true> */
    public $diffs;
    /** @var PaperInfo */
    private $_nnprow;
    private $_paper_upd;
    private $_paper_overflow_upd;
    public $_topic_ins; // set by Topics_PaperOption
    /** @var associative-array<int,PaperValue> */
    private $_field_values;
    private $_option_delid;
    private $_option_ins;
    /** @var associative-array<string,array{int,int,int}> */
    private $_conflict_values;
    /** @var ?list<array{int,int,int}> */
    private $_conflict_ins;
    private $_created_contacts;
    private $_paper_submitted;
    private $_documents_changed;
    private $_joindocs;

    function __construct(Conf $conf, Contact $user = null, $options = array()) {
        $this->conf = $conf;
        $this->user = $user ?? $conf->site_contact();
        foreach (["no_notify", "export_ids", "hide_docids", "add_topics",
                  "export_content", "disable_users",
                  "allow_any_content_file", "content_file_prefix"] as $k) {
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        }
        $this->_on_document_import[] = [$this, "document_import_check_filename"];
        $this->clear();
    }

    static function make_prow(Contact $user, PaperInfo $prow) {
        $ps = new PaperStatus($prow->conf, $user);
        $ps->prow = $prow;
        $ps->paperId = $prow->paperId;
        return $ps;
    }

    function clear() {
        parent::clear();
        $this->uploaded_documents = [];
        $this->prow = null;
        $this->diffs = [];
        $this->_paper_upd = $this->_paper_overflow_upd = [];
        $this->_topic_ins = null;
        $this->_field_values = $this->_option_delid = $this->_option_ins = [];
        $this->_conflict_values = [];
        $this->_conflict_ins = null;
        $this->_paper_submitted = $this->_documents_changed = false;
        $this->_joindocs = [];
    }

    function on_document_export($cb) {
        // arguments: $document_json, DocumentInfo $doc, $dtype, PaperStatus $pstatus
        $this->_on_document_export[] = $cb;
    }

    function on_document_import($cb) {
        // arguments: $document_json, $prow
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

    function _() {
        return call_user_func_array([$this->conf->ims(), "x"], func_get_args());
    }

    function document_to_json($dtype, $docid, $field = false) {
        if (!is_object($docid)) {
            $doc = $this->prow ? $this->prow->document($dtype, $docid) : null;
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
        if ($doc->size) {
            $d->size = $doc->size;
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
            if (call_user_func($cb, $d, $doc, $dtype, $this) === false)
                return null;
        }
        if (!count(get_object_vars($d))) {
            $d = null;
        }

        // maybe warn about format check
        if ($d
            && $field
            && $doc->mimetype === "application/pdf"
            && ($spec = $this->conf->format_spec($doc->documentType))
            && !$spec->is_empty()) {
            if (!$this->_cf) {
                $this->_cf = new CheckFormat($this->conf, CheckFormat::RUN_NO);
            }
            $this->_cf->check_document($this->prow, $doc);
            if ($this->_cf->has_problem()) {
                $this->msg_at($field, false, $this->_cf->problem_status());
            }
        }

        return $d;
    }

    private function _maybe_check_format($doc, $name) {
    }

    function paper_json($prow, $args = array()) {
        if (is_int($prow)) {
            $prow = $this->conf->paper_by_id($prow, $this->user, ["topics" => true, "options" => true]);
        }

        $original_user = $user = $this->user;
        if ($args["forceShow"] ?? false) {
            $user = $this->conf->site_contact();
        }
        if (!$prow || !$user->can_view_paper($prow)) {
            return null;
        }
        $this->user = $user;

        $original_no_msgs = $this->ignore_msgs;
        $this->ignore_msgs = true;

        $this->prow = $prow;
        $this->paperId = $prow->paperId;

        $pj = (object) [];
        $pj->pid = (int) $prow->paperId;
        $pj->title = $prow->title;

        $submitted_status = "submitted";
        if ($prow->outcome != 0 && $user->can_view_decision($prow)) {
            $pj->decision = $this->conf->decision_name($prow->outcome);
            if ($pj->decision === false) {
                $pj->decision = (int) $prow->outcome;
                $submitted_status = $pj->decision > 0 ? "accepted" : "rejected";
            } else {
                $submitted_status = $pj->decision;
            }
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
            $pj->status = "inprogress";
            $pj->draft = true;
        }
        if (($t = $prow->submitted_at())) {
            $pj->submitted_at = $t;
        }

        $can_view_authors = !$user
            || $user->can_view_authors($prow);
        if ($can_view_authors) {
            $contacts = array();
            foreach ($prow->named_contacts() as $cflt) {
                $contacts[strtolower($cflt->email)] = $cflt;
            }

            $pj->authors = array();
            foreach ($prow->author_list() as $au) {
                $aux = (object) array();
                if ($au->email) {
                    $aux->email = $au->email;
                }
                if ($au->firstName) {
                    $aux->first = $au->firstName;
                }
                if ($au->lastName) {
                    $aux->last = $au->lastName;
                }
                if ($au->affiliation) {
                    $aux->affiliation = $au->affiliation;
                }
                $lemail = strtolower((string) $au->email);
                if ($lemail
                    && ($cflt = $contacts[$lemail] ?? null)
                    && $cflt->conflictType >= CONFLICT_AUTHOR) {
                    $aux->contact = true;
                    unset($contacts[$lemail]);
                }
                $pj->authors[] = $aux;
            }

            $other_contacts = array();
            foreach ($contacts as $cflt) {
                if ($cflt->conflictType >= CONFLICT_AUTHOR) {
                    $aux = (object) ["email" => $cflt->email];
                    if ($cflt->firstName) {
                        $aux->first = $cflt->firstName;
                    }
                    if ($cflt->lastName) {
                        $aux->last = $cflt->lastName;
                    }
                    if ($cflt->affiliation) {
                        $aux->affiliation = $cflt->affiliation;
                    }
                    $other_contacts[] = $aux;
                }
            }
            if (!empty($other_contacts)) {
                $pj->contacts = $other_contacts;
            }
        }

        if ($this->conf->submission_blindness() == Conf::BLIND_OPTIONAL) {
            $pj->nonblind = !$prow->blind;
        }

        if ($prow->abstract_text() !== "" || !$this->conf->opt("noAbstract")) {
            $pj->abstract = $prow->abstract_text();
        }

        $topics = array();
        foreach ($prow->topic_map() as $tid => $tname) {
            $topics[$this->export_ids ? $tid : $tname] = true;
        }
        if (!empty($topics)) {
            $pj->topics = (object) $topics;
        }

        if ($prow->paperStorageId > 1
            && (!$user || $user->can_view_pdf($prow))
            && ($doc = $this->document_to_json(DTYPE_SUBMISSION, (int) $prow->paperStorageId, "paper"))) {
            $pj->submission = $doc;
        }

        if ($prow->finalPaperStorageId > 1
            && (!$user || $user->can_view_pdf($prow))
            && ($doc = $this->document_to_json(DTYPE_FINAL, (int) $prow->finalPaperStorageId, "final"))) {
            $pj->final = $doc;
        }
        if ($prow->timeFinalSubmitted > 0) {
            $pj->final_submitted = true;
            $pj->final_submitted_at = (int) $prow->timeFinalSubmitted;
        }

        $options = array();
        foreach ($this->conf->paper_opts->option_list() as $o) {
            if ($user && !$user->can_edit_option($prow, $o)) {
                continue;
            }
            $ov = $prow->force_option($o);
            $oj = $o->value_unparse_json($ov, $this);
            if ($oj !== null) {
                $options[$this->export_ids ? $o->id : $o->json_key()] = $oj;
            }
        }
        if (!empty($options)) {
            $pj->options = (object) $options;
        }

        if ($can_view_authors) {
            $confset = $this->conf->conflict_types();
            $pcconflicts = array();
            foreach ($prow->pc_conflicts(true) as $id => $cflt) {
                if (($ct = Conflict::nonauthor_part($cflt->conflictType))) {
                    $pcconflicts[$cflt->email] = $confset->unparse_json($ct);
                }
            }
            if (!empty($pcconflicts)) {
                $pj->pc_conflicts = (object) $pcconflicts;
            }
            if (($collab = $prow->collaborators()) !== "") {
                $pj->collaborators = $collab;
            }
        }

        $this->ignore_msgs = $original_no_msgs;
        $this->user = $original_user;
        return $pj;
    }


    static function field_title(Conf $conf, $f) {
        $o = $conf->paper_opts->find($f);
        if (!$o) {
            if ($f === "title") {
                $o = $conf->paper_opts->get(PaperOption::TITLEID);
            } else if ($f === "abstract") {
                $o = $conf->paper_opts->get(PaperOption::ABSTRACTID);
            } else if ($f === "collaborators") {
                $o = $conf->paper_opts->get(PaperOption::COLLABORATORSID);
            } else if (str_starts_with($f, "au")) {
                $o = $conf->paper_opts->get(PaperOption::AUTHORSID);
            }
        }
        return $o ? htmlspecialchars($o->edit_title()) : false;
    }

    function msg_at_option(PaperOption $o, $msg, $status) {
        $this->msg_at($o->field_key(), $msg, $status);
    }
    function error_at_option(PaperOption $o, $msg) {
        $this->error_at($o->field_key(), $msg);
    }
    function warning_at_option(PaperOption $o, $msg) {
        $this->warning_at($o->field_key(), $msg);
    }
    function landmarked_message_texts() {
        $ms = [];
        foreach ($this->message_list() as $mx) {
            if ($mx[1]) {
                $t = $mx[0] ? (string) self::field_title($this->conf, $mx[0]) : "";
                $ms[] = $t ? "{$t}: {$mx[1]}" : $mx[1];
            }
        }
        return $ms;
    }

    function format_error_at($key, $value) {
        $this->error_at($key, "Format error [" . htmlspecialchars($key) . "]");
        error_log($this->conf->dbname . ": PaperStatus: format error $key " . gettype($value));
    }


    function document_import_check_filename($docj, PaperOption $o, PaperStatus $pstatus) {
        if (isset($docj->content_file)
            && is_string($docj->content_file)
            && !($docj instanceof DocumentInfo)) {
            if (!$this->allow_any_content_file && preg_match(',\A/|(?:\A|/)\.\.(?:/|\z),', $docj->content_file)) {
                $pstatus->error_at_option($o, "Bad content_file: only simple filenames allowed.");
                return false;
            }
            if ((string) $this->content_file_prefix !== "") {
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
            $this->format_error_at($o->field_key(), $docj);
            return null;
        } else if (get($docj, "error") || get($docj, "error_html")) {
            $this->error_at_option($o, get($docj, "error_html", "Upload error."));
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
                && ($docid = get($docj, "docid"))
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
                    "paperId" => $this->paperId, "sha1" => (string) $dochash,
                    "documentType" => $o->id
                ];
                foreach (["timestamp", "mimetype", "content", "content_base64",
                          "content_file", "metadata", "filename"] as $k) {
                    if (isset($docj->$k))
                        $args[$k] = $docj->$k;
                }
                DocumentInfo::fix_mimetype($args);
                $doc = new DocumentInfo($args, $this->conf, $this->prow);
            }
        }

        // save
        if ($doc->paperStorageId > 0 || $doc->save()) {
            $this->uploaded_documents[] = $doc->paperStorageId;
            return $doc;
        } else {
            error_log($doc->error_html);
            $this->error_at_option($o, $doc->error_html);
            return null;
        }
    }

    private function normalize_string($pj, $k, $simplify) {
        if (isset($pj->$k) && is_string($pj->$k)) {
            $pj->$k = $simplify ? simplify_whitespace($pj->$k) : trim($pj->$k);
        } else if (isset($pj->$k)) {
            $this->format_error_at($k, $pj->$k);
            unset($pj->$k);
        }
    }

    private function normalize_author($pj, $au, &$au_by_lemail) {
        $aux = Text::analyze_name($au);
        $aux->first = simplify_whitespace($aux->firstName);
        $aux->last = simplify_whitespace($aux->lastName);
        $aux->email = simplify_whitespace($aux->email);
        $aux->affiliation = simplify_whitespace((string) $aux->affiliation);
        // borrow from old author information
        if ($aux->email && $aux->first === "" && $aux->last === "" && $this->prow
            && ($old_au = $this->prow->author_by_email($aux->email))) {
            $aux->first = get($old_au, "first", "");
            $aux->last = get($old_au, "last", "");
            if ($aux->affiliation === "") {
                $aux->affiliation = get($old_au, "affiliation", "");
            }
        }
        // set contactness and author index
        if (is_object($au) && isset($au->contact)) {
            $aux->contact = !!$au->contact;
        }
        if (is_object($au) && isset($au->index) && is_int($au->index)) {
            $aux->index = $au->index;
        } else {
            $aux->index = count($pj->authors) + count($pj->bad_authors);
        }

        if ($aux->first !== "" || $aux->last !== ""
            || $aux->email !== "" || $aux->affiliation !== "") {
            $pj->authors[] = $aux;
        } else {
            $pj->bad_authors[] = $aux;
        }
        if ($aux->email) {
            $lemail = strtolower($aux->email);
            $au_by_lemail[$lemail] = $aux;
            if (!validate_email($lemail)
                && (!$this->prow || !$this->prow->author_by_email($lemail))) {
                $pj->bad_email_authors[] = $aux;
            }
        }
    }

    private function normalize_options($pj, $options) {
        // canonicalize option values to use IDs, not abbreviations
        $new_options = [];
        foreach ($options as $id => $oj) {
            $omatches = $this->conf->paper_opts->find_all($id);
            if (count($omatches) != 1) {
                $pj->bad_options[$id] = true;
            } else {
                $o = current($omatches);
                // XXX setting decision in JSON?
                if (($o->final && (!$this->prow || $this->prow->outcome <= 0))
                    || $o->id <= 0) {
                    continue;
                }
                $new_options[(string) $o->id] = $oj;
            }
        }
        $pj->options = (object) $new_options;
    }

    private function valid_contact($email) {
        global $Me;
        if ($email) {
            if (validate_email($email) || strcasecmp($email, $Me->email) == 0) {
                return true;
            }
            foreach ($this->prow ? $this->prow->contacts(true) : [] as $cflt) {
                if (strcasecmp($cflt->email, $email) == 0)
                    return true;
            }
        }
        return false;
    }

    private function normalize($pj) {
        // Errors prevent saving
        global $Now;

        // Authors
        $au_by_lemail = [];
        $pj->bad_authors = $pj->bad_email_authors = [];
        if (isset($pj->authors)) {
            if (is_array($pj->authors)) {
                $input_authors = $pj->authors;
            } else {
                $this->format_error_at("authors", $pj->authors);
                $input_authors = [];
            }
            $pj->authors = [];
            foreach ($input_authors as $k => $au) {
                if (is_string($au) || is_object($au)) {
                    $this->normalize_author($pj, $au, $au_by_lemail);
                } else {
                    $this->format_error_at("authors", $au);
                }
            }
        }

        // Status
        if (!isset($pj->submitted)) {
            if (isset($pj->draft)) {
                $pj->submitted = !$pj->draft;
            }
            if (isset($pj->status) && $pj->status === "submitted") {
                $pj->submitted = true;
            } else if (isset($pj->status) && $pj->status === "draft") {
                $pj->submitted = false;
            } else {
                $pj->submitted = $this->prow && $this->prow->timeSubmitted != 0;
            }
        }
        if (!isset($pj->draft)) {
            $pj->draft = !$pj->submitted;
        }
        if (!isset($pj->withdrawn)) {
            if (isset($pj->status) && $pj->status === "withdrawn") {
                $pj->withdrawn = true;
            } else {
                $pj->withdrawn = $this->prow && $this->prow->timeWithdrawn != 0;
            }
        }
        foreach (["withdrawn_at", "submitted_at", "final_submitted_at"] as $k) {
            if (isset($pj->$k)) {
                if (is_numeric($pj->$k)) {
                    $pj->$k = (int) $pj->$k;
                } else if (is_string($pj->$k)) {
                    $pj->$k = $this->conf->parse_time($pj->$k, $Now);
                } else {
                    $pj->$k = false;
                }
                if ($pj->$k === false || $pj->$k < 0) {
                    $pj->$k = $Now;
                }
            }
        }

        // Options
        $pj->bad_options = [];
        if (isset($pj->options)) {
            if (is_associative_array($pj->options) || is_object($pj->options)) {
                $this->normalize_options($pj, $pj->options);
            } else if (is_array($pj->options) && count($pj->options) == 1 && is_object($pj->options[0])) {
                $this->normalize_options($pj, $pj->options[0]);
            } else if ($pj->options === false) {
                $pj->options = (object) array();
            } else {
                $this->format_error_at("options", $pj->options);
                unset($pj->options);
            }
        }

        // load previous conflicts
        // old conflicts
        foreach ($this->prow ? $this->prow->conflicts(true) : [] as $cflt) {
            $this->_conflict_values[strtolower($cflt->email)] = [$cflt->conflictType, 0, 0];
        }

        // verify emails on authors marked as contacts
        $pj->bad_contacts = array();
        foreach ($pj->authors ?? [] as $au) {
            if (($au->contact ?? false)
                && (!isset($au->email) || !$this->valid_contact($au->email)))
                $pj->bad_contacts[] = $au;
        }

        // Contacts
        $contacts = $pj->contacts ?? null;
        if ($contacts !== null) {
            if (is_object($contacts) || is_array($contacts)) {
                $contacts = (array) $contacts;
            } else {
                $this->format_error_at("contacts", $contacts);
                $contacts = [];
            }
            $pj->contacts = [];
            // verify emails on explicitly named contacts
            foreach ($contacts as $k => $v) {
                if (!$v) {
                    continue;
                }
                if ($v === true) {
                    $v = (object) [];
                } else if (is_string($v) && is_int($k)) {
                    $v = trim($v);
                    if ($this->valid_contact($v))  {
                        $v = (object) ["email" => $v];
                    } else {
                        $v = Text::analyze_name($v);
                    }
                }
                if (is_object($v) && !($v->email ?? false) && is_string($k)) {
                    $v->email = $k;
                }
                if (is_object($v) && ($v->email ?? false)) {
                    if ($this->valid_contact($v->email)) {
                        $pj->contacts[] = (object) array_merge((array) get($au_by_lemail, strtolower($v->email)), (array) $v);
                    } else {
                        $pj->bad_contacts[] = $v;
                    }
                } else {
                    $this->format_error_at("contacts", $v);
                }
            }
        }

        // Inherit contactness
        if (isset($pj->authors) && $this->prow) {
            foreach ($this->prow->contacts(true) as $cflt) {
                if ($cflt->conflictType >= CONFLICT_CONTACTAUTHOR
                    && ($aux = $au_by_lemail[strtolower($cflt->email)] ?? null)
                    && !isset($aux->contact))
                    $aux->contact = true;
            }
        }

        // If user modifies paper, make them a contact (not just an author)
        if ($this->prow
            && !$this->user->allow_administer($this->prow)
            && $this->prow->conflict_type($this->user) === CONFLICT_AUTHOR) {
            if (!isset($pj->contacts)) {
                $pj->contacts = [];
                foreach ($this->prow->contacts(true) as $cflt) {
                    if ($cflt->conflictType >= CONFLICT_CONTACTAUTHOR)
                        $pj->contacts[] = (object) ["email" => $cflt->email];
                }
            }
            if (!array_filter($pj->contacts, function ($cflt) {
                    return strcasecmp($this->user->email, $cflt->email) === 0;
                })) {
                $pj->contacts[] = (object) ["email" => $this->user->email];
            }
        }
    }

    function save_paperf($f, $v) {
        assert(!isset($this->_paper_upd[$f]));
        $this->_paper_upd[$f] = $v;
    }

    function update_paperf($f, $v) {
        $this->_paper_upd[$f] = $v;
    }

    function update_paperf_overflow($f, $v) {
        $this->_paper_overflow_upd[$f] = $v;
    }

    function mark_diff($diff) {
        $this->diffs[$diff] = true;
    }

    static private function author_information($pj) {
        $x = "";
        foreach ($pj && get($pj, "authors") ? $pj->authors : [] as $au) {
            $x .= get($au, "first", get($au, "firstName", "")) . "\t"
                . get($au, "last", get($au, "lastName", "")) . "\t"
                . get($au, "email", "") . "\t"
                . get($au, "affiliation", "") . "\n";
        }
        return $x;
    }

    static function check_authors(PaperStatus $ps, $pj) {
        $authors = $pj->authors ?? null;
        $max_authors = $ps->conf->opt("maxAuthors");
        if ((is_array($authors) && empty($authors))
            || ($authors === null && (!$ps->prow || !$ps->prow->author_list()))) {
            $ps->error_at("authors", $ps->_("Entry required."));
        }
        if ($max_authors > 0 && is_array($authors) && count($authors) > $max_authors) {
            $ps->error_at("authors", $ps->_("Each submission can have at most %d authors.", $max_authors));
        }
        if (!empty($pj->bad_authors)) {
            $ps->error_at("authors", $ps->_("Some authors ignored."));
        }
        foreach ($pj->bad_email_authors as $aux) {
            $ps->error_at("authors", null);
            $ps->error_at("auemail" . $aux->index, $ps->_("“%s” is not a valid email address.", htmlspecialchars($aux->email)));
        }
        if (isset($pj->authors)
            && !$ps->has_error_at("authors")) {
            $v = convert_to_utf8(self::author_information($pj));
            if ($v !== ($ps->prow ? $ps->prow->authorInformation : "")) {
                $ps->save_paperf("authorInformation", $v);
                $ps->mark_diff("authors");
            }
        }
    }

    static function check_one_pdf(PaperOption $opt, PaperStatus $ps, $pj) {
        // store documents (XXX should attach to paper even if error)
        $k = $opt->json_key();
        if ($k === "paper"
            && !isset($pj->paper)
            && isset($pj->submission)) {
            $k = "submission";
        }
        $doc = null;
        if (isset($pj->$k) && $pj->$k) {
            $doc = $ps->upload_document($pj->$k, $opt);
        }
        if (isset($pj->$k) && !$ps->has_error_at($opt->json_key())) {
            $null_id = $opt->id ? 0 : 1;
            $new_id = $doc ? $doc->paperStorageId : $null_id;
            $prowk = $opt->id ? "finalPaperStorageId" : "paperStorageId";
            if ($new_id != ($ps->prow ? $ps->prow->$prowk : $null_id)) {
                $ps->save_paperf($prowk, $new_id);
                $ps->mark_diff($opt->json_key());
                $ps->_joindocs[$opt->id] = $doc;
                $ps->_documents_changed = true;
            }
        }
    }

    static function check_status(PaperStatus $ps, $pj) {
        global $Now;
        $pj_withdrawn = get($pj, "withdrawn");
        $pj_submitted = get($pj, "submitted");
        $pj_draft = get($pj, "draft");

        if ($ps->has_error()
            && $pj_submitted
            && !$pj_withdrawn
            && (!$ps->prow || $ps->prow->timeSubmitted == 0)) {
            $pj_submitted = false;
            $pj_draft = true;
        }

        $submitted = $pj_submitted;
        if (isset($pj->submitted_at)) {
            $submitted_at = $pj->submitted_at;
        } else if ($ps->prow) {
            $submitted_at = $ps->prow->submitted_at();
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
            if (!$ps->prow || $ps->prow->timeWithdrawn <= 0) {
                $ps->save_paperf("timeWithdrawn", get($pj, "withdrawn_at") ? : $Now);
                $ps->save_paperf("timeSubmitted", $submitted_at);
                $ps->mark_diff("status");
            } else if (($ps->prow->submitted_at() > 0) !== $pj_submitted) {
                $ps->save_paperf("timeSubmitted", $submitted_at);
                $ps->mark_diff("status");
            }
        } else if ($pj_submitted) {
            if (!$ps->prow || $ps->prow->timeSubmitted <= 0) {
                if ($submitted_at <= 0
                    || $submitted_at === PaperInfo::SUBMITTED_AT_FOR_WITHDRAWN) {
                    $submitted_at = $Now;
                }
                $ps->save_paperf("timeSubmitted", $submitted_at);
                $ps->mark_diff("status");
            }
            if ($ps->prow && $ps->prow->timeWithdrawn != 0) {
                $ps->save_paperf("timeWithdrawn", 0);
                $ps->mark_diff("status");
            }
        } else if ($ps->prow && ($ps->prow->timeWithdrawn > 0 || $ps->prow->timeSubmitted > 0)) {
            $ps->save_paperf("timeSubmitted", 0);
            $ps->save_paperf("timeWithdrawn", 0);
            $ps->mark_diff("status");
        }
        $ps->_paper_submitted = $pj_submitted && !$pj_withdrawn;
    }

    static function check_final_status(PaperStatus $ps, $pj) {
        global $Now;
        if (isset($pj->final_submitted)) {
            if ($pj->final_submitted) {
                $time = get($pj, "final_submitted_at") ? : $Now;
            } else {
                $time = 0;
            }
            if (!$ps->prow || $ps->prow->timeFinalSubmitted != $time) {
                $ps->save_paperf("timeFinalSubmitted", $time);
                $ps->mark_diff("final_status");
            }
        }
    }

    static function execute_topics(PaperStatus $ps) {
        if (isset($ps->_topic_ins)) {
            $ps->conf->qe("delete from PaperTopic where paperId=?", $ps->paperId);
            if (!empty($ps->_topic_ins)) {
                $ti = array_map(function ($tid) use ($ps) {
                    return [$ps->paperId, $tid];
                }, $ps->_topic_ins);
                $ps->conf->qe("insert into PaperTopic (paperId,topicId) values ?v", $ti);
            }
        }
    }

    static function check_one_option(PaperOption $opt, PaperStatus $ps, $oj) {
        if ($oj === null) {
            $ov = null;
        } else if ($oj instanceof PaperValue) {
            $ov = $oj;
        } else {
            $ov = $opt->parse_json($ps->_nnprow, $oj);
        }
        if ($ov !== null) {
            if (!$ov->has_error()) {
                $opt->value_store($ov, $ps);
            }
            $ps->_nnprow->set_new_option($ov);
            $ps->_field_values[$opt->id] = $ov;
        }
    }

    static function check_options(PaperStatus $ps, $pj) {
        if (!empty($pj->bad_options)) {
            $ps->warning_at("options", $ps->_("Unknown options ignored (%2\$s).", count($pj->bad_options), htmlspecialchars(join("; ", array_keys($pj->bad_options)))));
        }
        if (isset($pj->options)) {
            foreach ($pj->options as $oid => $oj) {
                $o = $ps->conf->paper_opts->get($oid);
                self::check_one_option($o, $ps, $oj);
            }
        }
    }

    private function validate_fields() {
        $max_status = 0;
        foreach ($this->conf->paper_opts->form_field_list($this->_nnprow) as $opt) {
            if ($opt->id <= 0 && $opt->type !== "intrinsic2") {
                continue;
            }
            $ov = $this->_nnprow->new_option($opt);
            $errorindex = count($ov->message_list());
            if (!$ov->has_error()) {
                $ov->option->value_check($ov, $this->user);
            }
            foreach ($ov->message_list() as $i => $m) {
                $max_status = max($max_status, $m[2]);
                if ($i < $errorindex || $m[2] >= MessageSet::ERROR) {
                    $this->msg_at($m[0], $m[1], $m[2]);
                }
            }
        }
        return $max_status < MessageSet::ESTOP;
    }

    private function save_fields() {
        foreach ($this->_field_values ?? [] as $ov) {
            $v1 = $ov->value_array();
            $d1 = $ov->data_array();
            $oldv = $this->_nnprow->force_option($ov->id);
            if ($v1 !== $oldv->value_array() || $d1 !== $oldv->data_array()) {
                $this->mark_diff($ov->option->json_key());
                if (!$ov->option->value_save($ov, $this)) {
                    // normal option
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

    static function execute_options(PaperStatus $ps) {
        if (!empty($ps->_option_delid)) {
            $ps->conf->qe("delete from PaperOption where paperId=? and optionId?a", $ps->paperId, $ps->_option_delid);
        }
        if (!empty($ps->_option_ins)) {
            foreach ($ps->_option_ins as &$x) {
                $x[0] = $ps->paperId;
            }
            $ps->conf->qe("insert into PaperOption (paperId, optionId, value, data, dataOverflow) values ?v", $ps->_option_ins);
        }
    }

    /** @param string $email
     * @param int $mask
     * @param int $new */
    function update_conflict_value($email, $mask, $new) {
        $lemail = strtolower($email);
        assert(($new & $mask) === $new);
        if (!isset($this->_conflict_values[$lemail])) {
            $this->_conflict_values[$lemail] = [0, 0, 0];
        }
        $cv = &$this->_conflict_values[$lemail];
        if ($mask
            && ($mask !== ((CONFLICT_AUTHOR - 1) & ~1)
                || ((($cv[0] & ~$cv[1]) | $cv[2]) & 1) === 0)) {
            $cv[1] |= $mask;
            $cv[2] = ($cv[2] & ~$mask) | $new;
        }
    }

    /** @param ?array{int,int,int} $cv
     * @return int */
    static function new_conflict_value($cv) {
        return $cv ? ($cv[0] & ~$cv[1]) | $cv[2] : 0;
    }

    private function check_conflicts($pj) {
        // new authors
        if (isset($pj->authors)) {
            foreach ($this->_conflict_values as &$cv) {
                $cv[1] |= CONFLICT_AUTHOR;
                $cv[2] &= ~CONFLICT_AUTHOR;
            }
            unset($cv);
            foreach ($pj->authors as $aux) {
                if (isset($aux->email)) {
                    $this->update_conflict_value($aux->email, CONFLICT_AUTHOR, CONFLICT_AUTHOR);
                    if (isset($aux->contact)) {
                        $this->update_conflict_value($aux->email, CONFLICT_CONTACTAUTHOR, $aux->contact ? CONFLICT_CONTACTAUTHOR : 0);
                    }
                }
            }
        }

        // new contacts
        if (isset($pj->contacts)) {
            foreach ($this->_conflict_values as &$cv) {
                if (($cv[0] & (CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR)) === CONFLICT_CONTACTAUTHOR) {
                    $cv[1] |= CONFLICT_CONTACTAUTHOR;
                    $cv[2] &= ~CONFLICT_CONTACTAUTHOR;
                }
            }
            unset($cv);
            foreach ($pj->contacts as $aux) {
                $this->update_conflict_value($aux->email, CONFLICT_CONTACTAUTHOR, CONFLICT_CONTACTAUTHOR);
            }
        }

        // check for errors
        if ($this->prow) {
            if (!array_filter($this->_conflict_values, function ($cv) { return (self::new_conflict_value($cv) & CONFLICT_CONTACTAUTHOR) !== 0; })
                && array_filter($this->prow->contacts(), function ($cflt) { return $cflt->conflictType >= CONFLICT_CONTACTAUTHOR; })) {
                $this->error_at("contacts", $this->_("Each submission must have at least one contact."));
            }

            if (!$this->user->allow_administer($this->prow)
                && self::new_conflict_value($this->_conflict_values[strtolower($this->user->email)] ?? null) < CONFLICT_AUTHOR) {
                $this->error_at("contacts", $this->_("You can’t remove yourself as submission contact. (Ask another contact to remove you.)"));
            }
        }

        foreach ($pj->bad_contacts as $reg) {
            $key = "contacts";
            if (isset($reg->index) && is_int($reg->index)) {
                $key = (isset($reg->is_new) && $reg->is_new === true ? "newcontact_email_" : "contact_") . $reg->index;
            }
            if (!isset($reg->email)) {
                $this->error_at($key, $this->_("Contact %s has no associated email.", Text::user_html($reg)));
            } else {
                $this->error_at($key, $this->_("Contact email %s is invalid.", htmlspecialchars($reg->email)));
            }
            $this->error_at("contacts", false);
        }
    }

    private function check_contacts_create_user($lemail, $pj) {
        $c = null;
        foreach ($pj->authors ?? [] as $aux) {
            if (isset($aux->email)
                && strcasecmp($aux->email, $lemail) === 0
                && validate_email($aux->email)) {
                $c = clone $aux;
            }
        }
        foreach ($pj->contacts ?? [] as $aux) {
            if (strcasecmp($aux->email, $lemail) === 0) {
                $c = $c ?? (object) [];
                foreach (get_object_vars($aux) as $k => $v) {
                    $c->$k = $v;
                }
            }
        }
        if ($c) {
            $c->disabled = !!$this->disable_users;
            $flags = $c->contact ?? false ? 0 : Contact::SAVE_IMPORT;
            $u = Contact::create($this->conf, $this->user, $c, $flags);
            if ($u) {
                if ($u->password_unset()
                    && !$u->activity_at
                    && !$u->isPC
                    && !$u->is_disabled()) {
                    $this->_created_contacts[] = $u;
                }
            } else if (!($flags & Contact::SAVE_IMPORT)) {
                $key = "contacts";
                if (isset($c->index) && is_int($c->index)) {
                    $key = (isset($c->is_new) && $c->is_new === true ? "newcontact_" : "contact_") . $c->index;
                }
                $this->error_at($key, $this->_("Could not create an account for contact %s.", Text::user_html($c)));
                $this->error_at("contacts", false);
            }
        }
    }

    private function check_contacts_last($pj) {
        // check for differences
        $new_contact_lemail = [];
        $diff_lemail = [];
        foreach ($this->_conflict_values ?? [] as $lemail => $cv) {
            if (($cv[0] ^ self::new_conflict_value($cv)) & (CONFLICT_AUTHOR - 1)) {
                $this->diffs["pc_conflicts"] = true;
            }
            if (($cv[0] ^ self::new_conflict_value($cv)) >= CONFLICT_AUTHOR) {
                $this->diffs["contacts"] = true;
                if ($cv[0] < CONFLICT_AUTHOR) {
                    $new_contact_lemail[] = $lemail;
                }
            }
            if ($cv[0] !== self::new_conflict_value($cv)) {
                $diff_lemail[] = $lemail;
            }
        }

        // create new contacts
        if (!$this->has_error_at("contacts")) {
            foreach ($new_contact_lemail as $lemail) {
                $this->check_contacts_create_user($lemail, $pj);
            }
        }

        // transform values
        if (!empty($diff_lemail)
            && !$this->has_error_at("contacts")
            && !$this->has_error_at("pc_conflicts")) {
            $this->_conflict_ins = [];
            $result = $this->conf->qe("select contactId, email from ContactInfo where email?a", $diff_lemail);
            while (($row = $result->fetch_row())) {
                /** @phan-suppress-next-line PhanTypeArraySuspiciousNullable */
                $cv = $this->_conflict_values[strtolower($row[1])];
                $this->_conflict_ins[] = [(int) $row[0], $cv[1], $cv[2]];
            }
            Dbl::free($result);
        }
    }

    private function execute_conflicts() {
        if ($this->_conflict_ins !== null) {
            $cfltf = Dbl::make_multi_query_stager($this->conf->dblink, Dbl::F_ERROR);
            $auflags = CONFLICT_AUTHOR | CONFLICT_CONTACTAUTHOR;
            foreach ($this->_conflict_ins as $ci) {
                if (($ci[1] & (CONFLICT_AUTHOR - 1)) === ((CONFLICT_AUTHOR - 1) & ~1)) {
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
                $u->send_mail("@newaccount.paper", $rest);
            }
        }
    }

    function prepare_save_paper_json($pj) {
        assert(!$this->hide_docids);
        assert(is_object($pj));

        $paperid = get($pj, "pid", get($pj, "id", null));
        if ($paperid !== null && is_int($paperid) && $paperid <= 0) {
            $paperid = null;
        }
        if ($paperid !== null && !is_int($paperid)) {
            $key = isset($pj->pid) ? "pid" : "id";
            $this->format_error_at($key, $paperid);
            return false;
        }

        if (($pj->error ?? null) || ($pj->error_html ?? null)) {
            $this->error_at("error", $this->_("Refusing to save submission with error"));
            return false;
        }

        $this->clear();
        $this->paperId = $paperid ? : -1;
        if ($paperid) {
            $this->prow = $this->conf->paper_by_id($paperid, $this->user, ["topics" => true, "options" => true]);
        }
        if ($this->prow && $paperid !== $this->prow->paperId) {
            $this->error_at("pid", $this->_("Saving submission with different ID"));
            return false;
        }
        $this->_nnprow = $this->prow ? : PaperInfo::make_new($this->user);

        // normalize and check format
        $this->normalize($pj);
        if ($this->has_error()) {
            return false;
        }

        // save parts and track diffs
        $opts = $this->conf->paper_opts;
        self::check_one_option($opts->get(PaperOption::TITLEID), $this, $pj->title ?? null);
        self::check_one_option($opts->get(PaperOption::ABSTRACTID), $this, $pj->abstract ?? null);
        self::check_authors($this, $pj);
        self::check_one_option($opts->get(PaperOption::COLLABORATORSID), $this, $pj->collaborators ?? null);
        self::check_one_option($opts->get(PaperOption::ANONYMITYID), $this, $pj->nonblind ?? null);
        self::check_one_option($opts->get(PaperOption::PCCONFID), $this, $pj->pc_conflicts ?? null);
        $this->check_conflicts($pj);
        self::check_one_pdf($opts->get(DTYPE_SUBMISSION), $this, $pj);
        self::check_one_pdf($opts->get(DTYPE_FINAL), $this, $pj);
        self::check_one_option($opts->get(PaperOption::TOPICSID), $this, $pj->topics ?? null);
        self::check_options($this, $pj);
        self::check_status($this, $pj);
        self::check_final_status($this, $pj);

        // don't save if serious error
        if (!$this->validate_fields()) {
            return false;
        }

        $this->save_fields();
        if ($this->conf->submission_blindness() != Conf::BLIND_OPTIONAL) {
            $want_blind = $this->conf->submission_blindness() != Conf::BLIND_NEVER;
            if (!$this->prow || (bool) $this->prow->blind !== $want_blind) {
                $this->save_paperf("blind", $want_blind ? 1 : 0);
                if ($this->prow) {
                    $this->mark_diff("blind");
                }
            }
        }
        $this->check_contacts_last($pj);
        return true;
    }

    private function unused_random_pid() {
        $n = max(100, 3 * $this->conf->fetch_ivalue("select count(*) from Paper"));
        while (1) {
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

    static function preexecute_set_default_columns(PaperStatus $ps) {
        foreach (["title" => "", "abstract" => "", "authorInformation" => "",
                  "paperStorageId" => 1, "finalPaperStorageId" => 0] as $f => $v) {
            if (!isset($ps->_paper_upd[$f])) {
                $ps->_paper_upd[$f] = $v;
            }
        }
    }

    static function postexecute_check_required_options(PaperStatus $ps) {
        $prow = null;
        $required_failure = false;
        foreach ($ps->conf->paper_opts->option_list() as $o) {
            if (!$o->required) {
                continue;
            }
            if (!$prow) {
                $prow = $ps->conf->paper_by_id($ps->paperId, $ps->user, ["options" => true]);
            }
            if ($ps->user->can_edit_option($prow, $o)
                && $o->test_required($prow)
                && !$o->value_present($prow->force_option($o->id))) {
                $ps->error_at_option($o, "Entry required.");
                $required_failure = true;
            }
        }
        if ($required_failure
            && (!$ps->prow || $ps->prow->timeSubmitted == 0)) {
            // Some required option was missing and the paper was not submitted
            // before, so it shouldn't be submitted now.
            $ps->conf->qe("update Paper set timeSubmitted=0 where paperId=?", $ps->paperId);
            $ps->_paper_submitted = false;
        }
    }

    function execute_save() {
        global $Now;
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
            $need_insert = $this->paperId <= 0;
            if ($need_insert) {
                self::preexecute_set_default_columns($this);
            }

            $old_joindoc = $this->prow ? $this->prow->joindoc() : null;
            $old_joinid = $old_joindoc ? $old_joindoc->paperStorageId : 0;

            if ($this->_joindocs[DTYPE_FINAL] ?? null) {
                $new_joindoc = $this->_joindocs[DTYPE_FINAL];
            } else if (!isset($this->_joindocs[DTYPE_FINAL])
                       && $this->prow
                       && $this->prow->finalPaperStorageId > 0) {
                $new_joindoc = $this->prow->document(DTYPE_FINAL);
            } else if ($this->_joindocs[DTYPE_SUBMISSION] ?? null) {
                $new_joindoc = $this->_joindocs[DTYPE_SUBMISSION];
            } else if (!isset($this->_joindocs[DTYPE_SUBMISSION])
                       && $this->prow
                       && $this->prow->paperStorageId > 1) {
                $new_joindoc = $this->prow->document(DTYPE_SUBMISSION);
            } else {
                $new_joindoc = null;
            }
            $new_joinid = $new_joindoc ? $new_joindoc->paperStorageId : 0;

            if ($new_joindoc && $new_joinid != $old_joinid) {
                if ($new_joindoc->ensure_size()) {
                    $this->save_paperf("size", $new_joindoc->size);
                } else {
                    $this->save_paperf("size", 0);
                }
                $this->save_paperf("mimetype", $new_joindoc->mimetype);
                $this->save_paperf("sha1", $new_joindoc->binary_hash());
                $this->save_paperf("timestamp", $new_joindoc->timestamp);
                if ($this->conf->sversion >= 145) {
                    $this->save_paperf("pdfFormatStatus", 0);
                }
            } else if (!$this->prow || $new_joinid != $old_joinid) {
                $this->save_paperf("size", 0);
                $this->save_paperf("mimetype", "");
                $this->save_paperf("sha1", "");
                $this->save_paperf("timestamp", 0);
                if ($this->conf->sversion >= 145) {
                    $this->save_paperf("pdfFormatStatus", 0);
                }
            }

            $this->save_paperf("timeModified", $Now);

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
                if (($random_pids = $this->conf->setting("random_pids"))) {
                    $this->conf->qe("lock tables Paper write");
                    $this->_paper_upd["paperId"] = $this->unused_random_pid();
                }
                $result = $this->conf->qe_apply("insert into Paper set " . join("=?, ", array_keys($this->_paper_upd)) . "=?", array_values($this->_paper_upd));
                if ($random_pids) {
                    $this->conf->qe("unlock tables");
                }
                if (Dbl::is_error($result) || !$result->insert_id) {
                    return $this->error_at(false, $this->_("Could not create paper."));
                }
                $this->paperId = (int) $result->insert_id;
                if (!empty($this->uploaded_documents)) {
                    $this->conf->qe("update PaperStorage set paperId=? where paperStorageId?a", $this->paperId, $this->uploaded_documents);
                }
            }
        }

        self::execute_topics($this);
        self::execute_options($this);
        $this->execute_conflicts();

        if ($this->_paper_submitted) {
            self::postexecute_check_required_options($this);
        }

        // maybe update `papersub` settings
        $was_submitted = $this->prow
            && $this->prow->timeWithdrawn <= 0
            && $this->prow->timeSubmitted > 0;
        if ($this->_paper_submitted != $was_submitted) {
            $this->conf->update_papersub_setting($this->_paper_submitted ? 1 : -1);
        }

        // update autosearch
        $this->conf->update_autosearch_tags($this->paperId);

        // update document inactivity
        if ($this->_documents_changed
            && ($prow = $this->conf->paper_by_id($this->paperId, null, ["options" => true]))) {
            $prow->mark_inactive_documents();
        }

        return true;
    }

    function save_paper_json($pj) {
        if ($this->prepare_save_paper_json($pj)) {
            $this->execute_save();
            return $this->paperId;
        } else {
            return false;
        }
    }
}
