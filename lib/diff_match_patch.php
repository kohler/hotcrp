<?php
// diff_match_patch.php -- PHP diff-match-patch.
// Copyright 2018 The diff-match-patch Authors.
// Copyright (c) 2006-2022 Eddie Kohler.
// Ported with some changes from Neil Fraser's diff-match-patch:
// https://github.com/google/diff-match-patch/

namespace dmp;

const DIFF_DELETE = -1;
const DIFF_INSERT = 1;
const DIFF_EQUAL = 0;

class diff_match_patch {
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
    public $Line_Histogram = false;

    /** @var 0|1
     * $iota === 1 if we are doing a line diff, so the unit is 2 bytes */
    private $iota = 0;

    /** @param string $text1
     * @param string $text2
     * @param bool $checklines
     * @param ?float $deadline
     * @return list<diff_obj> */
    function diff($text1, $text2, $checklines = true, $deadline = null) {
        return $this->diff_main($text1, $text2, $checklines, $deadline);
    }

    /** @param ?float $deadline
     * @return float */
    private function compute_deadline_($deadline) {
        if ($deadline !== null) {
            return $deadline;
        } else if ($this->Diff_Timeout <= 0) {
            return INF;
        } else {
            return microtime(true) + $this->Diff_Timeout;
        }
    }

    /** @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param bool $checklines
     * @param ?float $deadline
     * @return list<diff_obj> */
    function diff_main($text1, $text2, $checklines = true, $deadline = null) {
        $text1 = (string) $text1;
        $text2 = (string) $text2;

        if ($text1 === $text2) {
            if ($text1 !== "") {
                return [new diff_obj(DIFF_EQUAL, $text1)];
            } else {
                return [];
            }
        }

        // Clean up parameters
        $Fix_UTF8 = $this->Fix_UTF8;
        $this->Fix_UTF8 = false;
        $deadline = $deadline ?? $this->compute_deadline_(null);

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
        $diffs = $this->diff_compute_($text1, $text2, $checklines ?? true, $deadline);

        // Restore the prefix and suffix.
        if ($commonprefix !== "") {
            array_unshift($diffs, new diff_obj(DIFF_EQUAL, $commonprefix));
        }
        if ($commonsuffix !== "") {
            $diffs[] = new diff_obj(DIFF_EQUAL, $commonsuffix);
        }
        $this->diff_cleanupMerge($diffs);

        // Fix UTF-8 issues.
        if (($this->Fix_UTF8 = $Fix_UTF8)) {
            $this->diff_cleanupUTF8($diffs);
        }

        return $diffs;
    }

    /**
     * Find the differences between two texts.  Assumes that the texts do not
     * have any common prefix or suffix.
     * @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param bool $checklines Speedup flag.
     * @param float $deadline Time when the diff should be complete by.
     * @return list<diff_obj> */
    private function diff_compute_($text1, $text2, $checklines, $deadline) {
        if ($text1 === "") {
            return [new diff_obj(DIFF_INSERT, $text2)];
        } else if ($text2 === "") {
            return [new diff_obj(DIFF_DELETE, $text1)];
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
            $op = strlen($text1) > strlen($text2) ? DIFF_DELETE : DIFF_INSERT;
            return [
                new diff_obj($op, substr($longtext, 0, $i)),
                new diff_obj(DIFF_EQUAL, $shorttext),
                new diff_obj($op, substr($longtext, $i + strlen($shorttext)))
            ];
        }

        if (strlen($shorttext) === 1) {
            // Single character string.
            // After the previous speedup, the character can't be an equality.
            return [
                new diff_obj(DIFF_DELETE, $text1),
                new diff_obj(DIFF_INSERT, $text2)
            ];
        }

        // Check to see if the problem can be split in two.
        $hm = $this->diff_halfMatch_($text1, $text2);
        if ($hm !== null) {
            // Send both pairs off for separate processing.
            $diffs_a = $this->diff_main($hm[0], $hm[2], $checklines, $deadline);
            $diffs_a[] = new diff_obj(DIFF_EQUAL, $hm[4]);
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
     * @return list<diff_obj> */
    private function diff_lineMode_($text1, $text2, $deadline) {
        assert($this->iota === 0);
        // Scan the text on a line-by-line basis first.
        list($text1, $text2, $lineArray) = $this->diff_linesToChars_($text1, $text2);

        $this->iota = 1;
        if ($this->Line_Histogram) {
            $diffs = [];
            $this->diff_histogram_($text1, $text2, count($lineArray), $deadline, $diffs);
            $this->line_diff_cleanupSemantic_($diffs);
        } else {
            $diffs = $this->diff_main($text1, $text2, false, $deadline);
        }
        $this->iota = 0;

        // Convert the diff back to original text.
        $this->diff_charsToLines_($diffs, $lineArray);
        // Eliminate freak matches (e.g. blank lines)
        $this->diff_cleanupSemantic($diffs);

        // Rediff any replacement blocks, this time character-by-character.
        // Add a dummy entry at the end.
        $diffs[] = new diff_obj(DIFF_EQUAL, "");
        $opos = 0;
        $out = [];
        '@phan-var-force list<diff_obj> $out';
        foreach ($diffs as $diff) {
            // Upon reaching an equality, check for prior redundancies.
            if ($diff->op === DIFF_EQUAL
                && $opos > 1
                && $out[$opos-1]->op === DIFF_INSERT
                && $out[$opos-2]->op === DIFF_DELETE) {
                $opos -= 2;
                foreach ($this->diff_main($out[$opos]->text, $out[$opos+1]->text, false, $deadline) as $subDiff) {
                    $opos = $this->diff_merge1($out, $opos, $subDiff);
                }
            }
            $opos = $this->diff_merge1($out, $opos, $diff);
        }
        array_splice($out, $opos);
        return $out;
    }


    /**
     * Produce a line-based diff.
     * @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param ?float $deadline Time when diff should be complete by.
     * @return list<diff_obj> */
    function line_diff($text1, $text2, $deadline = null) {
        assert($this->iota === 0);

        // Clean up parameters
        $Fix_UTF8 = $this->Fix_UTF8;
        $this->Fix_UTF8 = false;
        $deadline = $deadline ?? $this->compute_deadline_(null);

        // Scan the text on a line-by-line basis first.
        list($text1, $text2, $lineArray) = $this->diff_linesToChars_($text1 ?? "", $text2 ?? "");

        $this->iota = 1;
        if ($this->Line_Histogram) {
            $diffs = [];
            $this->diff_histogram_($text1, $text2, count($lineArray), $deadline, $diffs);
        } else {
            $diffs = $this->diff_main($text1, $text2, false, $deadline);
        }
        // Shift diffs forward.
        $this->line_diff_cleanupSemantic_($diffs);
        $this->iota = 0;

        // Convert the diff back to original text.
        $this->diff_charsToLines_($diffs, $lineArray);

        $this->Fix_UTF8 = $Fix_UTF8;
        return $diffs;
    }


    /**
     * Find the 'middle snake' of a diff, split the problem in two
     * and return the recursively constructed diff.
     * See Myers 1986 paper: An O(ND) Difference Algorithm and Its Variations.
     * @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param float $deadline Time at which to bail if not yet complete.
     * @return list<diff_obj> Array of diff tuples.
     */
    private function diff_bisect_($text1, $text2, $deadline) {
        list($x, $y) = $this->diff_bisect_helper_($text1, $text2, $deadline);
        if ($x < 0) {
            // Ran out of time
            return [
                new diff_obj(DIFF_DELETE, $text1),
                new diff_obj(DIFF_INSERT, $text2)
            ];
        } else {
            // Given the location of the 'middle snake', split the diff in two parts
            // and recurse.
            $text1a = substr($text1, 0, $x);
            $text2a = substr($text2, 0, $y);
            $text1b = substr($text1, $x);
            $text2b = substr($text2, $y);
            $diffs = $this->diff_main($text1a, $text2a, false, $deadline);
            array_push($diffs, ...$this->diff_main($text1b, $text2b, false, $deadline));
            return $diffs;
        }
    }

    /**
     * Helper for diff_bisect_.
     * Returns split positions, rather than making a recursive call, to free up
     * array memory earlier.
     * @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param float $deadline Time at which to bail if not yet complete.
     * @return array{int,int} Split positions.
     */
    private function diff_bisect_helper_($text1, $text2, $deadline) {
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
                            return [$x1 << $this->iota, $y1 << $this->iota];
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
                            return [$x1 << $this->iota, $y1 << $this->iota];
                        }
                    }
                }
            }
        }

        // Diff took too long and hit the deadline or
        // number of diffs equals number of characters, no commonality at all.
        return [-1, -1];
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
        $cr = $nl = -1;
        while ($lineStart < $textLen) {
            if ($cr !== false && $cr < $lineStart) {
                $cr = strpos($text, "\r", $lineStart);
            }
            if ($nl !== false && $nl < $lineStart) {
                $nl = strpos($text, "\n", $lineStart);
            }
            if ($cr === false && $nl === false) {
                $lineEnd = $textLen;
            } else if ($nl !== false
                       && ($cr === false || $cr >= $nl - 1)) {
                $lineEnd = $nl + 1;
            } else {
                $lineEnd = $cr + 1;
            }
            $line = substr($text, $lineStart, $lineEnd - $lineStart);
            $ch = $lineHash[$line] ?? null;
            if ($ch === null) {
                if ($lineArrayLength === $maxLines) {
                    // Bail out once we reach maxLines
                    $line = substr($text, $lineStart);
                    $lineEnd = $textLen;
                }
                $lineHash[$line] = $ch = $lineArrayLength;
                $lineArray[] = $line;
                ++$lineArrayLength;
            }
            $chars .= chr($ch & 0xFF) . chr($ch >> 8);
            $lineStart = $lineEnd;
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
     * @param list<diff_obj> $diffs Array of diff tuples.
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


    /** @param list<diff_obj> $diffs */
    private function diff_checkEvenLengths($diffs) {
        if ($this->iota) {
            foreach ($diffs as $diff) {
                assert(strlen($diff->text) % 2 === 0);
            }
        }
    }



    /** @param string $text1
     * @param string $text2
     * @param int $nlines
     * @param float $deadline
     * @param list<diff_obj> &$diffs
     * @return void */
    private function diff_histogram_($text1, $text2, $nlines, $deadline, &$diffs) {
        if ($text1 === "") {
            $diffs[] = new diff_obj(DIFF_INSERT, $text2);
        } else if ($text2 === "") {
            $diffs[] = new diff_obj(DIFF_DELETE, $text1);
        } else {
            $hstate = new histogram_state($text1, $text2, $nlines);
            if ($hstate->mstate === 0
                || microtime(true) > $deadline) {
                $diffs[] = new diff_obj(DIFF_DELETE, $text1);
                $diffs[] = new diff_obj(DIFF_INSERT, $text2);
            } else if ($hstate->mstate === 1) {
                $this->diff_histogram_(
                    substr($text1, 0, $hstate->mpos1),
                    substr($text2, 0, $hstate->mpos2),
                    $nlines, $deadline, $diffs
                );
                $diffs[] = new diff_obj(DIFF_EQUAL, substr($text1, $hstate->mpos1, $hstate->mlen));
                $this->diff_histogram_(
                    substr($text1, $hstate->mpos1 + $hstate->mlen),
                    substr($text2, $hstate->mpos2 + $hstate->mlen),
                    $nlines, $deadline, $diffs
                );
            } else {
                array_push($diffs, ...$this->diff_main($text1, $text2, false, $deadline));
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


    /** @param list<diff_obj> $diffs
     * @param int $opos
     * @param int $pos
     * @param diff_obj $diff
     * @param int $ndiffs
     * @return bool */
    private function diff_allowMergeEqual_($diffs, $opos, $pos, $diff, $ndiffs) {
        assert($diff->op === DIFF_EQUAL);
        $len = strlen($diff->text);
        $pre = $opos > 0 && $diffs[$opos-1]->op === DIFF_INSERT ? 1 : 0;
        $pre += $opos > $pre && $diffs[$opos-$pre-1]->op === DIFF_DELETE ? 1 : 0;
        $post = $pos+1 < $ndiffs && $diffs[$pos+1]->op === DIFF_DELETE ? 1 : 0;
        $post += $pos+$post+1 < $ndiffs && $diffs[$pos+$post+1]->op === DIFF_INSERT ? 1 : 0;
        return $len > 0  /* merging empty is handled separately */
            && $pre !== 0
            && $post !== 0
            && $len <= max(strlen($diffs[$opos-1]->text),
                           $pre === 2 ? strlen($diffs[$opos-2]->text) : 0)
            && $len <= max(strlen($diffs[$pos+1]->text),
                           $post === 2 ? strlen($diffs[$pos+2]->text) : 0);
    }

    /** @param list<diff_obj> &$diffs
     * @param int $opos
     * @param int $pos
     * @param diff_obj $diff
     * @param int $ndiffs
     * @return int */
    private function diff_mergeEqual_(&$diffs, $opos, $pos, $diff, $ndiffs) {
        while (true) {
            $opos = $this->diff_merge1($diffs, $opos, new diff_obj(DIFF_DELETE, $diff->text));
            $opos = $this->diff_merge1($diffs, $opos, new diff_obj(DIFF_INSERT, $diff->text));
            // merge subsequent diffs
            for (++$pos; $pos !== $ndiffs && $diffs[$pos]->op !== DIFF_EQUAL; ++$pos) {
                $opos = $this->diff_merge1($diffs, $opos, $diffs[$pos]);
            }
            // potentially backtrack: find prior equality
            $pos = $opos - 1;
            while ($pos !== 0 && ($diff = $diffs[$pos])->op !== DIFF_EQUAL) {
                --$pos;
            }
            if ($pos === 0
                || !$this->diff_allowMergeEqual_($diffs, $pos, $pos, $diff, $opos)) {
                return $opos;
            }
            // backtrack
            $ndiffs = $opos;
            $opos = $pos;
        }
    }

    /**
     * Reduce the number of edits by eliminating semantically trivial equalities.
     * @param list<diff_obj> &$diffs Array of diff tuples.
     */
    function diff_cleanupSemantic(&$diffs) {
        '@phan-var-force list<diff_obj> &$diffs';
        assert($this->iota === 0);
        $pos = $opos = 1;
        $ndiffs = count($diffs);
        for ($pos = 1; $pos < $ndiffs; ++$pos) {
            $diff = $diffs[$pos];
            if ($diff->op === DIFF_EQUAL
                && $this->diff_allowMergeEqual_($diffs, $opos, $pos, $diff, $ndiffs)) {
                $opos = $this->diff_mergeEqual_($diffs, $opos, $pos, $diff, $ndiffs);
                while ($pos + 1 !== $ndiffs && $diffs[$pos+1]->op !== DIFF_EQUAL) {
                    ++$pos;
                }
            } else {
                $opos = $this->diff_merge1($diffs, $opos, $diff);
            }
        }
        array_splice($diffs, $opos);

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
            if ($diffs[$pos-1]->op === DIFF_DELETE
                && $diffs[$pos]->op === DIFF_INSERT) {
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
                        array_splice($diffs, $pos, 0, [new diff_obj(DIFF_EQUAL, substr($ins, 0, $overlaplen1))]);
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
                        array_splice($diffs, $pos, 0, [new diff_obj(DIFF_EQUAL,
                            substr($del, 0, $overlaplen2))]);
                        $diffs[$pos-1]->op = DIFF_INSERT;
                        $diffs[$pos-1]->text = substr($ins, 0, strlen($ins) - $overlaplen2);
                        $diffs[$pos+1]->op = DIFF_DELETE;
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
     * @param list<diff_obj> &$diffs Array of diff tuples.
     */
    function diff_cleanupSemanticLossless(&$diffs) {
        $pos = 1;
        $ndiffs = count($diffs);
        while ($pos < $ndiffs - 1) {
            if ($diffs[$pos-1]->op === DIFF_EQUAL
                && $diffs[$pos+1]->op === DIFF_EQUAL) {
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
     * @param list<diff_obj> &$diffs Array of diff tuples.
     */
    function diff_cleanupEfficiency(&$diffs) {
        '@phan-var-force list<diff_obj> &$diffs';
        $pos = 1;  // Index of current position.
        $ndiffs = count($diffs);
        while ($pos < $ndiffs - 1) {
            if ($diffs[$pos]->op === DIFF_EQUAL
                && strlen($diffs[$pos]->text) < $this->Diff_EditCost) {  // Equality found.
                $preins = $pos > 0 && $diffs[$pos - 1]->op === DIFF_INSERT ? 1 : 0;
                $predel = $pos > $preins && $diffs[$pos - $preins - 1]->op === DIFF_DELETE ? 1 : 0;
                $postdel = $pos + 1 < $ndiffs && $diffs[$pos + 1]->op === DIFF_DELETE ? 1 : 0;
                $postins = $pos + $postdel + 1 < $ndiffs && $diffs[$pos + $postdel + 1]->op === DIFF_INSERT ? 1 : 0;
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


    /** @param list<diff_obj> &$diffs
     * @param int $opos
     * @param diff_obj $diff
     * @return int */
    static private function diff_merge1(&$diffs, $opos, $diff) {
        if ($diff->text === "") {
            return $opos;
        } else if ($opos > 0
                   && $diffs[$opos-1]->op === $diff->op) {
            $diffs[$opos-1]->text .= $diff->text;
            return $opos;
        } else if ($opos > 0
                   && $diffs[$opos-1]->op === DIFF_INSERT
                   && $diff->op === DIFF_DELETE) {
            if ($opos > 1
                && $diffs[$opos-2]->op === DIFF_DELETE) {
                $diffs[$opos-2]->text .= $diff->text;
                return $opos;
            } else {
                $diffs[$opos] = $diffs[$opos-1];
                $diffs[$opos-1] = $diff;
                return $opos + 1;
            }
        } else {
            $diffs[$opos] = $diff;
            return $opos + 1;
        }
    }


    /**
     * Reorder and merge like edit sections.  Merge equalities.
     * Any edit section can move as long as it doesn't cross an equality.
     * @param list<diff_obj> &$diffs Array of diff tuples.
     */
    function diff_cleanupMerge(&$diffs) {
        // Add a dummy entry at the end.
        $diffs[] = new diff_obj(DIFF_EQUAL, "");
        $opos = 0;
        foreach ($diffs as $pos => $diff) {
            if ($diff->op !== DIFF_EQUAL) {
                $opos = $this->diff_merge1($diffs, $opos, $diff);
            } else {
                if ($opos > 1
                    && $diffs[$opos-1]->op === DIFF_INSERT
                    && $diffs[$opos-2]->op === DIFF_DELETE) {
                    // Factor out any common prefixes.
                    $ddiff = $diffs[$opos-2];
                    $idiff = $diffs[$opos-1];
                    $clen = $this->diff_commonPrefix($idiff->text, $ddiff->text);
                    if ($clen !== 0) {
                        $ctext = substr($idiff->text, 0, $clen);
                        $idiff->text = substr($idiff->text, $clen);
                        $ddiff->text = substr($ddiff->text, $clen);
                        assert($opos === 2 || $diffs[$opos-3]->op === DIFF_EQUAL);
                        if ($opos === 2) {
                            // Special case: inserting new DIFF_EQUAL at beginning.
                            // This is the only case we do splicing.
                            array_splice($diffs, 2, $pos - $opos);
                            array_splice($diffs, 0, 0, [new diff_obj(DIFF_EQUAL, $ctext)]);
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
                } else if ($opos !== 0 && $diffs[$opos-1]->op === DIFF_EQUAL) {
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
            if ($diff1->op === DIFF_EQUAL && $diff3->op === DIFF_EQUAL) {
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


    /**
     * Reduce the number of edits by eliminating semantically trivial equalities.
     * @param list<diff_obj> &$diffs Array of diff tuples.
     */
    private function line_diff_cleanupSemantic_(&$diffs) {
        '@phan-var-force list<diff_obj> &$diffs';
        assert($this->iota === 1);
        // Add a dummy entry at the end.
        $opos = 0;
        $ndiffs = count($diffs);
        for ($pos = 0; $pos !== $ndiffs; ++$pos) {
            $diff = $diffs[$pos];
            if ($diff->op !== DIFF_EQUAL) {
                $opos = $this->diff_merge1($diffs, $opos, $diff);
            } else if ($opos === 0) {
                $diffs[$opos] = $diff;
                ++$opos;
            } else if ($diffs[$opos-1]->op === DIFF_EQUAL) {
                $diffs[$opos-1]->text .= $diff->text;
            } else {
                $pdiff = $diffs[$opos-1];
                $clen = $this->diff_commonPrefix($pdiff->text, $diff->text) & ~1;
                if ($clen > 1 && $clen < strlen($pdiff->text)) {
                    $pfx = substr($diff->text, 0, $clen);
                    if ($opos > 2 && $diffs[$opos-2]->op === DIFF_EQUAL) {
                        $diffs[$opos-2]->text .= $pfx;
                    } else {
                        array_splice($diffs, $opos-1, 0, [new diff_obj(DIFF_EQUAL, $pfx)]);
                        ++$pos;
                        ++$opos;
                        ++$ndiffs;
                    }
                    $pdiff->text = substr($pdiff->text, $clen) . $pfx;
                    $diff->text = substr($diff->text, $clen);
                }
                if ($diff->text !== "") {
                    $diffs[$opos] = $diff;
                    ++$opos;
                }
            }
        }
        array_splice($diffs, $opos);
    }


    /** @param list<diff_obj> &$diffs */
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
                if ($diff->op === DIFF_EQUAL) {
                    // distribute to next diffs
                    $suffix = substr($text, $p);
                    $diff->text = substr($text, 0, $p);
                    $diffs[$pos + 1]->text = $suffix . $diffs[$pos + 1]->text;
                    if ($pos + 2 !== $ndiffs && $diffs[$pos + 2]->op === DIFF_INSERT) {
                        $diffs[$pos + 2]->text = $suffix . $diffs[$pos + 2]->text;
                        if ($diff->text === "") {
                            array_splice($diffs, $pos, 1);
                            --$ndiffs;
                        }
                    } else if ($diff->text === "") {
                        if ($diffs[$pos + 1]->op === DIFF_INSERT) {
                            $diff->op = DIFF_DELETE;
                        } else {
                            $diffs[$pos] = $diffs[$pos + 1];
                            $diffs[$pos + 1] = $diff;
                            $diff->op = DIFF_INSERT;
                        }
                        $diff->text = $suffix;
                    } else {
                        $hasdel = $diffs[$pos + 1]->op === DIFF_INSERT ? 0 : 1;
                        $ndiff = new diff_obj($hasdel ? DIFF_INSERT : DIFF_DELETE, $suffix);
                        array_splice($diffs, $pos + $hasdel + 1, 0, [$ndiff]);
                        ++$ndiffs;
                    }
                } else if ($diff->op === DIFF_DELETE) {
                    // claim from next diffs
                    // (INSERT invalid-UTF-8 cannot be cleaned up unless the previous
                    // diff deleted invalid UTF-8.)
                    $npos = $pos + ($diffs[$pos + 1]->op === DIFF_INSERT ? 2 : 1);
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
                            $postdel = $pos + 3 < $ndiffs && $diffs[$pos+3]->op === DIFF_DELETE ? 1 : 0;
                            $postins = $pos + 3 + $postdel < $ndiffs && $diffs[$pos+3+$postdel]->op === DIFF_INSERT ? 1 : 0;
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
                        $eqdiff->op = DIFF_INSERT;
                        $eqdiff->text = substr($diff->text, $p);
                        $postdel = $pos + 1 < $ndiffs && $diffs[$pos+1]->op === DIFF_DELETE ? 1 : 0;
                        $postins = $pos + 1 + $postdel < $ndiffs && $diffs[$pos+1+$postdel]->op === DIFF_INSERT ? 1 : 0;
                        if ($postdel) {
                            $diff->text .= $diffs[$pos+1]->text;
                        }
                        if ($postins) {
                            $eqdiff->text .= $diffs[$pos+1+$postdel]->text;
                        }
                        array_splice($diffs, $pos + 1, $postins + $postdel);
                        $ndiffs -= $postins + $postdel;
                    } else {
                        array_splice($diffs, $pos + 1, 0, [new diff_obj(DIFF_INSERT, substr($diff->text, $p))]);
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
     * @param list<diff_obj> $diffs Array of diff tuples.
     * @param int $loc Location within text1.
     * @return int Location within text2.
     */
    function diff_xIndex($diffs, $loc) {
        $chars1 = 0;
        $chars2 = 0;
        $last_chars1 = 0;
        $last_chars2 = 0;
        foreach ($diffs as $diff) {
            if ($diff->op !== DIFF_INSERT) {  // Equality or deletion.
                $chars1 += strlen($diff->text);
            }
            if ($diff->op !== DIFF_DELETE) {  // Equality or insertion.
                $chars2 += strlen($diff->text);
            }
            if ($chars1 > $loc) {  // Overshot the location.
                if ($diff->op !== DIFF_DELETE) {
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
     * @param list<diff_obj> $diffs Array of diff tuples.
     * @return string HTML representation.
     */
    function diff_prettyHtml($diffs) {
        $html = [];
        foreach ($diffs as $diff) {
            $text = htmlspecialchars($diff->text, ENT_NOQUOTES);
            $text = str_replace("\n", '&para;<br>', $text);
            if ($diff->op === DIFF_INSERT) {
                $html[] = "<ins style=\"background:#e6ffe6;\">{$text}</ins>";
            } else if ($diff->op === DIFF_DELETE) {
                $html[] = "<del style=\"background:#ffe6e6;\">{$text}</del>";
            } else {
                $html[] = "<span>{$text}</span>";
            }
        }
        return join("", $html);
    }


    /**
     * Compute and return the source text (all equalities and deletions).
     * @param list<diff_obj> $diffs Array of diff tuples.
     * @return string Source text.
     */
    function diff_text1($diffs) {
        $text = [];
        foreach ($diffs as $diff) {
            if ($diff->op !== DIFF_INSERT)
                $text[] = $diff->text;
        }
        return join("", $text);
    }


    /**
     * Compute and return the destination text (all equalities and insertions).
     * @param list<diff_obj> $diffs Array of diff tuples.
     * @return string Destination text.
     */
    function diff_text2($diffs) {
        $text = [];
        foreach ($diffs as $diff) {
            if ($diff->op !== DIFF_DELETE)
                $text[] = $diff->text;
        }
        return join("", $text);
    }


    /**
     * Compute the Levenshtein distance; the number of inserted, deleted or
     * substituted characters.
     * @param list<diff_obj> $diffs Array of diff tuples.
     * @return int Number of changes.
     */
    function diff_levenshtein($diffs) {
        $levenshtein = 0;
        $insertions = 0;
        $deletions = 0;
        foreach ($diffs as $diff) {
            if ($diff->op === DIFF_INSERT) {
                $insertions += strlen($diff->text);
            } else if ($diff->op === DIFF_DELETE) {
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
     * Character counts assume UTF16 encoding.
     * Operations are tab-separated.  Inserted text is escaped using %xx notation.
     * @param list<diff_obj> $diffs Array of diff tuples.
     * @return string Delta text.
     */
    function diff_toDelta($diffs) {
        $text = [];
        foreach ($diffs as $diff) {
            if ($diff->op === DIFF_INSERT) {
                $text[] = "+" . self::diff_encodeURI($diff->text);
            } else {
                $n = self::utf16strlen($diff->text);
                $text[] = ($diff->op === DIFF_DELETE ? "-" : "=") . $n;
            }
        }
        return join("\t", $text);
    }

    /**
     * Given the original text1, and an encoded string which describes the
     * operations required to transform text1 into text2, compute the full diff.
     * @param string $text1 Source string for the diff.
     * @param string $delta Delta text.
     * @return list<diff_obj> Array of diff tuples.
     * @throws diff_exception If invalid input.
     */
    function diff_fromDelta($text1, $delta) {
        $diffs = [];
        $pos = 0;  // cursor in $text1
        $len = strlen($text1);
        $dpos = 0;  // cursor in $delta
        $dlen = strlen($delta);
        while ($dpos !== $dlen) {
            $dtab = strpos($delta, "\t", $dpos);
            if ($dtab === $dpos) {
                $dpos = $dtab + 1;
                continue;
            } else if ($dtab === false) {
                $dtab = $dlen;
            }
            // Each token begins with a one character parameter which specifies the
            // operation of this token (delete, insert, equality).
            $op = $delta[$dpos];
            $param = substr($delta, $dpos + 1, $dtab - $dpos - 1);
            $dpos = min($dtab + 1, $dlen);
            if ($op === "+") {
                $diffs[] = new diff_obj(DIFF_INSERT, rawurldecode($param));
            } else if ($op === "-" || $op === "=") {
                if (!ctype_digit($param)
                    || ($n = intval($param)) <= 0) {
                    throw new diff_exception("Invalid number in diff_fromDelta");
                }
                $part = "";
                while (true) {
                    if ($pos + $n > strlen($text1)) {
                        throw new diff_exception("Invalid number in diff_fromDelta");
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
                if ($op === "-") {
                    $diffs[] = new diff_obj(DIFF_DELETE, $part);
                } else {
                    $diffs[] = new diff_obj(DIFF_EQUAL, $part);
                }
            } else {
                throw new diff_exception("Invalid operation in diff_fromDelta");
            }
        }
        if ($pos !== $len) {
            throw new diff_exception("Delta length doesn't cover source text");
        }
        return $diffs;
    }

    /**
     * Crush the diff into an encoded string which describes the operations
     * required to transform text1 into text2.
     * E.g. =3|-2|+ing  -> Keep 3 bytes, delete 2 bytes, insert 'ing'.
     * Operations are separated by |. Characters % and | are escaped using %xx notation.
     * @param list<diff_obj> $diffs Array of diff tuples.
     * @return string Delta text.
     */
    function diff_toHCDelta($diffs) {
        $text = [];
        foreach ($diffs as $diff) {
            if ($diff->op === DIFF_INSERT) {
                $text[] = "+" . str_replace(["%", "|"], ["%25", "%7C"], $diff->text);
            } else {
                $text[] = ($diff->op === DIFF_DELETE ? "-" : "=") . strlen($diff->text);
            }
        }
        return join("|", $text);
    }

    /**
     * Given the original text1, and an encoded string which describes the
     * operations required to transform text1 into text2, compute the full diff.
     * @param string $text1 Source string for the diff.
     * @param string $delta HCDelta text.
     * @return list<diff_obj> Array of diff tuples.
     * @throws diff_exception If invalid input.
     */
    function diff_fromHCDelta($text1, $delta) {
        $diffs = [];
        $pos = 0;  // cursor in $text1
        $len = strlen($text1);
        $dpos = 0;  // cursor in $delta
        $dlen = strlen($delta);
        while ($dpos !== $dlen) {
            $dtab = strpos($delta, "|", $dpos);
            if ($dtab === $dpos) {
                $dpos = $dtab + 1;
                continue;
            } else if ($dtab === false) {
                $dtab = $dlen;
            }
            // Each token begins with a one character parameter which specifies the
            // operation of this token (delete, insert, equality).
            $op = $delta[$dpos];
            $param = substr($delta, $dpos + 1, $dtab - $dpos - 1);
            if ($op === "+") {
                $diffs[] = new diff_obj(DIFF_INSERT, rawurldecode($param));
            } else if ($op === "-" || $op === "=") {
                if (!ctype_digit($param)
                    || ($n = intval($param)) <= 0
                    || $pos + $n > strlen($text1)) {
                    throw new diff_exception("Invalid number `{$param}` in diff_fromHCDelta @{$pos}/{$len}:{$dpos}");
                }
                $part = substr($text1, $pos, $n);
                $pos += $n;
                if ($op === "-") {
                    $diffs[] = new diff_obj(DIFF_DELETE, $part);
                } else {
                    $diffs[] = new diff_obj(DIFF_EQUAL, $part);
                }
            } else {
                throw new diff_exception("Invalid operation `{$op}` in diff_fromHCDelta @{$pos}/{$len}:{$dpos}");
            }
            $dpos = min($dtab + 1, $dlen);
        }
        if ($pos !== $len) {
            throw new diff_exception("Invalid source length in diff_fromHCDelta @{$pos}/{$len}");
        }
        return $diffs;
    }

    /**
     * Given the original text1, and an encoded string which describes the
     * operations required to transform text1 into text2, return text2.
     * @param string $text1 Source string for the diff.
     * @param string $delta HCDelta text.
     * @return string Transformed source string.
     * @throws diff_exception If invalid input.
     */
    function diff_applyHCDelta($text1, $delta) {
        $out = [];
        $pos = 0;  // cursor in $text1
        $len = strlen($text1);
        $dpos = 0;  // cursor in $delta
        $dlen = strlen($delta);
        while ($dpos !== $dlen) {
            $dtab = strpos($delta, "|", $dpos);
            if ($dtab === $dpos) {
                $dpos = $dtab + 1;
                continue;
            } else if ($dtab === false) {
                $dtab = $dlen;
            }
            // Each token begins with a one character parameter which specifies the
            // operation of this token (delete, insert, equality).
            $op = $delta[$dpos];
            $param = substr($delta, $dpos + 1, $dtab - $dpos - 1);
            if ($op === "+") {
                $out[] = rawurldecode($param);
            } else if ($op === "-" || $op === "=") {
                if (!ctype_digit($param)
                    || ($n = intval($param)) <= 0
                    || $pos + $n > strlen($text1)) {
                    throw new diff_exception("Invalid number `{$param}` in diff_applyHCDelta @{$pos}/{$len}:{$dpos}");
                }
                if ($op === "=") {
                    $out[] = substr($text1, $pos, $n);
                }
                $pos += $n;
            } else {
                throw new diff_exception("Invalid operation in diff_applyHCDelta @{$pos}/{$len}:{$dpos}");
            }
            $dpos = min($dtab + 1, $dlen);
        }
        if ($pos !== $len) {
            throw new diff_exception("Invalid source length in diff_applyHCDelta @{$pos}/{$len}");
        }
        return join("", $out);
    }


    /** @param list<string> $strs
     * @return list<diff_obj> */
    function diff_fromStringList($strs) {
        return diff_obj::parse_string_list($strs);
    }

    /** @param list<diff_obj> $diffs
     * @return list<string> */
    function diff_toStringList($diffs) {
        return diff_obj::unparse_string_list($diffs);
    }

    /** @param list<diff_obj> $diffs
     * @param string $s1
     * @param string $s2
     * @return void */
    function diff_validate($diffs, $s1, $s2) {
        if (($x = $this->diff_text1($diffs)) !== $s1) {
            throw new diff_exception("incorrect diff_text1", $s1, $x);
        }
        if (($x = $this->diff_text2($diffs)) !== $s2) {
            throw new diff_exception("incorrect diff_text2", $s2, $x);
        }
    }


    /** @param string $s
     * @return list<string> */
    static function split_lines($s) {
        $cr = $nl = -1;
        $pos = 0;
        $len = strlen($s);
        $lines = [];
        while ($pos !== $len) {
            if ($cr !== false && $cr < $pos) {
                $cr = strpos($s, "\r", $pos);
            }
            if ($nl !== false && $nl < $pos) {
                $nl = strpos($s, "\n", $pos);
            }
            if ($cr === false && $nl === false) {
                $npos = $len;
            } else if ($nl !== false && ($cr === false || $cr >= $nl - 1)) {
                $npos = $nl + 1;
            } else {
                $npos = $cr + 1;
            }
            if ($npos !== $pos) {
                $xpos = $npos;
                while ($xpos > $pos && ($s[$xpos-1] === "\n" || $s[$xpos-1] === "\r")) {
                    --$xpos;
                }
                $lines[] = substr($s, $pos, $xpos - $pos);
            }
            $pos = $npos;
        }
        return $lines;
    }

    /** @param list<diff_obj> $diffs
     * @param ?int $context
     * @return string */
    function line_diff_toUnified($diffs, $context = null) {
        $context = $context ?? 3;
        $l1 = $l2 = $sl1 = $sl2 = 1;
        $nl1 = $nl2 = 0;
        $ndiffs = count($diffs);
        $cpos = 0;
        $out = [""];
        for ($i = 0; $i !== $ndiffs; ++$i) {
            $diff = $diffs[$i];
            $ls = self::split_lines($diff->text);
            $nls = count($ls);
            if ($diff->op === DIFF_EQUAL) {
                $j = 0;
                if ($cpos !== 0 || count($out) !== 1) {
                    if ($nls <= 8 && $i !== $ndiffs - 1) {
                        $last = $nls - 3;
                    } else {
                        $last = min($nls, 3);
                    }
                    while ($j < $last) {
                        $out[] = " {$ls[$j]}\n";
                        ++$l1;
                        ++$nl1;
                        ++$l2;
                        ++$nl2;
                        ++$j;
                    }
                    if ($j < $nls - 3 && $i !== $ndiffs - 1) {
                        $out[$cpos] = "@@ -{$sl1},{$nl1} +{$sl2},{$nl2} @@\n";
                        $cpos = count($out);
                        $out[] = "";
                        $sl1 = $l1;
                        $sl2 = $l2;
                        $nl1 = $nl2 = 0;
                    }
                }
                if ($j < $nls - 3 && $cpos === count($out) - 1) {
                    $x = $nls - 3 - $j;
                    $l1 += $x;
                    $sl1 += $x;
                    $l2 += $x;
                    $sl2 += $x;
                    $j += $x;
                }
                if ($i !== $ndiffs - 1) {
                    while ($j < $nls) {
                        $out[] = " {$ls[$j]}\n";
                        ++$l1;
                        ++$nl1;
                        ++$l2;
                        ++$nl2;
                        ++$j;
                    }
                }
            } else if ($diff->op === DIFF_INSERT) {
                foreach ($ls as $t) {
                    $out[] = "+{$t}\n";
                }
                $nl2 += $nls;
                $l2 += $nls;
            } else {
                foreach ($ls as $t) {
                    $out[] = "-{$t}\n";
                }
                $nl1 += $nls;
                $l1 += $nls;
            }
        }
        $out[$cpos] = "@@ -{$sl1},{$nl1} +{$sl2},{$nl2} @@\n";
        return join("", $out);
    }


    /**
     * Increase the context until it is unique,
     * but don't let the pattern expand beyond Match_MaxBits.
     * @param patch_obj $patch The patch to grow.
     * @param string $text Source text.
     */
    private function patch_addContext_($patch, $text) {
        if ($text === "") {
            return;
        }
        if ($patch->start2 === null) {
            throw new diff_exception('patch not initialized');
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
            array_unshift($patch->diffs, new diff_obj(DIFF_EQUAL, $prefix));
        }
        // Add the suffix.
        $suffix = substr($text, $patch->start2 + $patch->length1, $padding);
        if ($suffix !== "") {
            $patch->diffs[] = new diff_obj(DIFF_EQUAL, $suffix);
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
     * @param string|list<diff_obj> $a text1 (methods 1,3,4) or
     * Array of diff tuples for text1 to text2 (method 2).
     * @param null|string|list<diff_obj> $opt_b text2 (methods 1,4) or
     * Array of diff tuples for text1 to text2 (method 3) or undefined (method 2).
     * @param null|string|list<diff_obj> $opt_c Array of diff tuples
     * for text1 to text2 (method 4) or undefined (methods 1,2,3).
     * @return list<patch_obj> Array of Patch objects.
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
            throw new diff_exception('Unknown call format to patch_make.');
        }

        if (empty($diffs)) {
            return [];  // Get rid of the null case.
        }
        $patches = [];
        $patch = new patch_obj;
        $char_count1 = 0;  // Number of characters into the text1 string.
        $char_count2 = 0;  // Number of characters into the text2 string.
        // Start with text1 (prepatch_text) and apply the diffs until we arrive at
        // text2 (postpatch_text).  We recreate the patches one by one to determine
        // context info.
        $prepatch_text = $text1;
        $postpatch_text = $text1;
        foreach ($diffs as $x => $diff) {
            if (empty($patch->diffs) && $diff->op !== DIFF_EQUAL) {
                // A new patch starts here.
                $patch->start1 = $char_count1;
                $patch->start2 = $char_count2;
            }

            if ($diff->op === DIFF_INSERT) {
                $patch->diffs[] = $diff;
                $patch->length2 += strlen($diff->text);
                $postpatch_text = substr($postpatch_text, 0, $char_count2)
                    . $diff->text . substr($postpatch_text, $char_count2);
            } else if ($diff->op === DIFF_DELETE) {
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
                    $patch = new patch_obj;
                    // Unlike Unidiff, our patch lists have a rolling context.
                    // https://github.com/google/diff-match-patch/wiki/Unidiff
                    // Update prepatch text & pos to reflect the application of the
                    // just completed patch.
                    $prepatch_text = $postpatch_text;
                    $char_count1 = $char_count2;
                }
            }

            // Update the current character count.
            if ($diff->op !== DIFF_INSERT) {
                $char_count1 += strlen($diff->text);
            }
            if ($diff->op !== DIFF_DELETE) {
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
     * @param list<patch_obj> $patches Array of Patch objects.
     * @return list<patch_obj> Array of Patch objects.
     */
    function patch_deepCopy($patches) {
        $copy = [];
        foreach ($patches as $p) {
            $copy[] = $p2 = clone $p;
            foreach ($p2->diffs as &$diff) {
                $diff = clone $diff;
            }
            unset($diff);
        }
        return $copy;
    }
}

class diff_obj implements \JsonSerializable {
    /** @var -1|0|1 */
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
     * @return list<diff_obj> */
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
                    throw new diff_exception("bad diff_obj::parse_string_list hex");
                }
                $ch = $s[1];
            } else {
                $x = substr($s, 1);
            }
            if ($ch === "+") {
                $a[] = new diff_obj(DIFF_INSERT, $x);
            } else if ($ch === "=") {
                $a[] = new diff_obj(DIFF_EQUAL, $x);
            } else if ($ch === "-") {
                $a[] = new diff_obj(DIFF_DELETE, $x);
            } else {
                throw new diff_exception("bad diff_obj::parse_string_list `{$ch}`");
            }
        }
        return $a;
    }

    /** @param list<diff_obj> $diffs
     * @return list<string> */
    static function unparse_string_list($diffs) {
        $a = [];
        foreach ($diffs as $diff) {
            $a[] = $diff->__toString();
        }
        return $a;
    }

    /** @param -1|0|1 $op
     * @return string */
    static function unparse_op($op) {
        $s = "-=+";
        return $s[$op + 1];
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
        return $x . self::unparse_op($this->op) . $t;
    }

    #[\ReturnTypeWillChange]
    function jsonSerialize() {
        return $this->__toString();
    }
}


class patch_obj {
    /** @var list<diff_obj> */
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
            if ($diff->op === DIFF_INSERT) {
                $op = "+";
            } else if ($diff->op === DIFF_DELETE) {
                $op = "-";
            } else {
                $op = " ";
            }
            $text[] = $op . diff_match_patch::diff_encodeURI($diff->text) . "\n";
        }
        return join("", $text);
    }
}


class histogram_state {
    const MAXMATCH = 64;

    /** @var int */
    public $mstate = 0;
    /** @var int */
    public $mpos1 = -1;
    /** @var int */
    public $mpos2 = -1;
    /** @var int */
    public $mlen = 0;
    /** @var int */
    private $mcount = self::MAXMATCH + 1;

    /** @param string $text1
     * @param string $text2
     * @param int $nlines */
    function __construct($text1, $text2, $nlines) {
        // analyze $text1
        $first = array_fill(0, $nlines, -1);
        $count = array_fill(0, $nlines, 0);
        $next = array_fill(0, strlen($text1) >> 1, -1);
        $len1 = strlen($text1);
        for ($j = 0; $j !== $len1; $j += 2) {
            $ch = ord($text1[$j]) | (ord($text1[$j+1]) << 8);
            $next[$j >> 1] = $first[$ch];
            $first[$ch] = $j;
            $count[$ch] += 1;
        }

        // find LCS with $text2
        $len2 = strlen($text2);
        $j = 0;
        while ($j !== $len2) {
            $j = $this->try_lcs($text1, $text2, $j, $first, $count, $next);
        }

        // too high match count => fallback
        if ($this->mstate === 1 && $this->mcount > self::MAXMATCH) {
            $this->mstate = 2;
        }
    }

    /** @param string $text1
     * @param string $text2
     * @param int $pos2
     * @param list<int> $first
     * @param list<int> $count
     * @param list<int> $next
     * @return int */
    function try_lcs($text1, $text2, $pos2, $first, $count, $next) {
        $focusch = ord($text2[$pos2]) | (ord($text2[$pos2+1]) << 8);
        $maxpos2 = $pos2 + 2;
        if ($first[$focusch] < 0) {
            return $maxpos2;
        }
        $this->mstate = 1;
        if ($count[$focusch] > $this->mcount) {
            return $maxpos2;
        }
        $len1 = strlen($text1);
        $len2 = strlen($text2);
        $pos1 = $first[$focusch];
        while ($pos1 >= 0) {
            $b1 = $pos1;
            $b2 = $pos2;
            $ml = 2;
            $rc = $count[$focusch];
            while ($b1 + $ml + 2 < $len1
                   && $b2 + $ml + 2 < $len2
                   && $text1[$b1 + $ml] === $text2[$b2 + $ml]
                   && $text1[$b1 + $ml + 1] === $text2[$b2 + $ml + 1]) {
                if ($rc > 1) {
                    $ch = ord($text1[$b1 + $ml]) | (ord($text1[$b1 + $ml + 1]) << 8);
                    $rc = min($rc, $count[$ch]);
                }
                $ml += 2;
            }
            while ($b1 > 0
                   && $b2 > 0
                   && $text1[$b1 - 2] === $text2[$b2 - 2]
                   && $text1[$b1 - 1] === $text2[$b2 - 1]) {
                $b1 -= 2;
                $b2 -= 2;
                $ml += 2;
                if ($rc > 1) {
                    $ch = ord($text1[$b1]) | (ord($text1[$b1+1]) << 8);
                    $rc = min($rc, $count[$ch]);
                }
            }
            $maxpos2 = max($maxpos2, $b2 + $ml);
            if ($ml > $this->mlen || $rc < $this->mcount) {
                $this->mpos1 = $b1;
                $this->mpos2 = $b2;
                $this->mlen = $ml;
                $this->mcount = $rc;
            }

            $pos1 = $next[$pos1 >> 1];
            while ($pos1 >= 0 && $pos1 < $b1 + $ml) {
                $pos1 = $next[$pos1 >> 1];
            }
        }
        return $maxpos2;
    }
}


class diff_exception extends \RuntimeException {
    /** @var ?string */
    public $expected;
    /** @var ?string */
    public $actual;
    /** @param string $msg
     * @param ?string $expected
     * @param ?string $actual */
    function __construct($msg, $expected = null, $actual = null) {
        parent::__construct($msg);
        $this->expected = $expected;
        $this->actual = $actual;
    }
}
