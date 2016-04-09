<?php
// sa/sa_get_json.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetJson_SearchAction extends SearchAction {
    private $iszip;
    private $zipdoc;
    public function __construct($iszip) {
        $this->iszip = $iszip;
    }
    public function document_callback($dj, $prow, $dtype, $drow) {
        if ($drow->docclass->load($drow)) {
            $dj->content_file = HotCRPDocument::filename($drow);
            $this->zipdoc->add_as($drow, $dj->content_file);
        }
    }
    function allow(Contact $user) {
        return $user->is_manager();
    }
    function run(Contact $user, $qreq, $ssel) {
        global $Conf, $Opt;
        $q = $Conf->paperQuery($user, ["paperId" => $ssel->selection(), "topics" => true, "options" => true]);
        $result = Dbl::qe_raw($q);
        $pj = [];
        $ps = new PaperStatus($user, ["forceShow" => true]);
        if ($this->iszip) {
            $this->zipdoc = new ZipDocument($Opt["downloadPrefix"] . "data.zip");
            $ps->add_document_callback([$this, "document_callback"]);
        }
        while (($prow = PaperInfo::fetch($result, $user)))
            if ($user->can_administer($prow, true))
                $pj[$prow->paperId] = $ps->row_to_json($prow);
            else {
                $pj[$prow->paperId] = (object) ["pid" => $prow->paperId, "error" => "You don’t have permission to administer this paper."];
                if ($this->iszip)
                    $this->zipdoc->warnings[] = "#$prow->paperId: You don’t have permission to administer this paper.";
            }
        $pj = array_values($ssel->reorder($pj));
        if (count($pj) == 1) {
            $pj = $pj[0];
            $pj_filename = $Opt["downloadPrefix"] . "paper" . $ssel->selection_at(0) . "-data.json";
        } else
            $pj_filename = $Opt["downloadPrefix"] . "data.json";
        if ($this->iszip) {
            $this->zipdoc->add(json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n", $pj_filename);
            $this->zipdoc->download();
        } else {
            header("Content-Type: application/json");
            header("Content-Disposition: attachment; filename=" . mime_quote_string($pj_filename));
            echo json_encode($pj, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
        exit;
    }
}

SearchActions::register("get", "json", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetJson_SearchAction(false));
SearchActions::register("get", "jsonattach", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetJson_SearchAction(true));
