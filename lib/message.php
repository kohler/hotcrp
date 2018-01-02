<?php
// message.php -- HotCRP message support
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

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
        if (($lang = opt("lang")))
            self::load_one("$ConfSitePATH/src/messages.$lang.csv");
        self::load_one("$ConfSitePATH/conf/messages-local.csv");
        if ($lang)
            self::load_one("$ConfSitePATH/conf/messages-local.$lang.csv");
        if (opt("messages_include"))
            foreach (expand_includes($Opt["messages_include"], ["lang" => $lang]) as $f)
                self::load_one($f);
    }

    public static function default_html($name) {
        if (self::$messages === null)
            self::load();
        $msg = get(self::$messages, $name);
        if (!$msg && preg_match('/\A(.*)_(?:\$|n|\d+)(|\..*)\z/', $name, $m)) {
            $name = $m[1] . $m[2];
            $msg = get(self::$messages, $name);
        }
        if (!$msg && ($p = strrpos($name, ".")))
            $msg = get(self::$messages, substr($name, 0, $p));
        if ($msg && isset($msg->html))
            return $msg->html;
        else if ($msg && isset($msg->text))
            return htmlspecialchars($msg->text);
        else
            return false;
    }
}
