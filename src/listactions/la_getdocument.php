<?php
// listactions/la_getdocument.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class GetDocument_ListAction extends ListAction {
    private $dt;
    function __construct($conf, $fj) {
        $this->dt = $fj->dtype;
    }
    static function make_list_action(PaperOption $opt) {
        return new GetDocument_ListAction($opt->conf, self::list_action_json($opt));
    }
    static function list_action_json(PaperOption $opt) {
        return (object) [
            "name" => "get/" . $opt->dtype_name(),
            "get" => true,
            "dtype" => $opt->id,
            "title" => "Documents/" . $opt->plural_title(),
            "position" => $opt->display_position(),
            "display_if" => "listhas:" . $opt->field_key(),
            "function" => "+GetDocument_ListAction"
        ];
    }
    static function expand2(GroupedExtensions $gex) {
        $user = $gex->viewer();
        foreach ($user->conf->options()->display_fields() as $o) {
            if ($o->is_document() && $user->can_view_some_option($o))
                $gex->add(self::list_action_json($o));
        }
    }
    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $opt = $user->conf->checked_option_by_id($this->dt);
        $dn = $user->conf->download_prefix . pluralx($opt->id <= 0 ? 2 : 1, $opt->dtype_name()) . ".zip";
        $docset = new DocumentInfoSet($dn);
        foreach ($ssel->paper_set($user) as $row) {
            if (($whyNot = $user->perm_view_option($row, $opt))) {
                $docset->add_error_html($whyNot->unparse_html());
            } else if (($doc = $row->document($opt->id))) {
                $docset->add_as($doc, $doc->export_filename());
            } else {
                $docset->add_error_html($row->conf->_("Submission #%d has no %s field.", $row->paperId, $opt->title_html()));
            }
        }
        $user->set_overrides($old_overrides);
        if ($docset->is_empty()) {
            Conf::msg_error(array_merge(["Nothing to download."], $docset->error_texts()));
        } else {
            session_write_close();
            if ($docset->download(DocumentRequest::add_connection_options(["attachment" => true, "single" => true]))) {
                DocumentInfo::log_download_activity($docset->as_list(), $user);
                exit;
            } else {
                Conf::msg_error($docset->error_texts());
            }
        }
        // XXX how to return errors?
    }
}
