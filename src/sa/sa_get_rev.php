<?php
// sa/sa_get_rev.php -- HotCRP helper classes for search actions
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class GetLead_SearchAction extends SearchAction {
    private $islead;
    public function __construct($islead) {
        $this->islead = $islead;
    }
    function run(Contact $user, $qreq, $ssel) {
        global $Conf;
        if (!$user->isPC)
            return self::EPERM;
        $type = $this->islead ? "lead" : "shepherd";
        $result = Dbl::qe_raw($Conf->paperQuery($user, array("paperId" => $ssel->selection(), "reviewerName" => $type)));
        $texts = array();
        while (($row = PaperInfo::fetch($result, $user)))
            if ($row->reviewEmail
                && ($this->islead ? $user->can_view_lead($row, true) : $user->can_view_shepherd($row, true)))
                arrayappend($texts[$row->paperId], [$row->paperId, $row->title, $row->reviewFirstName, $row->reviewLastName, $row->reviewEmail]);
        downloadCSV($ssel->reorder($texts), array("paper", "title", "first", "last", "{$type}email"), "{$type}s");
    }
}

SearchActions::register("get", "lead", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetLead_SearchAction(true));
SearchActions::register("get", "shepherd", SiteLoader::API_GET | SiteLoader::API_PAPER, new GetLead_SearchAction(false));
