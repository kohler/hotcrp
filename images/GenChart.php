<?php
//
// Generates a GIF image of a bar chat.
// Arguments are passed in as v
//

$blockHeight = 8;
$blockWidth = 8; // Was 16

$blockSkip = 2; // along vertical
$blockPad = 4; // pad from left/right side

if (!isset($_REQUEST["v"]))
    exit;

if (isset($_REQUEST["first"]) && is_numeric($_REQUEST["first"]))
    $valMin = $valMax = intval($_REQUEST["first"]);
else
    $valMin = $valMax = 1;
$values = array();
$maxY = 5;
foreach (explode(",", $_REQUEST["v"]) as $value) {
    $value = (is_numeric($value) && $value > 0 ? intval($value) : 0);
    $values[$valMax++] = $value;
    $maxY = max($value, $maxY);
}

$textWidth = 12;

$picWidth = ($blockWidth + $blockPad) * ($valMax - $valMin)
    + $blockPad
    + 2 * $textWidth;

$picHeight=$blockHeight * $maxY + $blockSkip * ($maxY+1);

$pic=ImageCreate($picWidth+1,$picHeight+1);

//
// White background, black outline
//
$cWhite=ImageColorAllocate($pic,255,255,255);
$cBlack=ImageColorAllocate($pic,0,0,0);

ImageFilledRectangle($pic,0,0,$picWidth+1,$picHeight+1,$cBlack);
ImageFilledRectangle($pic,1,1,$picWidth-1,$picHeight-1,$cWhite);

for ($value = $valMin; $value < $valMax; $value++) {
  //
  // Set fill color for rectangles...
  //
  $frac = (255.0 * ($value-$valMin));
  $frac = $frac / ($valMax - $valMin);

  //  print "frac is $frac\n";
  $cFill=ImageColorAllocate($pic,255-$frac, 0, $frac);

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

ImageStringUp($pic, 2, 0, 40, "Bad", $cBlack);
ImageStringUp($pic, 2, $picWidth-$textWidth, 40, "Good", $cBlack);

Header("Content-type: image/png");
Header("Cache-Control: public");
ImagePNG($pic);
exit();
