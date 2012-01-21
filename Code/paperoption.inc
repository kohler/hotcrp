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
    const T_FINALPDF = 100;
    const T_FINALSLIDES = 101;
    const T_FINALVIDEO = 102;

    const F_DOCUMENT = 1;
    const F_PDF = 2;
    const F_FINAL = 4;

    static function make_info() {
	return array(self::T_CHECKBOX => 0,
		     self::T_SELECTOR => 0,
		     self::T_NUMERIC => 0,
		     self::T_TEXT => 0,
		     self::T_PDF => self::F_DOCUMENT + self::F_PDF,
		     self::T_SLIDES => self::F_DOCUMENT + self::F_PDF,
		     self::T_VIDEO => self::F_DOCUMENT,
		     self::T_FINALPDF => self::F_DOCUMENT + self::F_PDF + self::F_FINAL,
		     self::T_FINALSLIDES => self::F_DOCUMENT + self::F_PDF + self::F_FINAL,
		     self::T_FINALVIDEO => self::F_DOCUMENT + self::F_FINAL);
    }

    static function type_is_valid($t) {
	global $paperOptionInfo;
	return isset($paperOptionInfo[$t]);
    }

    static function type_is_document($t) {
	global $paperOptionInfo;
	return isset($paperOptionInfo[$t]) && ($paperOptionInfo[$t] & self::F_DOCUMENT) != 0;
    }

    static function type_is_final($t) {
	global $paperOptionInfo;
	return isset($paperOptionInfo[$t]) && ($paperOptionInfo[$t] & self::F_FINAL) != 0;
    }

    static function type_takes_pdf($t) {
	global $paperOptionInfo, $Opt;
	if ($t === null)
	    return !isset($Opt["disablePDF"]) || !$Opt["disablePDF"];
	else
	    return isset($paperOptionInfo[$t]) && ($paperOptionInfo[$t] & self::F_PDF) != 0;
    }

}

global $paperOptionInfo;
$paperOptionInfo = PaperOption::make_info();
