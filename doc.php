<?php // -*- mode: php -*-
// doc -- HotCRP paper download page
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

require_once("src/initweb.php");

function document_error($status, $msg) {
    global $Conf, $Me, $Qreq;
    if (str_starts_with($status, "403") && $Me->is_empty()) {
        $Me->escape();
        exit;
    } else if (str_starts_with($status, "5")) {
        $navpath = $Qreq->path();
        error_log($Conf->dbname . ": bad doc $status $msg " . json_encode($Qreq) . ($navpath ? " @$navpath" : "") . ($Me ? " {$Me->email}" : "") . (empty($_SERVER["HTTP_REFERER"]) ? "" : " R[" . $_SERVER["HTTP_REFERER"] . "]"));
    }

    header("HTTP/1.1 $status");
    if (isset($Qreq->fn)) {
        json_exit(["ok" => false, "error" => $msg ? : "Internal error."]);
    } else {
        $Conf->header("Download", null);
        $msg && Conf::msg_error($msg);
        $Conf->footer();
        exit;
    }
}

function document_history_element(DocumentInfo $doc) {
    $pj = ["hash" => $doc->text_hash(), "at" => $doc->timestamp, "mimetype" => $doc->mimetype];
    if ($doc->size) {
        $pj["size"] = $doc->size;
    }
    if ($doc->filename) {
        $pj["filename"] = $doc->filename;
    }
    return (object) $pj;
}

function document_history(PaperInfo $prow, $dtype) {
    global $Me;
    $docs = $prow->documents($dtype);

    $pjs = $actives = [];
    foreach ($docs as $doc) {
        $pj = document_history_element($doc);
        $pj->active = true;
        $actives[$doc->paperStorageId] = true;
        $pjs[] = $pj;
    }

    if ($Me->can_view_document_history($prow)
        && $dtype >= DTYPE_FINAL) {
        $result = $prow->conf->qe("select paperId, paperStorageId, timestamp, mimetype, sha1, filename, infoJson, size from PaperStorage where paperId=? and documentType=? and filterType is null order by paperStorageId desc", $prow->paperId, $dtype);
        while (($doc = DocumentInfo::fetch($result, $prow->conf, $prow))) {
            if (!get($actives, $doc->paperStorageId))
                $pjs[] = document_history_element($doc);
        }
        Dbl::free($result);
    }

    return $pjs;
}

function document_download($qreq) {
    global $Conf, $Me;

    try {
        $dr = new DocumentRequest($qreq, $qreq->path(), $Conf);
    } catch (Exception $e) {
        document_error("404 Not Found", htmlspecialchars($e->getMessage()));
    }

    if (($whyNot = $dr->perm_view_document($Me)))
        document_error(isset($whyNot["permission"]) ? "403 Forbidden" : "404 Not Found", whyNotText($whyNot));
    $prow = $dr->prow;
    $want_docid = $request_docid = (int) $dr->docid;

    // history
    if ($qreq->fn === "history") {
        json_exit(["ok" => true, "result" => document_history($prow, $dr->dtype)]);
    }

    if (!isset($qreq->version) && isset($qreq->hash)) {
        $qreq->version = $qreq->hash;
    }

    // time
    if (isset($qreq->at) && !isset($qreq->version) && $dr->dtype >= DTYPE_FINAL) {
        if (ctype_digit($qreq->at)) {
            $time = intval($qreq->at);
        } else if (!($time = $Conf->parse_time($qreq->at))) {
            $time = $Now;
        }
        $want_pj = null;
        foreach (document_history($prow, $dr->dtype) as $pj) {
            if ($want_pj && $want_pj->at <= $time && $pj->at < $want_pj->at) {
                break;
            } else {
                $want_pj = $pj;
            }
        }
        if ($want_pj) {
            $qreq->version = $want_pj->hash;
        }
    }

    // version
    if (isset($qreq->version) && $dr->dtype >= DTYPE_FINAL) {
        $version_hash = Filer::hash_as_binary(trim($qreq->version));
        if (!$version_hash) {
            document_error("404 Not Found", "No such version.");
        }
        $want_docid = $Conf->fetch_ivalue("select max(paperStorageId) from PaperStorage where paperId=? and documentType=? and sha1=? and filterType is null", $dr->paperId, $dr->dtype, $version_hash);
        if ($want_docid !== null && $Me->can_view_document_history($prow)) {
            $request_docid = $want_docid;
        }
    }

    if ($dr->attachment && !$request_docid) {
        $doc = $prow->attachment($dr->dtype, $dr->attachment);
    } else {
        $doc = $prow->document($dr->dtype, $request_docid);
    }
    if ($want_docid !== 0 && (!$doc || $doc->paperStorageId != $want_docid)) {
        document_error("404 Not Found", "No such version.");
    } else if (!$doc) {
        document_error("404 Not Found", "No such " . ($dr->attachment ? "attachment" : "document") . " “" . htmlspecialchars($dr->req_filename) . "”.");
    }

    // pass through filters
    foreach ($dr->filters as $filter) {
        $doc = $filter->apply($doc, $prow) ? : $doc;
    }

    // check for contents request
    if ($qreq->fn === "listing" || $qreq->fn === "consolidatedlisting") {
        if (!$doc->is_archive()) {
            json_exit(["ok" => false, "error" => "That file is not an archive."]);
        } else if (($listing = $doc->archive_listing(65536)) === false) {
            json_exit(["ok" => false, "error" => $doc->error ? $doc->error_html : "Internal error."]);
        } else {
            $listing = ArchiveInfo::clean_archive_listing($listing);
            if ($qreq->fn === "consolidatedlisting")
                $listing = join(", ", ArchiveInfo::consolidate_archive_listing($listing));
            json_exit(["ok" => true, "result" => $listing]);
        }
    }

    // check for If-Not-Modified
    if ($doc->has_hash()) {
        $ifnonematch = null;
        if (function_exists("getallheaders")) {
            foreach (getallheaders() as $k => $v)
                if (strcasecmp($k, "If-None-Match") == 0)
                    $ifnonematch = $v;
        } else {
            $ifnonematch = get($_SERVER, "HTTP_IF_NONE_MATCH");
        }
        if ($ifnonematch && $ifnonematch === "\"" . $doc->text_hash() . "\"") {
            header("HTTP/1.1 304 Not Modified");
            exit;
        }
    }

    // Actually download paper.
    session_write_close();      // to allow concurrent clicks
    $opts = ["attachment" => cvtint($qreq->save) > 0];
    if ($doc->has_hash() && ($x = $qreq->hash) && $doc->check_text_hash($x)) {
        $opts["cacheable"] = true;
    }
    if ($Conf->download_documents([$doc], $opts)) {
        DocumentInfo::log_download_activity([$doc], $Me);
        exit;
    }

    document_error("500 Server Error", null);
}

$Me->add_overrides(Contact::OVERRIDE_CONFLICT);
document_download($Qreq);
