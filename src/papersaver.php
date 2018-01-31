<?php
// papersaver.php -- HotCRP helper for mapping requests to JSON
// Copyright (c) 2008-2018 Eddie Kohler; see LICENSE.

class PaperSaver {
    static private $list = [];

    static function register($prio, PaperSaver $saver) {
        self::$list[] = [$prio, count(self::$list), $saver];
        usort(self::$list, function ($a, $b) {
            if ($a[0] != $b[0])
                return $a[0] - $b[0];
            else
                return $a[1] - $b[1];
        });
    }
    static function apply_all(Qrequest $qreq, PaperInfo $prow = null, Contact $user, $action) {
        $ps = new PaperStatus($user->conf);
        $pj = (object) $ps->paper_json($prow);
        if (!isset($pj->pid))
            $pj->pid = -1;
        foreach (self::$list as $fn)
            $fn[2]->apply($pj, $qreq, $prow, $user, $action);
        return $pj;
    }

    function apply($pj, Qrequest $qreq, PaperInfo $prow = null, Contact $user, $action) {
    }

    static function replace_contacts($pj, $qreq) {
        $pj->contacts = array();
        for ($i = 1; isset($qreq["contact_email_{$i}"]); ++$i) {
            if ($qreq["contact_active_{$i}"])
                $pj->contacts[] = $qreq["contact_email_{$i}"];
        }
        for ($i = 1; isset($qreq["newcontact_email_{$i}"]); ++$i) {
            $email = trim((string) $qreq["newcontact_email_{$i}"]);
            if ($qreq["newcontact_active_{$i}"]
                && $email !== ""
                && $email !== "Email") {
                $name = simplify_whitespace((string) $qreq["newcontact_name_{$i}"]);
                if ($name === "Name")
                    $name = "";
                $pj->contacts[] = (object) ["email" => $email, "name" => $name];
            }
        }
    }
}

class Default_PaperSaver extends PaperSaver {
    function apply($pj, Qrequest $qreq, PaperInfo $prow = null, Contact $user, $action) {
        $admin = $prow ? $user->can_administer($prow) : $user->privChair;

        // Contacts
        if ($qreq->setcontacts || $qreq->has_contacts || $action === "updatecontacts")
            PaperSaver::replace_contacts($pj, $qreq);
        else if (!$prow)
            $pj->contacts = array($user);
        if ($action === "updatecontacts")
            return;

        // Title, abstract, collaborators
        foreach (array("title", "abstract", "collaborators") as $k)
            if (isset($qreq[$k]))
                $pj->$k = UnicodeHelper::remove_f_ligatures($qreq[$k]);

        // Authors
        $aukeys = ["name" => "Name", "email" => "Email", "aff" => "Affiliation"];
        $authors = [];
        for ($n = 1; true; ++$n) {
            $au = (object) ["index" => $n];
            $isnull = $isempty = true;
            foreach ($aukeys as $k => $defaultv) {
                $v = $qreq["au" . $k . $n];
                if ($v !== null) {
                    $isnull = false;
                    $v = simplify_whitespace($v);
                    if ($v !== "" && $v !== $defaultv) {
                        if ($k === "aff") {
                            $k = "affiliation";
                        }
                        $au->$k = $v;
                        $isempty = false;
                    }
                }
            }

            if ($isnull) {
                break;
            } else if ($isempty) {
                continue;
            }

            // some people enter email in the affiliation slot
            if (isset($au->affiliation) && validate_email($au->affiliation)) {
                if (!isset($au->email)) {
                    $au->email = $au->affiliation;
                    unset($au->affiliation);
                } else if (!validate_email($au->email)) {
                    if (!isset($au->name) || strpos($au->name, " ") === false) {
                        $au->name = trim(get($au, "name", "") . " " . $au->email);
                        $au->email = $au->affiliation;
                        unset($au->affiliation);
                    } else {
                        $x = $au->affiliation;
                        $au->affiliation = $au->email;
                        $au->email = $x;
                    }
                }
            }

            $authors[] = $au;
        }
        if ($n !== 1)
            $pj->authors = $authors;

        // Status
        if ($action === "submit")
            $pj->submitted = true;
        else if ($action === "final")
            $pj->final_submitted = $pj->submitted = true;
        else
            $pj->submitted = false;

        // Paper upload
        if ($qreq->has_file("paperUpload")) {
            if ($action === "final")
                $pj->final = DocumentInfo::make_file_upload($pj->pid, DTYPE_FINAL, $qreq->file("paperUpload"));
            else if ($action === "update" || $action === "submit")
                $pj->submission = DocumentInfo::make_file_upload($pj->pid, DTYPE_SUBMISSION, $qreq->file("paperUpload"));
        }

        // Blindness
        if ($action !== "final" && $user->conf->subBlindOptional())
            $pj->nonblind = !$qreq->blind;

        // Topics
        if ($qreq->has_topics) {
            $pj->topics = (object) array();
            foreach ($user->conf->topic_map() as $tid => $tname)
                if (+$qreq["top$tid"] > 0)
                    $pj->topics->$tname = true;
        }

        // Options
        if (!isset($pj->options))
            $pj->options = (object) [];
        foreach ($user->conf->paper_opts->option_list() as $o)
            if ($qreq["has_{$o->formid}"]
                && (!$o->final || $action === "final")) {
                $okey = $o->json_key();
                $pj->options->$okey = $o->parse_request(get($pj->options, $okey), $qreq, $user, $prow);
            }
        if (!count(get_object_vars($pj->options)))
            unset($pj->options);

        // PC conflicts
        if ($user->conf->setting("sub_pcconf")
            && ($action !== "final" || $admin)
            && $qreq->has_pcconf) {
            $pj->pc_conflicts = (object) array();
            foreach ($user->conf->pc_members() as $pcid => $pc) {
                $ctype = Conflict::constrain_editable($qreq["pcc$pcid"], $admin);
                if ($ctype) {
                    $email = $pc->email;
                    $pj->pc_conflicts->$email = Conflict::$type_names[$ctype];
                }
            }
        }
    }
}

PaperSaver::register(0, new Default_PaperSaver);
