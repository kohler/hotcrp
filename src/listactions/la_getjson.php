<?php
// listactions/la_getjson.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetJson_ListAction extends ListAction {
    /** @var bool */
    private $iszip;
    /** @var ?DocumentInfoSet */
    private $zipdoc;
    function __construct($conf, $fj) {
        $this->iszip = $fj->name === "get/jsonattach";
    }
    function document_callback($dj, DocumentInfo $doc, $dtype, PaperStatus $pstatus) {
        if ($doc->ensure_content()) {
            $dj->content_file = $doc->export_filename();
            $this->zipdoc->add_as($doc, $dj->content_file);
        }
    }
    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $pj = [];
        $ps = new PaperStatus($user->conf, $user, ["hide_docids" => true]);
        if ($this->iszip) {
            $this->zipdoc = new DocumentInfoSet($user->conf->download_prefix . "data.zip");
            $ps->on_document_export([$this, "document_callback"]);
        }
        foreach ($ssel->paper_set($user, ["topics" => true, "options" => true]) as $prow) {
            $pj1 = $ps->paper_json($prow);
            if ($pj1) {
                $pj[] = $pj1;
            } else {
                $pj[] = (object) ["pid" => $prow->paperId, "error" => "You don’t have permission to administer this submission"];
                if ($this->iszip) {
                    $mi = $this->zipdoc->error("You don’t have permission to administer this submission");
                    $mi->landmark = "#{$prow->paperId}";
                }
            }
        }
        $user->set_overrides($old_overrides);
        if (count($pj) == 1) {
            $pj = $pj[0];
            $pj_filename = $user->conf->download_prefix . "paper" . $ssel->selection_at(0) . "-data.json";
        } else {
            $pj_filename = $user->conf->download_prefix . "data.json";
        }
        if ($this->iszip) {
            $this->zipdoc->add_string_as(json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", $pj_filename);
            $this->zipdoc->download();
        } else {
            header("Content-Type: application/json; charset=utf-8");
            header("Content-Disposition: attachment; filename=" . mime_quote_string($pj_filename));
            echo json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        exit;
    }
}
