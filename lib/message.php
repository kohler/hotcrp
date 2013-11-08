<?php
// message.php -- HotCRP message support
// HotCRP is Copyright (c) 2006-2013 Eddie Kohler and Regents of the UC
// Distributed under an MIT-like license; see LICENSE

class Message {

    private static $messages = null;

    private static function load_one($f) {
        if (!file_exists($f))
            return false;
        $csv = new CsvParser(file_get_contents($f),
                             CsvParser::TYPE_GUESS);
        $csv->set_comment_chars("%#");
        if (($req = $csv->next())) {
            if (array_search("name", $req) !== false)
                $csv->set_header($req);
            else {
                $csv->set_header(array("name", "html"));
                $csv->unshift($req);
            }
            while (($req = $csv->next()) !== false)
                self::$messages[$req["name"]] = (object) $req;
            return true;
        } else
            return false;
    }

    private static function load() {
        global $ConfSitePATH, $Opt;
        self::$messages = array();
        self::load_one("$ConfSitePATH/src/messages.csv");
        if (($lang = @$Opt["lang"]))
            self::load_one("$ConfSitePATH/src/messages.$lang.csv");
        self::load_one("$ConfSitePATH/conf/messages-local.csv");
        if ($lang)
            self::load_one("$ConfSitePATH/conf/messages-local.$lang.csv");
    }

    public static function html($name, $expansions = null) {
        global $Conf;
        if (($html = $Conf->settingText("msg.$name")) !== false
            && ($p = strrpos($name, ".")))
            $html = $Conf->settingText("msg." . substr($name, 0, $p));
        if ($html === false)
            $html = self::default_html($name);
        if ($html && $expansions)
            foreach ($expansions as $k => $v)
                $html = str_replace("%$k%", $v, $html);
        return $html;
    }

    public static function default_html($name) {
        if (self::$messages === null)
            self::load();
        if (!($m = @self::$messages[$name])
            && ($p = strrpos($name, ".")))
            $m = self::$messages[substr($name, 0, $p)];
        if ($m && isset($m->html))
            return $m->html;
        else if ($m && isset($m->text))
            return htmlspecialchars($m->text);
        else
            return false;
    }

}
