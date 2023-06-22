<?php
// oklabcolor.php -- HotCRP helper class for Oklab color space
// From https://bottosson.github.io/posts/oklab/ by Eddie Kohler in 2023

class OklabColor implements JsonSerializable {
    const K = 18;

    /** @var float */
    public $okl;
    /** @var float */
    public $oka;
    /** @var float */
    public $okb;

    /** @param float $okl
     * @param float $oka
     * @param float $okb */
    function __construct($okl, $oka, $okb) {
        $this->okl = $okl;
        $this->oka = $oka;
        $this->okb = $okb;
    }

    /** @param int|float $r
     * @param int|float $g
     * @param int|float $b
     * @return OklabColor */
    static function from_rgb($r, $g, $b) {
        $lr = LabColor::rgb2lrgb($r);
        $lg = LabColor::rgb2lrgb($g);
        $lb = LabColor::rgb2lrgb($b);
        $l = 0.4122214708 * $lr + 0.5363325363 * $lg + 0.0514459929 * $lb;
        $m = 0.2119034982 * $lr + 0.6806995451 * $lg + 0.1073969566 * $lb;
        $s = 0.0883024619 * $lr + 0.2817188376 * $lg + 0.6299787005 * $lb;
        $lp = pow($l, 0.3333333333);
        $mp = pow($m, 0.3333333333);
        $sp = pow($s, 0.3333333333);
        return new OklabColor(
            0.2104542553*$lp + 0.7936177850*$mp - 0.0040720468*$sp,
            1.9779984951*$lp - 2.4285922050*$mp + 0.4505937099*$sp,
            0.0259040371*$lp + 0.7827717662*$mp - 0.8086757660*$sp
        );
    }

    /** @param OklchColor $oklch
     * @return OklabColor */
    static function from_oklch($oklch) {
        return $oklch->oklab();
    }

    /** @param ?float $k
     * @return OklabColor */
    function brighter($k = null) {
        return new OklabColor($this->okl + self::K * ($k ?? 1), $this->oka, $this->okb);
    }

    /** @param ?float $k
     * @return OklabColor */
    function darker($k = null) {
        return new OklabColor($this->okl - self::K * ($k ?? 1), $this->oka, $this->okb);
    }

    /** @return array{float,float,float} */
    function rgb() {
        $lp = $this->okl + 0.3963377774 * $this->oka + 0.2158037573 * $this->okb;
        $mp = $this->okl - 0.1055613458 * $this->oka - 0.0638541728 * $this->okb;
        $sp = $this->okl - 0.0894841775 * $this->oka - 1.2914855480 * $this->okb;
        $l = $lp * $lp * $lp;
        $m = $mp * $mp * $mp;
        $s = $sp * $sp * $sp;
        return [
            LabColor::lrgb2rgb(+4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s),
            LabColor::lrgb2rgb(-1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s),
            LabColor::lrgb2rgb(-0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s)
        ];
    }

    /** @return string */
    function hashcolor() {
        return sprintf("#%02X%02X%02X", ...$this->rgb());
    }

    /** @return OklchColor */
    function oklch() {
        return OklchColor::from_oklab($this);
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return get_object_vars($this);
    }
}
