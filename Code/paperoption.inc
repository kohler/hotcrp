<?php
// paperoption.inc -- HotCRP helper class for paper options
// HotCRP is Copyright (c) 2006-2012 Eddie Kohler and Regents of the UC
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

}
