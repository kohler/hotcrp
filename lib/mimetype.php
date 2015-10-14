<?php
// mimetype.php -- HotCRP helper file for MIME types
// HotCRP is Copyright (c) 2006-2015 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Mimetype {
    const TXT = 1;
    const PDF = 2;

    public $mimetypeid;
    public $mimetype;
    public $extension;
    public $description;

    static $tmap = array();
    static $alltypes = array();

    public function __construct($id, $type, $extension, $description = null) {
        $this->mimetypeid = (int) $id;
        $this->mimetype = $type;
        $this->extension = $extension;
        $this->description = $description;
    }

    static function register($id, $type, $extension, $description = null) {
        $m = new Mimetype($id, $type, $extension, $description);
        self::$tmap[$type] = self::$tmap[$id] = $m;
        if ($extension)
            self::$tmap[$extension] = $m;
        self::$alltypes[] = $m;
    }

    static function register_synonym($synonym, $type) {
        self::$tmap[$synonym] = self::$tmap[$type];
    }

    static function lookup($type) {
        if (!$type)
            return null;
        else if (is_object($type))
            return $type;
        else if (isset(self::$tmap[$type]))
            return self::$tmap[$type];
        else
            return null;
    }

    static function lookup_extension($extension) {
        foreach (self::$alltypes as $t)
            if ($t->extension === $extension)
                return $t;
        return null;
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
        return $x && $x->mimetypeid <= 2;
    }

    static function sniff($content) {
        if (strncmp("%PDF-", $content, 5) == 0)
            return self::type("pdf");
        else if (strncmp("%!PS-", $content, 5) == 0)
            return self::type("ps");
        else if (substr($content, 512, 4) == "\x00\x6E\x1E\xF0")
            return self::type("ppt");
        else
            return null;
    }
}

Mimetype::register(Mimetype::TXT, "text/plain", ".txt", "text");
Mimetype::register(Mimetype::PDF, "application/pdf", ".pdf", "PDF");
Mimetype::register(3, "application/postscript", ".ps", "PostScript");
Mimetype::register(4, "application/vnd.ms-powerpoint", ".ppt", "PowerPoint");
Mimetype::register(5, "application/vnd.openxmlformats-officedocument.presentationml.presentation", ".pptx", "PowerPoint");
Mimetype::register(6, "video/mp4", ".mp4");
Mimetype::register(7, "video/x-msvideo", ".avi");
Mimetype::register(8, "application/json", ".json");

Mimetype::register_synonym("application/mspowerpoint", "application/vnd.ms-powerpoint");
Mimetype::register_synonym("application/powerpoint", "application/vnd.ms-powerpoint");
Mimetype::register_synonym("application/x-mspowerpoint", "application/vnd.ms-powerpoint");
