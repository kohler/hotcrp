<?php
// src/pages/p_procrastinationgraph.php -- HotCRP procrastination graph drawing page
// Copyright (c) 2006-2021 Eddie Kohler; see LICENSE.

class ProcrastinationGraph_Page {
    static function go(Contact $user, Qrequest $qreq) {
        Graph_Page::echo_graph(false, null, []);
        $rt = new ReviewTimes($user);
        echo Ht::unstash(), Ht::script_open(),
            '$(function () { hotcrp.graph("#hotgraph", ',
            json_encode_browser($rt->json()),
            ") });</script>\n";
    }
}
