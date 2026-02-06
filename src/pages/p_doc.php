<?php
// pages/doc.php -- HotCRP document download page
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Doc_Page {
    /** @param int $status
     * @param MessageItem|MessageSet|iterable<MessageItem> $msg
     * @param Qrequest $qreq */
    static private function error($status, $msg, $qreq) {
        if ($status === 403 && $qreq->user()->is_empty()) {
            $qreq->user()->escape();
            exit(0);
        }

        $ml = MessageSet::make_list($msg);
        if ($status >= 500) {
            $navpath = $qreq->path();
            error_log($qreq->conf()->dbname . ": bad doc {$status} "
                . MessageSet::feedback_text($ml)
                . json_encode($qreq) . ($navpath ? " @{$navpath}" : "")
                . ($qreq->user() ? " " . $qreq->user()->email : "")
                . (empty($_SERVER["HTTP_REFERER"]) ? "" : " R[" . $_SERVER["HTTP_REFERER"] . "]"));
        }

        if (isset($qreq->fn)) {
            JsonResult::make_message_list($status, $ml)->complete();
        }
        http_response_code($status);
        $qreq->print_header("Download", "", ["body_class" => "body-error"]);
        $qreq->conf()->feedback_msg($ml);
        $qreq->print_footer();
        exit(0);
    }

    /** @param Contact $user
     * @param Qrequest $qreq */
    static function go($user, $qreq) {
        $qreq->qsession()->commit();      // to allow concurrent clicks
        $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $path = $qreq->path() ?? "";
        while (str_starts_with($path, "/")) {
            $path = substr($path, 1);
        }
        $dr = new DocumentRequest($qreq, $user, $path !== "" ? $path : null);
        $dr->apply_version($qreq);
        if (!($doc = $dr->filtered_document())) {
            self::error($dr->error_status(), $dr->message_list(), $qreq);
        }

        // check for contents request
        if ($qreq->fn === "listing" || $qreq->fn === "consolidatedlisting") {
            /* XXX obsolete */
            if (!$doc->is_archive()) {
                json_exit(JsonResult::make_error(400, "<0>That file is not an archive"));
            } else if (($listing = $doc->archive_listing(65536)) === null) {
                $ml = $doc->message_list();
                if (empty($ml)) {
                    $ml[] = MessageItem::error("<0>Internal error");
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
        $dopt = new Downloader;
        $dopt->parse_qreq($qreq);
        // `save=1` requests attachment;
        // default is inline only for whitelisted formats
        $dopt->set_attachment(friendly_boolean($qreq->save) ? : null);
        $dopt->set_cacheable($dr->cacheable);
        $dopt->log_user = $user;
        if ($doc->emit($dopt) === 500) {
            self::error(500, $doc->message_set(), $qreq);
        }
    }
}
