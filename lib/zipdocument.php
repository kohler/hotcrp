<?php
// zipdocument.php -- document helper class for zip archives
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class ZipDocument {
    private $conf;
    public $filename;
    private $mimetype;
    private $filestore;

    private $_tmpdir = null;
    public $warnings;
    private $headers;
    private $start_time;

    private $_docs;
    private $_files;
    private $_saveindex;
    private $_docmem;

    function __construct($filename, $mimetype = "application/zip") {
        global $Conf;
        $this->conf = $Conf;
        $this->filename = $filename;
        $this->mimetype = $mimetype;
        $this->clean();
    }

    function clean() {
        $this->filestore = false;
        $this->_tmpdir = null;
        $this->files = array();
        $this->warnings = array();
        $this->headers = false;
        $this->start_time = time();

        $this->_docs = $this->_files = [];
        $this->_saveindex = $this->_docmem = 0;
    }

    private function tmpdir() {
        if ($this->_tmpdir === null
            && ($this->_tmpdir = tempdir()) === false)
            $this->warnings[] = "Could not create temporary directory.";
        return $this->_tmpdir;
    }

    private function _save_one() {
        assert($this->_saveindex < count($this->_docs));
        if (!($tmpdir = $this->tmpdir()))
            return false;
        $doc = $this->_docs[$this->_saveindex];
        ++$this->_saveindex;
        // create parent directories
        $fn = $doc->filename;
        for ($p = strpos($fn, "/"); $p !== false; $p = strpos($fn, "/", $p + 1)) {
            $dfn = $tmpdir . "/" . substr($fn, 0, $p);
            if (!is_dir($dfn)
                && (file_exists($dfn) || !@mkdir($dfn, 0777))) {
                $this->warnings[] = "$fn: Filename conflicts with directory.";
                return false;
            }
        }
        // store file in temporary directory
        $zfn = $tmpdir . "/" . $fn;
        if (($path = $doc->available_content_file())
            && @symlink($path, $zfn))
            /* OK */;
        else {
            $content = $doc->content();
            $trylen = file_put_contents($zfn, $content);
            if ($trylen !== strlen($content)) {
                clean_tempdirs();
                $trylen = file_put_contents($zfn, $content);
            }
            if ($trylen !== strlen($content)) {
                $this->warnings[] = "$fn: Could not save.";
                return false;
            }
        }
        if ($doc->content !== null) {
            $doc->text_hash();
            $this->_docmem -= strlen($doc->content);
            $doc->content = null;
        }
        return true;
    }

    private function _add($doc, $filename, $check_filename) {
        if (is_string($doc))
            $doc = new DocumentInfo(["content" => $doc]);
        assert($doc instanceof DocumentInfo);

        if ($filename == "" && $doc->filename != "")
            $filename = $doc->filename;

        // maybe this is a warning container
        if ($doc->error) {
            $this->warnings[] = "$filename: " . ($doc->error_html ? htmlspecialchars_decode($doc->error_html) : "Unknown error.");
            return false;
        }

        // check filename
        if ($filename == "") {
            $this->warnings[] = "Empty filename.";
            return false;
        }
        if (str_ends_with($filename, "/")
            || strpos($filename, "//") !== false
            || ($check_filename
                && !preg_match(',\A[^.*/\s\000-\017\\\\\'"][^*/\000-\017\\\\\'"]*\z,', $filename))) {
            $this->warnings[] = "$filename: Bad filename.";
            return false;
        }

        // mark directory components
        for ($p = strpos($filename, "/"); $p !== false; $p = strpos($filename, "/", $p + 1)) {
            $pfx = substr($filename, 0, $p);
            if (isset($this->_files[$pfx]) && $this->_files[$pfx] === false) {
                $this->warnings[] = "$filename: Conflict with previous filename.";
                return false;
            }
            $this->_files[$pfx] = true;
        }
        if (isset($this->_files[$filename])) {
            $this->warnings[] = "$filename: Conflict with previous filename.";
            return false;
        }
        $this->_files[$filename] = false;

        // add document to list
        $doc = clone $doc;
        $doc->filename = $filename;
        if ($doc->available_content_file() && $doc->content !== null)
            $doc->content = null;
        $this->_docs[] = $doc;
        if ($doc->content !== null)
            $this->_docmem += strlen($doc->content);
        while ($this->_saveindex < count($this->_docs)
               && $this->_docmem > 4000000)
            $this->_save_one();

        return true;
    }

    function add($doc, $filename = null) {
        return $this->_add($doc, $filename, true);
    }

    function add_as($doc, $filename) {
        return $this->_add($doc, $filename, false);
    }

    function download_headers() {
        if (!$this->headers) {
            header("Content-Disposition: attachment; filename=" . mime_quote_string($this->filename));
            header("Content-Type: " . $this->mimetype);
            $this->headers = true;
        }
    }

    private function _make_document($error_html = false) {
        if (!$error_html)
            return new DocumentInfo(["filename" => $this->filename, "mimetype" => $this->mimetype, "content_file" => $this->filestore]);
        else {
            $this->filestore = false;
            return new DocumentInfo(["error" => true, "error_html" => $error_html]);
        }
    }

    function create() {
        global $Now;

        if (!empty($this->warnings))
            $this->add_as(join("\n", $this->warnings) . "\n", "README-warnings.txt");

        if ($this->conf->opt("docstore")) {
            // calculate hash for zipfile contents
            $xdocs = $this->_docs;
            usort($xdocs, function ($a, $b) {
                return strcmp($a->filename, $b->filename);
            });
            $signature = count($xdocs) . "\n";
            foreach ($xdocs as $doc)
                $signature .= $doc->filename . "\n" . $doc->text_hash() . "\n";
            $sha1 = sha1($signature, false);
            // maybe zipfile with that signature already exists
            $dstore_prefix = Filer::docstore_fixed_prefix($this->conf->opt("docstore"));
            $zfn = $dstore_prefix . "tmp/" . $sha1 . ".zip";
            if (Filer::prepare_docstore($dstore_prefix, $zfn)) {
                $this->filestore = $zfn;
                if (file_exists($zfn)) {
                    if (@filemtime($zfn) < $Now - 21600)
                        @touch($zfn);
                    return $this->_make_document();
                }
            }
        }

        if (!($tmpdir = $this->tmpdir()))
            return $this->_make_document("Could not create temporary directory.");
        if (!($zipcmd = $this->conf->opt("zipCommand", "zip")))
            return $this->_make_document("<code>zip</code> is not supported on this installation.");

        DocumentInfo::prefetch_content(array_slice($this->_docs, $this->_saveindex));
        while ($this->_saveindex < count($this->_docs))
            $this->_save_one();

        if (!$this->filestore) {
            for ($n = 0; isset($this->_files["_hotcrp$n.zip"]); ++$n)
                /* skip */;
            $this->filestore = $tmpdir . "/_hotcrp$n.zip";
        }
        $topfiles = array_filter(array_keys($this->_files), function ($f) {
            return strpos($f, "/") === false;
        });
        $opts = count($topfiles) === count($this->_files) ? "-q" : "-rq";

        set_time_limit(60);
        $command = "cd $tmpdir; $zipcmd $opts " . escapeshellarg($this->filestore) . " " . join(" ", array_map("escapeshellarg", $topfiles));
        $out = system("$command 2>&1", $status);
        if ($status == 0 && file_exists($this->filestore))
            return $this->_make_document();

        if ($status != 0)
            return $this->_make_document("<code>zip</code> returned an error. Its output: <pre>" . htmlspecialchars($out) . "</pre>");
        else
            return $this->_make_document("<code>zip</code> result unreadable or empty. Its output: <pre>" . htmlspecialchars($out) . "</pre>");
    }

    function download() {
        $doc = $this->create();
        if (!$doc->error) {
            set_time_limit(180); // large zip files might download slowly
            $this->download_headers();
            Filer::download_file($doc->content_file);
        }
        $this->clean();
        return $doc;
    }
}
