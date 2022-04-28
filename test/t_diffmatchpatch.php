<?php
// t_diffmatchpatch.php -- Tester for HotCRP diff-match-patch
// Copyright 2018 The diff-match-patch Authors.
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.
// Ported with some changes from Neil Fraser's diff-match-patch:
// https://github.com/google/diff-match-patch/

use dmp\diff_match_patch as diff_match_patch;
use const dmp\DIFF_DELETE;
use const dmp\DIFF_INSERT;

class DiffMatchPatch_Tester {
    function assertEquals($a, $b) {
        if ($a !== $b) {
            $tr = explode("\n", (new Exception)->getTraceAsString());
            $s = preg_replace('/\A\#?\d*\s*/', "", $tr[0]);
            fwrite(STDERR, "ASSERTION FAILURE: $s\n");
            fwrite(STDERR, "  expected " . (is_string($a) ? $a : (json_encode($a) ?: var_export($a, true))) . "\n");
            fwrite(STDERR, "       got " . (is_string($b) ? $b : (json_encode($b) ?: var_export($b, true))) . "\n");
            fwrite(STDERR, join("\n", $tr) . "\n");
        }
        xassert($a === $b);
    }

    /** @param list<dmp\diff_obj>|list<string>|string $ax
     * @param list<dmp\diff_obj> $b */
    function assertEqualDiffs($ax, $b) {
        $al = is_string($ax) ? json_decode($ax) : $ax;
        if (!empty($al) && is_string($al[0])) {
            $a = dmp\diff_obj::parse_string_list($al);
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
                fwrite(STDERR, "       got diff    " . json_encode($b) . "\n");
                fwrite(STDERR, join("\n", $tr) . "\n");
                xassert(false);
                break;
            }
            xassert($da !== null && $db !== null && $da->op === $db->op && $da->text === $db->text);
        }
    }

    function testDiffCommonPrefix() {
        $dmp = new diff_match_patch;

        // Detect any common prefix.
        // Null case.
        $this->assertEquals(0, $dmp->diff_commonPrefix('abc', 'xyz'));

        // Non-null case.
        $this->assertEquals(4, $dmp->diff_commonPrefix('1234abcdef', '1234xyz'));

        // Whole case.
        $this->assertEquals(4, $dmp->diff_commonPrefix('1234', '1234xyz'));
    }

    function testDiffCommonSuffix() {
        $dmp = new diff_match_patch;

        // Detect any common suffix.
        // Null case.
        $this->assertEquals(0, $dmp->diff_commonSuffix('abc', 'xyz'));

        // Non-null case.
        $this->assertEquals(4, $dmp->diff_commonSuffix('abcdef1234', 'xyz1234'));

        // Whole case.
        $this->assertEquals(4, $dmp->diff_commonSuffix('1234', 'xyz1234'));
    }

    function testDiffCommonOverlap() {
        $dmp = new diff_match_patch;
        $m = new \ReflectionMethod("dmp\\diff_match_patch", "diff_commonOverlap_");
        $m->setAccessible(true);

        // Detect any suffix/prefix overlap.
        // Null case.
        $this->assertEquals(0, $m->invoke($dmp, '', 'abcd'));

        // Whole case.
        $this->assertEquals(3, $m->invoke($dmp, 'abc', 'abcd'));

        // No overlap.
        $this->assertEquals(0, $m->invoke($dmp, '123456', 'abcd'));

        // Overlap.
        $this->assertEquals(3, $m->invoke($dmp, '123456xxx', 'xxxabcd'));

        // Unicode.
        // Some overly clever languages (C#) may treat ligatures as equal to their
        // component letters.  E.g. U+FB01 == 'fi'
        $this->assertEquals(0, $m->invoke($dmp, 'fi', '\ufb01i'));
    }

    function testDiffHalfMatch() {
        // Detect a halfmatch.
        $dmp = new diff_match_patch;
        $dmp->Diff_Timeout = 1;
        $m = new \ReflectionMethod("dmp\\diff_match_patch", "diff_halfMatch_");
        $m->setAccessible(true);

        // No match.
        $this->assertEquals(null, $m->invoke($dmp, '1234567890', 'abcdef'));

        $this->assertEquals(null, $m->invoke($dmp, '12345', '23'));

        // Single Match.
        $this->assertEquals(['12', '90', 'a', 'z', '345678'], $m->invoke($dmp, '1234567890', 'a345678z'));

        $this->assertEquals(['a', 'z', '12', '90', '345678'], $m->invoke($dmp, 'a345678z', '1234567890'));

        $this->assertEquals(['abc', 'z', '1234', '0', '56789'], $m->invoke($dmp, 'abc56789z', '1234567890'));

        $this->assertEquals(['a', 'xyz', '1', '7890', '23456'], $m->invoke($dmp, 'a23456xyz', '1234567890'));

        // Multiple Matches.
        $this->assertEquals(['12123', '123121', 'a', 'z', '1234123451234'], $m->invoke($dmp, '121231234123451234123121', 'a1234123451234z'));

        $this->assertEquals(['', '-=-=-=-=-=', 'x', '', 'x-=-=-=-=-=-=-='], $m->invoke($dmp, 'x-=-=-=-=-=-=-=-=-=-=-=-=', 'xx-=-=-=-=-=-=-='));

        $this->assertEquals(['-=-=-=-=-=', '', '', 'y', '-=-=-=-=-=-=-=y'], $m->invoke($dmp, '-=-=-=-=-=-=-=-=-=-=-=-=y', '-=-=-=-=-=-=-=yy'));

        // Non-optimal halfmatch.
        // Optimal diff would be -q+x=H-i+e=lloHe+Hu=llo-Hew+y not -qHillo+x=HelloHe-w+Hulloy
        $this->assertEquals(['qHillo', 'w', 'x', 'Hulloy', 'HelloHe'], $m->invoke($dmp, 'qHilloHelloHew', 'xHelloHeHulloy'));

        // Optimal no halfmatch.
        $dmp->Diff_Timeout = 0;
        $this->assertEquals(null, $m->invoke($dmp, 'qHilloHelloHew', 'xHelloHeHulloy'));
    }

    function testDiffLinesToChars() {
        $dmp = new diff_match_patch;
        $m = new \ReflectionMethod("dmp\\diff_match_patch", "diff_linesToChars_");
        $m->setAccessible(true);

        // Convert lines down to characters.
        $this->assertEquals(["\x01\x00\x02\x00\x01\x00", "\x02\x00\x01\x00\x02\x00", ["", "alpha\n", "beta\n"]],
            $m->invoke($dmp, "alpha\nbeta\nalpha\n", "beta\nalpha\nbeta\n"));

        $this->assertEquals(["", "\x01\x00\x02\x00\x03\x00\x03\x00", ["", "alpha\r\n", "beta\r\n", "\r\n"]],
            $m->invoke($dmp, "", "alpha\r\nbeta\r\n\r\n\r\n"));

        $this->assertEquals(["\x01\x00", "\x02\x00", ["", "a", "b"]],
            $m->invoke($dmp, "a", "b"));

        // More than 256 to reveal any 8-bit limitations.
        $n = 300;
        $lineList = $charList = [];
        for ($i = 1; $i <= 300; ++$i) {
            $lineList[] = "{$i}\n";
            $charList[] = chr($i % 256) . chr($i >> 8);
        }
        $this->assertEquals($n, count($lineList));
        $lines = join("", $lineList);
        $chars = join("", $charList);
        $this->assertEquals($n * 2, strlen($chars));
        array_unshift($lineList, "");
        $this->assertEquals([$chars, "", $lineList], $m->invoke($dmp, $lines, ""));
    }

    function testDiffCharsToLines() {
        $dmp = new diff_match_patch;
        $m = new \ReflectionMethod("dmp\\diff_match_patch", "diff_charsToLines_");
        $m->setAccessible(true);
        $ml2c = new \ReflectionMethod("dmp\\diff_match_patch", "diff_linesToChars_");
        $ml2c->setAccessible(true);

        // Convert chars up to lines.
        $diffs = $dmp->diff_fromStringList(["=\x01\x00\x02\x00\x01\x00", "+\x02\x00\x01\x00\x02\x00"]);
        $m->invoke($dmp, $diffs, ["", "alpha\n", "beta\n"]);
        $this->assertEqualDiffs(["=alpha\nbeta\nalpha\n","+beta\nalpha\nbeta\n"], $diffs);

        // More than 256 to reveal any 8-bit limitations.
        $n = 300;
        $lineList = [];
        $charList = [];
        for ($i = 1; $i <= $n; ++$i) {
            $lineList[] = "{$i}\n";
            $charList[] = chr($i % 256) . chr($i >> 8);
        }
        $this->assertEquals($n, count($lineList));
        $lines = join("", $lineList);
        $chars = join("", $charList);
        $this->assertEquals($n * 2, strlen($chars));
        array_unshift($lineList, "");
        $diffs = [new dmp\diff_obj(DIFF_DELETE, $chars)];
        $m->invoke($dmp, $diffs, $lineList);
        $this->assertEqualDiffs(["-{$lines}"], $diffs);

        // More than 65536 to verify any 16-bit limitation.
        $lineList = [];
        for ($i = 0; $i < 66000; ++$i) {
            $lineList[] = "{$i}\n";;
        }
        $chars = join("", $lineList);
        $results = $ml2c->invoke($dmp, $chars, "");
        $diffs = [new dmp\diff_obj(DIFF_INSERT, $results[0])];
        $m->invoke($dmp, $diffs, $results[2]);
        $this->assertEquals($chars, $diffs[0]->text);
    }

    function testDiffCleanupMerge() {
        $dmp = new diff_match_patch;

        // Cleanup a messy diff.
        // Null case.
        $diffs = [];
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEquals([], $diffs);

        // No change case.
        $diffs = $dmp->diff_fromStringList(["=a", "-b", "+c"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["=a","-b","+c"], $diffs);

        // Merge equalities.
        $diffs = $dmp->diff_fromStringList(["=a", "=b", "=c"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["=abc"], $diffs);

        // Merge deletions.
        $diffs = $dmp->diff_fromStringList(["-a", "-b", "-c"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["-abc"], $diffs);

        // Merge insertions.
        $diffs = $dmp->diff_fromStringList(["+a", "+b", "+c"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["+abc"], $diffs);

        // Merge interweave.
        $diffs = $dmp->diff_fromStringList(["-a", "+b", "-c", "+d", "=e", "=f"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["-ac","+bd","=ef"], $diffs);

        // Prefix and suffix detection.
        $diffs = $dmp->diff_fromStringList(["-a","+abc","-dc"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["=a","-d","+b","=c"], $diffs);

        // Prefix and suffix detection with equalities.
        $diffs = $dmp->diff_fromStringList(["=x","-a","+abc","-dc","=y"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["=xa","-d","+b","=cy"], $diffs);

        // Slide edit left.
        $diffs = $dmp->diff_fromStringList(["=a","+ba","=c"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["+ab","=ac"], $diffs);

        // Slide edit right.
        $diffs = $dmp->diff_fromStringList(["=c","+ab","=a"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["=ca","+ba"], $diffs);

        // Slide edit left recursive.
        $diffs = $dmp->diff_fromStringList(["=a","-b","=c","-ac","=x"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["-abc","=acx"], $diffs);

        // Slide edit right recursive.
        $diffs = $dmp->diff_fromStringList(["=x","-ca","=c","-b","=a"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["=xca","-cba"], $diffs);

        // Empty merge.
        $diffs = $dmp->diff_fromStringList(["-b","+ab","=c"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["+a","=bc"], $diffs);

        // Empty equality.
        $diffs = $dmp->diff_fromStringList(["=","+a","=b"]);
        $dmp->diff_cleanupMerge($diffs);
        $this->assertEqualDiffs(["+a","=b"], $diffs);
    }


    function testDiffCleanupSemanticLossless() {
        $dmp = new diff_match_patch;

        // Slide diffs to match logical boundaries.
        // Null case.
        $diffs = [];
        $dmp->diff_cleanupSemanticLossless($diffs);
        $this->assertEquals([], $diffs);

        // Blank lines.
        $diffs = $dmp->diff_fromStringList(["=AAA\r\n\r\nBBB","+\r\nDDD\r\n\r\nBBB","=\r\nEEE"]);
        $dmp->diff_cleanupSemanticLossless($diffs);
        $this->assertEqualDiffs(["=AAA\r\n\r\n","+BBB\r\nDDD\r\n\r\n","=BBB\r\nEEE"], $diffs);

        // Line boundaries.
        $diffs = $dmp->diff_fromStringList(["=AAA\r\n\r\nBBB","+ DDD\r\nBBB","= EEE"]);
        $dmp->diff_cleanupSemanticLossless($diffs);
        $this->assertEqualDiffs(["=AAA\r\n\r\n","+BBB DDD\r\n","=BBB EEE"], $diffs);

        // Word boundaries.
        $diffs = $dmp->diff_fromStringList(["=The c","+ow and the c","=at."]);
        $dmp->diff_cleanupSemanticLossless($diffs);
        $this->assertEqualDiffs(["=The ","+cow and the ","=cat."], $diffs);

        // Alphanumeric boundaries.
        $diffs = $dmp->diff_fromStringList(["=The-c","+ow-and-the-c","=at."]);
        $dmp->diff_cleanupSemanticLossless($diffs);
        $this->assertEqualDiffs(["=The-","+cow-and-the-","=cat."], $diffs);

        // Hitting the start.
        $diffs = $dmp->diff_fromStringList(["=a","-a","=ax"]);
        $dmp->diff_cleanupSemanticLossless($diffs);
        $this->assertEqualDiffs(["-a","=aax"], $diffs);

        // Hitting the end.
        $diffs = $dmp->diff_fromStringList(["=xa","-a","=a"]);
        $dmp->diff_cleanupSemanticLossless($diffs);
        $this->assertEqualDiffs(["=xaa","-a"], $diffs);

        // Sentence boundaries.
        $diffs = $dmp->diff_fromStringList(["=The xxx. The ","+zzz. The ","=yyy."]);
        $dmp->diff_cleanupSemanticLossless($diffs);
        $this->assertEqualDiffs(["=The xxx.","+ The zzz.","= The yyy."], $diffs);
    }


    function testDiffCleanupSemantic() {
        $dmp = new diff_match_patch;

        // Cleanup semantically trivial equalities.
        // Null case.
        $diffs = [];
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs([], $diffs);

        // No elimination #1.
        $diffs = $dmp->diff_fromStringList(["-ab","+cd","=12","-e"]);
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs(["-ab","+cd","=12","-e"], $diffs);

        // No elimination #2.
        $diffs = $dmp->diff_fromStringList(["-abc","+ABC","=1234","-wxyz"]);
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs(["-abc","+ABC","=1234","-wxyz"], $diffs);

        // Simple elimination.
        $diffs = $dmp->diff_fromStringList(["-a","=b","-c"]);
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs(["-abc","+b"], $diffs);

        // Backpass elimination.
        $diffs = $dmp->diff_fromStringList(["-ab","=cd","-e","=f","+g"]);
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs(["-abcdef","+cdfg"], $diffs);

        // Multiple eliminations.
        $diffs = $dmp->diff_fromStringList(["+1","=A","-B","+2","=_","+1","=A","-B","+2"]);
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs(["-AB_AB","+1A2_1A2"], $diffs);

        // Word boundaries.
        $diffs = $dmp->diff_fromStringList(["=The c","-ow and the c","=at."]);
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs(["=The ","-cow and the ","=cat."], $diffs);

        // No overlap elimination.
        $diffs = $dmp->diff_fromStringList(["-abcxx","+xxdef"]);
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs(["-abcxx","+xxdef"], $diffs);

        // Overlap elimination.
        $diffs = $dmp->diff_fromStringList(["-abcxxx","+xxxdef"]);
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs(["-abc","=xxx","+def"], $diffs);

        // Reverse overlap elimination.
        $diffs = $dmp->diff_fromStringList(["-xxxabc","+defxxx"]);
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs(["+def","=xxx","-abc"], $diffs);

        // Two overlap eliminations.
        $diffs = $dmp->diff_fromStringList(["-abcd1212","+1212efghi","=----","-A3","+3BC"]);
        $dmp->diff_cleanupSemantic($diffs);
        $this->assertEqualDiffs(["-abcd","=1212","+efghi","=----","-A","=3","+BC"], $diffs);
    }


    function testDiffCleanupEfficiency() {
        $dmp = new diff_match_patch;
        $dmp->Diff_EditCost = 4;

        // Null case.
        $diffs = [];
        $dmp->diff_cleanupEfficiency($diffs);
        $this->assertEqualDiffs("[]", $diffs);

        // No elimination.
        $diffs = $dmp->diff_fromStringList(["-ab", "+12", "=wxyz", "-cd", "+34"]);
        $dmp->diff_cleanupEfficiency($diffs);
        $this->assertEqualDiffs(["-ab","+12","=wxyz","-cd","+34"], $diffs);

        // Four-edit elimination.
        $diffs = $dmp->diff_fromStringList(["-ab","+12","=xyz","-cd","+34"]);
        $dmp->diff_cleanupEfficiency($diffs);
        $this->assertEqualDiffs(["-abxyzcd","+12xyz34"], $diffs);

        // Three-edit elimination.
        $diffs = $dmp->diff_fromStringList(["+12","=x","-cd","+34"]);
        $dmp->diff_cleanupEfficiency($diffs);
        $this->assertEqualDiffs(["-xcd","+12x34"], $diffs);

        // Backpass elimination.
        $diffs = $dmp->diff_fromStringList(["-ab","+12","=xy","+34","=z","-cd","+56"]);
        $dmp->diff_cleanupEfficiency($diffs);
        $this->assertEqualDiffs(["-abxyzcd","+12xy34z56"], $diffs);

        // High cost elimination.
        $dmp->Diff_EditCost = 5;
        $diffs = $dmp->diff_fromStringList(["-ab","+12","=wxyz","-cd","+34"]);
        $dmp->diff_cleanupEfficiency($diffs);
        $this->assertEqualDiffs(["-abwxyzcd","+12wxyz34"], $diffs);
    }

    function testDiffPrettyHtml() {
        // Pretty print.
        $dmp = new diff_match_patch;
        $diffs = $dmp->diff_fromStringList(["=a\n","-<B>b</B>","+c&d"]);
        $this->assertEquals('<span>a&para;<br></span><del style="background:#ffe6e6;">&lt;B&gt;b&lt;/B&gt;</del><ins style="background:#e6ffe6;">c&amp;d</ins>', $dmp->diff_prettyHtml($diffs));
    }

    function testDiffText() {
        // Compute the source and destination texts.
        $dmp = new diff_match_patch;
        $diffs = $dmp->diff_fromStringList(["=jump","-s","+ed","= over ","-the","+a","= lazy"]);
        $this->assertEquals('jumps over the lazy', $dmp->diff_text1($diffs));
        $this->assertEquals('jumped over a lazy', $dmp->diff_text2($diffs));
    }

    function testDiffDelta() {
        $dmp = new diff_match_patch;

        // Convert a diff into delta string.
        $diffs = $dmp->diff_fromStringList(["=jump","-s","+ed","= over ","-the","+a","= lazy","+old dog"]);
        $text1 = $dmp->diff_text1($diffs);
        $this->assertEquals('jumps over the lazy', $text1);

        $delta = $dmp->diff_toDelta($diffs);
        $this->assertEquals("=4\t-1\t+ed\t=6\t-3\t+a\t=5\t+old dog", $delta);

        // Convert delta string into a diff.
        $this->assertEqualDiffs($diffs, $dmp->diff_fromDelta($text1, $delta));

        // Generates error (19 != 20).
        try {
            $dmp->diff_fromDelta($text1 . 'x', $delta);
            $this->assertEquals(false, true);
        } catch (\Exception $e) {
            // Exception expected.
        }

        // Generates error (19 != 18).
        try {
            $dmp->diff_fromDelta(substr($text1, 1), $delta);
            $this->assertEquals(false, true);
        } catch (\Exception $e) {
            // Exception expected.
        }

        // Generates error (%c3%xy invalid Unicode).
        // XXX This is not validated in the PHP version.
        try {
            $dmp->diff_fromDelta('', '+%c3%xy');
            $this->assertEquals(true, true);  // XXX
        } catch (\Exception $e) {
            // Exception should be expected.
            $this->assertEquals(false, true);   // XXX
        }

        // Test deltas with special characters.
        $diffs = $dmp->diff_fromStringList(["=\xda\x80 \x00 \t %","-\xda\x81 \x01 \n ^","+\xda\x82 \x02 \\ |"]);
        $text1 = $dmp->diff_text1($diffs);
        $this->assertEquals("\xda\x80 \x00 \t %\xda\x81 \x01 \n ^", $text1);

        $delta = $dmp->diff_toDelta($diffs);
        $this->assertEquals("=7\t-7\t+%DA%82 %02 %5C %7C", $delta);

        // Convert delta string into a diff.
        $this->assertEqualDiffs($diffs, $dmp->diff_fromDelta($text1, $delta));

        // Test deltas for surrogate pairs.
        $diffs = $dmp->diff_fromStringList(["=😀Héló"]);
        $text1 = $dmp->diff_text1($diffs);
        $this->assertEquals("😀Héló", $text1);

        $delta = $dmp->diff_toDelta($diffs);
        $this->assertEquals("=6", $delta);

        $this->assertEqualDiffs($diffs, $dmp->diff_fromDelta($text1, $delta));

        // Verify pool of unchanged characters.
        $diffs = $dmp->diff_fromStringList(['+A-Z a-z 0-9 - _ . ! ~ * \' ( ) ; / ? : @ & = + $ , # ']);
        $text2 = $dmp->diff_text2($diffs);
        $this->assertEquals('A-Z a-z 0-9 - _ . ! ~ * \' ( ) ; / ? : @ & = + $ , # ', $text2);

        $delta = $dmp->diff_toDelta($diffs);
        $this->assertEquals('+A-Z a-z 0-9 - _ . ! ~ * \' ( ) ; / ? : @ & = + $ , # ', $delta);

        // Convert delta string into a diff.
        $this->assertEqualDiffs($diffs, $dmp->diff_fromDelta('', $delta));

        // 160 kb string.
        $a = 'abcdefghij';
        for ($i = 0; $i < 14; ++$i) {
            $a .= $a;
        }
        $diffs = [new dmp\diff_obj(DIFF_INSERT, $a)];
        $delta = $dmp->diff_toDelta($diffs);
        $this->assertEquals('+' . $a, $delta);

        // Convert delta string into a diff.
        $this->assertEqualDiffs($diffs, $dmp->diff_fromDelta('', $delta));
    }

    function testDiffHCDelta() {
        $dmp = new diff_match_patch;

        // Convert a diff into delta string.
        $diffs = $dmp->diff_fromStringList(["=jump","-s","+ed","= over ","-the","+a","= lazy","+old dog"]);
        $text1 = $dmp->diff_text1($diffs);
        $this->assertEquals('jumps over the lazy', $text1);
        $text2 = $dmp->diff_text2($diffs);

        $delta = $dmp->diff_toHCDelta($diffs);
        $this->assertEquals("=4|-1|+ed|=6|-3|+a|=5|+old dog", $delta);

        // Convert delta string into a diff.
        $this->assertEqualDiffs($diffs, $dmp->diff_fromHCDelta($text1, $delta));
        $this->assertEquals($text2, $dmp->diff_applyHCDelta($text1, $delta));

        // Generates error (19 != 20).
        try {
            $dmp->diff_fromHCDelta($text1 . 'x', $delta);
            $this->assertEquals(false, true);
        } catch (\Exception $e) {
            // Exception expected.
        }

        // Generates error (19 != 18).
        try {
            $dmp->diff_fromHCDelta(substr($text1, 1), $delta);
            $this->assertEquals(false, true);
        } catch (\Exception $e) {
            // Exception expected.
        }

        // Test deltas with special characters.
        $diffs = $dmp->diff_fromStringList(["=\xda\x80 \x00 \t %|","-\xda\x81 \x01 \n ^","+\xda\x82 \x02 \\ |%"]);
        $text1 = $dmp->diff_text1($diffs);
        $this->assertEquals("\xda\x80 \x00 \t %|\xda\x81 \x01 \n ^", $text1);
        $text2 = $dmp->diff_text2($diffs);

        $delta = $dmp->diff_toHCDelta($diffs);
        $this->assertEquals("=9|-8|+\xda\x82 \x02 \\ %7C%25", $delta);

        // Convert delta string into a diff.
        $this->assertEqualDiffs($diffs, $dmp->diff_fromHCDelta($text1, $delta));
        $this->assertEquals($text2, $dmp->diff_applyHCDelta($text1, $delta));

        // Test deltas for surrogate pairs.
        $diffs = $dmp->diff_fromStringList(["=😀Héló"]);
        $text1 = $dmp->diff_text1($diffs);
        $this->assertEquals("😀Héló", $text1);
        $text2 = $dmp->diff_text1($diffs);

        $delta = $dmp->diff_toHCDelta($diffs);
        $this->assertEquals("=10", $delta);

        $this->assertEqualDiffs($diffs, $dmp->diff_fromHCDelta($text1, $delta));
        $this->assertEquals($text2, $dmp->diff_applyHCDelta($text1, $delta));

        // Verify pool of unchanged characters.
        $diffs = $dmp->diff_fromStringList(['+A-Z a-z 0-9 - _ . ! ~ * \' ( ) ; / ? : @ & = + $ , # ']);
        $text2 = $dmp->diff_text2($diffs);
        $this->assertEquals('A-Z a-z 0-9 - _ . ! ~ * \' ( ) ; / ? : @ & = + $ , # ', $text2);

        $delta = $dmp->diff_toHCDelta($diffs);
        $this->assertEquals('+A-Z a-z 0-9 - _ . ! ~ * \' ( ) ; / ? : @ & = + $ , # ', $delta);

        // Convert delta string into a diff.
        $this->assertEqualDiffs($diffs, $dmp->diff_fromHCDelta('', $delta));

        // 160 kb string.
        $a = 'abcdefghij';
        for ($i = 0; $i < 14; ++$i) {
            $a .= $a;
        }
        $diffs = [new dmp\diff_obj(DIFF_INSERT, $a)];
        $delta = $dmp->diff_toHCDelta($diffs);
        $this->assertEquals('+' . $a, $delta);

        // Convert delta string into a diff.
        $this->assertEqualDiffs($diffs, $dmp->diff_fromHCDelta('', $delta));
    }

    function testDiffXIndex() {
        $dmp = new diff_match_patch;

        // Translate a location in text1 to text2.
        // Translation on equality.
        $this->assertEquals(5, $dmp->diff_xIndex($dmp->diff_fromStringList(["-a","+1234","=xyz"]), 2));

        // Translation on deletion.
        $this->assertEquals(1, $dmp->diff_xIndex($dmp->diff_fromStringList(["=a","-1234","=xyz"]), 3));
    }

    function testDiffLevenshtein() {
        $dmp = new diff_match_patch;

        // Levenshtein with trailing equality.
        $this->assertEquals(4, $dmp->diff_levenshtein($dmp->diff_fromStringList(["-abc","+1234","=xyz"])));
        // Levenshtein with leading equality.
        $this->assertEquals(4, $dmp->diff_levenshtein($dmp->diff_fromStringList(["=xyz","-abc","+1234"])));
        // Levenshtein with middle equality.
        $this->assertEquals(7, $dmp->diff_levenshtein($dmp->diff_fromStringList(["-abc","=xyz","+1234"])));
    }

    function testDiffBisect() {
        $dmp = new diff_match_patch;
        $m = new \ReflectionMethod("dmp\\diff_match_patch", "diff_bisect_");
        $m->setAccessible(true);

        // Normal.
        $a = 'cat';
        $b = 'map';
        // Since the resulting diff hasn't been normalized, it would be ok if
        // the insertion and deletion pairs are swapped.
        // If the order changes, tweak this test as required.
        $this->assertEqualDiffs(["-c", "+m", "=a", "-t", "+p"], $m->invoke($dmp, $a, $b, INF));

        // Timeout.
        $this->assertEqualDiffs(["-cat", "+map"], $m->invoke($dmp, $a, $b, 0));
    }

    function testDiffMain() {
        $dmp = new diff_match_patch;

        // Perform a trivial diff.
        // Null case.
        $this->assertEqualDiffs([], $dmp->diff_main('', '', false));

        // Equality.
        $this->assertEqualDiffs(["=abc"], $dmp->diff_main('abc', 'abc', false));

        // Simple insertion.
        $this->assertEqualDiffs(["=ab", "+123", "=c"], $dmp->diff_main('abc', 'ab123c', false));

        // Simple deletion.
        $this->assertEqualDiffs(["=a", "-123", "=bc"], $dmp->diff_main('a123bc', 'abc', false));

        // Two insertions.
        $this->assertEqualDiffs(["=a", "+123", "=b", "+456", "=c"], $dmp->diff_main('abc', 'a123b456c', false));

        // Two deletions.
        $this->assertEqualDiffs(["=a", "-123", "=b", "-456", "=c"], $dmp->diff_main('a123b456c', 'abc', false));

        // Perform a real diff.
        // Switch off the timeout.
        $dmp->Diff_Timeout = 0;
        // Simple cases.
        $this->assertEqualDiffs(["-a", "+b"], $dmp->diff_main('a', 'b', false));

        $this->assertEqualDiffs(["-Apple", "+Banana", "=s are a", "+lso", "= fruit."], $dmp->diff_main('Apples are a fruit.', 'Bananas are also fruit.', false));

        $this->assertEqualDiffs(["-a", "+\xDA\x80", "=x", "-\t", "+\0"], $dmp->diff_main("ax\t", "\xDA\x80x\0", false));

        // Overlaps.
        $this->assertEqualDiffs(["-1", "=a", "-y", "=b", "-2", "+xab"], $dmp->diff_main('1ayb2', 'abxab', false));

        $this->assertEqualDiffs(["+xaxcx", "=abc", "-y"], $dmp->diff_main('abcy', 'xaxcxabc', false));

        $this->assertEqualDiffs(["-ABCD", "=a", "-=", "+-", "=bcd", "-=", "+-", "=efghijklmnopqrs", "-EFGHIJKLMNOefg"], $dmp->diff_main('ABCDa=bcd=efghijklmnopqrsEFGHIJKLMNOefg', 'a-bcd-efghijklmnopqrs', false));

        // Large equality.
        $this->assertEqualDiffs(["+ ", "=a", "+nd", "= [[Pennsylvania]]", "- and [[New"], $dmp->diff_main('a [[Pennsylvania]] and [[New', ' and [[Pennsylvania]]', false));

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
        $this->assertEqualDiffs($dmp->diff_main($a, $b, false), $dmp->diff_main($a, $b, true));

        // Single line-mode.
        $a = '1234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890';
        $b = 'abcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghijabcdefghij';
        $this->assertEqualDiffs($dmp->diff_main($a, $b, false), $dmp->diff_main($a, $b, true));

        // Overlap line-mode.
        $a = "1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n1234567890\n";
        $b = "abcdefghij\n1234567890\n1234567890\n1234567890\nabcdefghij\n1234567890\n1234567890\n1234567890\nabcdefghij\n1234567890\n1234567890\n1234567890\nabcdefghij\n";
        $this->assertEquals($dmp->diff_text1($dmp->diff_main($a, $b, false)),
                     $dmp->diff_text1($dmp->diff_main($a, $b, true)));
        $this->assertEquals($dmp->diff_text2($dmp->diff_main($a, $b, false)),
                     $dmp->diff_text2($dmp->diff_main($a, $b, true)));

        // Test null inputs.
        // XXX This is a change from other language behavior.
        try {
            $diffs = $dmp->diff_main(null, null);
            $this->assertEquals([], $diffs);
        } catch (\TypeError $e) {
            xassert(false);
        }

        $this->assertEquals("+hello", (string) (new dmp\diff_obj(dmp\DIFF_INSERT, "hello")));
    }

    function testUTF16Strlen() {
        $this->assertEquals(5, diff_match_patch::utf16strlen("abcde"));
        $this->assertEquals(5, diff_match_patch::utf16strlen("abcdé"));
        $this->assertEquals(5, diff_match_patch::utf16strlen("abcdࠀ"));
        $this->assertEquals(6, diff_match_patch::utf16strlen("abcdࠀࠀ"));
        $this->assertEquals(8, diff_match_patch::utf16strlen("abcdࠀࠀ😀"));
    }

    function testDiffUTF8() {
        $dmp = new diff_match_patch;

        $diffs = $dmp->diff("Hello this is a tést of Unicode", "Hello this is a tèst of Unicode");
        $this->assertEquals('["=Hello this is a t","-é","+è","=st of Unicode"]', json_encode($diffs, JSON_UNESCAPED_UNICODE));

        $diffs = $dmp->diff("πanother test", "Ȁanother test");
        $this->assertEquals('["-π","+Ȁ","=another test"]', json_encode($diffs, JSON_UNESCAPED_UNICODE));

        $dmp->Fix_UTF8 = false;
        $diffs = $dmp->diff("\xe0\xa0\x96", "\xe1\xa0\x97");
        $this->assertEquals('["X-e0","X+e1","X=a0","X-96","X+97"]', json_encode($diffs));

        $dmp->Fix_UTF8 = true;
        $diffs = $dmp->diff("\xe0\xa0\x96", "\xe1\xa0\x97");
        $this->assertEquals("[\"-\xe0\xa0\x96\",\"+\xe1\xa0\x97\"]", json_encode($diffs, JSON_UNESCAPED_UNICODE));
    }

    function test_1500_random_constructions() {
        if (!is_readable("/usr/share/dict/words")) {
            return;
        }

        $words = preg_split('/\s+/', file_get_contents("/usr/share/dict/words"));
        $nwords = count($words);
        $differ = new diff_match_patch;
        $time = 0.0;

        for ($nt = 0; $nt !== 1500; ++$nt) {
            $nw = mt_rand(10, 2000);
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

            $tm0 = microtime(true);
            $diff = $differ->diff($t0, $t1);
            $time += microtime(true) - $tm0;
            $o0 = $differ->diff_text1($diff);
            xassert_eqq($t0, $o0);
            if ($o0 !== $t0) {
                fwrite(STDERR, "\n#{$nt}\n");
                fwrite(STDERR, "=== NO:\n$t0\n=== GOT:\n$o0\n");
                $c1 = $differ->diff_commonPrefix($t0, $o0);
                fwrite(STDERR, "=== NEAR {$c1}:\n" . substr($t0, $c1, 100) . "\n=== GOT:\n" . substr($o0, $c1, 100) . "\n");
                assert(false);
            }
            $o1 = $differ->diff_text2($diff);
            xassert_eqq($t1, $o1);
            if ($o1 !== $t1) {
                fwrite(STDERR, "\n#{$nt}\n");
                fwrite(STDERR, "=== NO OUT:\n$t1\n=== GOT:\n$o1\n");
                $c1 = $differ->diff_commonPrefix($t1, $o1);
                fwrite(STDERR, "=== NEAR {$c1} OUT:\n" . substr($t1, $c1, 100) . "\n=== GOT:\n" . substr($o1, $c1, 100) . "\n");
                assert(false);
            }
        }
    }

    function test_line_diffs() {
        $dmp = new diff_match_patch;
        $dmp->Line_Histogram = true;
        $t1 = "Hello.\nThis is a test of line diffs.\n";
        $t2 = "Goodbye.\nThere is nothing to be done.\n";

        xassert_eqq("@@ -1,2 +1,2 @@\n-Hello.\n-This is a test of line diffs.\n+Goodbye.\n+There is nothing to be done.\n", $dmp->line_diff_toUnified($dmp->line_diff($t1, $t2)));

        $text1 = '<?php
// diff_match_patch.php -- PHP diff-match-patch.
// Copyright 2018 The diff-match-patch Authors.
// Copyright (c) 2006-2022 Eddie Kohler.
// Ported with some changes from Neil Fraser\'s diff-match-patch:
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

    /** @var 0|1
     * $iota === 1 if we are doing a line diff, so the unit is 2 bytes */
    private $iota = 0;

    /** @param string $text1
     * @param string $text2
     * @param ?bool $checklines
     * @param ?float $deadline
     * @return list<diff_obj> */
    function diff($text1, $text2, $checklines = null, $deadline = null) {
        return $this->diff_main($text1, $text2, $checklines, $deadline);
    }

    /** @param string $text1 Old string to be diffed.
     * @param string $text2 New string to be diffed.
     * @param ?bool $checklines
     * @param ?float $deadline
     * @return list<diff_obj> */
    function diff_main($text1, $text2, $checklines = null, $deadline = null) {
        $text1 = (string) $text1;
        $text2 = (string) $text2;
';
 
    $text2 = '<?php
// diff_match_patch.php -- PHP diff-match-patch.
// Copyright 2018 The diff-match-patch Authors.
// Copyright (c) 2006-2022 Eddie Kohler.
// Ported with some changes from Neil Fraser\'s diff-match-patch:
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
';
 
        $diff = '@@ -29,18 +29,30 @@
 
     /** @param string $text1
      * @param string $text2
-     * @param ?bool $checklines
+     * @param bool $checklines
      * @param ?float $deadline
      * @return list<diff_obj> */
-    function diff($text1, $text2, $checklines = null, $deadline = null) {
+    function diff($text1, $text2, $checklines = true, $deadline = null) {
         return $this->diff_main($text1, $text2, $checklines, $deadline);
     }
 
+    /** @param ?float $deadline
+     * @return float */
+    private function compute_deadline_($deadline) {
+        if ($deadline !== null) {
+            return $deadline;
+        } else if ($this->Diff_Timeout <= 0) {
+            return INF;
+        } else {
+            return microtime(true) + $this->Diff_Timeout;
+        }
+    }
+
     /** @param string $text1 Old string to be diffed.
      * @param string $text2 New string to be diffed.
-     * @param ?bool $checklines
+     * @param bool $checklines
      * @param ?float $deadline
      * @return list<diff_obj> */
-    function diff_main($text1, $text2, $checklines = null, $deadline = null) {
+    function diff_main($text1, $text2, $checklines = true, $deadline = null) {
         $text1 = (string) $text1;
         $text2 = (string) $text2;
';

        xassert_eqq($diff, $dmp->line_diff_toUnified($dmp->line_diff($text1, $text2)));

        $text1 .= "a\na\na\na\na\na\na\na\na\na\na\na\nn\n";
        $text2 .= "a\na\na\na\na\na\na\na\na\na\na\na\no\n";
        $diff = str_replace("@@ -29,18 +29,30 @@", "@@ -29,19 +29,31 @@", $diff)
            . ' a
@@ -56,4 +68,4 @@
 a
 a
 a
-n
+o
';

        xassert_eqq($diff, $dmp->line_diff_toUnified($dmp->line_diff($text1, $text2)));


        $text1 = 'void func1() {
    x += 1
}

void func2() {
    x += 2
}
';

        $text2 = 'void func1() {
    x += 1
}

void functhreehalves() {
    x += 1.5
}

void func2() {
    x += 2
}
';

        $diff = '@@ -2,6 +2,10 @@
     x += 1
 }
 
+void functhreehalves() {
+    x += 1.5
+}
+
 void func2() {
     x += 2
 }
';

        xassert_eqq($diff, $dmp->line_diff_toUnified($dmp->line_diff($text1, $text2)));


        $dmp->Line_Histogram = true;
        $text1 = 'public class File1 {

  public int add (int a, int b)
  {
    log();
    return a + b;
  }

  public int sub (int a, int b)
  {
    if (a == b)
    {
        return 0;
    }
    log();
    return a - b;
    // TOOD: JIRA1234
  }

}
';

        $text2 = 'public class File1 {

  public int sub (int a, int b)
  {
    // TOOD: JIRA1234
    if ( isNull(a, b) )
    {
        return null
    }
    log();
    return a - b;
  }

  public int mul (int a, int b)
  {
    if ( isNull(a, b) )
    {
        return null;
    }
    log();
    return a * b;
  }

}
';

        $diff = '@@ -1,20 +1,24 @@
 public class File1 {
 
-  public int add (int a, int b)
-  {
-    log();
-    return a + b;
-  }
-
   public int sub (int a, int b)
   {
-    if (a == b)
+    // TOOD: JIRA1234
+    if ( isNull(a, b) )
     {
-        return 0;
+        return null
     }
     log();
     return a - b;
-    // TOOD: JIRA1234
   }
 
+  public int mul (int a, int b)
+  {
+    if ( isNull(a, b) )
+    {
+        return null;
+    }
+    log();
+    return a * b;
+  }
+
 }
';

        xassert_eqq($diff, $dmp->line_diff_toUnified($dmp->line_diff($text1, $text2)));
    }
}
