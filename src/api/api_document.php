<?php
// api_document.php -- HotCRP document APIs
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Document_API {
    static function archive_contents(Contact $user, Qrequest $qreq) {
        $qreq->qsession()->commit();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        $dr = (new DocumentRequest($qreq, $user))
            ->apply_version($qreq);
        if (!($doc = $dr->filtered_document())) {
            return $dr->error_result();
        } else if (!$doc->is_archive()) {
            return JsonResult::make_error(400, "<0>Document is not an archive");
        } else if (($listing = $doc->archive_listing(65536)) === null) {
            $ml = $doc->message_list() ? : [MessageItem::error("<0>Internal error")];
            return JsonResult::make_message_list($ml);
        }
        $listing = ArchiveInfo::clean_archive_listing($listing);
        $jr = new JsonResult(["ok" => true, "archive_contents" => $listing]);
        if (friendly_boolean($qreq->summary)) {
            $jr->set("archive_contents_summary", join(", ", ArchiveInfo::consolidate_archive_listing($listing)));
        }
        return $jr;
    }

    static function archivelisting(Contact $user, Qrequest $qreq) {
        $qreq->qsession()->commit();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        $dr = (new DocumentRequest($qreq, $user))
            ->apply_version($qreq);
        if (!($doc = $dr->filtered_document())) {
            return $dr->error_result();
        } else if (!$doc->is_archive()) {
            return JsonResult::make_error(400, "<0>Document is not an archive");
        } else if (($listing = $doc->archive_listing(65536)) === null) {
            $ml = $doc->message_list() ? : [MessageItem::error("<0>Internal error")];
            return JsonResult::make_message_list($ml);
        }
        $listing = ArchiveInfo::clean_archive_listing($listing);
        $jr = new JsonResult(["ok" => true, "listing" => $listing]);
        if (friendly_boolean($qreq->consolidated)) {
            $jr->set("consolidated_listing", join(", ", ArchiveInfo::consolidate_archive_listing($listing)));
        }
        return $jr;
    }

    /** @param bool $active
     * @param ?PaperOption $dtopt
     * @return object */
    static private function history_element(DocumentInfo $doc, $active, $dtopt) {
        $dj = ["docid" => $doc->paperStorageId];
        if ($dtopt) {
            $dj["dt"] = $dtopt;
        }
        $dj["hash"] = $doc->text_hash();
        $dj["mimetype"] = $doc->mimetype;
        $dj["created_at"] = $doc->timestamp;
        $dj["attached_at"] = $doc->timeReferenced ?? $doc->timestamp;
        if (($sz = $doc->size()) >= 0) {
            $dj["size"] = $sz;
        }
        if ($doc->filename) {
            $dj["filename"] = $doc->filename;
        }
        if ($active) {
            $dj["active"] = true;
        }
        $dj["link"] = $doc->url(null, DocumentInfo::DOCURL_INCLUDE_DOCID | Conf::HOTURL_RAW | Conf::HOTURL_ABSOLUTE);
        return (object) $dj;
    }

    static function paper_documentlist(Contact $user, Qrequest $qreq, PaperInfo $prow) {
        $all = friendly_boolean($qreq->history);
        $ajs = $hjs = [];
        $xqreq = Qrequest::make("GET", ["p" => $prow->paperId])
            ->set_paper($qreq->paper());
        foreach ($prow->form_fields() as $opt) {
            if (!$opt->has_document()
                || !$user->can_view_option($prow, $opt)) {
                continue;
            }
            $xqreq->set("dt", (string) $opt->id);
            $dr = new DocumentRequest($xqreq, $user);
            if ($dr->has_error()) {
                error_log($dr->full_feedback_text());
                continue;
            }
            foreach ($all ? $dr->history() : $dr->active() as $i => $doc) {
                if ($i < $dr->active_count()) {
                    $ajs[] = self::history_element($doc, true, $opt);
                } else {
                    $hjs[] = self::history_element($doc, false, $opt);
                }
            }
        }
        usort($hjs, function ($aj, $bj) {
            return ($bj->attached_at <=> $aj->attached_at)
                ? : PaperOption::form_compare($aj->dt, $bj->dt)
                ? : ($aj->docid <=> $bj->docid);
        });
        array_push($ajs, ...$hjs);
        foreach ($ajs as $hj) {
            $hj->dt = $hj->dt->json_key();
        }
        return new JsonResult([
            "ok" => true,
            "document_history" => $ajs
        ]);
    }

    static function documentlist(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $qreq->qsession()->commit();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        if ($prow && !isset($qreq->dt) && !isset($qreq->doc)) {
            return self::paper_documentlist($user, $qreq, $prow);
        }
        $all = friendly_boolean($qreq->history);
        $dr = new DocumentRequest($qreq, $user);
        if ($dr->has_error()) {
            return $dr->error_result();
        }
        $hjs = [];
        foreach ($all ? $dr->history() : $dr->active() as $i => $doc) {
            $hjs[] = self::history_element($doc, $i < $dr->active_count(), null);
        }
        return new JsonResult([
            "ok" => true,
            "dt" => $dr->dtype,
            "document_history" => $hjs
        ]);
    }

    /** @deprecated */
    static function documenthistory(Contact $user, Qrequest $qreq, ?PaperInfo $prow) {
        $qreq->history = "1";
        return self::documentlist($user, $qreq, $prow);
    }

    static function document(Contact $user, Qrequest $qreq) {
        $qreq->qsession()->commit();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        $dr = (new DocumentRequest($qreq, $user))
            ->apply_version($qreq);
        if (!($doc = $dr->filtered_document())) {
            return $dr->error_result();
        }
        // serve document
        $dopt = (new Downloader)
            ->parse_qreq($qreq)
            ->set_cacheable($dr->cacheable)
            ->set_log_user($user);
        if (!$doc->prepare_download($dopt)) {
            return JsonResult::make_message_list(500, $doc->message_list());
        }
        return $dopt;
    }
}
