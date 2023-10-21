<?php
// pages/deadlines.php -- HotCRP deadline reporting page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Deadlines_Page {
    /** @var Conf */
    public $conf;
    /** @var Contact */
    public $user;
    /** @var list<array{int,int,string}> */
    private $dl = [];

    function __construct($user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    /** @param int $time
     * @param string $phrase
     * @param string $description
     * @param FmtArg ...$args */
    private function dl1($time, $phrase, $description, ...$args) {
        $title = Ftext::as(5, $this->conf->_($phrase, new FmtArg("time", $time), ...$args));
        $desc = Ftext::as(5, $this->conf->_($description, new FmtArg("time", $time), ...$args));
        $ttime = $this->conf->unparse_time_with_local_span($time);
        if ($title !== "" && $desc !== "") {
            $this->dl[] = [$time, count($this->dl), "<dt><strong>{$title}</strong>: {$ttime}</dt><dd>{$desc}</dd>"];
        } else if ($title !== "") {
            $this->dl[] = [$time, count($this->dl), "<dt><strong>{$title}</strong>: {$ttime}</dt><dd></dd>"];
        } else if ($desc !== "") {
            $this->dl[] = [$time, count($this->dl), "<dt>{$ttime}</dt><dd>{$desc}</dd>"];
        }
    }

    function run(Qrequest $qreq) {
        $conf = $this->conf;
        $dl = $this->user->my_deadlines();

        $qreq->print_header("Deadlines", "deadlines");

        if ($this->user->privChair) {
            echo "<p>As PC chair, you can <a href=\"", $this->conf->hoturl("settings"), "\">change the deadlines</a>.</p>\n";
        }

        // If you change these, also change Contact::has_reportable_deadline().

        // XXX deadlines for multiple submission classes
        foreach ($this->conf->submission_round_list() as $sr) {
            $srarg = new FmtArg("sclass", $sr->tag, 0);
            if ($sr->register > 0
                && ($sr->update <= 0 || $sr->register < $sr->update)) {
                $this->dl1($sr->register, "<5>{sclass} registration deadline",
                           "<5>You can register new {sclass} {submissions} until this deadline.", $srarg);
            }
            if ($sr->update > 0 && $sr->update != $sr->submit) {
                $this->dl1($sr->update, "<5>{sclass} update deadline",
                           "<5>You can update {sclass} {submissions} and upload new versions until this deadline.", $srarg);
            }
            if ($sr->submit) {
                $this->dl1($sr->submit, "<5>{sclass} submission deadline",
                           "<5>{sclass} {submissions} must be ready by this deadline to be reviewed.", $srarg);
            }
        }

        if ($dl->resps ?? false) {
            foreach ($dl->resps as $rname => $dlr) {
                if (($dlr->open ?? false)
                    && $dlr->open <= Conf::$now
                    && ($dlr->done ?? false)) {
                    $this->dl1($dlr->done, "<5>{respround} response deadline",
                               "<5>You can submit {respround} responses to the reviews until this deadline.",
                               new FmtArg("respround", $rname == 1 ? "" : $rname, 0));
                }
            }
        }

        if (($dl->rev ?? false) && ($dl->rev->open ?? false)) {
            $dlbyround = [];
            $last_dlbyround = null;
            foreach ($conf->defined_rounds() as $i => $round_name) {
                $isuf = $i ? "_{$i}" : "";
                $es = +$conf->setting("extrev_soft{$isuf}");
                $eh = +$conf->setting("extrev_hard{$isuf}");
                $ps = $ph = -1;

                $thisdl = [];
                if ($this->user->isPC) {
                    $ps = +$conf->setting("pcrev_soft{$isuf}");
                    $ph = +$conf->setting("pcrev_hard{$isuf}");
                    if ($ph && ($ph < Conf::$now || $ps < Conf::$now)) {
                        $thisdl[] = "PH{$ph}";
                    } else if ($ps) {
                        $thisdl[] = "PS{$ps}";
                    }
                }
                if ($es != $ps || $eh != $ph) {
                    if ($eh && ($eh < Conf::$now || $es < Conf::$now)) {
                        $thisdl[] = "EH{$eh}";
                    } else if ($es) {
                        $thisdl[] = "ES{$es}";
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
                    $rarg = new FmtArg("round", $roundname, 0);
                    if ($dt === "PS") {
                        $this->dl1($dv, "<5>{round} review deadline",
                                   "<5>{round} reviews are requested by this deadline.", $rarg);
                    } else if ($dt === "PH") {
                        $this->dl1($dv, "<5>{round} review hard deadline",
                                   "<5>{round} reviews must be submitted by this deadline.", $rarg);
                    } else if ($dt === "ES") {
                        $this->dl1($dv, "<5>{round} external review deadline",
                                   "<5>{round} reviews are requested by this deadline.", $rarg);
                    } else if ($dt === "EH") {
                        $this->dl1($dv, "<5>{round} external review hard deadline",
                                   "<5>{round} reviews must be submitted by this deadline.", $rarg);
                    }
                }
                if ($dlroundunify) {
                    break;
                }
            }
        }

        usort($this->dl, function ($a, $b) {
            return $a[0] <=> $b[0] ? : $a[1] <=> $b[1];
        });
        $old = $new = [];
        foreach ($this->dl as $x) {
            if ($x[0] >= Conf::$now) {
                $new[] = $x[2];
            } else {
                $old[] = $x[2];
            }
        }

        if (!empty($new)) {
            echo '<h3>', Ftext::as(5, $this->conf->_c("deadlines", "Upcoming")),
                "</h3><dl>", join("\n", $new), "</dl>\n";
        }
        if (!empty($old)) {
            echo '<h3>', Ftext::as(5, $this->conf->_c("deadlines", "Past")),
                "</h3><dl>", join("\n", array_reverse($old)), "</dl>\n";
        }
        $qreq->print_footer();
    }

    static function go(Contact $user, Qrequest $qreq) {
        if ($user->contactId && $user->is_disabled()) {
            $user = Contact::make_email($user->conf, $user->email);
        }
        (new Deadlines_Page($user))->run($qreq);
    }
}
