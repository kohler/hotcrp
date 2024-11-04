<?php
// mentionparser.php -- HotCRP helper class for parsing mentions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class MentionPhrase {
    /** @var Contact|Author
     * @readonly */
    public $user;
    /** @var int
     * @readonly */
    public $pos1;
    /** @var int
     * @readonly */
    public $pos2;

    /** @param Contact|Author $user
     * @param int $pos1
     * @param int $pos2 */
    function __construct($user, $pos1, $pos2) {
        $this->user = $user;
        $this->pos1 = $pos1;
        $this->pos2 = $pos2;
    }

    /** @return bool */
    function named() {
        return $this->user instanceof Contact
            || $this->user->status !== Author::STATUS_ANONYMOUS_REVIEWER;
    }
}

class PossibleMentionParse implements JsonSerializable {
    /** @var Contact|Author */
    public $user;
    /** @var string */
    public $text;
    /** @var int */
    public $matchpos;
    /** @var int */
    public $breakpos;
    /** @var int */
    public $priority;

    /** @param Contact|Author $user
     * @param string $text
     * @param int $breakpos
     * @param int $priority */
    function __construct($user, $text, $breakpos, $priority) {
        $this->user = $user;
        $this->text = $text;
        $this->matchpos = 0;
        $this->breakpos = $breakpos;
        $this->priority = $priority;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $j = [];
        foreach (["email", "firstName", "lastName"] as $k) {
            if ($this->user->$k)
                $j[$k] = $this->user->$k;
        }
        return $j + ["text" => $this->text, "matchpos" => $this->matchpos, "breakpos" => $this->breakpos, "priority" => $this->priority];
    }
}

class MentionParser {
    /** @param string $s
     * @param array<Contact|Author> ...$user_lists
     * @return \Generator<MentionPhrase> */
    static function parse($s, ...$user_lists) {
        // filter out empty user lists
        $ulists = [];
        foreach ($user_lists as $ulist) {
            if (!empty($ulist)) {
                $ulists[] = $ulist;
            }
        }

        $pos = 0;
        $len = strlen($s);
        $isascii = $collator = $strength = null;
        while (($pos = strpos($s, "@", $pos)) !== false) {
            // check that the mention is isolated on the left
            if (($pos > 0
                 && !ctype_space($s[$pos - 1])
                 /** @phan-suppress-next-line PhanParamSuspiciousOrder */
                 && strpos("([{-+,;/", $s[$pos - 1]) === false
                 && (/* not en- or em-dash */ $pos < 4
                     || $s[$pos - 3] !== "\xe2"
                     || $s[$pos - 2] !== "\x80"
                     || ($s[$pos - 1] !== "\x93" && $s[$pos - 1] !== "\x94")))
                || $pos === $len - 1
                || ctype_space($s[$pos + 1])) {
                ++$pos;
                continue;
            }

            // search for a match, up to 3 words
            $isascii = $isascii ?? is_usascii($s);

            // check emails
            if (($email = validate_email_at($s, $pos + 1))) {
                foreach ($ulists as $ulist) {
                    foreach ($ulist as $u) {
                        if (strcasecmp($u->email, $email) === 0
                            && self::mention_ends_at($s, $pos + 1 + strlen($email))) {
                            yield new MentionPhrase($u, $pos, $pos + 1 + strlen($email));
                            $pos += 1 + strlen($email);
                            continue 3;
                        }
                    }
                }
            }

            // check names
            if (!self::word_at($s, $pos + 1, $isascii, $m)) {
                ++$pos;
                continue;
            }

            $w = $m[1];
            $collator = $collator ?? new Collator("en_US.utf8");
            self::set_strength($collator, $strength, $isascii || is_usascii($w) ? Collator::PRIMARY : Collator::SECONDARY);

            // Match the first word
            $uset = $matchuids = [];
            foreach ($ulists as $listindex => $ulist) {
                foreach ($ulist as $u) {
                    if (in_array($u->contactId, $matchuids)) {
                        continue;
                    }
                    // check name
                    if ($u->firstName !== "" || $u->lastName !== "") {
                        $fn = $u->firstName === "" ? "" : "{$u->firstName} ";
                        $n = $fn . ($u->lastName === "" ? "" : "{$u->lastName} ");
                        if (strpos($n, ".") !== false) {
                            $fn = preg_replace('/\.\s*/', " ", $fn);
                            $n = preg_replace('/\.\s*/', " ", $n);
                        }
                        $ux = new PossibleMentionParse($u, $n, strlen($fn === "" ? $n : $fn), $listindex);
                        if (self::match_word($ux, $w, $collator)) {
                            $uset[] = $ux;
                            $matchuids[] = $u->contactId;
                            continue;
                        }
                    }
                    // check email prefix (require 2 or more letters)
                    $at = (int) strpos($u->email, "@");
                    if ($at > 1
                        && substr_compare($s, $u->email, $pos + 1, $at, true) === 0
                        && self::mention_ends_at($s, $pos + 1 + $at)) {
                        $uset[] = new PossibleMentionParse($u, substr($u->email, 0, $at) . " ", $at + 1, $listindex);
                        $matchuids[] = $u->contactId;
                        continue;
                    }
                }
            }

            // Match remaining words until there are no more words or there's
            // exactly one match
            $endpos = $pos + 1 + strlen($w);
            $pos2 = $pos + 1 + strlen($m[0]);
            $best_ux = $best_pos2 = $best_endpos = null;
            $sorted = count($ulists) === 1 || count($uset) <= 1;
            while (count($uset) > 1 && self::word_at($s, $pos2, $isascii, $m)) {
                if (!$sorted) {
                    usort($uset, function ($a, $b) {
                        return $a->priority <=> $b->priority;
                    });
                }

                // One of the remaining matches has higher priority than the others.
                // Remember it in case the next word fails to match anything.
                if ($uset[0]->priority < $uset[1]->priority) {
                    $best_ux = $uset[0];
                    $best_pos2 = $pos2;
                    $best_endpos = $endpos;
                } else {
                    $best_ux = null;
                }

                $w = $m[1];
                self::set_strength($collator, $strength, $isascii || is_usascii($w) ? Collator::PRIMARY : Collator::SECONDARY);
                $xuset = [];
                foreach ($uset as $ux) {
                    if (self::match_word($ux, $w, $collator)) {
                        $xuset[] = $ux;
                    }
                }
                $uset = $xuset;
                $endpos = $pos2 + strlen($w);
                $pos2 = $pos2 + strlen($m[0]);
            }

            // If exactly one match, consume remaining matching words until done
            while (count($uset) === 1 && self::word_at($s, $pos2, $isascii, $m)) {
                $w = $m[1];
                self::set_strength($collator, $strength, $isascii || is_usascii($w) ? Collator::PRIMARY : Collator::SECONDARY);
                if (!self::match_word($uset[0], $w, $collator)) {
                    break;
                }
                $endpos = $pos2 + strlen($w);
                $pos2 = $pos2 + strlen($m[0]);
            }

            // If we ended with no matches but there was one best match at some
            // point, select that
            if (count($uset) === 0 && $best_ux !== null) {
                $uset = [$best_ux];
                $pos2 = $best_pos2;
                $endpos = $best_endpos;
            }

            // Yield result if any
            if (count($uset) === 1
                && self::mention_ends_at($s, $endpos)) {
                $ux = $uset[0]->user;
                // Heuristic to include the period after an initial
                if ($endpos < $len
                    && $s[$endpos] === "."
                    && $endpos - $pos >= 2
                    && strpos($ux->firstName . $ux->lastName, substr($s, $endpos - 1, 2)) !== false) {
                    ++$endpos;
                }
                yield new MentionPhrase($ux, $pos, $endpos);
            }

            $pos = $pos2;
        }
    }

    /** @param string $s
     * @param int $pos
     * @param bool $isascii
     * @param list<string> &$m
     * @return bool */
    static function word_at($s, $pos, $isascii, &$m) {
        if ($isascii) {
            return !!preg_match('/\G([A-Za-z](?:[A-Za-z0-9]|-(?=[A-Za-z]))*)\.?[ \t]*\r?\n?[ \t]*/', $s, $m, 0, $pos);
        } else {
            return !!preg_match('/\G(\pL(?:[\pL\pM\pN]|-(?=\pL))*)\.?[ \t]*\r?\n?[ \t]*/u', $s, $m, 0, $pos);
        }
    }

    /** @param string $s
     * @param int $pos
     * @return bool */
    static function mention_ends_at($s, $pos) {
        return $pos === strlen($s)
            /** @phan-suppress-next-line PhanParamSuspiciousOrder */
            || strpos(".,;:?!)]}-/ \t\r\n\f\v", $s[$pos]) !== false
            || preg_match('/\G(?!@)[\p{Po}\p{Pd}\p{Pe}\p{Pf}\pS\pZ]/u', $s, $m, 0, $pos);
    }

    /** @param PossibleMentionParse $ux
     * @param string $w
     * @param Collator $collator
     * @return bool */
    static function match_word($ux, $w, $collator) {
        $sp = strpos($ux->text, " ", $ux->matchpos);
        if ($sp === false) {
            return false;
        }
        $substr = substr($ux->text, $ux->matchpos, $sp - $ux->matchpos);
        if ($collator->compare($w, $substr) === 0) {
            $ux->matchpos = $sp + 1;
            return true;
        }
        if ($ux->matchpos >= $ux->breakpos) {
            return false;
        }
        $sp = strpos($ux->text, " ", $ux->breakpos);
        if ($sp === false) {
            return false;
        }
        $substr = substr($ux->text, $ux->breakpos, $sp - $ux->breakpos);
        if ($collator->compare($w, $substr) === 0) {
            $ux->matchpos = $sp + 1;
            return true;
        }
        return false;
    }

    /** @param Collator $collator
     * @param ?int &$strength
     * @param int $xstrength */
    static private function set_strength($collator, &$strength, $xstrength) {
        if ($xstrength !== $strength) {
            $collator->setStrength($xstrength);
            $strength = $xstrength;
        }
    }
}
