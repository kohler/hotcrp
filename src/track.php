<?php
// track.php -- HotCRP track permissions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Track {
    /** @var string
     * @readonly */
    public $ltag;
    /** @var string
     * @readonly */
    public $tag;
    /** @var bool */
    public $is_default = false;
    /** @var list<?string> */
    public $perm;

    const VIEW = 0;
    const VIEWPDF = 1;
    const VIEWREV = 2;
    const VIEWREVID = 3;
    const ASSREV = 4;
    const SELFASSREV = 5;
    const UNASSREV = 5; // XXX compat
    const VIEWTRACKER = 6;
    const ADMIN = 7;
    const HIDDENTAG = 8;
    const VIEWALLREV = 9;
    const COMMENT = 10;
    const NPERM = 11;

    const BITS_VIEW = 0x1;        // 1 << VIEW
    const BITS_REVIEW = 0x30;     // (1 << ASSREV) | (1 << SELFASSREV)
    const BITS_ADMIN = 0x80;      // 1 << ADMIN
    const BITS_VIEWADMIN = 0x81;  // (1 << VIEW) | (1 << ADMIN)
    const BITS_REQUIRED = 0x180;  // (1 << HIDDENTAG) | (1 << ADMIN)

    /** @readonly */
    static public $perm_name_map = [
        "view" => 0, "viewpdf" => 1, "viewrev" => 2, "viewrevid" => 3,
        "assrev" => 4, "unassrev" => 5, "viewtracker" => 6, "admin" => 7,
        "hiddentag" => 8, "viewallrev" => 9, "comment" => 10
    ];

    /** @param ?string $tag */
    function __construct($tag = null) {
        $this->tag = $tag ?? "";
        $this->ltag = strtolower($this->tag);
        $this->perm = [null, null, null, null, null, null, null, null, null, null, null];
        $this->is_default = $tag === "";
    }

    /** @param int $right
     * @return bool */
    static function right_required($right) {
        return $right === self::ADMIN || $right === self::HIDDENTAG;
    }

    /** @param string $right
     * @return bool */
    static function right_name_required($right) {
        return $right === "admin" || $right === "hiddentag" || str_ends_with($right, "!");
    }

    /** @param int $right
     * @return bool
     * @deprecated */
    static function perm_required($right) {
        return self::right_required($right);
    }

    /** @param string $right
     * @return ?int */
    static function parse_right($right) {
        return self::$perm_name_map[$right] ?? null;
    }

    /** @param int $right
     * @return string */
    static function unparse_right($right) {
        return array_search($right, self::$perm_name_map);
    }
}
