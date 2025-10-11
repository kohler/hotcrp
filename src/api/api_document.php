<?php
// api_document.php -- HotCRP document APIs
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Document_API {
    static function archivelisting(Contact $user, Qrequest $qreq) {
        $qreq->qsession()->commit();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        $dr = (new DocumentRequest($qreq, $qreq->file, $user))
            ->apply_version($qreq);
        if (!($doc = $dr->filtered_document($qreq, true))) {
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
     * @return object */
    static private function history_element(DocumentInfo $doc, $active) {
        $dj = [
            "hash" => $doc->text_hash(),
            "at" => $doc->timestamp,
            "mimetype" => $doc->mimetype
        ];
        if (($sz = $doc->size()) >= 0) {
            $dj["size"] = $sz;
        }
        if ($doc->filename) {
            $dj["filename"] = $doc->filename;
        }
        if ($active) {
            $dj["active"] = true;
        }
        $dj["link"] = $doc->url(null, DocumentInfo::DOCURL_INCLUDE_TIME | Conf::HOTURL_RAW | Conf::HOTURL_ABSOLUTE);
        return (object) $dj;
    }

    static function documenthistory(Contact $user, Qrequest $qreq) {
        $qreq->qsession()->commit();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        $dr = new DocumentRequest($qreq, $qreq->file, $user);
        if ($dr->has_error()) {
            return $dr->error_result();
        }
        $hjs = [];
        foreach ($dr->history() as $i => $doc) {
            $hjs[] = self::history_element($doc, $i < $dr->history_nactive());
        }
        return new JsonResult([
            "ok" => true,
            "dtype" => $dr->dtype,
            "document_history" => $hjs
        ]);
    }

    static function document(Contact $user, Qrequest $qreq) {
        $qreq->qsession()->commit();
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        $dr = (new DocumentRequest($qreq, $qreq->file, $user))
            ->apply_version($qreq);
        if (!($doc = $dr->filtered_document($qreq, true))) {
            return $dr->error_result();
        }
        // serve document
        $dopt = (new Downloader)
            ->parse_qreq($qreq)
            ->set_cacheable($dr->cacheable)
            ->set_log_user($user);
        if ($doc->emit($dopt)) {
            exit(0);
        }
        return JsonResult::make_message_list(500, $doc->message_list());
    }
}
