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
    /** @var bool
     * @readonly */
    public $is_default;
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

    const BITS_VIEW = 0x1;        // 1 << VIEW
    const BITS_REVIEW = 0x30;     // (1 << ASSREV) | (1 << UNASSREV)
    const BITS_ADMIN = 0x80;      // 1 << ADMIN
    const BITS_VIEWADMIN = 0x81;  // (1 << VIEW) | (1 << ADMIN)

    /** @readonly */
    static public $map = [
        "view" => 0, "viewpdf" => 1, "viewrev" => 2, "viewrevid" => 3,
        "assrev" => 4, "unassrev" => 5, "viewtracker" => 6, "admin" => 7,
        "hiddentag" => 8, "viewallrev" => 9
    ];

    /** @param string $tag */
    function __construct($tag) {
        $this->ltag = strtolower($tag);
        $this->tag = $tag;
        $this->is_default = $tag === "_" || $tag === "";
        $this->perm = [null, null, null, null, null, null, null, null, null, null];
    }

    /** @param int $perm
     * @return bool */
    static function permission_required($perm) {
        return $perm === self::ADMIN || $perm === self::HIDDENTAG;
    }

    function __set($name, $value) {
        assert(isset(self::$map[$name]));
        $this->perm[self::$map[$name]] = $value;
    }

    function __get($name) {
        assert(isset(self::$map[$name]));
        return $this->perm[self::$map[$name]];
    }

    function __isset($name) {
        assert(isset(self::$map[$name]));
        return isset($this->perm[self::$map[$name]]);
    }

    function __unset($name) {
        assert(isset(self::$map[$name]));
        $this->perm[self::$map[$name]] = null;
    }
}
