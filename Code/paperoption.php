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
        return false;
    }

    static function type_needs_data($t) {
        return $t == self::T_TEXT || $t == self::T_TEXT_5LINE;
    }

    static function parse_paper_options($prow) {
        global $Conf;
        if (!$prow)
            return 0;
        $options = paperOptions();
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
                } else {
                    $ox->value = $optsel[$o->optionId][0];
                    if (self::type_needs_data($o->type))
                        $ox->data = $optdata[$o->optionId . "." . $ox->value];
                }
                $prow->option_array[$o->optionId] = $ox;
            }

        return count($prow->option_array);
    }

}
