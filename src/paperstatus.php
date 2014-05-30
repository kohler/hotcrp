<?php
// paperactions.php -- HotCRP helpers for common paper actions
// HotCRP is Copyright (c) 2008-2014 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperStatus {

    private $uploaded_documents;
    private $errf;
    private $errmsg;
    public $nerrors;
    private $no_email = false;
    private $allow_error = array();
    private $contact = null;
    private $forceShow = null;
    private $export_ids = false;
    private $export_content = false;

    function __construct($options = array()) {
        foreach (array("no_email", "allow_error", "contact",
                       "forceShow", "export_ids", "export_content") as $k)
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

    function load($pid) {
        global $Conf;
        $prow = $Conf->paperRow(array("paperId" => $pid,
                                      "topics" => true,
                                      "options" => true), $this->contact);
        return $prow ? $this->row_to_json($prow) : null;
    }

    function document_to_json($prow, $dtype, $docid) {
        global $Conf;
        $d = (object) array();
        if ($this->export_ids)
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
        return count(get_object_vars($d)) ? $d : null;
    }

    function row_to_json($prow) {
        global $Conf;
        if (!$prow || ($this->contact && !$this->contact->canViewPaper($prow)))
            return null;

        $pj = (object) array();
        $pj->id = $prow->paperId;
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
        } else
            $pj->status = "inprogress";
        if ($prow->timeSubmitted > 0)
            $pj->submitted_at = (int) $prow->timeSubmitted;
        else if ($prow->timeSubmitted == -100 && $prow->timeWithdrawn > 0)
            $pj->submitted_at = 1000000000;
        if ($prow->timestamp > 0)
            $pj->updated_at = (int) $prow->timestamp;

        $can_view_authors = !$this->contact
            || $this->contact->canViewAuthors($prow, $this->forceShow);
        if ($can_view_authors) {
            $contacts = array();
            foreach ($prow->contacts(true) as $id => $conf)
                $contacts[$conf->email] = true;

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
                if (@$aux->email && @$contacts[$aux->email])
                    $aux->contact = true;
                $pj->authors[] = $aux;
            }
            $pj->contacts = (object) $contacts;
        }

        if ((isset($pj->paperBlind) ? !$prow->paperBlind : $prow->blind)
            && $Conf->subBlindOptional())
            $pj->nonblind = true;

        $pj->abstract = $prow->abstract;

        $topics = array();
        foreach (array_intersect_key($Conf->topic_map(), array_flip($prow->topics())) as $tid => $tname)
            $topics[$this->export_ids ? $tid : $tname] = true;
        if (count($topics))
            $pj->topics = (object) $topics;

        if ($prow->paperStorageId > 1
            && (!$this->contact || $this->contact->canDownloadPaper($prow))
            && ($doc = $this->document_to_json($prow, DTYPE_SUBMISSION,
                                               (int) $prow->paperStorageId)))
            $pj->submission = $doc;

        if ($prow->finalPaperStorageId > 1
            && (!$this->contact || $this->contact->canDownloadPaper($prow))
            && ($doc = $this->document_to_json($prow, DTYPE_FINAL,
                                               (int) $prow->finalPaperStorageId)))
            $pj->final = $doc;

        if (count($prow->options())) {
            $options = array();
            foreach ($prow->options() as $oa) {
                $o = $oa->option;
                if ($this->contact
                    && !$this->contact->canViewPaperOption($prow, $o, $this->forceShow))
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
                        if ($docid && ($doc = $this->document_to_json($prow, $o->id, $docid)))
                            $attachments[] = $doc;
                    if (count($attachments))
                        $options[$okey] = $attachments;
                } else if ($o->is_document() && $oa->value
                           && ($doc = $this->document_to_json($prow, $o->id, $oa->value)))
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

    private function set_error($field, $html) {
        if ($field)
            $this->errf[$field] = true;
        $this->errmsg[] = $html;
        if (!$field
	    || !$this->allow_error
            || array_search($field, $this->allow_error) === false)
            ++$this->nerrors;
    }

    private function set_warning($field, $html) {
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
            $oldj = $this->document_to_json($paperid, $dtype, $docid);
            if (@$docj->sha1 && @$oldj->sha1 !== $docj->sha1)
                $docid = null;
        } else if (!$docid && $paperid && @$docj->sha1) {
            $result = $Conf->qe("select paperStorageId from PaperStorage where paperId=$paperid and documentType=$dtype and sha1='" . sqlq($docj->sha1) . "'");
            if (($row = edb_row($result)))
                $docid = $row[0];
        }

        // if no sha1 match, upload
        $docclass = new HotCRPDocument($dtype);
        if (!$docid && !@$docj->content && !@$docj->content_base64)
            DocumentHelper::load($docclass, $docj);
        $upload = null;
        if (!$docid && (@$docj->content || @$docj->content_base64 || @$docj->filestore))
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
            $this->set_error($opt->abbr, htmlspecialchars($opt->name) . ": "
                             . ($upload ? $upload->error_html : "empty document"));
        }
    }

    function normalize($pj, $old_pj) {
        global $Conf, $Now;
        foreach (array("topics", "options", "contacts") as $k)
            if (!is_object(@$pj->$k))
                $pj->$k = (object) array();

        // Title, abstract
        foreach (array("title", "abstract", "collaborators") as $k) {
            if (@$pj->$k && !is_string(@$pj->$k))
                $this->set_error($k, "Format error [$k]");
            if (!is_string(@$pj->$k))
                $pj->$k = "";
        }
        $pj->title = simplify_whitespace($pj->title);
        $pj->abstract = trim($pj->abstract);
        $pj->collaborators = trim($pj->collaborators);

        // Authors
        if (@$pj->authors && !is_array($pj->authors))
            $this->set_error("author", "Format error [authors]");
        $curau = is_array(@$pj->authors) ? $pj->authors : array();
        $pj->authors = $pj->bad_authors = array();
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
            } else
                $this->set_error("author", "Format error [authors]");

        // Status
        if (@$pj->withdrawn && !isset($pj->withdrawn_at))
            $pj->withdrawn_at = $Now;
        if (@$pj->submitted && !isset($pj->submitted_at))
            $pj->submitted_at = $Now;
        if (@$pj->final_submitted && !isset($pj->final_submitted_at))
            $pj->final_submitted_at = $Now;
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
        $topics = @$pj->topics;
        $pj->topics = (object) array();
        $pj->bad_topics = array();
        if ($topics && is_array($topics)) {
            $new_topics = (object) array();
            foreach ($topics as $v)
                if ($v && (is_int($v) || is_string($v)))
                    $new_topics->$v = true;
                else if ($v)
                    $this->set_error("topics", "Format error [topics]");
            $topics = $new_topics;
        }
        if ($topics && is_object($topics)) {
            $topic_map = $Conf->topic_map();
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
            $this->set_error("topics", "Format error [topics]");

        // Options
        $options = @$pj->options;
        $pj->options = (object) array();
        $pj->bad_options = array();
        if ($options && is_object($options)) {
            $option_list = PaperOption::option_list();

            // canonicalize option values to use IDs, not abbreviations
            foreach ($options as $id => $oa)
                if (@($o = PaperOption::find_abbr($id))) {
                    $id = $o->id;
                    $pj->options->$id = $oa;
                } else
                    $pj->bad_options[$id] = true;

            // check values
            foreach ($pj->options as $id => $oa) {
                $o = $option_list[$id];
                if ($o->type == "checkbox") {
                    if (!is_bool($oa))
                        $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option should be “true” or “false”.");
                } else if ($o->has_selector()) {
                    if (is_int($oa) && isset($o->selectors[$oa]))
                        /* OK */;
                    else if (is_string($oa)
                             && ($ov = array_search($oa, $o->selectors)))
                        $pj->options->$id = $ov;
                    else
                        $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option doesn’t match any of the selectors.");
                } else if ($o->type == "numeric") {
                    if (!is_int($oa))
                        $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option should be an integer.");
                } else if ($o->type == "text") {
                    if (!is_string($oa))
                        $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option should be a text string.");
                } else if ($o->type == "attachments" || $o->is_document()) {
                    if ($o->is_document() && !is_object($oa))
                        $oa = null;
                    $oa = $oa && !is_array($oa) ? array($oa) : $oa;
                    if (!is_array($oa))
                        $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option format error.");
                    else
                        foreach ($oa as $ov)
                            if (!is_object($ov))
                                $this->set_error("opt$id", htmlspecialchars($o->name) . ": Option format error.");
                } else
                    unset($pj->options->$id);
            }
        } else if ($options)
            $this->set_error("options", "Format error [options]");

        // PC conflicts
        $conflicts = @$pj->pc_conflicts;
        $pj->pc_conflicts = (object) array();
        $pj->bad_pc_conflicts = (object) array();
        if ($conflicts && (is_object($conflicts) || is_array($conflicts))) {
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
                    $this->set_error("pc_conflicts", "Format error [PC conflicts]");
                else {
                    if (is_int($ct) && isset(Conflict::$type_names[$ct]))
                        $ctn = $ct;
                    else if (($ctn = array_search($ct, Conflict::$type_names)) !== false)
                        /* OK */;
                    else {
                        $pj->bad_pc_conflicts->$email = $ct;
                        $ctn = array_search("other", Conflict::$type_names);
                    }
                    $pj->pc_conflicts->$email = $ctn;
                }
            }
        } else if ($conflicts)
            $this->set_error("pc_conflicts", "Format error [PC conflicts]");

        // Contacts
        $contacts = @$pj->contacts;
        $old_contacts = $old_pj ? $old_pj->contacts : (object) array();
        $pj->contacts = array();
        $pj->bad_contacts = array();
        foreach ($pj->authors as $au)
            if (@$au->contact) {
                if (@$au->email && validateEmail($au->email))
                    $pj->contacts[strtolower($au->email)] = $au;
                else
                    $pj->bad_contacts[] = $au;
            }
        if ($contacts && (is_array($contacts) || is_object($contacts)))
            foreach ($contacts as $k => $v) {
                if (!$v)
                    continue;
                if ($v === true)
                    $v = (object) array();
                else if (is_string($v) && is_int($k)) {
                    $v = trim($v);
                    if (validateEmail($v))
                        $v = (object) array("email" => $v);
                    else
                        $v = Text::analyze_name($v);
                }
                if (is_object($v)) {
                    if (!@$v->email && is_string($k))
                        $v->email = $k;
                    $email = strtolower($v->email);
                    if (!validateEmail($email)
                        && !@$old_contacts->$email
                        && !Contact::id_by_email($email))
                        $pj->bad_contacts[] = $v;
                    else if (!@$pj->contacts[$email])
                        $pj->contacts[$email] = $v;
                    else
                        $pj->contacts[$email] = (object) array_merge((array) $pj->contacts[$email], (array) $v);
                } else
                    $this->set_error("contacts", "Format error [contacts]");
            }
        $pj->contacts = (object) $pj->contacts;
    }

    function check_invariants($pj) {
        global $Now;

        // Title, abstract, authors
        if ($pj->title == "")
            $this->set_error("title", "Each paper must have a title.");
        if ($pj->abstract == "")
            $this->set_error("abstract", "Each paper must have an abstract.");
        if (!count($pj->authors))
            $this->set_error("author", "Each paper must have at least one author.");
        if (count($pj->bad_authors))
            $this->set_error("author", "Some authors ignored.");
        foreach ($pj->bad_contacts as $reg)
            if (!isset($reg->email))
                $this->set_error("contacts", "Contact " . Text::user_html($reg) . " has no associated email.");
            else
                $this->set_error("contacts", "Contact email " . htmlspecialchars($reg->email) . " is invalid.");
        if (count($pj->bad_topics))
            $this->set_error("topics", "Unknown topics ignored (" . commajoin($pj->bad_topics) . ").");
        if (count($pj->bad_options))
            $this->set_error("options", "Unknown options ignored (" . commajoin(array_keys($pj->bad_options)) . ").");
    }

    static function author_information($pj) {
        $x = "";
        foreach (($pj ? $pj->authors : array()) as $au)
            $x .= (@$au->first ? $au->first : (@$au->firstName ? $au->firstName : "")) . "\t"
                . (@$au->last ? $au->last : (@$au->lastName ? $au->lastName : "")) . "\t"
                . (@$au->email ? $au->email : "") . "\t"
                . (@$au->affiliation ? $au->affiliation : "") . "\n";
        return $x;
    }

    static function topics_sql($pj) {
        $x = array();
        foreach (($pj ? $pj->topics : array()) as $id => $v)
            $x[] = "($id,$pj->id)";
        sort($x);
        return join(",", $x);
    }

    static function options_sql($pj) {
        $x = array();
        $option_list = PaperOption::option_list();
        foreach (($pj ? $pj->options : array()) as $id => $oa) {
            $o = $option_list[$id];
            if ($o->type == "text")
                $x[] = "($pj->id,$o->id,1,'" . sqlq($oa) . "')";
            else if ($o->is_document())
                $x[] = "($pj->id,$o->id,$oa->docid,null)";
            else if ($o->type == "attachments") {
                $oa = is_array($oa) ? $oa : array($oa);
                foreach ($oa as $ord => $ov)
                    $x[] = "($pj->id,$o->id,$ov->docid,'" . ($ord + 1) . "')";
            } else
                $x[] = "($pj->id,$o->id,$oa,null)";
        }
        sort($x);
        return join(",", $x);
    }

    static function conflicts_array($pj, $old_pj) {
        $x = array();
        if ($pj && @$pj->pc_conflicts)
            foreach ($pj->pc_conflicts as $email => $type)
                $x[strtolower($email)] = $type;
        if ($pj && @$pj->authors)
            foreach ($pj->authors as $au)
                if (@$au->email)
                    $x[strtolower($au->email)] = CONFLICT_AUTHOR;
        if ($pj && @$pj->contacts)
            foreach ($pj->contacts as $email => $crap) {
                $email = strtolower($email);
                if (!@$x[$email] || $x[$email] < CONFLICT_CONTACTAUTHOR)
                    $x[$email] = CONFLICT_CONTACTAUTHOR;
            }
        if ($old_pj && @$old_pj->pc_conflicts)
            foreach ($old_pj->pc_conflicts as $email => $type)
                if ($type == CONFLICT_CHAIRMARK) {
                    $email = strtolower($email);
                    if (@($x[$email] < CONFLICT_CHAIRMARK))
                        $x[$email] = CONFLICT_CHAIRMARK;
                }
        ksort($x);
        return $x;
    }

    function save($pj, $old_pj = null) {
        global $Conf, $Now;

        if (@$pj->id && !$old_pj)
            $old_pj = self::load($pj->id);
        if (!@$pj->id)
            $pj->id = $old_pj ? $old_pj->id : 0;
        if ($pj && $old_pj && $pj->id != $old_pj->id) {
            $this->set_error("id", "Saving paper with different ID");
            return false;
        }

        $this->normalize($pj, $old_pj);
        if ($old_pj)
            $this->normalize($old_pj, null);
        if ($this->nerrors)
            return false;
        $this->check_invariants($pj);

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
        foreach ($pj->contacts as $email => $c)
            if (!Contact::find_by_email($c->email, $c, !$this->no_email))
                $this->set_error("contacts", "Could not create an account for contact " . Text::user_html($c) . ".");

        // catch errors
        if ($this->nerrors)
            return false;

        // update Paper table
        $q = array();
        if (!$old_pj || $old_pj->title != @$pj->title)
            $q[] = "title='" . sqlq($pj->title) . "'";
        if (!$old_pj || $old_pj->abstract != @$pj->abstract)
            $q[] = "abstract='" . sqlq($pj->abstract) . "'";
        if (!$old_pj || $old_pj->collaborators != @$pj->collaborators)
            $q[] = "collaborators='" . sqlq($pj->collaborators) . "'";

        $autext = self::author_information($pj);
        $old_autext = self::author_information($old_pj);
        if ($autext != $old_autext || !$old_pj)
            $q[] = "authorInformation='" . sqlq($autext) . "'";

        if ($Conf->subBlindOptional()
            && (!$old_pj || !$old_pj->nonblind != !@$pj->nonblind))
            $q[] = "blind=" . (@$pj->nonblind ? 1 : 0);

        if (!@$pj->submission && $old_pj && $old_pj->submission)
            $q[] = "paperStorageId=1";
        else if (@$pj->submission
                 && (!$old_pj || !@$old_pj->submission
                     || $old_pj->submission->docid != $pj->submission->docid))
            $q[] = "paperStorageId=" . $pj->submission->docid;
        if (!@$pj->final && $old_pj && @$old_pj->final)
            $q[] = "finalPaperStorageId=0";
        else if (@$pj->final
                 && (!$old_pj || !@$old_pj->final
                     || $old_pj->final->docid != $pj->final->docid))
            $q[] = "finalPaperStorageId=" . $pj->final->docid;

        if (@$pj->withdrawn) {
            if (!$old_pj || !@$old_pj->withdrawn) {
                $q[] = "timeWithdrawn=" . $pj->withdrawn_at;
                $q[] = "timeSubmitted=" . ($pj->submitted_at ? -100 : 0);
            } else if ((@$old_pj->submitted_at > 0) != (@$pj->submitted_at > 0))
                $q[] = "timeSubmitted=-100";
        } else if (@$pj->submitted) {
            if (!$old_pj || !@$old_pj->submitted)
                $q[] = "timeSubmitted=" . $pj->submitted_at;
            if ($old_pj && @$old_pj->withdrawn)
                $q[] = "timeWithdrawn=0";
        } else if ($old_pj && (@$old_pj->withdrawn || @$old_pj->submitted)) {
            $q[] = "timeSubmitted=0";
            $q[] = "timeWithdrawn=0";
        }

        if (count($q)) {
            if (!$Conf->subBlindOptional())
                $q[] = "blind=" . ($Conf->subBlindNever() ? 0 : 1);

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
                $result = $Conf->qe("update Paper set " . join(",", $q) . " where paperId=$pj->id");
                if (edb_nrows_affected($result) === 0
                    && edb_nrows($Conf->qe("select paperId from Paper where paperId=$pj->id")) === 0)
                    $result = $Conf->qe("insert into Paper set paperId=$pj->id, " . join(",", $q));
            } else {
                $result = $Conf->qe("insert into Paper set " . join(",", $q));
                if (!$result
                    || !($pj->id = $Conf->lastInsertId()))
                    return $this->set_error(false, "Could not create paper.");
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
        if (@$pj->topics || ($old_pj && @$old_pj->topics)) {
            $topics = self::topics_sql($pj);
            $old_topics = self::topics_sql($old_pj);
            if ($topics != $old_topics) {
                $result = $Conf->qe("delete from PaperTopic where paperId=$pj->id");
                if ($topics)
                    $result = $Conf->qe("insert into PaperTopic (topicId,paperId) values $topics");
            }
        }

        // update PaperOption
        if (@$pj->options || ($old_pj && @$old_pj->options)) {
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

    function error_messages() {
        return $this->errmsg;
    }

}
