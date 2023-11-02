<?php
// mentionparser.php -- HotCRP helper class for parsing mentions
// Copyright (c) 2006-2023 Eddie Kohler; see LICENSE.

class MentionParser {
    /** @param string $s
     * @param array<Contact|Author> ...$user_lists
     * @return \Generator<array{Contact|Author,int,int}> */
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
                            yield [$u, $pos, $pos + 1 + strlen($email)];
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
            $uset = [];
            foreach ($ulists as $listindex => $ulist) {
                foreach ($ulist as $u) {
                    if ($u->firstName === "" && $u->lastName === "") {
                        continue;
                    }
                    $fn = $u->firstName === "" ? "" : "{$u->firstName} ";
                    $n = $fn . ($u->lastName === "" ? "" : "{$u->lastName} ");
                    if (strpos($n, ".") !== false) {
                        $fn = preg_replace('/\.\s*/', " ", $fn);
                        $n = preg_replace('/\.\s*/', " ", $n);
                    }
                    $ux = [$u, $n, 0, strlen($fn === "" ? $n : $fn), $listindex];
                    if (self::match_word($ux, $w, $collator)
                        && !self::matches_contain($uset, $u->contactId)) {
                        $uset[] = $ux;
                    }
                }
            }

            // Match remaining words until there are no more words or there's
            // exactly one match
            $endpos = $pos + 1 + strlen($w);
            $pos2 = $pos + 1 + strlen($m[0]);
            $best_ux = $best_pos2 = $best_endpos = null;
            while (count($uset) > 1 && self::word_at($s, $pos2, $isascii, $m)) {
                if (count($ulists) > 1) {
                    usort($uset, function ($a, $b) {
                        return $a[4] <=> $b[4];
                    });
                    // One of the remaining matches has higher priority than the others.
                    // Remember it in case the next word fails to match anything.
                    if ($uset[0][4] < $uset[1][4]) {
                        $best_ux = $uset[0][0];
                        $best_pos2 = $pos2;
                        $best_endpos = $endpos;
                    } else {
                        $best_ux = null;
                    }
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
                $uset = [[$best_ux]];
                $pos2 = $best_pos2;
                $endpos = $best_endpos;
            }

            // Yield result if any
            if (count($uset) === 1
                && self::mention_ends_at($s, $endpos)) {
                $ux = $uset[0][0];
                // Heuristic to include the period after an initial
                if ($endpos < $len
                    && $s[$endpos] === "."
                    && $endpos - $pos >= 2
                    && strpos($ux->firstName . $ux->lastName, substr($s, $endpos - 1, 2)) !== false) {
                    ++$endpos;
                }
                yield [$ux, $pos, $endpos];
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

    /** @param array{Contact|Author,string,int,int,int} &$ux
     * @param string $w
     * @param Collator $collator
     * @return bool */
    static function match_word(&$ux, $w, $collator) {
        $sp = strpos($ux[1], " ", $ux[2]);
        if ($sp !== false) {
            $substr = substr($ux[1], $ux[2], $sp - $ux[2]);
            if ($collator->compare($w, $substr) === 0) {
                $ux[2] = $sp + 1;
                return true;
            } else if ($ux[2] >= $ux[3]) {
                return false;
            } else {
                $sp = strpos($ux[1], " ", $ux[3]);
                if ($sp !== false) {
                    $substr = substr($ux[1], $ux[3], $sp - $ux[3]);
                    if ($collator->compare($w, $substr) === 0) {
                        $ux[2] = $sp + 1;
                        return true;
                    }
                }
            }
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

    /** @param list<array{Contact|Author,string,int,int,int}> $uxs
     * @param int $cid
     * @return bool */
    static private function matches_contain($uxs, $cid) {
        foreach ($uxs as $ux) {
            if ($ux[0]->contactId === $cid)
                return true;
        }
        return false;
    }
}
