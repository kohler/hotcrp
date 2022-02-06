<?php
// help/h_scoresort.php -- HotCRP help functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class ScoreSort_HelpTopic {
    static function print(HelpRenderer $hth) {
        echo "
<p>Some paper search results include columns with score graphs. Click on a score
column heading to sort the paper list using that score. Search &gt; View
options changes how scores are sorted.  There are five choices:</p>

<dl>

<dt><strong>Counts</strong> (default)</dt>

<dd>Sort by the number of highest scores, then the number of second-highest
scores, then the number of third-highest scores, and so on.  To sort a paper
with fewer reviews than others, HotCRP adds phantom reviews with scores just
below the paper’s lowest real score.  Also known as Minshall score.</dd>

<dt><strong>Average</strong></dt>
<dd>Sort by the average (mean) score.</dd>

<dt><strong>Median</strong></dt>
<dd>Sort by the median score.</dd>

<dt><strong>Variance</strong></dt>
<dd>Sort by the variance in scores.</dd>

<dt><strong>Max &minus; min</strong></dt>
<dd>Sort by the difference between the largest and smallest scores (a good
measure of differences of opinion).</dd>

<dt><strong>My score</strong></dt>
<dd>Sort by your score.  In the score graphs, your score is highlighted with a
darker colored square.</dd>

</dl>";
    }
}
