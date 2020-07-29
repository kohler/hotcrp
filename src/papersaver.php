<?php
// papersaver.php -- HotCRP helper for mapping requests to JSON
// Copyright (c) 2008-2020 Eddie Kohler; see LICENSE.

class PaperSaver {
    static private $list = [];

    static function register($prio, PaperSaver $saver) {
        self::$list[] = [$prio, count(self::$list), $saver];
        usort(self::$list, function ($a, $b) {
            if ($a[0] != $b[0]) {
                return $a[0] - $b[0];
            } else {
                return $a[1] - $b[1];
            }
        });
    }
    static function apply_all(Qrequest $qreq, PaperInfo $prow = null, Contact $user, $action) {
        $ps = new PaperStatus($user->conf);
        $pj = (object) $ps->paper_json($prow);
        if (!isset($pj->pid)) {
            $pj->pid = -1;
        }
        foreach (self::$list as $fn) {
            $fn[2]->apply($pj, $qreq, $prow, $user, $action);
        }
        return $pj;
    }

    function apply($pj, Qrequest $qreq, PaperInfo $prow = null, Contact $user, $action) {
    }

    /** @param Qrequest $qreq */
    static function translate_authors_qreq($qreq) {
        $n = 1;
        while (isset($qreq["auemail$n"])) {
            $qreq["authors:email_$n"] = $qreq["auemail$n"];
            $qreq["authors:name_$n"] = $qreq["auname$n"];
            $qreq["authors:affiliation_$n"] = $qreq["auaff$n"];
            ++$n;
        }
    }

    /** @param Qrequest $qreq */
    static function replace_contacts($pj, $qreq) {
        $pj->contacts = [];
        for ($n = 1; isset($qreq["contacts:email_$n"]); ++$n) {
            $email = trim($qreq["contacts:email_$n"]);
            if (strcasecmp($email, "Email") === 0) {
                $email = "";
            }
            $name = simplify_whitespace((string) $qreq["contacts:name_$n"]);
            if (strcasecmp($name, "Name") === 0) {
                $name = "";
            }
            if ($qreq["contacts:active_$n"] && $email !== "") {
                $pj->contacts[] = (object) [
                    "email" => $email,
                    "name" => $name === "" ? null : $name,
                    "is_new" => !!$qreq["contacts:isnew_$n"], "index" => $n
                ];
            }
        }
    }
}

class Default_PaperSaver extends PaperSaver {
    function apply($pj, Qrequest $qreq, PaperInfo $prow = null, Contact $user, $action) {
        $admin = $prow ? $user->can_administer($prow) : $user->privChair;

        // Contacts
        if ($qreq->has_contacts
            || $action === "updatecontacts") {
            PaperSaver::replace_contacts($pj, $qreq);
        }
        if (!$prow) {
            if (!isset($pj->contacts)) {
                $pj->contacts = [];
            }
            $has_me = !!array_filter($pj->contacts, function ($c) use ($user) {
                return strcasecmp($c->email, $user->email) === 0;
            });
            if (!$has_me) {
                $pj->contacts[] = Author::make_keyed($user);
            }
        }
        if ($action === "updatecontacts") {
            return;
        }

        // Authors
        $aukeys = ["name" => "Name", "email" => "Email", "affiliation" => "Affiliation"];
        $authors = [];
        if (isset($qreq["auemail1"]) && !isset($qreq["authors:email_1"])) {
            self::translate_authors_qreq($qreq);
        }
        for ($n = 1; true; ++$n) {
            $au = (object) ["index" => $n];
            $isnull = $isempty = true;
            foreach ($aukeys as $k => $defaultv) {
                $v = $qreq["authors:{$k}_{$n}"];
                if ($v !== null) {
                    $isnull = false;
                    $v = simplify_whitespace($v);
                    if ($v !== "" && $v !== $defaultv) {
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
        if ($n !== 1) {
            $pj->authors = $authors;
        }

        // Status
        unset($pj->status);
        if ($action === "submit") {
            $pj->submitted = true;
            $pj->draft = false;
        } else if ($action === "final") {
            $pj->final_submitted = $pj->submitted = true;
            $pj->draft = false;
        } else {
            $pj->submitted = false;
            $pj->draft = true;
        }

        // Paper upload
        if ($action === "final") {
            $fname = $qreq->has_file("opt-1") ? "opt-1" : "paperUpload";
            if (($f1 = $qreq->file($fname))) {
                $pj->final = DocumentInfo::make_uploaded_file($f1, $pj->pid, DTYPE_FINAL, $user->conf);
            } else if (($f2 = $qreq["opt-1:upload"])) {
                $pj->final = DocumentInfo::make_capability($f2, $pj->pid, DTYPE_FINAL, $user->conf);
            }
        } else if ($action === "update" || $action === "submit") {
            $fname = $qreq->has_file("opt0") ? "opt0" : "paperUpload";
            if (($f1 = $qreq->file($fname))) {
                $pj->submission = DocumentInfo::make_uploaded_file($f1, $pj->pid, DTYPE_SUBMISSION, $user->conf);
            } else if (($f2 = $qreq["opt0:upload"])) {
                $pj->submission = DocumentInfo::make_capability($f2, $pj->pid, DTYPE_SUBMISSION, $user->conf);
            }
        }

        // Options
        $nnprow = $prow ? : PaperInfo::make_new($user);
        if (!isset($pj->options)) {
            $pj->options = (object) [];
        }
        foreach ($user->conf->options()->form_fields($nnprow) as $o) {
            if (($qreq["has_{$o->formid}"] || isset($qreq[$o->formid]))
                && ($o->id > 0 || $o->type === "intrinsic2")
                && (!$o->final || $action === "final")) {
                // XXX test_editable
                $okey = $o->json_key();
                $ov = $o->parse_web($nnprow, $qreq);
                if ($ov === false) {
                    throw new Error("option {$o->id} {$o->title()} should implement parse_web but doesn't");
                }
                if ($o->id <= 0) {
                    $pj->$okey = $ov;
                } else {
                    $pj->options->$okey = $ov;
                }
            }
        }
        if (!count(get_object_vars($pj->options))) {
            unset($pj->options);
        }
    }
}

PaperSaver::register(0, new Default_PaperSaver);
