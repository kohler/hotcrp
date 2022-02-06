<?php
// pages/p_graph_procrastination.php -- HotCRP procrastination graph drawing page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Graph_Procrastination_Page {
    static function go(Contact $user, Qrequest $qreq) {
        Graph_Page::print_graph(false, null, []);
        $rt = new ReviewTimes($user);
        echo Ht::unstash(), Ht::script_open(),
            '$(function () { hotcrp.graph("#hotgraph", ',
            json_encode_browser($rt->json()),
            ") });</script>\n";
    }
}
