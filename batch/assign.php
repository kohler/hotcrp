<?php
// assign.php -- HotCRP assignment script
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

if (realpath($_SERVER["PHP_SELF"]) === __FILE__) {
    require_once(dirname(__DIR__) . "/src/init.php");
    exit(Assign_Batch::make_args($argv)->run());
}

class Assign_Batch {
    /** @var Contact */
    public $user;
    /** @var string */
    public $filename;
    /** @var string */
    public $text;
    /** @var bool */
    public $dry_run;

    function __construct(Contact $user, $arg) {
        $this->user = $user;
        $this->dry_run = isset($arg["dry-run"]);
        if (empty($arg["_"])) {
            $this->filename = "<stdin>";
            $this->text = stream_get_contents(STDIN);
        } else {
            $this->filename = $arg["_"][0];
            $this->text = file_get_contents_throw($this->filename);
        }
        $this->text = convert_to_utf8($this->text);
    }

    /** @return int */
    function run() {
        $assignset = (new AssignmentSet($this->user))->set_override_conflicts(true);
        $assignset->parse($this->text, $this->filename);
        if ($assignset->has_error()) {
            fwrite(STDERR, $assignset->full_feedback_text());
            return 1;
        } else if ($assignset->is_empty()) {
            fwrite(STDERR, "{$this->filename}: Assignment makes no changes\n");
        } else if ($this->dry_run) {
            fwrite(STDOUT, $assignset->make_acsv()->unparse());
        } else {
            $assignset->execute();
            $pids = $assignset->assigned_pids();
            $pidt = $assignset->numjoin_assigned_pids(", #");
            fwrite(STDERR, "{$this->filename}: Assigned "
                . join(", ", $assignset->assigned_types())
                . " to " . plural_word($pids, "paper") . " #" . $pidt . "\n");
        }
        return 0;
    }

    /** @return Assign_Batch */
    static function make_args($argv) {
        $arg = (new Getopt)->long(
            "name:,n: !",
            "config: !",
            "dry-run,d Do not perform assignment; output CSV instead.",
            "help,h !"
        )->description("Perform HotCRP bulk assignments specified in the input CSV file.
Usage: php batch/assign.php [--dry-run] [FILE]")
         ->maxarg(1)
         ->helpopt("help")
         ->parse($argv);

        $conf = initialize_conf($arg["config"] ?? null, $arg["name"] ?? null);
        return new Assign_Batch($conf->root_user(), $arg);
    }
}
