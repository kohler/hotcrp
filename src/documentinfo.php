<?php
// documentinfo.php -- HotCRP document objects
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class DocumentInfo implements JsonSerializable {
    public $conf;
    public $prow;

    // database fields
    public $paperId = 0;
    public $paperStorageId = 0;
    public $timestamp;
    public $mimetype;
    // $paper - translated to $content on load
    public $compression;
    public $sha1 = ""; // binary hash; empty = unhashed, false = not available
    public $documentType = 0;
    public $filename;
    public $infoJson = false;
    public $size;
    public $filterType;
    public $originalStorageId;
    public $inactive = 0;
    private $_owner;

    public $content;
    public $content_file;
    public $filestore;

    public $unique_filename;
    public $sourceHash;
    public $is_partial = false;
    public $filters_applied;
    public $error;
    public $error_html;

    function __construct($p, Conf $conf, PaperInfo $prow = null) {
        $this->merge($p, $conf, $prow);
    }

    private function merge($p, Conf $conf, PaperInfo $prow = null) {
        $this->conf = $conf;
        $this->prow = $prow;
        if ($p) {
            foreach ($p as $k => $v) {
                if ($k === "hash")
                    $this->sha1 = $v;
                else if ($k === "content_base64")
                    $this->content = base64_decode($v);
                else if ($k === "metadata")
                    $this->infoJson = $v;
                else
                    $this->$k = $v;
            }
        }
        $this->paperId = (int) $this->paperId;
        $this->paperStorageId = (int) $this->paperStorageId;
        $this->timestamp = (int) $this->timestamp;
        $this->documentType = (int) $this->documentType;
        assert($this->paperStorageId <= 1 || !!$this->mimetype);
        assert($this->sha1 === false || is_string($this->sha1));
        if ($this->sha1 !== false && $this->sha1 !== "")
            $this->sha1 = Filer::hash_as_binary($this->sha1);
        $this->size = (int) $this->size;
        if (is_string($this->infoJson))
            $this->infoJson = json_decode($this->infoJson);
        else if (is_associative_array($this->infoJson))
            $this->infoJson = array_to_object_recursive($this->infoJson);
        else if (!is_object($this->infoJson) && $this->infoJson !== false)
            $this->infoJson = null;
        $this->filterType = (int) $this->filterType ? : null;
        $this->originalStorageId = (int) $this->originalStorageId ? : null;
        $this->inactive = (int) $this->inactive;
        if ($this->sourceHash != "")
            $this->sourceHash = Filer::hash_as_binary($this->sourceHash);
        if (isset($this->paper) && !isset($this->content)) {
            $this->content = $this->paper;
            unset($this->paper);
        }
        if ($this->error_html)
            $this->error = true;
    }

    static function fetch($result, Conf $conf, PaperInfo $prow = null) {
        $di = $result ? $result->fetch_object("DocumentInfo", [null, $conf, $prow]) : null;
        if ($di && !is_int($di->paperStorageId))
            $di->merge(null, $conf, $prow);
        return $di;
    }

    static function make_file_upload($paperId, $documentType, $upload, $conf) {
        if (!$upload || !is_array($upload)) {
            return null;
        }
        $args = ["paperId" => $paperId,
                 "documentType" => $documentType,
                 "timestamp" => time(),
                 "mimetype" => get($upload, "type")];
        if (isset($upload["name"])
            && strlen($upload["name"]) <= 255
            && is_valid_utf8($upload["name"])) {
            $args["filename"] = $upload["name"];
        }
        $fnhtml = isset($args["filename"]) ? " “" . htmlspecialchars($args["filename"]) . "”" : "";

        $content = false;
        if (isset($upload["content"])) {
            $content = $args["content"] = $upload["content"];
        } else if (isset($upload["content_file"])) {
            $args["content_file"] = $upload["content_file"];
        } else if (isset($upload["tmp_name"]) && is_readable($upload["tmp_name"])) {
            $args["size"] = filesize($upload["tmp_name"]);
            if ($args["size"] > 0)
                $args["content_file"] = $upload["tmp_name"];
            else
                $args["error_html"] = "Uploaded file$fnhtml was empty, not saving.";
        } else {
            $args["error_html"] = "Uploaded file$fnhtml could not be read.";
        }
        self::fix_mimetype($args);
        return new DocumentInfo($args, $conf);
    }

    static function fix_mimetype(&$args) {
        $content = get($args, "content");
        if ($content === null && isset($args["content_file"]))
            $content = file_get_contents($args["content_file"], false, null, 0, 1024);
        $args["mimetype"] = Mimetype::content_type($content, get($args, "mimetype"));
    }


    function with_owner($owner) {
        if ($this->_owner === null) {
            $this->_owner = $owner;
            return $this;
        } else if ($this->_owner === $owner) {
            return $this;
        } else {
            $d = clone $this;
            $d->_owner = $owner;
            return $d;
        }
    }

    function add_error_html($error_html, $warning = false) {
        if (!$warning) {
            $this->error = true;
        }
        if ($this->error_html) {
            $this->error_html .= " " . $error_html;
        } else {
            $this->error_html = $error_html;
        }
        return false;
    }

    function set_content($content, $mimetype = null) {
        assert(is_string($content));
        $this->content = $content;
        $this->size = strlen($content);
        $this->mimetype = $mimetype;
        $this->sha1 = "";
    }


    function content_available() {
        return $this->content !== null
            || $this->content_file !== null
            || $this->filestore !== null;
    }

    function ensure_content() {
        if ($this->content_available())
            return true;
        // 1. check docstore
        if ($this->load_docstore())
            return true;
        // 2. check db
        $dbNoPapers = $this->conf->opt("dbNoPapers");
        if (!$dbNoPapers && $this->load_database())
            return true;
        // 3. check S3
        if ($this->load_s3())
            return true;
        // 4. check db as last resort
        if ($dbNoPapers && $this->load_database())
            return true;
        return $this->add_error_html("Cannot load document.");
    }

    function ensure_size() {
        if ($this->size == 0 && $this->paperStorageId != 1) {
            if (!$this->ensure_content())
                return false;
            else if ($this->content !== null)
                $this->size = strlen($this->content);
            else if ($this->content_file !== null)
                $this->size = (int) filesize($this->content_file);
            else if ($this->filestore !== null)
                $this->size = (int) filesize($this->filestore);
        }
        return $this->size != 0 || $this->paperStorageId == 1;
    }

    function load_database() {
        if ($this->paperStorageId <= 1)
            return false;
        $row = $this->conf->fetch_first_row("select paper, compression from PaperStorage where paperId=? and paperStorageId=?", $this->paperId, $this->paperStorageId);
        if ($row === null)
            $row = $this->conf->fetch_first_row("select paper, compression from PaperStorage where paperStorageId=?", $this->paperStorageId);
        if ($row !== null && $row[0] !== null) {
            $this->content = $row[1] == 1 ? gzinflate($row[0]) : $row[0];
            $this->size = strlen($this->content);
            return true;
        }
        return false;
    }

    function load_docstore() {
        if (($dspath = Filer::docstore_path($this, Filer::FPATH_EXISTS))) {
            $this->filestore = $dspath;
            $this->size = 0;
            return true;
        }
        return false;
    }

    function store_skeleton() {
        $this->ensure_size();
        if (!$this->timestamp)
            $this->timestamp = time();
        $upd = ["sha1" => $this->binary_hash(), "inactive" => 0];
        foreach (["paperId", "timestamp", "size", "mimetype", "documentType"] as $k)
            $upd[$k] = $this->$k;
        foreach (["filename", "filterType", "originalStorageId"] as $k)
            if ($this->$k)
                $upd[$k] = $this->$k;
        if ($this->infoJson)
            $upd["infoJson"] = json_encode_db($this->infoJson);

        if ($this->paperStorageId > 1) {
            $qv = array_values($upd);
            $qv[] = $this->paperStorageId;
            $result = $this->conf->qe_apply("update PaperStorage set " . join("=?, ", array_keys($upd)) . "=? where paperStorageId=?", $qv);
        } else {
            $result = $this->conf->qe_apply("insert into PaperStorage set " . join("=?, ", array_keys($upd)) . "=?", array_values($upd));
            if ($result)
                $this->paperStorageId = (int) $result->insert_id;
        }

        if ($result) {
            Dbl::free($result);
            return true;
        } else {
            if ($this->conf->dblink->errno)
                error_log("Error while saving document: " . $this->conf->dblink->error);
            return $this->add_error_html("Error while saving document.");
        }
    }

    function store_database() {
        if (!$this->conf->opt("dbNoPapers")) {
            $content = $this->content();
            for ($p = 0; $p < strlen($content); $p += 400000) {
                $result = $this->conf->qe("update PaperStorage set paper=concat(coalesce(paper,''),?) where paperId=? and paperStorageId=?", substr($content, $p, 400000), $this->paperId, $this->paperStorageId);
                if (!$result)
                    break;
                Dbl::free($result);
            }
            if ($this->conf->fetch_ivalue("select length(paper) from PaperStorage where paperId=? and paperStorageId=?", $this->paperId, $this->paperStorageId) === strlen($content))
                return true;
            return $this->add_error_html("Error while saving document content to database.", true);
        }
        return null;
    }

    private function store_docstore() {
        if (($dspath = Filer::docstore_path($this, Filer::FPATH_MKDIR))) {
            if (file_exists($dspath)
                && $this->file_binary_hash($dspath, $this->binary_hash()) === $this->binary_hash())
                $ok = true;
            else if ($this->content_file !== null
                     && copy($this->content_file, $dspath)
                     && @filesize($dspath) === @filesize($this->content_file))
                $ok = true;
            else {
                $content = $this->content();
                if (file_put_contents($dspath . "~", $content) === strlen($content)
                    && rename($dspath . "~", $dspath))
                    $ok = true;
                else {
                    @unlink($dspath . "~");
                    $ok = false;
                }
            }
            if ($ok) {
                $this->filestore = $dspath;
                @chmod($dspath, 0660 & ~umask());
                return true;
            } else {
                @unlink($dspath);
                return $this->add_error_html("Error while saving document to file system.", true);
            }
        }
        return null;
    }

    function s3_key() {
        if (($hash = $this->text_hash()) === false)
            return null;
        // Format: `doc/%[2/3]H/%h%x`. Why not algorithm in subdirectory?
        // Because S3 works better if keys are partitionable.
        if (strlen($hash) === 40)
            $x = substr($hash, 0, 2);
        else
            $x = substr($hash, strpos($hash, "-") + 1, 3);
        return "doc/$x/$hash" . Mimetype::extension($this->mimetype);
    }

    private function s3_upgrade_extension(S3Document $s3, $s3k) {
        $extension = Mimetype::extension($this->mimetype);
        if ($extension === ".pdf" || $extension === "")
            return false;
        return $s3->copy(substr($s3k, 0, -strlen($extension)), $s3k);
    }

    function load_s3() {
        if (($s3 = $this->conf->s3_docstore())
            && ($s3k = $this->s3_key())) {
            if (($dspath = Filer::docstore_path($this, Filer::FPATH_MKDIR))
                && function_exists("curl_init")
                && ($stream = @fopen($dspath . "~", "x+b"))) {
                $s3l = $s3->make_curl_loader($s3k, $stream);
                $s3l->run();
                return $this->handle_load_s3_curl($s3l, $dspath);
            } else
                return $this->load_s3_direct($s3, $s3k, $dspath);
        }
        return false;
    }

    private function handle_load_s3_curl($s3l, $dspath) {
        if ($s3l->status == 404
            && $this->s3_upgrade_extension($s3l->s3, $s3l->skey))
            $s3l->run();
        fflush($s3l->dstream);
        fclose($s3l->dstream);
        $unlink = true;
        if ($s3l->status == 200) {
            if (rename($dspath . "~", $dspath)) {
                $this->filestore = $dspath;
                $this->size = 0;
                $unlink = false;
            } else {
                $this->content = file_get_contents($dspath . "~");
                $this->size = strlen($this->content);
            }
        } else
            error_log("S3 error: GET $s3l->skey: $s3l->status $s3l->status_text " . json_encode_db($s3l->response_headers));
        if ($unlink)
            @unlink($dspath . "~");
        $s3l->close();
        return $s3l->status == 200;
    }

    private function load_s3_direct($s3, $s3k, $dspath) {
        $content = $s3->load($s3k);
        if ($s3->status == 404
            && $this->s3_upgrade_extension($s3, $s3k))
            $content = $s3->load($s3k);
        if ($s3->status == 200 && (string) $content !== "") {
            if ($dspath
                && file_put_contents($dspath, $content) === strlen($content))
                $this->filestore = $dspath;
            else
                $this->content = $content;
            $this->size = strlen($content);
            return true;
        }
        if ($s3->status != 200)
            error_log("S3 error: GET $s3k: $s3->status $s3->status_text " . json_encode_db($s3->response_headers));
        return false;
    }

    function check_s3() {
        return ($s3 = $this->conf->s3_docstore())
            && ($s3k = $this->s3_key())
            && ($s3->check($s3k)
                || ($this->s3_upgrade_extension($s3, $s3k) && $s3->check($s3k)));
    }

    function store_s3() {
        if (($s3 = $this->conf->s3_docstore())
            && ($s3k = $this->s3_key())) {
            $meta = ["conf" => $this->conf->dbname, "pid" => $this->paperId, "dtype" => $this->documentType];
            if ($this->filterType) {
                $meta["filtertype"] = $this->filterType;
                if ($this->sourceHash != "")
                    $meta["sourcehash"] = Filer::hash_as_text($this->sourceHash);
            }
            $s3k = $this->s3_key();
            $s3->save($s3k, $this->content(), $this->mimetype,
                      ["hotcrp" => json_encode_db($meta)]);
            if ($s3->status == 200)
                return true;
            else {
                error_log("S3 error: POST $s3k: $s3->status $s3->status_text " . json_encode_db($s3->response_headers));
                return $this->add_error_html("Error while saving document to S3.", true);
            }
        }
        return null;
    }


    function save() {
        // look for an existing document with same sha1
        if ($this->binary_hash() !== false && $this->paperId != 0) {
            $row = $this->conf->fetch_first_row("select paperStorageId, timestamp, inactive, filename, mimetype from PaperStorage where paperId=? and documentType=? and sha1=?", $this->paperId, $this->documentType, $this->binary_hash());
            if ($row
                && (!isset($this->filename) || $row[3] === $this->filename)
                && (!isset($this->mimetype) || $row[4] === $this->mimetype)) {
                $this->paperStorageId = (int) $row[0];
                $this->timestamp = (int) $row[1];
                if ($row[2])
                    $this->conf->qe("update PaperStorage set inactive=0 where paperId=? and paperStorageId=?", $this->paperId, $this->paperStorageId);
                return true;
            }
        }

        // ensure content
        if (!$this->ensure_content())
            return $this->add_error_html("Cannot load document.");
        else if ($this->error)
            return false;

        // validate
        if (!$this->filterType) {
            $opt = $this->conf->paper_opts->get($this->documentType);
            if ($opt && !$opt->validate_document($this))
                return false;
        }

        // store
        $s0 = $this->store_skeleton();
        $s1 = $s0 && $this->store_database();
        $s2 = $this->store_docstore();
        $s3 = $this->store_s3();
        if ($s0 && ($s1 || $s2 || $s3)) {
            if ($this->error_html) {
                error_log("Recoverable error saving document " . $this->export_filename() . ", hash " . $this->text_hash() . ": " . $this->error_html);
                $this->error_html = "";
            }
            return true;
        } else {
            error_log("Error saving document " . $this->export_filename() . ", hash " . $this->text_hash() . ": " . $this->error_html);
            $this->error = true; // if !($s1||$s2||$s3), may have error still false
            $this->error_html = rtrim("Document not saved. " . $this->error_html);
            return false;
        }
    }


    function content() {
        $this->ensure_content();
        if ($this->content !== null)
            return $this->content;
        else if (($path = $this->content_file) !== null
                 || ($path = $this->filestore) !== null)
            return @file_get_contents($path);
        else
            return false;
    }

    function available_content_file() {
        if ($this->content_file !== null && is_readable($this->content_file))
            return $this->content_file;
        else if ($this->filestore !== null && is_readable($this->filestore))
            return $this->filestore;
        else
            return false;
    }

    function content_file() {
        $this->ensure_content();
        if (($path = $this->available_content_file())) {
            return $path;
        } else if ($this->content !== null) {
            if ($this->store_docstore())
                return $this->filestore;
            if (($f = $this->_temp_content_filename())) {
                $trylen = file_put_contents($f, $this->content);
                if ($trylen !== strlen($this->content)) {
                    clean_tempdirs();
                    $trylen = file_put_contents($f, $this->content);
                }
                if ($trylen === strlen($this->content))
                    return ($this->content_file = $f);
            }
            return false;
        } else {
            return false;
        }
    }

    private function _temp_content_filename() {
        if (!Filer::$tempdir && !(Filer::$tempdir = tempdir())) {
            return false;
        }
        if ($this->has_hash()) {
            $base = $this->text_hash();
        } else {
            ++Filer::$tempcounter;
            $base = "__temp" . Filer::$tempcounter . "__";
        }
        return Filer::$tempdir . "/" . $base . Mimetype::extension($this->mimetype);
    }

    function content_text_signature() {
        $s = false;
        if ($this->content === null
            && ($path = $this->available_content_file()))
            $s = file_get_contents($path, false, null, 0, 16);
        if ($s === false)
            $s = $this->content();
        if ($s === false)
            return "cannot be loaded";
        else if ($s === "")
            return "is empty";
        else {
            $t = substr($s, 0, 8);
            if (!is_valid_utf8($s)) {
                $t = UnicodeHelper::utf8_prefix(UnicodeHelper::utf8_truncate_invalid($s), 8);
                if (strlen($t) < 7)
                    $t = join("", array_map(function ($ch) {
                        $c = ord($ch);
                        if ($c >= 0x20 && $c <= 0x7E)
                            return $ch;
                        else
                            return sprintf("\\x%02X", $c);
                    }, str_split(substr($s, 0, 8))));
            }
            return "starts with “{$t}”";
        }
    }

    function content_mimetype() {
        $s = false;
        if ($this->content === null
            && ($path = $this->available_content_file()))
            $s = file_get_contents($path, false, null, 0, 1024);
        if ($s === false)
            $s = $this->content();
        return Mimetype::content_type($s, $this->mimetype);
    }


    static function prefetch_content($docs) {
        $pfdocs = [];
        foreach ($docs as $doc) {
            if (!$doc->available_content_file()
                && $doc->content === null
                && !$doc->load_docstore()
                && $doc->conf->s3_docstore())
                $pfdocs[] = $doc;
        }
        if (empty($pfdocs) || !function_exists("curl_multi_init"))
            return;

        $adocs = [];
        $curlm = curl_multi_init();
        $starttime = $stoptime = null;

        while (1) {
            // check time
            $time = microtime(true);
            if ($stoptime === null) {
                $starttime = $time;
                $stoptime = $time + 20 * max(ceil(count($pfdocs) / 8), 1);
                S3Document::$retry_timeout_allowance += 5 * count($pfdocs) / 4;
            }
            if ($time >= $stoptime)
                break;
            if ($time >= $starttime + 5)
                set_time_limit(30);

            // add documents to sliding window
            while (count($adocs) < 8 && !empty($pfdocs)) {
                $doc = array_pop($pfdocs);
                $s3 = $doc->conf->s3_docstore();
                if (($s3k = $doc->s3_key())
                    && ($dspath = Filer::docstore_path($doc, Filer::FPATH_MKDIR))
                    && ($stream = @fopen($dspath . "~", "x+b"))) {
                    $s3l = $s3->make_curl_loader($s3k, $stream);
                    $adocs[] = [$doc, $s3l, 0, $dspath];
                }
            }
            if (empty($adocs))
                break;

            // block if needed
            $mintime = $stoptime;
            foreach ($adocs as &$adoc) {
                if ($adoc[2] === 0 || $adoc[2] >= $time) {
                    $adoc[1]->prepare();
                    curl_multi_add_handle($curlm, $adoc[1]->curlh);
                    $adoc[2] = -1;
                }
                $mintime = min($mintime, $adoc[2]);
            }
            unset($adoc);
            if ($mintime > $time) {
                usleep((int) (($mintime - $time) * 1000000));
                S3Document::$retry_timeout_allowance -= $mintime - $time;
                continue;
            }

            // call multi_exec
            while (($mstat = curl_multi_exec($curlm, $mrunning)) == CURLM_CALL_MULTI_PERFORM) {
            }
            if ($mstat !== CURLM_OK)
                break;

            // handle results
            while (($minfo = curl_multi_info_read($curlm))) {
                $curlh = $minfo["handle"];
                for ($i = 0; $i < count($adocs) && $adocs[$i][1]->curlh !== $curlh; ++$i) {
                }
                $s3l = $adocs[$i][1];
                curl_multi_remove_handle($curlm, $s3l->curlh);
                if ($s3l->parse_result()) {
                    $adocs[$i][0]->handle_load_s3_curl($s3l, $adocs[$i][3]);
                    array_splice($adocs, $i, 1);
                } else {
                    $adocs[$i][2] = microtime(true) + 0.005 * (1 << $s3l->runindex);
                }
            }

            // maybe block
            if ($mrunning)
                curl_multi_select($curlm, $stoptime - microtime(true));
        }

        // clean up leftovers
        foreach ($adocs as $adoc) {
            $adoc[1]->status = null;
            $adoc[0]->handle_load_s3_curl($adoc[1], $adoc[3]);
        }
        curl_multi_close($curlm);
    }


    function has_hash() {
        assert($this->sha1 !== null);
        return (bool) $this->sha1;
    }
    function text_hash() {
        return Filer::hash_as_text($this->binary_hash());
    }
    function binary_hash() {
        if ($this->sha1 === "")
            $this->sha1 = $this->content_binary_hash();
        return $this->sha1;
    }
    function binary_hash_data() {
        $hash = $this->binary_hash();
        if ($hash === false || strlen($hash) === 20)
            return $hash;
        else
            return substr($hash, strpos($hash, "-") + 1);
    }
    function check_text_hash($hash) {
        $hash = $this->binary_hash();
        return $hash !== false && $hash === Filer::hash_as_binary($hash);
    }
    function hash_algorithm() {
        assert($this->has_hash());
        if (strlen($this->sha1) === 20)
            return "sha1";
        else if (substr($this->sha1, 0, 5) === "sha2-")
            return "sha256";
        else
            return false;
    }
    function hash_algorithm_prefix() {
        assert($this->has_hash());
        if (strlen($this->sha1) === 20)
            return "";
        else if (($dash = strpos($this->sha1, "-")) !== false)
            return substr($this->sha1, 0, $dash + 1);
        else
            return false;
    }
    private function hash_algorithm_for($like_hash) {
        $ha = null;
        if ($like_hash)
            $ha = new HashAnalysis($like_hash);
        if (!$ha || !$ha->known_algorithm())
            $ha = HashAnalysis::make_known_algorithm($this->conf->opt("contentHashMethod"));
        return $ha;
    }
    function content_binary_hash($like_hash = null) {
        // never cached
        $ha = $this->hash_algorithm_for($like_hash);
        $this->ensure_content();
        if ($this->content !== null)
            return $ha->prefix() . hash($ha->algorithm(), $this->content, true);
        else if (($file = $this->available_content_file())
                 && ($h = hash_file($ha->algorithm(), $file, true)) !== false)
            return $ha->prefix() . $h;
        else
            return false;
    }
    function file_binary_hash($file, $like_hash = null) {
        $ha = $this->hash_algorithm_for($like_hash);
        if (($h = hash_file($ha->algorithm(), $file, true)) !== false)
            return $ha->prefix() . $h;
        else
            return false;
    }


    function export_filename($filters = null) {
        $fn = $this->conf->download_prefix;
        if ($this->documentType == DTYPE_SUBMISSION) {
            $fn .= "paper" . $this->paperId;
        } else if ($this->documentType == DTYPE_FINAL) {
            $fn .= "final" . $this->paperId;
        } else if ($this->documentType == DTYPE_COMMENT) {
            assert(!$filters && $this->_owner && $this->_owner instanceof CommentInfo);
            $fn .= "paper" . $this->paperId;
            if (!$this->_owner) {
                $fn .= "/comment";
            } else if ($this->_owner->is_response()) {
                $fn .= "/" . $this->_owner->unparse_html_id();
            } else {
                $fn .= "/comment-" . $this->_owner->unparse_html_id();
            }
            return $fn . "/" . ($this->unique_filename ? : $this->filename);
        } else {
            $o = $this->conf->paper_opts->get($this->documentType);
            if ($o && $o->nonpaper && $this->paperId < 0) {
                $fn .= $o->dtype_name();
                $oabbr = "";
            } else {
                $fn .= "paper" . $this->paperId;
                $oabbr = $o ? "-" . $o->dtype_name() : "-unknown";
            }
            if ($o
                && $o->has_attachments()
                && ($afn = $this->unique_filename ? : $this->filename)) {
                assert(!$filters);
                // do not decorate with MIME type suffix
                return $fn . $oabbr . "/" . $afn;
            }
            $fn .= $oabbr;
        }
        $mimetype = $this->mimetype;
        if ($filters === null && isset($this->filters_applied)) {
            $filters = $this->filters_applied;
        }
        if ($filters) {
            foreach (is_array($filters) ? $filters : [$filters] as $filter) {
                if (is_string($filter))
                    $filter = FileFilter::find_by_name($filter);
                if ($filter instanceof FileFilter) {
                    $fn .= "-" . $filter->name;
                    $mimetype = $filter->mimetype($this, $mimetype);
                }
            }
        }
        if ($mimetype) {
            if (($ext = Mimetype::extension($mimetype))) {
                $fn .= $ext;
            } else if ($this->filename
                       && preg_match('/(\.[A-Za-z0-9]{1,5})\z/', $this->filename, $m)
                       && (!$filters || $mimetype === $this->mimetype)) {
                $fn .= $m[1];
            }
        }
        return $fn;
    }

    static function assign_unique_filenames($docs) {
        if (empty($docs)) {
            return;
        } else if (count($docs) === 1) {
            $docs[0]->unique_filename = $docs[0]->filename;
            return;
        }
        $used = [];
        foreach ($docs as $d) {
            if (!in_array($d->filename, $used)) {
                $d->unique_filename = $used[] = $d->filename;
            } else {
                $d->unique_filename = null;
            }
        }
        if (count($used) !== count($docs)) {
            foreach ($docs as $d) {
                if ($d->unique_filename === null) {
                    $fn = $d->filename;
                    while (in_array($fn, $used)) {
                        if (preg_match('/\A(.*\()(\d+)(\)(?:\.\w+|))\z/', $fn, $m)) {
                            $fn = $m[1] . ($m[2] + 1) . $m[3];
                        } else if (preg_match('/\A(.*?)(\.\w+|)\z/', $fn, $m) && $m[1] !== "") {
                            $fn = $m[1] . " (1)" . $m[2];
                        } else {
                            $fn .= " (1)";
                        }
                    }
                    $d->unique_filename = $fn;
                    $used[] = $fn;
                }
            }
        }
    }

    function url($filters = null, $flags = 0) {
        if ($filters === null) {
            $filters = $this->filters_applied;
        }
        if ($this->mimetype) {
            $f = "file=" . rawurlencode($this->export_filename($filters));
        } else {
            $f = "p=$this->paperId";
            if ($this->documentType == DTYPE_FINAL)
                $f .= "&amp;final=1";
            else if ($this->documentType > 0)
                $f .= "&amp;dt=$this->documentType";
        }
        return $this->conf->hoturl("doc", $f, $flags);
    }

    const L_SMALL = 1;
    const L_NOSIZE = 2;
    const L_FINALTITLE = 4;
    const L_REQUIREFORMAT = 8;
    function link_html($html = "", $flags = 0, $filters = null) {
        $p = $this->url($filters);
        $suffix = $info = "";
        $title = null;
        $small = ($flags & self::L_SMALL) != 0;

        if ($this->documentType == DTYPE_FINAL
            || ($this->documentType > 0
                && ($o = $this->conf->paper_opts->get($this->documentType))
                && $o->final))
            $suffix = "f";
        if ($this->documentType == DTYPE_FINAL && ($flags & self::L_FINALTITLE))
            $title = "Final version";

        assert(!($flags & self::L_REQUIREFORMAT) || !!$this->prow);
        $need_run = false;
        if (($this->documentType == DTYPE_SUBMISSION || $this->documentType == DTYPE_FINAL)
            && $this->prow) {
            list($info, $suffix, $need_run) = $this->link_html_format_info($flags, $suffix);
        }

        if ($this->mimetype == "application/pdf") {
            list($img, $alt) = ["pdf", "[PDF]"];
        } else if ($this->mimetype == "application/postscript") {
            list($img, $alt) = ["postscript", "[PS]"];
        } else {
            $img = "generic";
            $m = Mimetype::lookup($this->mimetype);
            $alt = "[" . ($m && $m->description ? $m->description : $this->mimetype) . "]";
        }

        $x = '<a href="' . $p . '" class="q' . ($need_run ? " need-format-check" : "") . '">'
            . Ht::img($img . $suffix . ($small ? "" : "24") . ".png", $alt, ["class" => $small ? "sdlimg" : "dlimg", "title" => $title]);
        if ($html) {
            $x .= "&nbsp;" . $html;
        }
        if ($this->size > 0 && !($flags && self::L_NOSIZE)) {
            $x .= " <span class=\"dlsize\">" . ($html ? "(" : "");
            if ($this->size > 921)
                $x .= round($this->size / 1024);
            else
                $x .= max(round($this->size / 102.4), 1) / 10;
            $x .= "kB" . ($html ? ")" : "") . "</span>";
        }
        return $x . "</a>" . ($info ? "&nbsp;$info" : "");
    }
    private function link_html_format_info($flags, $suffix) {
        $need_run = false;
        if (($spects = $this->conf->format_spec($this->documentType)->timestamp)) {
            if ($this->prow->is_joindoc($this)) {
                $specstatus = $this->prow->pdfFormatStatus;
                if ($specstatus == -$spects && ($flags & self::L_SMALL))
                    return ["", $suffix . "x", false];
                else if ($specstatus == $spects)
                    return ["", $suffix, false];
            }
            $runflag = CheckFormat::RUN_NO;
            if ($flags & self::L_REQUIREFORMAT)
                $runflag = CheckFormat::RUN_PREFER_NO;
            $cf = new CheckFormat($this->conf, $runflag);
            $cf->check_document($this->prow, $this);
            if ($cf->has_error()) {
                if (($flags & self::L_SMALL) || $cf->failed)
                    return ["", $suffix . "x", $cf->need_run];
                else
                    return ['<span class="need-tooltip" style="font-weight:bold" data-tooltip="' . htmlspecialchars(join("<br />", $cf->messages())) . '">ⓘ</span>', $suffix . "x", $cf->need_run];
            } else
                $need_run = $cf->need_run;
        }
        return ["", $suffix, $need_run];
    }

    function metadata() {
        if ($this->is_partial && $this->infoJson === false) {
            $x = Dbl::fetch_value($this->conf->dblink, "select infoJson from PaperStorage where paperId=? and paperStorageId=?", $this->paperId, $this->paperStorageId) ? : "null";
            $this->infoJson = json_decode($x);
        }
        return $this->infoJson;
    }

    function update_metadata($delta, $quiet = false) {
        if ($this->paperStorageId <= 1)
            return false;
        $length_ok = true;
        $ijstr = Dbl::compare_and_swap($this->conf->dblink,
            "select infoJson from PaperStorage where paperId=? and paperStorageId=?", [$this->paperId, $this->paperStorageId],
            function ($old) use ($delta, &$length_ok) {
                $j = json_object_replace($old ? json_decode($old) : null, $delta, true);
                $new = $j ? json_encode($j) : null;
                $length_ok = $new === null || strlen($new) <= 32768;
                return $length_ok ? $new : $old;
            },
            "update PaperStorage set infoJson=?{desired} where paperId=? and paperStorageId=? and infoJson?{expected}e", [$this->paperId, $this->paperStorageId]);
        $this->infoJson = is_string($ijstr) ? json_decode($ijstr) : null;
        if (!$length_ok && !$quiet)
            error_log(caller_landmark() . ": {$this->conf->dbname}: update_metadata(paper $this->paperId, dt $this->documentType): delta too long, delta " . json_encode($delta));
        return $length_ok;
    }

    function is_archive() {
        return $this->filename
            && preg_match('/\.(?:zip|tar|tgz|tar\.[gx]?z|tar\.bz2)\z/i', $this->filename);
    }
    function archive_listing($max_length = -1) {
        return ArchiveInfo::archive_listing($this, $max_length);
    }

    function npages() {
        if ($this->mimetype && $this->mimetype != "application/pdf")
            return null;
        else if (($m = $this->metadata()) && isset($m->npages))
            return $m->npages;
        else if (($path = $this->content_file())) {
            $cf = new CheckFormat($this->conf);
            $cf->clear();
            $bj = $cf->run_banal($path);
            if ($bj && is_object($bj) && isset($bj->pages)) {
                $this->update_metadata(["npages" => count($bj->pages), "banal" => $bj]);
                return count($bj->pages);
            }
        }
        return null;
    }

    function unparse_json() {
        $x = [];
        if ($this->filename) {
            $x["filename"] = $this->filename;
        }
        if ($this->unique_filename && $this->unique_filename !== $this->filename) {
            $x["unique_filename"] = $this->unique_filename;
        }
        if ($this->mimetype) {
            $x["mimetype"] = $this->mimetype;
        }
        if ($this->size) {
            $x["size"] = $this->size;
        }
        if ($this->has_hash()) {
            $x["hash"] = $this->text_hash();
        }
        $x["siteurl"] = $this->url(null, Conf::HOTURL_SITE_RELATIVE | Conf::HOTURL_RAW);
        return (object) $x;
    }
    function jsonSerialize() {
        $x = [];
        foreach (get_object_vars($this) as $k => $v) {
            if ($k === "content" && is_string($v) && strlen($v) > 50)
                $x[$k] = substr($v, 0, 50) . "…";
            else if ($k === "sha1" && is_string($v))
                $x[$k] = Filer::hash_as_text($v);
            else if ($v !== null && $k !== "conf" && $k !== "prow" && $k[0] !== "_")
                $x[$k] = $v;
        }
        return $x;
    }

    static function log_download_activity($docs, Contact $user) {
        if ($user->is_anonymous_user()) {
            return;
        }
        $byn = [];
        $any_nonauthor = false;
        foreach ($docs as $doc) {
            if ($doc->documentType !== DTYPE_COMMENT
                && $doc->conf === $user->conf
                && $doc->paperId > 0) {
                // XXX ignores documents from other conferences
                $byn[$doc->documentType][$doc->paperId] = true;
                $any_nonauthor = $any_nonauthor || !$doc->prow || !$doc->prow->has_author($user);
            }
        }
        if ($any_nonauthor) {
            foreach ($byn as $dtype => $pidm) {
                $opt = $user->conf->paper_opts->get($dtype);
                $name = $opt ? $opt->json_key() : "opt" . $dtype;
                if (!empty($pidm)) {
                    $user->log_activity("Download $name", array_keys($pidm));
                }
            }
        }
    }

    static function active_document_map(Conf $conf) {
        $q = ["select paperStorageId from Paper where paperStorageId>1",
            "select finalPaperStorageId from Paper where finalPaperStorageId>1",
            "select documentId from DocumentLink where documentId>1"];
        $document_option_ids = array();
        foreach ($conf->paper_opts->full_option_list() as $id => $o) {
            if ($o->has_document())
                $document_option_ids[] = $id;
        }
        if (!empty($document_option_ids)) {
            $q[] = "select value from PaperOption where optionId in ("
                . join(",", $document_option_ids) . ") and value>1";
        }

        $result = $conf->qe_raw(join(" UNION ", $q));
        $ids = [];
        while (($row = $result->fetch_row())) {
            $ids[(int) $row[0]] = true;
        }
        Dbl::free($result);
        return $ids;
    }
}
