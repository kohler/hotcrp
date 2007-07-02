<?php
// GenChart.php -- HotCRP chart generator
// HotCRP is Copyright (c) 2006-2007 Eddie Kohler and Regents of the UC
// This file is less changed than usual from Dirk Grunwald's version
// Distributed under an MIT-like license; see LICENSE

// Generates a PNG image of a bar chat.
// Arguments are passed in as v; s is graph style.
// Don't forget to change the width and height calculations in
// Conference::textValuesGraph if you change the width and height here.

if (!isset($_REQUEST["v"]))
    exit;

// parse values
$s = (isset($_REQUEST["s"]) ? $_REQUEST["s"] : 0);
if (isset($_REQUEST["first"]) && is_numeric($_REQUEST["first"]))
    $valMin = $valMax = intval($_REQUEST["first"]);
else
    $valMin = $valMax = 1;
$values = array();
$maxY = $sum = 0;
foreach (explode(",", $_REQUEST["v"]) as $value) {
    $value = (ctype_digit($value) && $value > 0 ? intval($value) : 0);
    $values[$valMax++] = $value;
    $maxY = max($value, $maxY);
    $sum += $value;
}

// set shape constants
if ($s == 0) {
    list($blockHeight, $blockWidth, $blockSkip, $blockPad, $textWidth)
	= array(8, 8, 2, 4, 12);
} else if ($s == 1) {
    list($blockHeight, $blockWidth, $blockSkip, $blockPad, $textWidth)
	= array(3, 3, 2, 2, 0);
}

if ($s == 0 || $s == 1) {
    $maxY = max($maxY, 3);
    $picWidth = ($blockWidth + $blockPad) * ($valMax - $valMin)
	+ $blockPad
	+ 2 * $textWidth;
    $picHeight = $blockHeight * $maxY + $blockSkip * ($maxY + 1);
    $pic = @imagecreate($picWidth + 1, $picHeight + 1);

} else if ($s == 2) {
    $picWidth = 64;
    $picHeight = 8;
    $pic = @imagecreate($picWidth, $picHeight);
}

$cWhite = imagecolorallocate($pic, 255, 255, 255);
$cBlack = imagecolorallocate($pic, 0, 0, 0);
$cgrey = imagecolorallocate($pic, 190, 190, 255);

if ($s == 0) {
    imagefilledrectangle($pic, 0, 0, $picWidth + 1, $picHeight + 1, $cBlack);
    imagefilledrectangle($pic, 1, 1, $picWidth - 1, $picHeight - 1, $cWhite);
} else if ($s == 1) {
    imagecolortransparent($pic, $cWhite);
    imagefilledrectangle($pic, 0, $picHeight, $picWidth + 1, $picHeight + 1, $cgrey);
    imagefilledrectangle($pic, 0, $picHeight - $blockHeight - $blockPad, 0, $picHeight + 1, $cgrey);
    imagefilledrectangle($pic, $picWidth, $picHeight - $blockHeight - $blockPad, $picWidth + 1, $picHeight + 1, $cgrey);
}

$cbad = array(200, 128, 128);
$cgood = array(0, 240, 0);

$pos = 0;

for ($value = $valMin; $value < $valMax; $value++) {
    $height = $values[$value];
    $frac = ($value - $valMin) / ($valMax - $valMin);
    $cFill = imagecolorallocate($pic,
				$cgood[0] * $frac + $cbad[0] * (1 - $frac),
				$cgood[1] * $frac + $cbad[1] * (1 - $frac),
				$cgood[2] * $frac + $cbad[2] * (1 - $frac));

    if ($s == 0 || $s == 1) {
	
	$curX = $blockWidth * ($value - $valMin)
	    + $blockPad * ($value - $valMin + 1) + $textWidth;
	$curY = $picHeight - $blockSkip;

	for ($h = 1; $h <= $height; $h++) {
	    imagefilledrectangle($pic, $curX, $curY - $blockHeight,
				 $curX + $blockWidth, $curY, $cFill);
	    $curY -= ($blockHeight + $blockSkip);
	}
    } else {
	if ($height > 0)
	    imagefilledrectangle($pic, ($picWidth + 1) * $pos / $sum, 0,
				 ($picWidth + 1) * ($pos + $height) / $sum - 2, $picHeight,
				 $cFill);
	$pos += $height;
    }
}

if ($s == 0) {
    imagestringup($pic, 2, 0, 30, "Bad", $cBlack);
    imagestringup($pic, 2, $picWidth-$textWidth, 30, "Good", $cBlack);
 } else if ($s == 1) {
    if ($values[$valMin] == 0)
	imagestring($pic, 1, $textWidth + $blockPad, $picHeight - $blockHeight - $blockSkip - 3, "L", $cgrey);
    if ($values[$valMax - 1] == 0)
	imagestring($pic, 1, $picWidth - $blockWidth - $textWidth - $blockPad, $picHeight - $blockHeight - $blockSkip - 3, "H", $cgrey);
}

header("Cache-Control: public, max-age=31557600");
header("Expires: " . gmdate("D, d M Y H:i:s", time() + 31557600) . " GMT");
header("Pragma: "); // don't know where the pragma is coming from; oh well
header("Content-Type: image/png");
imagepng($pic);
exit();
