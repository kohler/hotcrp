<?php
// paperstatus.php -- HotCRP helper for reading/storing papers as JSON
// HotCRP is Copyright (c) 2008-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperStatus {
    private $contact = null;
    private $uploaded_documents;
    private $errf;
    private $errmsg;
    private $has_errors;
    private $no_email = false;
    private $allow_error = array();
    private $forceShow = null;
    private $export_ids = false;
    private $hide_docids = false;
    private $export_content = false;
    private $disable_users = false;
    private $prow;
    private $paperid;
    private $document_callbacks = array();

    function __construct($contact, $options = array()) {
        $this->contact = $contact;
        foreach (array("no_email", "allow_error",
                       "forceShow", "export_ids", "hide_docids",
                       "export_content", "disable_users") as $k)
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        $this->clear();
    }

    function clear() {
        $this->uploaded_documents = array();
        $this->errf = array();
        $this->errmsg = array();
        $this->has_errors = false;
        $this->prow = null;
    }

    function add_document_callback($cb) {
        $this->document_callbacks[] = $cb;
    }

    function paper_row() {
        return $this->prow;
    }

    public function document_to_json($dtype, $docid) {
        global $Conf;
        if (!is_object($docid)) {
            $dresult = $Conf->document_result($this->paperid, $dtype, $docid);
            $drow = $Conf->document_row($dresult, $dtype);
            Dbl::free($dresult);
        } else {
            $drow = $docid;
            $docid = $drow ? $drow->paperStorageId : null;
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
                $d->timestamp = (int) $drow->timestamp;
            if (get($drow, "filename"))
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
        global $Conf;
        if (is_int($prow))
            $prow = $Conf->paperRow(["paperId" => $prow, "topics" => true, "options" => true], $this->contact);
        $contact = $this->contact;
        if (get($args, "forceShow"))
            $contact = null;

        if (!$prow || ($contact && !$contact->can_view_paper($prow)))
            return null;
        $this->prow = $prow;
        $this->paperid = $prow->paperId;

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
            foreach ($prow->named_contacts() as $conf)
                $contacts[strtolower($conf->email)] = $conf;

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
                if ($lemail && ($conf = get($contacts, $lemail))
                    && $conf->conflictType >= CONFLICT_AUTHOR) {
                    $aux->contact = true;
                    unset($contacts[$lemail]);
                }
                $pj->authors[] = $aux;
            }

            $other_contacts = array();
            foreach ($contacts as $conf)
                if ($conf->conflictType >= CONFLICT_AUTHOR) {
                    $aux = (object) array("email" => $conf->email);
                    if ($conf->firstName)
                        $aux->first = $conf->firstName;
                    if ($conf->lastName)
                        $aux->last = $conf->lastName;
                    if ($conf->affiliation)
                        $aux->affiliation = $conf->affiliation;
                    $other_contacts[] = $aux;
                }
            if (!empty($other_contacts))
                $pj->contacts = $other_contacts;
        }

        if ($Conf->submission_blindness() == Conf::BLIND_OPTIONAL)
            $pj->nonblind = !(isset($pj->paperBlind) ? $prow->paperBlind : $prow->blind);

        $pj->abstract = $prow->abstract;

        $topics = array();
        foreach (array_intersect_key($Conf->topic_map(), array_flip($prow->topics())) as $tid => $tname)
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

        if (count($prow->options())) {
            $options = array();
            foreach (PaperOption::option_list() as $o) {
                if ($contact && !$contact->can_view_paper_option($prow, $o, $this->forceShow))
                    continue;
                $ov = $prow->option($o->id) ? : new PaperOptionValue($o->id, $o);
                $options[$this->export_ids ? $o->id : $o->abbr] =
                    $o->unparse_json($ov, $this, $contact);
            }
            if (!empty($options))
                $pj->options = (object) $options;
        }

        if ($can_view_authors) {
            $pcconflicts = array();
            foreach ($prow->pc_conflicts(true) as $id => $conf) {
                if (($ctname = get(Conflict::$type_names, $conf->conflictType)))
                    $pcconflicts[$conf->email] = $ctname;
            }
            if (!empty($pcconflicts))
                $pj->pc_conflicts = (object) $pcconflicts;
        }

        if ($prow->collaborators && $can_view_authors)
            $pj->collaborators = $prow->collaborators;

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


    public function set_option_error_html($o, $html) {
        $this->set_error_html($o->id <= 0 ? $o->abbr : "opt$o->id",
                              htmlspecialchars($o->name) . ": " . $html);
    }

    public function set_error_html($field, $html) {
        if ($field)
            $this->errf[$field] = true;
        if ($html)
            $this->errmsg[] = $html;
        if (!$field
            || !$this->allow_error
            || array_search($field, $this->allow_error) === false)
            $this->has_errors = true;
    }

    public function set_warning_html($field, $html) {
        if ($field)
            $this->errf[$field] = true;
        $this->errmsg[] = $html;
    }

    public function upload_document($docj, PaperOption $o) {
        global $Conf;
        // look for an existing document with same sha1;
        // check existing docid's sha1
        $docid = get($docj, "docid");
        if ($docid) {
            $oldj = $this->document_to_json($o->id, $docid);
            if (get($docj, "sha1") && get($oldj, "sha1") !== $docj->sha1)
                $docid = null;
        } else if (!$docid && $this->paperid > 0 && get($docj, "sha1")) {
            $result = Dbl::qe("select paperStorageId from PaperStorage where paperId=? and documentType=? and sha1=?", $this->paperid, $o->id, $docj->sha1);
            if (($row = edb_row($result)))
                $docid = $row[0];
        }
        if ($docid) {
            $docj->docid = $docid;
            return;
        }

        // check filter
        if (get($docj, "filter") && is_int($docj->filter)) {
            if (is_int(get($docj, "original_id")))
                $result = Dbl::qe("select paperStorageId, timestamp, sha1 from PaperStorage where paperStorageId=?", $docj->original_id);
            else if (is_string(get($docj, "original_sha1")))
                $result = Dbl::qe("select paperStorageId, timestamp, sha1 from PaperStorage where paperId=? and sha1=?", $this->paperid, $docj->original_sha1);
            else if ($o->id == DTYPE_SUBMISSION || $o->id == DTYPE_FINAL)
                $result = Dbl::qe("select PaperStorage.paperStorageId, PaperStorage.timestamp, PaperStorage.sha1 from PaperStorage join Paper on (Paper.paperId=PaperStorage.paperId and Paper." . ($o->id == DTYPE_SUBMISSION ? "paperStorageId" : "finalPaperStorageId") . "=PaperStorage.paperStorageId) where Paper.paperId=?", $this->paperid);
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
        }

        // if no sha1 match, upload
        $docclass = new HotCRPDocument($o->id);
        $upload = null;
        if ($docclass->load($docj))
            $upload = $docclass->upload($docj, (object) array("paperId" => $this->paperid));
        if ($upload && get($upload, "paperStorageId") > 1) {
            foreach (array("size", "sha1", "mimetype", "timestamp") as $k)
                $docj->$k = $upload->$k;
            $this->uploaded_documents[] = $docj->docid = $upload->paperStorageId;
        } else {
            $docj->docid = 1;
            $this->set_option_error_html($o, $upload ? $upload->error_html : "empty document");
        }
    }

    private function normalize_string($pj, $k, $simplify) {
        if (isset($pj->$k))
            if (is_string($pj->$k))
                $pj->$k = $simplify ? simplify_whitespace($pj->$k) : trim($pj->$k);
            else {
                $this->set_error_html($k, "Format error [$k]");
                unset($pj, $k);
            }
    }

    private function normalize_topics($pj) {
        global $Conf;
        $topics = $pj->topics;
        unset($pj->topics);
        if (is_array($topics)) {
            $new_topics = (object) array();
            foreach ($topics as $v)
                if ($v && (is_int($v) || is_string($v)))
                    $new_topics->$v = true;
                else if ($v)
                    $this->set_error_html("topics", "Format error [topics]");
            $topics = $new_topics;
        }
        if (is_object($topics)) {
            $topic_map = $Conf->topic_map();
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
            $this->set_error_html("topics", "Format error [topics]");
    }

    private function normalize_options($pj) {
        // canonicalize option values to use IDs, not abbreviations
        $options = $pj->options;
        $pj->options = (object) array();
        foreach ($options as $id => $oj) {
            $omatches = PaperOption::search($id);
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
            if (!($pccid = pcByEmail($email)))
                $pj->bad_pc_conflicts->$email = true;
            else if (!is_int($ct) && !is_string($ct) && $ct !== true)
                $this->set_error_html("pc_conflicts", "Format error [PC conflicts]");
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

    private function normalize($pj, $old_pj) {
        // Errors prevent saving
        global $Conf, $Now;

        // Title, abstract
        $this->normalize_string($pj, "title", true);
        $this->normalize_string($pj, "abstract", false);
        $this->normalize_string($pj, "collaborators", false);
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
                $this->set_error_html("author", "Format error [authors]");
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
                if (is_string($au) || is_object($au)) {
                    $aux = Text::analyze_name($au);
                    $aux->first = simplify_whitespace($aux->firstName);
                    $aux->last = simplify_whitespace($aux->lastName);
                    $aux->email = simplify_whitespace($aux->email);
                    $aux->affiliation = simplify_whitespace($aux->affiliation);
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
                            $pj->bad_email_authors[$k] = $aux;
                    }
                } else
                    $this->set_error_html("author", "Format error [authors]");
        }

        // Status
        foreach (array("withdrawn_at", "submitted_at", "final_submitted_at") as $k)
            if (isset($pj->$k)) {
                if (is_numeric($pj->$k))
                    $pj->$k = (int) $pj->$k;
                else if (is_string($pj->$k))
                    $pj->$k = $Conf->parse_time($pj->$k, $Now);
                else
                    $pj->$k = false;
                if ($pj->$k === false || $pj->$k < 0)
                    $pj->$k = $Now;
            }

        // Blindness
        if (isset($pj->nonblind)) {
            if (($x = friendly_boolean($pj->nonblind)) !== null)
                $pj->nonblind = $x;
            else
                $this->set_error_html("nonblind", "Format error [nonblind]");
        }

        // Topics
        $pj->bad_topics = array();
        if (get($pj, "topics") !== null)
            $this->normalize_topics($pj);

        // Options
        $pj->bad_options = array();
        if (get($pj, "options") && is_object($pj->options))
            $this->normalize_options($pj);
        else if (get($pj, "options") === false)
            $pj->options = (object) array();
        else if (get($pj, "options") !== null)
            $this->set_error_html("options", "Format error [options]");

        // PC conflicts
        $pj->bad_pc_conflicts = (object) array();
        if (get($pj, "pc_conflicts")
            && (is_object($pj->pc_conflicts) || is_array($pj->pc_conflicts)))
            $this->normalize_pc_conflicts($pj);
        else if (get($pj, "pc_conflicts") === false)
            $pj->pc_conflicts = (object) array();
        else if (get($pj, "pc_conflicts") !== null)
            $this->set_error_html("pc_conflicts", "Format error [PC conflicts]");

        // Old contacts (to avoid validate_email errors on unchanged contacts)
        $old_contacts = array();
        if ($old_pj && get($old_pj, "authors"))
            foreach ($old_pj->authors as $au)
                if (get($au, "contact"))
                    $old_contacts[strtolower($au->email)] = true;
        if ($old_pj && get($old_pj, "contacts"))
            foreach ($old_pj->contacts as $conf)
                $old_contacts[strtolower($conf->email)] = true;

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
                $this->set_error_html("contacts", "Format error [contacts]");
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
                    $this->set_error_html("contacts", "Format error [contacts]");
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
            $o = PaperOption::find($oid);
            $result = null;
            if ($oj !== null)
                $result = $o->parse_json($oj, $this);
            if ($result === null)
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
        global $Opt;
        // Errors don't prevent saving
        if (get($pj, "title") === ""
            || (get($pj, "title") === null && (!$old_pj || !$old_pj->title)))
            $this->set_error_html("title", "Each paper must have a title.");
        if (get($pj, "abstract") === ""
            || (get($pj, "abstract") === null && (!$old_pj || !$old_pj->abstract))) {
            if (!get($Opt, "noAbstract"))
                $this->set_error_html("abstract", "Each paper must have an abstract.");
        }
        if ((is_array(get($pj, "authors")) && empty($pj->authors))
            || (get($pj, "authors") === null && (!$old_pj || empty($old_pj->authors))))
            $this->set_error_html("author", "Each paper must have at least one author.");
        if (!empty($pj->bad_authors))
            $this->set_error_html("author", "Some authors ignored.");
        foreach ($pj->bad_email_authors as $k => $aux) {
            $this->set_error_html("author", null);
            $this->set_error_html("auemail" . ($k + 1), "“" . htmlspecialchars($aux->email) . "” is not a valid email address.");
        }
        $ncontacts = 0;
        foreach ($this->conflicts_array($pj, $old_pj) as $c)
            if ($c >= CONFLICT_CONTACTAUTHOR)
                ++$ncontacts;
        if (!$ncontacts && $old_pj && self::contacts_array($old_pj))
            $this->set_error_html("contacts", "Each paper must have at least one contact.");
        foreach ($pj->bad_contacts as $reg)
            if (!isset($reg->email))
                $this->set_error_html("contacts", "Contact " . Text::user_html($reg) . " has no associated email.");
            else
                $this->set_error_html("contacts", "Contact email " . htmlspecialchars($reg->email) . " is invalid.");
        if (get($pj, "options"))
            $this->check_options($pj);
        if (!empty($pj->bad_topics))
            $this->set_warning_html("topics", "Unknown topics ignored (" . htmlspecialchars(commajoin($pj->bad_topics)) . ").");
        if (!empty($pj->bad_options))
            $this->set_warning_html("options", "Unknown options ignored (" . htmlspecialchars(commajoin(array_keys($pj->bad_options))) . ").");
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

    static function options_sql($pj, $paperid) {
        $q = [];
        foreach (get($pj, "parsed_options", []) as $id => $ovs)
            foreach ($ovs as $ov) {
                if (is_int($ov))
                    $q[] = "($paperid,$id,$ov,null)";
                else
                    $q[] = Dbl::format_query("($paperid,$id,?,?)", $ov[0], get($ov, 1));
            }
        sort($q);
        return join(",", $q);
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

        if ($pj && get($pj, "pc_conflicts") !== null)
            $c = $pj->pc_conflicts;
        else
            $c = ($old_pj ? get($old_pj, "pc_conflicts") : null) ? : array();
        foreach ((array) $c as $email => $type)
            $x[strtolower($email)] = $type;

        if ($pj && get($pj, "authors") !== null)
            $c = $pj->authors;
        else
            $c = $old_pj ? $old_pj->authors : array();
        foreach ($c as $au)
            if (get($au, "email")) {
                $lemail = strtolower($au->email);
                $x[$lemail] = get($au, "contact") ? CONFLICT_CONTACTAUTHOR : CONFLICT_AUTHOR;
            }

        if ($pj && get($pj, "contacts") !== null)
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

    function save_paper_json($pj) {
        global $Conf, $Now;
        assert(!$this->hide_docids);

        $paperid = null;
        if (isset($pj->pid) && is_int($pj->pid) && $pj->pid > 0)
            $paperid = $pj->pid;
        else if (!isset($pj->pid) && isset($pj->id) && is_int($pj->id) && $pj->id > 0)
            $paperid = $pj->id;
        else if (isset($pj->pid) || isset($pj->id)) {
            $key = isset($pj->pid) ? "pid" : "id";
            $this->set_error_html($key, "Format error [$key]");
            return false;
        }

        if (get($pj, "error") || get($pj, "error_html")) {
            $this->set_error_html("error", "Refusing to save paper with error");
            return false;
        }

        $this->prow = $old_pj = null;
        $this->paperid = $paperid ? : -1;
        if ($paperid)
            $this->prow = $Conf->paperRow(["paperId" => $paperid, "topics" => true, "options" => true], $this->contact);
        if ($this->prow)
            $old_pj = $this->paper_json($this->prow, ["forceShow" => true]);
        if ($pj && $old_pj && $paperid != $old_pj->pid) {
            $this->set_error_html("pid", "Saving paper with different ID");
            return false;
        }

        $this->normalize($pj, $old_pj);
        if ($old_pj)
            $this->normalize($old_pj, null);
        if ($this->has_errors)
            return false;
        $this->check_invariants($pj, $old_pj);

        // store documents (options already stored)
        if (isset($pj->submission) && $pj->submission)
            $this->upload_document($pj->submission, PaperOption::find_document(DTYPE_SUBMISSION));
        if (isset($pj->final) && $pj->final)
            $this->upload_document($pj->final, PaperOption::find_document(DTYPE_FINAL));

        // create contacts
        foreach (self::contacts_array($pj) as $c) {
            $c->only_if_contactdb = !get($c, "contact");
            $c->disabled = !!$this->disable_users;
            if (!Contact::create($c, !$this->no_email)
                && get($c, "contact"))
                $this->set_error_html("contacts", "Could not create an account for contact " . Text::user_html($c) . ".");
        }

        // catch errors
        if ($this->has_errors)
            return false;

        // update Paper table
        $q = array();
        foreach (array("title", "abstract", "collaborators") as $k) {
            $v = convert_to_utf8((string) get($pj, $k));
            if (!$old_pj || (get($pj, $k) !== null && $v !== (string) get($old_pj, $k)))
                $q[] = "$k='" . sqlq($v) . "'";
        }

        if (!$old_pj || get($pj, "authors") !== null) {
            $autext = convert_to_utf8(self::author_information($pj));
            $old_autext = self::author_information($old_pj);
            if ($autext !== $old_autext || !$old_pj)
                $q[] = "authorInformation='" . sqlq($autext) . "'";
        }

        if ($Conf->submission_blindness() == Conf::BLIND_OPTIONAL
            && (!$old_pj || (get($pj, "nonblind") !== null
                             && !$pj->nonblind != !$old_pj->nonblind)))
            $q[] = "blind=" . (get($pj, "nonblind") ? 0 : 1);

        if (!$old_pj || get($pj, "submission") !== null) {
            $new_id = get($pj, "submission") ? $pj->submission->docid : 1;
            $old_id = $old_pj && get($old_pj, "submission") ? $old_pj->submission->docid : 1;
            if (!$old_pj || $new_id != $old_id)
                $q[] = "paperStorageId=$new_id";
        }

        if (!$old_pj || get($pj, "final") !== null) {
            $new_id = get($pj, "final") ? $pj->final->docid : 0;
            $old_id = $old_pj && get($old_pj, "final") ? $old_pj->final->docid : 0;
            if (!$old_pj || $new_id != $old_id)
                $q[] = "finalPaperStorageId=$new_id";
        }

        if (get($pj, "withdrawn") !== null
            || get($pj, "submitted") !== null
            || get($pj, "draft") !== null) {
            if (get($pj, "submitted") !== null)
                $submitted = $pj->submitted;
            else if (get($pj, "draft") !== null)
                $submitted = !$pj->draft;
            else if ($old_pj)
                $submitted = get($old_pj, "submitted_at") > 0;
            else
                $submitted = false;
            if (get($pj, "withdrawn")) {
                if (!$old_pj || !get($old_pj, "withdrawn")) {
                    $q[] = "timeWithdrawn=" . (get($pj, "withdrawn_at") ? : $Now);
                    $q[] = "timeSubmitted=" . ($submitted ? -100 : 0);
                } else if ((get($old_pj, "submitted_at") > 0) !== $submitted)
                    $q[] = "timeSubmitted=" . ($submitted ? -100 : 0);
            } else if ($submitted) {
                if (!$old_pj || !get($old_pj, "submitted"))
                    $q[] = "timeSubmitted=" . (get($pj, "submitted_at") ? : $Now);
                if ($old_pj && get($old_pj, "withdrawn"))
                    $q[] = "timeWithdrawn=0";
            } else if ($old_pj && (get($old_pj, "withdrawn") || get($old_pj, "submitted"))) {
                $q[] = "timeSubmitted=0";
                $q[] = "timeWithdrawn=0";
            }
        }

        if (get($pj, "final_submitted") !== null) {
            if ($pj->final_submitted)
                $time = get($pj, "final_submitted_at") ? : $Now;
            else
                $time = 0;
            if (!$old_pj || get($old_pj, "final_submitted_at") != $time)
                $q[] = "timeFinalSubmitted=$time";
        }

        if (!empty($q)) {
            if ($Conf->submission_blindness() == Conf::BLIND_NEVER)
                $q[] = "blind=0";
            else if ($Conf->submission_blindness() != Conf::BLIND_OPTIONAL)
                $q[] = "blind=1";

            $joindoc = $old_joindoc = null;
            if (get($pj, "final")) {
                $joindoc = $pj->final;
                $old_joindoc = $old_pj ? get($old_pj, "final") : null;
            } else if (get($pj, "submission")) {
                $joindoc = $pj->submission;
                $old_joindoc = $old_pj ? get($old_pj, "submission") : null;
            }
            if ($joindoc
                && (!$old_joindoc || $old_joindoc->docid != $joindoc->docid)
                && get($joindoc, "size") && get($joindoc, "timestamp")) {
                $q[] = "size=" . $joindoc->size;
                $q[] = "mimetype='" . sqlq($joindoc->mimetype) . "'";
                $q[] = "sha1='" . sqlq($joindoc->sha1) . "'";
                $q[] = "timestamp=" . $joindoc->timestamp;
            } else if (!$joindoc)
                $q[] = "size=0,mimetype='',sha1='',timestamp=0";

            if ($paperid) {
                $result = Dbl::qe_raw("update Paper set " . join(",", $q) . " where paperId=$paperid");
                if ($result
                    && $result->affected_rows === 0
                    && edb_nrows(Dbl::qe_raw("select paperId from Paper where paperId=$paperid")) === 0)
                    $result = Dbl::qe_raw("insert into Paper set paperId=$paperid, " . join(",", $q));
            } else {
                $result = Dbl::qe_raw("insert into Paper set " . join(",", $q));
                if (!$result
                    || !($paperid = $pj->pid = $result->insert_id))
                    return $this->set_error_html(false, "Could not create paper.");
                if (!empty($this->uploaded_documents))
                    Dbl::qe_raw("update PaperStorage set paperId=$paperid where paperStorageId in (" . join(",", $this->uploaded_documents) . ")");
            }

            // maybe update `papersub` settings
            $is_submitted = !get($pj, "withdrawn") && get($pj, "submitted");
            $was_submitted = $old_pj && !get($old_pj, "withdrawn") && get($old_pj, "submitted");
            if ($is_submitted != $was_submitted)
                $Conf->update_papersub_setting($is_submitted);
        }

        // update PaperTopics
        if (get($pj, "topics")) {
            $topics = self::topics_sql($pj, $paperid);
            $old_topics = self::topics_sql($old_pj, $paperid);
            if ($topics !== $old_topics) {
                $result = Dbl::qe_raw("delete from PaperTopic where paperId=$paperid");
                if ($topics)
                    $result = Dbl::qe_raw("insert into PaperTopic (topicId,paperId) values $topics");
            }
        }

        // update PaperOption
        if (get($pj, "options")) {
            $options = convert_to_utf8(self::options_sql($pj, $paperid));
            $old_options = self::options_sql($old_pj, $paperid);
            if ($options !== $old_options) {
                $result = Dbl::qe("delete from PaperOption where paperId=$paperid and optionId?a", array_keys($pj->parsed_options));
                if ($options)
                    $result = Dbl::qe_raw("insert into PaperOption (paperId,optionId,value,data) values $options");
            }
        }

        // update PaperConflict
        $conflict = $this->conflicts_array($pj, $old_pj);
        $old_conflict = $this->conflicts_array($old_pj, null);
        if (join(",", array_keys($conflict)) !== join(",", array_keys($old_conflict))
            || join(",", array_values($conflict)) !== join(",", array_values($old_conflict))) {
            $q = array();
            foreach ($conflict as $email => $type)
                $q[] = "'" . sqlq($email) . "'";
            $ins = array();
            if (!empty($q)) {
                $result = Dbl::qe_raw("select contactId, email from ContactInfo where email in (" . join(",", $q) . ")");
                while (($row = edb_row($result)))
                    $ins[] = "($paperid,$row[0]," . $conflict[strtolower($row[1])] . ")";
            }
            $result = Dbl::qe_raw("delete from PaperConflict where paperId=$paperid");
            if (!empty($ins))
                $result = Dbl::qe_raw("insert into PaperConflict (paperId,contactId,conflictType) values " . join(",", $ins));
        }

        return $paperid;
    }

    function error_html() {
        return $this->errmsg;
    }

    function error_fields() {
        return $this->errf;
    }

    function has_error($field = null) {
        return $field ? isset($this->errf[$field]) : $this->has_errors;
    }
}
