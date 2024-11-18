<?php
// subprocess.php -- Helper class for subprocess running
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class Subprocess {
    /** @param string $word
     * @return string */
    static function shell_quote_light($word) {
        if (preg_match('/\A[-_.,:+\/a-zA-Z0-9][-_.,:=+\/a-zA-Z0-9~]*\z/', $word)) {
            return $word;
        }
        return escapeshellarg($word);
    }

    /** @param list<string> $args
     * @return string */
    static function shell_quote_args($args) {
        $s = [];
        foreach ($args as $word) {
            $s[] = self::shell_quote_light($word);
        }
        return join(" ", $s);
    }

    /** @param list<string> $args
     * @return list<string>|string */
    static function args_to_command($args) {
        if (PHP_VERSION_ID < 70400) {
            return self::shell_quote_args($args);
        }
        return $args;
    }
}
