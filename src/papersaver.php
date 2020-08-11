<?php
// papersaver.php -- HotCRP helper for mapping requests to JSON
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class PaperSaver {
    static function apply_all(Qrequest $qreq, PaperInfo $prow = null, Contact $user, $action) {
        $ps = new PaperStatus($user->conf);
        $pj = (object) $ps->paper_json($prow);
        if (!isset($pj->pid)) {
            $pj->pid = -1;
        }

        // Status
        unset($pj->status);
        $updatecontacts = $action === "updatecontacts";
        if ($action === "submit") {
            $pj->submitted = true;
            $pj->draft = false;
        } else if ($action === "final") {
            $pj->final_submitted = $pj->submitted = true;
            $pj->draft = false;
        } else if (!$updatecontacts) {
            $pj->submitted = false;
            $pj->draft = true;
        }

        // Fields
        $nnprow = $prow ?? PaperInfo::make_new($user);
        foreach ($user->conf->options()->form_fields($nnprow) as $o) {
            if (($qreq["has_{$o->formid}"] || isset($qreq[$o->formid]))
                && (!$o->final || $action === "final")
                && (!$updatecontacts || $o->id === PaperOption::CONTACTSID)) {
                // XXX test_editable
                $pj->{$o->json_key()} = $o->parse_web($nnprow, $qreq);
            }
        }

        return $pj;
    }
}
