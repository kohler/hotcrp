<?php
// cleansettings.php -- HotCRP maintenance script
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

class CleanSettings_Batch {
    /** @var Conf */
    public $conf;
    /** @var bool */
    public $dry_run;
    /** @var bool */
    public $verbose;

    function __construct(Conf $conf, $arg) {
        $this->conf = $conf;
        $this->dry_run = isset($arg["d"]);
        $this->verbose = isset($arg["V"]);
        $this->conf->fmt()->define_override("Authors", "Authors");
    }

    function clean_default_mailbody() {
        if (!($mb = $this->conf->setting_data("mailbody_requestreview"))) {
            if ($this->verbose) {
                fwrite(STDERR, "{$this->conf->dbname}: No mailbody_requestreview\n");
            }
            return true;
        }
        if (!str_ends_with($mb, "\n")) {
            $mb .= "\n";
        }
        $null_mailer = new HotCRPMailer($this->conf, null, ["width" => false]);
        $xmb = $null_mailer->expand($mb, "body");
        $dmp = new dmp\diff_match_patch;
        $diffs = [];

        $t0 = "Dear {{NAME}},\n\nOn behalf of the {{CONFNAME}} program committee, {{REQUESTERCONTACT}} has asked you to review {{CONFSHORTNAME}} submission #{{PID}}.\n\n{{IF(REASON)}}\nThey supplied this note: {{REASON}}\n{{ENDIF}}\n\n* Title: {{TITLE}}\n* Author(s): {{OPT(AUTHORS)}}\n* Site: {{LINK(review, p={{PID}}&cap={{REVIEWACCEPTOR}})}}\n\nOnce you have viewed the submission and decided whether you are willing to review it, please accept or decline this review request at the submission site. {{IF(REVIEWDEADLINE)}}Should you accept, your review is requested by {{REVIEWDEADLINE}}.{{ENDIF}}\n\n{{IF(NEEDPASSWORD)}}\nYou haven't used the site as {{EMAIL}} before, so you may need to create a password to sign in. Use this link to set one up:\n\n{{PASSWORDLINK}}\n\nShould the link expire, obtain a new one using \"Forgot my password\".\n{{ENDIF}}\n\nThank you for your help -- we appreciate that reviewing is hard work.\n\nContact {{ADMIN}} with any questions or concerns.\n\n{{SIGNATURE}}\n{{LINK}}\n";
        $match = $xmb === $null_mailer->expand($t0, "body");
        if (!$match) {
            $diffs[] = __FILE__ . ":" . __LINE__ . ":\n" . $dmp->line_diff_toUnified($dmp->line_diff($null_mailer->expand($t0, "body"), $xmb));
            $t0 = substr($t0, 0, -9);
            $t0 = str_replace("{{PID}}", "{{NUMBER}}", $t0);
            $t0 = str_replace("{{LINK", "{{URL", $t0);
            $match = $xmb === $null_mailer->expand($t0, "body");
        }
        if (!$match) {
            $diffs[] = __FILE__ . ":" . __LINE__ . ":\n" . $dmp->line_diff_toUnified($dmp->line_diff($null_mailer->expand($t0, "body"), $xmb));
            $t0 = str_replace("{{CONFSHORTNAME}}", "{{CONFNAME}}", $t0);
            $t0 = str_replace("{{IF(NEEDPASSWORD)}}\n", "{{IF(NEEDPASSWORD)}}", $t0);
            $match = $xmb === $null_mailer->expand($t0, "body");
        }
        if (!$match) {
            $diffs[] = __FILE__ . ":" . __LINE__ . ":\n" . $dmp->line_diff_toUnified($dmp->line_diff($null_mailer->expand($t0, "body"), $xmb));
            $t0 = "Dear %NAME%,\n\nOn behalf of the %CONFNAME% program committee, %REQUESTERCONTACT% has asked you to review %CONFNAME% submission #%NUMBER%.%IF(REASON)% They supplied this note: %REASON%%ENDIF%\n\n* Title: %TITLE%\n* Author(s): %OPT(AUTHORS)%\n* Site: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%)%\n\nOnce you have viewed the submission and decided whether you are willing to review it, please accept or decline this review request at the submission site.%IF(REVIEWDEADLINE)% Should you accept, your review is requested by %REVIEWDEADLINE%.%ENDIF%\n\n%IF(NEEDPASSWORD)%You haven't used the site as %EMAIL% before, so you may need to create a password to sign in. Use this link to set one up:\n\n%PASSWORDLINK%\n\nShould the link expire, obtain a new one using \"Forgot my password\".\n\n%ENDIF%Thank you for your help -- we appreciate that reviewing is hard work.\n\nContact %ADMIN% with any questions or concerns.\n\n%SIGNATURE%\n";
            $match = $xmb === $null_mailer->expand($t0, "body");
        }
        if (!$match) {
            $diffs[] = __FILE__ . ":" . __LINE__ . ":\n" . $dmp->line_diff_toUnified($dmp->line_diff($null_mailer->expand($t0, "body"), $xmb));
            $t0 = "Dear %NAME%,\n\nOn behalf of the %CONFNAME% program committee, %REQUESTERCONTACT% has asked you to review %CONFNAME% submission #%NUMBER%.\n%IF(REASON)%\nThey supplied this note: %REASON%\n%ENDIF%\n* Title: %TITLE%\n* Author(s): %OPT(AUTHORS)%\n* Site: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%)%\n\nOnce you have viewed the submission and decided whether you are willing to review it, please accept or decline this review request at the submission site. Should you accept, your review will also be entered on that site.%IF(DEADLINE(extrev_soft))% The review is requested by %DEADLINE(extrev_soft)%.%ENDIF%\n\n%IF(NEEDPASSWORD)%You haven't used the site as %EMAIL% before, so you may need to create a password to sign in. Use this link to set one up:\n\n%PASSWORDLINK%\n\nShould the link expire, obtain a new one using \"Forgot my password\".\n\n%ENDIF%Thank you for your help -- we appreciate that reviewing is hard work.\n\nContact %ADMIN% with any questions or concerns.\n\n%SIGNATURE%\n";
            $match = $xmb === $null_mailer->expand($t0, "body");
        }
        if (!$match) {
            $diffs[] = __FILE__ . ":" . __LINE__ . ":\n" . $dmp->line_diff_toUnified($dmp->line_diff($null_mailer->expand($t0, "body"), $xmb));
            $t0 = str_replace("On behalf of the %CONFNAME% program committee, %REQUESTERCONTACT% has asked you to review %CONFNAME% submission #%NUMBER%.\n%IF(REASON)%\nThey supplied this note: %REASON%\n%ENDIF%\n",
                "On behalf of the %CONFNAME% program committee, %REQUESTERCONTACT% has asked you to review %CONFNAME% submission #%NUMBER%.%IF(REASON)%\n\nThey supplied this note: %REASON%%ENDIF%\n\n", $t0);
            $t0 = str_replace("* Author(s)", "* Authors", $t0);
            $match = $xmb === $null_mailer->expand($t0, "body");
        }
        if (!$match) {
            $diffs[] = __FILE__ . ":" . __LINE__ . ":\n" . $dmp->line_diff_toUnified($dmp->line_diff($null_mailer->expand($t0, "body"), $xmb));
            $t0 = str_replace("Once you have viewed the submission and decided whether you are willing to review it, please accept or decline this review request at the submission site. Should you accept, your review will also be entered on that site.%IF(DEADLINE(extrev_soft))% The review is requested by %DEADLINE(extrev_soft)%.%ENDIF%\n\n",
                "If you are willing to review this submission, you may enter your review on the conference site or complete a review form offline and upload it.%IF(DEADLINE(extrev_soft))% Your review is requested by %DEADLINE(extrev_soft)%.%ENDIF%\n\nOnce you've decided, please accept or decline this review request on the submission site.\n\n* Site: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%)%\n\n", $t0);
            $match = $xmb === $null_mailer->expand($t0, "body");
        }
        if (!$match) {
            $diffs[] = __FILE__ . ":" . __LINE__ . ":\n" . $dmp->line_diff_toUnified($dmp->line_diff($null_mailer->expand($t0, "body"), $xmb));
            $t0 = str_replace("* Site: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%)%\n\n",
                "* Site: %URL(paper, p=%NUMBER%&cap=%REVIEWACCEPTOR%)%\n\n", $t0);
            $t0 = str_replace("Once you've decided, please accept or decline this review request on the submission site.\n\n* Site: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%)%\n\n",
                "Once you've decided, please accept or decline this review request using one of these links.\n\n* Accept: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%&accept=1)%\n* Decline: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%&decline=1)%\n\n", $t0);
            $match = $xmb === $null_mailer->expand($t0, "body");
        }
        if (!$match) {
            $diffs[] = __FILE__ . ":" . __LINE__ . ":\n" . $dmp->line_diff_toUnified($dmp->line_diff($null_mailer->expand($t0, "body"), $xmb));
            $t0 = str_replace("* Site: %URL(paper, p=%NUMBER%&cap=%REVIEWACCEPTOR%)%\n\n",
                "* Site: %URL(paper, p=%NUMBER%)%\n\n", $t0);
            $match = $xmb === $null_mailer->expand($t0, "body");
        }
        if (!$match) {
            $diffs[] = __FILE__ . ":" . __LINE__ . ":\n" . $dmp->line_diff_toUnified($dmp->line_diff($null_mailer->expand($t0, "body"), $xmb));
            $t0 = str_replace("* Title: %TITLE%\n* Authors: %OPT(AUTHORS)%\n* Site: %URL(paper, p=%NUMBER%)%\n\n",
                "       Title: %TITLE%\n     Authors: %OPT(AUTHORS)%\n        Site: %URL(paper, p=%NUMBER%)%\n\n", $t0);
            $t0 = str_replace("* Accept: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%&accept=1)%\n* Decline: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%&decline=1)%\n\n",
                "      Accept: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%&accept=1)%\n     Decline: %URL(review, p=%NUMBER%&cap=%REVIEWACCEPTOR%&decline=1)%\n\n", $t0);
            $match = $xmb === $null_mailer->expand($t0, "body");
        }
        if ($match) {
            $this->conf->save_setting("mailbody_requestreview", null);
        }
        if ($this->verbose) {
            if ($match) {
                fwrite(STDERR, "{$this->conf->dbname}: Cleaned\n  " . rtrim(str_replace("\n", "\n  ", $xmb)) . "\n");
            } else {
                fwrite(STDERR, "{$this->conf->dbname}: Failed\n" . join("", $diffs));
            }
        }
        return true;
    }

    /** @return int */
    function run() {
        $this->clean_default_mailbody();
        return 0;
    }

    /** @param list<string> $argv
     * @return CleanSettings_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "help,h !",
            "d,dry-run",
            "V,verbose"
        )->description("Clean settings in a HotCRP database.
Usage: php batch/cleansettings.php [-n CONFID]")
         ->helpopt("help")
         ->maxarg(0)
         ->parse($argv);

        initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new CleanSettings_Batch(Conf::$main, $arg);
    }
}

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(CleanSettings_Batch::make_args($argv)->run());
}
