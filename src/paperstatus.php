<?php
// paperstatus.php -- HotCRP helper for reading/storing papers as JSON
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperStatus extends MessageSet {
    private $conf;
    private $contact;
    private $uploaded_documents;
    private $no_email = false;
    private $forceShow = null;
    private $export_ids = false;
    private $hide_docids = false;
    private $export_content = false;
    private $disable_users = false;
    private $prow;
    private $paperid;
    private $document_callbacks = array();
    private $qf;
    private $qv;

    function __construct(Conf $conf, Contact $contact = null, $options = array()) {
        $this->conf = $conf;
        $this->contact = $contact;
        foreach (array("no_email", "allow_error",
                       "forceShow", "export_ids", "hide_docids",
                       "export_content", "disable_users") as $k)
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        $this->clear();
    }

    function clear() {
        parent::clear();
        $this->uploaded_documents = [];
        $this->prow = null;
    }

    function add_document_callback($cb) {
        $this->document_callbacks[] = $cb;
    }

    function user() {
        return $this->contact;
    }

    function paper_row() {
        return $this->prow;
    }

    private function _() {
        return call_user_func_array([$this->conf->ims(), "x"], func_get_args());
    }

    function document_to_json($dtype, $docid) {
        if (!is_object($docid))
            $drow = $this->prow ? $this->prow->document($dtype, $docid) : null;
        else {
            $drow = $docid;
            $docid = $drow->paperStorageId;
        }
        $d = (object) array();
        if ($docid && !$this->hide_docids)
            $d->docid = $docid;
        if ($drow) {
            if ($drow->mimetype)
                $d->mimetype = $drow->mimetype;
            if ($drow->sha1 !== null && $drow->sha1 !== "")
                $d->sha1 = bin2hex($drow->sha1);
            if ($drow->timestamp)
                $d->timestamp = $drow->timestamp;
            if ($drow->size)
                $d->size = $drow->size;
            if ($drow->filename)
                $d->filename = $drow->filename;
            $meta = null;
            if (isset($drow->infoJson) && is_object($drow->infoJson))
                $meta = $drow->infoJson;
            else if (isset($drow->infoJson) && is_string($drow->infoJson))
                $meta = json_decode($drow->infoJson);
            if ($meta)
                $d->metadata = $meta;
            if ($this->export_content
                && $drow->docclass->load($drow))
                $d->content_base64 = base64_encode(Filer::content($drow));
        }
        foreach ($this->document_callbacks as $cb)
            call_user_func($cb, $d, $this->prow, $dtype, $drow);
        if (!count(get_object_vars($d)))
            $d = null;
        return $d;
    }

    function paper_json($prow, $args = array()) {
        if (is_int($prow))
            $prow = $this->conf->paperRow(["paperId" => $prow, "topics" => true, "options" => true], $this->contact);
        $contact = $this->contact;
        if (get($args, "forceShow"))
            $contact = null;

        if (!$prow || ($contact && !$contact->can_view_paper($prow)))
            return null;
        $was_no_msgs = $this->ignore_msgs;
        $this->ignore_msgs = !get($args, "msgs");

        $this->prow = $prow;
        $this->paperId = $prow->paperId;

        $pj = (object) array();
        $pj->pid = (int) $prow->paperId;
        $pj->title = $prow->title;

        if ($prow->timeWithdrawn > 0) {
            $pj->status = "withdrawn";
            $pj->withdrawn = true;
            $pj->withdrawn_at = (int) $prow->timeWithdrawn;
            if (get($prow, "withdrawReason"))
                $pj->withdrawn_reason = $prow->withdrawReason;
        } else if ($prow->timeSubmitted > 0) {
            $pj->status = "submitted";
            $pj->submitted = true;
        } else {
            $pj->status = "inprogress";
            $pj->draft = true;
        }
        if ($prow->timeSubmitted > 0)
            $pj->submitted_at = (int) $prow->timeSubmitted;
        else if ($prow->timeSubmitted == -100 && $prow->timeWithdrawn > 0)
            $pj->submitted_at = 1000000000;

        $can_view_authors = !$contact
            || $contact->can_view_authors($prow, $this->forceShow);
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
            $pj->nonblind = !(isset($pj->paperBlind) ? $prow->paperBlind : $prow->blind);

        if ($prow->abstract !== "" || !$this->conf->opt("noAbstract"))
            $pj->abstract = $prow->abstract;

        $topics = array();
        foreach (array_intersect_key($this->conf->topic_map(), array_flip($prow->topics())) as $tid => $tname)
            $topics[$this->export_ids ? $tid : $tname] = true;
        if (!empty($topics))
            $pj->topics = (object) $topics;

        if ($prow->paperStorageId > 1
            && (!$contact || $contact->can_view_pdf($prow))
            && ($doc = $this->document_to_json(DTYPE_SUBMISSION, (int) $prow->paperStorageId)))
            $pj->submission = $doc;

        if ($prow->finalPaperStorageId > 1
            && (!$contact || $contact->can_view_pdf($prow))
            && ($doc = $this->document_to_json(DTYPE_FINAL, (int) $prow->finalPaperStorageId)))
            $pj->final = $doc;
        if ($prow->timeFinalSubmitted > 0) {
            $pj->final_submitted = true;
            $pj->final_submitted_at = (int) $prow->timeFinalSubmitted;
        }

        $options = array();
        foreach ($this->conf->paper_opts->option_list() as $o) {
            if ($contact && !$contact->can_view_paper_option($prow, $o, $this->forceShow))
                continue;
            $ov = $prow->option($o->id) ? : new PaperOptionValue($prow, $o);
            $oj = $o->unparse_json($ov, $this, $contact);
            if ($oj !== null)
                $options[$this->export_ids ? $o->id : $o->abbreviation()] = $oj;
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
        }

        if ($prow->collaborators && $can_view_authors)
            $pj->collaborators = $prow->collaborators;
        if (!$prow->collaborators && $can_view_authors && $this->conf->setting("sub_collab")
            && ($prow->outcome <= 0 || ($contact && !$contact->can_view_decision($prow)))) {
            $field = $this->_($this->conf->setting("sub_pcconf") ? "Other conflicts" : "Potential conflicts");
            $this->warning_at("collaborators", $this->_("Enter the authors’ potential conflicts of interest in the %s field. If none of the authors have conflicts, enter “None”.", $field));
        }

        $this->ignore_msgs = $was_no_msgs;
        return $pj;
    }


    static public function clone_json($pj) {
        $x = (object) [];
        foreach ($pj ? get_object_vars($pj) : [] as $k => $v)
            if (is_object($v))
                $x->$k = self::clone_json($v);
            else
                $x->$k = $v;
        return $x;
    }


    function error_at_option(PaperOption $o, $html) {
        $this->error_at($o->field_key(), htmlspecialchars($o->name) . ": " . $html);
    }

    function warning_at_option(PaperOption $o, $html) {
        $this->warning_at($o->field_key(), htmlspecialchars($o->name) . ": " . $html);
    }


    public function set_document_prow($prow) {
        // XXX this is butt ugly
        $this->prow = $prow;
        $this->paperId = $prow->paperId ? : -1;
    }

    public function upload_document($docj, PaperOption $o) {
        if (get($docj, "error") || get($docj, "error_html")) {
            $this->error_at_option($o, get($docj, "error_html", "Upload error."));
            $docj->docid = 1;
            return;
        }

        // look for an existing document with same sha1;
        // check existing docid's sha1
        $docid = get($docj, "docid");
        if ($docid) {
            $oldj = $this->document_to_json($o->id, $docid);
            if (get($docj, "sha1") && get($oldj, "sha1") !== Filer::text_sha1($docj->sha1))
                $docid = null;
        } else if ($this->paperId != -1 && get($docj, "sha1")) {
            $oldj = Dbl::fetch_first_object($this->conf->dblink, "select paperStorageId, sha1, timestamp, size, mimetype from PaperStorage where paperId=? and documentType=? and sha1=?", $this->paperId, $o->id, Filer::binary_sha1($docj->sha1));
            if ($oldj)
                $docid = $oldj->paperStorageId;
        }
        if ($docid) {
            $docj->docid = $docid;
            $docj->sha1 = Filer::binary_sha1($oldj->sha1);
            $docj->timestamp = $oldj->timestamp;
            $docj->size = $oldj->size;
            $docj->mimetype = $oldj->mimetype;
            return;
        }

        // check filter
        if (get($docj, "filter") && is_int($docj->filter)) {
            if (is_int(get($docj, "original_id")))
                $result = $this->conf->qe("select paperStorageId, timestamp, sha1 from PaperStorage where paperId=? and paperStorageId=?", $this->paperId, $docj->original_id);
            else if (is_string(get($docj, "original_sha1")))
                $result = $this->conf->qe("select paperStorageId, timestamp, sha1 from PaperStorage where paperId=? and sha1=?", $this->paperId, Filer::binary_sha1($docj->original_sha1));
            else if ($o->id == DTYPE_SUBMISSION || $o->id == DTYPE_FINAL)
                $result = $this->conf->qe("select PaperStorage.paperStorageId, PaperStorage.timestamp, PaperStorage.sha1 from PaperStorage join Paper on (Paper.paperId=PaperStorage.paperId and Paper." . ($o->id == DTYPE_SUBMISSION ? "paperStorageId" : "finalPaperStorageId") . "=PaperStorage.paperStorageId) where Paper.paperId=?", $this->paperId);
            else
                $result = null;
            if (($row = edb_orow($result))) {
                $docj->original_id = (int) $row->paperStorageId;
                $docj->original_timestamp = (int) $row->timestamp;
                $docj->original_sha1 = $row->sha1;
                if (get($docj, "preserve_timestamp"))
                    $docj->timestamp = (int) $docj->original_timestamp;
            } else
                unset($docj->original_id);
            Dbl::free($result);
        }

        // if no sha1 match, upload
        $docclass = $this->conf->docclass($o->id);
        $docj->paperId = $this->paperId;
        $newdoc = new DocumentInfo($docj);
        if ($docclass->upload($newdoc) && $newdoc->paperStorageId > 1) {
            foreach (array("size", "sha1", "mimetype", "timestamp") as $k)
                $docj->$k = $newdoc->$k;
            $this->uploaded_documents[] = $docj->docid = $newdoc->paperStorageId;
        } else {
            $docj->docid = 1;
            $this->error_at_option($o, $newdoc ? $newdoc->error_html : "Empty document.");
        }
    }

    private function normalize_string($pj, $k, $simplify, $preserve) {
        if (isset($pj->$k) && is_string($pj->$k)) {
            if (!$preserve && $simplify)
                $pj->$k = simplify_whitespace($pj->$k);
            else if (!$preserve)
                $pj->$k = trim($pj->$k);
        } else if (isset($pj->$k)) {
            $this->error_at($k, "Format error [$k]");
            unset($pj, $k);
        }
    }

    private function normalize_author($pj, $au, &$au_by_email, $old_au_by_email, $preserve) {
        if (!$preserve) {
            $aux = Text::analyze_name($au);
            $aux->first = simplify_whitespace($aux->firstName);
            $aux->last = simplify_whitespace($aux->lastName);
            $aux->email = simplify_whitespace($aux->email);
            $aux->affiliation = simplify_whitespace($aux->affiliation);
        } else {
            $aux = clone $au;
            foreach (["first", "last", "email", "affiliation"] as $k)
                if (!isset($aux->$k))
                    $aux->$k = "";
        }
        // borrow from old author information
        if ($aux->email && $aux->first === "" && $aux->last === ""
            && ($old_au = get($old_au_by_email, strtolower($aux->email)))) {
            $aux->first = get($old_au, "first", "");
            $aux->last = get($old_au, "last", "");
            if ($aux->affiliation === "")
                $aux->affiliation = get($old_au, "affiliation", "");
        }
        if ($aux->first !== "" || $aux->last !== ""
            || $aux->email !== "" || $aux->affiliation !== "")
            $pj->authors[] = $aux;
        else
            $pj->bad_authors[] = $aux;
        $aux->index = count($pj->authors) + count($pj->bad_authors);
        if (is_object($au) && isset($au->contact))
            $aux->contact = !!$au->contact;
        if ($aux->email) {
            $lemail = strtolower($aux->email);
            $au_by_email[$lemail] = $aux;
            if (!validate_email($lemail) && !isset($old_au_by_email[$lemail]))
                $pj->bad_email_authors[] = $aux;
        }
    }

    private function normalize_topics($pj) {
        $topics = $pj->topics;
        unset($pj->topics);
        if (is_array($topics)) {
            $new_topics = (object) array();
            foreach ($topics as $v)
                if ($v && (is_int($v) || is_string($v)))
                    $new_topics->$v = true;
                else if ($v)
                    $this->error_at("topics", "Format error [topics]");
            $topics = $new_topics;
        }
        if (is_object($topics)) {
            $topic_map = $this->conf->topic_map();
            $pj->topics = (object) array();
            foreach ($topics as $k => $v)
                if (!$v)
                    /* skip */;
                else if (get($topic_map, $k))
                    $pj->topics->$k = true;
                else if (($x = array_search($k, $topic_map, true)) !== false)
                    $pj->topics->$x = true;
                else
                    $pj->bad_topics[] = $k;
        } else if ($topics)
            $this->error_at("topics", "Format error [topics]");
    }

    private function normalize_options($pj, $options) {
        // canonicalize option values to use IDs, not abbreviations
        $pj->options = (object) array();
        foreach ($options as $id => $oj) {
            $omatches = $this->conf->paper_opts->search($id);
            if (count($omatches) != 1)
                $pj->bad_options[$id] = true;
            else {
                $o = current($omatches);
                // XXX setting decision in JSON?
                if ($o->final && (!$this->prow || $this->prow->outcome <= 0))
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
            if ($ct === "none" || $ct === "" || $ct === false || $ct === 0)
                continue;
            if ($ct === "conflict")
                $ct = true;
            if (!($pccid = $this->conf->pc_member_by_email($email)))
                $pj->bad_pc_conflicts->$email = true;
            else if (!is_int($ct) && !is_string($ct) && $ct !== true)
                $this->error_at("pc_conflicts", "Format error [PC conflicts]");
            else {
                if (is_int($ct) && isset(Conflict::$type_names[$ct]))
                    $ctn = $ct;
                else if (($ctn = array_search($ct, Conflict::$type_names, true)) !== false)
                    /* OK */;
                else {
                    $pj->bad_pc_conflicts->$email = $ct;
                    $ctn = array_search("other", Conflict::$type_names, true);
                }
                $pj->pc_conflicts->$email = $ctn;
            }
        }
    }

    private function valid_contact($lemail, $old_contacts) {
        global $Me;
        return $lemail
            && (get($old_contacts, $lemail) || validate_email($lemail)
                || strcasecmp($lemail, $Me->email) == 0);
    }

    private function normalize($pj, $old_pj, $preserve) {
        // Errors prevent saving
        global $Now;

        // Title, abstract
        $this->normalize_string($pj, "title", true, $preserve);
        $this->normalize_string($pj, "abstract", false, $preserve);
        $this->normalize_string($pj, "collaborators", false, $preserve);
        if (isset($pj->collaborators)) {
            $collab = [];
            foreach (preg_split('/[\r\n]+/', $pj->collaborators) as $line)
                $collab[] = preg_replace('/[,;\s]+\z/', '', $line);
            while (!empty($collab) && $collab[count($collab) - 1] === "")
                array_pop($collab);
            if (!empty($collab))
                $pj->collaborators = join("\n", $collab) . "\n";
            else
                $pj->collaborators = "";
        }

        // Authors
        $au_by_email = array();
        $pj->bad_authors = $pj->bad_email_authors = array();
        if (isset($pj->authors)) {
            if (!is_array($pj->authors))
                $this->error_at("authors", "Format error [authors]");
            // old author information
            $old_au_by_email = [];
            if ($old_pj && isset($old_pj->authors)) {
                foreach ($old_pj->authors as $au)
                    if (isset($au->email))
                        $old_au_by_email[strtolower($au->email)] = $au;
            }
            // new author information
            $curau = is_array($pj->authors) ? $pj->authors : array();
            $pj->authors = array();
            foreach ($curau as $k => $au)
                if (is_string($au) || is_object($au))
                    $this->normalize_author($pj, $au, $au_by_email, $old_au_by_email, $preserve);
                else
                    $this->error_at("authors", "Format error [authors]");
        }

        // Status
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
                $this->error_at("nonblind", "Format error [nonblind]");
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
                $this->error_at("options", "Format error [options]");
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
            $this->error_at("pc_conflicts", "Format error [PC conflicts]");
            unset($pj->pc_conflicts);
        }

        // Old contacts (to avoid validate_email errors on unchanged contacts)
        $old_contacts = array();
        if ($old_pj && get($old_pj, "authors"))
            foreach ($old_pj->authors as $au)
                if (get($au, "contact"))
                    $old_contacts[strtolower($au->email)] = true;
        if ($old_pj && get($old_pj, "contacts"))
            foreach ($old_pj->contacts as $cflt)
                $old_contacts[strtolower($cflt->email)] = true;

        // verify emails on authors marked as contacts
        $pj->bad_contacts = array();
        foreach (get($pj, "authors") ? : array() as $au)
            if (get($au, "contact")
                && (!get($au, "email")
                    || !$this->valid_contact(strtolower($au->email), $old_contacts)))
                $pj->bad_contacts[] = $au;

        // Contacts
        $contacts = get($pj, "contacts");
        if ($contacts !== null) {
            if (is_object($contacts) || is_array($contacts))
                $contacts = (array) $contacts;
            else {
                $this->error_at("contacts", "Format error [contacts]");
                $contacts = array();
            }
            $pj->contacts = array();
            // verify emails on explicitly named contacts
            foreach ($contacts as $k => $v) {
                if (!$v)
                    continue;
                if ($v === true)
                    $v = (object) array();
                else if (is_string($v) && is_int($k)) {
                    $v = trim($v);
                    if ($this->valid_contact(strtolower($v), $old_contacts))
                        $v = (object) array("email" => $v);
                    else
                        $v = Text::analyze_name($v);
                }
                if (is_object($v) && !get($v, "email") && is_string($k))
                    $v->email = $k;
                if (is_object($v) && get($v, "email")) {
                    $lemail = strtolower($v->email);
                    if ($this->valid_contact($lemail, $old_contacts))
                        $pj->contacts[] = (object) array_merge((array) get($au_by_email, $lemail), (array) $v);
                    else
                        $pj->bad_contacts[] = $v;
                } else
                    $this->error_at("contacts", "Format error [contacts]");
            }
        }

        // Inherit contactness
        if (isset($pj->authors) && $old_pj && isset($old_pj->authors)) {
            foreach ($old_pj->authors as $au)
                if (get($au, "contact") && $au->email
                    && ($aux = get($au_by_email, strtolower($au->email)))
                    && !isset($aux->contact))
                    $aux->contact = true;
        }
        if (isset($pj->authors) && $old_pj && isset($old_pj->contacts)) {
            foreach ($old_pj->contacts as $au)
                if (($aux = get($au_by_email, strtolower($au->email)))
                    && !isset($aux->contact))
                    $aux->contact = true;
        }
    }

    private function check_options($pj) {
        $pj->parsed_options = array();
        foreach ($pj->options as $oid => $oj) {
            $o = $this->conf->paper_opts->find($oid);
            $result = null;
            if ($oj !== null && $oj !== false)
                $result = $o->parse_json($oj, $this);
            if ($result === null || $result === false)
                $result = [];
            if (!is_array($result))
                $result = [[$result]];
            else if (count($result) == 2 && is_string($result[1]))
                $result = [$result];
            $pj->parsed_options[$o->id] = $result;
        }
        ksort($pj->parsed_options);
    }

    private function check_invariants($pj, $old_pj) {
        // Errors don't prevent saving
        if (get($pj, "title") === ""
            || (get($pj, "title") === null && (!$old_pj || !$old_pj->title)))
            $this->error_at("title", $this->_("Each submission must have a title."));
        if (get($pj, "abstract") === ""
            || (get($pj, "abstract") === null && (!$old_pj || !get($old_pj, "abstract")))) {
            if (!$this->conf->opt("noAbstract"))
                $this->error_at("abstract", $this->_("Each submission must have an abstract."));
        }
        if ((is_array(get($pj, "authors")) && empty($pj->authors))
            || (get($pj, "authors") === null && (!$old_pj || empty($old_pj->authors))))
            $this->error_at("authors", $this->_("Each submission must have at least one author."));
        $max_authors = $this->conf->opt("maxAuthors");
        if ($max_authors > 0 && is_array(get($pj, "authors")) && count($pj->authors) > $max_authors)
            $this->error_at("authors", $this->_("Each submission can have at most %d authors.", $max_authors));
        if (!empty($pj->bad_authors))
            $this->error_at("authors", $this->_("Some authors ignored."));
        foreach ($pj->bad_email_authors as $k => $aux) {
            $this->error_at("authors", null);
            $this->error_at("auemail" . ($k + 1), $this->_("“%s” is not a valid email address.", htmlspecialchars($aux->email)));
        }
        $ncontacts = 0;
        foreach ($this->conflicts_array($pj, $old_pj) as $c)
            if ($c >= CONFLICT_CONTACTAUTHOR)
                ++$ncontacts;
        if (!$ncontacts && $old_pj && self::contacts_array($old_pj))
            $this->error_at("contacts", $this->_("Each submission must have at least one contact."));
        foreach ($pj->bad_contacts as $reg)
            if (!isset($reg->email))
                $this->error_at("contacts", $this->_("Contact %s has no associated email.", Text::user_html($reg)));
            else
                $this->error_at("contacts", $this->_("Contact email %s is invalid.", htmlspecialchars($reg->email)));
        if (get($pj, "options"))
            $this->check_options($pj);
        if (!empty($pj->bad_topics))
            $this->warning_at("topics", "Unknown topics ignored (" . htmlspecialchars(commajoin($pj->bad_topics)) . ").");
        if (!empty($pj->bad_options))
            $this->warning_at("options", "Unknown options ignored (" . htmlspecialchars(commajoin(array_keys($pj->bad_options))) . ").");
    }

    static private function author_information($pj) {
        $x = "";
        foreach (($pj && get($pj, "authors") ? $pj->authors : array()) as $au) {
            $x .= get($au, "first", get($au, "firstName", "")) . "\t"
                . get($au, "last", get($au, "lastName", "")) . "\t"
                . get($au, "email", "") . "\t"
                . get($au, "affiliation", "") . "\n";
        }
        return $x;
    }

    static function topics_sql($pj, $paperid) {
        $x = array();
        foreach (($pj ? (array) get($pj, "topics") : array()) as $id => $v)
            $x[] = "($id,$paperid)";
        sort($x);
        return join(",", $x);
    }

    private function options_sql($pj, $paperid) {
        $q = [];
        foreach ($pj->parsed_options as $id => $ovs)
            foreach ($ovs as $ov) {
                if (is_int($ov))
                    $q[] = "($paperid,$id,$ov,null)";
                else
                    $q[] = Dbl::format_query($this->conf->dblink, "($paperid,$id,?,?)", $ov[0], get($ov, 1));
            }
        sort($q);
        return join(", ", $q);
    }

    static private function contacts_array($pj) {
        $contacts = array();
        foreach (get($pj, "authors") ? : array() as $au)
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

    function conflicts_array($pj, $old_pj) {
        $x = array();

        if ($pj && isset($pj->pc_conflicts))
            $c = $pj->pc_conflicts;
        else
            $c = ($old_pj ? get($old_pj, "pc_conflicts") : null) ? : array();
        foreach ((array) $c as $email => $type)
            $x[strtolower($email)] = $type;

        if ($pj && isset($pj->authors))
            $c = $pj->authors;
        else
            $c = $old_pj ? $old_pj->authors : array();
        foreach ($c as $au)
            if (get($au, "email")) {
                $lemail = strtolower($au->email);
                $x[$lemail] = get($au, "contact") ? CONFLICT_CONTACTAUTHOR : CONFLICT_AUTHOR;
            }

        if ($pj && isset($pj->contacts))
            $c = $pj->contacts;
        else
            $c = $old_pj ? (get($old_pj, "contacts") ? : []) : [];
        foreach ($c as $v) {
            $lemail = strtolower($v->email);
            $x[$lemail] = max((int) get($x, $lemail), CONFLICT_CONTACTAUTHOR);
        }

        if ($old_pj && get($old_pj, "pc_conflicts")) {
            $can_administer = !$this->contact
                || $this->contact->can_administer($this->prow, $this->forceShow);
            foreach ($old_pj->pc_conflicts as $email => $type)
                if ($type == CONFLICT_CHAIRMARK) {
                    $lemail = strtolower($email);
                    if (get_i($x, $lemail) < CONFLICT_CHAIRMARK
                        && !$can_administer)
                        $x[$lemail] = CONFLICT_CHAIRMARK;
                }
        }

        ksort($x);
        return $x;
    }

    private function addf($f, $v) {
        $this->qf[] = "$f=?";
        $this->qv[] = $v;
    }

    function save_paper_json($pj) {
        global $Now;
        assert(!$this->hide_docids);

        $paperid = get($pj, "pid", get($pj, "id", null));
        if ($paperid !== null && is_int($paperid) && $paperid <= 0)
            $paperid = null;
        if ($paperid !== null && !is_int($paperid)) {
            $key = isset($pj->pid) ? "pid" : "id";
            $this->error_at($key, "Format error [$key]");
            return false;
        }

        if (get($pj, "error") || get($pj, "error_html")) {
            $this->error_at("error", $this->_("Refusing to save submission with error"));
            return false;
        }

        $this->prow = $old_pj = null;
        $this->paperId = $paperid ? : -1;
        if ($paperid)
            $this->prow = $this->conf->paperRow(["paperId" => $paperid, "topics" => true, "options" => true], $this->contact);
        if ($this->prow)
            $old_pj = $this->paper_json($this->prow, ["forceShow" => true]);
        if ($pj && $old_pj && $paperid != $old_pj->pid) {
            $this->error_at("pid", $this->_("Saving submission with different ID"));
            return false;
        }

        $this->normalize($pj, $old_pj, false);
        if ($old_pj)
            $this->normalize($old_pj, null, true);
        if ($this->has_error())
            return false;
        $this->check_invariants($pj, $old_pj);

        // store documents (options already stored)
        if (isset($pj->submission) && $pj->submission)
            $this->upload_document($pj->submission, $this->conf->paper_opts->find_document(DTYPE_SUBMISSION));
        if (isset($pj->final) && $pj->final)
            $this->upload_document($pj->final, $this->conf->paper_opts->find_document(DTYPE_FINAL));

        // create contacts
        foreach (self::contacts_array($pj) as $c) {
            $c->only_if_contactdb = !get($c, "contact");
            $c->disabled = !!$this->disable_users;
            if (!Contact::create($this->conf, $c, !$this->no_email)
                && get($c, "contact"))
                $this->error_at("contacts", $this->_("Could not create an account for contact %s.", Text::user_html($c)));
        }

        // catch errors
        if ($this->has_error())
            return false;

        // update Paper table
        $this->qf = $this->qv = [];
        foreach (array("title", "abstract", "collaborators") as $k) {
            $v = convert_to_utf8((string) get($pj, $k));
            if (!$old_pj || (isset($pj->$k) && $v !== (string) get($old_pj, $k)))
                $this->addf($k, $v);
        }

        if (!$old_pj || isset($pj->authors)) {
            $autext = convert_to_utf8(self::author_information($pj));
            $old_autext = self::author_information($old_pj);
            if ($autext !== $old_autext || !$old_pj)
                $this->addf("authorInformation", $autext);
        }

        if ($this->conf->submission_blindness() == Conf::BLIND_OPTIONAL
            && (!$old_pj || (isset($pj->nonblind) && !$pj->nonblind != !$old_pj->nonblind)))
            $this->addf("blind", get($pj, "nonblind") ? 0 : 1);

        $newPaperStorageId = null;
        if (!$old_pj || isset($pj->submission)) {
            $new_id = get($pj, "submission") ? $pj->submission->docid : 1;
            $old_id = $old_pj && get($old_pj, "submission") ? $old_pj->submission->docid : 1;
            if (!$old_pj || $new_id != $old_id) {
                $this->addf("paperStorageId", $new_id);
                $newPaperStorageId = $new_id;
            }
        }

        $newFinalPaperStorageId = null;
        if (!$old_pj || isset($pj->final)) {
            $new_id = get($pj, "final") ? $pj->final->docid : 0;
            $old_id = $old_pj && get($old_pj, "final") ? $old_pj->final->docid : 0;
            if (!$old_pj || $new_id != $old_id) {
                $this->addf("finalPaperStorageId", $new_id);
                $newFinalPaperStorageId = $new_id;
            }
        }

        $pj_withdrawn = get($pj, "withdrawn");
        $pj_submitted = get($pj, "submitted");
        $pj_draft = get($pj, "draft");
        if ($pj_withdrawn === null && $pj_submitted === null && $pj_draft === null) {
            $pj_status = get($pj, "status");
            if ($pj_status === "submitted")
                $pj_submitted = true;
            else if ($pj_status === "withdrawn")
                $pj_withdrawn = true;
            else if ($pj_status === "draft")
                $pj_draft = true;
        }

        if ($pj_withdrawn !== null || $pj_submitted !== null || $pj_draft !== null) {
            if ($pj_submitted !== null)
                $submitted = $pj_submitted;
            else if ($pj_draft !== null)
                $submitted = !$pj_draft;
            else if ($old_pj)
                $submitted = get($old_pj, "submitted_at") > 0;
            else
                $submitted = false;
            if ($pj_withdrawn) {
                if (!$old_pj || !get($old_pj, "withdrawn")) {
                    $this->addf("timeWithdrawn", get($pj, "withdrawn_at") ? : $Now);
                    $this->addf("timeSubmitted", $submitted ? -100 : 0);
                } else if ((get($old_pj, "submitted_at") > 0) !== $submitted)
                    $this->addf("timeSubmitted", $submitted ? -100 : 0);
            } else if ($submitted) {
                if (!$old_pj || !get($old_pj, "submitted"))
                    $this->addf("timeSubmitted", get($pj, "submitted_at") ? : $Now);
                if ($old_pj && get($old_pj, "withdrawn"))
                    $this->addf("timeWithdrawn", 0);
            } else if ($old_pj && (get($old_pj, "withdrawn") || get($old_pj, "submitted"))) {
                $this->addf("timeSubmitted", 0);
                $this->addf("timeWithdrawn", 0);
            }
        }

        if (isset($pj->final_submitted)) {
            if ($pj->final_submitted)
                $time = get($pj, "final_submitted_at") ? : $Now;
            else
                $time = 0;
            if (!$old_pj || get($old_pj, "final_submitted_at") != $time)
                $this->addf("timeFinalSubmitted", $time);
        }

        if (!empty($this->qf)) {
            if ($this->conf->submission_blindness() == Conf::BLIND_NEVER)
                $this->addf("blind", 0);
            else if ($this->conf->submission_blindness() != Conf::BLIND_OPTIONAL)
                $this->addf("blind", 1);

            if ($old_pj && isset($old_pj->final))
                $old_joindoc = $old_pj->final;
            else if ($old_pj && isset($old_pj->submission))
                $old_joindoc = $old_pj->submission;
            else
                $old_joindoc = null;
            if ($newFinalPaperStorageId > 0)
                $new_joindoc = $pj->final;
            else if ($newFinalPaperStorageId === null && $old_pj && isset($old_pj->final))
                $new_joindoc = $old_pj->final;
            else if ($newPaperStorageId > 1)
                $new_joindoc = $pj->submission;
            else if ($newPaperStorageId === null && $old_pj && isset($old_pj->submission))
                $new_joindoc = $old_pj->submission;
            else
                $new_joindoc = null;
            if ($new_joindoc
                && (!$old_joindoc || $old_joindoc->docid != $new_joindoc->docid)) {
                $this->addf("size", $new_joindoc->size);
                $this->addf("mimetype", $new_joindoc->mimetype);
                $this->addf("sha1", Filer::binary_sha1($new_joindoc->sha1));
                $this->addf("timestamp", $new_joindoc->timestamp);
                if ($this->conf->sversion >= 145)
                    $this->addf("pdfFormatStatus", 0);
            } else if (!$paperid || ($new_joindoc && !$old_joindoc)) {
                $this->addf("size", 0);
                $this->addf("mimetype", "");
                $this->addf("sha1", "");
                $this->addf("timestamp", 0);
                if ($this->conf->sversion >= 145)
                    $this->addf("pdfFormatStatus", 0);
            }

            $this->addf("timeModified", $Now);

            if ($paperid) {
                $this->qv[] = $paperid;
                $result = $this->conf->qe_apply("update Paper set " . join(", ", $this->qf) . " where paperId=?", $this->qv);
                if ($result
                    && $result->affected_rows === 0
                    && edb_nrows($this->conf->qe("select paperId from Paper where paperId=?", $paperid)) === 0)
                    $result = $this->conf->qe_apply("insert into Paper set " . join(", ", $this->qf) . ", paperId=?", $this->qv);
            } else {
                $result = $this->conf->qe_apply("insert into Paper set " . join(", ", $this->qf), $this->qv);
                if (!$result
                    || !($paperid = $pj->pid = $result->insert_id))
                    return $this->error_at(false, $this->_("Could not create paper."));
                if (!empty($this->uploaded_documents))
                    $this->conf->qe("update PaperStorage set paperId=? where paperStorageId?a", $paperid, $this->uploaded_documents);
            }

            // maybe update `papersub` settings
            $is_submitted = !get($pj, "withdrawn") && get($pj, "submitted");
            $was_submitted = $old_pj && !get($old_pj, "withdrawn") && get($old_pj, "submitted");
            if ($is_submitted != $was_submitted)
                $this->conf->update_papersub_setting($is_submitted);
        }

        // update PaperTopics
        if (get($pj, "topics")) {
            $topics = self::topics_sql($pj, $paperid);
            $old_topics = self::topics_sql($old_pj, $paperid);
            if ($topics !== $old_topics) {
                $this->conf->qe_raw("delete from PaperTopic where paperId=$paperid");
                if ($topics)
                    $this->conf->qe_raw("insert into PaperTopic (topicId, paperId) values $topics");
            }
        }

        // update PaperOption
        if (get($pj, "options")) {
            $options = convert_to_utf8($this->options_sql($pj, $paperid));
            if ($old_pj && isset($old_pj->options)) {
                $this->check_options($old_pj);
                $old_options = $this->options_sql($old_pj, $paperid);
            } else
                $old_options = "";
            if ($options !== $old_options) {
                $this->conf->qe("delete from PaperOption where paperId=? and optionId?a", $paperid, array_keys($pj->parsed_options));
                if ($options)
                    $this->conf->qe_raw("insert into PaperOption (paperId,optionId,value,data) values $options");
            }
        }

        // update PaperConflict
        $conflict = $this->conflicts_array($pj, $old_pj);
        $old_conflict = $this->conflicts_array($old_pj, null);
        if (join(",", array_keys($conflict)) !== join(",", array_keys($old_conflict))
            || join(",", array_values($conflict)) !== join(",", array_values($old_conflict))) {
            $ins = array();
            if (!empty($conflict)) {
                $result = $this->conf->qe("select contactId, email from ContactInfo where email?a", array_keys($conflict));
                while (($row = edb_row($result)))
                    $ins[] = "($paperid,$row[0]," . $conflict[strtolower($row[1])] . ")";
            }
            $this->conf->qe("delete from PaperConflict where paperId=?", $paperid);
            if (!empty($ins))
                $this->conf->qe_raw("insert into PaperConflict (paperId,contactId,conflictType) values " . join(",", $ins));
        }

        return $paperid;
    }
}
