<?php
// listactions/la_getdocument.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

class GetDocument_ListAction extends ListAction {
    private $dt;
    function __construct($conf, $fj) {
        $this->dt = $fj->dtype;
    }
    static function make_list_action(PaperOption $opt) {
        $fj = (object) [
            "name" => "get/" . $opt->dtype_name(),
            "dtype" => $opt->id,
            "selector" => "Documents/" . ($opt->id <= 0 ? pluralize($opt->title) : $opt->title),
            "position" => $opt->position + ($opt->final ? 0 : 100),
            "display_if_list_has" => $opt->field_key(),
            "callback" => "+GetDocument_ListAction"
        ];
        return $fj;
    }
    static function expand($name, Conf $conf, $fj) {
        if (($o = $conf->paper_opts->find(substr($name, 4)))
            && $o->is_document())
            return [self::make_list_action($o)];
        else
            return null;
    }
    static function error_document(PaperOption $opt, PaperInfo $row, $error_html = "") {
        if (!$error_html)
            $error_html = $row->conf->_("Submission #%d has no %s.", $row->paperId, $opt->message_title);
        $x = new DocumentInfo(["documentType" => $opt->id, "paperId" => $row->paperId, "error" => true, "error_html" => $error_html], $row->conf);
        if (($mimetypes = $opt->mimetypes()) && count($mimetypes) == 1)
            $x->mimetype = $mimetypes[0]->mimetype;
        return $x;
    }
    function run(Contact $user, $qreq, $ssel) {
        $downloads = $errors = [];
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $opt = $user->conf->paper_opts->get($this->dt);
        foreach ($user->paper_set($ssel) as $row) {
            if (($whyNot = $user->perm_view_paper_option($row, $opt)))
                $errors[] = self::error_document($opt, $row, whyNotText($whyNot));
            else if (($doc = $row->document($opt->id)))
                $downloads[] = $doc;
            else
                $errors[] = self::error_document($opt, $row);
        }
        $user->set_overrides($old_overrides);
        if (!empty($downloads)) {
            session_write_close(); // it can take a while to generate the download
            $downloads = array_merge($downloads, $errors);
            if ($user->conf->download_documents($downloads, true))
                exit;
        } else if (!empty($errors))
            Conf::msg_error("Nothing to download.<br />" . join("<br />", array_map(function ($ed) { return $ed->error_html; }, $errors)));
        // XXX how to return errors?
    }
}
