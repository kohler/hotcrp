<?php
// pc_color.php -- HotCRP helper classes for paper list content
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class Color_PaperColumn extends PaperColumn {
    /** @var array<string,HclColor> */
    private $colors = [];
    /** @var ?string */
    private $decoration;
    /** @var float */
    private $hdelta = 0.0;
    /** @var bool */
    private $hrev = false;

    function __construct(Conf $conf, $cj) {
        parent::__construct($conf, $cj);
    }
    function add_decoration($decor) {
        $known_decor = ["rgb" => "+0", "gbr" => "+120", "brg" => "+240",
                        "rbg" => "-45", "grb" => "-165", "bgr" => "-285"];
        $kdecor = $known_decor[$decor] ?? $decor;
        if (preg_match('/\A[-+]?\d+\z/', $kdecor)
            && ($n = (float) $kdecor) >= -360
            && $n <= 360) {
            $this->hdelta = abs($n);
            $this->hrev = str_starts_with($kdecor, "-");
            $this->__add_decoration($decor, [$this->decoration]);
            $this->decoration = $decor;
            return true;
        } else {
            return parent::add_decoration($decor);
        }
    }
    function prepare(PaperList $pl, $visible) {
        return $visible === self::PREP_SORT;
    }
    /** @return ?OklchColor */
    private function color(PaperInfo $a, PaperList $pl) {
        $x = $a->sorted_viewable_tags($pl->user);
        if (!array_key_exists($x, $this->colors)) {
            $n = 0;
            $lch = null;
            foreach ($pl->conf->tags()->unique_tagstyles($x, TagStyle::BG) as $ks) {
                ++$n;
                if ($lch === null) {
                    $lch = $ks->oklch();
                } else {
                    $lch = $lch->interpolate(1 / $n, $ks->oklch());
                }
            }
            $this->colors[$x] = $lch;
        }
        return $this->colors[$x];
    }
    function compare(PaperInfo $a, PaperInfo $b, PaperList $pl) {
        $alch = $this->color($a, $pl);
        $bhcl = $this->color($b, $pl);

        // null colors are sorted at end
        if ($alch === null || $bhcl === null) {
            return ($alch ? 0 : 1) <=> ($bhcl ? 0 : 1);
        }

        // gray colors are sorted after non-gray coors
        $agray = $alch->okl < 5 || $alch->okc < 5;
        $bgray = $bhcl->okl < 5 || $bhcl->okc < 5;
        if ($agray !== $bgray) {
            return $agray ? 1 : -1;
        }

        // sort by quantized hue first
        $ah = is_nan($alch->okh) ? 0.0 : $alch->okh;
        if (($ah -= $this->hdelta) < 0) {
            $ah += 360;
        }
        $bh = is_nan($bhcl->okh) ? 0.0 : $bhcl->okh;
        if (($bh -= $this->hdelta) < 0) {
            $bh += 360;
        }
        if ($agray) {
            $ahb = $bhb = 0;
        } else {
            $ahb = (int) ($ah / 24);
            $bhb = (int) ($bh / 24);
        }
        if ($ahb !== $bhb) {
            return $this->hrev ? $bhb <=> $ahb : $ahb <=> $bhb;
        }

        // then sort by quantized lightness
        $alb = (int) ($alch->okl / 2);
        $blb = (int) ($bhcl->okl / 2);
        if ($alb !== $blb) {
            return $blb <=> $alb;
        }

        // then sort by quantized chroma
        $acb = (int) ($alch->okc / 8);
        $bcb = (int) ($bhcl->okc / 8);
        if ($acb !== $bcb) {
            return $bcb <=> $acb;
        }

        // if all buckets match, do a fine-grained comparison
        if ($ah !== $bh) {
            return $this->hrev ? $bh <=> $ah : $ah <=> $bh;
        } else {
            return ($bhcl->okl <=> $alch->okl) ? : ($bhcl->okc <=> $alch->okc);
        }
    }
}
