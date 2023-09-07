<?php
// isovideomimetype.php -- HotCRP helper file for video MIME types
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class ISOVideoMimetype implements JsonSerializable {
    /** @var ?string */
    private $filename;
    /** @var ISOVideoFragment */
    private $data;
    /** @var int */
    private $bound;
    /** @var bool */
    private $verbose = false;
    /** @var int */
    private $depth = 0;

    /** @var int */
    private $flags = 0;
    /** @var ?int */
    private $moov_timescale;
    /** @var ?int */
    private $moov_duration;
    /** @var list<ISOVideoTrack> */
    private $tracks = [];

    /** @var ?float */
    public $duration;
    /** @var ?int */
    public $width;
    /** @var ?int */
    public $height;
    /** @var 0|1|2 */
    public $tflags;

    const F_FTYP = 1;
    const F_MOOV = 2;
    const F_MVEX = 4;
    const F_ANALYZED = 8;

    const TF_AUDIO = 1;
    const TF_VIDEO = 2;
    const TF_ASPECT = 4;
    const TF_PREVIEW = 8;


    private function __construct() {
    }

    /** @param string $s
     * @return ISOVideoMimetype */
    static function make_string($s) {
        $vm = new ISOVideoMimetype;
        $vm->data = new ISOVideoFragment($s, 0, strlen($s));
        $vm->bound = strlen($s);
        return $vm;
    }

    /** @param string $filename
     * @param ?string $prefix
     * @return ISOVideoMimetype */
    static function make_file($filename, $prefix = null) {
        $vm = new ISOVideoMimetype;
        $vm->filename = $filename;
        $s = $prefix ?? file_get_contents($filename, false, null, 0, 32768);
        $vm->data = new ISOVideoFragment($s, 0, strlen($s));
        $vm->bound = @filesize($filename);
        return $vm;
    }

    /** @param bool $v
     * @return $this */
    function set_verbose($v) {
        $this->verbose = $v;
        return $this;
    }

    /** @param ISOVideoFragment $data
     * @param int $pos
     * @param int $epos
     * @return ?ISOVideoFragment */
    function ensure($data, $pos, $epos) {
        if (!$this->filename
            || $epos > $this->bound) {
            return null;
        }
        $xpos = $pos & ~32767;
        $xepos = min((($epos - 1) | 32767) + 1, $this->bound);
        if ($xpos <= $data->epos) {
            $d = file_get_contents($this->filename, false, null, $data->epos, $xepos - $data->epos);
            if ($d === false || $data->epos + strlen($d) < $epos) {
                return null;
            }
            $data->s .= $d;
            $data->epos += strlen($d);
            return $data;
        } else {
            $d = file_get_contents($this->filename, false, null, $xpos, $xepos - $xpos);
            if ($d === false || $xpos + strlen($d) < $epos) {
                return null;
            }
            return new ISOVideoFragment($d, $xpos, $xepos);
        }
    }

    /** @param ISOVideoFragment $data
     * @param int $pos
     * @param int $bound
     * @return ?ISOVideoBox */
    function iso_box_at($data, $pos, $bound) {
        if ($pos + 8 > $data->epos
            && !($data = $this->ensure($data, $pos, $pos + 8))) {
            return null;
        }
        list($size, $type) = Mimetype::be32at_x2($data->s, $pos - $data->pos);
        $xpos = $pos + 8;
        if ($size === 1) {
            if ($xpos + 8 > $data->epos
                && !($data = $this->ensure($data, $pos, $xpos + 8))) {
                return null;
            }
            $size = Mimetype::be64at($data->s, $xpos - $data->pos);
            $xpos += 8;
        } else if ($size === 0) {
            $size = $bound - $pos;
        }
        if ($size < 8 || $pos + $size > $bound) {
            return null;
        }
        if ($type === 0x75756964 /* `uuid` */) {
            if ($xpos + 16 > $data->epos
                && !($data = $this->ensure($data, $pos, $xpos + 16))) {
                return null;
            }
            $type = substr($data->s, $xpos - $data->pos, 16);
            $xpos += 16;
        }
        $box = new ISOVideoBox;
        $box->type = $type;
        $box->pos = $pos;
        $box->cpos = $xpos;
        $box->bound = $pos + $size;
        $box->data = $data;
        if ($this->verbose) {
            fwrite(STDERR, sprintf("%s%s [%d,%d)\n",
                str_repeat("  ", $this->depth),
                self::unparse_tag($type), $pos, $pos + $size));
        }
        return $box;
    }

    /** @param int|string $type
     * @return string */
    static function unparse_tag($type) {
        return is_int($type) ? pack("N", $type) : $type;
    }


    // mvhd timescale -- time units per second
    // mvhd duration -- duration of video; ~0 means cannot be determined
    // tkhd duration -- duration of track, including all edits; ~0 = N/A
    // mvex -- movie extension -- do not handle

    /** @param ISOVideoFragment $data
     * @param int $pos
     * @param int $bound */
    private function walk_moov($data, $pos, $bound) {
        ++$this->depth;
        while (($box = $this->iso_box_at($data, $pos, $bound))) {
            $data = $box->data;
            if ($box->type === 0x6d766864 /* `mvhd` */) {
                $this->walk_mvhd($data, $box->cpos, $box->bound);
            } else if ($box->type === 0x7472616b /* `trak` */) {
                $this->walk_trak($data, $box->cpos, $box->bound);
            } else if ($box->type === 0x6d766578 /* `mvex` */) {
                $this->flags |= self::F_MVEX;
            }
            $pos = $box->bound;
        }
        --$this->depth;
    }

    /** @param ISOVideoFragment $data
     * @param int $pos
     * @param int $bound */
    private function walk_mvhd($data, $pos, $bound) {
        if ($pos + 32 > $data->epos
            && !($data = $this->ensure($data, $pos, $pos + 32))) {
            return;
        }
        $version = ord($data->s[$pos - $data->pos]);
        if ($version === 0) {
            $timescale = Mimetype::be32at($data->s, $pos + 12 - $data->pos);
            $duration = Mimetype::be32at($data->s, $pos + 16 - $data->pos);
            $mask = 0xFFFFFFFF;
        } else if ($version === 1) {
            $timescale = Mimetype::be32at($data->s, $pos + 20 - $data->pos);
            $duration = Mimetype::be64at($data->s, $pos + 24 - $data->pos);
            $mask = 0xFFFFFFFFFFFFFFFF;
        } else {
            return;
        }
        if ($timescale > 0) {
            $this->moov_timescale = $timescale;
        }
        if (($duration & $mask) !== $mask) {
            $this->moov_duration = $duration;
        }
    }


    /** @param ISOVideoFragment $data
     * @param int $pos
     * @param int $bound */
    private function walk_trak($data, $pos, $bound) {
        ++$this->depth;
        $track = new ISOVideoTrack;
        while (($box = $this->iso_box_at($data, $pos, $bound))) {
            $data = $box->data;
            if ($box->type === 0x746b6864 /* `tkhd` */) {
                $this->walk_tkhd($data, $box->cpos, $box->bound, $track);
            } else if ($box->type === 0x6d646961 /* `mdia` */) {
                $this->walk_mdia($data, $box->cpos, $box->bound, $track);
            }
            $pos = $box->bound;
        }
        if ($track->flags !== null) {
            $this->tracks[] = $track;
        }
        --$this->depth;
    }

    /** @param ISOVideoFragment $data
     * @param int $pos
     * @param int $bound
     * @param ISOVideoTrack $track */
    private function walk_tkhd($data, $pos, $bound, $track) {
        if ($pos + 84 > $data->epos
            && !($data = $this->ensure($data, $pos, $pos + 84))) {
            return;
        }
        $vflags = Mimetype::be32at($data->s, $pos - $data->pos);
        $track->flags = $vflags;
        if (($vflags & 0xFF000000) === 0) {
            $duration = Mimetype::be32at($data->s, $pos + 20 - $data->pos);
            $mask = 0xFFFFFFFF;
            $xoff = 24;
        } else if (($vflags & 0xFF000000) === 0x01000000) {
            $duration = Mimetype::be64at($data->s, $pos + 28 - $data->pos);
            $mask = 0xFFFFFFFFFFFFFFFF;
            $xoff = 36;
        } else {
            return;
        }
        if (($duration & $mask) !== $mask) {
            $track->duration = $duration;
        } else {
            $duration = null;
        }
        if ($pos + $xoff + 60 > $data->epos
            && !($data = $this->ensure($data, $pos, $pos + $xoff + 60))) {
            return;
        }
        $track->width = Mimetype::be32at($data->s, $pos + $xoff + 52 - $data->pos);
        $track->height = Mimetype::be32at($data->s, $pos + $xoff + 56 - $data->pos);
        if ($this->verbose) {
            fwrite(STDERR, sprintf("%s  tkhd: flags %x, duration %s, width %g, height %g\n",
                str_repeat("  ", $this->depth),
                $vflags & 0xFFFFFF,
                json_encode($duration),
                $track->width / 65536., $track->height / 65536.));
        }
    }


    /** @param ISOVideoFragment $data
     * @param int $pos
     * @param int $bound
     * @param ISOVideoTrack $track */
    private function walk_mdia($data, $pos, $bound, $track) {
        ++$this->depth;
        while (($box = $this->iso_box_at($data, $pos, $bound))) {
            $data = $box->data;
            if ($box->type === 0x68646c72 /* `hdlr` */) {
                $this->walk_hdlr($data, $box->cpos, $box->bound, $track);
                break;
            }
            $pos = $box->bound;
        }
        --$this->depth;
    }

    /** @param ISOVideoFragment $data
     * @param int $pos
     * @param int $bound
     * @param ISOVideoTrack $track */
    private function walk_hdlr($data, $pos, $bound, $track) {
        if ($bound - $pos < 24
            || ($bound > $data->epos
                && !($data = $this->ensure($data, $pos, $bound)))) {
            return;
        }
        if (ord($data->s[$pos - $data->pos]) !== 0) {
            return;
        }
        $track->handler = Mimetype::be32at($data->s, $pos + 8 - $data->pos);
        if ($this->verbose) {
            fwrite(STDERR, sprintf("%s  hdlr: %s\n",
                str_repeat("  ", $this->depth), self::unparse_tag($track->handler)));
        }
    }


    function analyze() {
        if (($this->flags & self::F_ANALYZED) !== 0) {
            return;
        }
        $this->flags |= self::F_ANALYZED;

        $data = $this->data;
        $pos = 0;
        while (($this->flags & 3) !== 3
               && ($box = $this->iso_box_at($data, $pos, $this->bound))) {
            $data = $box->data;
            if ($box->type === 0x6d6f6f76 /* `moov` */) {
                $this->flags |= self::F_MOOV;
                $this->walk_moov($data, $box->cpos, $box->bound);
            } else if ($box->type === 0x66747970 /* `ftyp` */) {
                $this->flags |= self::F_FTYP;
            }
            $pos = $box->bound;
        }

        // handle parsed data
        $this->tflags = 0;
        if (($this->flags & self::F_MOOV) === 0
            || empty($this->tracks)) {
            return;
        }

        // turn on track flags if necessary
        $trflags = 0;
        foreach ($this->tracks as $tr) {
            $trflags |= $tr->flags;
        }
        $addflags = (($trflags & 1) ^ 1) | (($trflags & 6) === 0 ? 6 : 0);
        foreach ($this->tracks as $tr) {
            $tr->flags |= $addflags;
        }

        // parse max_duration, etc.
        $max_duration = 0;
        if (($this->flags & self::F_MVEX) !== 0) {
            $max_duration = null;
        }
        foreach ($this->tracks as $tr) {
            if (($tr->flags & 1) === 0) {
                continue;
            }
            if ($max_duration !== null
                && $tr->duration !== null
                && $tr->duration > $max_duration) {
                $max_duration = $tr->duration;
            }
            if ($tr->handler === 0x76696465 /* `vide` */) {
                $this->tflags |= self::TF_VIDEO;
                if ($tr->width === null || $tr->height === null) {
                    continue;
                }
                $w = (int) round($tr->width / 65536.0);
                $h = (int) round($tr->height / 65536.0);
                // if aspect ratio, ignore unless plausibly pixels
                if (($tr->flags & 8) !== 0) {
                    if ($this->width !== null
                        || $w < 400 || $w > 8000
                        || $h < 400 || $h > 8000) {
                        continue;
                    }
                    $this->tflags |= self::TF_ASPECT;
                } else {
                    if ($this->width !== null
                        && ($this->tflags & self::TF_ASPECT) === 0) {
                        continue;
                    }
                    $this->tflags &= ~self::TF_ASPECT;
                }
                // if preview-only, override
                if (($tr->flags & 2) === 0) {
                    if ($this->width !== null) {
                        continue;
                    }
                    $this->tflags |= self::TF_PREVIEW;
                } else {
                    if ($this->width !== null
                        && ($this->tflags & self::TF_PREVIEW) === 0) {
                        continue;
                    }
                    $this->tflags &= ~self::TF_PREVIEW;
                }
                $this->width = $w;
                $this->height = $h;
            } else if ($tr->handler === 0x736f756e /* `soun` */) {
                $this->tflags |= self::TF_AUDIO;
            }
        }
        if ($this->moov_timescale !== null) {
            if ($this->moov_duration !== null) {
                $this->duration = $this->moov_duration / $this->moov_timescale;
            } else if ($max_duration !== null) {
                $this->duration = $max_duration / $this->moov_timescale;
            }
        }
    }

    /** @param ?string $type
     * @return array */
    function content_info($type = null) {
        $this->analyze();
        if ($this->tflags === 0) {
            return $type ? ["type" => $type] : [];
        }
        if ($type === null) {
            if (($this->tflags & self::TF_VIDEO) !== 0) {
                $type = "video/mp4";
            } else {
                $type = "audio/mp4";
            }
        }
        $info = ["type" => $type];
        if ($this->duration !== null) {
            $info["duration"] = $this->duration;
        }
        if ($this->width !== null && $this->height !== null) {
            $info["width"] = $this->width;
            $info["height"] = $this->height;
        }
        return $info;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = ["flags" => $this->flags];
        if ($this->moov_timescale !== null) {
            $j["moov_timescale"] = $this->moov_timescale;
        }
        if ($this->moov_duration !== null) {
            $j["moov_duration"] = $this->moov_duration;
        }
        foreach ($this->tracks as $tr) {
            $tj = ["flags" => $tr->flags];
            foreach (["duration", "width", "height", "handler"] as $k) {
                if ($tr->$k !== null)
                    $tj[$k] = $tr->$k;
            }
            $j["tracks"][] = $tj;
        }
        if ($this->tflags !== 0) {
            $j["result"] = [
                "tflags" => $this->tflags,
                "duration" => $this->duration,
                "width" => $this->width,
                "height" => $this->height
            ];
        }
        return $j;
    }
}

class ISOVideoFragment {
    /** @var string */
    public $s;
    /** @var int */
    public $pos;
    /** @var int */
    public $epos;

    /** @param string $s
     * @param int $pos
     * @param int $epos */
    function __construct($s, $pos, $epos) {
        $this->s = $s;
        $this->pos = $pos;
        $this->epos = $epos;
    }
}

class ISOVideoBox {
    /** @var int|string */
    public $type;
    /** @var int */
    public $pos;
    /** @var int */
    public $cpos;
    /** @var int */
    public $bound;
    /** @var ISOVideoFragment */
    public $data;
}

class ISOVideoTrack {
    /** @var int */
    public $flags;
    /** @var ?int */
    public $duration;
    /** @var ?int */
    public $width;
    /** @var ?int */
    public $height;
    /** @var ?int */
    public $handler;
}
