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
    static function apply_all(Contact $user, $prow, $opj, Qrequest $qreq, $action) {
        $pj = PaperStatus::clone_json($opj);
        if (!isset($pj->pid))
            $pj->pid = -1;
        foreach (self::$list as $fn)
            $fn[2]->apply($user, $pj, $prow, $opj, $qreq, $action);
        return $pj;
    }
    static function diffs_all(Contact $user, $pj1, $pj2) {
        $diffs = [];
        foreach (self::$list as $fn)
            $fn[2]->diffs($diffs, $user, $pj1, $pj2);
        return $diffs;
    }

    function apply(Contact $user, $pj, $prow, $opj, Qrequest $qreq, $action) {
    }
    function diffs(&$diffs, Contact $user, $pj1, $pj2) {
    }

    static function json_encode_nonempty($j) {
        $j = json_encode_db($j);
        if ($j === "{}")
            $j = "null";
        return $j;
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
    function apply(Contact $user, $pj, $prow, $opj, Qrequest $qreq, $action) {
        $admin = $prow ? $user->can_administer($prow) : $user->privChair;

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

        // Contacts
        if ($qreq->setcontacts || $qreq->has_contacts)
            PaperSaver::replace_contacts($pj, $qreq);
        else if (!$opj)
            $pj->contacts = array($user);

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
            if ($qreq["has_opt$o->id"]
                && (!$o->final || $action === "final")) {
                $okey = $o->json_key();
                $pj->options->$okey = $o->parse_request(get($pj->options, $okey), $qreq, $user, $pj);
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

    function diffs(&$diffs, Contact $user, $pj1, $pj2) {
        if (!$pj1 && !$pj2)
            return;
        else if (!$pj1) {
            $diffs["deleted"] = true;
            return;
        } else if (!$pj2) {
            $diffs["new"] = true;
            $pj2 = (object) [];
        }

        foreach (array("title", "abstract", "collaborators") as $k)
            if (get_s($pj1, $k) !== get_s($pj2, $k))
                $diffs[$k] = true;

        if (!$this->same_authors($pj1, $pj2))
            $diffs["authors"] = true;

        if (self::json_encode_nonempty(get($pj1, "topics"))
            !== self::json_encode_nonempty(get($pj2, "topics")))
            $diffs["topics"] = true;

        $opt1 = get($pj1, "options", (object) []);
        $opt2 = get($pj2, "options", (object) []);
        foreach ($user->conf->paper_opts->option_list() as $o) {
            $oabbr = $o->json_key();
            if (isset($opt1->$oabbr)) {
                $same = isset($opt2->$oabbr)
                    && json_encode_db($opt1->$oabbr) === json_encode_db($opt2->$oabbr);
            } else
                $same = !isset($opt2->$oabbr);
            if (!$same)
                $diffs[$oabbr] = true;
        }

        if ($user->conf->subBlindOptional()
            && !get($pj1, "nonblind") !== !get($pj2, "nonblind"))
            $diffs["nonblind"] = true;

        if (self::pc_conflicts($pj1, $user) !== self::pc_conflicts($pj2, $user))
            $diffs["pc_conflicts"] = true;

        if (json_encode(get($pj1, "submission")) !== json_encode(get($pj2, "submission")))
            $diffs["submission"] = true;
        if (json_encode(get($pj1, "final")) !== json_encode(get($pj2, "final")))
            $diffs["final"] = true;

        if (self::contact_emails($pj1) !== self::contact_emails($pj2))
            $diffs["contacts"] = true;
    }

    private function same_authors($pj1, $pj2) {
        $ct1 = count(get($pj1, "authors", []));
        if ($ct1 != count(get($pj2, "authors", [])))
            return false;
        for ($i = 0; $i != $ct1; ++$i) {
            $au1 = $pj1->authors[$i];
            $au2 = $pj2->authors[$i];
            if (strcasecmp(get_s($au1, "email"), get_s($au2, "email")) !== 0
                || get_s($au1, "affiliation") !== get_s($au2, "affiliation")
                || Text::name_text($au1) !== Text::name_text($au2))
                return false;
        }
        return true;
    }

    private function contact_emails($pj) {
        $c = [];
        foreach (get($pj, "contacts", []) as $v)
            $c[strtolower(is_string($v) ? $v : $v->email)] = true;
        foreach (get($pj, "authors", []) as $au)
            if (get($au, "contact"))
                $c[strtolower($au->email)] = true;
        ksort($c);
        return array_keys($c);
    }

    private function pc_conflicts($pj, Contact $user) {
        $c = [];
        foreach (get($pj, "pc_conflicts", []) as $e => $t)
            $c[strtolower($e)] = $t;
        foreach (self::contact_emails($pj) as $e)
            if ($user->conf->pc_member_by_email($e))
                $c[$e] = "author";
        ksort($c);
        return $c;
    }
}

PaperSaver::register(0, new Default_PaperSaver);
