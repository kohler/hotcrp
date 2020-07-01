<?php
// documentinfoset.php -- HotCRP document set
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class DocumentInfoSet implements ArrayAccess, IteratorAggregate, Countable {
    /** @var Conf */
    private $conf;
    /** @var list<string> */
    private $ufn = [];
    /** @var list<DocumentInfo> */
    private $docs = [];
    /** @var list<string> */
    private $_errors_html = [];
    /** @var ?string */
    private $_filename;
    /** @var ?string */
    private $_mimetype;
    /** @var ?string|false */
    private $_tmpdir;
    /** @var int */
    private $_saveindex = 0;
    /** @var ?string */
    private $_filestore;

    /** @param ?string $filename */
    function __construct($filename = null) {
        $this->conf = Conf::$main;
        $this->_filename = $filename;
    }
    /** @param string $mimetype */
    function set_mimetype($mimetype) {
        $this->_mimetype = $mimetype;
    }

    function add(DocumentInfo $doc) {
        return $this->add_as($doc, $doc->filename ?? "");
    }
    /** @param string $fn */
    function add_as(DocumentInfo $doc, $fn) {
        if ($this->_filename) {
            assert(!$doc->error);
            if ($doc->error
                || $fn === ""
                || str_ends_with($fn, "/")
                || strpos($fn, "//") !== false
                || !preg_match('/\A[^.*\/\s\000-\037\\\\\'"][^*\000-\037\\\\\'"]*\z/', $fn)) {
                error_log("{$this->conf->dbname}: failing to add #{$doc->paperStorageId} at $fn");
                return false;
            }
        }
        while ($fn !== "" && in_array($fn, $this->ufn)) {
            if (preg_match('/\A(.*\()(\d+)(\)(?:\.\w+|))\z/', $fn, $m)) {
                $fn = $m[1] . ((int) $m[2] + 1) . $m[3];
            } else if (preg_match('/\A(.*?)(\.\w+|)\z/', $fn, $m) && $m[1] !== "") {
                $fn = $m[1] . " (1)" . $m[2];
            } else {
                $fn .= " (1)";
            }
        }
        $this->ufn[] = $fn;
        $this->docs[] = $doc->with_member_filename($fn);
        return true;
    }
    /** @param string $text
     * @param string $fn
     * @param ?string $mimetype */
    function add_string_as($text, $fn, $mimetype = null) {
        return $this->add_as(new DocumentInfo([
            "content" => $text, "size" => strlen($text),
            "filename" => $fn, "mimetype" => $mimetype ?? "text/plain"
        ], $this->conf), $fn);
    }
    /** @param string $error_html */
    function add_error_html($error_html) {
        $this->_errors_html[] = $error_html;
    }

    /** @return list<DocumentInfo> */
    function as_list() {
        return $this->docs;
    }
    /** @return list<int> */
    function document_ids() {
        return array_map(function ($doc) { return $doc->paperStorageId; }, $this->docs);
    }
    /** @return bool */
    function is_empty() {
        return empty($this->docs);
    }
    /** @return int */
    function size() {
        return count($this->docs);
    }
    /** @return int */
    function count() {
        return count($this->docs);
    }
    /** @return bool */
    function has_errors() {
        return !empty($this->_errors_html);
    }
    /** @return list<string> */
    function error_texts() {
        return $this->_errors_html;
    }
    /** @param int $i
     * @return ?DocumentInfo */
    function document_by_index($i) {
        return $this->docs[$i] ?? null;
    }
    /** @param int $i
     * @return DocumentInfo */
    function checked_document_by_index($i) {
        $doc = $this->docs[$i] ?? null;
        if (!$doc) {
            throw new Exception("DocumentInfoSet::checked_document_by_index($i) failure");
        }
        return $doc;
    }
    /** @param string $fn
     * @return ?DocumentInfo */
    function document_by_filename($fn) {
        $i = array_search($fn, $this->ufn);
        return $i !== false && $fn !== "" ? $this->docs[$i] : null;
    }
    /** @param int $i
     * @return ?string */
    function filename_by_index($i) {
        return $this->ufn[$i] ?? null;
    }
    /** @return Iterator<DocumentInfo> */
    function getIterator() {
        return new ArrayIterator($this->docs);
    }
    /** @param int|string $offset
     * @return bool */
    function offsetExists($offset) {
        return is_int($offset)
            ? isset($this->docs[$offset])
            : $offset !== "" && in_array($offset, $this->ufn);
    }
    /** @param int|string $offset
     * @return ?DocumentInfo */
    function offsetGet($offset) {
        if (!is_int($offset) && $offset !== "") {
            $offset = array_search($offset, $this->ufn);
        }
        return is_int($offset) ? $this->docs[$offset] ?? null : null;
    }
    function offsetSet($offset, $value) {
        throw new Exception("invalid DocumentInfoSet::offsetSet");
    }
    function offsetUnset($offset) {
        throw new Exception("invalid DocumentInfoSet::offsetUnset");
    }

    /** @return string|false */
    private function _tmpdir() {
        if ($this->_tmpdir === null
            && ($this->_tmpdir = tempdir()) === false) {
            $this->_errors_html[] = "Could not create temporary directory.";
        }
        return $this->_tmpdir;
    }
    /** @return bool */
    private function _store_one() {
        assert($this->_saveindex < count($this->docs));
        if (!($tmpdir = $this->_tmpdir())) {
            return false;
        }
        $doc = $this->docs[$this->_saveindex];
        $fn = $this->ufn[$this->_saveindex];
        ++$this->_saveindex;
        // create parent directories
        for ($p = strpos($fn, "/"); $p !== false; $p = strpos($fn, "/", $p + 1)) {
            $dfn = $tmpdir . "/" . substr($fn, 0, $p);
            if (!is_dir($dfn)
                && (file_exists($dfn) || !@mkdir($dfn, 0777))) {
                $this->_errors_html[] = htmlspecialchars($fn) . ": Filename conflicts with directory.";
                return false;
            }
        }
        // store file in temporary directory
        $zfn = $tmpdir . "/" . $fn;
        if (($path = $doc->available_content_file())
            && @symlink($path, $zfn)) {
            // OK
        } else {
            $content = $doc->content();
            $trylen = file_put_contents($zfn, $content);
            if ($trylen !== strlen($content)) {
                clean_tempdirs();
                $trylen = file_put_contents($zfn, $content);
            }
            if ($trylen !== strlen($content)) {
                $this->_errors_html[] = htmlspecialchars($fn) . ": Could not save.";
                return false;
            }
        }
        return true;
    }
    /** @return ?DocumentInfo */
    function make_zip_document() {
        if (($dstore_tmp = Filer::docstore_tmpdir($this->conf))) {
            // calculate hash for zipfile contents
            $xdocs = $this->docs;
            usort($xdocs, function ($a, $b) {
                return strcmp($a->member_filename(), $b->member_filename());
            });
            $signature = count($xdocs) . "\n";
            foreach ($xdocs as $doc) {
                $signature .= $doc->filename . "\n" . $doc->text_hash() . "\n";
            }
            if (!empty($this->_errors_html)) {
                $signature .= "README-warnings.txt\nsha2-" . hash("sha256", join("\n", $this->_errors_html)) . "\n";
            }
            $this->_filestore = $dstore_tmp . hash("sha256", $signature, false) . ".zip";
            // maybe zipfile with that signature already exists
            if (file_exists($this->_filestore)) {
                if (@filemtime($this->_filestore) < Conf::$now - 21600) {
                    @touch($this->_filestore);
                }
                return $this->_make_success_document();
            }
        }

        if (!($tmpdir = $this->_tmpdir())) {
            $this->add_error_html("Could not create temporary directory.");
            return null;
        } else if (!($zipcmd = $this->conf->opt("zipCommand", "zip"))) {
            $this->add_error_html("<code>zip</code> is not supported on this installation.");
            return null;
        }

        DocumentInfo::prefetch_content(array_slice($this->docs, $this->_saveindex));
        if (!empty($this->_errors_html)) {
            $this->add_string_as(Text::html_to_text(join("\n", $this->_errors_html) . "\n"), "README-warnings.txt");
        }
        while ($this->_saveindex < count($this->docs)) {
            $this->_store_one();
        }

        if (!$this->_filestore) {
            for ($n = 0; in_array("_hotcrp$n.zip", $this->ufn); ++$n) {
            }
            $this->_filestore = $tmpdir . "/_hotcrp$n.zip";
        }
        $topfiles = array_filter($this->ufn, function ($f) {
            return strpos($f, "/") === false;
        });
        $zipopts = count($topfiles) === count($this->ufn) ? "-q" : "-rq";

        set_time_limit(60);
        $command = "cd $tmpdir; $zipcmd $zipopts " . escapeshellarg($this->_filestore) . " " . join(" ", array_map("escapeshellarg", $topfiles));
        $out = system("$command 2>&1", $status);
        if ($status == 0 && file_exists($this->_filestore)) {
            return $this->_make_success_document();
        } else if ($status != 0) {
            $this->add_error_html("<code>zip</code> returned an error. Its output: <pre>" . htmlspecialchars($out) . "</pre>");
            return null;
        } else {
            $this->add_error_html("<code>zip</code> result unreadable or empty. Its output: <pre>" . htmlspecialchars($out) . "</pre>");
            return null;
        }
    }
    /** @return DocumentInfo */
    private function _make_success_document() {
        return new DocumentInfo([
            "filename" => $this->_filename,
            "mimetype" => $this->_mimetype ?? "application/zip",
            "documentType" => DTYPE_EXPORT,
            "content_file" => $this->_filestore
        ], $this->conf);
    }

    /** @return bool */
    function download($opts = []) {
        if (!$this->_filename) {
            throw new Exception("trying to download blank-named DocumentInfoSet");
        }
        if (count($this->docs) === 1
            && empty($this->_errors_html)
            && ($opts["single"] ?? false)) {
            $doc = $this->docs[0];
        } else if (($doc = $this->make_zip_document())) {
            set_time_limit(180); // large zip files might download slowly
        } else {
            return false;
        }
        if ($doc->download($opts)) {
            return true;
        } else {
            $this->add_error_html($doc->error_html);
            return false;
        }
    }
}
