<?php
//
// generate a GIF impage of a bar chart.
// The values specified are in the format: v[0]=(c1,c2,c3),v[1]=(d1,d2),
// where the 'c' and 'd' values numeric values representing a color
// range.
//

$blockHeight = 8;
$blockWidth = 16;

$blockSkip = 2; // along vertical
$blockPad = 4; // pad from left/right side

$valMin = 10000;
$valMax = -1;
$values = array();
$maxY = 5;

if (!IsSet($maxValueList)) {
  $maxValueList = 0;
}


if (!IsSet($minValueList)) {
  $minValueList = 1000;
}

if (!IsSet($_REQUEST[v])) {
  exit;
} else {
  foreach ($_REQUEST[v] as $key => $value) {
    if ( preg_match("/\((.+)\)/", $value, $matches ) ) {
      //
      // It's a value list. Extract individual values
      //
      $valuelist = $matches[1];
      $nums = preg_split("/ *[,\$]/", $valuelist);
      rsort($nums);
      $values[$key] = $nums;
      $scalar_value = count($nums);
      foreach ($nums as $i => $j) {
	if ($j > $maxValueList ) {
	  $maxValueList = $j;
	}
	if ($j < $minValueList ) {
	  $minValueList = $j;
	}
      }
    } else {
      $values[$key] = $value;
      $scalar_value = $value;
    }
    $valMin = min($key, $valMin);
    $valMax = max($key, $valMax);
    $maxY = max($scalar_value, $maxY);
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
  // This is gross
  //
  $curX=$blockWidth*($value-1) + $blockPad * ($value)
    + $textWidth;
  $curY=$picHeight - $blockSkip;

  if ( gettype($values[$value]) == "integer" ) {
    $height=$values[$value];

    //
    // Set fill color for rectangles...
    //
    $frac = (255.0 * ($value-$valMin));
    $frac = $frac / ($valMax - $valMin + 1);
    $cFill=ImageColorAllocate($pic,255-$frac, 0, $frac);

    for ($h = 1; $h <= $height; $h++) {
      //    print "Do $value/$h - curx is $curX, $curY\n";
      $i = ImageFilledRectangle($pic,
				$curX, $curY - $blockHeight,
				$curX + $blockWidth,
				$curY,
				$cFill);
      $curY -= ($blockHeight + $blockSkip);
    }
  } else if (gettype($values[$value]) == "array") {
    $avals = $values[$value];
    $height=count($avals);

    //
    // Determine a fill color -- range of 0..255
    //

    //    print "it is array, avals are\n";
    //    print_r($avals);
    for ($el =0; $el < count($avals); $el++) {
      //    print "Do $value/$h - curx is $curX, $curY\n";
      $thisvalue = $avals[$el];
      $frac = (255.0 * ($thisvalue-$minValueList));
      $frac = $frac / ($maxValueList - $minValueList + 1);
      //      print "<br> thisvalue is $thisvalue, min is $minValueList, max is $maxValueList, frac is $frac\n";
      $cFill=ImageColorAllocate($pic,255-$frac, 0, $frac);
      $i = ImageFilledRectangle($pic,
				$curX, $curY - $blockHeight,
				$curX + $blockWidth,
				$curY,
				$cFill);
      $curY -= ($blockHeight + $blockSkip);
    }

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

