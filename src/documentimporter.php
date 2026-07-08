<?php
// documentimporter.php -- HotCRP helper for importing paper-related documents
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

final class DocumentImporter {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var PaperInfo
     * @readonly */
    public $prow;
    /** @var int
     * @readonly */
    public $dt;
    /** @var ?list<int> */
    private $allowed_docids;
    /** @var int */
    private $doc_savef;
    /** @var list<callable> */
    private $_on_import = [];
    /** @var MessageSet */
    private $ms;
    /** @var ?string */
    private $field;

    /** @param int $dt
     * @param int $doc_savef */
    function __construct(PaperInfo $prow, $dt, $doc_savef, MessageSet $ms, $field = null) {
        $this->conf = $prow->conf;
        $this->prow = $prow;
        $this->dt = $dt;
        $this->doc_savef = $doc_savef;
        $this->ms = $ms;
        $this->field = $field;
    }

    /** @param callable $f
     * @return $this */
    function on_import($f) {
        $this->_on_import[] = $f;
        return $this;
    }

    /** @param list<callable> $on_imports
     * @return $this */
    function set_on_import($on_imports) {
        $this->_on_import = $on_imports;
        return $this;
    }

    /** Restrict documents locatable by `docid`; null (default) allows any.
     * @param ?list<int> $ids
     * @return $this */
    function set_allowed_docids($ids) {
        $this->allowed_docids = $ids;
        return $this;
    }


    /** @return MessageItem */
    function append_item(MessageItem $mi) {
        return $this->ms->append_item($mi);
    }

    /** @return MessageItem */
    function error($msg) {
        return $this->ms->error_at($this->field, $msg);
    }

    /** @return MessageItem */
    function warning($msg) {
        return $this->ms->warning_at($this->field, $msg);
    }


    /** @return ?DocumentInfo */
    function upload_document($docj) {
        // $docj can be a DocumentInfo or a JSON.
        // If it is a JSON, its format is set by document_to_json.
        if (is_array($docj) && count($docj) === 1 && isset($docj[0])) {
            $docj = $docj[0];
        }
        if (!is_object($docj)) {
            $this->error("<0>Validation error");
            return null;
        } else if (($docj->error ?? false) || ($docj->error_html ?? false)) {
            $this->error("<5>" . ($docj->error_html ?? "Upload error"));
            return null;
        }
        assert(!isset($docj->filter));

        // check content_file
        if (!($docj instanceof DocumentInfo)
            && isset($docj->content_file)
            && $docj->content_file !== false) {
            if (($this->doc_savef & DocumentInfo::SAVEF_IGNORE_CONTENT_FILE) !== 0) {
                $docj->content_file = null;
            } else if (($problem = $this->check_content_file_first($docj->content_file))) {
                $this->error($problem);
                return null;
            }
        }

        // check on_document_import
        foreach ($this->_on_import as $cb) {
            if (call_user_func($cb, $docj, $this->dt, $this) === false)
                return null;
        }

        // validate JSON
        if ($docj instanceof DocumentInfo) {
            $doc = $docj;
        } else if (!($doc = $this->_upload_json_document($docj))) {
            return null;
        }

        // save
        if ($doc->paperStorageId === 0
            && ($doc->has_error() || !$doc->save($this->doc_savef))) {
            foreach ($doc->message_list() as $mi) {
                $mi = $this->append_item($mi->with_field($this->field));
                $mi->landmark = $doc->error_filename();
            }
            return null;
        }

        assert($doc->paperId === $this->prow->paperId || $doc->paperId === 0 || $doc->paperId === -1);
        $doc->release_redundant_content();
        return $doc;
    }

    /** @param mixed $content_file
     * @return ?string */
    private function check_content_file_first($content_file) {
        if (!is_string($content_file)) {
            return "<0>Invalid `content_file`";
        } else if (($this->doc_savef & DocumentInfo::SAVEF_ANY_CONTENT_FILE) === 0
                   && preg_match('/\A\/|(?:\A|\/)\.\.(?:\/|\z)/', $content_file)) {
            return "<0>`content_file` filename violates locality constraints";
        }
        return null;
    }

    /** @param object $docj
     * @return ?DocumentInfo */
    private function _upload_json_document($docj) {
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
        } else if (($this->doc_savef & DocumentInfo::SAVEF_IGNORE_CONTENT_FILE) !== 0) {
            /* no content */
        } else if (isset($docj->content_file) && is_string($docj->content_file)) {
            if (is_readable($docj->content_file)) {
                $content_file = $docj->content_file;
            } else {
                $this->error("<0>Could not access `content_file`");
            }
        } else if (isset($docj->content_file) && is_resource($docj->content_file)) {
            if (!($content_file = $this->_upload_content_stream($docj->content_file, $mimetype))) {
                $this->warning("<0>Could not copy `content_file` to a temporary file");
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
            $this->warning("<0>Invalid `hash` ignored");
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
            $this->error("<0>Document corrupt (its content did not match the provided hash)");
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
                $this->warning("<0>Invalid `crc32` ignored");
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
                $this->error("<0>Document corrupt (its content did not match the provided checksum)");
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

        // check for existing document. A caller-supplied allowlist bounds which
        // docids may be retained (docids are enumerable, so e.g. a comment may
        // retain only its own attachments; hash is a possession capability and
        // stays unscoped).
        $docid = -1;
        if (isset($docj->docid)
            && is_int($docj->docid)
            && $docj->docid > 0
            && ($this->allowed_docids === null
                || in_array($docj->docid, $this->allowed_docids, true))) {
            $docid = $docj->docid;
        }
        if (!$this->prow->is_new()
            && ($docid > 0 || $hash !== null)) {
            $qf = ["paperId=?", "documentType=?", "filterType is null"];
            $qv = [$this->prow->paperId, $this->dt];
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
                if (($docj->inactive ?? null) === true) {
                    $edoc->set_prefer_inactive();
                }
                return $edoc;
            }
        }

        // content required from here on; fail if it's not available
        if ($content === null
            && $content_file === null
            && (($this->doc_savef & DocumentInfo::SAVEF_ALLOW_HASH_WITHOUT_CONTENT) === 0
                || $hash === null
                || $mimetype === null)) {
            $this->error("<0>Ignored attempt to upload document without any content");
            return null;
        }

        // make new document
        $doc = DocumentInfo::make($this->conf)
            ->set_paper($this->prow)
            ->set_document_type($this->dt);
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
        if (($docj->inactive ?? null) === true) {
            $doc->set_prefer_inactive();
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
    private function _upload_content_stream($f, $mimetype) {
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
}
