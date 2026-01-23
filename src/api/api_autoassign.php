<?php
// api_autoassign.php -- HotCRP autoassignment API calls
// Copyright (c) 2008-2026 Eddie Kohler; see LICENSE.

class Autoassign_API {
    /** @param Qrequest $qreq
     * @param string $name
     * @param bool $is_param
     * @return ?list<string> */
    static private function parse_param($qreq, $name, $is_param) {
        if (!isset($qreq->$name)) {
            return [];
        }

        if ($qreq->has_a($name)) {
            if ($is_param) {
                foreach ($qreq->get_a($name) as $v) {
                    if (strpos($v, "=") === false) {
                        return null;
                    }
                }
            }
            return $qreq->get_a($name);
        }

        $ls = [];
        if (preg_match('/\A\s*\[/', $qreq->$name)) {
            $list = json_decode($qreq->$name);
            if (!is_array($list)) {
                return null;
            }
            foreach ($list as $elt) {
                if ($is_param
                    ? !is_string($elt) || strpos($elt, "=") === false
                    : !is_int($elt) && !is_string($elt)) {
                    return null;
                }
                $ls[] = (string) $elt;
            }
        } else if ($is_param && preg_match('/\A\s*\{/', $qreq->$name)) {
            $map = json_decode($qreq->$name, true);
            if (!is_array($map)) {
                return null;
            }
            foreach ($map as $k => $v) {
                if (!is_int($v) && !is_float($v) && !is_string($v)) {
                    return null;
                }
                $ls[] = "{$k}={$v}";
            }
        } else {
            if ($is_param && strpos((string) $qreq->$name, "=") === false) {
                return null;
            }
            $ls[] = (string) $qreq->$name;
        }
        return $ls;
    }

    static function autoassign(Contact $user, Qrequest $qreq) {
        if (!isset($qreq->autoassigner)) {
            return JsonResult::make_missing_error("autoassigner");
        } else if (!($aa = $user->conf->autoassigner($qreq->autoassigner))) {
            return JsonResult::make_not_found_error("autoassigner");
        }
        if (!isset($qreq->q)) {
            return JsonResult::make_missing_error("q");
        }

        $argv = ["-q{$qreq->q}", "-t" . ($qreq->t ?? "s"), "-a{$aa->name}"];

        $us = self::parse_param($qreq, "u", false);
        if ($us === null) {
            return JsonResult::make_parameter_error("u");
        }
        foreach ($us as $u) {
            $argv[] = "-u{$u}";
        }

        $disjoints = self::parse_param($qreq, "disjoint", false);
        if ($disjoints === null) {
            return JsonResult::make_parameter_error("disjoint");
        }
        foreach ($disjoints as $dj) {
            $argv[] = "-X{$dj}";
        }

        $params = self::parse_param($qreq, "param", true);
        if ($params === null) {
            return JsonResult::make_parameter_error("param");
        }
        array_push($argv, ...$params);

        if (isset($qreq->count)) {
            if (($n = stoi($qreq->count)) === null) {
                return JsonResult::make_parameter_error("count");
            }
            $argv[] = "count={$n}";
        }

        $jargv = ["-je"];
        if (friendly_boolean($qreq->minimal_dry_run)) {
            $jargv[] = "-D";
        } else if (friendly_boolean($qreq->dry_run)) {
            $jargv[] = "-d";
        }

        $tok = Job_Token::make($user, "Autoassign", $jargv)
            ->set_input("assign_argv", $argv)
            ->insert();
        $jobid = $tok->salt;

        $emit_function = function () use ($qreq, $jobid) {
            $jr = new JsonResult(202 /* Accepted */, [
                "ok" => true,
                "job" => $jobid,
                "job_url" => $qreq->conf()->hoturl("api/job", ["job" => $jobid], Conf::HOTURL_RAW | Conf::HOTURL_ABSOLUTE)
            ]);
            $jr->emit($qreq);
            $qreq->qsession()->commit();
        };

        $s = $tok->run_live($emit_function);

        if ($s === "forked") {
            $emit_function();
        }
        if ($s !== "done") {
            exit(0);
        }

        $tok->load_data();
        if ($tok->data("exit_status") === 0) {
            return $tok->json_result("string");
        }
        $jr = JsonResult::make_message_list($tok->data("message_list") ?? []);
        $tok->delete();
        return $jr;
    }

    static function autoassigners(Contact $user, Qrequest $qreq) {
        $conf = $user->conf;
        $exs = [];
        $xtp = (new XtParams($conf, $user))->set_warn_deprecated(false);
        $amap = $conf->autoassigner_map();

        foreach ($amap as $name => $j) {
            if (!($j = Completion_API::resolve_published($xtp, $name, $j, FieldRender::CFAPI))) {
                continue;
            }
            $aj = ["name" => $name];
            if (isset($j->description)) {
                $aj["description"] = $j->description;
            }
            $vos = Autoassigner::expand_parameters($conf, $j->parameters ?? []);
            foreach ($vos as $vot) {
                if (!isset($vot->alias))
                    $aj["parameters"][] = $vot->unparse_export();
            }
            $exs[] = $aj;
        }
        usort($exs, function ($a, $b) {
            return strnatcasecmp($a["name"], $b["name"]);
        });
        return [
            "ok" => true,
            "autoassigners" => $exs
        ];
    }
}
