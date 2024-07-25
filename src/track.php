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
    const ADMIN_S = 7;
    const ADMIN_R = 8;
    const HIDDENTAG = 9;
    const VIEWALLREV = 10;
    const NPERM = 11;

    const BITS_VIEW = 0x1;        // 1 << VIEW
    const BITS_REVIEW = 0x30;     // (1 << ASSREV) | (1 << UNASSREV)
    const BITS_ADMIN = 0x180;     // (1 << ADMIN_S) | (1 << ADMIN_R)
    const BITS_VIEWADMIN = 0x181; // (1 << VIEW) | BITS_ADMIN
    const BITS_PERMCLASS_S = 0x3; // (1 << VIEW) | (1 << VIEWPDF)

    /** @readonly */
    static public $perm_name_map = [
        "view" => 0, "viewpdf" => 1, "viewrev" => 2, "viewrevid" => 3,
        "assrev" => 4, "unassrev" => 5, "viewtracker" => 6, "admin_s" => 7,
        "admin_r" => 8, "hiddentag" => 9, "viewallrev" => 10
    ];

    /** @param ?string $tag */
    function __construct($tag = null) {
        $this->tag = $tag ?? "";
        $this->ltag = strtolower($this->tag);
        $this->perm = [null, null, null, null, null, null, null, null, null, null, null];
        $this->is_default = $tag === "";
    }

    /** @param int $perm
     * @return bool */
    static function perm_required($perm) {
        return $perm === self::ADMIN_S || $perm === self::ADMIN_R || $perm === self::HIDDENTAG;
    }

    /** @param int $perm
     * @return string */
    static function perm_name($perm) {
        return array_search($perm, self::$perm_name_map);
    }
}
