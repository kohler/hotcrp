<?php
// papersaver.php -- HotCRP helper for mapping requests to JSON
// HotCRP is Copyright (c) 2008-2017 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperSaver {
    static private $list = [];
    static public function register($prio, PaperSaver $saver) {
        self::$list[] = [$prio, count(self::$list), $saver];
        usort(self::$list, function ($a, $b) {
            if ($a[0] != $b[0])
                return $a[0] - $b[0];
            else
                return $a[1] - $b[1];
        });
    }
    static public function apply_all(Contact $user, $pj, $opj, $qreq, $action) {
        if (!isset($pj->pid))
            $pj->pid = -1;
        foreach (self::$list as $fn)
            $fn[2]->apply($user, $pj, $opj, $qreq, $action);
    }
    static public function all_diffs($pj, $opj) {
        $diffs = [];
        foreach (self::$list as $fn)
            $fn[2]->diffs($diffs, $pj, $opj);
        return $diffs;
    }

    public function apply(Contact $user, $pj, $opj, $qreq, $action) {
    }
    public function diffs(&$diffs, $pj, $opj) {
    }

    static public function replace_contacts($pj, $qreq) {
        $pj->contacts = array();
        foreach ($qreq as $k => $v)
            if (str_starts_with($k, "contact_")) {
                $email = html_id_decode(substr($k, 8));
                $pj->contacts[] = $email;
            } else if (str_starts_with($k, "newcontact_email")
                       && trim($v) !== ""
                       && trim($v) !== "Email") {
                $suffix = substr($k, strlen("newcontact_email"));
                $email = trim($v);
                $name = $qreq["newcontact_name$suffix"];
                if ($name === "Name")
                    $name = "";
                $pj->contacts[] = (object) ["email" => $email, "name" => $name];
            }
    }
}

class Default_PaperSaver extends PaperSaver {
    public function apply(Contact $user, $pj, $opj, $qreq, $action) {
        global $Conf;
        // Title, abstract, collaborators
        foreach (array("title", "abstract", "collaborators") as $k)
            if (isset($qreq[$k]))
                $pj->$k = UnicodeHelper::remove_f_ligatures($qreq[$k]);

        // Authors
        $bad_author = ["name" => "Name", "email" => "Email", "aff" => "Affiliation"];
        $authors = array();
        foreach ($qreq as $k => $v)
            if (preg_match('/\Aau(name|email|aff)(\d+)\z/', $k, $m)
                && ($v = simplify_whitespace($v)) !== ""
                && $v !== $bad_author[$m[1]]) {
                $au = $authors[$m[2]] = (get($authors, $m[2]) ? : (object) array());
                $x = ($m[1] == "aff" ? "affiliation" : $m[1]);
                $au->$x = $v;
            }
        // some people are idiots
        foreach ($authors as $au)
            if (isset($au->affiliation) && validate_email($au->affiliation)) {
                $aff = $au->affiliation;
                if (!isset($au->email)) {
                    $au->email = $aff;
                    unset($au->affiliation);
                } else if (!validate_email($au->email)) {
                    if (!isset($au->name) || strpos($au->name, " ") === false) {
                        $au->name = trim(get($au, "name", "") . " " . $au->email);
                        $au->email = $aff;
                        unset($au->affiliation);
                    } else {
                        $au->affiliation = $au->email;
                        $au->email = $aff;
                    }
                }
            }
        if (!empty($authors)) {
            ksort($authors, SORT_NUMERIC);
            $pj->authors = array_values($authors);
        }

        // Contacts
        if ($qreq->setcontacts || $qreq->has_contacts)
            PaperSaver::replace_contacts($pj, $qreq);
        else if (!$opj)
            $pj->contacts = array($user);

        // Status
        if ($action === "submit")
            $pj->submitted = true;
        else if ($action === "final")
            $pj->final_submitted = true;
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
        if ($action !== "final" && $Conf->subBlindOptional())
            $pj->nonblind = !$qreq->blind;

        // Topics
        if ($qreq->has_topics) {
            $pj->topics = (object) array();
            foreach ($Conf->topic_map() as $tid => $tname)
                if (+$qreq["top$tid"] > 0)
                    $pj->topics->$tname = true;
        }

        // Options
        if (!isset($pj->options))
            $pj->options = (object) [];
        foreach ($Conf->paper_opts->option_list() as $o)
            if ($qreq["has_opt$o->id"]
                && (!$o->final || $action === "final")) {
                $okey = $o->abbreviation();
                $pj->options->$okey = $o->parse_request(get($pj->options, $okey), $qreq, $user, $pj);
            }
        if (!count(get_object_vars($pj->options)))
            unset($pj->options);

        // PC conflicts
        if ($Conf->setting("sub_pcconf")
            && ($action !== "final" || $user->privChair)
            && $qreq->has_pcconf) {
            $cmax = $user->privChair ? CONFLICT_CHAIRMARK : CONFLICT_MAXAUTHORMARK;
            $pj->pc_conflicts = (object) array();
            foreach (pcMembers() as $pcid => $pc) {
                $ctype = cvtint($qreq["pcc$pcid"], 0);
                $ctype = max(min($ctype, $cmax), 0);
                if ($ctype) {
                    $email = $pc->email;
                    $pj->pc_conflicts->$email = Conflict::$type_names[$ctype];
                }
            }
        }
    }

    public function diffs(&$diffs, $pj, $opj) {
        global $Conf;
        if (!$opj) {
            $diffs["new"] = true;
            return;
        }

        foreach (array("title", "abstract", "collaborators") as $k)
            if (get_s($pj, $k) !== get_s($opj, $k))
                $diffs[$k] = true;
        if (!$this->same_authors($pj, $opj))
            $diffs["authors"] = true;
        if (json_encode(get($pj, "topics") ? : (object) array())
            !== json_encode(get($opj, "topics") ? : (object) array()))
            $diffs["topics"] = true;
        $pjopt = get($pj, "options", (object) []);
        $opjopt = get($opj, "options", (object) []);
        foreach ($Conf->paper_opts->option_list() as $o) {
            $oabbr = $o->abbreviation();
            if (!get($pjopt, $oabbr) != !get($opjopt, $oabbr)
                || (get($pjopt, $oabbr)
                    && json_encode($pjopt->$oabbr) !== json_encode($opjopt->$oabbr))) {
                $diffs["options"] = true;
                break;
            }
        }
        if ($Conf->subBlindOptional() && !get($pj, "nonblind") !== !get($opj, "nonblind"))
            $diffs["anonymity"] = true;
        if (json_encode(get($pj, "pc_conflicts")) !== json_encode(get($opj, "pc_conflicts")))
            $diffs["PC conflicts"] = true;
        if (json_encode(get($pj, "submission")) !== json_encode(get($opj, "submission")))
            $diffs["submission"] = true;
        if (json_encode(get($pj, "final")) !== json_encode(get($opj, "final")))
            $diffs["final copy"] = true;
    }

    private function same_authors($pj, $opj) {
        $pj_ct = count(get($pj, "authors"));
        $opj_ct = count(get($opj, "authors"));
        if ($pj_ct != $opj_ct)
            return false;
        for ($i = 0; $i != $pj_ct; ++$i)
            if (get($pj->authors[$i], "email") !== get($opj->authors[$i], "email")
                || get_s($pj->authors[$i], "affiliation") !== get_s($opj->authors[$i], "affiliation")
                || Text::name_text($pj->authors[$i]) !== Text::name_text($opj->authors[$i]))
                return false;
        return true;
    }
}

PaperSaver::register(0, new Default_PaperSaver);
