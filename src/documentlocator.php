<?php
// documentlocator.php -- HotCRP API helper for finding documents in ZIP/form
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class DocumentLocator {
    /** @var ?Qrequest */
    public $attachment_qreq;
    /** @var ?ZipArchive */
    public $ziparchive;
    /** @var ?string */
    public $ziparchive_docdir;

    const M_ONE = 1;
    const M_MULTI = 2;
    const M_MATCH = 4;

    /** @return ?string */
    function set_zipfile($file) {
        $this->ziparchive = new ZipArchive;
        if ($this->ziparchive->open($file) !== true) {
            throw new CommandLineException("{$file}: Invalid zip");
        }
        list($this->ziparchive_docdir, $json) = self::analyze_zip_contents($this->ziparchive);
        return $json;
    }

    /** An `upload` capability names the request payload document, and is obeyed
     * whenever present (e.g. a `?upload=` query parameter), regardless of the
     * request body's content type; the body is used only when `upload` is absent.
     * @return ?DocumentInfo */
    function uploaded_document(Qrequest $qreq) {
        if (!isset($qreq->upload)) {
            return null;
        }
        return DocumentInfo::make_capability($qreq->conf(), $qreq->upload);
    }

    /** @return array{object|list<object>,1|2|4} */
    function parse_json_request(Qrequest $qreq, $mode) {
        // check for uploaded file
        if (($updoc = $this->uploaded_document($qreq))) {
            $ct = $updoc->mimetype;
            $ct_form = false;
        } else if (isset($qreq->upload)) {
            JsonResult::make_missing_error("upload", "<0>Upload not found")->complete();
        } else {
            $ct = $qreq->body_content_type();
            $ct_form = $ct === null || Mimetype::is_form($ct);
        }

        // from here on, expect JSON
        if ($ct === "application/json") {
            $jsonstr = $updoc ? $updoc->content() : $qreq->body();
        } else if ($ct === "application/zip") {
            $this->ziparchive = new ZipArchive;
            $cf = $updoc ? $updoc->content_file() : $qreq->body_file(".zip");
            if (!$cf) {
                JsonResult::make_error(500, "<0>Uploaded content unreadable")->complete();
            }
            $ec = $this->ziparchive->open($cf);
            if ($ec !== true) {
                JsonResult::make_error(400, "<0>ZIP error " . json_encode($ec))->complete();
            }
            list($this->ziparchive_docdir, $jsonname) = self::analyze_zip_contents($this->ziparchive);
            if (!$jsonname) {
                JsonResult::make_error(400, "<0>ZIP `data.json` not found")->complete();
            }
            $jsonstr = $this->ziparchive->getFromName($jsonname);
        } else if ($ct_form) {
            $jsonstr = $qreq->json;
            $this->attachment_qreq = $qreq;
        } else {
            JsonResult::make_error(400, "<0>Unexpected content type")->complete();
            $jsonstr = ""; // unreachable - shut up Phan
        }

        // read JSON, check format
        $jp = Json::try_decode((string) $jsonstr);
        if (is_object($jp)) {
            if (isset($qreq->q)
                && ($mode & self::M_MATCH) !== 0) {
                $mode = self::M_MATCH;
            } else if (($mode & self::M_ONE) !== 0) {
                $mode = self::M_ONE;
            } else {
                $jp = [$jp];
                $mode = self::M_MULTI;
            }
        } else if (is_array($jp)) {
            if (($mode & self::M_MULTI) !== 0) {
                $mode = self::M_MULTI;
            } else if (($mode & self::M_ONE) !== 0
                       && count($jp) === 1
                       && is_object($jp[0])) {
                $jp = $jp[0];
                $mode = self::M_ONE;
            } else {
                JsonResult::make_error(400, "<0>Expected object")->complete();
            }
        } else if ($jp === null) {
            JsonResult::make_error(400, "<0>Invalid JSON (" . Json::last_error_msg() . ")")->complete();
        } else {
            JsonResult::make_error(400, $mode === self::M_MULTI ? "<0>Expected array of objects" : "<0>Expected object")->complete();
        }
        return [$jp, $mode];
    }


    /** @param string $fname
     * @return bool */
    static function should_skip_zip_filename($fname) {
        return preg_match('/(?:\A|\/)(?:__MACOSX|\.[^\/]*+|\$RECYCLE\.BIN|\#[^\/]*\#|[^\/]*~)(?:\z|\/)/', $fname);
    }

    /** @return array{string,?string} */
    static function analyze_zip_contents(ZipArchive $zip) {
        // find common directory prefix
        $dirpfx = null;
        $xjsons = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $name = $zip->getNameIndex($i);
            if (self::should_skip_zip_filename($name)) {
                continue;
            }
            if ($dirpfx === null) {
                $xslash = (int) strrpos($name, "/");
                $dirpfx = $xslash > 0 ? substr($name, 0, $xslash + 1) : "";
            }
            while ($dirpfx !== "" && !str_starts_with($name, $dirpfx)) {
                $xslash = (int) strrpos($dirpfx, "/", -2);
                $dirpfx = $xslash > 0 ? substr($dirpfx, 0, $xslash + 1) : "";
            }
            if (str_ends_with($name, ".json")) {
                $xjsons[] = $name;
            }
        }

        // find JSONs
        $datas = $jsons = [];
        foreach ($xjsons as $name) {
            if (strpos($name, "/", strlen($dirpfx)) !== false) {
                continue;
            }
            $jsons[] = $name;
            if (preg_match('/\G(?:|.*[-_])data\.json\z/', $name, $m, 0, strlen($dirpfx))) {
                $datas[] = $name;
            }
        }

        if (count($datas) === 1) {
            return [$dirpfx, $datas[0]];
        } else if (count($jsons) === 1) {
            return [$dirpfx, $jsons[0]];
        }
        return [$dirpfx, null];
    }


    /** @param object $docj
     * @return bool */
    private function apply_zip_content_file($docj, DocumentImporter $importer) {
        $filename = $this->ziparchive_docdir . $docj->content_file;
        $stat = $this->ziparchive->statName($filename);
        if (!$stat) {
            $importer->error("<0>{$filename}: File not found");
            return false;
        }
        // use resources to store large files
        if ($stat["size"] > 50000000) {
            if (PHP_VERSION_ID >= 80200) {
                $content = $this->ziparchive->getStreamIndex($stat["index"]);
            } else {
                $content = $this->ziparchive->getStream($filename);
            }
        } else {
            $content = $this->ziparchive->getFromIndex($stat["index"]);
        }
        if ($content === false) {
            $importer->error("<0>{$filename}: File not found");
            return false;
        }
        if (is_string($content)) {
            $docj->content = $content;
            $docj->content_file = null;
        } else {
            $docj->content_file = $content;
        }
        self::apply_docj_filename($docj, $filename);
        return true;
    }

    /** @param object $docj
     * @param QrequestFile $qf */
    private static function apply_qrequest_file($docj, $qf) {
        if ($qf->content !== null) {
            $docj->content = $qf->content;
            $docj->content_file = null;
        } else {
            $docj->content_file = $qf->tmp_name;
        }
        if (!isset($docj->size) && isset($qf->size)) {
            $docj->size = $qf->size;
        }
        if (!isset($docj->mimetype) && isset($qf->type)) {
            $docj->mimetype = $qf->type;
        }
        if (!isset($docj->filename) && isset($qf->name)) {
            self::apply_docj_filename($docj, $qf->name);
        }
    }

    /** @param object $docj
     * @param string $filename */
    private static function apply_docj_filename($docj, $filename) {
        if (!isset($docj->filename)) {
            $slash = strpos($filename, "/");
            $docj->filename = $slash !== false ? substr($filename, $slash + 1) : $filename;
        }
    }

    /** Resolve a `docs` entry's `content_file` reference against a ZIP archive or
     * an uploaded form file.
     * @param object $docj
     * @param int $dt
     * @param DocumentImporter $importer
     * @return ?bool */
    function on_document_import($docj, $dt, $importer) {
        if ($docj instanceof DocumentInfo
            || !isset($docj->content_file)) {
            return null;
        }
        if (is_string($docj->content_file)) {
            if ($this->ziparchive) {
                return $this->apply_zip_content_file($docj, $importer);
            } else if ($this->attachment_qreq
                       && ($qf = $this->attachment_qreq->file($docj->content_file))) {
                return self::apply_qrequest_file($docj, $qf);
            }
        }
        unset($docj->content_file);
        return null;
    }
}
