<?php
// valueformat.php -- HotCRP classes for rendering values
// Copyright (c) 2006-2025 Eddie Kohler; see LICENSE.

abstract class ValueFormat {
    /** @param int|float $x
     * @return string */
    abstract function vtext($x);

    /** @param null|bool|int|float $x
     * @return string */
    final function text($x) {
        if ($x === null) {
            return "";
        } else if ($x === false) {
            return "N";
        } else if ($x === true) {
            return "Y";
        }
        return $this->vtext($x);
    }

    /** @param null|bool|int|float $x
     * @return string */
    function vhtml($x) {
        return $this->vtext($x);
    }

    /** @param null|bool|int|float $x
     * @return string */
    final function html($x) {
        if ($x === null || $x === false) {
            return "";
        } else if ($x === true) {
            return "✓";
        }
        return $this->vhtml($x);
    }

    /** @return ValueFormat */
    function difference_format() {
        return $this;
    }

    /** @return ValueFormat */
    function sum_format() {
        return $this;
    }
}

class Null_ValueFormat extends ValueFormat {
    /** @var Null_ValueFormat */
    static private $main;

    /** @return Null_ValueFormat */
    static function main() {
        self::$main = self::$main ?? new Null_ValueFormat;
        return self::$main;
    }

    function vtext($x) {
        return "";
    }

    function vhtml($x) {
        return "—";
    }
}

class Bool_ValueFormat extends ValueFormat {
    /** @var Bool_ValueFormat */
    static private $main;

    /** @return Bool_ValueFormat */
    static function main() {
        self::$main = self::$main ?? new Bool_ValueFormat;
        return self::$main;
    }

    function vtext($x) {
        if ($x === 0 || $x === 1) {
            return $x ? "Y" : "N";
        } else if (is_int($x)) {
            return (string) $x;
        }
        return (string) (round($x * 100) / 100);
    }

    function vhtml($x) {
        if ($x === 0 || $x === 1) {
            return $x ? "✓" : "";
        } else if (is_int($x)) {
            return (string) $x;
        }
        return (string) (round($x * 100) / 100);
    }

    function difference_format() {
        return Numeric_ValueFormat::main();
    }

    function sum_format() {
        return Numeric_ValueFormat::main();
    }
}

class Numeric_ValueFormat extends ValueFormat {
    /** @var ?string */
    private $real_format;

    /** @var Numeric_ValueFormat */
    static private $main;

    /** @return Numeric_ValueFormat */
    static function main() {
        self::$main = self::$main ?? new Numeric_ValueFormat;
        return self::$main;
    }

    /** @param ?string $real_format */
    function __construct($real_format = null) {
        $this->real_format = $real_format;
    }

    function vtext($x) {
        if ($this->real_format) {
            return sprintf($this->real_format, $x);
        } else if (is_int($x)) {
            return (string) $x;
        }
        return (string) (round($x * 100) / 100);
    }
}

class Int_ValueFormat extends ValueFormat {
    /** @var Int_ValueFormat */
    static private $main;

    /** @return Int_ValueFormat */
    static function main() {
        self::$main = self::$main ?? new Int_ValueFormat;
        return self::$main;
    }

    function vtext($x) {
        return (string) $x;
    }
}

class Date_ValueFormat extends ValueFormat {
    function vtext($x) {
        if ($x <= 0) {
            return "";
        }
        return date("Y-m-d", $x);
    }

    function difference_format() {
        return Duration_ValueFormat::main();
    }
}

class Time_ValueFormat extends ValueFormat {
    function vtext($x) {
        if ($x <= 0) {
            return "";
        }
        return date("Y-m-d\\TH:i:s", $x);
    }

    function difference_format() {
        return Duration_ValueFormat::main();
    }

    function sum_format() {
        return Null_ValueFormat::main();
    }
}

class Duration_ValueFormat extends ValueFormat {
    /** @var Duration_ValueFormat */
    static private $main;

    /** @return Duration_ValueFormat */
    static function main() {
        self::$main = self::$main ?? new Duration_ValueFormat;
        return self::$main;
    }

    function vtext($x) {
        $t = "";
        if ($x < 0) {
            $t .= "-";
            $x = -$x;
        }
        if ($x > 259200) {
            return $t . sprintf("%.1fd", $x / 86400);
        } else if ($x > 7200) {
            return $t . sprintf("%.1fh", $x / 3600);
        } else if ($x > 59) {
            return $t . sprintf("%.1fm", $x / 60);
        } else {
            return $t . sprintf("%.1fs", $x);
        }
    }
}

class User_ValueFormat extends ValueFormat {
    /** @var Contact */
    private $user;

    function __construct(Contact $user) {
        $this->user = $user;
    }

    function vtext($x) {
        if ($x <= 0 || (int) $x != $x) {
            return "";
        }
        return $this->user->name_text_for((int) $x);
    }

    function vhtml($x) {
        if ($x <= 0 || (int) $x != $x) {
            return "";
        }
        return $this->user->reviewer_html_for((int) $x);
    }

    function difference_format() {
        return Null_ValueFormat::main();
    }

    function sum_format() {
        return Null_ValueFormat::main();
    }
}

class ReviewField_ValueFormat extends ValueFormat {
    /** @var ReviewField */
    private $rf;

    function __construct(ReviewField $rf) {
        $this->rf = $rf;
    }

    function vtext($x) {
        return $this->rf->unparse_computed($x);
    }

    function vhtml($x) {
        return $this->rf->unparse_span_html($x);
    }

    function difference_format() {
        return Numeric_ValueFormat::main();
    }

    function sum_format() {
        if ($this->rf instanceof Checkbox_ReviewField) {
            return Int_ValueFormat::main();
        }
        return Null_ValueFormat::main();
    }
}

class SubmissionField_ValueFormat extends ValueFormat {
    /** @var PaperOption */
    private $sf;
    /** @var FieldRender */
    private $fr;
    /** @var PaperInfo */
    private $prow;

    function __construct(Contact $user, PaperOption $sf) {
        $this->sf = $sf;
        $this->fr = new FieldRender(FieldRender::CFHTML, $user);
        $this->prow = PaperInfo::make_placeholder($user->conf, -1);
    }

    function vtext($x) {
        $this->fr->set_context(FieldRender::CFTEXT | FieldRender::CFCSV | FieldRender::CFVERBOSE);
        $this->sf->render($this->fr, PaperValue::make($this->prow, $this->sf, $x));
        return $this->fr->value_text();
    }

    function vhtml($x) {
        $this->fr->set_context(FieldRender::CFHTML);
        $this->sf->render($this->fr, PaperValue::make($this->prow, $this->sf, $x));
        return $this->fr->value_html();
    }

    function difference_format() {
        return Numeric_ValueFormat::main();
    }
}

class Expertise_ValueFormat extends ValueFormat {
    /** @var ReviewField */
    private $erf;

    function __construct(Conf $conf) {
        $this->erf = ReviewField::make_expertise($conf);
    }

    function vtext($x) {
        return $this->erf->unparse_computed($x + 2);
    }

    function vhtml($x) {
        return $this->erf->unparse_span_html($x + 2);
    }

    function difference_format() {
        return Numeric_ValueFormat::main();
    }
}
