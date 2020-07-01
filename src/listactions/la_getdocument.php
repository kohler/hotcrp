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
    static function expand($name, $user, $fj) {
        if (($o = $user->conf->paper_opts->find(substr($name, 4)))
            && $o->is_document()) {
            return [self::list_action_json($o)];
        } else {
            return null;
        }
    }
    static function error_document(PaperOption $opt, PaperInfo $row, $error_html = "") {
        if (!$error_html) {
            $error_html = htmlspecialchars($row->conf->_("Submission #%d has no %s field.", $row->paperId, $opt->title()));
        }
        $x = new DocumentInfo(["documentType" => $opt->id, "paperId" => $row->paperId, "error" => true, "error_html" => $error_html], $row->conf);
        if (($mimetypes = $opt->mimetypes()) && count($mimetypes) === 1) {
            $x->mimetype = $mimetypes[0]->mimetype;
        }
        return $x;
    }
    function run(Contact $user, $qreq, $ssel) {
        $docs = [];
        $ngood = 0;
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $opt = $user->conf->checked_option_by_id($this->dt);
        foreach ($ssel->paper_set($user) as $row) {
            if (($whyNot = $user->perm_view_option($row, $opt))) {
                $docs[] = self::error_document($opt, $row, whyNotText($whyNot));
            } else if (($doc = $row->document($opt->id))) {
                $docs[] = $doc;
                $doc->filename = $doc->export_filename();
                ++$ngood;
            } else {
                $docs[] = self::error_document($opt, $row);
            }
        }
        $user->set_overrides($old_overrides);
        if ($ngood === 1 && count($docs) === 1) {
            session_write_close();
            $result = Filer::multidownload($docs, null, ["attachment" => true]);
        } else if ($ngood > 0) {
            session_write_close(); // it can take a while to generate the download
            $result = Filer::multidownload($docs,
                $user->conf->download_prefix . pluralx($opt->id <= 0 ? 2 : 1, $opt->dtype_name()) . ".zip",
                ["attachment" => true]);
        } else {
            if (!empty($docs)) {
                Conf::msg_error("Nothing to download.<br />" . join("<br />", array_map(function ($ed) { return $ed->error_html; }, $docs)));
            }
            return;
        }
        if (!$result->error) {
            DocumentInfo::log_download_activity($docs, $user);
        } else {
            Conf::msg_error($result->error_html);
        }
        // XXX how to return errors?
    }
}
