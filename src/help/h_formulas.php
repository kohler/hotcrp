<?php
// help/h_formulas.php -- HotCRP help functions
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Formulas_HelpTopic {
    static function print(HelpRenderer $hth) {
        echo "<p>Program committee members and administrators can search and display <em>formulas</em>
that calculate properties of paper scores&mdash;for instance, the
standard deviation of papers’ Overall merit scores, or average Overall
merit among reviewers with high Reviewer expertise.</p>

<p>To display a formula, use a search term such as “",
$hth->search_link("show:var(OveMer)"), "” (show
the variance in Overall merit scores, along with statistics for all papers).
You can also ", $hth->hotlink("graph formulas", "graph", "group=formula"), ".
To search for a formula, use a search term such as “",
$hth->search_link("formula:var(OveMer)>0.5"), "”
(select papers with variance in Overall merit greater than 0.5).
Or save formulas using ",
$hth->search_link("Search &gt; View options", ["q" => "", "#" => "view"]),
" &gt; Edit formulas</a>.</p>

<p>Formulas use a familiar expression language.
For example, this computes the sum of the squares of the overall merit scores:</p>

<blockquote>sum(OveMer**2)</blockquote>

<p>This calculates an average of overall merit scores, weighted by expertise
(high-expertise reviews are given slightly more weight):</p>

<blockquote>wavg(OveMer, RevExp >= 4 ? 1 : 0.8)</blockquote>

<p>And there are many variations. This version gives more weight to PC
reviewers with the “#heavy” tag:</p>

<blockquote>wavg(OveMer, re:#heavy + 1)</blockquote>

<p>(“re:#heavy + 1” equals 2 for #heavy reviews and 1 for others.)</p>

<p>Formulas work better for numeric scores, but you can use them for letter
scores too. HotCRP uses alphabetical order for letter scores, so the “min” of
scores A, B, and D is A. For instance:</p>

<blockquote>count(confidence=X)</blockquote>";

        echo $hth->subhead("Expressions");
        echo "<p>Formula expressions are built from the following parts:</p>";
        echo $hth->table();
        echo $hth->tgroup("Arithmetic");
        echo $hth->trow("2", "Numbers");
        echo $hth->trow("true, false", "Booleans");
        echo $hth->trow("null", "The null value");
        echo $hth->trow("(<em>e</em>)", "Parentheses");
        echo $hth->trow("<em>e</em> + <em>e</em>, <em>e</em> - <em>e</em>", "Addition, subtraction");
        echo $hth->trow("<em>e</em> * <em>e</em>, <em>e</em> / <em>e</em>, <em>e</em> % <em>e</em>", "Multiplication, division, remainder");
        echo $hth->trow("<em>e</em> ** <em>e</em>", "Exponentiation");
        echo $hth->trow("<em>e</em> == <em>e</em>, <em>e</em> != <em>e</em>,<br /><em>e</em> &lt; <em>e</em>, <em>e</em> &gt; <em>e</em>, <em>e</em> &lt;= <em>e</em>, <em>e</em> &gt;= <em>e</em>", "Comparisons");
        echo $hth->trow("!<em>e</em>", "Logical not");
        echo $hth->trow("<em>e1</em> &amp;&amp; <em>e2</em>", "Logical and (returns <em>e1</em> if <em>e1</em> is false, otherwise returns <em>e2</em>)");
        echo $hth->trow("<em>e1</em> || <em>e2</em>", "Logical or (returns <em>e1</em> if <em>e1</em> is true, otherwise returns <em>e2</em>)");
        echo $hth->trow("<em>test</em> ? <em>iftrue</em> : <em>iffalse</em>", "If-then-else operator");
        echo $hth->trow("let <em>var</em> = <em>val</em> in <em>e</em>", "Local variable definition");
        echo $hth->trow("greatest(<em>e</em>, <em>e</em>, ...)", "Maximum");
        echo $hth->trow("least(<em>e</em>, <em>e</em>, ...)", "Minimum");
        echo $hth->trow("coalesce(<em>e</em>, <em>e</em>, ...)", "Null coalescing: return first of <em>e</em>s that is not null");
        echo $hth->trow("log(<em>e</em>)", "Natural logarithm");
        echo $hth->trow("log(<em>e</em>, <em>b</em>)", "Log to the base <em>b</em>");
        echo $hth->trow("round(<em>e</em>[, <em>m</em>])", "Round to the nearest multiple of <em>m</em>");
        echo $hth->tgroup("Submission properties");
        echo $hth->trow("pid", "Paper ID");
        echo $hth->trow("au", "Number of authors");
        echo $hth->trow("au:pc", "Number of PC authors");
        echo $hth->trow("au:<em>text</em>", "Number of authors matching <em>text</em>");
        if ($hth->conf->has_topics()) {
            echo $hth->trow("topics", "Number of topics");
            echo $hth->trow("topics:<em>text</em>", "Number of topics matching <em>text</em>");
        }
        echo $hth->tgroup("Tags");
        echo $hth->trow("#<em>tagname</em>", "True if this paper has tag <em>tagname</em>");
        echo $hth->trow("tagval:<em>tagname</em>", "The value of tag <em>tagname</em>, or null if this paper doesn’t have that tag");
        echo $hth->tgroup("Scores");
        echo $hth->trow("overall-merit", "This review’s Overall merit score<div class=\"hint\">Only completed reviews are considered.</div>");
        echo $hth->trow("OveMer", "Abbreviations also accepted");
        echo $hth->trow("OveMer:external", "Overall merit for external reviews, null for other reviews");
        echo $hth->trow("OveMer:R2", "Overall merit for round R2 reviews, null for other reviews");
        echo $hth->tgroup("Submitted reviews");
        echo $hth->trow("re:type", "Review type");
        echo $hth->trow("re:round", "Review round");
        echo $hth->trow("re:auwords", "Review word count (author-visible fields only)");
        echo $hth->trow("re:primary", "True for primary reviews");
        echo $hth->trow("re:secondary", "True for secondary reviews");
        echo $hth->trow("re:external", "True for external reviews");
        echo $hth->trow("re:pc", "True for PC reviews");
        echo $hth->trow("re:sylvia", "True if reviewer matches “sylvia”");
        if (($retag = $hth->meaningful_pc_tag())) {
            echo $hth->trow("re:#$retag", "True if reviewer has tag “#{$retag}”");
        }
        echo $hth->tgroup("Review preferences");
        echo $hth->trow("pref", "Review preference");
        echo $hth->trow("prefexp", "Predicted expertise");
        echo $hth->end_table();

        echo $hth->subhead("Aggregate functions");
        echo "<p>Aggregate functions calculate a
value based on all of a paper’s reviews and/or review preferences.
For instance, “max(OveMer)” would return the maximum Overall merit score
assigned to a paper.</p>

<p>An aggregate function’s argument is calculated once per viewable review
or preference.
For instance, “max(OveMer/RevExp)” calculates the maximum value of
“OveMer/RevExp” for any review, whereas
“max(OveMer)/max(RevExp)” divides the maximum overall merit by the
maximum reviewer expertise.</p>

<p>The top-level value of a formula expression cannot be a raw review score
or preference.
Use an aggregate function to calculate a property over all review scores.</p>";
        echo $hth->table();
        echo $hth->tgroup("Aggregates");
        echo $hth->trow("max(<em>e</em>), min(<em>e</em>)", "Maximum, minimum");
        echo $hth->trow("count(<em>e</em>)", "Number of reviews where <em>e</em> is not null or false");
        echo $hth->trow("sum(<em>e</em>)", "Sum");
        echo $hth->trow("avg(<em>e</em>)", "Average (mean)");
        echo $hth->trow("wavg(<em>e</em>, <em>weight</em>)", "Weighted average; equals “sum(<em>e</em> * <em>weight</em>) / sum(<em>weight</em>)”");
        echo $hth->trow("median(<em>e</em>)", "Median");
        echo $hth->trow("quantile(<em>e</em>, <em>p</em>)", "Quantile; 0≤<em>p</em>≤1; 0 yields min, 0.5 median, 1 max");
        echo $hth->trow("stddev(<em>e</em>)", "Population standard deviation");
        echo $hth->trow("var(<em>e</em>)", "Population variance");
        echo $hth->trow("stddev_samp(<em>e</em>), var_samp(<em>e</em>)", "Sample standard deviation, sample variance");
        echo $hth->trow("any(<em>e</em>)", "True if any of the reviews have <em>e</em> true");
        echo $hth->trow("all(<em>e</em>)", "True if all of the reviews have <em>e</em> true");
        echo $hth->trow("argmin(<em>x</em>, <em>e</em>)", "Value of <em>x</em> when <em>e</em> is minimized");
        echo $hth->trow("argmax(<em>x</em>, <em>e</em>)", "Value of <em>x</em> when <em>e</em> is maximized");
        echo $hth->trow("my(<em>e</em>)", "Calculate <em>e</em> for your review");
        echo $hth->end_table();
    }
}
