<?php
// listactions/la_whovaxlsx.php -- HotCRP helper classes for list actions
// Copyright (c) 2006-2026 Eddie Kohler; see LICENSE.

class WhovaXlsx_ListAction extends ListAction {
    const SESSION_HEADER = ["ID*", "Title*", "Description (optional)", "Tracks (Optional)", "Keywords or topics (optional)", "Session type (Optional)", "Speakers (Optional)", "Authors (Optional)"];
    const SPEAKER_HEADER = ["*Full name", "*First name", "*Last name", "Email", "Affiliation", "Position", "Bio"];
    const SESSION_WIDTHS = [10, 40, 60, 18, 26, 20, 28, 40];
    const SPEAKER_WIDTHS = [24, 16, 16, 28, 28, 20, 50];

    function allow(Contact $user, Qrequest $qreq) {
        return $user->is_manager();
    }

    /** 
     * @param string $value
     * @return string */
    static function strip_delimiters($value) {
        return trim(preg_replace('/[;()<>]/', "", $value));
    }

    /** @param Author $au
     * @return string */
    static function unparse_author($au) {
        $s = self::strip_delimiters($au->firstName . " " . $au->lastName);
        $aff = self::strip_delimiters($au->affiliation);
        if ($aff !== "") {
            $s .= " ({$aff})";
        }
        if ($au->email !== "") {
            $s .= " <{$au->email}>";
        }
        return $s;
    }

    /** @param Iterable<PaperInfo> $prows
     * @return list<list<string>> */
    static function session_list_rows(Contact $user, $prows) {
        $abstract_opt = $user->conf->checked_option_by_id(PaperOption::ABSTRACTID);
        $rows = [];
        foreach ($prows as $prow) {
            if (!$user->can_view_paper($prow) || !$user->allow_view_authors($prow)) {
                continue;
            }
            $description = "";
            if ($user->allow_view_option($prow, $abstract_opt)) {
                $description = $prow->abstract();
            }
            $authors = array_map([self::class, "unparse_author"], $prow->author_list());
            $rows[] = [
                (string) $prow->paperId,
                $prow->title,
                $description,
                "",
                join(";", $prow->topic_map()),
                "",
                "",
                join(";", $authors)
            ];
        }
        return $rows;
    }

    /** @param list<list<string>> $data_rows */
    static function add_session_list_sheet(XlsxGenerator $xlsx, $data_rows) {
        $sheet = new WhovaXlsx_Sheet($xlsx);
        $sheet->add_bold(["Whova Session List Excel Template (two sheets: Session List, Speaker)"]);
        $sheet->add(["This file lists your sessions. After uploading it, you can schedule these sessions into the agenda from the session list."]);
        $sheet->add([]);
        $sheet->add_bold(["Instructions"]);
        $sheet->add(["Step 1: The rows under \"START YOUR SESSION LIST BELOW\" are already filled in from your papers. Edit them as needed."]);
        $sheet->add(["Step 2: * marks a required field. Do not delete any columns. Leave a column blank if you are not using it."]);
        $sheet->add(["Step 3: When you are done, upload this file to the Session Manager on the organizer dashboard."]);
        $sheet->add(["Step 4: If there are any errors, they will show on the uploading window."]);
        $sheet->add([]);
        $sheet->add_bold(["Important Notes"]);
        $sheet->add(["About IDs: The ID keeps track of sessions you upload. It can combine letters, numbers, dashes, and underscores, e.g. 1, 2, 3 or P-110, P-111, P-112."]);
        $sheet->add(["If you have tracks: If there are multiple tracks for one session, separate them with semicolons."]);
        $sheet->add(["If you have keywords or topics: If there are multiple keywords or topics for one session, separate them with semicolons."]);
        $sheet->add(["If you have speakers: If there are multiple speakers in one session, separate them with semicolons. Add each speaker to the \"Speaker\" sheet with their other info."]);
        $sheet->add(["If you have authors: Authors are mainly for academic conferences. Unlike speakers, they are not necessarily present at the conference and are not added to the attendee or speaker list."]);
        $sheet->add(["Separate multiple authors with semicolons. Optionally give an affiliation in parentheses and an email in angle brackets after the name, e.g. Thomas Lee (Rockit Rocket) <tlee@rockitrocket.com>;Sarah Tran;Jeremy Tin (Globe Star)."]);
        $sheet->add([]);
        $sheet->add_bold(["START YOUR SESSION LIST BELOW       * = Required Field"]);
        $sheet->add_band(self::SESSION_HEADER);
        foreach ($data_rows as $r) {
            $sheet->add($r);
        }
        $sheet->add_to($xlsx, "Session List", self::SESSION_WIDTHS);
    }

    static function add_speaker_sheet(XlsxGenerator $xlsx) {
        $sheet = new WhovaXlsx_Sheet($xlsx);
        $sheet->add_bold(["Whova Session List Excel Template (Speaker sheet)"]);
        $sheet->add(["Step 1: Enter either \"First name\" and \"Last name\", or \"Full name\"."]);
        $sheet->add(["Step 2: Every speaker name here must also appear in the Speakers column of the Session List sheet."]);
        $sheet->add([]);
        $sheet->add_bold(["START YOUR SPEAKER DETAILS BELOW     * = Required Field"]);
        $sheet->add_band(self::SPEAKER_HEADER);
        $sheet->add_to($xlsx, "Speaker", self::SPEAKER_WIDTHS);
    }

    function run(Contact $user, Qrequest $qreq, SearchSelection $ssel) {
        $old_overrides = $user->add_overrides(Contact::OVERRIDE_CONFLICT);
        $prows = $ssel->paper_set($user, ["topics" => true]);
        $data_rows = self::session_list_rows($user, $prows);
        $user->set_overrides($old_overrides);

        $xlsx = new XlsxGenerator($user->conf->download_prefix . "whova.xlsx");
        self::add_session_list_sheet($xlsx, $data_rows);
        self::add_speaker_sheet($xlsx);

        $dopt = new Downloader;
        $dopt->parse_qreq($qreq);
        $dopt->set_attachment(true);
        $dopt->set_log_user($user);
        $xlsx->emit($dopt);
        Navigation::complete();
        return null;
    }
}

class WhovaXlsx_Sheet {
    /** @var list<list<string>> */
    private $rows = [];
    /** @var array<int,int> */
    private $styles = [];
    /** @var int */
    private $bold;
    /** @var int */
    private $band;

    function __construct(XlsxGenerator $xlsx) {
        $this->bold = $xlsx->define_style(["bold" => true]);
        $this->band = $xlsx->define_style(["bold" => true, "fill" => "D9D9D9"]);
    }

    /** @param list<string> $values
     * @param int $style */
    function add($values, $style = 0) {
        $this->styles[count($this->rows)] = $style;
        $this->rows[] = $values;
    }

    /** @param list<string> $values */
    function add_bold($values) {
        $this->add($values, $this->bold);
    }

    /** @param list<string> $values */
    function add_band($values) {
        $this->add($values, $this->band);
    }

    /** @param string $name
     * @param array<int,int|float> $col_widths */
    function add_to(XlsxGenerator $xlsx, $name, $col_widths) {
        $xlsx->add_sheet(null, $this->rows, $name, $col_widths, $this->styles);
    }
}
