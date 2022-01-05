<?php
// api_graphdata.php -- HotCRP formula graph API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class GraphData_API {
    static function graphdata(Contact $user, Qrequest $qreq) {
        if (!isset($qreq->x)) {
            return new JsonResult(400, "Missing parameter.");
        }
        $fg = new FormulaGraph($user, $qreq->gtype ? : "scatter", $qreq->x, $qreq->y);
        if ($qreq->xorder) {
            $fg->set_xorder($qreq->xorder);
        }

        list($queries, $styles) = FormulaGraph::parse_queries($qreq);
        for ($i = 0; $i < count($queries); ++$i) {
            $fg->add_query($queries[$i], $styles[$i], isset($qreq->q1) ? "q$i" : "q");
        }

        if (!$fg->has_error()) {
            return ["ok" => true] + $fg->graph_json();
        } else {
            return new JsonResult(400, ["ok" => false, "message_list" => $fg->message_list()]);
        }
    }
}
