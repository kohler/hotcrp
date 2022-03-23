<?php
// diffmatch.php -- PHP diff-match-patch.
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.
// Ported from Neil Fraser's diff-match-patch.

class DiffMatch {
    /** @var float */
    public $Diff_Timeout = 1.0;
    /** @var int */
    public $Diff_EditCost = 4;
    /** @var bool */
    public $Fix_UTF8 = true;

    /** @var bool */
    public $checklines;
    /** @var float */
    public $deadline;
    /** @var 0|1
     * $iota === 1 if we are doing a line diff, so the unit is 2 bytes */
    private $iota = 0;

    const DIFF_DELETE = -1;
    const DIFF_INSERT = 1;
    const DIFF_EQUAL = 0;

    /** @param string $text1
     * @param string $text2
     * @param ?bool $checklines
     * @param ?float $deadline
     * @return list<DiffMatch_Diff> */
    function diff($text1, $text2, $checklines = null, $deadline = null) {
        if ($text1 === null || $text2 === null) {
            throw new TypeError;
        }
        if ($deadline === null) {
            if ($this->Diff_Timeout <= 0) {
                $deadline = INF;
            } else {
                $deadline = microtime(true) + $this->Diff_Timeout;
            }
        }
        $diffs = $this->diff_main($text1, $text2, $checklines ?? true, $deadline);
        if ($this->Fix_UTF8) {
            $this->diff_cleanupUnicode($diffs);
        }
        return $diffs;
    }

    /** @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param bool $checklines
     * @param float $deadline
     * @return list<DiffMatch_Diff> */
    private function diff_main($text1, $text2, $checklines = null, $deadline = null) {
        if ($text1 === $text2) {
            if ($text1 !== "") {
                return [new DiffMatch_Diff(self::DIFF_EQUAL, $text1)];
            } else {
                return [];
            }
        }

        $commonlength = $this->diff_commonPrefix($text1, $text2);
        if ($commonlength !== 0) {
            $commonprefix = substr($text1, 0, $commonlength);
            $text1 = substr($text1, $commonlength);
            $text2 = substr($text2, $commonlength);
        } else {
            $commonprefix = "";
        }

        $commonlength = $this->diff_commonSuffix($text1, $text2);
        if ($commonlength !== 0) {
            $commonsuffix = substr($text1, -$commonlength);
            $text1 = substr($text1, 0, -$commonlength);
            $text2 = substr($text2, 0, -$commonlength);
        } else {
            $commonsuffix = "";
        }

        $diffs = $this->diff_compute_($text1, $text2, $checklines, $deadline);

        if ($commonprefix !== "") {
            array_unshift($diffs, new DiffMatch_Diff(self::DIFF_EQUAL, $commonprefix));
        }
        if ($commonsuffix !== "") {
            $diffs[] = new DiffMatch_Diff(self::DIFF_EQUAL, $commonsuffix);
        }
        $this->diff_cleanupMerge($diffs);
        return $diffs;
    }

    /**
     * Find the differences between two texts.  Assumes that the texts do not
     * have any common prefix or suffix.
     * @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param bool $checklines Speedup flag.
     * @param float $deadline Time when the diff should be complete by.
     * @return list<DiffMatch_Diff> */
    private function diff_compute_($text1, $text2, $checklines, $deadline) {
        if ($text1 === "") {
            return [new DiffMatch_Diff(self::DIFF_INSERT, $text2)];
        }
        if ($text2 === "") {
            return [new DiffMatch_Diff(self::DIFF_DELETE, $text1)];
        }

        if (strlen($text1) > strlen($text2)) {
            $longtext = $text1;
            $shorttext = $text2;
        } else {
            $longtext = $text2;
            $shorttext = $text1;
        }

        $i = strpos($longtext, $shorttext);
        while ($i !== false && ($i & $this->iota) === 1) {
            $i = strpos($longtext, $shorttext, $i + 1);
        }
        if ($i !== false) {
            // Shorter text is inside the longer text (speedup).
            $op = strlen($text1) > strlen($text2) ? self::DIFF_DELETE : self::DIFF_INSERT;
            return [
                new DiffMatch_Diff($op, substr($longtext, 0, $i)),
                new DiffMatch_Diff(self::DIFF_EQUAL, $shorttext),
                new DiffMatch_Diff($op, substr($longtext, $i + strlen($shorttext)))
            ];
        }

        if (strlen($shorttext) === 1) {
            // Single character string.
            // After the previous speedup, the character can't be an equality.
            return [
                new DiffMatch_Diff(self::DIFF_DELETE, $text1),
                new DiffMatch_Diff(self::DIFF_INSERT, $text2)
            ];
        }

        // Check to see if the problem can be split in two.
        $hm = $this->diff_halfMatch_($text1, $text2);
        if ($hm !== null) {
            // Send both pairs off for separate processing.
            $diffs_a = $this->diff_main($hm[0], $hm[2], $checklines, $deadline);
            $diffs_a[] = new DiffMatch_Diff(self::DIFF_EQUAL, $hm[4]);
            array_push($diffs_a, ...$this->diff_main($hm[1], $hm[3], $checklines, $deadline));
            return $diffs_a;
        }

        if ($checklines && strlen($text1) > 100 && strlen($text2) > 100) {
            return $this->diff_lineMode_($text1, $text2, $deadline);
        } else {
            return $this->diff_bisect_($text1, $text2, $deadline);
        }
    }


    /**
     * Do a quick line-level diff on both strings, then rediff the parts for
     * greater accuracy.
     * This speedup can produce non-minimal diffs.
     * @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param float $deadline Time when the diff should be complete by.
     * @return list<DiffMatch_Diff> */
    private function diff_lineMode_($text1, $text2, $deadline) {
        assert($this->iota === 0);
        // Scan the text on a line-by-line basis first.
        list($text1, $text2, $lineArray) = $this->diff_linesToChars_($text1, $text2);

        $this->iota = 1;
        $diffs = $this->diff_main($text1, $text2, false, $deadline);
        $this->iota = 0;

        // Convert the diff back to original text.
        $this->diff_charsToLines_($diffs, $lineArray);
        // Eliminate freak matches (e.g. blank lines)
        $this->diff_cleanupSemantic($diffs);

        // Rediff any replacement blocks, this time character-by-character.
        // Add a dummy entry at the end.
        $diffs[] = new DiffMatch_Diff(self::DIFF_EQUAL, "");
        $opos = 0;
        $out = [];
        foreach ($diffs as $diff) {
            if ($diff->op !== self::DIFF_EQUAL) {
                $opos = $this->diff_merge1($out, $opos, $diff);
            } else {
                // Upon reaching an equality, check for prior redundancies.
                if ($opos > 1
                    && $out[$opos-1]->op === self::DIFF_INSERT
                    && $out[$opos-2]->op === self::DIFF_DELETE) {
                    $subDiff = $this->diff_main($out[$opos-2]->text, $out[$opos-1]->text, false, $deadline);
                    array_splice($out, $opos - 2, 2, $subDiff);
                    $opos = count($out);
                }
                if ($diff->text !== "") {
                    $out[] = $diff;
                    ++$opos;
                }
            }
        }

        return $out;
    }


    /**
     * Find the 'middle snake' of a diff, split the problem in two
     * and return the recursively constructed diff.
     * See Myers 1986 paper: An O(ND) Difference Algorithm and Its Variations.
     * @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param float $deadline Time at which to bail if not yet complete.
     * @return list<DiffMatch_Diff> Array of diff tuples.
     */
    private function diff_bisect_($text1, $text2, $deadline) {
        // Cache the text lengths to prevent multiple calls.
        $text1_length = strlen($text1) >> $this->iota;
        $text2_length = strlen($text2) >> $this->iota;
        $max_d = ($text1_length + $text2_length + 1) >> 1;
        $v_offset = $max_d;
        $v_length = 2 * $max_d;
        $v1 = array_fill(0, $v_length, -1);
        $v2 = array_fill(0, $v_length, -1);
        $v1[$v_offset + 1] = 0;
        $v2[$v_offset + 1] = 0;
        $delta = $text1_length - $text2_length;
        // If the total number of characters is odd, then the front path will collide
        // with the reverse path.
        $front = ($delta % 2 !== 0);
        // Offsets for start and end of k loop.
        // Prevents mapping of space beyond the grid.
        $k1start = 0;
        $k1end = 0;
        $k2start = 0;
        $k2end = 0;
        for ($d = 0; $d < $max_d; $d += 1) {
            // Bail out if deadline is reached.
            if (microtime(true) > $deadline) {
                break;
            }

            // Walk the front path one step.
            for ($k1 = -$d + $k1start; $k1 <= $d - $k1end; $k1 += 2) {
                $k1_offset = $v_offset + $k1;
                if ($k1 === -$d
                    || ($k1 !== $d && $v1[$k1_offset - 1] < $v1[$k1_offset + 1])) {
                    $x1 = $v1[$k1_offset + 1];
                } else {
                    $x1 = $v1[$k1_offset - 1] + 1;
                }
                $y1 = $x1 - $k1;
                if ($this->iota === 0) {
                    while ($x1 < $text1_length && $y1 < $text2_length &&
                           $text1[$x1] === $text2[$y1]) {
                        ++$x1;
                        ++$y1;
                    }
                } else {
                    while ($x1 < $text1_length && $y1 < $text2_length &&
                           $text1[$x1 << 1] === $text2[$y1 << 1] &&
                           $text1[($x1 << 1) + 1] === $text2[($y1 << 1) + 1]) {
                        ++$x1;
                        ++$y1;
                    }
                }
                $v1[$k1_offset] = $x1;
                if ($x1 > $text1_length) {
                    // Ran off the right of the graph.
                    $k1end += 2;
                } else if ($y1 > $text2_length) {
                    // Ran off the bottom of the graph.
                    $k1start += 2;
                } else if ($front) {
                    $k2_offset = $v_offset + $delta - $k1;
                    if ($k2_offset >= 0 && $k2_offset < $v_length && $v2[$k2_offset] !== -1) {
                        // Mirror x2 onto top-left coordinate system.
                        $x2 = $text1_length - $v2[$k2_offset];
                        if ($x1 >= $x2) {
                            // Overlap detected.
                            return $this->diff_bisectSplit_($text1, $text2, $x1, $y1, $deadline);
                        }
                    }
                }
            }

            // Walk the reverse path one step.
            for ($k2 = -$d + $k2start; $k2 <= $d - $k2end; $k2 += 2) {
                $k2_offset = $v_offset + $k2;
                if ($k2 === -$d || ($k2 !== $d && $v2[$k2_offset - 1] < $v2[$k2_offset + 1])) {
                    $x2 = $v2[$k2_offset + 1];
                } else {
                    $x2 = $v2[$k2_offset - 1] + 1;
                }
                $y2 = $x2 - $k2;
                if ($this->iota === 0) {
                    while ($x2 < $text1_length && $y2 < $text2_length &&
                           $text1[$text1_length - $x2 - 1] === $text2[$text2_length - $y2 - 1]) {
                        ++$x2;
                        ++$y2;
                    }
                } else {
                    while ($x2 < $text1_length && $y2 < $text2_length &&
                           $text1[($text1_length - $x2 - 1) << 1] === $text2[($text2_length - $y2 - 1) << 1] &&
                           $text1[(($text1_length - $x2 - 1) << 1) + 1] === $text2[(($text2_length - $y2 - 1) << 1) + 1]) {
                        ++$x2;
                        ++$y2;
                    }
                }
                $v2[$k2_offset] = $x2;
                if ($x2 > $text1_length) {
                    // Ran off the left of the graph.
                    $k2end += 2;
                } else if ($y2 > $text2_length) {
                    // Ran off the top of the graph.
                    $k2start += 2;
                } else if (!$front) {
                    $k1_offset = $v_offset + $delta - $k2;
                    if ($k1_offset >= 0 && $k1_offset < $v_length && $v1[$k1_offset] !== -1) {
                        $x1 = $v1[$k1_offset];
                        $y1 = $v_offset + $x1 - $k1_offset;
                        // Mirror x2 onto top-left coordinate system.
                        $x2 = $text1_length - $x2;
                        if ($x1 >= $x2) {
                            // Overlap detected.
                            return $this->diff_bisectSplit_($text1, $text2, $x1, $y1, $deadline);
                        }
                    }
                }
            }
        }

        // Diff took too long and hit the deadline or
        // number of diffs equals number of characters, no commonality at all.
        return [new DiffMatch_Diff(self::DIFF_DELETE, $text1),
                new DiffMatch_Diff(self::DIFF_INSERT, $text2)];
    }


    /**
     * Given the location of the 'middle snake', split the diff in two parts
     * and recurse.
     * @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param int $x Index of split point in text1.
     * @param int $y Index of split point in text2.
     * @param float $deadline Time at which to bail if not yet complete.
     * @return list<DiffMatch_Diff> Array of diff tuples.
     */
    private function diff_bisectSplit_($text1, $text2, $x, $y, $deadline) {
        $text1a = substr($text1, 0, $x << $this->iota);
        $text2a = substr($text2, 0, $y << $this->iota);
        $text1b = substr($text1, $x << $this->iota);
        $text2b = substr($text2, $y << $this->iota);

        // Compute both diffs serially.
        $diffs = $this->diff_main($text1a, $text2a, false, $deadline);
        array_push($diffs, ...$this->diff_main($text1b, $text2b, false, $deadline));
        return $diffs;
    }


    /**
     * Split a text into an array of strings.  Reduce the texts to a string of
     * hashes where each Unicode character represents one line.
     * Modifies linearray and linehash through being a closure.
     * @param string $text String to encode.
     * @param list<string> &$lineArray
     * @param array<string,int> &$lineHash
     * @param int $maxLines
     * @return string Encoded string.
     */
    private function diff_linesToCharsMunge_($text, &$lineArray, &$lineHash, $maxLines) {
        $chars = "";
        // Walk the text, pulling out a substring for each line.
        // text.split('\n') would would temporarily double our memory footprint.
        // Modifying text would create many large strings to garbage collect.
        $lineStart = 0;
        // Keeping our own length variable is faster than looking it up.
        $lineArrayLength = count($lineArray);
        $textLen = strlen($text);
        while ($lineStart < $textLen) {
            $lineEnd = strpos($text, "\n", $lineStart);
            if ($lineEnd === false) {
                $lineEnd = $textLen - 1;
            }
            $line = substr($text, $lineStart, $lineEnd + 1 - $lineStart);

            $ch = $lineHash[$line] ?? null;
            if ($ch === null) {
                if ($lineArrayLength === $maxLines) {
                    // Bail out once we reach maxLines
                    $line = substr($text, $lineStart);
                    $lineEnd = $textLen - 1;
                }
                $lineHash[$line] = $ch = $lineArrayLength;
                $lineArray[] = $line;
                ++$lineArrayLength;
            }
            $chars .= chr($ch & 0xFF) . chr($ch >> 8);
            $lineStart = $lineEnd + 1;
        }
        return $chars;
    }


    /**
     * Split two texts into an array of strings.  Reduce the texts to a string of
     * hashes where each Unicode character represents one line.
     * @param string $text1 First string.
     * @param string $text2 Second string.
     * @return array{string,string,list<string>}
     *     An object containing the encoded text1, the encoded text2 and
     *     the array of unique strings.
     *     The zeroth element of the array of unique strings is intentionally blank.
     */
    private function diff_linesToChars_($text1, $text2) {
        $lineArray = [""];  // e.g. $lineArray[4] === "Hello\n"
        $lineHash = [];     // e.g. $lineHash["Hello\n"] === 4

        $chars1 = $this->diff_linesToCharsMunge_($text1, $lineArray, $lineHash, 40000);
        $chars2 = $this->diff_linesToCharsMunge_($text2, $lineArray, $lineHash, 65535);
        return [$chars1, $chars2, $lineArray];
    }

    /**
     * Rehydrate the text in a diff from a string of line hashes to real lines of
     * text.
     * @param list<DiffMatch_Diff> $diffs Array of diff tuples.
     * @param list<string> $lineArray Array of unique strings.
     */
    private function diff_charsToLines_($diffs, $lineArray) {
        foreach ($diffs as $diff) {
            assert(strlen($diff->text) % 2 === 0);
            $chars = $diff->text;
            $len = strlen($chars);
            $text = [];
            for ($j = 0; $j !== $len; $j += 2) {
                $ch = ord($diff->text[$j]) | (ord($diff->text[$j+1]) << 8);
                $text[] = $lineArray[$ch];
            }
            $diff->text = join("", $text);
        }
    }


    /** @param list<DiffMatch_Diff> $diffs */
    private function diff_checkEvenLengths($diffs) {
        if ($this->iota) {
            foreach ($diffs as $diff) {
                assert(strlen($diff->text) % 2 === 0);
            }
        }
    }


    /**
     * Determine the common prefix of two strings.
     * @param string $text1 First string.
     * @param string $text2 Second string.
     * @return int The number of characters common to the start of each
     *     string.
     */
    function diff_commonPrefix($text1, $text2) {
        $len1 = strlen($text1);
        $len2 = strlen($text2);
        // Quick check for common null cases.
        if ($len1 === 0 || $len2 === 0 || $text1[0] !== $text2[0]) {
            return 0;
        }
        // Binary search.
        // Performance analysis: https://neil.fraser.name/news/2007/10/09/
        $ptrmin = 0;
        $ptrmax = min(strlen($text1), strlen($text2));
        $ptrmid = $ptrmax;
        $ptrstart = 0;
        while ($ptrmin < $ptrmid) {
            if (substr_compare($text1, substr($text2, $ptrstart), $ptrstart, $ptrmid - $ptrstart) === 0) {
                $ptrmin = $ptrstart = $ptrmid;
            } else {
                $ptrmax = $ptrmid;
            }
            $ptrmid = $ptrmin + (($ptrmax - $ptrmin) >> 1);
            if (($ptrmid & $this->iota) === 1) {
                --$ptrmid;
            }
        }
        return $ptrmid;
    }


    /**
     * Determine the common suffix of two strings.
     * @param string $text1 First string.
     * @param string $text2 Second string.
     * @return int The number of characters common to the end of each string.
     */
    private function diff_commonSuffix($text1, $text2) {
        $len1 = strlen($text1);
        $len2 = strlen($text2);
        // Quick check for common null cases.
        if ($len1 === 0
            || $len2 === 0
            || $text1[$len1-1] !== $text2[$len2-1]
            || ($this->iota === 1 && $text1[$len1-2] !== $text2[$len2-2])) {
            return 0;
        }

        // Binary search.
        // Performance analysis: https://neil.fraser.name/news/2007/10/09/
        $ptrmin = 0;
        $ptrmax = min($len1, $len2);
        $ptrmid = $ptrmax;
        $ptrend = 0;
        while ($ptrmin < $ptrmid) {
            if (substr_compare($text1, substr($text2, $len2 - $ptrmid), $len1 - $ptrmid, $ptrmid - $ptrend) === 0) {
                $ptrmin = $ptrend = $ptrmid;
            } else {
                $ptrmax = $ptrmid;
            }
            $ptrmid = $ptrmin + (($ptrmax - $ptrmin) >> 1);
            if (($ptrmid & $this->iota) === 1) {
                --$ptrmid;
            }
        }
        return $ptrmid;
    }


    /**
     * Does a substring of shorttext exist within longtext such that the substring
     * is at least half the length of longtext?
     * @param string $longtext Longer string.
     * @param string $shorttext Shorter string.
     * @param int $i Start index of quarter length substring within longtext.
     * @return ?array{string,string,string,string,string} Five element Array, containing the prefix of
     *     longtext, the suffix of longtext, the prefix of shorttext, the suffix
     *     of shorttext and the common middle.  Or null if there was no match.
     * @private
     */
    private function diff_halfMatchI_($longtext, $shorttext, $i) {
        // Start with a 1/4 length substring at position i as a seed.
        if (($i & $this->iota) === 1) {
            ++$i;
        }
        $slen = strlen($longtext) >> 2;
        if (($slen & $this->iota) === 1) {
            --$slen;
        }
        $seed = substr($longtext, $i, $slen);
        $j = -1;
        $bestlen = $bestlongpos = $bestshortpos = 0;
        while (($j = strpos($shorttext, $seed, $j + 1)) !== false) {
            $prefixLength = $this->diff_commonPrefix(substr($longtext, $i), substr($shorttext, $j));
            $suffixLength = $this->diff_commonSuffix(substr($longtext, 0, $i), substr($shorttext, 0, $j));
            if ($bestlen < $suffixLength + $prefixLength) {
                $bestlen = $suffixLength + $prefixLength;
                $bestlongpos = $i - $suffixLength;
                $bestshortpos = $j - $suffixLength;
            }
        }
        if ($bestlen * 2 >= strlen($longtext)) {
            assert(($bestlongpos & $this->iota) === 0);
            assert(($bestshortpos & $this->iota) === 0);
            assert(($bestlen & $this->iota) === 0);
            return [
                substr($longtext, 0, $bestlongpos),
                substr($longtext, $bestlongpos + $bestlen),
                substr($shorttext, 0, $bestshortpos),
                substr($shorttext, $bestshortpos + $bestlen),
                substr($shorttext, $bestshortpos, $bestlen)
            ];
        } else {
            return null;
        }
    }

    /**
     * Do the two texts share a substring which is at least half the length of the
     * longer text?
     * This speedup can produce non-minimal diffs.
     * @param string $text1 First string.
     * @param string $text2 Second string.
     * @return ?array{string,string,string,string,string} Five element Array, containing the prefix of
     *     text1, the suffix of text1, the prefix of text2, the suffix of
     *     text2 and the common middle.  Or null if there was no match.
     */
    private function diff_halfMatch_($text1, $text2) {
        if ($this->Diff_Timeout <= 0) {
            // Don't risk returning a non-optimal diff if we have unlimited time.
            return null;
        }
        if (strlen($text1) > strlen($text2)) {
            $longtext = $text1;
            $shorttext = $text2;
        } else {
            $longtext = $text2;
            $shorttext = $text1;
        }
        if (strlen($longtext) < (4 << $this->iota)
            || (strlen($shorttext) << 1) < strlen($longtext)) {
            return null;  // Pointless.
        }

        // First check if the second quarter is the seed for a half-match.
        $hm1 = $this->diff_halfMatchI_($longtext, $shorttext, (strlen($longtext) + 3) >> 2);
        // Check again based on the third quarter.
        $hm2 = $this->diff_halfMatchI_($longtext, $shorttext, (strlen($longtext) + 1) >> 1);
        if ($hm1 === null && $hm2 === null) {
            return null;
        } else if ($hm2 === null
                   || ($hm1 !== null && strlen($hm1[4]) > strlen($hm2[4]))) {
            $hm = $hm1;
        } else {
            $hm = $hm2;
        }

        if (strlen($text1) > strlen($text2)) {
            return $hm;
        } else {
            return [$hm[2], $hm[3], $hm[0], $hm[1], $hm[4]];
        }
    }


    /**
     * Determine if the suffix of one string is the prefix of another.
     * @param string $text1 First string.
     * @param string $text2 Second string.
     * @return int The number of characters common to the end of the first
     *     string and the start of the second string.
     */
    private function diff_commonOverlap_($text1, $text2) {
        assert($this->iota === 0);
        // Cache the text lengths to prevent multiple calls.
        $text1_length = strlen($text1);
        $text2_length = strlen($text2);
        // Eliminate the null case.
        if ($text1_length === 0 || $text2_length === 0) {
            return 0;
        }
        // Truncate the longer string.
        if ($text1_length > $text2_length) {
            $text1 = substr($text1, $text1_length - $text2_length);
        } else if ($text1_length < $text2_length) {
            $text2 = substr($text2, 0, $text1_length);
        }
        $text_length = min($text1_length, $text2_length);
        // Quick check for the worst case.
        if ($text1 === $text2) {
            return $text_length;
        }

        // Start by looking for a single character match
        // and increase length until no match is found.
        // Performance analysis: https://neil.fraser.name/news/2010/11/04/
        $best = 0;
        $length = 1;
        while (true) {
            $pattern = substr($text1, $text_length - $length);
            $found = strpos($text2, $pattern);
            if ($found === false) {
                return $best;
            }
            $length += $found;
            if ($found === 0
                || substr_compare($text1, $text2, $text_length - $length, $length) === 0) {
                $best = $length;
                ++$length;
            }
        }
    }


    /**
     * Reduce the number of edits by eliminating semantically trivial equalities.
     * @param list<DiffMatch_Diff> &$diffs Array of diff tuples.
     */
    function diff_cleanupSemantic(&$diffs) {
        assert($this->iota === 0);
        $pos = 1;
        $ndiffs = count($diffs);
        while ($pos < $ndiffs) {
            $diff = $diffs[$pos];
            if ($diff->op === self::DIFF_EQUAL) {
                $preins = $pos > 0 && $diffs[$pos - 1]->op === self::DIFF_INSERT ? 1 : 0;
                $predel = $pos > $preins && $diffs[$pos - $preins - 1]->op === self::DIFF_DELETE ? 1 : 0;
                $postdel = $pos + 1 < $ndiffs && $diffs[$pos + 1]->op === self::DIFF_DELETE ? 1 : 0;
                $postins = $pos + $postdel + 1 < $ndiffs && $diffs[$pos + $postdel + 1]->op === self::DIFF_INSERT ? 1 : 0;
                // Eliminate an equality that is smaller or equal to the edits on both
                // sides of it.
                $len = strlen($diff->text);
                if ($preins + $predel !== 0
                    && $postins + $postdel !== 0
                    && $len <= max($preins ? strlen($diffs[$pos - 1]->text) : 0,
                                   $predel ? strlen($diffs[$pos - $preins - 1]->text) : 0)
                    && $len <= max($postins ? strlen($diffs[$pos + $postdel + 1]->text) : 0,
                                   $postdel ? strlen($diffs[$pos + 1]->text) : 0)) {
                    if ($predel) {
                        $diffs[$pos - $preins - 1]->text .= $diff->text;
                        if ($postdel) {
                            $diffs[$pos - $preins - 1]->text .= $diffs[$pos + 1]->text;
                        }
                    } else {
                        $diffs[$pos] = $diffs[$pos - 1];
                        if ($postdel) {
                            $diffs[$pos - 1] = $diffs[$pos + 1];
                            $diffs[$pos - 1]->text = $diff->text . $diffs[$pos - 1]->text;
                        } else {
                            $diffs[$pos - 1] = $diff;
                            $diff->op = self::DIFF_DELETE;
                        }
                        ++$pos;
                        $predel = 1;
                        $postdel = 0;
                    }
                    if ($preins) {
                        $diffs[$pos - 1]->text .= $diff->text;
                        if ($postins) {
                            $diffs[$pos - 1]->text .= $diffs[$pos + $postdel + 1]->text;
                        }
                    } else if ($postins) {
                        $diffs[$pos + $postdel + 1]->text = $diff->text . $diffs[$pos + $postdel + 1]->text;
                    } else {
                        $diffs[$pos] = $diff;
                        $diff->op = self::DIFF_INSERT;
                        ++$pos;
                    }
                    array_splice($diffs, $pos, $predel + $preins + $postdel + $postins - 1);
                    $ndiffs = count($diffs);
                    $pos = max($pos - $preins - $predel - 1, 0) - 1;
                }
            }
            ++$pos;
        }

        $this->diff_cleanupSemanticLossless($diffs);

        // Find any overlaps between deletions and insertions.
        // e.g: <del>abcxxx</del><ins>xxxdef</ins>
        //   -> <del>abc</del>xxx<ins>def</ins>
        // e.g: <del>xxxabc</del><ins>defxxx</ins>
        //   -> <ins>def</ins>xxx<del>abc</del>
        // Only extract an overlap if it is as big as the edit ahead or behind it.
        $pos = 1;
        $ndiffs = count($diffs);
        while ($pos < $ndiffs) {
            if ($diffs[$pos-1]->op === self::DIFF_DELETE
                && $diffs[$pos]->op === self::DIFF_INSERT) {
                $del = $diffs[$pos-1]->text;
                $ins = $diffs[$pos]->text;
                $overlaplen1 = $this->diff_commonOverlap_($del, $ins);
                $overlaplen2 = $this->diff_commonOverlap_($ins, $del);
                if ($overlaplen1 + $overlaplen2 === 0) {
                    // do nothing
                } else if ($overlaplen1 >= $overlaplen2) {
                    if ($overlaplen1 >= ((strlen($del) + 1) >> 1)
                        || $overlaplen1 >= ((strlen($ins) + 1) >> 1)) {
                        // Overlap found.  Insert an equality and trim the surrounding edits.
                        array_splice($diffs, $pos, 0, [new DiffMatch_Diff(self::DIFF_EQUAL, substr($ins, 0, $overlaplen1))]);
                        $diffs[$pos-1]->text = substr($del, 0, strlen($del) - $overlaplen1);
                        $diffs[$pos+1]->text = substr($ins, $overlaplen1);
                        ++$pos;
                        ++$ndiffs;
                    }
                } else {
                    if ($overlaplen2 >= ((strlen($del) + 1) >> 1)
                        || $overlaplen2 >= ((strlen($ins) + 1) >> 1)) {
                        // Reverse overlap found.
                        // Insert an equality and swap and trim the surrounding edits.
                        array_splice($diffs, $pos, 0, [new DiffMatch_Diff(self::DIFF_EQUAL,
                            substr($del, 0, $overlaplen2))]);
                        $diffs[$pos-1]->op = self::DIFF_INSERT;
                        $diffs[$pos-1]->text = substr($ins, 0, strlen($ins) - $overlaplen2);
                        $diffs[$pos+1]->op = self::DIFF_DELETE;
                        $diffs[$pos+1]->text = substr($del, $overlaplen2);
                        ++$pos;
                        ++$ndiffs;
                    }
                }
                ++$pos;
            }
            ++$pos;
        }
    }


    /**
     * Given two strings, compute a score representing whether the internal
     * boundary falls on logical boundaries.
     * Scores range from 6 (best) to 0 (worst).
     * Closure, but does not reference any external variables.
     * @param string $one First string.
     * @param string $two Second string.
     * @return int The score.
     */
    private function diff_cleanupSemanticScore_($one, $two) {
        if ($one === "" || $two === "") {
            // Edges are the best.
            return 6;
        }

        // Each port of this function behaves slightly differently due to
        // subtle differences in each language's definition of things like
        // 'whitespace'.  Since this function's purpose is largely cosmetic,
        // the choice has been made to use each language's native features
        $ch1 = $one[strlen($one) - 1];
        $ch2 = $two[0];
        if (($line2 = $ch2 === "\n" || $ch2 === "\r")
            || $ch1 === "\n"
            || $ch1 === "\r") {
            if (($ch1 === "\n" && preg_match('/\n\r?\n\z/', $one))
                || ($line2 && preg_match('/\A\r?\n\r?\n/', $two))) {
                // Five points for blank lines.
                return 5;
            } else {
                // Four points for line breaks.
                return 4;
            }
        }

        $nonalnum1 = !ctype_alnum($ch1);
        $ws1 = $nonalnum1 && ctype_space($ch1);
        $ws2 = ctype_space($ch2);
        if ($nonalnum1 && !$ws1 && $ws2) {
            // Three points for end of sentences.
            return 3;
        } else if ($ws1 || $ws2) {
            // Two points for whitespace.
            return 2;
        } else if ($nonalnum1 || !ctype_alnum($ch2)) {
            // One point for non-alphanumeric.
            return 1;
        } else {
            return 0;
        }
    }


    /**
     * Look for single edits surrounded on both sides by equalities
     * which can be shifted sideways to align the edit to a word boundary.
     * e.g: The c<ins>at c</ins>ame. -> The <ins>cat </ins>came.
     * @param list<DiffMatch_Diff> &$diffs Array of diff tuples.
     */
    private function diff_cleanupSemanticLossless(&$diffs) {
        $pos = 1;
        $ndiffs = count($diffs);
        while ($pos < $ndiffs - 1) {
            if ($diffs[$pos-1]->op === self::DIFF_EQUAL
                && $diffs[$pos+1]->op === self::DIFF_EQUAL) {
                // This is a single edit surrounded by equalities.
                $eq1 = $diffs[$pos-1]->text;
                $edit = $diffs[$pos]->text;
                $eq2 = $diffs[$pos+1]->text;

                // First, shift the edit as far left as possible.
                $commonoff = $this->diff_commonSuffix($eq1, $edit);
                if ($commonoff !== 0) {
                    $commonstr = substr($edit, strlen($edit) - $commonoff);
                    $eq1 = substr($eq1, 0, strlen($eq1) - $commonoff);
                    $edit = $commonstr . substr($edit, 0, strlen($edit) - $commonoff);
                    $eq2 = $commonstr . $eq2;
                }

                // Second, step character by character right, looking for the best fit.
                $besteq1 = $eq1;
                $bestedit = $edit;
                $besteq2 = $eq2;
                $bestscore = $this->diff_cleanupSemanticScore_($eq1, $edit)
                    + $this->diff_cleanupSemanticScore_($edit, $eq2);
                while ($edit[0] === $eq2[0]) {
                    $eq1 .= $edit[0];
                    $edit = substr($edit, 1) . $eq2[0];
                    $eq2 = substr($eq2, 1);
                    $score = $this->diff_cleanupSemanticScore_($eq1, $edit)
                        + $this->diff_cleanupSemanticScore_($edit, $eq2);
                    // The >= encourages trailing rather than leading whitespace on edits.
                    if ($score >= $bestscore) {
                        $bestscore = $score;
                        $besteq1 = $eq1;
                        $bestedit = $edit;
                        $besteq2 = $eq2;
                    }
                }

                if ($diffs[$pos-1]->text !== $besteq1) {
                    // We have an improvement, save it back to the diff.
                    if ($besteq1 !== "") {
                        $diffs[$pos-1]->text = $besteq1;
                    } else {
                        array_splice($diffs, $pos - 1, 1);
                        --$pos;
                        --$ndiffs;
                    }
                    $diffs[$pos]->text = $bestedit;
                    if ($besteq2 !== "") {
                        $diffs[$pos+1]->text = $besteq2;
                    } else {
                        array_splice($diffs, $pos + 1, 1);
                        --$pos;
                        --$ndiffs;
                    }
                }
            }
            ++$pos;
        }
    }


    /**
     * Reduce the number of edits by eliminating operationally trivial equalities.
     * @param list<DiffMatch_Diff> &$diffs Array of diff tuples.
     */
    function diff_cleanupEfficiency(&$diffs) {
        $pos = 1;  // Index of current position.
        $ndiffs = count($diffs);
        while ($pos < $ndiffs - 1) {
            if ($diffs[$pos]->op === self::DIFF_EQUAL
                && strlen($diffs[$pos]->text) < $this->Diff_EditCost) {  // Equality found.
                $preins = $pos > 0 && $diffs[$pos - 1]->op === self::DIFF_INSERT ? 1 : 0;
                $predel = $pos > $preins && $diffs[$pos - $preins - 1]->op === self::DIFF_DELETE ? 1 : 0;
                $postdel = $pos + 1 < $ndiffs && $diffs[$pos + 1]->op === self::DIFF_DELETE ? 1 : 0;
                $postins = $pos + $postdel + 1 < $ndiffs && $diffs[$pos + $postdel + 1]->op === self::DIFF_INSERT ? 1 : 0;
                if ($predel + $preins + $postdel + $postins >= 3
                    && ($predel + $preins + $postdel + $postins === 4
                        || strlen($diffs[$pos]->text) < $this->Diff_EditCost / 2)) {
                    $eqtext = $diffs[$pos]->text;
                    if ($predel) {
                        $diffs[$pos - $preins - 1]->text .= $eqtext;
                        if ($postdel) {
                            $diffs[$pos - $preins - 1]->text .= $diffs[$pos + 1]->text;
                        }
                    } else {
                        $diffs[$pos] = $diffs[$pos - 1];
                        $diffs[$pos - 1] = $diffs[$pos + 1];
                        $diffs[$pos - 1]->text = $eqtext . $diffs[$pos - 1]->text;
                        ++$pos;
                        $predel = 1;
                        $postdel = 0;
                    }
                    if ($preins) {
                        $diffs[$pos - 1]->text = $diffs[$pos - 1]->text .= $eqtext;
                        if ($postins) {
                            $diffs[$pos - 1]->text .= $diffs[$pos + $postdel + 1]->text;
                        }
                    } else {
                        $diffs[$pos + 2]->text = $eqtext . $diffs[$pos + 2]->text;
                    }
                    array_splice($diffs, $pos, $predel + $preins + $postdel + $postins - 1);
                    $ndiffs = count($diffs);
                    $pos = max($pos - 4, 0);
                }
            }
            ++$pos;
        }
    }


    /** @param list<DiffMatch_Diff> &$diffs
     * @param int $opos
     * @param DiffMatch_Diff $diff
     * @return int */
    static private function diff_merge1(&$diffs, $opos, $diff) {
        assert($diff->op !== self::DIFF_EQUAL);
        if ($diff->text === "") {
            return $opos;
        } else if ($opos > 0
                   && $diffs[$opos-1]->op === $diff->op) {
            $diffs[$opos-1]->text .= $diff->text;
            return $opos;
        } else if ($opos > 1
                   && $diffs[$opos-1]->op === self::DIFF_INSERT
                   && $diffs[$opos-2]->op === $diff->op) {
            $diffs[$opos-2]->text .= $diff->text;
            return $opos;
        } else if ($opos > 0
                   && $diffs[$opos-1]->op === self::DIFF_INSERT
                   && $diff->op === self::DIFF_DELETE) {
            $diffs[$opos] = $diffs[$opos-1];
            $diffs[$opos-1] = $diff;
            return $opos + 1;
        } else {
            $diffs[$opos] = $diff;
            return $opos + 1;
        }
    }


    /**
     * Reorder and merge like edit sections.  Merge equalities.
     * Any edit section can move as long as it doesn't cross an equality.
     * @param list<DiffMatch_Diff> &$diffs Array of diff tuples.
     */
    function diff_cleanupMerge(&$diffs) {
        // Add a dummy entry at the end.
        $diffs[] = new DiffMatch_Diff(self::DIFF_EQUAL, "");
        $ndiff = count($diffs);
        $opos = 0;
        foreach ($diffs as $pos => $diff) {
            if ($diff->op !== self::DIFF_EQUAL) {
                $opos = $this->diff_merge1($diffs, $opos, $diff);
            } else {
                if ($opos > 1
                    && $diffs[$opos-1]->op === self::DIFF_INSERT
                    && $diffs[$opos-2]->op === self::DIFF_DELETE) {
                    // Factor out any common prefixes.
                    $ddiff = $diffs[$opos-2];
                    $idiff = $diffs[$opos-1];
                    $clen = $this->diff_commonPrefix($idiff->text, $ddiff->text);
                    if ($clen !== 0) {
                        $ctext = substr($idiff->text, 0, $clen);
                        $idiff->text = substr($idiff->text, $clen);
                        $ddiff->text = substr($ddiff->text, $clen);
                        assert($opos === 2 || $diffs[$opos-3]->op === self::DIFF_EQUAL);
                        if ($opos === 2) {
                            // Special case: inserting new DIFF_EQUAL at beginning.
                            // This is the only case we do splicing.
                            array_splice($diffs, 2, $pos - $opos);
                            array_splice($diffs, 0, 0, [new DiffMatch_Diff(self::DIFF_EQUAL, $ctext)]);
                            $this->diff_cleanupMerge($diffs);
                            return;
                        } else {
                            $diffs[$opos-3]->text .= $ctext;
                        }
                    }
                    // Factor out any common suffixes.
                    $clen = $this->diff_commonSuffix($idiff->text, $ddiff->text);
                    if ($clen !== 0) {
                        $diff->text = substr($idiff->text, -$clen) . $diff->text;
                        $idiff->text = substr($idiff->text, 0, -$clen);
                        $ddiff->text = substr($ddiff->text, 0, -$clen);
                    }
                    if ($ddiff->text === "" && $idiff->text === "") {
                        $opos -= 2;
                    } else if ($idiff->text === "") {
                        --$opos;
                    } else if ($ddiff->text === "") {
                        $diffs[$opos-2] = $idiff;
                        --$opos;
                    }
                }
                if ($diff->text === "") {
                    // skip
                } else if ($opos !== 0 && $diffs[$opos-1]->op === self::DIFF_EQUAL) {
                    $diffs[$opos-1]->text .= $diff->text;
                } else {
                    $diffs[$opos] = $diff;
                    ++$opos;
                }
            }
        }
        array_splice($diffs, $opos);

        // Second pass: look for single edits surrounded on both sides by equalities
        // which can be shifted sideways to eliminate an equality.
        // e.g: A<ins>BA</ins>C -> <ins>AB</ins>AC
        $ndiffs = count($diffs);
        // Intentionally ignore the first and last element (don't need checking).
        for ($opos = $pos = 2; $pos < $ndiffs; ++$pos) {
            $diff1 = $diffs[$opos-2];
            $diff3 = $diffs[$pos];
            if ($diff1->op === self::DIFF_EQUAL && $diff3->op === self::DIFF_EQUAL) {
                // This is a single edit surrounded by equalities.
                $diff2 = $diffs[$opos-1];
                if (str_ends_with($diff2->text, $diff1->text)) {
                    // Shift the edit over the previous equality.
                    $diff3->text = $diff1->text . $diff3->text;
                    $diff1->op = $diff2->op;
                    $diff1->text .= substr($diff2->text, 0, -strlen($diff1->text));
                    --$opos;
                } else if (str_starts_with($diff2->text, $diff3->text)) {
                    // Shift the edit over the next equality.
                    $diff1->text .= $diff3->text;
                    $diff2->text = substr($diff2->text, strlen($diff3->text)) . $diff3->text;
                    continue;  // do not include $diff3
                }
            }
            $diffs[$opos] = $diff3;
            ++$opos;
        }

        // If shifts were made, the diff needs reordering and another shift sweep.
        if ($opos < count($diffs)) {
            array_splice($diffs, $opos);
            $this->diff_cleanupMerge($diffs);
        }
    }


    /** @param list<DiffMatch_Diff> &$diffs */
    private function diff_cleanupUnicode(&$diffs) {
        $ndiffs = count($diffs);
        for ($pos = 0; $pos !== $ndiffs; ++$pos) {
            $diff = $diffs[$pos];
            $text = $diff->text;
            $len = strlen($text);
            if (($ch = ord($text[$len - 1])) >= 0x80) {
                $p = $len - 1;
                while ($p !== 0 && $ch >= 0x80 && $ch < 0xC0 && $p > $len - 5) {
                    --$p;
                    $ch = ord($text[$p]);
                }
                if ($ch >= 0xF0) {
                    $need = $p + 4 - $len;
                } else if ($ch >= 0xE0) {
                    $need = $p + 3 - $len;
                } else if ($ch >= 0x80) {
                    $need = $p + 2 - $len;
                } else {
                    continue;
                }
                if ($need <= 0 || $pos === $ndiffs - 1) {
                    continue;
                }
                if ($diff->op === self::DIFF_EQUAL) {
                    // distribute to next diffs
                    $suffix = substr($text, $p);
                    $diff->text = substr($text, 0, $p);
                    $diffs[$pos + 1]->text = $suffix . $diffs[$pos + 1]->text;
                    if ($pos + 2 !== $ndiffs && $diffs[$pos + 2]->op === self::DIFF_INSERT) {
                        $diffs[$pos + 2]->text = $suffix . $diffs[$pos + 2]->text;
                        if ($diff->text === "") {
                            array_splice($diffs, $pos, 1);
                            --$ndiffs;
                        }
                    } else if ($diff->text === "") {
                        if ($diffs[$pos + 1]->op === self::DIFF_INSERT) {
                            $diff->op = self::DIFF_DELETE;
                        } else {
                            $diffs[$pos] = $diffs[$pos + 1];
                            $diffs[$pos + 1] = $diff;
                            $diff->op = self::DIFF_INSERT;
                        }
                        $diff->text = $suffix;
                    } else {
                        $hasdel = $diffs[$pos + 1]->op === self::DIFF_INSERT ? 0 : 1;
                        $ndiff = new DiffMatch_Diff($hasdel ? self::DIFF_INSERT : self::DIFF_DELETE, $suffix);
                        array_splice($diffs, $pos + $hasdel + 1, 0, [$ndiff]);
                        ++$ndiffs;
                    }
                } else if ($diff->op === self::DIFF_DELETE) {
                    // claim from next diffs
                    // (INSERT invalid-UTF-8 cannot be cleaned up unless the previous
                    // diff deleted invalid UTF-8.)
                    $npos = $pos + ($diffs[$pos + 1]->op === self::DIFF_INSERT ? 2 : 1);
                    if ($npos === $ndiffs) {
                        continue;
                    }
                    $eqdiff = $diffs[$npos];
                    $suffix = substr($eqdiff->text, 0, $need);
                    $diff->text .= $suffix;
                    $eqdiff->text = substr($eqdiff->text, strlen($suffix));
                    if ($npos === $pos + 2) {
                        $diffs[$pos+1]->text .= $suffix;
                        if ($eqdiff->text === "") {
                            $postdel = $pos + 3 < $ndiffs && $diffs[$pos+3]->op === self::DIFF_DELETE ? 1 : 0;
                            $postins = $pos + 3 + $postdel < $ndiffs && $diffs[$pos+3+$postdel]->op === self::DIFF_INSERT ? 1 : 0;
                            if ($postdel) {
                                $diff->text .= $diffs[$pos+3]->text;
                            }
                            if ($postins) {
                                $diffs[$pos+1]->text .= $diffs[$pos+3+$postdel]->text;
                            }
                            array_splice($diffs, $pos + 2, 1 + $postins + $postdel);
                            $ndiffs -= 1 + $postins + $postdel;
                        }
                    } else if ($eqdiff->text === "") {
                        $eqdiff->op = self::DIFF_INSERT;
                        $eqdiff->text = substr($diff->text, $p);
                        $postdel = $pos + 1 < $ndiffs && $diffs[$pos+1]->op === self::DIFF_DELETE ? 1 : 0;
                        $postins = $pos + 1 + $postdel < $ndiffs && $diffs[$pos+1+$postdel]->op === self::DIFF_INSERT ? 1 : 0;
                        if ($postdel) {
                            $diff->text .= $diffs[$pos+1]->text;
                        }
                        if ($postins) {
                            $eqdiff->text .= $diffs[$pos+1+$postdel]->text;
                        }
                        array_splice($diffs, $pos + 1, $postins + $postdel);
                        $ndiffs -= $postins + $postdel;
                    } else {
                        array_splice($diffs, $pos + 1, 0, [new DiffMatch_Diff(self::DIFF_INSERT, substr($diff->text, $p))]);
                        ++$ndiffs;
                    }
                    --$pos;
                }
            }
        }
    }


    /**
     * loc is a location in text1, compute and return the equivalent location in
     * text2.
     * e.g. 'The cat' vs 'The big cat', 1->1, 5->8
     * @param list<DiffMatch_Diff> $diffs Array of diff tuples.
     * @param int $loc Location within text1.
     * @return int Location within text2.
     */
    function diff_xIndex($diffs, $loc) {
        $chars1 = 0;
        $chars2 = 0;
        $last_chars1 = 0;
        $last_chars2 = 0;
        foreach ($diffs as $diff) {
            if ($diff->op !== self::DIFF_INSERT) {  // Equality or deletion.
                $chars1 += strlen($diff->text);
            }
            if ($diff->op !== self::DIFF_DELETE) {  // Equality or insertion.
                $chars2 += strlen($diff->text);
            }
            if ($chars1 > $loc) {  // Overshot the location.
                if ($diff->op !== self::DIFF_DELETE) {
                    $last_chars2 += $loc - $last_chars1;
                }
                return $last_chars2;
            }
            $last_chars1 = $chars1;
            $last_chars2 = $chars2;
        }
        return $last_chars2 + ($loc - $last_chars1);
    }


# /**
#  * Convert a diff array into a pretty HTML report.
#  * @param {!Array.<!diff_match_patch.Diff>} diffs Array of diff tuples.
#  * @return {string} HTML representation.
#  */
# diff_match_patch.prototype.diff_prettyHtml = function(diffs) {
#   var html = [];
#   var pattern_amp = /&/g;
#   var pattern_lt = /</g;
#   var pattern_gt = />/g;
#   var pattern_para = /\n/g;
#   for (var x = 0; x < diffs.length; x++) {
#     var op = diffs[x][0];    // Operation (insert, delete, equal)
#     var data = diffs[x][1];  // Text of change.
#     var text = data.replace(pattern_amp, '&amp;').replace(pattern_lt, '&lt;')
#         .replace(pattern_gt, '&gt;').replace(pattern_para, '&para;<br>');
#     switch (op) {
#       case DIFF_INSERT:
#         html[x] = '<ins style="background:#e6ffe6;">' + text + '</ins>';
#         break;
#       case DIFF_DELETE:
#         html[x] = '<del style="background:#ffe6e6;">' + text + '</del>';
#         break;
#       case DIFF_EQUAL:
#         html[x] = '<span>' + text + '</span>';
#         break;
#     }
#   }
#   return html.join('');
# };


    /**
     * Compute and return the source text (all equalities and deletions).
     * @param list<DiffMatch_Diff> $diffs Array of diff tuples.
     * @return string Source text.
     */
    function diff_text1($diffs) {
        $text = [];
        foreach ($diffs as $diff) {
            if ($diff->op !== self::DIFF_INSERT)
                $text[] = $diff->text;
        }
        return join("", $text);
    }


    /**
     * Compute and return the destination text (all equalities and insertions).
     * @param list<DiffMatch_Diff> $diffs Array of diff tuples.
     * @return string Destination text.
     */
    function diff_text2($diffs) {
        $text = [];
        foreach ($diffs as $diff) {
            if ($diff->op !== self::DIFF_DELETE)
                $text[] = $diff->text;
        }
        return join("", $text);
    }


    /**
     * Compute the Levenshtein distance; the number of inserted, deleted or
     * substituted characters.
     * @param list<DiffMatch_Diff> $diffs Array of diff tuples.
     * @return int Number of changes.
     */
    function diff_levenshtein($diffs) {
        $levenshtein = 0;
        $insertions = 0;
        $deletions = 0;
        foreach ($diffs as $diff) {
            if ($diff->op === self::DIFF_INSERT) {
                $insertions += strlen($diff->text);
            } else if ($diff->op === self::DIFF_DELETE) {
                $deletions += strlen($diff->text);
            } else {
                // A deletion and an insertion is one substitution.
                $levenshtein += max($insertions, $deletions);
                $insertions = 0;
                $deletions = 0;
            }
        }
        $levenshtein += max($insertions, $deletions);
        return $levenshtein;
    }


# /**
#  * Crush the diff into an encoded string which describes the operations
#  * required to transform text1 into text2.
#  * E.g. =3\t-2\t+ing  -> Keep 3 chars, delete 2 chars, insert 'ing'.
#  * Operations are tab-separated.  Inserted text is escaped using %xx notation.
#  * @param {!Array.<!diff_match_patch.Diff>} diffs Array of diff tuples.
#  * @return {string} Delta text.
#  */
# diff_match_patch.prototype.diff_toDelta = function(diffs) {
#   var text = [];
#   for (var x = 0; x < diffs.length; x++) {
#     switch (diffs[x][0]) {
#       case DIFF_INSERT:
#         text[x] = '+' + encodeURI(diffs[x][1]);
#         break;
#       case DIFF_DELETE:
#         text[x] = '-' + diffs[x][1].length;
#         break;
#       case DIFF_EQUAL:
#         text[x] = '=' + diffs[x][1].length;
#         break;
#     }
#   }
#   return text.join('\t').replace(/%20/g, ' ');
# };
#
#
# /**
#  * Given the original text1, and an encoded string which describes the
#  * operations required to transform text1 into text2, compute the full diff.
#  * @param {string} text1 Source string for the diff.
#  * @param {string} delta Delta text.
#  * @return {!Array.<!diff_match_patch.Diff>} Array of diff tuples.
#  * @throws {!Error} If invalid input.
#  */
# diff_match_patch.prototype.diff_fromDelta = function(text1, delta) {
#   var diffs = [];
#   var diffsLength = 0;  // Keeping our own length var is faster in JS.
#   var pointer = 0;  // Cursor in text1
#   var tokens = delta.split(/\t/g);
#   for (var x = 0; x < tokens.length; x++) {
#     // Each token begins with a one character parameter which specifies the
#     // operation of this token (delete, insert, equality).
#     var param = tokens[x].substring(1);
#     switch (tokens[x].charAt(0)) {
#       case '+':
#         try {
#           diffs[diffsLength++] =
#               new diff_match_patch.Diff(DIFF_INSERT, decodeURI(param));
#         } catch (ex) {
#           // Malformed URI sequence.
#           throw new Error('Illegal escape in diff_fromDelta: ' + param);
#         }
#         break;
#       case '-':
#         // Fall through.
#       case '=':
#         var n = parseInt(param, 10);
#         if (isNaN(n) || n < 0) {
#           throw new Error('Invalid number in diff_fromDelta: ' + param);
#         }
#         var text = text1.substring(pointer, pointer += n);
#         if (tokens[x].charAt(0) == '=') {
#           diffs[diffsLength++] = new diff_match_patch.Diff(DIFF_EQUAL, text);
#         } else {
#           diffs[diffsLength++] = new diff_match_patch.Diff(DIFF_DELETE, text);
#         }
#         break;
#       default:
#         // Blank tokens are ok (from a trailing \t).
#         // Anything else is an error.
#         if (tokens[x]) {
#           throw new Error('Invalid diff operation in diff_fromDelta: ' +
#                           tokens[x]);
#         }
#     }
#   }
#   if (pointer != text1.length) {
#     throw new Error('Delta length (' + pointer +
#         ') does not equal source text length (' + text1.length + ').');
#   }
#   return diffs;
# };
}

class DiffMatch_Diff implements JsonSerializable {
    /** @var int */
    public $op;
    /** @var string */
    public $text;

    /** @param -1|0|1 $op
     * @param string $text */
    function __construct($op, $text) {
        $this->op = $op;
        $this->text = $text;
    }

    /** @param list<string> $slist
     * @return list<DiffMatch_Diff> */
    static function parse_string_list($slist) {
        $a = [];
        foreach ($slist as $s) {
            if ($s === "") {
                // skip
            } else if ($s[0] === "+") {
                $a[] = new DiffMatch_Diff(DiffMatch::DIFF_INSERT, substr($s, 1));
            } else if ($s[0] === "=") {
                $a[] = new DiffMatch_Diff(DiffMatch::DIFF_EQUAL, substr($s, 1));
            } else if ($s[0] === "-") {
                $a[] = new DiffMatch_Diff(DiffMatch::DIFF_DELETE, substr($s, 1));
            } else {
                throw new RuntimeException("bad DiffMatch_Diff::parse_string_list `{$s}`");
            }
        }
        return $a;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        $t = $this->text;
        if (!preg_match('//u', $t)) {
            $t = bin2hex($t);
            $x = "X";
        } else {
            $x = "";
        }
        if ($this->op === DiffMatch::DIFF_INSERT) {
            return "{$x}+{$t}";
        } else if ($this->op === DiffMatch::DIFF_EQUAL) {
            return "{$x}={$t}";
        } else {
            return "{$x}-{$t}";
        }
    }
}


function test_diff_cleanupSemantic() {
    $dmp = new DiffMatch;

    // Cleanup semantically trivial equalities.
    // Null case.
    $diffs = [];
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '[]');

    // No elimination #1.
    $diffs = DiffMatch_Diff::parse_string_list(["-ab","+cd","=12","-e"]);
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '["-ab","+cd","=12","-e"]');

    // No elimination #2.
    $diffs = DiffMatch_Diff::parse_string_list(["-abc","+ABC","=1234","-wxyz"]);
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '["-abc","+ABC","=1234","-wxyz"]');

    // Simple elimination.
    $diffs = DiffMatch_Diff::parse_string_list(["-a","=b","-c"]);
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '["-abc","+b"]');

    // Backpass elimination.
    $diffs = DiffMatch_Diff::parse_string_list(["-ab","=cd","-e","=f","+g"]);
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '["-abcdef","+cdfg"]');

    // Multiple eliminations.
    $diffs = DiffMatch_Diff::parse_string_list(["+1","=A","-B","+2","=_","+1","=A","-B","+2"]);
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '["-AB_AB","+1A2_1A2"]');

    // Word boundaries.
    $diffs = DiffMatch_Diff::parse_string_list(["=The c","-ow and the c","=at."]);
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '["=The ","-cow and the ","=cat."]');

    // No overlap elimination.
    $diffs = DiffMatch_Diff::parse_string_list(["-abcxx","+xxdef"]);
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '["-abcxx","+xxdef"]');

    // Overlap elimination.
    $diffs = DiffMatch_Diff::parse_string_list(["-abcxxx","+xxxdef"]);
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '["-abc","=xxx","+def"]');

    // Reverse overlap elimination.
    $diffs = DiffMatch_Diff::parse_string_list(["-xxxabc","+defxxx"]);
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '["+def","=xxx","-abc"]');

    // Two overlap eliminations.
    $diffs = DiffMatch_Diff::parse_string_list(["-abcd1212","+1212efghi","=----","-A3","+3BC"]);
    $dmp->diff_cleanupSemantic($diffs);
    assert(json_encode($diffs) === '["-abcd","=1212","+efghi","=----","-A","=3","+BC"]');
}


function test_diff_cleanupEfficiency() {
    $dmp = new DiffMatch;
    $dmp->Diff_EditCost = 4;

    // Null case.
    $diffs = [];
    $dmp->diff_cleanupEfficiency($diffs);
    assert(json_encode($diffs) === "[]");

    // No elimination.
    $diffs = DiffMatch_Diff::parse_string_list(["-ab", "+12", "=wxyz", "-cd", "+34"]);
    $dmp->diff_cleanupEfficiency($diffs);
    assert(json_encode($diffs) === '["-ab","+12","=wxyz","-cd","+34"]');

    // Four-edit elimination.
    $diffs = DiffMatch_Diff::parse_string_list(["-ab","+12","=xyz","-cd","+34"]);
    $dmp->diff_cleanupEfficiency($diffs);
    assert(json_encode($diffs) === '["-abxyzcd","+12xyz34"]');

    // Three-edit elimination.
    $diffs = DiffMatch_Diff::parse_string_list(["+12","=x","-cd","+34"]);
    $dmp->diff_cleanupEfficiency($diffs);
    assert(json_encode($diffs) === '["-xcd","+12x34"]');

    // Backpass elimination.
    $diffs = DiffMatch_Diff::parse_string_list(["-ab","+12","=xy","+34","=z","-cd","+56"]);
    $dmp->diff_cleanupEfficiency($diffs);
    assert(json_encode($diffs) === '["-abxyzcd","+12xy34z56"]');

    // High cost elimination.
    $dmp->Diff_EditCost = 5;
    $diffs = DiffMatch_Diff::parse_string_list(["-ab","+12","=wxyz","-cd","+34"]);
    $dmp->diff_cleanupEfficiency($diffs);
    assert(json_encode($diffs) === '["-abwxyzcd","+12wxyz34"]');
}


function test_diff_unicode() {
    $dmp = new DiffMatch;

    $diffs = $dmp->diff("Hello this is a tést of Unicode", "Hello this is a tèst of Unicode");
    assert(json_encode($diffs, JSON_UNESCAPED_UNICODE) === '["=Hello this is a t","-é","+è","=st of Unicode"]');

    $diffs = $dmp->diff("πanother test", "Ȁanother test");
    assert(json_encode($diffs, JSON_UNESCAPED_UNICODE) === '["-π","+Ȁ","=another test"]');

    $dmp->Fix_UTF8 = false;
    $diffs = $dmp->diff("\xe0\xa0\x96", "\xe1\xa0\x97");
    assert(json_encode($diffs) === '["X-e0","X+e1","X=a0","X-96","X+97"]');
    $dmp->Fix_UTF8 = true;
    $diffs = $dmp->diff("\xe0\xa0\x96", "\xe1\xa0\x97");
    assert(json_encode($diffs, JSON_UNESCAPED_UNICODE) === "[\"-\xe0\xa0\x96\",\"+\xe1\xa0\x97\"]");
}


function test() {
    $words = preg_split('/\s+/', file_get_contents("/usr/share/dict/words"));
    $nwords = count($words);
    mt_srand(1);
    $differ = new DiffMatch;
    $time = 0.0;

    for ($nt = 0; $nt !== 100000; ++$nt) {
        $nw = mt_rand(10, 3000);
        $w = [];
        for ($i = 0; $i !== $nw; ++$i) {
            $w[] = $words[mt_rand(0, $nwords - 1)];
            $w[] = mt_rand(0, 4) === 0 ? "\n" : " ";
        }
        $t0 = join("", $w);

        $nc = mt_rand(1, 24);
        $t1 = $t0;
        for ($i = 0; $i !== $nc; ++$i) {
            $type = mt_rand(0, 7);
            $len = mt_rand(1, 100);
            $pos = mt_rand(0, strlen($t1));
            if ($type < 3) {
                $t1 = substr($t1, 0, $pos) . substr($t1, $pos + $len);
            } else {
                $t1 = substr($t1, 0, $pos) . $words[mt_rand(0, $nwords - 1)] . " " . substr($t1, $pos);
            }
        }

        if ($nt % 1000 === 0) {
            fwrite(STDERR, "#{$nt}{{$time}} ");
        }
        $tm0 = microtime(true);
        $diff = $differ->diff($t0, $t1);
        $time += microtime(true) - $tm0;
        $o0 = $differ->diff_text1($diff);
        if ($o0 !== $t0) {
            fwrite(STDERR, "\n#{$nt}\n");
            fwrite(STDERR, "=== NO:\n$t0\n=== GOT:\n$o0\n");
            $c1 = $differ->diff_commonPrefix($t0, $o0);
            fwrite(STDERR, "=== NEAR {$c1}:\n" . substr($t0, $c1,100) . "\n=== GOT:\n" . substr($o0, $c1, 100) . "\n");
            assert(false);
        }
        $o1 = $differ->diff_text2($diff);
        if ($o1 !== $t1) {
            fwrite(STDERR, "\n#{$nt}\n");
            fwrite(STDERR, "=== NO OUT:\n$t1\n=== GOT:\n$o1\n");
            $c1 = $differ->diff_commonPrefix($t1, $o1);
            fwrite(STDERR, "=== NEAR {$c1} OUT:\n" . substr($t1, $c1,100) . "\n=== GOT:\n" . substr($o1, $c1, 100) . "\n");
            assert(false);
        }
    }
}

test_diff_cleanupSemantic();
test_diff_cleanupEfficiency();
test_diff_unicode();
test();
