<?php
// paperstatus.php -- HotCRP helper for reading/storing papers as JSON
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class PaperStatus extends MessageSet {
    public $conf;
    public $user;
    private $uploaded_documents;
    private $no_email = false;
    private $export_ids = false;
    private $hide_docids = false;
    private $export_content = false;
    private $disable_users = false;
    private $allow_any_content_file = false;
    private $content_file_prefix = false;
    private $add_topics = false;
    public $prow;
    public $paperId;
    private $_on_document_export = [];
    private $_on_document_import = [];

    public $diffs;
    private $_paper_upd;
    private $_topic_ins;
    private $_option_delid;
    private $_option_ins;
    private $_new_conflicts;
    private $_conflict_ins;
    private $_paper_submitted;
    private $_document_change;

    function __construct(Conf $conf, Contact $user = null, $options = array()) {
        $this->conf = $conf;
        $this->user = $user;
        foreach (array("no_email", "export_ids", "hide_docids",
                       "export_content", "disable_users",
                       "allow_any_content_file", "content_file_prefix",
                       "add_topics") as $k)
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        $this->_on_document_import[] = [$this, "document_import_check_filename"];
        $this->clear();
    }

    function clear() {
        parent::clear();
        $this->uploaded_documents = [];
        $this->prow = null;
        $this->diffs = [];
        $this->_paper_upd = [];
        $this->_topic_ins = null;
        $this->_option_delid = $this->_option_ins = [];
        $this->_new_conflicts = $this->_conflict_ins = null;
        $this->_paper_submitted = $this->_document_change = null;
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

    function paper_row() {
        return $this->prow;
    }

    function _() {
        return call_user_func_array([$this->conf->ims(), "x"], func_get_args());
    }

    function document_to_json($dtype, $docid) {
        if (!is_object($docid))
            $doc = $this->prow ? $this->prow->document($dtype, $docid) : null;
        else {
            $doc = $docid;
            $docid = $doc->paperStorageId;
        }
        if (!$doc)
            return null;
        assert($doc instanceof DocumentInfo);

        $d = (object) array();
        if ($docid && !$this->hide_docids)
            $d->docid = $docid;
        if ($doc->mimetype)
            $d->mimetype = $doc->mimetype;
        if ($doc->has_hash())
            $d->hash = $doc->text_hash();
        if ($doc->timestamp)
            $d->timestamp = $doc->timestamp;
        if ($doc->size)
            $d->size = $doc->size;
        if ($doc->filename)
            $d->filename = $doc->filename;
        $meta = null;
        if (isset($doc->infoJson) && is_object($doc->infoJson))
            $meta = $doc->infoJson;
        else if (isset($doc->infoJson) && is_string($doc->infoJson))
            $meta = json_decode($doc->infoJson);
        if ($meta)
            $d->metadata = $meta;
        if ($this->export_content
            && ($content = $doc->content()) !== false)
            $d->content_base64 = base64_encode($content);
        foreach ($this->_on_document_export as $cb)
            if (call_user_func($cb, $d, $doc, $dtype, $this) === false)
                return null;
        if (!count(get_object_vars($d)))
            $d = null;
        return $d;
    }

    function paper_json($prow, $args = array()) {
        if (is_int($prow))
            $prow = $this->conf->paperRow(["paperId" => $prow, "topics" => true, "options" => true], $this->user);
        $original_user = $user = $this->user;
        if (get($args, "forceShow"))
            $user = null;

        if (!$prow || ($user && !$user->can_view_paper($prow)))
            return null;
        $this->user = $user;
        $original_no_msgs = $this->ignore_msgs;
        $this->ignore_msgs = !get($args, "msgs");

        $this->prow = $prow;
        $this->paperId = $prow->paperId;

        $pj = (object) array();
        $pj->pid = (int) $prow->paperId;
        $pj->title = $prow->title;

        $submitted_status = "submitted";
        if ($prow->outcome != 0
            && (!$user || $user->can_view_decision($prow))) {
            $pj->decision = $this->conf->decision_name($prow->outcome);
            if ($pj->decision === false) {
                $pj->decision = (int) $prow->outcome;
                $submitted_status = $pj->decision > 0 ? "accepted" : "rejected";
            } else
                $submitted_status = $pj->decision;
        }

        if ($prow->timeWithdrawn > 0) {
            $pj->status = "withdrawn";
            $pj->withdrawn = true;
            $pj->withdrawn_at = (int) $prow->timeWithdrawn;
            if (get($prow, "withdrawReason"))
                $pj->withdraw_reason = $prow->withdrawReason;
        } else if ($prow->timeSubmitted > 0) {
            $pj->status = $submitted_status;
            $pj->submitted = true;
        } else {
            $pj->status = "inprogress";
            $pj->draft = true;
        }
        if (($t = $prow->submitted_at()))
            $pj->submitted_at = $t;

        $can_view_authors = !$user
            || $user->can_view_authors($prow);
        if ($can_view_authors) {
            $contacts = array();
            foreach ($prow->named_contacts() as $cflt)
                $contacts[strtolower($cflt->email)] = $cflt;

            $pj->authors = array();
            foreach ($prow->author_list() as $au) {
                $aux = (object) array();
                if ($au->email)
                    $aux->email = $au->email;
                if ($au->firstName)
                    $aux->first = $au->firstName;
                if ($au->lastName)
                    $aux->last = $au->lastName;
                if ($au->affiliation)
                    $aux->affiliation = $au->affiliation;
                $lemail = strtolower((string) $au->email);
                if ($lemail && ($cflt = get($contacts, $lemail))
                    && $cflt->conflictType >= CONFLICT_AUTHOR) {
                    $aux->contact = true;
                    unset($contacts[$lemail]);
                }
                $pj->authors[] = $aux;
            }

            $other_contacts = array();
            foreach ($contacts as $cflt)
                if ($cflt->conflictType >= CONFLICT_AUTHOR) {
                    $aux = (object) array("email" => $cflt->email);
                    if ($cflt->firstName)
                        $aux->first = $cflt->firstName;
                    if ($cflt->lastName)
                        $aux->last = $cflt->lastName;
                    if ($cflt->affiliation)
                        $aux->affiliation = $cflt->affiliation;
                    $other_contacts[] = $aux;
                }
            if (!empty($other_contacts))
                $pj->contacts = $other_contacts;
        }

        if ($this->conf->submission_blindness() == Conf::BLIND_OPTIONAL)
            $pj->nonblind = !$prow->blind;

        if ($prow->abstract !== "" || !$this->conf->opt("noAbstract"))
            $pj->abstract = $prow->abstract;

        $topics = array();
        foreach ($prow->named_topic_map() as $tid => $tname)
            $topics[$this->export_ids ? $tid : $tname] = true;
        if (!empty($topics))
            $pj->topics = (object) $topics;

        if ($prow->paperStorageId > 1
            && (!$user || $user->can_view_pdf($prow))
            && ($doc = $this->document_to_json(DTYPE_SUBMISSION, (int) $prow->paperStorageId)))
            $pj->submission = $doc;

        if ($prow->finalPaperStorageId > 1
            && (!$user || $user->can_view_pdf($prow))
            && ($doc = $this->document_to_json(DTYPE_FINAL, (int) $prow->finalPaperStorageId)))
            $pj->final = $doc;
        if ($prow->timeFinalSubmitted > 0) {
            $pj->final_submitted = true;
            $pj->final_submitted_at = (int) $prow->timeFinalSubmitted;
        }

        $options = array();
        foreach ($this->conf->paper_opts->option_list() as $o) {
            if ($user && !$user->can_view_paper_option($prow, $o))
                continue;
            $ov = $prow->option($o->id) ? : new PaperOptionValue($prow, $o);
            $oj = $o->unparse_json($ov, $this, $user);
            if ($oj !== null)
                $options[$this->export_ids ? $o->id : $o->json_key()] = $oj;
        }
        if (!empty($options))
            $pj->options = (object) $options;

        if ($can_view_authors) {
            $pcconflicts = array();
            foreach ($prow->pc_conflicts(true) as $id => $cflt) {
                if (($ctname = get(Conflict::$type_names, $cflt->conflictType)))
                    $pcconflicts[$cflt->email] = $ctname;
            }
            if (!empty($pcconflicts))
                $pj->pc_conflicts = (object) $pcconflicts;
            if ($prow->collaborators)
                $pj->collaborators = $prow->collaborators;
        }

        // Now produce messages.
        if (!$this->ignore_msgs
            && $pj->title === "")
            $this->error_at("title", $this->_("Entry required."));
        if (!$this->ignore_msgs
            && (!isset($pj->abstract) || $pj->abstract === "")
            && !$this->conf->opt("noAbstract"))
            $this->error_at("abstract", $this->_("Entry required."));
        if (!$this->ignore_msgs
            && $can_view_authors) {
            $msg1 = $msg2 = false;
            foreach ($prow->author_list() as $n => $au) {
                if (strpos($au->email, "@") === false
                    && strpos($au->affiliation, "@") !== false) {
                    $msg1 = true;
                    $this->warning_at("author" . ($n + 1), null);
                } else if ($au->firstName === "" && $au->lastName === ""
                           && $au->email === "" && $au->affiliation !== "") {
                    $msg2 = true;
                    $this->warning_at("author" . ($n + 1), null);
                }
            }
            $max_authors = $this->conf->opt("maxAuthors");
            if (!$prow->author_list()) {
                $this->error_at("authors", $this->_("Entry required."));
                $this->error_at("author1", false);
            }
            if ($max_authors > 0 && count($prow->author_list()) > $max_authors)
                $this->error_at("authors", $this->_("Each submission can have at most %d authors.", $max_authors));
            if ($msg1)
                $this->warning_at("authors", "You may have entered an email address in the wrong place. The first author field is for author name, the second for email address, and the third for affiliation.");
            if ($msg2)
                $this->warning_at("authors", "Please enter a name and optional email address for every author.");
        }
        if (!$this->ignore_msgs
            && $can_view_authors
            && $this->conf->setting("sub_collab")
            && ($prow->outcome <= 0 || ($user && !$user->can_view_decision($prow)))
            && !$prow->collaborators) {
            $this->warning_at("collaborators", $this->_("Enter the authors’ external conflicts of interest. If none of the authors have external conflicts, enter “None”."));
        }
        if (!$this->ignore_msgs
            && $can_view_authors
            && $this->conf->setting("sub_pcconf")
            && ($prow->outcome <= 0 || ($user && !$user->can_view_decision($prow)))) {
            $pcs = [];
            foreach ($this->conf->full_pc_members() as $p) {
                if (!$prow->has_conflict($p)
                    && $prow->potential_conflict($p))
                    $pcs[] = Text::name_html($p);
            }
            if (!empty($pcs))
                $this->warning_at("pcconf", $this->_("You may have missed conflicts of interest with %s. Please verify that all conflicts are correctly marked. Hover over “possible conflict” labels for more information.", commajoin($pcs, "and")));
        }

        $this->ignore_msgs = $original_no_msgs;
        $this->user = $original_user;
        return $pj;
    }


    static function field_title(Conf $conf, $f) {
        if (($o = $conf->paper_opts->find($f)))
            return $conf->_c("paper_field/edit", htmlspecialchars($o->title));
        else if ($f === "title")
            return $conf->_c("paper_field/edit", "Title");
        else if ($f === "abstract")
            return $conf->_c("paper_field/edit", "Abstract");
        else if ($f === "collaborators")
            return $conf->_c("paper_field/edit", "Collaborators", $conf->setting("sub_pcconf"));
        else if (str_starts_with($f, "au"))
            return $conf->_c("paper_field/edit", "Authors", (int) $conf->opt("maxAuthors"));
        else
            return false;
    }

    function error_at_option(PaperOption $o, $html) {
        $this->error_at($o->field_key(), $html);
    }
    function warning_at_option(PaperOption $o, $html) {
        $this->warning_at($o->field_key(), $html);
    }
    function landmarked_messages() {
        $ms = [];
        foreach ($this->messages(true) as $mx)
            if ($mx[1]) {
                $t = $mx[0] ? (string) self::field_title($this->conf, $mx[0]) : "";
                $ms[] = $t ? "{$t}: {$mx[1]}" : $mx[1];
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
            if ((string) $this->content_file_prefix !== "")
                $docj->content_file = $this->content_file_prefix . $docj->content_file;
        }
    }

    function upload_document($docj, PaperOption $o) {
        // $docj can be a DocumentInfo or a JSON.
        // If it is a JSON, its format is set by document_to_json.
        if (is_array($docj) && count($docj) === 1 && isset($docj[0]))
            $docj = $docj[0];
        if (!is_object($docj)) {
            $this->format_error_at($o->field_key(), $docj);
            return null;
        } else if (get($docj, "error") || get($docj, "error_html")) {
            $this->error_at_option($o, get($docj, "error_html", "Upload error."));
            return null;
        }
        assert(!isset($docj->filter));

        // check on_document_import
        foreach ($this->_on_document_import as $cb)
            if (call_user_func($cb, $docj, $o, $this) === false)
                return null;

        // validate JSON
        if ($docj instanceof DocumentInfo)
            $doc = $docj;
        else {
            $doc = null;
            if (!isset($docj->hash) && isset($docj->sha1))
                $dochash = (string) Filer::sha1_hash_as_text($docj->sha1);
            else
                $dochash = (string) get($docj, "hash");

            if ($this->prow
                && ($docid = get($docj, "docid"))
                && is_int($docid)) {
                $result = $this->conf->qe("select * from PaperStorage where paperId=? and paperStorageId=? and documentType=?", $this->prow->paperId, $docid, $o->id);
                $doc = DocumentInfo::fetch($result, $this->conf, $this->prow);
                Dbl::free($result);
                if (!$doc || ($dochash !== "" && !Filer::check_text_hash($doc->sha1, $dochash)))
                    $doc = null;
            }

            if (!$doc) {
                $args = ["paperId" => $this->paperId, "sha1" => $dochash, "documentType" => $o->id];
                foreach (["timestamp", "mimetype", "content", "content_base64",
                          "content_file", "metadata", "filename"] as $k)
                    if (isset($docj->$k))
                        $args[$k] = $docj->$k;
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
            return false;
        }
    }

    private function normalize_string($pj, $k, $simplify) {
        if (isset($pj->$k) && is_string($pj->$k)) {
            $pj->$k = $simplify ? simplify_whitespace($pj->$k) : trim($pj->$k);
        } else if (isset($pj->$k)) {
            $this->format_error_at($k, $pj->$k);
            unset($pj, $k);
        }
    }

    private function normalize_author($pj, $au, &$au_by_lemail) {
        $aux = Text::analyze_name($au);
        $aux->first = simplify_whitespace($aux->firstName);
        $aux->last = simplify_whitespace($aux->lastName);
        $aux->email = simplify_whitespace($aux->email);
        $aux->affiliation = simplify_whitespace($aux->affiliation);
        // borrow from old author information
        if ($aux->email && $aux->first === "" && $aux->last === "" && $this->prow
            && ($old_au = $this->prow->author_by_email($aux->email))) {
            $aux->first = get($old_au, "first", "");
            $aux->last = get($old_au, "last", "");
            if ($aux->affiliation === "")
                $aux->affiliation = get($old_au, "affiliation", "");
        }
        // set contactness and author index
        if (is_object($au) && isset($au->contact))
            $aux->contact = !!$au->contact;
        if (is_object($au) && isset($au->index) && is_int($au->index))
            $aux->index = $au->index;
        else
            $aux->index = count($pj->authors) + count($pj->bad_authors);

        if ($aux->first !== "" || $aux->last !== ""
            || $aux->email !== "" || $aux->affiliation !== "")
            $pj->authors[] = $aux;
        else
            $pj->bad_authors[] = $aux;
        if ($aux->email) {
            $lemail = strtolower($aux->email);
            $au_by_lemail[$lemail] = $aux;
            if (!validate_email($lemail)
                && (!$this->prow || !$this->prow->author_by_email($lemail)))
                $pj->bad_email_authors[] = $aux;
        }
    }

    private function normalize_topics($pj) {
        $topics = $pj->topics;
        unset($pj->topics);
        if (is_string($topics))
            $topics = explode("\n", cleannl($topics));
        if (is_array($topics)) {
            $new_topics = (object) array();
            foreach ($topics as $v) {
                if ($v && (is_int($v) || is_string($v)))
                    $new_topics->$v = true;
                else if ($v)
                    $this->format_error_at("topics", $v);
            }
            $topics = $new_topics;
        }
        if (is_object($topics)) {
            $topic_map = $this->conf->topic_map();
            $pj->topics = (object) array();
            foreach ($topics as $k => $v) {
                if (!$v)
                    /* skip */;
                else if (isset($topic_map[$k]))
                    $pj->topics->$k = true;
                else {
                    $tid = array_search($k, $topic_map, true);
                    if ($tid === false && $k !== "" && !ctype_digit($k)) {
                        $tmatches = [];
                        foreach ($topic_map as $tid => $tname)
                            if (strcasecmp($k, $tname) == 0)
                                $tmatches[] = $tid;
                        if (empty($tmatches) && $this->add_topics) {
                            $this->conf->qe("insert into TopicArea set topicName=?", $k);
                            if (!$this->conf->has_topics())
                                $this->conf->save_setting("has_topics", 1);
                            $this->conf->invalidate_topics();
                            $topic_map = $this->conf->topic_map();
                            if (($tid = array_search($k, $topic_map, true)) !== false)
                                $tmatches[] = $tid;
                        }
                        $tid = (count($tmatches) == 1 ? $tmatches[0] : false);
                    }
                    if ($tid !== false)
                        $pj->topics->$tid = true;
                    else
                        $pj->bad_topics[] = $k;
                }
            }
        } else if ($topics)
            $this->format_error_at("topics", $topics);
    }

    private function normalize_options($pj, $options) {
        // canonicalize option values to use IDs, not abbreviations
        $pj->options = (object) array();
        foreach ($options as $id => $oj) {
            $omatches = $this->conf->paper_opts->find_all($id);
            if (count($omatches) != 1)
                $pj->bad_options[$id] = true;
            else {
                $o = current($omatches);
                // XXX setting decision in JSON?
                if (($o->final && (!$this->prow || $this->prow->outcome <= 0))
                    || $o->id <= 0)
                    continue;
                $oid = $o->id;
                $pj->options->$oid = $oj;
            }
        }
    }

    private function normalize_pc_conflicts($pj) {
        $conflicts = get($pj, "pc_conflicts");
        $pj->pc_conflicts = (object) array();
        if (is_object($conflicts))
            $conflicts = (array) $conflicts;
        foreach ($conflicts as $email => $ct) {
            if (is_int($email) && is_string($ct))
                list($email, $ct) = array($ct, true);
            if (!($pccid = $this->conf->pc_member_by_email($email)))
                $pj->bad_pc_conflicts->$email = true;
            else if (!is_bool($ct) && !is_int($ct) && !is_string($ct))
                $this->format_error_at("pc_conflicts", $ct);
            else {
                if (is_int($ct) && isset(Conflict::$type_names[$ct]))
                    $ctn = $ct;
                else if ((is_bool($ct) || is_string($ct))
                         && ($ctn = Conflict::parse($ct, CONFLICT_AUTHORMARK)) !== false)
                    /* OK */;
                else {
                    $pj->bad_pc_conflicts->$email = $ct;
                    $ctn = Conflict::parse("other", 1);
                }
                $pj->pc_conflicts->$email = $ctn;
            }
        }
    }

    private function valid_contact($email) {
        global $Me;
        if ($email) {
            if (validate_email($email) || strcasecmp($email, $Me->email) == 0)
                return true;
            foreach ($this->prow ? $this->prow->contacts(true) : [] as $cflt)
                if (strcasecmp($cflt->email, $email) == 0)
                    return true;
        }
        return false;
    }

    private function normalize($pj) {
        // Errors prevent saving
        global $Now;

        // Title, abstract
        $this->normalize_string($pj, "title", true);
        $this->normalize_string($pj, "abstract", false);
        $this->normalize_string($pj, "collaborators", false);
        if (isset($pj->collaborators)) {
            $collab = rtrim(cleannl($pj->collaborators));
            if (!$this->prow || $collab !== rtrim(cleannl($this->prow->collaborators))) {
                $old_collab = $collab;
                $collab = (string) AuthorMatcher::fix_collaborators($old_collab);
                if ($collab !== $old_collab) {
                    $name = self::field_title($this->conf, "collaborators");
                    $this->warning_at("collaborators", "$name changed to follow our required format. You may want to look them over.");
                }
            }
            $pj->collaborators = $collab;
        }

        // Authors
        $au_by_lemail = [];
        $pj->bad_authors = $pj->bad_email_authors = [];
        if (isset($pj->authors)) {
            if (is_array($pj->authors))
                $input_authors = $pj->authors;
            else {
                $this->format_error_at("authors", $pj->authors);
                $input_authors = [];
            }
            $pj->authors = [];
            foreach ($input_authors as $k => $au) {
                if (is_string($au) || is_object($au))
                    $this->normalize_author($pj, $au, $au_by_lemail);
                else
                    $this->format_error_at("authors", $au);
            }
        }

        // Status
        if (!isset($pj->submitted)) {
            if (isset($pj->draft))
                $pj->submitted = !$pj->draft;
            else if (isset($pj->status)) {
                if ($pj->status === "submitted")
                    $pj->submitted = true;
                else if ($pj->status === "draft")
                    $pj->submitted = false;
            }
        }
        if (!isset($pj->submitted))
            $pj->submitted = $this->prow && $this->prow->timeSubmitted != 0;
        foreach (array("withdrawn_at", "submitted_at", "final_submitted_at") as $k)
            if (isset($pj->$k)) {
                if (is_numeric($pj->$k))
                    $pj->$k = (int) $pj->$k;
                else if (is_string($pj->$k))
                    $pj->$k = $this->conf->parse_time($pj->$k, $Now);
                else
                    $pj->$k = false;
                if ($pj->$k === false || $pj->$k < 0)
                    $pj->$k = $Now;
            }

        // Blindness
        if (isset($pj->nonblind)) {
            if (($x = friendly_boolean($pj->nonblind)) !== null)
                $pj->nonblind = $x;
            else {
                $this->format_error_at("nonblind", $pj->nonblind);
                unset($pj->nonblind);
            }
        }

        // Topics
        $pj->bad_topics = array();
        if (isset($pj->topics))
            $this->normalize_topics($pj);

        // Options
        $pj->bad_options = array();
        if (isset($pj->options)) {
            if (is_associative_array($pj->options) || is_object($pj->options))
                $this->normalize_options($pj, $pj->options);
            else if (is_array($pj->options) && count($pj->options) == 1 && is_object($pj->options[0]))
                $this->normalize_options($pj, $pj->options[0]);
            else if ($pj->options === false)
                $pj->options = (object) array();
            else {
                $this->format_error_at("options", $pj->options);
                unset($pj->options);
            }
        }

        // PC conflicts
        $pj->bad_pc_conflicts = (object) array();
        if (get($pj, "pc_conflicts")
            && (is_object($pj->pc_conflicts) || is_array($pj->pc_conflicts)))
            $this->normalize_pc_conflicts($pj);
        else if (get($pj, "pc_conflicts") === false)
            $pj->pc_conflicts = (object) array();
        else if (isset($pj->pc_conflicts)) {
            $this->format_error_at("pc_conflicts", $pj->pc_conflicts);
            unset($pj->pc_conflicts);
        }

        // verify emails on authors marked as contacts
        $pj->bad_contacts = array();
        foreach (get($pj, "authors") ? : array() as $au)
            if (get($au, "contact")
                && (!isset($au->email) || !$this->valid_contact($au->email)))
                $pj->bad_contacts[] = $au;

        // Contacts
        $contacts = get($pj, "contacts");
        if ($contacts !== null) {
            if (is_object($contacts) || is_array($contacts))
                $contacts = (array) $contacts;
            else {
                $this->format_error_at("contacts", $contacts);
                $contacts = [];
            }
            $pj->contacts = [];
            // verify emails on explicitly named contacts
            foreach ($contacts as $k => $v) {
                if (!$v)
                    continue;
                if ($v === true)
                    $v = (object) array();
                else if (is_string($v) && is_int($k)) {
                    $v = trim($v);
                    if ($this->valid_contact($v))
                        $v = (object) array("email" => $v);
                    else
                        $v = Text::analyze_name($v);
                }
                if (is_object($v) && !get($v, "email") && is_string($k))
                    $v->email = $k;
                if (is_object($v) && get($v, "email")) {
                    if ($this->valid_contact($v->email))
                        $pj->contacts[] = (object) array_merge((array) get($au_by_lemail, strtolower($v->email)), (array) $v);
                    else
                        $pj->bad_contacts[] = $v;
                } else
                    $this->format_error_at("contacts", $v);
            }
        }

        // Inherit contactness
        if (isset($pj->authors) && $this->prow) {
            foreach ($this->prow->contacts(true) as $cflt)
                if ($cflt->conflictType >= CONFLICT_CONTACTAUTHOR
                    && ($aux = get($au_by_lemail, strtolower($cflt->email)))
                    && !isset($aux->contact))
                    $aux->contact = true;
        }
        // If user modifies paper, make them a contact (not just an author)
        if ($this->prow
            && $this->user
            && !$this->user->allow_administer($this->prow)
            && $this->prow->conflict_type($this->user) === CONFLICT_AUTHOR) {
            if (!isset($pj->contacts)) {
                $pj->contacts = [];
                foreach ($this->prow->contacts(true) as $cflt)
                    if ($cflt->conflictType >= CONFLICT_CONTACTAUTHOR)
                        $pj->contacts[] = (object) ["email" => $cflt->email];
            }
            if (!array_filter($pj->contacts, function ($cflt) {
                    return strcasecmp($this->user->email, $cflt->email) === 0;
                }))
                $pj->contacts[] = (object) ["email" => $this->user->email];
        }
    }

    static function check_title(PaperStatus $ps, $pj) {
        $v = convert_to_utf8(get_s($pj, "title"));
        if ($v === ""
            && (isset($pj->title) || !$ps->prow || (string) $ps->prow->title === ""))
            $ps->error_at("title", $ps->_("Entry required."));
        if (!$ps->prow
            || (!$ps->has_error_at("title")
                && isset($pj->title)
                && $v !== (string) $ps->prow->title))
            $ps->save_paperf("title", $v, "title");
    }

    static function check_abstract(PaperStatus $ps, $pj) {
        $v = convert_to_utf8(get_s($pj, "abstract"));
        if ($v === ""
            && (isset($pj->abstract) || !$ps->prow || (string) $ps->prow->abstract === "")) {
            if (!$ps->conf->opt("noAbstract"))
                $ps->error_at("abstract", $ps->_("Entry required."));
        }
        if (!$ps->prow
            || (!$ps->has_error_at("abstract")
                && isset($pj->abstract)
                && $v !== (string) $ps->prow->abstract))
            $ps->save_paperf("abstract", $v, "abstract");
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
        $authors = get($pj, "authors");
        $max_authors = $ps->conf->opt("maxAuthors");
        if ((is_array($authors) && empty($authors))
            || ($authors === null && (!$ps->prow || !$ps->prow->author_list())))
            $ps->error_at("authors", $ps->_("Entry required."));
        if ($max_authors > 0 && is_array($authors) && count($authors) > $max_authors)
            $ps->error_at("authors", $ps->_("Each submission can have at most %d authors.", $max_authors));
        if (!empty($pj->bad_authors))
            $ps->error_at("authors", $ps->_("Some authors ignored."));
        foreach ($pj->bad_email_authors as $aux) {
            $ps->error_at("authors", null);
            $ps->error_at("auemail" . $aux->index, $ps->_("“%s” is not a valid email address.", htmlspecialchars($aux->email)));
        }
        if (!$ps->prow || isset($pj->authors)) {
            $v = convert_to_utf8(self::author_information($pj));
            if (!$ps->prow
                || (!$ps->has_error_at("authors")
                    && $v !== $ps->prow->authorInformation))
                $ps->save_paperf("authorInformation", $v, "authors");
        }
    }

    static function check_collaborators(PaperStatus $ps, $pj) {
        $v = convert_to_utf8(get_s($pj, "collaborators"));
        if (!$ps->prow
            || (isset($pj->collaborators)
                && $v !== (string) $ps->prow->collaborators))
            $ps->save_paperf("collaborators", $v, "collaborators");
    }

    static function check_nonblind(PaperStatus $ps, $pj) {
        if ($ps->conf->submission_blindness() == Conf::BLIND_OPTIONAL
            && (!$ps->prow
                || (isset($pj->nonblind)
                    && !$pj->nonblind !== !!$ps->prow->blind))) {
            $ps->save_paperf("blind", get($pj, "nonblind") ? 0 : 1, "nonblind");
        }
    }

    static function check_pdfs(PaperStatus $ps, $pj) {
        // store documents (XXX should attach to paper even if error)
        foreach (["submission", "final"] as $i => $k) {
            if (isset($pj->$k) && $pj->$k) {
                $pj->$k = $ps->upload_document($pj->$k, $ps->conf->paper_opts->get($i ? DTYPE_FINAL : DTYPE_SUBMISSION));
            }
            if (!$ps->prow
                || (isset($pj->$k)
                    && !$ps->has_error_at($i ? "final" : "paper"))) {
                $null_id = $i ? 0 : 1;
                $new_id = isset($pj->$k) && $pj->$k ? $pj->$k->paperStorageId : $null_id;
                $prowk = $i ? "finalPaperStorageId" : "paperStorageId";
                if (($ps->prow ? $ps->prow->$prowk : $null_id) != $new_id)
                    $ps->save_paperf($prowk, $new_id, $k);
                else if (!$ps->prow)
                    $ps->save_paperf($prowk, $new_id);
            }
        }
    }

    static function check_status(PaperStatus $ps, $pj) {
        global $Now;
        $pj_withdrawn = get($pj, "withdrawn");
        $pj_submitted = get($pj, "submitted");
        $pj_draft = get($pj, "draft");
        if ($pj_withdrawn === null
            && $pj_submitted === null
            && $pj_draft === null) {
            $pj_status = get($pj, "status");
            if ($pj_status === "submitted")
                $pj_submitted = true;
            else if ($pj_status === "withdrawn")
                $pj_withdrawn = true;
            else if ($pj_status === "draft")
                $pj_draft = true;
        }
        if ($ps->has_error()
            && ($pj_submitted || $pj_draft === false)
            && !$pj_withdrawn
            && (!$ps->prow || $ps->prow->timeSubmitted == 0)) {
            $pj_submitted = false;
            $pj_draft = true;
        }

        $submitted = false;
        if ($pj_withdrawn !== null
            || $pj_submitted !== null
            || $pj_draft !== null) {
            if ($pj_submitted !== null)
                $submitted = $pj_submitted;
            else if ($pj_draft !== null)
                $submitted = !$pj_draft;
            else if ($ps->prow)
                $submitted = $ps->prow->timeSubmitted != 0;
            if (isset($pj->submitted_at))
                $submitted_at = $pj->submitted_at;
            else if ($ps->prow)
                $submitted_at = $ps->prow->submitted_at();
            else
                $submitted_at = 0;
            if ($pj_withdrawn) {
                if ($submitted && $submitted_at <= 0)
                    $submitted_at = -100;
                else if (!$submitted)
                    $submitted_at = 0;
                else
                    $submitted_at = -$submitted_at;
                if (!$ps->prow || $ps->prow->timeWithdrawn <= 0) {
                    $ps->save_paperf("timeWithdrawn", get($pj, "withdrawn_at") ? : $Now, "status");
                    $ps->save_paperf("timeSubmitted", $submitted_at);
                } else if (($ps->prow->submitted_at() > 0) !== $submitted)
                    $ps->save_paperf("timeSubmitted", $submitted_at, "status");
            } else if ($submitted) {
                if (!$ps->prow || $ps->prow->timeSubmitted <= 0) {
                    if ($submitted_at <= 0 || $submitted_at === PaperInfo::SUBMITTED_AT_FOR_WITHDRAWN)
                        $submitted_at = $Now;
                    $ps->save_paperf("timeSubmitted", $submitted_at, "status");
                }
                if ($ps->prow && $ps->prow->timeWithdrawn != 0)
                    $ps->save_paperf("timeWithdrawn", 0, "status");
            } else if ($ps->prow && ($ps->prow->timeWithdrawn > 0 || $ps->prow->timeSubmitted > 0)) {
                $ps->save_paperf("timeSubmitted", 0, "status");
                $ps->save_paperf("timeWithdrawn", 0);
            }
        }
        $ps->_paper_submitted = !$pj_withdrawn && $submitted;
    }

    static function check_final_status(PaperStatus $ps, $pj) {
        global $Now;
        if (isset($pj->final_submitted)) {
            if ($pj->final_submitted)
                $time = get($pj, "final_submitted_at") ? : $Now;
            else
                $time = 0;
            if (!$ps->prow || $ps->prow->timeFinalSubmitted != $time)
                $ps->save_paperf("timeFinalSubmitted", $time, "final_status");
        }
    }

    static function check_topics(PaperStatus $ps, $pj) {
        if (!empty($pj->bad_topics))
            $ps->warning_at("topics", $ps->_("Unknown topics ignored (%2\$s).", count($pj->bad_topics), htmlspecialchars(join("; ", $pj->bad_topics))));
        if (isset($pj->topics)) {
            $old_topics = $ps->prow ? $ps->prow->topic_list() : [];
            $new_topics = array_map("intval", array_keys((array) $pj->topics));
            sort($old_topics);
            sort($new_topics);
            if ($old_topics !== $new_topics) {
                $ps->diffs["topics"] = true;
                $ps->_topic_ins = $new_topics;
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

    static function check_options(PaperStatus $ps, $pj) {
        if (!empty($pj->bad_options))
            $ps->warning_at("options", $ps->_("Unknown options ignored (%2\$s).", count($pj->bad_options), htmlspecialchars(join("; ", array_keys($pj->bad_options)))));
        if (!isset($pj->options))
            return;

        $parsed_options = array();
        foreach ($pj->options as $oid => $oj) {
            $o = $ps->conf->paper_opts->get($oid);
            $result = null;
            if ($oj !== null)
                $result = $o->store_json($oj, $ps);
            if ($result === null || $result === false) {
                if ($o->required && $pj->submitted)
                    $ps->error_at_option($o, "Entry required.");
                $result = [];
            } else if (!is_array($result))
                $result = [[$result]];
            else if (count($result) == 2 && !is_int($result[1]))
                $result = [$result];
            if (!$ps->has_error_at($o->field_key()))
                $parsed_options[$o->id] = $result;
        }

        ksort($parsed_options);
        foreach ($parsed_options as $id => $parsed_vs) {
            // old values
            $ov = $od = [];
            if ($ps->prow) {
                list($ov, $od) = $ps->prow->option_value_data($id);
            }

            // new values
            $nv = $nd = [];
            foreach ($parsed_vs as $vx) {
                $nv[] = is_int($vx) ? $vx : $vx[0];
                $nd[] = is_int($vx) ? null : get($vx, 1);
            }

            // save difference
            if ($ov !== $nv || $od !== $nd) {
                $opt = $ps->conf->paper_opts->get($id);
                $ps->_option_delid[] = $id;
                $ps->diffs[$opt->json_key()] = true;
                for ($i = 0; $i < count($nv); ++$i) {
                    $qv0 = [-1, $id, $nv[$i], null, null];
                    if ($nd[$i] !== null) {
                        $qv0[strlen($nd[$i]) < 32768 ? 3 : 4] = $nd[$i];
                    }
                    $ps->_option_ins[] = $qv0;
                }
                if ($opt->has_document())
                    $ps->_document_change = true;
            }
        }
    }

    static function execute_options(PaperStatus $ps) {
        if (!empty($ps->_option_delid))
            $ps->conf->qe("delete from PaperOption where paperId=? and optionId?a", $ps->paperId, $ps->_option_delid);
        if (!empty($ps->_option_ins)) {
            foreach ($ps->_option_ins as &$x)
                $x[0] = $ps->paperId;
            $ps->conf->qe("insert into PaperOption (paperId, optionId, value, data, dataOverflow) values ?v", $ps->_option_ins);
        }
    }

    static private function contacts_array($pj) {
        $contacts = array();
        foreach (get($pj, "authors") ? : [] as $au)
            if (get($au, "email") && validate_email($au->email)) {
                $c = clone $au;
                $contacts[strtolower($c->email)] = $c;
            }
        foreach (get($pj, "contacts") ? : array() as $v) {
            $lemail = strtolower($v->email);
            $c = (object) array_merge((array) get($contacts, $lemail), (array) $v);
            $c->contact = true;
            $contacts[$lemail] = $c;
        }
        return $contacts;
    }

    function conflicts_array($pj) {
        $cflts = [];

        // extract PC conflicts
        if (isset($pj->pc_conflicts)) {
            foreach ((array) $pj->pc_conflicts as $email => $type)
                $cflts[strtolower($email)] = $type;
        } else if ($this->prow) {
            foreach ($this->prow->conflicts(true) as $cflt)
                if ($cflt->conflictType < CONFLICT_AUTHOR)
                    $cflts[strtolower($cflt->email)] = $cflt->conflictType;
        }

        // extract contacts
        if (isset($pj->contacts)) {
            foreach ($pj->contacts as $aux) {
                $cflts[strtolower($aux->email)] = CONFLICT_CONTACTAUTHOR;
            }
        } else if ($this->prow) {
            foreach ($this->prow->contacts(true) as $cflt) {
                if ($cflt->conflictType == CONFLICT_CONTACTAUTHOR)
                    $cflts[strtolower($cflt->email)] = CONFLICT_CONTACTAUTHOR;
            }
        }

        // extract authors
        if (isset($pj->authors)) {
            foreach ($pj->authors as $aux) {
                if (isset($aux->email)) {
                    $lemail = strtolower($aux->email);
                    if (!isset($aux->contact))
                        $ctype = max(get_i($cflts, $lemail), CONFLICT_AUTHOR);
                    else
                        $ctype = $aux->contact ? CONFLICT_CONTACTAUTHOR : CONFLICT_AUTHOR;
                    $cflts[$lemail] = $ctype;
                }
            }
        } else if ($this->prow) {
            foreach ($this->prow->contacts(true) as $cflt) {
                $lemail = strtolower($cflt->email);
                $cflts[$lemail] = max(get_i($cflts, $lemail), $cflt->conflictType);
            }
            foreach ($this->prow->author_list() as $au)
                if ($au->email !== "") {
                    $lemail = strtolower($au->email);
                    $cflts[$lemail] = max(get_i($cflts, $lemail), CONFLICT_AUTHOR);
                }
        }

        // chair conflicts cannot be overridden
        if ($this->prow) {
            foreach ($this->prow->conflicts(true) as $cflt) {
                if ($cflt->conflictType == CONFLICT_CHAIRMARK) {
                    $lemail = strtolower($cflt->email);
                    if (get_i($cflts, $lemail) < CONFLICT_CHAIRMARK
                        && $this->user
                        && !$this->user->can_administer($this->prow))
                        $cflts[$lemail] = CONFLICT_CHAIRMARK;
                }
            }
        }

        ksort($cflts);
        return $cflts;
    }

    static private function check_contacts(PaperStatus $ps, $pj) {
        $cflts = $ps->conflicts_array($pj);
        if (!array_filter($cflts, function ($cflt) { return $cflt >= CONFLICT_CONTACTAUTHOR; })
            && $ps->prow
            && array_filter($ps->prow->contacts(), function ($cflt) { return $cflt->conflictType >= CONFLICT_CONTACTAUTHOR; })) {
            $ps->error_at("contacts", $ps->_("Each submission must have at least one contact."));
        }
        if ($ps->prow
            && $ps->user
            && !$ps->user->allow_administer($ps->prow)
            && get($cflts, strtolower($ps->user->email), 0) < CONFLICT_AUTHOR) {
            $ps->error_at("contacts", $ps->_("You can’t remove yourself as submission contact. (Ask another contact to remove you.)"));
        }
        foreach ($pj->bad_contacts as $reg) {
            $key = "contacts";
            if (isset($reg->index) && is_int($reg->index))
                $key = (isset($reg->is_new) && $reg->is_new === true ? "newcontact_email_" : "contact_") . $reg->index;
            if (!isset($reg->email))
                $ps->error_at($key, $ps->_("Contact %s has no associated email.", Text::user_html($reg)));
            else
                $ps->error_at($key, $ps->_("Contact email %s is invalid.", htmlspecialchars($reg->email)));
            $ps->error_at("contacts", false);
        }
    }

    static function check_conflicts(PaperStatus $ps, $pj) {
        if (isset($pj->contacts))
            self::check_contacts($ps, $pj);

        $ps->_new_conflicts = $new_cflts = $ps->conflicts_array($pj);
        $old_cflts = $ps->conflicts_array((object) []);
        foreach ($new_cflts + $old_cflts as $lemail => $v) {
            $new_ctype = get_i($new_cflts, $lemail);
            $old_ctype = get_i($old_cflts, $lemail);
            if ($new_ctype !== $old_ctype) {
                if ($new_ctype >= CONFLICT_AUTHOR || $old_ctype >= CONFLICT_AUTHOR)
                    $ps->diffs["contacts"] = true;
                if (($new_ctype > 0 && $new_ctype < CONFLICT_AUTHOR)
                    || ($old_ctype > 0 && $old_ctype < CONFLICT_AUTHOR))
                    $ps->diffs["pc_conflicts"] = true;
            }
        }
    }

    static function postcheck_contacts(PaperStatus $ps, $pj) {
        if (isset($ps->diffs["contacts"]) && !$ps->has_error_at("contacts")) {
            foreach (self::contacts_array($pj) as $c) {
                $flags = (get($c, "contact") ? 0 : Contact::SAVE_IMPORT)
                    | ($ps->no_email ? 0 : Contact::SAVE_NOTIFY);
                $c->disabled = !!$ps->disable_users;
                if (!Contact::create($ps->conf, $ps->user, $c, $flags)
                    && !($flags & Contact::SAVE_IMPORT)) {
                    $key = "contacts";
                    if (isset($c->index) && is_int($c->index))
                        $key = (isset($c->is_new) && $c->is_new === true ? "newcontact_" : "contact_") . $c->index;
                    $ps->error_at($key, $ps->_("Could not create an account for contact %s.", Text::user_html($c)));
                    $ps->error_at("contacts", false);
                }
            }
        }
        if ((isset($ps->diffs["contacts"]) || isset($ps->diffs["pc_conflicts"]))
            && !$ps->has_error_at("contacts")
            && !$ps->has_error_at("pc_conflicts")) {
            $ps->_conflict_ins = [];
            if (!empty($ps->_new_conflicts)) {
                $result = $ps->conf->qe("select contactId, email from ContactInfo where email?a", array_keys($ps->_new_conflicts));
                while (($row = edb_row($result)))
                    $ps->_conflict_ins[] = [-1, $row[0], $ps->_new_conflicts[strtolower($row[1])]];
                Dbl::free($result);
            }
        }
    }

    static function execute_conflicts(PaperStatus $ps) {
        if ($ps->_conflict_ins !== null) {
            $ps->conf->qe("delete from PaperConflict where paperId=?", $ps->paperId);
            foreach ($ps->_conflict_ins as &$x)
                $x[0] = $ps->paperId;
            if (!empty($ps->_conflict_ins))
                $ps->conf->qe("insert into PaperConflict (paperId,contactId,conflictType) values ?v", $ps->_conflict_ins);
        }
    }

    private function save_paperf($f, $v, $diff = null) {
        assert(!isset($this->_paper_upd[$f]));
        $this->_paper_upd[$f] = $v;
        if ($diff)
            $this->diffs[$diff] = true;
    }

    function prepare_save_paper_json($pj) {
        assert(!$this->hide_docids);
        assert(is_object($pj));

        $paperid = get($pj, "pid", get($pj, "id", null));
        if ($paperid !== null && is_int($paperid) && $paperid <= 0)
            $paperid = null;
        if ($paperid !== null && !is_int($paperid)) {
            $key = isset($pj->pid) ? "pid" : "id";
            $this->format_error_at($key, $paperid);
            return false;
        }

        if (get($pj, "error") || get($pj, "error_html")) {
            $this->error_at("error", $this->_("Refusing to save submission with error"));
            return false;
        }

        $this->clear();
        $this->paperId = $paperid ? : -1;
        if ($paperid)
            $this->prow = $this->conf->paperRow(["paperId" => $paperid, "topics" => true, "options" => true], $this->user);
        if ($pj && $this->prow && $paperid !== $this->prow->paperId) {
            $this->error_at("pid", $this->_("Saving submission with different ID"));
            return false;
        }

        // normalize and check format
        $this->normalize($pj);
        if ($this->has_error())
            return false;

        // save parts and track diffs
        self::check_title($this, $pj);
        self::check_abstract($this, $pj);
        self::check_authors($this, $pj);
        self::check_collaborators($this, $pj);
        self::check_nonblind($this, $pj);
        self::check_conflicts($this, $pj);
        self::check_pdfs($this, $pj);
        self::check_topics($this, $pj);
        self::check_options($this, $pj);
        self::check_status($this, $pj);
        self::check_final_status($this, $pj);
        self::postcheck_contacts($this, $pj);
        return true;
    }

    private function unused_random_pid() {
        $n = max(100, 3 * $this->conf->fetch_ivalue("select count(*) from Paper"));
        while (1) {
            $pids = [];
            while (count($pids) < 10)
                $pids[] = mt_rand(1, $n);

            $result = $this->conf->qe("select paperId from Paper where paperId?a", $pids);
            while ($result && ($row = $result->fetch_row()))
                $pids = array_values(array_diff($pids, [(int) $row[0]]));
            Dbl::free($result);

            if (!empty($pids))
                return $pids[0];
        }
    }

    function execute_save_paper_json($pj) {
        global $Now;
        if (!empty($this->_paper_upd)) {
            if ($this->conf->submission_blindness() == Conf::BLIND_NEVER)
                $this->save_paperf("blind", 0);
            else if ($this->conf->submission_blindness() != Conf::BLIND_OPTIONAL)
                $this->save_paperf("blind", 1);

            $old_joindoc = $this->prow ? $this->prow->joindoc() : null;
            $old_joinid = $old_joindoc ? $old_joindoc->paperStorageId : 0;

            $new_final_docid = get($this->_paper_upd, "finalPaperStorageId");
            $new_sub_docid = get($this->_paper_upd, "paperStorageId");
            if ($new_final_docid !== null || $new_sub_docid !== null)
                $this->_document_change = true;

            if ($new_final_docid > 0)
                $new_joindoc = $pj->final;
            else if ($new_final_docid === null
                     && $this->prow
                     && $this->prow->finalPaperStorageId > 0)
                $new_joindoc = $this->prow->document(DTYPE_FINAL);
            else if ($new_sub_docid > 1)
                $new_joindoc = $pj->submission;
            else if ($new_sub_docid === null
                     && $this->prow
                     && $this->prow->paperStorageId > 1)
                $new_joindoc = $this->prow->document(DTYPE_SUBMISSION);
            else
                $new_joindoc = null;
            $new_joinid = $new_joindoc ? $new_joindoc->paperStorageId : 0;

            if ($new_joindoc && $new_joinid != $old_joinid) {
                if ($new_joindoc->ensure_size())
                    $this->save_paperf("size", $new_joindoc->size);
                else
                    $this->save_paperf("size", 0);
                $this->save_paperf("mimetype", $new_joindoc->mimetype);
                $this->save_paperf("sha1", $new_joindoc->binary_hash());
                $this->save_paperf("timestamp", $new_joindoc->timestamp);
                if ($this->conf->sversion >= 145)
                    $this->save_paperf("pdfFormatStatus", 0);
            } else if (!$this->prow || $new_joinid != $old_joinid) {
                $this->save_paperf("size", 0);
                $this->save_paperf("mimetype", "");
                $this->save_paperf("sha1", "");
                $this->save_paperf("timestamp", 0);
                if ($this->conf->sversion >= 145)
                    $this->save_paperf("pdfFormatStatus", 0);
            }

            $this->save_paperf("timeModified", $Now);

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
                if (($random_pids = $this->conf->setting("random_pids"))) {
                    $this->conf->qe("lock tables Paper write");
                    $this->_paper_upd["paperId"] = $this->unused_random_pid();
                }
                $result = $this->conf->qe_apply("insert into Paper set " . join("=?, ", array_keys($this->_paper_upd)) . "=?", array_values($this->_paper_upd));
                if ($random_pids)
                    $this->conf->qe("unlock tables");
                if (!$result || !$result->insert_id)
                    return $this->error_at(false, $this->_("Could not create paper."));
                $pj->pid = $this->paperId = (int) $result->insert_id;
                if (!empty($this->uploaded_documents))
                    $this->conf->qe("update PaperStorage set paperId=? where paperStorageId?a", $this->paperId, $this->uploaded_documents);
            }

            // maybe update `papersub` settings
            $was_submitted = $this->prow && $this->prow->timeWithdrawn <= 0 && $this->prow->timeSubmitted > 0;
            if ($this->_paper_submitted != $was_submitted)
                $this->conf->update_papersub_setting($this->_paper_submitted ? 1 : -1);
        }

        self::execute_conflicts($this);
        self::execute_topics($this);
        self::execute_options($this);

        // update autosearch
        $this->conf->update_autosearch_tags($this->paperId);

        // update document inactivity
        if ($this->_document_change) {
            $pset = $this->conf->paper_set(null, ["paperId" => $this->paperId, "options" => true]);
            foreach ($pset as $prow)
                $prow->mark_inactive_documents();
        }

        return true;
    }

    function save_paper_json($pj) {
        if ($this->prepare_save_paper_json($pj)) {
            $this->execute_save_paper_json($pj);
            return $this->paperId;
        } else
            return false;
    }
}
