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
    const JPG = 9;
    const PNG = 10;
    const MAX_BUILTIN = 10;

    const PDF_TYPE = "application/pdf";
    const PS_TYPE = "application/postscript";
    const PPT_TYPE = "application/vnd.ms-powerpoint";
    const JPG_TYPE = "image/jpeg";
    const PNG_TYPE = "image/png";
    const TAR_TYPE = "application/x-tar";
    const ZIP_TYPE = "application/zip";
    const RAR_TYPE = "application/x-rar-compressed";

    public $mimetypeid;
    public $mimetype;
    public $extension;
    public $description;
    public $inline;

    private static $tmap = array();
    private static $sniffable = [
        self::PDF_TYPE => true, self::PPT_TYPE => true,
        self::JPG_TYPE => true, self::PNG_TYPE => true, self::RAR_TYPE => true
    ];

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
        self::$tmap[$m->mimetype] = $m;
        if ($m->mimetypeid)
            self::$tmap[$m->mimetypeid] = $m;
        if ($m->extension && !isset(self::$tmap[$m->extension]))
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

    static function sniff_type($content) {
        if (strncmp("%PDF-", $content, 5) == 0)
            return self::PDF_TYPE;
        else if (strncmp("%!PS-", $content, 5) == 0)
            return self::$tmap[self::PS]->mimetype;
        else if (substr($content, 512, 4) == "\x00\x6E\x1E\xF0")
            return self::$tmap[self::PPT]->mimetype;
        else if (strncmp($content, "\xFF\xD8\xFF\xD8", 4) == 0
                 || (strncmp($content, "\xFF\xD8\xFF\xE0", 4) == 0 && substr($content, 6, 6) == "JFIF\x00\x01")
                 || (strncmp($content, "\xFF\xD8\xFF\xE1", 4) == 0 && substr($content, 6, 6) == "Exif\x00\x00"))
            return self::JPG_TYPE;
        else if (strncmp($content, "\x89PNG\r\n\x1A\x0A", 8) == 0)
            return self::PNG_TYPE;
        else if (strncmp($content, "ustar\x0000", 8) == 0
                 || strncmp($content, "ustar  \x00", 8) == 0)
            return self::TAR_TYPE;
        else if (strncmp($content, "Rar!\x1A\x07\x00", 7) == 0
                 || strncmp($content, "Rar!\x1A\x07\x01\x00", 8) == 0)
            return self::RAR_TYPE;
        else
            return null;
    }

    static function is_sniff_type_reliable($content) {
        return strncmp("%PDF-", $content, 5) == 0
            || substr($content, 512, 4) == "\x00\x6E\x1E\xF0"
            || strncmp($content, "\xFF\xD8\xFF\xD8", 4) == 0
            || (strncmp($content, "\xFF\xD8\xFF\xE0", 4) == 0 && substr($content, 6, 6) == "JFIF\x00\x01")
            || (strncmp($content, "\xFF\xD8\xFF\xE1", 4) == 0 && substr($content, 6, 6) == "Exif\x00\x00")
            || strncmp($content, "\x89PNG\r\n\x1A\x0A", 8) == 0
            || strncmp($content, "Rar!\x1A\x07\x00", 7) == 0
            || strncmp($content, "Rar!\x1A\x07\x01\x00", 8) == 0;
    }

    static function is_sniffable($type) {
        return isset(self::$sniffable[self::type($type)]);
    }
}

Mimetype::make(Mimetype::TXT, "text/plain", ".txt", "text", true);
Mimetype::make(Mimetype::PDF, Mimetype::PDF_TYPE, ".pdf", "PDF", true);
Mimetype::make(Mimetype::PS, Mimetype::PS_TYPE, ".ps", "PostScript");
Mimetype::make(Mimetype::PPT, Mimetype::PPT_TYPE, ".ppt", "PowerPoint");
Mimetype::make(5, "application/vnd.openxmlformats-officedocument.presentationml.presentation", ".pptx", "PowerPoint");
Mimetype::make(6, "video/mp4", ".mp4");
Mimetype::make(7, "video/x-msvideo", ".avi");
Mimetype::make(Mimetype::JSON, "application/json", ".json", "JSON");
Mimetype::make(Mimetype::JPG, Mimetype::JPG_TYPE, ".jpg", "JPEG");
Mimetype::make(Mimetype::PNG, Mimetype::PNG_TYPE, ".png", "PNG");

Mimetype::make_synonym("application/mspowerpoint", Mimetype::PPT_TYPE);
Mimetype::make_synonym("application/powerpoint", Mimetype::PPT_TYPE);
Mimetype::make_synonym("application/x-mspowerpoint", Mimetype::PPT_TYPE);
Mimetype::make_synonym(".jpeg", Mimetype::JPG_TYPE);
