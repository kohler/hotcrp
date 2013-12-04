<?php
// paperoption.php -- HotCRP helper class for paper options
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class PaperOption {

    const T_CHECKBOX = 0;
    const T_SELECTOR = 1; // see also script.js:doopttype
    const T_NUMERIC = 2;
    const T_TEXT = 3;
    const T_PDF = 4;
    const T_SLIDES = 5;
    const T_VIDEO = 6;
    const T_RADIO = 7;
    const T_TEXT_5LINE = 8;
    const T_ATTACHMENTS = 9;
    const T_FINALPDF = 100;
    const T_FINALSLIDES = 101;
    const T_FINALVIDEO = 102;

    const F_OK = 1;
    const F_DOCUMENT = 2;
    const F_PDF = 4;
    const F_FINAL = 8;

    const DT_NORMAL = 0;
    const DT_HIGHLIGHT = 1;
    const DT_SUBMISSION = 2;

    static $info = null;

    static function type_flags($t) {
        if (!self::$info)
            self::$info = array(self::T_CHECKBOX => self::F_OK,
                                self::T_SELECTOR => self::F_OK,
                                self::T_NUMERIC => self::F_OK,
                                self::T_TEXT => self::F_OK,
                                self::T_RADIO => self::F_OK,
                                self::T_TEXT_5LINE => self::F_OK,
                                self::T_ATTACHMENTS => self::F_OK,
                                self::T_PDF => self::F_OK + self::F_DOCUMENT + self::F_PDF,
                                self::T_SLIDES => self::F_OK + self::F_DOCUMENT + self::F_PDF,
                                self::T_VIDEO => self::F_OK + self::F_DOCUMENT,
                                self::T_FINALPDF => self::F_OK + self::F_DOCUMENT + self::F_PDF + self::F_FINAL,
                                self::T_FINALSLIDES => self::F_OK + self::F_DOCUMENT + self::F_PDF + self::F_FINAL,
                                self::T_FINALVIDEO => self::F_OK + self::F_DOCUMENT + self::F_FINAL);
        return isset(self::$info[$t]) ? self::$info[$t] : 0;
    }

    static function type_is_valid($t) {
	return self::type_flags($t) != 0;
    }

    static function type_is_selectorlike($t) {
	return $t == self::T_RADIO || $t == self::T_SELECTOR;
    }

    static function type_is_document($t) {
	return (self::type_flags($t) & self::F_DOCUMENT) != 0;
    }

    static function type_is_final($t) {
	return (self::type_flags($t) & self::F_FINAL) != 0;
    }

    static function type_takes_pdf($t) {
	global $Opt;
	if ($t === null)
	    return !isset($Opt["disablePDF"]) || !$Opt["disablePDF"];
	else
	    return (self::type_flags($t) & self::F_PDF) != 0;
    }

    static function type_takes_multiple($t) {
        return $t == self::T_ATTACHMENTS;
    }

    static function type_needs_data($t) {
        return $t == self::T_TEXT || $t == self::T_TEXT_5LINE || $t == self::T_ATTACHMENTS;
    }

    private static function sort_multiples($o, $ox) {
        if ($o->type == self::T_ATTACHMENTS)
            array_multisort($ox->data, SORT_NUMERIC, $ox->values);
    }

    static function parse_paper_options($prow) {
        global $Conf;
        if (!$prow)
            return 0;
        $options = self::get();
        $prow->option_array = array();
        if (!count($options) || !isset($prow->optionIds) || !$prow->optionIds)
            return 0;

        preg_match_all('/(\d+)#(\d+)/', defval($prow, "optionIds", ""), $m);
        $optsel = array();
        for ($i = 0; $i < count($m[1]); ++$i)
            arrayappend($optsel[$m[1][$i]], $m[2][$i]);
        $optdata = null;

        foreach ($options as $o)
            if (isset($optsel[$o->optionId])) {
                $ox = (object) array("optionId" => $o->optionId,
                                     "option" => $o);
                if (self::type_needs_data($o->type) && !$optdata) {
                    $optdata = array();
                    $result = $Conf->qe("select optionId, value, data from PaperOption where paperId=$prow->paperId", "while selecting paper options");
                    while (($row = edb_row($result)))
                        $optdata[$row[0] . "." . $row[1]] = $row[2];
                }
                if (self::type_takes_multiple($o->type)) {
                    $ox->values = $optsel[$o->optionId];
                    if (self::type_needs_data($o->type)) {
                        $ox->data = array();
                        foreach ($ox->values as $v)
                            $ox->data[] = $optdata[$o->optionId . "." . $v];
                    }
                    self::sort_multiples($o, $ox);
                } else {
                    $ox->value = $optsel[$o->optionId][0];
                    if (self::type_needs_data($o->type))
                        $ox->data = $optdata[$o->optionId . "." . $ox->value];
                }
                $prow->option_array[$o->optionId] = $ox;
            }

        return count($prow->option_array);
    }

    static function get($id = null) {
        global $Conf;
        if ($Conf->setting("paperOption") <= 0 || $Conf->sversion <= 0)
            return $id ? null : array();

        // (re)load options from database
        $svar = defval($_SESSION, "paperOption", null);
        if (!$svar || !is_array($svar) || count($svar) < 3 || $svar[2] < 2
            || $svar[0] < $Conf->setting("paperOption")) {
            $opt = array();
            $result = $Conf->q("select * from OptionType order by sortOrder, optionName");
            $order = 0;
            while (($row = edb_orow($result))) {
                // begin backwards compatibility to old schema versions
                if (!isset($row->optionValues))
                    $row->optionValues = "";
                if (!isset($row->type) && $row->optionValues == "\x7Fi")
                    $row->type = self::T_NUMERIC;
                else if (!isset($row->type))
                    $row->type = ($row->optionValues ? self::T_SELECTOR : self::T_CHECKBOX);
                // end backwards compatibility to old schema versions
                $row->optionAbbrev = preg_replace("/-+\$/", "", preg_replace("/[^a-z0-9_]+/", "-", strtolower($row->optionName)));
                if ($row->optionAbbrev == "paper"
                    || $row->optionAbbrev == "submission"
                    || $row->optionAbbrev == "final"
                    || ctype_digit($row->optionAbbrev))
                    $row->optionAbbrev = "opt" . $row->optionId;
                $row->sortOrder = $order++;
                if (!isset($row->displayType))
                    $row->displayType = self::DT_NORMAL;
                if ($row->type == self::T_FINALPDF)
                    $row->displayType = self::DT_SUBMISSION;
                $row->isDocument = self::type_is_document($row->type);
                $row->isFinal = self::type_is_final($row->type);
                $opt[$row->optionId] = $row;
            }
            $_SESSION["paperOption"] = $svar =
                array($Conf->setting("paperOption"), $opt, 2);
        }

        if ($id)
            return defval($svar[1], $id, null);
        else
            return $svar[1];
    }

}
