<?php
//
// Generates a GIF image of a bar chat.
// Arguments are passed in as v[0]...v[n]
//

$blockHeight = 8;
$blockWidth = 8; // Was 16

$blockSkip = 2; // along vertical
$blockPad = 4; // pad from left/right side

$valMin = 10000;
$valMax = -1;
$values = array();
$maxY = 5;

if (!IsSet($_REQUEST[v])) {
  exit;
} else {
  foreach ($_REQUEST[v] as $key => $value) {
    $values[$key] = $value;
    $valMin = min($key, $valMin);
    $valMax = max($key, $valMax);
    $maxY = max($value, $maxY);
  }
}

$valWidth = $valMax - $valMin + 1;

$textWidth=12;

$picWidth=($blockWidth + $blockPad ) * $valWidth
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

for ($value = $valMin; $value <= $valMax; $value++) {
  //
  // Set fill color for rectangles...
  //
  $frac = (255.0 * ($value-$valMin));
  $frac = $frac / ($valMax - $valMin + 1);

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


if (function_exists("imagejpeg")) {
  Header("Content-type: image/jpeg");
  ImageJPEG($pic, "", 0.5);
}
elseif (function_exists("imagepng")) {
  Header("Content-type: image/png");
  ImagePNG($pic);
}
elseif (function_exists("imagewbmp")) {
  Header("Content-type: image/vnd.wap.wbmp");
  ImageWBMP($pic);
}
else {
  die("No image support in this PHP server");
}

?>

