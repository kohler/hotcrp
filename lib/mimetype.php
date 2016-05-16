<?php
// mimetype.php -- HotCRP helper file for MIME types
// HotCRP is Copyright (c) 2006-2016 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Mimetype {
    const TXT = 1;
    const PDF = 2;
    const PS = 3;
    const PPT = 4;
    const JSON = 8;
    const MAX_BUILTIN = 8;

    public $mimetypeid;
    public $mimetype;
    public $extension;
    public $description;
    public $inline;

    private static $tmap = array();

    static function make($id, $type, $extension, $description = null, $inline = false) {
        $m = new Mimetype;
        $m->mimetypeid = $id;
        $m->mimetype = $type;
        $m->extension = $extension;
        $m->description = $description;
        $m->inline = $inline;
        self::register($m);
    }

    static function register($m) {
        $m->mimetypeid = (int) $m->mimetypeid;
        $m->inline = !!$m->inline;
        self::$tmap[$m->mimetype] = self::$tmap[$m->mimetypeid] = $m;
        if ($m->extension)
            self::$tmap[$m->extension] = $m;
        return $m;
    }

    static function make_synonym($synonym, $type) {
        self::$tmap[$synonym] = self::$tmap[$type];
    }

    static function lookup($type, $nocreate = false) {
        if (!$type)
            return null;
        else if (is_object($type))
            return $type;
        else if (array_key_exists($type, self::$tmap))
            return self::$tmap[$type];
        else {
            while (1) {
                $result = Dbl::qe("select * from Mimetype where mimetype=?", $type);
                $m = $result ? $result->fetch_object("Mimetype") : null;
                Dbl::free($m);
                if ($m || $nocreate)
                    break;
                Dbl::qe("insert into Mimetype (mimetypeid, mimetype) select max(greatest(1000,1+mimetypeid)), ? from Mimetype", $type);
            }
            return $m ? self::register($m) : null;
        }
    }

    static function lookup_extension($extension) {
        return $extension ? get(self::$tmap, $extension) : null;
    }

    static function type($type) {
        if (($x = self::lookup($type)))
            return $x->mimetype;
        else
            return $type;
    }

    static function type_equals($typea, $typeb) {
        return self::type($typea) == self::type($typeb);
    }

    static function extension($type) {
        if (($x = self::lookup($type)) && $x->extension)
            return $x->extension;
        else
            return "";
    }

    static function description($type) {
        if (is_array($type)) {
            $a = array();
            foreach ($type as $x)
                if (($x = self::description($x)))
                    $a[$x] = $x;
            return commajoin($a, "or");
        } else {
            $x = self::lookup($type);
            if ($x && $x->description)
                return $x->description;
            else if ($x && $x->extension)
                return $x->extension;
            else if ($x)
                return $x->mimetype;
            else
                return $type;
        }
    }

    static function disposition_inline($type) {
        $x = self::lookup($type);
        return $x && $x->inline;
    }

    static function builtins() {
        $x = [];
        for ($i = 1; $i <= self::MAX_BUILTIN; ++$i)
            $x[] = self::$tmap[$i];
        return $x;
    }

    static function sniff($content) {
        if (strncmp("%PDF-", $content, 5) == 0)
            return self::$tmap[self::PDF];
        else if (strncmp("%!PS-", $content, 5) == 0)
            return self::$tmap[self::PS];
        else if (substr($content, 512, 4) == "\x00\x6E\x1E\xF0")
            return self::$tmap[self::PPT];
        else
            return null;
    }
}

Mimetype::make(Mimetype::TXT, "text/plain", ".txt", "text", true);
Mimetype::make(Mimetype::PDF, "application/pdf", ".pdf", "PDF", true);
Mimetype::make(Mimetype::PS, "application/postscript", ".ps", "PostScript");
Mimetype::make(Mimetype::PPT, "application/vnd.ms-powerpoint", ".ppt", "PowerPoint");
Mimetype::make(5, "application/vnd.openxmlformats-officedocument.presentationml.presentation", ".pptx", "PowerPoint");
Mimetype::make(6, "video/mp4", ".mp4");
Mimetype::make(7, "video/x-msvideo", ".avi");
Mimetype::make(Mimetype::JSON, "application/json", ".json", "JSON");

Mimetype::make_synonym("application/mspowerpoint", "application/vnd.ms-powerpoint");
Mimetype::make_synonym("application/powerpoint", "application/vnd.ms-powerpoint");
Mimetype::make_synonym("application/x-mspowerpoint", "application/vnd.ms-powerpoint");
