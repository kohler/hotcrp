<?php
// api_document.php -- HotCRP document APIs
// Copyright (c) 2008-2025 Eddie Kohler; see LICENSE.

class Document_API {
    /** @var PaperInfo */
    private $prow;
    /** @var DocumentRequest */
    private $dr;
    /** @var DocumentInfo */
    private $doc;

    /** @param int $dtype
     * @return list<DocumentInfo> */
    static private function history(Contact $user, PaperInfo $prow, $dtype) {
        $docs = $prow->documents($dtype);
        if ($user->can_view_document_history($prow)
            && $dtype >= DTYPE_FINAL) {
            $ignore_ids = [];
            foreach ($docs as $doc) {
                $ignore_ids[] = $doc->paperStorageId;
            }
            $result = $prow->conf->qe("select paperId, paperStorageId, timestamp, mimetype, sha1, filename, infoJson, size from PaperStorage where paperId=? and documentType=? and filterType is null and documentId?A order by paperStorageId desc",
                $prow->paperId, $dtype, $ignore_ids);
            while (($doc = DocumentInfo::fetch($result, $prow->conf, $prow))) {
                $docs[] = $doc;
            }
            Dbl::free($result);
        }
        return $docs;
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @return ?JsonResult */
    private function document_request($user, $qreq) {
        if (friendly_boolean($qreq->forceShow) !== false) {
            $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        }
        $this->dr = new DocumentRequest($qreq, $qreq->file, $user);
        if ($this->dr->has_error()) {
            return $this->dr->error_result();
        }
        $this->prow = $this->dr->prow;
        return null;
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @return ?JsonResult */
    private function unfiltered_document($user, $qreq) {
        if (($jr = self::document_request($user, $qreq))) {
            return $jr;
        }

        // check whether we need history
        $dochash = $doctime = $docid = null;
        if (isset($qreq->version)) {
            $dochash = HashAnalysis::hash_as_binary(trim($qreq->version));
            if (!$dochash) {
                return JsonResult::make_parameter_error("version");
            }
        } else if (isset($qreq->at)) {
            $doctime = stoi($qreq->at) ?? $user->conf->parse_time($qreq->at);
            if (!$doctime) {
                return JsonResult::make_parameter_error("at");
            }
        } else if (isset($qreq->docid)) {
            $docid = stoi($qreq->docid);
            if (!$docid) {
                return JsonResult::make_parameter_error("docid");
            }
        }
        if ($dochash || $doctime) {
            if ($this->dr->dtype < DTYPE_FINAL) {
                return JsonResult::make_parameter_error($dochash ? "version" : "at");
            }
            foreach (self::history($user, $this->prow, $this->dr->dtype) as $doc) {
                if ($dochash
                    && $doc->binary_hash() === $dochash) {
                    $this->doc = $doc;
                    return null;
                } else if ($doctime
                           && $doc->timestamp <= $doctime
                           && (!$this->doc || $this->doc->timestamp < $doc)) {
                    $this->doc = $doc;
                }
            }
            if (!$this->doc) {
                return JsonResult::make_not_found_error($dochash ? "version" : "at", "<0>Version not found");
            }
            return null;
        }

        // otherwise, attachment or document
        if ($this->dr->attachment && !$docid) {
            $this->doc = $this->prow->attachment($this->dr->dtype, $this->dr->attachment);
        } else {
            $this->doc = $this->prow->document($this->dr->dtype, $docid);
        }
        if ($docid && (!$this->doc || $this->doc->paperStorageId !== $docid)) {
            return JsonResult::make_not_found_error("docid", "<0>Version not found");
        } else if (!$this->doc || $this->doc->paperStorageId <= 1) {
            return JsonResult::make_not_found_error("file", "<0>Document not found");
        }
        return null;
    }

    /** @param Contact $user
     * @param Qrequest $qreq
     * @return ?JsonResult */
    private function filtered_document($user, $qreq) {
        if (($jr = self::unfiltered_document($user, $qreq))) {
            return $jr;
        }
        foreach ($this->dr->filters as $filter) {
            $this->doc = $filter->exec($this->doc) ?? $this->doc;
        }
        return null;
    }

    static function archivelisting(Contact $user, Qrequest $qreq) {
        $api = new Document_API;
        $qreq->qsession()->commit();
        if (($jr = $api->filtered_document($user, $qreq))) {
            return $jr;
        } else if (!$api->doc->is_archive()) {
            return JsonResult::make_error(400, "<0>Document is not an archive");
        } else if (($listing = $api->doc->archive_listing(65536)) === null) {
            $ml = $api->doc->message_list() ? : [MessageItem::error("<0>Internal error")];
            return JsonResult::make_message_list($ml);
        }
        $listing = ArchiveInfo::clean_archive_listing($listing);
        $jr = new JsonResult(["ok" => true, "listing" => $listing]);
        if (friendly_boolean($qreq->consolidated)) {
            $jr->set("consolidated_listing", join(", ", ArchiveInfo::consolidate_archive_listing($listing)));
        }
        return $jr;
    }
}
