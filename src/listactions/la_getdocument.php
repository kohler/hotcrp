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
            "dtype" => $opt->id,
            "title" => "Documents/" . $opt->plural_title(),
            "position" => $opt->position + ($opt->final ? 0 : 100),
            "display_if_list_has" => $opt->field_key(),
            "callback" => "+GetDocument_ListAction"
        ];
    }
    /** @param string $name */
    static function expand($name, Contact $user, $fj) {
        if (($o = $user->conf->options()->find(substr($name, 4)))
            && $o->is_document()) {
            return [self::list_action_json($o)];
        } else {
            return null;
        }
    }
    /** @deprecated */
    static function error_document(PaperOption $opt, PaperInfo $row, $error_html = "") {
        if (!$error_html) {
            $error_html = $row->conf->_("Submission #%d has no %s field.", $row->paperId, $opt->title_html());
        }
        $x = new DocumentInfo(["documentType" => $opt->id, "paperId" => $row->paperId, "error" => true, "error_html" => $error_html], $row->conf);
        if (($mimetypes = $opt->mimetypes()) && count($mimetypes) === 1) {
            $x->mimetype = $mimetypes[0]->mimetype;
        }
        return $x;
    }
    function run(Contact $user, $qreq, $ssel) {
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $opt = $user->conf->checked_option_by_id($this->dt);
        $dn = $user->conf->download_prefix . pluralx($opt->id <= 0 ? 2 : 1, $opt->dtype_name()) . ".zip";
        $docset = new DocumentInfoSet($dn);
        foreach ($ssel->paper_set($user) as $row) {
            if (($whyNot = $user->perm_view_option($row, $opt))) {
                $docset->add_error_html(whyNotText($whyNot));
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
            if ($docset->download(DocumentRequest::add_server_options(["attachment" => true, "single" => true]))) {
                DocumentInfo::log_download_activity($docset->as_list(), $user);
                exit;
            } else {
                Conf::msg_error($docset->error_texts());
            }
        }
        // XXX how to return errors?
    }
}
