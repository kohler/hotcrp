<?php
// diffmatch.php -- PHP diff-match-patch.
// Copyright 2018 The diff-match-patch Authors.
// Copyright (c) 2006-2022 Eddie Kohler.
// Ported with some changes from Neil Fraser's diff-match-patch:
// https://github.com/google/diff-match-patch/

class DiffMatch {
    /** @var float */
    public $Diff_Timeout = 1.0;
    /** @var int */
    public $Diff_EditCost = 4;
    /** @var bool */
    public $Fix_UTF8 = true;
    /** @var int */
    public $Patch_Margin = 4;
    /** @var int */
    public $Match_MaxBits = 32;

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
        return $this->diff_main($text1, $text2, $checklines ?? true, $deadline);
    }

    /** @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param ?bool $checklines
     * @param ?float $deadline
     * @return list<DiffMatch_Diff> */
    function diff_main($text1, $text2, $checklines = null, $deadline = null) {
        if ($text1 === null || $text2 === null) {
            throw new TypeError;
        }

        if ($text1 === $text2) {
            if ($text1 !== "") {
                return [new DiffMatch_Diff(self::DIFF_EQUAL, $text1)];
            } else {
                return [];
            }
        }

        // Clean up parameters
        $Fix_UTF8 = $this->Fix_UTF8;
        $this->Fix_UTF8 = false;
        $checklines = $checklines ?? true;
        if ($deadline === null) {
            if ($this->Diff_Timeout <= 0) {
                $deadline = INF;
            } else {
                $deadline = microtime(true) + $this->Diff_Timeout;
            }
        }

        // Trim off common prefix (speedup).
        $commonlength = $this->diff_commonPrefix($text1, $text2);
        if ($commonlength !== 0) {
            $commonprefix = substr($text1, 0, $commonlength);
            $text1 = substr($text1, $commonlength);
            $text2 = substr($text2, $commonlength);
        } else {
            $commonprefix = "";
        }

        // Trim off common suffix (speedup).
        $commonlength = $this->diff_commonSuffix($text1, $text2);
        if ($commonlength !== 0) {
            $commonsuffix = substr($text1, -$commonlength);
            $text1 = substr($text1, 0, -$commonlength);
            $text2 = substr($text2, 0, -$commonlength);
        } else {
            $commonsuffix = "";
        }

        // Compute the diff on the middle block.
        $diffs = $this->diff_compute_($text1, $text2, $checklines, $deadline);

        // Restore the prefix and suffix.
        if ($commonprefix !== "") {
            array_unshift($diffs, new DiffMatch_Diff(self::DIFF_EQUAL, $commonprefix));
        }
        if ($commonsuffix !== "") {
            $diffs[] = new DiffMatch_Diff(self::DIFF_EQUAL, $commonsuffix);
        }
        $this->diff_cleanupMerge($diffs);

        // Fix UTF-8 issues.
        if ($Fix_UTF8) {
            $this->diff_cleanupUTF8($diffs);
        }
        $this->Fix_UTF8 = $Fix_UTF8;

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
    function diff_commonSuffix($text1, $text2) {
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
        '@phan-var-force list<DiffMatch_Diff> &$diffs';
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
     * Given a string and a boundary index,
     * compute a score representing whether the internal
     * boundary falls on logical boundaries.
     * Scores range from 6 (best) to 0 (worst).
     * Change to indexes from Eric McSween (GH #103)
     * @param string $buf Buffer string.
     * @param int $idx Index.
     * @return int The score.
     */
    private function diff_cleanupSemanticScore_($buf, $idx) {
        $len = strlen($buf);
        if ($idx === 0 || $idx === $len) {
            // Edges are the best.
            return 6;
        }

        // Each port of this function behaves slightly differently due to
        // subtle differences in each language's definition of things like
        // 'whitespace'.  Since this function's purpose is largely cosmetic,
        // the choice has been made to use each language's native features
        $ch1 = $buf[$idx - 1];
        $ch2 = $buf[$idx];
        if ($ch2 === "\n" || $ch2 === "\r" || $ch1 === "\n" || $ch1 === "\r") {
            // Five points for blank lines.
            if ($ch1 === "\n" && $idx > 1) {
                $ch1x = $buf[$idx - 2];
                if ($ch1x === "\n"
                    || ($ch1x === "\r" && $idx > 2 && $buf[$idx - 3] === "\n"))
                    return 5;
            }
            if ($ch2 === "\r" && $idx < $len - 2) {
                ++$idx;
                $ch2 = $buf[$idx];
            }
            if ($ch2 === "\n" && $idx < $len - 1) {
                $ch2x = $buf[$idx + 1];
                if ($ch2x === "\n"
                    || ($ch2x === "\r" && $idx < $len - 2 && $buf[$idx + 2] === "\n"))
                    return 5;
            }
            // Four points for line breaks.
            return 4;
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
    function diff_cleanupSemanticLossless(&$diffs) {
        $pos = 1;
        $ndiffs = count($diffs);
        while ($pos < $ndiffs - 1) {
            if ($diffs[$pos-1]->op === self::DIFF_EQUAL
                && $diffs[$pos+1]->op === self::DIFF_EQUAL) {
                // This is a single edit surrounded by equalities.
                $eq1 = $diffs[$pos-1]->text;
                $edit = $diffs[$pos]->text;
                $eq2 = $diffs[$pos+1]->text;

                // Can the edit be shifted?
                $offLeft = $this->diff_commonSuffix($eq1, $edit);
                $offRight = $this->diff_commonPrefix($edit, $eq2);
                if ($offLeft !== 0 || $offRight !== 0) {
                    // Shift edit left as much as possible
                    $buf = "{$eq1}{$edit}{$eq2}";
                    $editStart = strlen($eq1) - $offLeft;
                    $editMax = strlen($eq1) + $offRight;
                    $editLen = strlen($edit);

                    // Step character by character right, looking for the best fit
                    $bestEditStart = $editStart;
                    $bestScore = $this->diff_cleanupSemanticScore_($buf, $editStart)
                        + $this->diff_cleanupSemanticScore_($buf, $editStart + $editLen);
                    while ($editStart < $editMax) {
                        ++$editStart;
                        $score = $this->diff_cleanupSemanticScore_($buf, $editStart)
                            + $this->diff_cleanupSemanticScore_($buf, $editStart + $editLen);
                        // The >= encourages trailing rather than leading whitespace.
                        if ($score >= $bestScore) {
                            $bestScore = $score;
                            $bestEditStart = $editStart;
                        }
                    }

                    if ($bestEditStart !== strlen($eq1)) {
                        // We have an improvement, save it back to the diff.
                        if ($bestEditStart !== 0) {
                            $diffs[$pos-1]->text = substr($buf, 0, $bestEditStart);
                        } else {
                            array_splice($diffs, $pos - 1, 1);
                            --$pos;
                            --$ndiffs;
                        }
                        $diffs[$pos]->text = substr($buf, $bestEditStart, $editLen);
                        if ($bestEditStart + $editLen !== strlen($buf)) {
                            $diffs[$pos+1]->text = substr($buf, $bestEditStart + $editLen);
                        } else {
                            array_splice($diffs, $pos + 1, 1);
                            --$pos;
                            --$ndiffs;
                        }
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
        '@phan-var-force list<DiffMatch_Diff> &$diffs';
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
    private function diff_cleanupUTF8(&$diffs) {
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


    /**
     * Convert a diff array into a pretty HTML report.
     * @param list<DiffMatch_Diff> $diffs Array of diff tuples.
     * @return string HTML representation.
     */
    function diff_prettyHtml($diffs) {
        $html = [];
        foreach ($diffs as $diff) {
            $text = htmlspecialchars($diff->text, ENT_NOQUOTES);
            $text = str_replace("\n", '&para;<br>', $text);
            if ($diff->op === self::DIFF_INSERT) {
                $html[] = "<ins style=\"background:#e6ffe6;\">{$text}</ins>";
            } else if ($diff->op === self::DIFF_DELETE) {
                $html[] = "<del style=\"background:#ffe6e6;\">{$text}</del>";
            } else {
                $html[] = "<span>{$text}</span>";
            }
        }
        return join("", $html);
    }


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


    /** @param string $utf8s
     * @return int */
    static function utf16strlen($utf8s) {
        $n = strlen($utf8s);
        if (($k = preg_replace("/[^\xC0-\xFF]/", "", $utf8s)) !== "") {
            $n -= strlen($k);
            if (($k = preg_replace("/[^\xE0-\xFF]/", "", $k)) !== "") {
                $n -= strlen($k);
            }
        }
        return $n;
    }

    /** @param string $s
     * @return string */
    static function diff_encodeURI($s) {
        return str_replace(["%20", "%21", "%23", "%24", "%26", "%27", "%28", "%29", "%2A", "%2B", "%2C", "%2F", "%3A", "%3B", "%3D", "%3F", "%40", "%7E"],
                    [" ", "!", "#", "\$", "&", "'", "(", ")", "*", "+", ",", "/", ":", ";", "=", "?", "@", "~"],
                    rawurlencode($s));
    }

    /**
     * Crush the diff into an encoded string which describes the operations
     * required to transform text1 into text2.
     * E.g. =3\t-2\t+ing  -> Keep 3 chars, delete 2 chars, insert 'ing'.
     * Operations are tab-separated.  Inserted text is escaped using %xx notation.
     * @param list<DiffMatch_Diff> $diffs Array of diff tuples.
     * @return string Delta text.
     */
    function diff_toDelta($diffs) {
        $text = [];
        foreach ($diffs as $diff) {
            if ($diff->op === self::DIFF_INSERT) {
                $text[] = "+" . self::diff_encodeURI($diff->text);
            } else {
                $n = self::utf16strlen($diff->text);
                $text[] = ($diff->op === self::DIFF_DELETE ? "-" : "=") . $n;
            }
        }
        return str_replace("%20", " ", join("\t", $text));
    }


    /**
     * Given the original text1, and an encoded string which describes the
     * operations required to transform text1 into text2, compute the full diff.
     * @param string $text1 Source string for the diff.
     * @param string $delta Delta text.
     * @return list<DiffMatch_Diff> Array of diff tuples.
     * @throws RuntimeException If invalid input.
     */
    function diff_fromDelta($text1, $delta) {
        $diffs = [];
        $pos = 0;  // cursor in text1
        $len = strlen($text1);
        foreach (explode("\t", $delta) as $t) {
            if ($t === "") {
                continue;
            }
            // Each token begins with a one character parameter which specifies the
            // operation of this token (delete, insert, equality).
            $param = substr($t, 1);
            if ($t[0] === "+") {
                $diffs[] = new DiffMatch_Diff(self::DIFF_INSERT, rawurldecode($param));
            } else if ($t[0] === "-" || $t[0] === "=") {
                if (!ctype_digit($param)
                    || ($n = intval($param)) <= 0) {
                    throw new RuntimeException("Invalid number in diff_fromDelta");
                }
                $part = "";
                while (true) {
                    if ($pos + $n > strlen($text1)) {
                        throw new RuntimeException("Invalid number in diff_fromDelta");
                    }
                    $chunk = substr($text1, $pos, $n);
                    $part .= $chunk;
                    $pos += $n;
                    if (($k = preg_replace("/[^\xC0-\xFF]/", "", $chunk)) === "") {
                        break;
                    }
                    $n = strlen($k);
                    if (($k = preg_replace("/[^\xE0-\xFF]/", "", $k)) !== "") {
                        $n += strlen($k);
                    }
                }
                if ($t[0] === "-") {
                    $diffs[] = new DiffMatch_Diff(self::DIFF_DELETE, $part);
                } else {
                    $diffs[] = new DiffMatch_Diff(self::DIFF_EQUAL, $part);
                }
            } else {
                throw new RuntimeException("Invalid operation in diff_fromDelta");
            }
        }
        if ($pos !== $len) {
            throw new RuntimeException("Delta length doesn't cover source text");
        }
        return $diffs;
    }


    /** @param list<string> $strs
     * @return list<DiffMatch_Diff> */
    function diff_fromStringList($strs) {
        return DiffMatch_Diff::parse_string_list($strs);
    }

    /** @param list<DiffMatch_Diff> $diffs
     * @return list<string> */
    function diff_toStringList($diffs) {
        return DiffMatch_Diff::unparse_string_list($diffs);
    }


    /**
     * Increase the context until it is unique,
     * but don't let the pattern expand beyond Match_MaxBits.
     * @param DiffMatch_Patch $patch The patch to grow.
     * @param string $text Source text.
     */
    private function patch_addContext_($patch, $text) {
        if ($text === "") {
            return;
        }
        if ($patch->start2 === null) {
            throw new RuntimeException('patch not initialized');
        }

        // Look for the first and last matches of pattern in text.  If two different
        // matches are found, increase the pattern length.
        $pattern = substr($text, $patch->start2, $patch->length1);
        $padding = 0;
        while (strpos($text, $pattern) !== strrpos($text, $pattern)
               && strlen($pattern) < $this->Match_MaxBits - $this->Patch_Margin - $this->Patch_Margin) {
            $padding += $this->Patch_Margin;
            $pattern = substr($text, $patch->start2 - $padding, $patch->start2 + $patch->length1 + $padding);
        }
        // Add one chunk for good luck.
        $padding += $this->Patch_Margin;

        // Add the prefix.
        $prefix = substr($text, $patch->start2 - $padding, $padding);
        if ($prefix !== "") {
            array_unshift($patch->diffs, new DiffMatch_Diff(self::DIFF_EQUAL, $prefix));
        }
        // Add the suffix.
        $suffix = substr($text, $patch->start2 + $patch->length1, $padding);
        if ($suffix !== "") {
            $patch->diffs[] = new DiffMatch_Diff(self::DIFF_EQUAL, $suffix);
        }

        // Roll back the start points.
        $patch->start1 -= strlen($prefix);
        $patch->start2 -= strlen($prefix);
        // Extend the lengths.
        $patch->length1 += strlen($prefix) + strlen($suffix);
        $patch->length2 += strlen($prefix) + strlen($suffix);
    }


    /**
     * Compute a list of patches to turn text1 into text2.
     * Use diffs if provided, otherwise compute it ourselves.
     * There are four ways to call this function, depending on what data is
     * available to the caller:
     * Method 1:
     * a = text1, b = text2
     * Method 2:
     * a = diffs
     * Method 3 (optimal):
     * a = text1, b = diffs
     * Method 4 (deprecated, use method 3):
     * a = text1, b = text2, c = diffs
     *
     * @param string|list<DiffMatch_Diff> $a text1 (methods 1,3,4) or
     * Array of diff tuples for text1 to text2 (method 2).
     * @param null|string|list<DiffMatch_Diff> $opt_b text2 (methods 1,4) or
     * Array of diff tuples for text1 to text2 (method 3) or undefined (method 2).
     * @param null|string|list<DiffMatch_Diff> $opt_c Array of diff tuples
     * for text1 to text2 (method 4) or undefined (methods 1,2,3).
     * @return list<DiffMatch_Patch> Array of Patch objects.
     */
    function patch_make($a, $opt_b = null, $opt_c = null) {
        if (is_string($a) && is_string($opt_b) && $opt_c === null) {
            // Method 1: text1, text2
            // Compute diffs from text1 and text2.
            $text1 = $a;
            $diffs = $this->diff_main($text1, $opt_b, true);
            if (count($diffs) > 2) {
                $this->diff_cleanupSemantic($diffs);
                $this->diff_cleanupEfficiency($diffs);
            }
        } else if (is_array($a) && $opt_b === null && $opt_c === null) {
            // Method 2: diffs
            // Compute text1 from diffs.
            $diffs = $a;
            $text1 = $this->diff_text1($diffs);
        } else if (is_string($a) && is_array($opt_b) && $opt_c === null) {
            // Method 3: text1, diffs
            $text1 = $a;
            $diffs = $opt_b;
        } else if (is_string($a) && is_string($opt_b) && is_array($opt_c)) {
            // Method 4: text1, text2, diffs
            // text2 is not used.
            $text1 = $a;
            $diffs = $opt_c;
        } else {
            throw new RuntimeException('Unknown call format to patch_make.');
        }

        if (empty($diffs)) {
            return [];  // Get rid of the null case.
        }
        $patches = [];
        $patch = new DiffMatch_Patch;
        $char_count1 = 0;  // Number of characters into the text1 string.
        $char_count2 = 0;  // Number of characters into the text2 string.
        // Start with text1 (prepatch_text) and apply the diffs until we arrive at
        // text2 (postpatch_text).  We recreate the patches one by one to determine
        // context info.
        $prepatch_text = $text1;
        $postpatch_text = $text1;
        foreach ($diffs as $x => $diff) {
            if (empty($patch->diffs) && $diff->op !== self::DIFF_EQUAL) {
                // A new patch starts here.
                $patch->start1 = $char_count1;
                $patch->start2 = $char_count2;
            }

            if ($diff->op === self::DIFF_INSERT) {
                $patch->diffs[] = $diff;
                $patch->length2 += strlen($diff->text);
                $postpatch_text = substr($postpatch_text, 0, $char_count2)
                    . $diff->text . substr($postpatch_text, $char_count2);
            } else if ($diff->op === self::DIFF_DELETE) {
                $patch->diffs[] = $diff;
                $patch->length1 += strlen($diff->text);
                $postpatch_text = substr($postpatch_text, 0, $char_count2)
                    . substr($postpatch_text, $char_count2 + strlen($diff->text));
            } else if (!empty($patch->diffs)) {
                if (strlen($diff->text) <= 2 * $this->Patch_Margin
                    && $x + 1 !== count($diffs)) {
                    // Small equality inside a patch.
                    $patch->diffs[] = $diff;
                    $patch->length1 += strlen($diff->text);
                    $patch->length2 += strlen($diff->text);
                } else if (strlen($diff->text) >= 2 * $this->Patch_Margin) {
                    // Time for a new patch.
                    $this->patch_addContext_($patch, $prepatch_text);
                    $patches[] = $patch;
                    $patch = new DiffMatch_Patch;
                    // Unlike Unidiff, our patch lists have a rolling context.
                    // https://github.com/google/diff-match-patch/wiki/Unidiff
                    // Update prepatch text & pos to reflect the application of the
                    // just completed patch.
                    $prepatch_text = $postpatch_text;
                    $char_count1 = $char_count2;
                }
            }

            // Update the current character count.
            if ($diff->op !== self::DIFF_INSERT) {
                $char_count1 += strlen($diff->text);
            }
            if ($diff->op !== self::DIFF_DELETE) {
                $char_count2 += strlen($diff->text);
            }
        }
        // Pick up the leftover patch if not empty.
        if (!empty($patch->diffs)) {
            $this->patch_addContext_($patch, $prepatch_text);
            $patches[] = $patch;
        }

        return $patches;
    }


    /**
     * Given an array of patches, return another array that is identical.
     * @param list<DiffMatch_Patch> $patches Array of Patch objects.
     * @return list<DiffMatch_Patch> Array of Patch objects.
     */
    function patch_deepCopy($patches) {
        $copy = [];
        foreach ($patches as $p) {
            $copy[] = $p2 = clone $p;
            foreach ($p->diffs as &$diff) {
                $diff = clone $diff;
            }
            unset($diff);
        }
        return $copy;
    }
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
                continue;
            }
            $ch = $s[0];
            if ($ch === "X") {
                if (strlen($s) < 2
                    || ($x = hex2bin(substr($s, 2))) === false) {
                    throw new RuntimeException("bad DiffMatch_Diff::parse_string_list hex");
                }
                $ch = $s[1];
            } else {
                $x = substr($s, 1);
            }
            if ($ch === "+") {
                $a[] = new DiffMatch_Diff(DiffMatch::DIFF_INSERT, $x);
            } else if ($ch === "=") {
                $a[] = new DiffMatch_Diff(DiffMatch::DIFF_EQUAL, $x);
            } else if ($ch === "-") {
                $a[] = new DiffMatch_Diff(DiffMatch::DIFF_DELETE, $x);
            } else {
                throw new RuntimeException("bad DiffMatch_Diff::parse_string_list `{$ch}`");
            }
        }
        return $a;
    }

    /** @param list<DiffMatch_Diff> $diffs
     * @return list<string> */
    static function unparse_string_list($diffs) {
        $a = [];
        foreach ($diffs as $diff) {
            $a[] = $diff->__toString();
        }
        return $a;
    }

    /** @return string */
    function __toString() {
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

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->__toString();
    }
}


class DiffMatch_Patch {
    /** @var list<DiffMatch_Diff> */
    public $diffs = [];
    /** @var ?int */
    public $start1 = null;
    /** @var ?int */
    public $start2 = null;
    /** @var int */
    public $length1 = 0;
    /** @var int */
    public $length2 = 0;

    /**
     * Emulate GNU diff's format.
     * Header: @@ -382,8 +481,9 @@
     * Indices are printed as 1-based, not 0-based.
     * @return string The GNU diff string.
     */
    function __toString() {
        if ($this->length1 === 0) {
            $coords1 = "{$this->start1},0";
        } else if ($this->length1 == 1) {
            $coords1 = (string) ($this->start1 + 1);
        } else {
            $coords1 = ($this->start1 + 1) . ',' . $this->length1;
        }
        if ($this->length2 === 0) {
            $coords2 = "{$this->start2},0";
        } else if ($this->length2 == 1) {
            $coords2 = (string) ($this->start2 + 1);
        } else {
            $coords2 = ($this->start2 + 1) . ',' . $this->length2;
        }
        $text = ["@@ -{$coords1} +{$coords2} @@\n"];
        // Escape the body of the patch with %xx notation.
        foreach ($this->diffs as $diff) {
            if ($diff->op === DiffMatch::DIFF_INSERT) {
                $op = "+";
            } else if ($diff->op === DiffMatch::DIFF_DELETE) {
                $op = "-";
            } else {
                $op = " ";
            }
            $text[] = $op . DiffMatch::diff_encodeURI($diff->text) . "\n";
        }
        return str_replace("%20", " ", join("", $text));
    }
}


function assertEquals($a, $b) {
    if ($a !== $b) {
        $tr = explode("\n", (new Exception)->getTraceAsString());
        $s = preg_replace('/\A\#?\d*\s*/', "", $tr[0]);
        fwrite(STDERR, "ASSERTION FAILURE: $s\n");
        fwrite(STDERR, "  expected " . (is_string($a) ? $a : (json_encode($a) ?? var_export($a, true))) . "\n");
        fwrite(STDERR, "       got " . (is_string($b) ? $b : (json_encode($b) ?? var_export($b, true))) . "\n");
        fwrite(STDERR, join("\n", $tr) . "\n");
        exit(1);
    }
}

/** @param list<DiffMatch_Diff>|list<string>|string $ax
 * @param list<DiffMatch_Diff> $b */
function assertEqualDiffs($ax, $b) {
    $al = is_string($ax) ? json_decode($ax) : $ax;
    if (!empty($al) && is_string($al[0])) {
        $a = DiffMatch_Diff::parse_string_list($al);
    } else {
        $a = $al;
    }
    for ($i = 0; $i < count($a) || $i < count($b); ++$i) {
        $da = $a[$i] ?? null;
        $db = $b[$i] ?? null;
        if ($da === null || $db === null || $da->op !== $db->op || $da->text !== $db->text) {
            $tr = explode("\n", (new Exception)->getTraceAsString());
            $s = preg_replace('/\A\#?\d*\s*/', "", $tr[0]);
            fwrite(STDERR, "ASSERTION FAILURE: $s\n");
            fwrite(STDERR, "  expected diff[{$i}] " . json_encode($da) . "\n");
            fwrite(STDERR, "       got diff[{$i}] " . json_encode($db) . "\n");
            fwrite(STDERR, join("\n", $tr) . "\n");
            exit(1);
        }
    }
}

function testDiffCommonPrefix() {
    $dmp = new DiffMatch;

    // Detect any common prefix.
    // Null case.
    assertEquals(0, $dmp->diff_commonPrefix('abc', 'xyz'));

    // Non-null case.
    assertEquals(4, $dmp->diff_commonPrefix('1234abcdef', '1234xyz'));

    // Whole case.
    assertEquals(4, $dmp->diff_commonPrefix('1234', '1234xyz'));
}

function testDiffCommonSuffix() {
    $dmp = new DiffMatch;

    // Detect any common suffix.
    // Null case.
    assertEquals(0, $dmp->diff_commonSuffix('abc', 'xyz'));

    // Non-null case.
    assertEquals(4, $dmp->diff_commonSuffix('abcdef1234', 'xyz1234'));

    // Whole case.
    assertEquals(4, $dmp->diff_commonSuffix('1234', 'xyz1234'));
}

function testDiffCommonOverlap() {
    $dmp = new DiffMatch;
    $m = new ReflectionMethod("DiffMatch", "diff_commonOverlap_");
    $m->setAccessible(true);

    // Detect any suffix/prefix overlap.
    // Null case.
    assertEquals(0, $m->invoke($dmp, '', 'abcd'));

    // Whole case.
    assertEquals(3, $m->invoke($dmp, 'abc', 'abcd'));

    // No overlap.
    assertEquals(0, $m->invoke($dmp, '123456', 'abcd'));

    // Overlap.
    assertEquals(3, $m->invoke($dmp, '123456xxx', 'xxxabcd'));

    // Unicode.
    // Some overly clever languages (C#) may treat ligatures as equal to their
    // component letters.  E.g. U+FB01 == 'fi'
    assertEquals(0, $m->invoke($dmp, 'fi', '\ufb01i'));
}

function testDiffHalfMatch() {
    // Detect a halfmatch.
    $dmp = new DiffMatch;
    $dmp->Diff_Timeout = 1;
    $m = new ReflectionMethod("DiffMatch", "diff_halfMatch_");
    $m->setAccessible(true);

    // No match.
    assertEquals(null, $m->invoke($dmp, '1234567890', 'abcdef'));

    assertEquals(null, $m->invoke($dmp, '12345', '23'));

    // Single Match.
    assertEquals(['12', '90', 'a', 'z', '345678'], $m->invoke($dmp, '1234567890', 'a345678z'));

    assertEquals(['a', 'z', '12', '90', '345678'], $m->invoke($dmp, 'a345678z', '1234567890'));

    assertEquals(['abc', 'z', '1234', '0', '56789'], $m->invoke($dmp, 'abc56789z', '1234567890'));

    assertEquals(['a', 'xyz', '1', '7890', '23456'], $m->invoke($dmp, 'a23456xyz', '1234567890'));

    // Multiple Matches.
    assertEquals(['12123', '123121', 'a', 'z', '1234123451234'], $m->invoke($dmp, '121231234123451234123121', 'a1234123451234z'));

    assertEquals(['', '-=-=-=-=-=', 'x', '', 'x-=-=-=-=-=-=-='], $m->invoke($dmp, 'x-=-=-=-=-=-=-=-=-=-=-=-=', 'xx-=-=-=-=-=-=-='));

    assertEquals(['-=-=-=-=-=', '', '', 'y', '-=-=-=-=-=-=-=y'], $m->invoke($dmp, '-=-=-=-=-=-=-=-=-=-=-=-=y', '-=-=-=-=-=-=-=yy'));

    // Non-optimal halfmatch.
    // Optimal diff would be -q+x=H-i+e=lloHe+Hu=llo-Hew+y not -qHillo+x=HelloHe-w+Hulloy
    assertEquals(['qHillo', 'w', 'x', 'Hulloy', 'HelloHe'], $m->invoke($dmp, 'qHilloHelloHew', 'xHelloHeHulloy'));

    // Optimal no halfmatch.
    $dmp->Diff_Timeout = 0;
    assertEquals(null, $m->invoke($dmp, 'qHilloHelloHew', 'xHelloHeHulloy'));
}

function testDiffLinesToChars() {
    $dmp = new DiffMatch;
    $m = new ReflectionMethod("DiffMatch", "diff_linesToChars_");
    $m->setAccessible(true);

    // Convert lines down to characters.
    assertEquals(["\x01\x00\x02\x00\x01\x00", "\x02\x00\x01\x00\x02\x00", ["", "alpha\n", "beta\n"]],
        $m->invoke($dmp, "alpha\nbeta\nalpha\n", "beta\nalpha\nbeta\n"));

    assertEquals(["", "\x01\x00\x02\x00\x03\x00\x03\x00", ["", "alpha\r\n", "beta\r\n", "\r\n"]],
        $m->invoke($dmp, "", "alpha\r\nbeta\r\n\r\n\r\n"));

    assertEquals(["\x01\x00", "\x02\x00", ["", "a", "b"]],
        $m->invoke($dmp, "a", "b"));

    // More than 256 to reveal any 8-bit limitations.
    $n = 300;
    $lineList = $charList = [];
    for ($i = 1; $i <= 300; ++$i) {
        $lineList[] = "{$i}\n";
        $charList[] = chr($i % 256) . chr($i >> 8);
    }
    assertEquals($n, count($lineList));
    $lines = join("", $lineList);
    $chars = join("", $charList);
    assertEquals($n * 2, strlen($chars));
    array_unshift($lineList, "");
    assertEquals([$chars, "", $lineList], $m->invoke($dmp, $lines, ""));
}

function testDiffCharsToLines() {
    $dmp = new DiffMatch;
    $m = new ReflectionMethod("DiffMatch", "diff_charsToLines_");
    $m->setAccessible(true);
    $ml2c = new ReflectionMethod("DiffMatch", "diff_linesToChars_");
    $ml2c->setAccessible(true);

    // Convert chars up to lines.
    $diffs = $dmp->diff_fromStringList(["=\x01\x00\x02\x00\x01\x00", "+\x02\x00\x01\x00\x02\x00"]);
    $m->invoke($dmp, $diffs, ["", "alpha\n", "beta\n"]);
    assertEqualDiffs(["=alpha\nbeta\nalpha\n","+beta\nalpha\nbeta\n"], $diffs);

    // More than 256 to reveal any 8-bit limitations.
    $n = 300;
    $lineList = [];
    $charList = [];
    for ($i = 1; $i <= $n; ++$i) {
        $lineList[] = "{$i}\n";
        $charList[] = chr($i % 256) . chr($i >> 8);
    }
    assertEquals($n, count($lineList));
    $lines = join("", $lineList);
    $chars = join("", $charList);
    assertEquals($n * 2, strlen($chars));
    array_unshift($lineList, "");
    $diffs = [new DiffMatch_Diff(DiffMatch::DIFF_DELETE, $chars)];
    $m->invoke($dmp, $diffs, $lineList);
    assertEqualDiffs(["-{$lines}"], $diffs);

    // More than 65536 to verify any 16-bit limitation.
    $lineList = [];
    for ($i = 0; $i < 66000; ++$i) {
        $lineList[] = "{$i}\n";;
    }
    $chars = join("", $lineList);
    $results = $ml2c->invoke($dmp, $chars, "");
    $diffs = [new DiffMatch_Diff(DiffMatch::DIFF_INSERT, $results[0])];
    $m->invoke($dmp, $diffs, $results[2]);
    assertEquals($chars, $diffs[0]->text);
}

function testDiffCleanupMerge() {
    $dmp = new DiffMatch;

    // Cleanup a messy diff.
    // Null case.
    $diffs = [];
    $dmp->diff_cleanupMerge($diffs);
    assertEquals([], $diffs);

    // No change case.
    $diffs = $dmp->diff_fromStringList(["=a", "-b", "+c"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["=a","-b","+c"], $diffs);

    // Merge equalities.
    $diffs = $dmp->diff_fromStringList(["=a", "=b", "=c"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["=abc"], $diffs);

    // Merge deletions.
    $diffs = $dmp->diff_fromStringList(["-a", "-b", "-c"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["-abc"], $diffs);

    // Merge insertions.
    $diffs = $dmp->diff_fromStringList(["+a", "+b", "+c"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["+abc"], $diffs);

    // Merge interweave.
    $diffs = $dmp->diff_fromStringList(["-a", "+b", "-c", "+d", "=e", "=f"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["-ac","+bd","=ef"], $diffs);

    // Prefix and suffix detection.
    $diffs = $dmp->diff_fromStringList(["-a","+abc","-dc"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["=a","-d","+b","=c"], $diffs);

    // Prefix and suffix detection with equalities.
    $diffs = $dmp->diff_fromStringList(["=x","-a","+abc","-dc","=y"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["=xa","-d","+b","=cy"], $diffs);

    // Slide edit left.
    $diffs = $dmp->diff_fromStringList(["=a","+ba","=c"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["+ab","=ac"], $diffs);

    // Slide edit right.
    $diffs = $dmp->diff_fromStringList(["=c","+ab","=a"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["=ca","+ba"], $diffs);

    // Slide edit left recursive.
    $diffs = $dmp->diff_fromStringList(["=a","-b","=c","-ac","=x"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["-abc","=acx"], $diffs);

    // Slide edit right recursive.
    $diffs = $dmp->diff_fromStringList(["=x","-ca","=c","-b","=a"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["=xca","-cba"], $diffs);

    // Empty merge.
    $diffs = $dmp->diff_fromStringList(["-b","+ab","=c"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["+a","=bc"], $diffs);

    // Empty equality.
    $diffs = $dmp->diff_fromStringList(["=","+a","=b"]);
    $dmp->diff_cleanupMerge($diffs);
    assertEqualDiffs(["+a","=b"], $diffs);
}


function testDiffCleanupSemanticLossless() {
    $dmp = new DiffMatch;

    // Slide diffs to match logical boundaries.
    // Null case.
    $diffs = [];
    $dmp->diff_cleanupSemanticLossless($diffs);
    assertEquals([], $diffs);

    // Blank lines.
    $diffs = $dmp->diff_fromStringList(["=AAA\r\n\r\nBBB","+\r\nDDD\r\n\r\nBBB","=\r\nEEE"]);
    $dmp->diff_cleanupSemanticLossless($diffs);
    assertEqualDiffs(["=AAA\r\n\r\n","+BBB\r\nDDD\r\n\r\n","=BBB\r\nEEE"], $diffs);

    // Line boundaries.
    $diffs = $dmp->diff_fromStringList(["=AAA\r\n\r\nBBB","+ DDD\r\nBBB","= EEE"]);
    $dmp->diff_cleanupSemanticLossless($diffs);
    assertEqualDiffs(["=AAA\r\n\r\n","+BBB DDD\r\n","=BBB EEE"], $diffs);

    // Word boundaries.
    $diffs = $dmp->diff_fromStringList(["=The c","+ow and the c","=at."]);
    $dmp->diff_cleanupSemanticLossless($diffs);
    assertEqualDiffs(["=The ","+cow and the ","=cat."], $diffs);

    // Alphanumeric boundaries.
    $diffs = $dmp->diff_fromStringList(["=The-c","+ow-and-the-c","=at."]);
    $dmp->diff_cleanupSemanticLossless($diffs);
    assertEqualDiffs(["=The-","+cow-and-the-","=cat."], $diffs);

    // Hitting the start.
    $diffs = $dmp->diff_fromStringList(["=a","-a","=ax"]);
    $dmp->diff_cleanupSemanticLossless($diffs);
    assertEqualDiffs(["-a","=aax"], $diffs);

    // Hitting the end.
    $diffs = $dmp->diff_fromStringList(["=xa","-a","=a"]);
    $dmp->diff_cleanupSemanticLossless($diffs);
    assertEqualDiffs(["=xaa","-a"], $diffs);

    // Sentence boundaries.
    $diffs = $dmp->diff_fromStringList(["=The xxx. The ","+zzz. The ","=yyy."]);
    $dmp->diff_cleanupSemanticLossless($diffs);
    assertEqualDiffs(["=The xxx.","+ The zzz.","= The yyy."], $diffs);
}


function testDiffCleanupSemantic() {
    $dmp = new DiffMatch;

    // Cleanup semantically trivial equalities.
    // Null case.
    $diffs = [];
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs([], $diffs);

    // No elimination #1.
    $diffs = $dmp->diff_fromStringList(["-ab","+cd","=12","-e"]);
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs(["-ab","+cd","=12","-e"], $diffs);

    // No elimination #2.
    $diffs = $dmp->diff_fromStringList(["-abc","+ABC","=1234","-wxyz"]);
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs(["-abc","+ABC","=1234","-wxyz"], $diffs);

    // Simple elimination.
    $diffs = $dmp->diff_fromStringList(["-a","=b","-c"]);
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs(["-abc","+b"], $diffs);

    // Backpass elimination.
    $diffs = $dmp->diff_fromStringList(["-ab","=cd","-e","=f","+g"]);
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs(["-abcdef","+cdfg"], $diffs);

    // Multiple eliminations.
    $diffs = $dmp->diff_fromStringList(["+1","=A","-B","+2","=_","+1","=A","-B","+2"]);
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs(["-AB_AB","+1A2_1A2"], $diffs);

    // Word boundaries.
    $diffs = $dmp->diff_fromStringList(["=The c","-ow and the c","=at."]);
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs(["=The ","-cow and the ","=cat."], $diffs);

    // No overlap elimination.
    $diffs = $dmp->diff_fromStringList(["-abcxx","+xxdef"]);
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs(["-abcxx","+xxdef"], $diffs);

    // Overlap elimination.
    $diffs = $dmp->diff_fromStringList(["-abcxxx","+xxxdef"]);
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs(["-abc","=xxx","+def"], $diffs);

    // Reverse overlap elimination.
    $diffs = $dmp->diff_fromStringList(["-xxxabc","+defxxx"]);
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs(["+def","=xxx","-abc"], $diffs);

    // Two overlap eliminations.
    $diffs = $dmp->diff_fromStringList(["-abcd1212","+1212efghi","=----","-A3","+3BC"]);
    $dmp->diff_cleanupSemantic($diffs);
    assertEqualDiffs(["-abcd","=1212","+efghi","=----","-A","=3","+BC"], $diffs);
}


function testDiffCleanupEfficiency() {
    $dmp = new DiffMatch;
    $dmp->Diff_EditCost = 4;

    // Null case.
    $diffs = [];
    $dmp->diff_cleanupEfficiency($diffs);
    assertEqualDiffs("[]", $diffs);

    // No elimination.
    $diffs = $dmp->diff_fromStringList(["-ab", "+12", "=wxyz", "-cd", "+34"]);
    $dmp->diff_cleanupEfficiency($diffs);
    assertEqualDiffs(["-ab","+12","=wxyz","-cd","+34"], $diffs);

    // Four-edit elimination.
    $diffs = $dmp->diff_fromStringList(["-ab","+12","=xyz","-cd","+34"]);
    $dmp->diff_cleanupEfficiency($diffs);
    assertEqualDiffs(["-abxyzcd","+12xyz34"], $diffs);

    // Three-edit elimination.
    $diffs = $dmp->diff_fromStringList(["+12","=x","-cd","+34"]);
    $dmp->diff_cleanupEfficiency($diffs);
    assertEqualDiffs(["-xcd","+12x34"], $diffs);

    // Backpass elimination.
    $diffs = $dmp->diff_fromStringList(["-ab","+12","=xy","+34","=z","-cd","+56"]);
    $dmp->diff_cleanupEfficiency($diffs);
    assertEqualDiffs(["-abxyzcd","+12xy34z56"], $diffs);

    // High cost elimination.
    $dmp->Diff_EditCost = 5;
    $diffs = $dmp->diff_fromStringList(["-ab","+12","=wxyz","-cd","+34"]);
    $dmp->diff_cleanupEfficiency($diffs);
    assertEqualDiffs(["-abwxyzcd","+12wxyz34"], $diffs);
}

function testDiffPrettyHtml() {
    // Pretty print.
    $dmp = new DiffMatch;
    $diffs = $dmp->diff_fromStringList(["=a\n","-<B>b</B>","+c&d"]);
    assertEquals('<span>a&para;<br></span><del style="background:#ffe6e6;">&lt;B&gt;b&lt;/B&gt;</del><ins style="background:#e6ffe6;">c&amp;d</ins>', $dmp->diff_prettyHtml($diffs));
}

function testDiffText() {
    // Compute the source and destination texts.
    $dmp = new DiffMatch;
    $diffs = $dmp->diff_fromStringList(["=jump","-s","+ed","= over ","-the","+a","= lazy"]);
    assertEquals('jumps over the lazy', $dmp->diff_text1($diffs));
    assertEquals('jumped over a lazy', $dmp->diff_text2($diffs));
}

function testDiffDelta() {
    $dmp = new DiffMatch;

    // Convert a diff into delta string.
    $diffs = $dmp->diff_fromStringList(["=jump","-s","+ed","= over ","-the","+a","= lazy","+old dog"]);
    $text1 = $dmp->diff_text1($diffs);
    assertEquals('jumps over the lazy', $text1);

    $delta = $dmp->diff_toDelta($diffs);
    assertEquals("=4\t-1\t+ed\t=6\t-3\t+a\t=5\t+old dog", $delta);

    // Convert delta string into a diff.
    assertEqualDiffs($diffs, $dmp->diff_fromDelta($text1, $delta));

    // Generates error (19 != 20).
    try {
        $dmp->diff_fromDelta($text1 . 'x', $delta);
        assertEquals(false, true);
    } catch (Exception $e) {
        // Exception expected.
    }

    // Generates error (19 != 18).
    try {
        $dmp->diff_fromDelta(substr($text1, 1), $delta);
        assertEquals(false, true);
    } catch (Exception $e) {
        // Exception expected.
    }

    // Generates error (%c3%xy invalid Unicode).
    // XXX This is not validated in the PHP version.
    try {
        $dmp->diff_fromDelta('', '+%c3%xy');
        assertEquals(true, true);  // XXX
    } catch (Exception $e) {
        // Exception should be expected.
        assertEquals(false, true);   // XXX
    }

    // Test deltas with special characters.
    $diffs = $dmp->diff_fromStringList(["=\xda\x80 \x00 \t %","-\xda\x81 \x01 \n ^","+\xda\x82 \x02 \\ |"]);
    $text1 = $dmp->diff_text1($diffs);
    assertEquals("\xda\x80 \x00 \t %\xda\x81 \x01 \n ^", $text1);

    $delta = $dmp->diff_toDelta($diffs);
    assertEquals("=7\t-7\t+%DA%82 %02 %5C %7C", $delta);

    // Convert delta string into a diff.
    assertEqualDiffs($diffs, $dmp->diff_fromDelta($text1, $delta));

    // Test deltas for surrogate pairs.
    $diffs = $dmp->diff_fromStringList(["=😀Héló"]);
    $text1 = $dmp->diff_text1($diffs);
    assertEquals("😀Héló", $text1);

    $delta = $dmp->diff_toDelta($diffs);
    assertEquals("=6", $delta);

    assertEqualDiffs($diffs, $dmp->diff_fromDelta($text1, $delta));

    // Verify pool of unchanged characters.
    $diffs = $dmp->diff_fromStringList(['+A-Z a-z 0-9 - _ . ! ~ * \' ( ) ; / ? : @ & = + $ , # ']);
    $text2 = $dmp->diff_text2($diffs);
    assertEquals('A-Z a-z 0-9 - _ . ! ~ * \' ( ) ; / ? : @ & = + $ , # ', $text2);

    $delta = $dmp->diff_toDelta($diffs);
    assertEquals('+A-Z a-z 0-9 - _ . ! ~ * \' ( ) ; / ? : @ & = + $ , # ', $delta);

    // Convert delta string into a diff.
    assertEqualDiffs($diffs, $dmp->diff_fromDelta('', $delta));

    // 160 kb string.
    $a = 'abcdefghij';
    for ($i = 0; $i < 14; ++$i) {
        $a .= $a;
    }
    $diffs = [new DiffMatch_Diff(DiffMatch::DIFF_INSERT, $a)];
    $delta = $dmp->diff_toDelta($diffs);
    assertEquals('+' . $a, $delta);

    // Convert delta string into a diff.
    assertEqualDiffs($diffs, $dmp->diff_fromDelta('', $delta));
}

function testDiffXIndex() {
    $dmp = new DiffMatch;

    // Translate a location in text1 to text2.
    // Translation on equality.
    assertEquals(5, $dmp->diff_xIndex($dmp->diff_fromStringList(["-a","+1234","=xyz"]), 2));

    // Translation on deletion.
    assertEquals(1, $dmp->diff_xIndex($dmp->diff_fromStringList(["=a","-1234","=xyz"]), 3));
}

function testDiffLevenshtein() {
    $dmp = new DiffMatch;

    // Levenshtein with trailing equality.
    assertEquals(4, $dmp->diff_levenshtein($dmp->diff_fromStringList(["-abc","+1234","=xyz"])));
    // Levenshtein with leading equality.
    assertEquals(4, $dmp->diff_levenshtein($dmp->diff_fromStringList(["=xyz","-abc","+1234"])));
    // Levenshtein with middle equality.
    assertEquals(7, $dmp->diff_levenshtein($dmp->diff_fromStringList(["-abc","=xyz","+1234"])));
}

function testDiffBisect() {
    $dmp = new DiffMatch;
    $m = new ReflectionMethod("DiffMatch", "diff_bisect_");
    $m->setAccessible(true);

    // Normal.
    $a = 'cat';
    $b = 'map';
    // Since the resulting diff hasn't been normalized, it would be ok if
    // the insertion and deletion pairs are swapped.
    // If the order changes, tweak this test as required.
    assertEqualDiffs(["-c", "+m", "=a", "-t", "+p"], $m->invoke($dmp, $a, $b, INF));

    // Timeout.
    assertEqualDiffs(["-cat", "+map"], $m->invoke($dmp, $a, $b, 0));
}

function testDiffMain() {
    $dmp = new DiffMatch;

    // Perform a trivial diff.
    // Null case.
    assertEqualDiffs([], $dmp->diff_main('', '', false));

    // Equality.
    assertEqualDiffs(["=abc"], $dmp->diff_main('abc', 'abc', false));

    // Simple insertion.
    assertEqualDiffs(["=ab", "+123", "=c"], $dmp->diff_main('abc', 'ab123c', false));

    // Simple deletion.
    assertEqualDiffs(["=a", "-123", "=bc"], $dmp->diff_main('a123bc', 'abc', false));

    // Two insertions.
    assertEqualDiffs(["=a", "+123", "=b", "+456", "=c"], $dmp->diff_main('abc', 'a123b456c', false));

    // Two deletions.
    assertEqualDiffs(["=a", "-123", "=b", "-456", "=c"], $dmp->diff_main('a123b456c', 'abc', false));

    // Perform a real diff.
    // Switch off the timeout.
    $dmp->Diff_Timeout = 0;
    // Simple cases.
    assertEqualDiffs(["-a", "+b"], $dmp->diff_main('a', 'b', false));

    assertEqualDiffs(["-Apple", "+Banana", "=s are a", "+lso", "= fruit."], $dmp->diff_main('Apples are a fruit.', 'Bananas are also fruit.', false));

    assertEqualDiffs(["-a", "+\xDA\x80", "=x", "-\t", "+\0"], $dmp->diff_main("ax\t", "\xDA\x80x\0", false));

    // Overlaps.
    assertEqualDiffs(["-1", "=a", "-y", "=b", "-2", "+xab"], $dmp->diff_main('1ayb2', 'abxab', false));

    assertEqualDiffs(["+xaxcx", "=abc", "-y"], $dmp->diff_main('abcy', 'xaxcxabc', false));

    assertEqualDiffs(["-ABCD", "=a", "-=", "+-", "=bcd", "-=", "+-", "=efghijklmnopqrs", "-EFGHIJKLMNOefg"], $dmp->diff_main('ABCDa=bcd=efghijklmnopqrsEFGHIJKLMNOefg', 'a-bcd-efghijklmnopqrs', false));

    // Large equality.
    assertEqualDiffs(["+ ", "=a", "+nd", "= [[Pennsylvania]]", "- and [[New"], $dmp->diff_main('a [[Pennsylvania]] and [[New', ' and [[Pennsylvania]]', false));

    // Timeout.
    $dmp->Diff_Timeout = 0.1;  // 100ms
    $a = "`Twas brillig, and the slithy toves\nDid gyre and gimble in the wabe:\nAll mimsy were the borogoves,\nAnd the mome raths outgrabe.\n";
    $b = "I am the very model of a modern major general,\nI\'ve information vegetable, animal, and mineral,\nI know the kings of England, and I quote the fights historical,\nFrom Marathon to Waterloo, in order categorical.\n";
    // Increase the text lengths by 1024 times to ensure a timeout.
    for ($i = 0; $i < 10; ++$i) {
        $a .= $a;
        $b .= $b;
    }
    $startTime = microtime(true);
    $dmp->diff_main($a, $b);
    $endTime = microtime(true);
    // Test that we took at least the timeout period.
    assert($dmp->Diff_Timeout <= $endTime - $startTime);
    // Test that we didn't take forever (be forgiving).
    // Theoretically this test could fail very occasionally if the
    // OS task swaps or locks up for a second at the wrong moment.
    assert($dmp->Diff_Timeout * 2 > $endTime - $startTime);
    $dmp->Diff_Timeout = 0;

    // Test the linemode speedup.
    // Must be long to pass the 100 char cutoff.
    // Simple line-mode.
    $a = "1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n";
    $b = "abcdefghij\nabcdefghij\nabcdefghij\nabcdefghij\nabcdefghij\nabcdefghij\nabcdefghij\nabcdefghij\nabcdefghij\nabcdefghij\nabcdefghij\nabcdefghij\nabcdefghij\n";
    assertEqualDiffs($dmp->diff_main($a, $b, false), $dmp->diff_main($a, $b, true));

    // Single line-mode.
    $a = '1234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890';
    $b = 'abcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghij';
    assertEqualDiffs($dmp->diff_main($a, $b, false), $dmp->diff_main($a, $b, true));

    // Overlap line-mode.
    $a = "1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n";
    $b = "abcdefghij\n1234567890\n1234567890\n1234567890\nabcdefghij\n1234567890\n1234567890\n1234567890\nabcdefghij\n1234567890\n1234567890\n1234567890\nabcdefghij\n";
    assertEquals($dmp->diff_text1($dmp->diff_main($a, $b, false)),
                 $dmp->diff_text1($dmp->diff_main($a, $b, true)));
    assertEquals($dmp->diff_text2($dmp->diff_main($a, $b, false)),
                 $dmp->diff_text2($dmp->diff_main($a, $b, true)));

    // Test null inputs.
    try {
        $dmp->diff_main(null, null);
        assertEquals(true, false);
    } catch (TypeError $e) {
        // Exception expected.
    }
}

function testUTF16Strlen() {
    assertEquals(5, DiffMatch::utf16strlen("abcde"));
    assertEquals(5, DiffMatch::utf16strlen("abcdé"));
    assertEquals(5, DiffMatch::utf16strlen("abcdࠀ"));
    assertEquals(6, DiffMatch::utf16strlen("abcdࠀࠀ"));
    assertEquals(8, DiffMatch::utf16strlen("abcdࠀࠀ😀"));
}

function testDiffUTF8() {
    $dmp = new DiffMatch;

    $diffs = $dmp->diff("Hello this is a tést of Unicode", "Hello this is a tèst of Unicode");
    assertEquals('["=Hello this is a t","-é","+è","=st of Unicode"]', json_encode($diffs, JSON_UNESCAPED_UNICODE));

    $diffs = $dmp->diff("πanother test", "Ȁanother test");
    assertEquals('["-π","+Ȁ","=another test"]', json_encode($diffs, JSON_UNESCAPED_UNICODE));

    $dmp->Fix_UTF8 = false;
    $diffs = $dmp->diff("\xe0\xa0\x96", "\xe1\xa0\x97");
    assertEquals('["X-e0","X+e1","X=a0","X-96","X+97"]', json_encode($diffs));

    $dmp->Fix_UTF8 = true;
    $diffs = $dmp->diff("\xe0\xa0\x96", "\xe1\xa0\x97");
    assertEquals("[\"-\xe0\xa0\x96\",\"+\xe1\xa0\x97\"]", json_encode($diffs, JSON_UNESCAPED_UNICODE));
}


function testRandom() {
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

function testSpeedtest() {
    $text1 = file_get_contents("conf/speedtest1.txt");
    $text2 = file_get_contents("conf/speedtest2.txt");

    $dmp = new DiffMatch;
    $dmp->Diff_Timeout = 0;

    // Execute one reverse diff as a warmup.
    $dmp->diff($text2, $text1);
    gc_collect_cycles();

    $us_start = microtime(true);
    $diff = $dmp->diff($text1, $text2);
    $us_end = microtime(true);

    file_put_contents("/tmp/x.html", $dmp->diff_prettyHtml($diff));
    fwrite(STDOUT, sprintf("Elapsed time: %.3f\n", $us_end - $us_start));
}

function testSpeedtestSemantic($sz) {
    $dmp = new DiffMatch;
    $dmp->Diff_Timeout = 0;
    $s1 = str_repeat("a", 50) . str_repeat("b", $sz) . str_repeat("c", 50);
    $s2 = str_repeat("a", 50) . str_repeat("b", 2 * $sz) . str_repeat("c", 50);

    $t0 = microtime(true);
    $diffs = $dmp->diff($s1, $s2);
    $t1 = microtime(true);
    $dmp->diff_cleanupSemantic($diffs);
    $t2 = microtime(true);

    fwrite(STDOUT, sprintf("Elapsed time: diff %.3f, cleanup %.3f\n", $t1-$t0, $t2-$t1));
}

testUTF16Strlen();
testDiffCommonPrefix();
testDiffCommonSuffix();
testDiffCommonOverlap();
testDiffHalfMatch();
testDiffLinesToChars();
testDiffCharsToLines();
testDiffCleanupMerge();
testDiffCleanupSemanticLossless();
testDiffCleanupSemantic();
testDiffCleanupEfficiency();
testDiffPrettyHtml();
testDiffText();
testDiffDelta();
testDiffXIndex();
testDiffLevenshtein();
testDiffBisect();
testDiffMain();
testSpeedtest();
testSpeedtestSemantic(1000000);
testDiffUTF8();
testRandom();
