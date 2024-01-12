<?php
// pages/doc.php -- HotCRP document download page
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Doc_Page {
    /** @param string $status
     * @param MessageItem|MessageSet $msg
     * @param Qrequest $qreq */
    static private function error($status, $msg, $qreq) {
        $ml = $msg instanceof MessageSet ? $msg->message_list() : [$msg];

        if (str_starts_with($status, "403") && $qreq->user()->is_empty()) {
            $qreq->user()->escape();
            exit;
        } else if (str_starts_with($status, "5")) {
            $navpath = $qreq->path();
            error_log($qreq->conf()->dbname . ": bad doc $status "
                . MessageSet::feedback_text($ml)
                . json_encode($qreq) . ($navpath ? " @$navpath" : "")
                . ($qreq->user() ? " " . $qreq->user()->email : "")
                . (empty($_SERVER["HTTP_REFERER"]) ? "" : " R[" . $_SERVER["HTTP_REFERER"] . "]"));
        }

        header("HTTP/1.1 {$status}");
        if (isset($qreq->fn)) {
            json_exit(["ok" => false, "message_list" => $ml]);
        } else {
            $qreq->print_header("Download", null);
            $qreq->conf()->feedback_msg($ml);
            $qreq->print_footer();
            exit;
        }
    }

    /** @param bool $active
     * @return object */
    static private function history_element(DocumentInfo $doc, $active) {
        $pj = ["hash" => $doc->text_hash(), "at" => $doc->timestamp, "mimetype" => $doc->mimetype];
        if (($sz = $doc->size()) >= 0) {
            $pj["size"] = $sz;
        }
        if ($doc->filename) {
            $pj["filename"] = $doc->filename;
        }
        if ($active) {
            $pj["active"] = true;
        }
        $pj["link"] = $doc->url(null, DocumentInfo::DOCURL_INCLUDE_TIME | Conf::HOTURL_RAW | Conf::HOTURL_ABSOLUTE);
        return (object) $pj;
    }

    /** @param int $dtype
     * @return list<object> */
    static private function history(Contact $user, PaperInfo $prow, $dtype) {
        $docs = $prow->documents($dtype);

        $pjs = $actives = [];
        foreach ($docs as $doc) {
            $pjs[] = self::history_element($doc, true);
            $actives[$doc->paperStorageId] = true;
        }

        if ($user->can_view_document_history($prow)
            && $dtype >= DTYPE_FINAL) {
            $result = $prow->conf->qe("select paperId, paperStorageId, timestamp, mimetype, sha1, filename, infoJson, size from PaperStorage where paperId=? and documentType=? and filterType is null order by paperStorageId desc", $prow->paperId, $dtype);
            while (($doc = DocumentInfo::fetch($result, $prow->conf, $prow))) {
                if (!isset($actives[$doc->paperStorageId]))
                    $pjs[] = self::history_element($doc, false);
            }
            Dbl::free($result);
        }

        return $pjs;
    }

    /** @param Contact $user
     * @param Qrequest $qreq */
    static function go($user, $qreq) {
        $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        try {
            $dr = new DocumentRequest($qreq, $qreq->path(), $user);
        } catch (Exception $e) {
            self::error("404 Not Found", MessageItem::error("<0>" . $e->getMessage()), $qreq);
        }

        if (($whyNot = $dr->perm_view_document($user))) {
            self::error(isset($whyNot["permission"]) ? "403 Forbidden" : "404 Not Found", MessageItem::error("<5>" . $whyNot->unparse_html()), $qreq);
        }
        $prow = $dr->prow;
        $want_docid = $request_docid = (int) $dr->docid;

        // history
        if ($qreq->fn === "history") {
            json_exit(["ok" => true, "result" => self::history($user, $prow, $dr->dtype)]);
        }

        if (!isset($qreq->version) && isset($qreq->hash)) {
            $qreq->version = $qreq->hash;
        }

        // time
        if (isset($qreq->at) && !isset($qreq->version) && $dr->dtype >= DTYPE_FINAL) {
            if (ctype_digit($qreq->at)) {
                $time = intval($qreq->at);
            } else if (!($time = $user->conf->parse_time($qreq->at))) {
                $time = Conf::$now;
            }
            $want_pj = null;
            foreach (self::history($user, $prow, $dr->dtype) as $pj) {
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
            $version_hash = HashAnalysis::hash_as_binary(trim($qreq->version));
            if (!$version_hash) {
                self::error("404 Not Found", MessageItem::error("<0>Version not found"), $qreq);
            }
            $want_docid = $user->conf->fetch_ivalue("select max(paperStorageId) from PaperStorage where paperId=? and documentType=? and sha1=? and filterType is null", $dr->paperId, $dr->dtype, $version_hash);
            if ($want_docid !== null && $user->can_view_document_history($prow)) {
                $request_docid = $want_docid;
            }
        }

        if ($dr->attachment && !$request_docid) {
            $doc = $prow->attachment($dr->dtype, $dr->attachment);
        } else {
            $doc = $prow->document($dr->dtype, $request_docid);
        }
        if ($want_docid !== 0 && (!$doc || $doc->paperStorageId !== $want_docid)) {
            self::error("404 Not Found", MessageItem::error("<0>Version not found"), $qreq);
        } else if (!$doc || $doc->paperStorageId <= 1) {
            self::error("404 Not Found", MessageItem::error("<0>" . ($dr->attachment ? "Attachment" : "Document") . " ‘{$dr->req_filename}’ not found"), $qreq);
        }

        // pass through filters
        foreach ($dr->filters as $filter) {
            $doc = $filter->exec($doc) ?? $doc;
        }

        // check for contents request
        if ($qreq->fn === "listing" || $qreq->fn === "consolidatedlisting") {
            if (!$doc->is_archive()) {
                json_exit(JsonResult::make_error(400, "<0>That file is not an archive"));
            } else if (($listing = $doc->archive_listing(65536)) === null) {
                $ml = $doc->message_list();
                if (empty($ml)) {
                    $ml[] = new MessageItem(null, "<0>Internal error", 2);
                }
                json_exit(["ok" => false, "message_list" => $ml]);
            } else {
                $listing = ArchiveInfo::clean_archive_listing($listing);
                if ($qreq->fn === "consolidatedlisting") {
                    $listing = join(", ", ArchiveInfo::consolidate_archive_listing($listing));
                }
                json_exit(["ok" => true, "result" => $listing]);
            }
        }

        // serve document
        $qreq->qsession()->commit();      // to allow concurrent clicks
        $dopt = Downloader::make_server_request();
        $dopt->attachment = (stoi($qreq->save) ?? -1) > 0;
        $dopt->cacheable = $doc->has_hash() && ($x = $qreq->hash) && $doc->check_text_hash($x);
        $dopt->log_user = $user;
        if (!$doc->download($dopt)) {
            self::error("500 Server Error", $doc->message_set(), $qreq);
        }
    }
}
