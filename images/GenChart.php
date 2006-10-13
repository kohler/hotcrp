<?php
//
// Generates a GIF image of a bar chat.
// Arguments are passed in as v
//

if (!isset($_REQUEST["v"]))
    exit;

$s = (isset($_REQUEST["s"]) ? $_REQUEST["s"] : 0);

$blockHeight = ($s == 1 ? 3 : 8);
$blockWidth = ($s == 1 ? 3 : 8); // Was 16
$blockSkip = 2; // along vertical
$blockPad = ($s == 1 ? 2 : 4); // pad from left/right side
$textWidth = ($s == 1 ? 0 : 12);

if (isset($_REQUEST["first"]) && is_numeric($_REQUEST["first"]))
    $valMin = $valMax = intval($_REQUEST["first"]);
else
    $valMin = $valMax = 1;
$values = array();
$maxY = 3;
foreach (explode(",", $_REQUEST["v"]) as $value) {
    $value = (is_numeric($value) && $value > 0 ? intval($value) : 0);
    $values[$valMax++] = $value;
    $maxY = max($value, $maxY);
}

$picWidth = ($blockWidth + $blockPad) * ($valMax - $valMin)
    + $blockPad
    + 2 * $textWidth;

$picHeight=$blockHeight * $maxY + $blockSkip * ($maxY+1);

$pic=ImageCreate($picWidth+1,$picHeight+1);

//
// White background, black outline
//
$cWhite = imagecolorallocate($pic,255,255,255);
$cBlack = imagecolorallocate($pic,0,0,0);
$cgrey = imagecolorallocate($pic, 190, 190, 255);

if ($s == 0) {
    ImageFilledRectangle($pic,0,0,$picWidth+1,$picHeight+1,$cBlack);
    ImageFilledRectangle($pic,1,1,$picWidth-1,$picHeight-1,$cWhite);
} else {
    imagecolortransparent($pic, $cWhite);
    imagefilledrectangle($pic, 0, $picHeight, $picWidth + 1, $picHeight + 1, $cgrey);
    imagefilledrectangle($pic, 0, $picHeight - $blockHeight - $blockPad, 0, $picHeight + 1, $cgrey);
    imagefilledrectangle($pic, $picWidth, $picHeight - $blockHeight - $blockPad, $picWidth + 1, $picHeight + 1, $cgrey);
}

$cbad = array(200, 128, 128);
$cgood = array(0, 240, 0);

for ($value = $valMin; $value < $valMax; $value++) {
    // Set fill color for rectangles...
    $frac = ($value - $valMin) / ($valMax - $valMin);

    //  print "frac is $frac\n";
    $cFill=ImageColorAllocate($pic, $cgood[0] * $frac + $cbad[0] * (1 - $frac),
	$cgood[1] * $frac + $cbad[1] * (1 - $frac),
	$cgood[2] * $frac + $cbad[2] * (1 - $frac));

  $height=$values[$value];

   // was:  $curX=$blockWidth*($value-1) + $blockPad * ($value)
   // the above assumes $valMin will always be 1 -- ratul
   $curX=$blockWidth*($value-$valMin) + $blockPad * ($value - $valMin + 1)
    + $textWidth;
  $curY=$picHeight - $blockSkip;

  for ($h = 1; $h <= $height; $h++) {
    //    print "Do $value/$h - curx is $curX, $curY\n";
    $i = ImageFilledRectangle($pic,
			      $curX, $curY - $blockHeight,
			      $curX + $blockWidth,
			      $curY,
			      $cFill);
    $curY -= ($blockHeight + $blockSkip);
  }

}

if ($s == 0) {
    ImageStringUp($pic, 2, 0, 40, "Bad", $cBlack);
    ImageStringUp($pic, 2, $picWidth-$textWidth, 40, "Good", $cBlack);
} else {
    if ($values[$valMin] == 0)
	imagestring($pic, 1, $textWidth + $blockPad, $picHeight - $blockHeight - $blockSkip - 3, "L", $cgrey);
    if ($values[$valMax - 1] == 0)
	imagestring($pic, 1, $picWidth - $blockWidth - $textWidth - $blockPad, $picHeight - $blockHeight - $blockSkip - 3, "H", $cgrey);
}

Header("Content-type: image/png");
Header("Cache-Control: public");
ImagePNG($pic);
exit();
