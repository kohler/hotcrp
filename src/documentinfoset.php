<?php
// documentinfoset.php -- HotCRP document set
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class DocumentInfoSet_ZipInfo {
    /** @var ?int */
    public $local_offset;
    /** @var string */
    public $localh;
    /** @var int */
    public $date = 0;
    /** @var int */
    public $time = 0;
    /** @var int */
    public $compression;
    /** @var ?string */
    public $compressed;
    /** @var int */
    public $compressed_length;
    /** @var ?int */
    public $central_offset;
    /** @var ?string */
    public $centralh;

    function local_end_offset() {
        return $this->local_offset + strlen($this->localh) + $this->compressed_length;
    }
    function central_end_offset() {
        return $this->central_offset + strlen($this->centralh);
    }
}

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
    /** @var ?list<string> */
    private $_dirfn;
    /** @var ?string */
    private $_filestore;
    /** @var list<DocumentInfoSet_ZipInfo> */
    private $_zipi;
    /** @var ?string */
    private $_signature;

    /** @param ?string $filename */
    function __construct($filename = null) {
        $this->conf = Conf::$main;
        $this->_filename = $filename;
        assert(strpos($filename ?? "", "/") === false);
    }
    /** @param string $mimetype */
    function set_mimetype($mimetype) {
        $this->_mimetype = $mimetype;
    }

    function add(DocumentInfo $doc) {
        return $this->add_as($doc, $doc->filename ?? "");
    }
    /** @return false */
    private function _add_fail(DocumentInfo $doc, $fn) {
        error_log("{$this->conf->dbname}: failing to add #{$doc->paperStorageId} at $fn");
        return false;
    }
    /** @param string $fn */
    function add_as(DocumentInfo $doc, $fn) {
        if ($this->_filename) {
            assert(!$doc->error);
            $slash = strpos($fn, "/");
            if ($doc->error
                || $fn === ""
                || ($slash !== false && str_ends_with($fn, "/"))
                || ($slash !== false && strpos($fn, "//") !== false)
                || !preg_match('/\A[^.*\/\s\000-\037\\\\\'"][^*\000-\037\\\\\'"]*\z/', $fn)) {
                return $this->_add_fail($doc, $fn);
            }
            while ($slash !== false) {
                $dir = substr($fn, 0, $slash);
                if (in_array($dir, $this->ufn)) {
                    return $this->_add_fail($doc, $fn);
                } else if (!in_array($dir, $this->_dirfn ?? [])) {
                    $this->_dirfn[] = $dir;
                }
            }
            if ($this->_dirfn !== null && in_array($fn, $this->_dirfn)) {
                return $this->_add_fail($doc, $fn);
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
     * @param ?string $mimetype
     * @param ?int $timestamp */
    function add_string_as($text, $fn, $mimetype = null, $timestamp = null) {
        return $this->add_as(new DocumentInfo([
            "content" => $text, "size" => strlen($text),
            "filename" => $fn, "mimetype" => $mimetype ?? "text/plain",
            "timestamp" => $timestamp ?? Conf::$now
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
    private function _hotzip_progress() {
        // assign local headers
        $this->_zipi = $this->_zipi ?? [];
        $i = count($this->_zipi);
        $offset = $i === 0 ? 0 : $this->_zipi[$i - 1]->local_end_offset();
        while ($i !== count($this->docs)) {
            $this->_zipi[] = $zi = new DocumentInfoSet_ZipInfo;
            $doc = $this->docs[$i];
            $fn = $this->ufn[$i];
            $zi->local_offset = $offset;
            if ($doc->compressible()) {
                $zi->compression = 8;
                $zi->compressed = gzdeflate($doc->content());
                $zi->compressed_length = strlen($zi->compressed);
            } else {
                $zi->compression = 0;
                $zi->compressed_length = $doc->size();
            }
            if ($doc->timestamp > 1) {
                $dt = new DateTime("@" . (int) $doc->timestamp, $doc->conf->timezone());
                if (($y = (int) $dt->format("Y")) > 1980) {
                    $zi->date = (int) $dt->format("j")
                        | ((int) $dt->format("n") << 5)
                        | (($y - 1980) << 9);
                    $zi->time = ((int) $dt->format("s") >> 1)
                        | ((int) $dt->format("i") << 5)
                        | ((int) $dt->format("G") << 10);
                }
            }
            $zi->localh = pack("VvvvvvVVVvv",
                0x04034b50,    // local file header signature
                10,            // version needed to extract
                1 << 11,       // general purpose bit flag
                $zi->compression, // compression method
                $zi->time,     // last mod file time
                $zi->date,     // last mod file date
                $doc->integer_crc32(), // crc-32
                $zi->compressed_length, // compressed size
                $doc->size(),  // uncompressed size
                strlen($fn),   // file name length
                0              // extra field length
            ) . $fn;
            $offset = $zi->local_end_offset();
            ++$i;
        }
    }
    private function _hotzip_final() {
        // assign central headers
        assert(count($this->_zipi) === count($this->docs));
        $n = count($this->_zipi);
        $offset = $n === 0 ? 0 : $this->_zipi[$n - 1]->local_end_offset();
        $central_offset = $offset;
        for ($i = 0; $i !== $n; ++$i) {
            $zi = $this->_zipi[$i];
            $doc = $this->docs[$i];
            $fn = $this->ufn[$i];
            $zi->central_offset = $offset;
            $zi->centralh = pack("VvvvvvvVVVvvvvvVV",
                0x02014b50,     // central file header signature
                0x31E,          // version made by
                10,             // version needed to extract
                1 << 11,        // general purpose bit flag
                $zi->compression, // compression method
                $zi->time,      // last mod file time
                $zi->date,      // last mod file date
                $doc->integer_crc32(),  // crc-32
                $zi->compressed_length, // compressed size
                $doc->size(),   // uncompressed size
                strlen($fn),    // file name length
                0,              // extra field length
                0,              // file comment length
                0,              // disk number start
                0,              // internal file attributes
                0100644 << 16,  // external file attributes
                $zi->local_offset // relative offset of local header
            ) . $fn;
            $offset += strlen($zi->centralh);
        }
        // assign final central offset
        $this->_zipi[] = $zi = new DocumentInfoSet_ZipInfo;
        $zi->central_offset = $offset;
        $zi->centralh = pack("VvvvvVVv",
            0x06054b50,       // end of central dir signature
            0,                // number of this disk
            0,                // number of the disk with the start of the central dir
            count($this->docs), // total number of entries in the central dir on this disk
            count($this->docs), // total number of entries in the central dir
            $offset - $central_offset, // size of the central directory
            $central_offset, // offset of start of central directory
            0                 // .ZIP file comment length
        );
    }
    /** @return int */
    private function _hotzip_filesize() {
        $nz = count($this->_zipi);
        assert($nz === count($this->docs) + 1);
        return $this->_zipi[$nz - 1]->central_end_offset();
    }
    private function _hotzip_make() {
        DocumentInfo::prefetch_content($this->docs);
        $this->_hotzip_progress();
        if (!empty($this->_errors_html)) {
            $this->add_string_as(Text::html_to_text(join("\n", $this->_errors_html) . "\n"), "README-warnings.txt");
            $this->_hotzip_progress();
        }
        $this->_hotzip_final();
        assert($this->_hotzip_filesize() < 0xFFFFFFFF);
    }
    /** @return string */
    private function content_signature() {
        if ($this->_signature === null) {
            $xdocs = $this->docs;
            usort($xdocs, function ($a, $b) {
                return strcmp($a->member_filename(), $b->member_filename());
            });
            $s = count($xdocs) . "\n";
            foreach ($xdocs as $doc) {
                $s .= $doc->member_filename() . "\n" . $doc->text_hash() . "\n";
            }
            if (!empty($this->_errors_html)) {
                $s .= "README-warnings.txt\nsha2-" . hash("sha256", join("\n", $this->_errors_html)) . "\n";
            }
            $this->_signature = "content.sha2-" . hash("sha256", $s);
        }
        return $this->_signature;
    }
    /** @return ?DocumentInfo */
    function make_zip_document() {
        if (($dstore_tmp = Filer::docstore_tmpdir($this->conf))) {
            $this->_filestore = $dstore_tmp . $this->content_signature() . ".zip";
            // maybe zipfile with that signature already exists
            if (file_exists($this->_filestore)) {
                if (@filemtime($this->_filestore) < Conf::$now - 21600) {
                    @touch($this->_filestore);
                }
                return $this->_make_success_document();
            }
        }

        // otherwise, need to create new .zip
        if (!$this->_filestore) {
            if (!($tmpdir = $this->_tmpdir())) {
                $this->add_error_html("Cannot create temporary directory.");
                return null;
            }
            $this->_filestore = $tmpdir . "/_hotcrp.zip";
        }

        if (!($out = fopen($this->_filestore . "~", "wb"))) {
            $this->add_error_html("Cannot create temporary file.");
            return null;
        }

        $this->_hotzip_make();

        // write data to stream
        for ($i = 0; $i !== count($this->docs); ++$i) {
            $zi = $this->_zipi[$i];
            $doc = $this->docs[$i];
            $sz = fwrite($out, $zi->localh);
            if ($zi->compressed !== null) {
                $sz += fwrite($out, $zi->compressed);
            } else if (($f = $doc->available_content_file())) {
                $in = fopen($f, "r");
                $sz += stream_copy_to_stream($in, $out);
                fclose($in);
            } else {
                $sz += fwrite($out, $doc->content());
            }
            if ($sz !== $zi->local_end_offset() - $zi->local_offset) {
                $this->add_error_html("Failure creating temporary file (#$i @{$zi->local_offset}).");
                fclose($out);
                return null;
            }
        }
        $sz = 0;
        foreach ($this->_zipi as $zi) {
            $sz += fwrite($out, $zi->centralh);
        }
        fclose($out);
        if ($sz !== $this->_hotzip_filesize() - $this->_zipi[0]->central_offset) {
            $this->add_error_html("Failure creating temporary file (dir @{$this->_zipi[0]->central_offset}).");
            return null;
        }

        // success
        rename($this->_filestore . "~", $this->_filestore);
        return $this->_make_success_document();
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
    private function _download_directly($opts = []) {
        if (isset($opts["if-none-match"])
            && $opts["if-none-match"] === "\"" . $this->content_signature() . "\"") {
            header("HTTP/1.1 304 Not Modified");
            return true;
        }

        $this->_hotzip_make();
        $filesize = $this->_hotzip_filesize();

        // Print headers
        header("Content-Type: " . Mimetype::type_with_charset($this->_mimetype));
        if (isset($opts["attachment"])) {
            $attachment = $opts["attachment"];
        } else {
            $attachment = !Mimetype::disposition_inline($this->_mimetype);
        }
        header("Content-Disposition: " . ($attachment ? "attachment" : "inline") . "; filename=" . mime_quote_string($this->_filename));
        if (zlib_get_coding_type() === false) {
            header("Content-Length: $filesize");
        }
        if ($opts["cacheable"] ?? false) {
            header("Cache-Control: max-age=315576000, private");
            header("Expires: " . gmdate("D, d M Y H:i:s", Conf::$now + 315576000) . " GMT");
        }
        // reduce likelihood of XSS attacks in IE
        header("X-Content-Type-Options: nosniff");
        header("ETag: \"" . $this->content_signature() . "\"");
        if ($filesize > 2000000) {
            header("X-Accel-Buffering: no");
        }

        for ($i = 0; $i !== count($this->docs); ++$i) {
            set_time_limit(120);
            $zi = $this->_zipi[$i];
            $doc = $this->docs[$i];
            echo $zi->localh;
            if ($zi->compressed !== null) {
                echo $zi->compressed;
                $sz = strlen($zi->compressed);
            } else if (($f = $doc->available_content_file())) {
                $sz = readfile($f);
            } else {
                $s = $doc->content();
                echo $s;
                $sz = strlen($s);
            }
            if ($sz !== $zi->compressed_length) {
                throw new Exception("Failure creating temporary file (#$i @{$zi->local_offset}).");
            }
        }
        foreach ($this->_zipi as $zi) {
            echo $zi->centralh;
        }
        return true;
    }

    /** @return bool */
    function download($opts = []) {
        if (!$this->_filename) {
            throw new Exception("trying to download blank-named DocumentInfoSet");
        }
        if (count($this->docs) === 1
            && empty($this->_errors_html)
            && ($opts["single"] ?? false)) {
            if ($this->docs[0]->download($opts)) {
                return true;
            } else {
                $this->add_error_html($this->docs[0]->error_html);
                return false;
            }
        } else {
            return $this->_download_directly($opts);
        }
    }
}
