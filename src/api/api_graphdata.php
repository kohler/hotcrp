<?php
// api_graphdata.php -- HotCRP formula graph API calls
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class GraphData_API {
    static function graphdata(Contact $user, Qrequest $qreq) {
        if (!isset($qreq->x)) {
            return JsonResult::make_missing_error("x");
        }
        $fg = new FormulaGraph($user, $qreq->gtype ? : "scatter", $qreq->x, $qreq->y);
        if ($qreq->xorder) {
            $fg->set_xorder($qreq->xorder);
        }
        foreach (FormulaGraph::parse_datasets($qreq) as $dataset) {
            $fg->add_dataset($dataset);
        }

        if ($fg->has_error()) {
            return new JsonResult(["ok" => false, "message_list" => $fg->message_list()]);
        }
        return $fg->graph_json(["ok" => true]);
    }
}
