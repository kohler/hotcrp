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
        $l = 0.41222147079999993 * $lr + 0.5363325363 * $lg + 0.0514459929 * $lb;
        $m = 0.2119034981999999 * $lr + 0.6806995450999999 * $lg + 0.1073969566 * $lb;
        $s = 0.08830246189999998 * $lr + 0.2817188376 * $lg + 0.6299787005000002 * $lb;
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
        $lp = 0.99999999845051981432 * $this->okl + 0.39633779217376785678 * $this->oka + 0.21580375806075880339 * $this->okb;
        $mp = 1.0000000088817607767 * $this->okl - 0.1055613423236563494 * $this->oka - 0.063854174771705903402 * $this->okb;
        $sp = 1.0000000546724109177 * $this->okl - 0.089484182094965759684 * $this->oka - 1.2914855378640917399 * $this->okb;
        $l = $lp * $lp * $lp;
        $m = $mp * $mp * $mp;
        $s = $sp * $sp * $sp;
        return [
            LabColor::lrgb2rgb(+4.076741661347994 * $l - 3.307711590408193 * $m + 0.230969928729428 * $s),
            LabColor::lrgb2rgb(-1.2684380040921763 * $l + 2.6097574006633715 * $m - 0.3413193963102197 * $s),
            LabColor::lrgb2rgb(-0.004196086541837188 * $l - 0.7034186144594493 * $m + 1.7076147009309444 * $s)
        ];
    }

    /** @return string */
    function hashcolor() {
        $rgb = $this->rgb();
        return sprintf("#%02X%02X%02X", round($rgb[0]), round($rgb[1]), round($rgb[2]));
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
