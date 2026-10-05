<?php
// documentimporter.php -- HotCRP helper for importing paper-related documents
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

final class DocumentImporter_Analysis {
    /** @var ?string */
    public $mimetype;
    /** @var ?string */
    public $filename;
    /** @var ?string */
    public $hash;
    /** @var bool */
    public $ha_trusted;
    /** @var ?string */
    public $content;
    /** @var ?string */
    public $content_file;
    /** @var ?string */
    public $crc32;
    /** @var bool */
    public $ok = false;

    /** Analyze what `$docj` says about its document: its MIME type,
     * sanitized filename, hash, content, content file, and CRC32, and whether
     * its hash is trusted. `ok` is false (and `$di` has an error) if the
     * document is corrupt.
     * @param object $docj */
    function __construct($docj, DocumentImporter $di) {
        // extract mimetype
        if (isset($docj->mimetype) && is_string($docj->mimetype)) {
            $this->mimetype = $docj->mimetype;
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
        $this->filename = DocumentInfo::sanitize_filename($filename);

        // extract requested hash
        $ha = $want_algorithm = null;
        if (isset($docj->hash) && is_string($docj->hash)) {
            $ha = new HashAnalysis($docj->hash);
        } else if (isset($docj->sha1) && is_string($docj->sha1)) {
            $ha = new HashAnalysis($docj->sha1);
            $want_algorithm = "sha1";
        }
        if ($ha && (!$ha->complete() || ($want_algorithm && $ha->algorithm() !== $want_algorithm))) {
            $di->warning("<0>Invalid `hash` ignored");
            $ha = null;
        }
        $this->ha_trusted = $ha && ($di->save_flags() & DocumentInfo::SAVEF_TRUST_METADATA) !== 0;

        // extract content
        if ($this->ha_trusted) {
            /* skip content, use provided hash */
        } else if (isset($docj->content) && is_string($docj->content)) {
            $this->content = $docj->content;
        } else if (isset($docj->content_base64) && is_string($docj->content_base64)) {
            $this->content = base64_decode($docj->content_base64);
        } else if (isset($docj->content_file) && is_string($docj->content_file)) {
            if (is_readable($docj->content_file)) {
                $this->content_file = $docj->content_file;
            } else {
                $di->error("<0>Could not access `content_file`");
            }
        } else if (isset($docj->content_file) && is_resource($docj->content_file)) {
            if (!($this->content_file = $di->upload_content_stream($docj->content_file, $this->mimetype))) {
                $di->warning("<0>Could not copy `content_file` to a temporary file");
            }
        }

        // compute content hash
        $content_ha = HashAnalysis::make_algorithm($di->conf, $ha ? $ha->algorithm() : null);
        if ($this->ha_trusted) {
            // do not compute content hash
        } else if ($this->content !== null) {
            $content_ha->set_hash($this->content);
        } else if ($this->content_file !== null) {
            $content_ha->set_hash_file($this->content_file);
        }

        // compare content hash with user-provided hash; error if different
        if ($ha
            && $content_ha->complete()
            && $ha->binary() !== $content_ha->binary()) {
            $di->error("<0>Document corrupt (its content did not match the provided hash)");
            return;
        }

        // also check CRC32 if provided
        if (isset($docj->crc32) && is_string($docj->crc32)) {
            if (strlen($docj->crc32) === 8 && ctype_xdigit($docj->crc32)) {
                $this->crc32 = hex2bin($docj->crc32);
            } else if (strlen($docj->crc32) === 4 && $docj->crc32 !== "\0\0\0\0") {
                $this->crc32 = $docj->crc32;
            } else {
                $di->warning("<0>Invalid `crc32` ignored");
            }
        }
        if ($this->crc32 !== null) {
            $content_crc32 = false;
            if ($this->ha_trusted) {
                // assume provided crc32 was correct
            } else if ($this->content !== null) {
                $content_crc32 = hash("crc32b", $this->content, true);
            } else if ($this->content_file !== null) {
                $content_crc32 = hash_file("crc32b", $this->content_file, true);
            }
            if ($content_crc32 !== false
                && $this->crc32 !== $content_crc32) {
                $di->error("<0>Document corrupt (its content did not match the provided checksum)");
                return;
            }
        }

        // choose a hash
        if ($ha) {
            $this->hash = $ha->binary();
        } else if ($content_ha->complete()) {
            $this->hash = $content_ha->binary();
        }
        $this->ok = true;
    }
}

final class DocumentImporter {
    // bound on stored documents indexed by hash (see `_load_hash_index`)
    const HASH_INDEX_LIMIT = 4000;

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
    /** @var array<string,list<array{int,?string,?string}>> */
    private $_hash_index = [];
    /** @var bool */
    private $_hash_index_loaded = false;

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

    /** @return int */
    function save_flags() {
        return $this->doc_savef;
    }

    /** Maximum accepted size for an imported document, in bytes. Uses the
     * field's configured `max_size`, else the upload-API limit, capped by
     * `Upload_API::MAX_SIZE`. Bounds decompression on the ZIP-import path.
     *
     * The raw `upload_max_filesize` ini limit does not apply: an imported
     * document arrives through the upload API (or the CLI), not a PHP multipart
     * upload. Only an explicit `uploadMaxFilesize` deployment option, which is a
     * deliberate cap, constrains it.
     * @return int */
    function max_upload_size() {
        $opt = $this->conf->option_by_id($this->dt);
        if ($opt && $opt->max_size > 0) {
            $sz = $opt->max_size;
        } else if ($this->conf->opt("uploadMaxFilesize") !== null) {
            $sz = $this->conf->upload_max_filesize(true);
        } else {
            $sz = $this->conf->opt("uploadApiMaxSize") ?? Upload_API::MAX_SIZE;
        }
        return $sz > 0 ? (int) min($sz, Upload_API::MAX_SIZE) : Upload_API::MAX_SIZE;
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
            && $docj->content_file !== false
            && !$this->check_content_file_first($docj)) {
            return null;
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

        // save (or reuse a stored copy)
        if ($doc->paperStorageId === 0
            && !($doc = $this->_save_document($doc))) {
            return null;
        }

        assert($doc->paperId === $this->prow->paperId || $doc->paperId === 0 || $doc->paperId === -1);
        $doc->release_redundant_content();
        return $doc;
    }

    /** Store `$doc`, or else return a stored copy of it -- perhaps one stored
     * earlier in this request, in which case the caller gets a distinct
     * DocumentInfo for the same row.
     * @return ?DocumentInfo */
    private function _save_document(DocumentInfo $doc) {
        if (!$doc->has_error()) {
            // prefer a stored copy (now that the content has been analyzed),
            // found through the index rather than by `save`
            $hash = $doc->binary_hash();
            if ($hash !== false
                && ($edoc = $this->_find_document(-1, $hash, $doc->mimetype, $doc->filename))) {
                if ($doc->prefer_inactive()) {
                    $edoc->set_prefer_inactive();
                }
                return $edoc;
            }
            if ($doc->save($this->doc_savef | DocumentInfo::SAVEF_SKIP_EXISTING)) {
                // a later duplicate in this request can share the row
                if ($hash !== false && $this->_hash_index_loaded) {
                    $this->_hash_index[$hash][] = [$doc->paperStorageId, $doc->mimetype, $doc->filename];
                }
                return $doc;
            }
        }
        foreach ($doc->message_list() as $mi) {
            $mi = $this->append_item($mi->with_field($this->field));
            $mi->landmark = $doc->error_filename();
        }
        return null;
    }

    /** Index this field’s stored documents as lists of [ID, MIME type,
     * filename] by binary hash. The stored documents are fetched once per
     * importer, not once per document, so that importing N documents does
     * not scan them N times; `_save_document` adds the documents it stores.
     * Whole rows are not kept, so a match costs one more fetch by ID; and
     * since a submission may have accumulated any number of replaced
     * documents, at most HASH_INDEX_LIMIT are indexed -- active ones first,
     * then the newest -- so older ones are simply not found by hash. */
    private function _load_hash_index() {
        if ($this->_hash_index_loaded) {
            return;
        }
        $this->_hash_index_loaded = true;
        // (a new submission has no stored documents yet)
        if (!$this->prow->is_new()) {
            $result = $this->conf->qe("select paperStorageId, sha1, mimetype, filename from PaperStorage where paperId=? and documentType=? and filterType is null order by inactive, paperStorageId desc limit " . self::HASH_INDEX_LIMIT, $this->prow->paperId, $this->dt);
            while (($row = $result->fetch_row())) {
                $this->_hash_index[$row[1]][] = [(int) $row[0], $row[2], $row[3]];
            }
            Dbl::free($result);
        }
    }

    /** Return a stored, unfiltered document of this field given its ID
     * `$docid` (if positive) or else its binary hash `$hash`; the document
     * must also have `$hash`, MIME type `$mimetype`, and sanitized filename
     * `$filename` where those are non-null.
     * @param int $docid
     * @param ?string $hash
     * @param ?string $mimetype
     * @param ?string $filename
     * @return ?DocumentInfo */
    private function _find_document($docid, $hash, $mimetype, $filename) {
        if ($docid <= 0) {
            if ($hash === null) {
                return null;
            }
            // find the ID of the first (oldest) matching stored copy
            $this->_load_hash_index();
            foreach ($this->_hash_index[$hash] ?? [] as $idmf) {
                if (($docid <= 0 || $idmf[0] < $docid)
                    && ($mimetype === null || $idmf[1] === $mimetype)
                    && ($filename === null || $idmf[2] === $filename)) {
                    $docid = $idmf[0];
                }
            }
            if ($docid <= 0) {
                return null;
            }
        } else if ($this->prow->is_new()) {
            // (a new submission has no stored documents to refer to by ID)
            return null;
        }
        $result = $this->conf->qe("select " . $this->conf->document_query_fields() . " from PaperStorage where paperId=? and paperStorageId=?", $this->prow->paperId, $docid);
        $edoc = DocumentInfo::fetch($result, $this->conf, $this->prow);
        Dbl::free($result);
        // (an ID found in the index will match, but a caller’s ID might not)
        if ($edoc
            && $edoc->documentType === $this->dt
            && $edoc->filterType === null
            && ($hash === null || $edoc->sha1 === $hash)
            && ($mimetype === null || $edoc->mimetype === $mimetype)
            && ($filename === null || $edoc->filename === $filename)) {
            return $edoc;
        }
        return null;
    }

    /** @param object $docj
     * @return bool */
    private function check_content_file_first($docj) {
        if (!is_string($docj->content_file)) {
            $this->error("<0>Invalid `content_file`");
            return false;
        }
        if (!preg_match('/\A\/|(?:\A|\/)\.\.(?:\/|\z)/', $docj->content_file)
            || ($this->doc_savef & DocumentInfo::SAVEF_ANY_CONTENT_FILE) !== 0) {
            // filename appears safe or any filename allowed
            return true;
        }
        if (($this->doc_savef & DocumentInfo::SAVEF_TRUST_METADATA) !== 0) {
            // we may not need this invalid content_file
            $docj->content_file = null;
            return true;
        }
        $this->error("<0>`content_file` filename violates locality constraints");
        return false;
    }

    /** Return the stored document `$docj` names, given its analysis `$dj`.
     * @param object $docj
     * @param object $dj
     * @return ?DocumentInfo */
    private function _find_json_document($docj, $dj) {
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
        return $this->_find_document($docid, $dj->hash, $dj->mimetype, $dj->filename);
    }

    /** Return the stored document of this field that `$docj` names -- the one
     * an upload of `$docj` would reuse -- or null. Nothing is stored, and a
     * document whose content is in a `content_file` is not looked up.
     * @param object|DocumentInfo $docj
     * @return ?DocumentInfo */
    function find_stored($docj) {
        if ($docj instanceof DocumentInfo) {
            $hash = $docj->has_error() ? false : $docj->binary_hash();
            return $hash !== false
                ? $this->_find_document(-1, $hash, $docj->mimetype, $docj->filename)
                : null;
        } else if (!is_object($docj)
                   || isset($docj->content_file)
                   || ($docj->error ?? false)
                   || ($docj->error_html ?? false)) {
            return null;
        }
        $da = new DocumentImporter_Analysis($docj, $this);
        return $da->ok ? $this->_find_json_document($docj, $da) : null;
    }

    /** @param object $docj
     * @return ?DocumentInfo */
    private function _upload_json_document($docj) {
        $da = new DocumentImporter_Analysis($docj, $this);
        if (!$da->ok) {
            return null;
        }
        if (($edoc = $this->_find_json_document($docj, $da))) {
            if (($docj->inactive ?? null) === true) {
                $edoc->set_prefer_inactive();
            }
            return $edoc;
        }

        // content required from here on; fail if it's not available
        if ($da->content === null
            && $da->content_file === null
            && (($this->doc_savef & DocumentInfo::SAVEF_ALLOW_HASH_WITHOUT_CONTENT) === 0
                || $da->hash === null
                || $da->mimetype === null)) {
            $this->error("<0>Ignored attempt to upload document without any content");
            return null;
        }

        // make new document
        $doc = DocumentInfo::make($this->conf)
            ->set_paper($this->prow)
            ->set_document_type($this->dt);
        if ($da->mimetype !== null) {
            $doc->set_mimetype($da->mimetype);
        }
        if (isset($docj->timestamp) && is_int($docj->timestamp)) {
            $doc->set_timestamp($docj->timestamp);
        }
        if ($da->filename !== null) {
            $doc->set_filename($da->filename);
        }
        if ($da->content !== null) {
            $doc->set_simple_content($da->content);
        } else if ($da->content_file !== null) {
            $doc->set_simple_content_file($da->content_file);
        }
        if ($da->hash !== null) {
            $doc->set_hash($da->hash);
        }
        if ($da->crc32 !== null) {
            $doc->set_crc32($da->crc32);
        }
        if (isset($docj->size)
            && is_int($docj->size)
            && $da->ha_trusted) {
            $doc->set_size($docj->size);
        }
        if (($docj->inactive ?? null) === true) {
            $doc->set_prefer_inactive();
        }

        // analyze content, complain if not available
        if ($da->ha_trusted) {
            // don't analyze content
        } else if ($doc->content_available() || $doc->ensure_content()) {
            $doc->analyze_content();
        } else {
            $doc->error("<0>Document has no content");
        }

        return $doc;
    }

    /** Copy stream `$f` to a temporary file, returning its name, or null if
     * the copy failed or exceeded the upload size limit. Closes `$f`.
     * @param resource $f
     * @param ?string $mimetype
     * @return ?string */
    function upload_content_stream($f, $mimetype) {
        $content_file = null;
        $template = "upf-%s" . Mimetype::extension($mimetype);
        if (($finfo = Filer::create_tempfile($this->conf->docstore_tempdir(), $template))) {
            // bound decompression: copy at most one byte past the limit, so a
            // lying declared size can't force an unbounded inflate
            $max = $this->max_upload_size();
            $n = stream_copy_to_stream($f, $finfo[1], $max + 1);
            fclose($finfo[1]);
            if ($n !== false && $n <= $max) {
                $content_file = $finfo[0];
            } else {
                @unlink($finfo[0]);
            }
        }
        fclose($f);
        return $content_file;
    }
}
