<?php
// listactions/la_getdocument.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class GetDocument_ListAction extends ListAction {
    private $dt;
    function __construct($conf, $fj) {
        $this->dt = $fj->dtype;
    }
    static private function list_action_json(Contact $user, PaperOption $opt) {
        return (object) [
            "name" => "get/" . $opt->dtype_name(),
            "get" => true,
            "allow_api" => true,
            "dtype" => $opt->id,
            "title" => "Documents/" . $opt->title(),
            "order" => $opt->page_order(),
            "display_if" => "listhas:" . $opt->field_key(),
            "data-bulkwarn" => $user->needs_some_bulk_download_warning() ? "" : null,
            "function" => "+GetDocument_ListAction"
        ];
    }
    static function expand2(ComponentSet $gex) {
        $user = $gex->viewer();
        foreach ($user->conf->options()->page_fields() as $o) {
            if ($o->has_document() && $user->can_view_some_option($o))
                $gex->add(self::list_action_json($user, $o));
        }
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $opt = $user->conf->checked_option_by_id($this->dt);
        $dn = $user->conf->download_prefix . plural_word($opt->id <= 0 ? 2 : 1, $opt->dtype_name()) . ".zip";
        $docset = new DocumentInfoSet($dn);
        foreach ($ssel->paper_set($user) as $row) {
            if (($whyNot = $user->perm_view_option($row, $opt))) {
                $whyNot->append_to($docset->message_set(), null, 2);
            } else if (($docs = $row->documents($opt->id))) {
                foreach ($docs as $doc) {
                    $docset->add_as($doc, $doc->export_filename());
                }
            } else {
                $docset->message_set()->msg_at(null, "<0>#{$row->paperId} has no ‘" . $opt->title() . "’ documents", MessageSet::WARNING_NOTE);
            }
        }
        $user->set_overrides($old_overrides);
        if ($docset->is_empty()) {
            return JsonResult::make_message_list($docset->message_set(),
                new MessageItem(null, "<0>Nothing to download", MessageSet::MARKED_NOTE));
        }
        $qreq->qsession()->commit();
        $dopt = new Downloader;
        $dopt->parse_qreq($qreq);
        $dopt->set_attachment(true);
        $dopt->single = true;
        $dopt->log_user = $user;
        if ($docset->prepare_download($dopt)) {
            return $dopt;
        }
        return JsonResult::make_message_list(400, $docset->message_list());
    }
}
