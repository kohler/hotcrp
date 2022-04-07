<?php
// pages/deadlines.php -- HotCRP deadline reporting page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Deadlines_Page {
    /** @param Conf $conf */
    static private function dl1($conf, $time, $phrase, $description, $arg = null) {
        echo "<dt><strong>", $conf->_($phrase, $arg), "</strong>: ",
            $conf->unparse_time_long($time), $conf->unparse_usertime_span($time),
            "</dt>\n<dd>", $conf->_($description, $arg), "</dd>";
    }

    static function go(Contact $user) {
        if ($user->contactId && $user->is_disabled()) {
            $user = Contact::make_email($user->conf, $user->email);
        }

        // header
        $conf = $user->conf;
        $dl = $user->my_deadlines();

        $conf->header("Deadlines", "deadlines");

        if ($user->privChair) {
            echo "<p>As PC chair, you can <a href=\"", $conf->hoturl("settings"), "\">change the deadlines</a>.</p>\n";
        }

        echo "<dl>\n";

        // If you change these, also change Contact::has_reportable_deadline().
        if ($dl->sub->reg ?? false) {
            self::dl1($conf, $dl->sub->reg, "Registration deadline",
                      "You can register new submissions until this deadline.");
        }

        if ($dl->sub->update ?? false) {
            self::dl1($conf, $dl->sub->update, "Update deadline",
                      "You can update submissions and upload new versions until this deadline.");
        }

        if ($dl->sub->sub ?? false) {
            self::dl1($conf, $dl->sub->sub, "Submission deadline",
                      "Submissions must be ready by this deadline to be reviewed.");
        }

        if ($dl->resps ?? false) {
            foreach ($dl->resps as $rname => $dlr) {
                if (($dlr->open ?? false)
                    && $dlr->open <= Conf::$now
                    && ($dlr->done ?? false)) {
                    if ($rname == 1) {
                        self::dl1($conf, $dlr->done, "Response deadline",
                                  "You can submit responses to the reviews until this deadline.");
                    } else {
                        self::dl1($conf, $dlr->done, "%s response deadline",
                                  "You can submit %s responses to the reviews until this deadline.", $rname);
                    }
                }
            }
        }

        if (($dl->rev ?? false) && ($dl->rev->open ?? false)) {
            $dlbyround = [];
            $last_dlbyround = null;
            foreach ($conf->defined_round_list() as $i => $round_name) {
                $isuf = $i ? "_$i" : "";
                $es = +$conf->setting("extrev_soft$isuf");
                $eh = +$conf->setting("extrev_hard$isuf");
                $ps = $ph = -1;

                $thisdl = [];
                if ($user->isPC) {
                    $ps = +$conf->setting("pcrev_soft$isuf");
                    $ph = +$conf->setting("pcrev_hard$isuf");
                    if ($ph && ($ph < Conf::$now || $ps < Conf::$now)) {
                        $thisdl[] = "PH" . $ph;
                    } else if ($ps) {
                        $thisdl[] = "PS" . $ps;
                    }
                }
                if ($es != $ps || $eh != $ph) {
                    if ($eh && ($eh < Conf::$now || $es < Conf::$now)) {
                        $thisdl[] = "EH" . $eh;
                    } else if ($es) {
                        $thisdl[] = "ES" . $es;
                    }
                }
                if (count($thisdl)) {
                    $dlbyround[$round_name] = $last_dlbyround = join(" ", $thisdl);
                }
            }

            $dlroundunify = true;
            foreach ($dlbyround as $x) {
                if ($x !== $last_dlbyround)
                    $dlroundunify = false;
            }

            foreach ($dlbyround as $roundname => $dltext) {
                if ($dltext === "") {
                    continue;
                }
                if ($dlroundunify) {
                    $roundname = "";
                }
                foreach (explode(" ", $dltext) as $dldesc) {
                    $dt = substr($dldesc, 0, 2);
                    $dv = (int) substr($dldesc, 2);
                    if ($dt === "PS") {
                        self::dl1($conf, $dv, "%s review deadline",
                                  "%s reviews are requested by this deadline.", $roundname);
                    } else if ($dt === "PH") {
                        self::dl1($conf, $dv, "%s review hard deadline",
                                  "%s reviews must be submitted by this deadline.", $roundname);
                    } else if ($dt === "ES") {
                        self::dl1($conf, $dv, "%s external review deadline",
                                  "%s reviews are requested by this deadline.", $roundname);
                    } else if ($dt === "EH") {
                        self::dl1($conf, $dv, "%s external review hard deadline",
                                  "%s reviews must be submitted by this deadline.", $roundname);
                    }
                }
                if ($dlroundunify) {
                    break;
                }
            }
        }

        echo "</table>\n";
        $conf->footer();
    }
}
