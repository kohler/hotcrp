<?php
// api_assign.php -- HotCRP assignment API calls
// Copyright (c) 2008-2022 Eddie Kohler; see LICENSE.

class Assign_API {
    static function assign(Contact $user, Qrequest $qreq, PaperInfo $prow = null) {
        if (!isset($qreq->assignments)) {
            return JsonResult::make_missing_error("assignments");
        }
        $a = json_decode($qreq->assignments);
        if (!is_array($a)) {
            return JsonResult::make_parameter_error("assignments");
        }

        $aset = (new AssignmentSet($user))->set_override_conflicts(true);
        if ($prow) {
            $aset->enable_papers($prow);
        }
        $aset->parse(CsvParser::make_json($a));
        $aset->execute();
        $jr = $aset->json_result();

        if ($jr["ok"] && $qreq->search) {
            Search_API::apply_search($jr, $user, $qreq, $qreq->search);
            // include tag information
            if (($p = self::assigned_paper_info($user, $aset))) {
                $jr["p"] = $p;
            }
        }
        return $jr;
    }

    static function assigned_paper_info(Contact $user, AssignmentSet $assigner) {
        $pids = [];
        foreach ($assigner->assignments() as $ai) {
            if ($ai instanceof Tag_Assigner) {
                $pids[$ai->pid] = ($pids[$ai->pid] ?? 0) | 1;
            } else if ($ai instanceof Decision_Assigner
                       || $ai instanceof Status_Assigner) {
                $pids[$ai->pid] = ($pids[$ai->pid] ?? 0) | 2;
            }
        }
        $p = [];
        foreach ($user->paper_set(["paperId" => array_keys($pids)]) as $pr) {
            $p[$pr->paperId] = $tmr = new TagMessageReport;
            $pr->add_tag_info_json($tmr, $user);
            if (($pids[$pr->paperId] & 2) !== 0) {
                list($class, $name) = $pr->status_class_and_name($user);
                if ($class !== "ps-submitted") {
                    $tmr->status_html = "<span class=\"pstat {$class}\">" . htmlspecialchars($name) . "</span>";
                } else {
                    $tmr->status_html = "";
                }
            }
        }
        return $p;
    }
}
