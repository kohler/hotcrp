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
    const UNASSREV = 5;
    const VIEWTRACKER = 6;
    const ADMIN = 7;
    const HIDDENTAG = 8;
    const VIEWALLREV = 9;
    const NPERM = 10;

    const BITS_VIEW = 0x1;        // 1 << VIEW
    const BITS_REVIEW = 0x30;     // (1 << ASSREV) | (1 << UNASSREV)
    const BITS_ADMIN = 0x80;      // 1 << ADMIN
    const BITS_VIEWADMIN = 0x81;  // (1 << VIEW) | (1 << ADMIN)

    /** @readonly */
    static public $perm_name_map = [
        "view" => 0, "viewpdf" => 1, "viewrev" => 2, "viewrevid" => 3,
        "assrev" => 4, "unassrev" => 5, "viewtracker" => 6, "admin" => 7,
        "hiddentag" => 8, "viewallrev" => 9
    ];

    /** @param string $tag */
    function __construct($tag) {
        $this->ltag = strtolower($tag);
        $this->tag = $tag;
        $this->perm = [null, null, null, null, null, null, null, null, null, null];
    }

    /** @param int $perm
     * @return bool */
    static function perm_required($perm) {
        return $perm === self::ADMIN || $perm === self::HIDDENTAG;
    }

    /** @param int $perm
     * @return string */
    static function perm_name($perm) {
        return array_search($perm, self::$perm_name_map);
    }
}
