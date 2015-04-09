<?php
// paperstatus.php -- HotCRP helper for reading/storing papers as JSON
// HotCRP is Copyright (c) 2008-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperStatus {

    private $uploaded_documents;
    private $errf;
    private $errmsg;
    public $nerrors;
    private $no_email = false;
    private $allow_error = array();
    private $view_contact = null;
    private $forceShow = null;
    private $export_ids = false;
    private $export_docids = false;
    private $export_content = false;
    private $disable_users = false;
    private $document_callbacks = array();

    function __construct($options = array()) {
        foreach (array("no_email", "allow_error", "view_contact",
                       "forceShow", "export_ids", "export_docids",
                       "export_content", "disable_users") as $k)
            if (array_key_exists($k, $options))
                $this->$k = $options[$k];
        $this->clear();
    }

    function clear() {
        $this->uploaded_documents = array();
        $this->errf = array();
        $this->errmsg = array();
        $this->nerrors = 0;
    }

    function add_document_callback($cb) {
        $this->document_callbacks[] = $cb;
    }

    function load($pid, $args = array()) {
        global $Conf, $Me;
        $prow = $Conf->paperRow(array("paperId" => $pid,
                                      "topics" => true, "options" => true),
                                $this->view_contact ? : $Me);
        return $prow ? $this->row_to_json($prow, $args) : null;
    }

    private function document_to_json($prow, $dtype, $docid, $args) {
        global $Conf;
        $d = (object) array();
        if (@$args["docids"] || $this->export_docids)
            $d->docid = $docid;
        $dresult = $Conf->document_result($prow, $dtype, $docid);
        if (($drow = $Conf->document_row($dresult, $dtype))) {
            if ($drow->mimetype)
                $d->mimetype = $drow->mimetype;
            if ($drow->sha1 !== null && $drow->sha1 !== "")
                $d->sha1 = bin2hex($drow->sha1);
            if ($drow->timestamp)
                $d->timestamp = (int) $drow->timestamp;
            if (@$drow->filename)
                $d->filename = $drow->filename;
            if (@$drow->infoJson
                && ($meta = json_decode($drow->infoJson)))
                $d->metadata = $meta;
            if ($this->export_content
                && DocumentHelper::load($drow->docclass, $drow))
                $d->content_base64 = base64_encode($drow->content);
        }
        foreach ($this->document_callbacks as $cb)
            call_user_func($cb, $d, $prow, $dtype, $drow);
        Dbl::free($dresult);
        return count(get_object_vars($d)) ? $d : null;
    }

    function row_to_json($prow, $args = array()) {
        global $Conf;
        if (!$prow || ($this->view_contact && !$this->view_contact->can_view_paper($prow)))
            return null;

        $pj = (object) array();
        $pj->id = (int) $prow->paperId;
        $pj->title = $prow->title;

        if ($prow->timeWithdrawn > 0) {
            $pj->status = "withdrawn";
            $pj->withdrawn = true;
            $pj->withdrawn_at = (int) $prow->timeWithdrawn;
            if (@$prow->withdrawReason)
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
        if ($prow->timestamp > 0)
            $pj->updated_at = (int) $prow->timestamp;

        $can_view_authors = !$this->view_contact
            || $this->view_contact->can_view_authors($prow, $this->forceShow);
        if ($can_view_authors) {
            $conflicts = array();
            foreach ($prow->conflicts(true) as $conf)
                $conflicts[strtolower($conf->email)] = $conf;

            $pj->authors = array();
            cleanAuthor($prow);
            foreach ($prow->authorTable as $au) {
                $aux = (object) array();
                if ($au[2])
                    $aux->email = $au[2];
                if ($au[0])
                    $aux->first = $au[0];
                if ($au[1])
                    $aux->last = $au[1];
                if ($au[3])
                    $aux->affiliation = $au[3];
                if (@$aux->email
                    && ($conf = @$conflicts[strtolower($aux->email)])
                    && $conf->conflictType >= CONFLICT_AUTHOR)
                    $aux->contact = true;
                $pj->authors[] = $aux;
            }

            $pj->contacts = (object) array();
            foreach ($conflicts as $conf)
                if ($conf->conflictType >= CONFLICT_CONTACTAUTHOR) {
                    $e = $conf->email;
                    $pj->contacts->$e = true;
                }
        }

        if ($Conf->submission_blindness() == Conference::BLIND_OPTIONAL)
            $pj->nonblind = !(isset($pj->paperBlind) ? $prow->paperBlind : $prow->blind);

        $pj->abstract = $prow->abstract;

        $topics = array();
        foreach (array_intersect_key($Conf->topic_map(), array_flip($prow->topics())) as $tid => $tname)
            $topics[$this->export_ids ? $tid : $tname] = true;
        if (count($topics))
            $pj->topics = (object) $topics;

        if ($prow->paperStorageId > 1
            && (!$this->view_contact || $this->view_contact->can_view_pdf($prow))
            && ($doc = $this->document_to_json($prow, DTYPE_SUBMISSION,
                                               (int) $prow->paperStorageId,
                                               $args)))
            $pj->submission = $doc;

        if ($prow->finalPaperStorageId > 1
            && (!$this->view_contact || $this->view_contact->can_view_pdf($prow))
            && ($doc = $this->document_to_json($prow, DTYPE_FINAL,
                                               (int) $prow->finalPaperStorageId,
                                               $args)))
            $pj->final = $doc;
        if ($prow->timeFinalSubmitted > 0) {
            $pj->final_submitted = true;
            $pj->final_submitted_at = (int) $prow->timeFinalSubmitted;
        }

        if (count($prow->options())) {
            $options = array();
            foreach ($prow->options() as $oa) {
                $o = $oa->option;
                if ($this->view_contact
                    && !$this->view_contact->can_view_paper_option($prow, $o, $this->forceShow))
                    continue;
                $okey = $this->export_ids ? $o->id : $o->abbr;
                if ($o->type == "checkbox" && $oa->value)
                    $options[$okey] = true;
                else if ($o->has_selector()
                         && @($otext = $o->selector[$oa->value]))
                    $options[$okey] = $otext;
                else if ($o->type == "numeric" && $oa->value != ""
                         && $oa->value != "0")
                    $options[$okey] = $oa->value;
                else if ($o->type == "text" && $oa->data != "")
                    $options[$okey] = $oa->data;
                else if ($o->type == "attachments") {
                    $attachments = array();
                    foreach ($oa->values as $docid)
                        if ($docid && ($doc = $this->document_to_json($prow, $o->id, $docid, $args)))
                            $attachments[] = $doc;
                    if (count($attachments))
                        $options[$okey] = $attachments;
                } else if ($o->is_document() && $oa->value
                           && ($doc = $this->document_to_json($prow, $o->id, $oa->value, $args)))
                    $options[$okey] = $doc;
            }
            if (count($options))
                $pj->options = (object) $options;
        }

        if ($can_view_authors) {
            $pcconflicts = array();
            foreach ($prow->pc_conflicts(true) as $id => $conf) {
                if (@($ctname = Conflict::$type_names[$conf->conflictType]))
                    $pcconflicts[$conf->email] = $ctname;
            }
            if (count($pcconflicts))
                $pj->pc_conflicts = (object) $pcconflicts;
        }

        if ($prow->collaborators && $can_view_authors)
            $pj->collaborators = $prow->collaborators;

        return $pj;
    }

    public function set_error_html($field, $html) {
        if ($field)
            $this->errf[$field] = true;
        $this->errmsg[] = $html;
        if (!$field
            || !$this->allow_error
            || array_search($field, $this->allow_error) === false)
            ++$this->nerrors;
    }

    public function set_warning_html($field, $html) {
        if ($field)
            $this->errf[$field] = true;
        $this->errmsg[] = $html;
    }

    private function upload_document($docj, $paperid, $dtype) {
        global $Conf;

        // look for an existing document with same sha1;
        // check existing docid's sha1
        $docid = @$docj->docid;
        if ($docid) {
            $oldj = $this->document_to_json($paperid, $dtype, $docid, array("docids" => true));
            if (@$docj->sha1 && @$oldj->sha1 !== $docj->sha1)
                $docid = null;
        } else if (!$docid && $paperid && @$docj->sha1) {
            $result = Dbl::qe("select paperStorageId from PaperStorage where paperId=? and documentType=? and sha1=?", $paperid, $dtype, $docj->sha1);
            if (($row = edb_row($result)))
                $docid = $row[0];
        }

        // check filter
        if (!$docid && @$docj->filter && is_int($docj->filter)) {
            if (is_int(@$docj->original_id))
                $result = Dbl::qe("select paperStorageId, timestamp, sha1 from PaperStorage where paperStorageId=?", $docj->original_id);
            else if (is_string(@$docj->original_sha1))
                $result = Dbl::qe("select paperStorageId, timestamp, sha1 from PaperStorage where paperId=? and sha1=?", $paperid, $docj->original_sha1);
            else if ($dtype == DTYPE_SUBMISSION || $dtype == DTYPE_FINAL)
                $result = Dbl::qe("select PaperStorage.paperStorageId, PaperStorage.timestamp, PaperStorage.sha1 from PaperStorage join Paper on (Paper.paperId=PaperStorage.paperId and Paper." . ($dtype == DTYPE_SUBMISSION ? "paperStorageId" : "finalPaperStorageId") . "=PaperStorage.paperStorageId) where Paper.paperId=?", $paperid);
            else
                $result = null;
            if (($row = edb_orow($result))) {
                $docj->original_id = (int) $row->paperStorageId;
                $docj->original_timestamp = (int) $row->timestamp;
                $docj->original_sha1 = $row->sha1;
                if (@$docj->preserve_timestamp)
                    $docj->timestamp = (int) $docj->original_timestamp;
            } else
                unset($docj->original_id);
        }

        // if no sha1 match, upload
        $docclass = new HotCRPDocument($dtype);
        $upload = null;
        if (!$docid && DocumentHelper::load($docclass, $docj))
            $upload = DocumentHelper::upload(new HotCRPDocument($dtype), $docj,
                                             (object) array("paperId" => $paperid));
        if ($docid)
            $docj->docid = $docid;
        else if ($upload && @$upload->paperStorageId > 1) {
            foreach (array("size", "sha1", "mimetype", "timestamp") as $k)
                $docj->$k = $upload->$k;
            $this->uploaded_documents[] = $docj->docid = $upload->paperStorageId;
        } else {
            $opt = PaperOption::find_document($dtype);
            $docj->docid = 1;
            $this->set_error_html($opt->abbr, htmlspecialchars($opt->name) . ": "
                                  . ($upload ? $upload->error_html : "empty document"));
        }
    }

    private function normalize_string($pj, $k, $simplify) {
        if (@$pj->$k !== null)
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
                else if (@$topic_map[$k])
                    $pj->topics->$k = true;
                else if (($x = array_search($k, $topic_map, true)) !== false)
                    $pj->topics->$x = true;
                else
                    $pj->bad_topics[] = $k;
        } else if ($topics)
            $this->set_error_html("topics", "Format error [topics]");
    }

    private function normalize_options($pj) {
        $options = @$pj->options;
        $pj->options = (object) array();
        $option_list = PaperOption::option_list();

        // canonicalize option values to use IDs, not abbreviations
        foreach ($options as $id => $oa) {
            $omatches = PaperOption::search($id);
            if (count($omatches) == 1) {
                $id = current($omatches)->id;
                $pj->options->$id = $oa;
            } else
                $pj->bad_options[$id] = true;
        }

        // check values
        foreach ($pj->options as $id => $oa) {
            $o = $option_list[$id];
            if ($o->type == "checkbox") {
                if (!is_bool($oa))
                    $this->set_error_html("opt$id", htmlspecialchars($o->name) . ": Option should be “true” or “false”.");
            } else if ($o->has_selector()) {
                if (is_int($oa) && isset($o->selector[$oa]))
                    /* OK */;
                else if (is_string($oa)
                         && ($ov = array_search($oa, $o->selector)))
                    $pj->options->$id = $ov;
                else
                    $this->set_error_html("opt$id", htmlspecialchars($o->name) . ": Option doesn’t match any of the selectors.");
            } else if ($o->type == "numeric") {
                if (!is_int($oa))
                    $this->set_error_html("opt$id", htmlspecialchars($o->name) . ": Option should be an integer.");
            } else if ($o->type == "text") {
                if (!is_string($oa))
                    $this->set_error_html("opt$id", htmlspecialchars($o->name) . ": Option should be a text string.");
            } else if ($o->has_document()) {
                if ($o->is_document() && !is_object($oa))
                    $oa = null;
                $oa = $oa && !is_array($oa) ? array($oa) : $oa;
                if (!is_array($oa))
                    $this->set_error_html("opt$id", htmlspecialchars($o->name) . ": Option format error.");
                else
                    foreach ($oa as $ov)
                        if (!is_object($ov))
                            $this->set_error_html("opt$id", htmlspecialchars($o->name) . ": Option format error.");
            } else
                unset($pj->options->$id);
        }
    }

    private function normalize_pc_conflicts($pj) {
        $conflicts = @$pj->pc_conflicts;
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

    function normalize($pj, $old_pj) {
        // Errors prevent saving
        global $Conf, $Now;

        // Title, abstract
        $this->normalize_string($pj, "title", true);
        $this->normalize_string($pj, "abstract", false);
        $this->normalize_string($pj, "collaborators", false);

        // Authors
        $au_by_email = array();
        $pj->bad_authors = array();
        if (@$pj->authors !== null) {
            if (!is_array($pj->authors))
                $this->set_error_html("author", "Format error [authors]");
            $curau = is_array($pj->authors) ? $pj->authors : array();
            $pj->authors = array();
            foreach ($curau as $k => $au)
                if (is_string($au) || is_object($au)) {
                    $aux = Text::analyze_name($au);
                    $aux->first = simplify_whitespace($aux->firstName);
                    $aux->last = simplify_whitespace($aux->lastName);
                    $aux->email = simplify_whitespace($aux->email);
                    $aux->affiliation = simplify_whitespace($aux->affiliation);
                    if ($aux->first !== "" || $aux->last !== ""
                        || $aux->email !== "" || $aux->affiliation !== "")
                        $pj->authors[] = $aux;
                    else
                        $pj->bad_authors[] = $aux;
                    $aux->index = count($pj->authors) + count($pj->bad_authors);
                    if (is_object($au) && @$au->contact)
                        $aux->contact = true;
                    if (@$aux->email)
                        $au_by_email[strtolower($aux->email)] = $aux;
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

        // Topics
        $pj->bad_topics = array();
        if (@$pj->topics !== null)
            $this->normalize_topics($pj);

        // Options
        $pj->bad_options = array();
        if (@$pj->options && is_object($pj->options))
            $this->normalize_options($pj);
        else if (@$pj->options === false)
            $pj->options = (object) array();
        else if (@$pj->options !== null)
            $this->set_error_html("options", "Format error [options]");

        // PC conflicts
        $pj->bad_pc_conflicts = (object) array();
        if (@$pj->pc_conflicts && (is_object($pj->pc_conflicts) || is_array($pj->pc_conflicts)))
            $this->normalize_pc_conflicts($pj);
        else if (@$pj->pc_conflicts === false)
            $pj->pc_conflicts = (object) array();
        else if (@$pj->pc_conflicts !== null)
            $this->set_error_html("pc_conflicts", "Format error [PC conflicts]");

        // Old contacts (to avoid validate_email errors on unchanged contacts)
        $old_contacts = array();
        if ($old_pj && @$old_pj->authors)
            foreach ($old_pj->authors as $au)
                if (@$au->contact)
                    $old_contacts[strtolower($au->email)] = true;
        if ($old_pj && @$old_pj->contacts)
            foreach ((array) $old_pj->contacts as $e => $ctype)
                $old_contacts[strtolower($e)] = true;

        // Contacts
        $contacts = @$pj->contacts;
        if ($contacts === null)
            $contacts = $old_pj ? @$old_pj->contacts : null;
        if ($contacts === null)
            $contacts = array();
        else if (is_object($contacts) || is_array($contacts))
            $contacts = (array) $contacts;
        else {
            $this->set_error_html("contacts", "Format error [contacts]");
            $contacts = array();
        }
        $pj->contacts = array();
        $pj->bad_contacts = array();
        // verify emails on authors marked as contacts
        foreach (@$pj->authors ? : array() as $au)
            if (@$au->contact
                && (!@$au->email
                    || (!@$old_contacts[strtolower($au->email)]
                        && !validate_email($au->email))))
                $pj->bad_contacts[] = $au;
        // verify emails on explicitly named contacts
        foreach ($contacts as $k => $v) {
            if (!$v)
                continue;
            if ($v === true)
                $v = (object) array();
            else if (is_string($v) && is_int($k)) {
                $v = trim($v);
                if (validate_email($v))
                    $v = (object) array("email" => $v);
                else
                    $v = Text::analyze_name($v);
            }
            if (is_object($v) && !@$v->email && is_string($k))
                $v->email = $k;
            if (is_object($v) && @$v->email) {
                $lemail = strtolower($v->email);
                if (validate_email($lemail) || @$old_contacts[$lemail])
                    $pj->contacts[$lemail] = (object) array_merge((array) @$au_by_email[$lemail], (array) $v);
                else
                    $pj->bad_contacts[] = $v;
            } else
                $this->set_error_html("contacts", "Format error [contacts]");
        }
        $pj->contacts = (object) $pj->contacts;
    }

    private function check_invariants($pj, $old_pj) {
        // Errors don't prevent saving
        if (@$pj->title === ""
            || (@$pj->title === null && (!$old_pj || !$old_pj->title)))
            $this->set_error_html("title", "Each paper must have a title.");
        if (@$pj->abstract === ""
            || (@$pj->abstract === null && (!$old_pj || !$old_pj->abstract)))
            $this->set_error_html("abstract", "Each paper must have an abstract.");
        if ((is_array(@$pj->authors) && !count($pj->authors))
            || (@$pj->authors === null && (!$old_pj || !count($old_pj->authors))))
            $this->set_error_html("author", "Each paper must have at least one author.");
        if (count($pj->bad_authors))
            $this->set_error_html("author", "Some authors ignored.");
        foreach ($pj->bad_contacts as $reg)
            if (!isset($reg->email))
                $this->set_error_html("contacts", "Contact " . Text::user_html($reg) . " has no associated email.");
            else
                $this->set_error_html("contacts", "Contact email " . htmlspecialchars($reg->email) . " is invalid.");
        if (count($pj->bad_topics))
            $this->set_warning_html("topics", "Unknown topics ignored (" . htmlspecialchars(commajoin($pj->bad_topics)) . ").");
        if (count($pj->bad_options))
            $this->set_warning_html("options", "Unknown options ignored (" . htmlspecialchars(commajoin(array_keys($pj->bad_options))) . ").");
    }

    static function author_information($pj) {
        $x = "";
        foreach (($pj && @$pj->authors ? $pj->authors : array()) as $au)
            $x .= (@$au->first ? $au->first : (@$au->firstName ? $au->firstName : "")) . "\t"
                . (@$au->last ? $au->last : (@$au->lastName ? $au->lastName : "")) . "\t"
                . (@$au->email ? $au->email : "") . "\t"
                . (@$au->affiliation ? $au->affiliation : "") . "\n";
        return $x;
    }

    static function topics_sql($pj) {
        $x = array();
        foreach (($pj ? (array) @$pj->topics : array()) as $id => $v)
            $x[] = "($id,$pj->id)";
        sort($x);
        return join(",", $x);
    }

    static function options_sql($pj) {
        $x = array();
        $option_list = PaperOption::option_list();
        foreach ((array) ($pj ? @$pj->options : null) as $id => $oa) {
            $o = $option_list[$id];
            if ($o->type == "text")
                $x[] = "($pj->id,$o->id,1,'" . sqlq($oa) . "')";
            else if ($o->is_document())
                $x[] = "($pj->id,$o->id,$oa->docid,null)";
            else if ($o->type == "attachments") {
                $oa = is_array($oa) ? $oa : array($oa);
                foreach ($oa as $ord => $ov)
                    $x[] = "($pj->id,$o->id,$ov->docid,'" . ($ord + 1) . "')";
            } else if ($o->type != "checkbox" || $oa)
                $x[] = "($pj->id,$o->id,$oa,null)";
        }
        sort($x);
        return join(",", $x);
    }

    static private function contacts_array($pj) {
        $contacts = array();
        foreach (@$pj->authors ? : array() as $au)
            if (@$au->email && validate_email($au->email)) {
                $c = clone $au;
                $contacts[strtolower($c->email)] = $c;
            }
        foreach ((array) (@$pj->contacts ? : array()) as $lemail => $v) {
            $c = (object) array_merge((array) @$contacts[$lemail], (array) $v);
            $c->contact = true;
            $contacts[$lemail] = $c;
        }
        return $contacts;
    }

    static function conflicts_array($pj, $old_pj) {
        $x = array();

        if ($pj && @$pj->pc_conflicts !== null)
            $c = $pj->pc_conflicts;
        else
            $c = ($old_pj ? @$old_pj->pc_conflicts : null) ? : array();
        foreach ((array) $c as $email => $type)
            $x[strtolower($email)] = $type;

        if ($pj && @$pj->authors !== null)
            $c = $pj->authors;
        else
            $c = $old_pj ? $old_pj->authors : array();
        foreach ($c as $au)
            if (@$au->email)
                $x[strtolower($au->email)] = CONFLICT_AUTHOR;

        if ($pj && @$pj->contacts !== null)
            $c = $pj->contacts;
        else
            $c = $old_pj ? $old_pj->contacts : array();
        foreach ((array) $c as $email => $crap) {
            $email = strtolower($email);
            if (!@$x[$email] || $x[$email] < CONFLICT_CONTACTAUTHOR)
                $x[$email] = CONFLICT_CONTACTAUTHOR;
        }

        if ($old_pj && @$old_pj->pc_conflicts)
            foreach ($old_pj->pc_conflicts as $email => $type)
                if ($type == CONFLICT_CHAIRMARK) {
                    $email = strtolower($email);
                    if (@+$x[$email] < CONFLICT_CHAIRMARK)
                        $x[$email] = CONFLICT_CHAIRMARK;
                }

        ksort($x);
        return $x;
    }

    function save($pj, $old_pj = null) {
        global $Conf, $Now;

        if (@$pj->id && !$old_pj)
            $old_pj = $this->load($pj->id, array("docids" => true));
        if (!@$pj->id)
            $pj->id = $old_pj ? $old_pj->id : 0;
        if ($pj && $old_pj && $pj->id != $old_pj->id) {
            $this->set_error_html("id", "Saving paper with different ID");
            return false;
        }

        $this->normalize($pj, $old_pj);
        if ($old_pj)
            $this->normalize($old_pj, null);
        if ($this->nerrors)
            return false;
        $this->check_invariants($pj, $old_pj);

        // store all documents
        if (@$pj->submission)
            $this->upload_document($pj->submission, $pj->id, DTYPE_SUBMISSION);
        if (@$pj->final)
            $this->upload_document($pj->final, $pj->id, DTYPE_FINAL);
        if (@$pj->options) {
            $option_list = PaperOption::option_list();
            foreach ($pj->options as $id => $oa) {
                $o = $option_list[$id];
                if ($o->type == "attachments" || $o->is_document()) {
                    $oa = is_array($oa) ? $oa : array($oa);
                    foreach ($oa as $x)
                        $this->upload_document($x, $pj->id, $id);
                }
            }
        }

        // create contacts
        foreach (self::contacts_array($pj) as $c) {
            $c->only_if_contactdb = !@$c->contact;
            $c->disabled = !!@$this->disable_users;
            if (!Contact::find_by_email($c->email, $c, !$this->no_email)
                && @$c->contact)
                $this->set_error_html("contacts", "Could not create an account for contact " . Text::user_html($c) . ".");
        }

        // catch errors
        if ($this->nerrors)
            return false;

        // update Paper table
        $q = array();
        foreach (array("title", "abstract", "collaborators") as $k)
            if (!$old_pj || (@$pj->$k !== null && @$old_pj->$k != $pj->$k))
                $q[] = "$k='" . sqlq((string) @$pj->$k) . "'";

        if (!$old_pj || @$pj->authors !== null) {
            $autext = self::author_information($pj);
            $old_autext = self::author_information($old_pj);
            if ($autext != $old_autext || !$old_pj)
                $q[] = "authorInformation='" . sqlq($autext) . "'";
        }

        if ($Conf->submission_blindness() == Conference::BLIND_OPTIONAL
            && (!$old_pj || (@$pj->nonblind !== null
                             && !$pj->nonblind != !$old_pj->nonblind)))
            $q[] = "blind=" . (@$pj->nonblind ? 0 : 1);

        if (!$old_pj || @$pj->submission !== null) {
            $new_id = @$pj->submission ? $pj->submission->docid : 1;
            $old_id = $old_pj && @$old_pj->submission ? $old_pj->submission->docid : 1;
            if (!$old_pj || $new_id != $old_id)
                $q[] = "paperStorageId=$new_id";
        }

        if (!$old_pj || @$pj->final !== null) {
            $new_id = @$pj->final ? $pj->final->docid : 0;
            $old_id = $old_pj && @$old_pj->final ? $old_pj->final->docid : 0;
            if (!$old_pj || $new_id != $old_id)
                $q[] = "finalPaperStorageId=$new_id";
        }

        if (@$pj->withdrawn !== null
            || @$pj->submitted !== null
            || @$pj->draft !== null) {
            if (@$pj->submitted !== null)
                $submitted = $pj->submitted;
            else if (@$pj->draft !== null)
                $submitted = !$pj->draft;
            else if ($old_pj)
                $submitted = @$old_pj->submitted_at > 0;
            else
                $submitted = false;
            if (@$pj->withdrawn) {
                if (!$old_pj || !@$old_pj->withdrawn) {
                    $q[] = "timeWithdrawn=" . (@$pj->withdrawn_at ? : $Now);
                    $q[] = "timeSubmitted=" . ($submitted ? -100 : 0);
                } else if ((@$old_pj->submitted_at > 0) !== $submitted)
                    $q[] = "timeSubmitted=" . ($submitted ? -100 : 0);
            } else if ($submitted) {
                if (!$old_pj || !@$old_pj->submitted)
                    $q[] = "timeSubmitted=" . (@$pj->submitted_at ? : $Now);
                if ($old_pj && @$old_pj->withdrawn)
                    $q[] = "timeWithdrawn=0";
            } else if ($old_pj && (@$old_pj->withdrawn || @$old_pj->submitted)) {
                $q[] = "timeSubmitted=0";
                $q[] = "timeWithdrawn=0";
            }
        }

        if (@$pj->final_submitted !== null) {
            if ($pj->final_submitted)
                $time = @$pj->final_submitted_at ? : $Now;
            else
                $time = 0;
            if (!$old_pj || @$old_pj->final_submitted_at != $time)
                $q[] = "timeFinalSubmitted=$time";
        }

        if (count($q)) {
            if ($Conf->submission_blindness() == Conference::BLIND_NEVER)
                $q[] = "blind=0";
            else if ($Conf->submission_blindness() != Conference::BLIND_OPTIONAL)
                $q[] = "blind=1";

            $joindoc = $old_joindoc = null;
            if (@$pj->final) {
                $joindoc = $pj->final;
                $old_joindoc = $old_pj ? @$old_pj->final : null;
            } else if (@$pj->submission) {
                $joindoc = $pj->submission;
                $old_joindoc = $old_pj ? @$old_pj->submission : null;
            }
            if ($joindoc
                && (!$old_joindoc || $old_joindoc->docid != $joindoc->docid)
                && @$joindoc->size && @$joindoc->timestamp) {
                $q[] = "size=" . $joindoc->size;
                $q[] = "mimetype='" . sqlq($joindoc->mimetype) . "'";
                $q[] = "sha1='" . sqlq($joindoc->sha1) . "'";
                $q[] = "timestamp=" . $joindoc->timestamp;
            } else if (!$joindoc)
                $q[] = "size=0,mimetype='',sha1='',timestamp=0";

            if ($pj->id) {
                $result = Dbl::qe_raw("update Paper set " . join(",", $q) . " where paperId=$pj->id");
                if ($result
                    && $result->affected_rows === 0
                    && edb_nrows($Conf->qe("select paperId from Paper where paperId=$pj->id")) === 0)
                    $result = $Conf->qe("insert into Paper set paperId=$pj->id, " . join(",", $q));
            } else {
                $result = Dbl::qe_raw("insert into Paper set " . join(",", $q));
                if (!$result
                    || !($pj->id = $result->insert_id))
                    return $this->set_error_html(false, "Could not create paper.");
                if (count($this->uploaded_documents))
                    $Conf->qe("update PaperStorage set paperId=$pj->id where paperStorageId in (" . join(",", $this->uploaded_documents) . ")");
            }

            // maybe update `papersub` settings
            $is_submitted = !@$pj->withdrawn && @$pj->submitted;
            $was_submitted = $old_pj && !@$old_pj->withdrawn && @$old_pj->submitted;
            if ($is_submitted != $was_submitted)
                $Conf->updatePapersubSetting($is_submitted);
        }

        // update PaperTopics
        if (@$pj->topics) {
            $topics = self::topics_sql($pj);
            $old_topics = self::topics_sql($old_pj);
            if ($topics != $old_topics) {
                $result = $Conf->qe("delete from PaperTopic where paperId=$pj->id");
                if ($topics)
                    $result = $Conf->qe("insert into PaperTopic (topicId,paperId) values $topics");
            }
        }

        // update PaperOption
        if (@$pj->options) {
            $options = self::options_sql($pj);
            $old_options = self::options_sql($old_pj);
            if ($options != $old_options) {
                $result = $Conf->qe("delete from PaperOption where paperId=$pj->id");
                if ($options)
                    $result = $Conf->qe("insert into PaperOption (paperId,optionId,value,data) values $options");
            }
        }

        // update PaperConflict
        $conflict = self::conflicts_array($pj, $old_pj);
        $old_conflict = self::conflicts_array($old_pj, null);
        if (join(",", array_keys($conflict)) != join(",", array_keys($old_conflict))
            || join(",", array_values($conflict)) != join(",", array_values($old_conflict))) {
            $q = array();
            foreach ($conflict as $email => $type)
                $q[] = "'" . sqlq($email) . "'";
            $ins = array();
            if (count($q)) {
                $result = $Conf->qe("select contactId, email from ContactInfo where email in (" . join(",", $q) . ")");
                while (($row = edb_row($result)))
                    $ins[] = "($pj->id,$row[0]," . $conflict[strtolower($row[1])] . ")";
            }
            $result = $Conf->qe("delete from PaperConflict where paperId=$pj->id");
            if (count($ins))
                $result = $Conf->qe("insert into PaperConflict (paperId,contactId,conflictType) values " . join(",", $ins));
        }

        return $pj->id;
    }

    function error_html() {
        return $this->errmsg;
    }

    function error_fields() {
        return $this->errf;
    }

}
