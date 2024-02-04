<?php
// help/h_chairsguide.php -- HotCRP help functions
// Copyright (c) 2006-2024 Eddie Kohler; see LICENSE.

class ChairsGuide_HelpTopic {
    static function print_presubmission(HelpRenderer $hth, $gj) {
        if ($gj->itemid === -1) {
            echo $hth->subhead("Submission time");
            echo "<p>Follow these steps to prepare to accept submissions.</p>\n\n<ol>\n";
            $hth->print_members("chair/presubmission");
            echo "</ol>\n\n";

        } else if ($gj->itemid === 1) {
            echo "<li><p><strong>",
                $hth->setting_group_link("Set up PC member accounts", "users"),
                "</strong>. Many PCs are divided into classes, such as
  “heavy” and “light”, or “PC” and “ERC”. Mark these classes with user tags.
  It’s also useful to configure ", $hth->setting_link("tag colors", "tag_style"),
  " so that PC member names are displayed
  differently based on class (for instance, heavy PC member names might appear
  in <b>bold</b>).</p></li>\n";

        } else if ($gj->itemid === 2) {
            echo "<li><p><strong>", $hth->setting_group_link("Set submission policies", "sub"),
  "</strong>, including anonymity.</p></li>\n";

        } else if ($gj->itemid === 3) {
            echo "<li><p><strong>", $hth->setting_group_link("Set submission deadlines.", "sub"),
  "</strong> Authors first <em>register</em>, then <em>submit</em>
  their work, possibly multiple times; they choose for each submitted
  version whether that version is ready for review.  Normally, HotCRP allows
  authors to update submissions until the deadline, but you can also require
  that authors “freeze” each submission when it is complete.
  Only the submission deadline really matters,
  but HotCRP also supports a separate registration deadline, after which
  new submissions cannot be started. An
  optional <em>grace period</em> applies to both deadlines:
  HotCRP reports the set deadlines, but allows updates post-deadline
  for the specified time.</p></li>\n";

        } else if ($gj->itemid === 4) {
            echo "<li><p><strong>", $hth->setting_group_link("Set up the submission form", "subform"),
"</strong>, including whether abstracts are required,
whether authors check off conflicted PC members (“Collect authors’ PC
conflicts with checkboxes”), and whether authors must enter additional
non-PC collaborators, which can help detect conflicts with external
reviewers (“Collect authors’ other collaborators as text”). The submission
form also can include:</p>

  <ul>

  <li><p><strong>PDF format checker.</strong> This adds a “Check format” link
  to the Edit screen. Clicking the link checks the submission for formatting
  errors, such as going over the page limit.  Submissions with formatting errors
  may still be submitted, since the checker itself can make mistakes, but the
  automated checker leaves cheating authors no excuse.</p></li>

  <li><p><strong>Additional fields</strong> such as checkboxes, selectors, freeform
  text, and uploaded attachments. Checkbox fields might include “Consider
  this paper for the Best Student Paper award” or “Provide this paper to the
  European shadow PC.” Attachment fields might include supplemental material.
  You can search for submissions with or without each field.</p></li>

  <li><p><strong>Topics.</strong> Authors can select topics, such as
  “Applications” or “Network databases,” that characterize their submission’s
  subject areas.  PC members express topics for which they have high, medium,
  and low interest, improving automatic review assignment.  Although explicit
  preferences (see below) are better than topic-based assignments, busy PC
  members might not specify their preferences; topic matching lets you do a
  reasonable job at assigning reviews anyway.</p></li>

  </ul></li>\n";

        } else if ($gj->itemid === 5) {
            echo '<li><p>Take a look at a ', $hth->hotlink("submission page", "paper", "p=new"),
              ' to make sure it looks right.</p></li>', "\n";

        } else if ($gj->itemid === 6) {
            echo "<li><p><strong>", $hth->setting_group_link("Open the site for submissions.", "sub"),
  "</strong> Submissions are allowed up to the listed deadline.</p></li>\n";
        }
    }

    static function print_assignments(HelpRenderer $hth, $gj) {
        if ($gj->itemid === -1) {
            echo $hth->subhead("Assignments");
            echo "<p>After the submission deadline has passed:</p>\n<ol>\n";
            $hth->print_members("chair/assignments");
            echo "</ol>\n\n";

        } else if ($gj->itemid === 1) {
            echo "<li><p>Consider checking ", $hth->search_link("the papers", ["q" => "", "t" => "all"]),
  " for anomalies.  Withdraw and/or delete duplicates or update details on the ",
  $hth->hotlink("paper pages", "paper"), " (via “Edit paper”).
  Also consider contacting the authors of ",
  $hth->search_link("incomplete submissions", ["q" => "status:unsub", "t" => "all"]),
  ", especially if a PDF document was uploaded; sometimes a
  user will uncheck “The submission is ready for review” by mistake.</p></li>\n";

        } else if ($gj->itemid === 2) {
            echo "<li><p><strong>Check for formatting violations (optional).</strong> ",
            $hth->hotlink("Search", "search", "q="), "
  &gt; Download &gt; Format check will download a summary report. Serious errors
  are also shown on paper pages (problematic PDFs are distinguished by an
  “X”).</p></li>\n";

        } else if ($gj->itemid === 3) {
            echo "<li><p><strong>", $hth->setting_link("Prepare the review form.", "rf"),
  "</strong> Take a look at the templates to get ideas.</p></li>\n";

        } else if ($gj->itemid === 4) {
            echo "<li><p><strong>", $hth->setting_group_link("Set review policies and deadlines", "reviews"),
  "</strong>, including reviewing deadlines,
  review anonymity, and whether PC members may review any paper
  (usually “yes” is the right answer).</p></li>\n";

        } else if ($gj->itemid === 5) {
            echo "<li><p><strong>", $hth->setting_link("Prepare tracks (optional).", "track"),
  "</strong> Tracks give chairs fine-grained control over PC
  members’ access rights for individual papers. Example situations calling for
  tracks include external review committees, PC-paper review committees, and
  multi-track conferences.</li>\n";

        } else if ($gj->itemid === 6) {
            echo "<li><p><strong>", $hth->hotlink("Collect review preferences from the PC.", "reviewprefs"), "</strong>
  PC members can enter per-paper review preferences (also known as bids) to
  mark papers they want or don’t want to review. Preferences are integers, typically
  in the range −20 to 20; the higher the number, the more desired the review assignment.
  Users can either set their preferences ",
  $hth->hotlink("all at once", "reviewprefs"), ", or (often more
  convenient) page through the ", $hth->search_link("list of submitted papers", ""),
  " and set their preferences on the ", $hth->hotlink("paper pages", "paper"), ".</p>

  <p>If desired, review preferences can be collected before the submission
  deadline.  Select ", $hth->setting_link("“PC can see <em>all registered papers</em> until submission deadline”", "draft_submission_early_visibility"),
  ", which allows PC members to see abstracts for registered papers that haven’t yet
  been submitted.</p></li>\n";

        } else if ($gj->itemid === 7) {
            echo "<li><p><strong>", $hth->hotlink("Check for missing conflicts.", "conflictassign"), "</strong> HotCRP does not automatically confirm all conflicts, such
  as conflicts indicated by PC members’ “Collaborators and other affiliations.”
  Use ", $hth->hotlink("the conflict assignment tool", "conflictassign"), " to find and confirm
  such conflicts.</p></li>\n";

        } else if ($gj->itemid === 8) {
            echo "<li><p><strong>", $hth->hotlink("Assign reviews.", "manualassign"), "</strong> You can make assignments ", $hth->hotlink("by paper", "assign"), ", ",
  $hth->hotlink("by PC member", "manualassign"), ", ",
  $hth->hotlink("by uploading an assignment file", "bulkassign"), ", or, even easier, ",
  $hth->hotlink("automatically", "autoassign"), ".  PC
  review assignments can be “primary” or “secondary”; the difference is
  that primary reviewers are expected to complete their review, but a
  secondary reviewer can delegate their review to someone else. You can
  also assign PC “metareviews”. Unlike normal reviewers, a metareviewer can
  view all other reviews before submitting their own.</p>

  <p>The assignment pages apply to all submissions by default.  You can
  also assign groups of submissions, such as ",
  $hth->search_link("papers with fewer than three completed reviews", "cre:<3"),
  ".</p></li>\n";

        } else if ($gj->itemid === 9) {
            echo "<li><p><strong>", $hth->setting_link("Enable review editing.", "review_open"), "</strong></p></li>\n";
        }
    }

    static function print_chair_conflicts(HelpRenderer $hth) {
        echo $hth->subhead("Chair conflicts");
        echo "<p>Chairs and system administrators can access any information stored in the
conference system, including reviewer identities for conflicted papers.
It is easiest to simply accept such conflicts as a fact of life. Chairs
who can’t handle conflicts fairly shouldn’t be chairs. However, HotCRP
does offer other mechanisms for conflicted reviews.</p>

<p>First, each paper can be assigned a <em>paper administrator</em>: a PC member
who manages that paper’s reviewing and discussion. Use the left-hand side of a ",
$hth->hotlink("submission’s assignment page", "assign"), " to enter its administrator. (You may need to
“Override conflicts” to access the assignment page.)
Paper administrators have full privilege to assign and view reviews for their
papers, and can, for example, use the autoassignment tool, but they cannot change
conference settings. When a paper
has an administrator, chair conflicts cannot be overridden.</p>

<p>Paper administrators make life easy for PC reviewers and greatly restrict
conflicted chairs’ access. Usually this suffices.
For additional privacy, use
<em>review tokens</em>, which are completely anonymous
review slots. To create a token, an administrator
goes to an ", $hth->hotlink("assignment page", "assign"), "
and clicks on “Request review” without entering a name
or email address. This reports the token, a short string of letters and
numbers such as “9HDZYUB”. Any user who knows the token can
enter it on HotCRP’s home page, after which the system lets them
view the paper and anonymously modify the corresponding “Jane Q. Public”
review. True reviewer identities will not appear in HotCRP’s
database or its logs.
For even more privacy, the paper administrator could collect
offline review forms via email and upload them using
review tokens; then even web server access logs store only the
administrator’s identity.</p>\n\n";
    }

    static function print_premeeting(HelpRenderer $hth, $gj) {
        if ($gj->itemid === -1) {
            echo $hth->subhead("Before the meeting");
            echo "<ol>\n";
            $hth->print_members("chair/premeeting");
            echo "</ol>\n\n";

        } else if ($gj->itemid === 1) {
            echo "<li><p><strong>", $hth->setting_link("Collect authors’ responses to the reviews (optional).", "response_active"),
  "</strong>  Authors’ responses (also called rebuttals) let authors correct reviewer misconceptions
  before decisions are made.  Responses are entered
  into the system as comments.  On the ", $hth->setting_group_link("decision settings page", "dec"),
  ", update “Collect responses to the reviews,” then ", $hth->hotlink("send mail to authors", "mail"), " informing them of the response deadline.  PC members can still
  update their reviews up to the ", $hth->setting_group_link("review deadline", "reviews"),
  "; authors are informed via email of any review changes.</p></li>\n";

        } else if ($gj->itemid === 2) {
            echo "<li><p>Set <strong>", $hth->setting_link("PC can see review contents", "review_visibility_pc"),
  "</strong> to “Yes” (optional). This opens up the reviews to the program committee,
  allowing everyone to see scores and read reviews for non-conflicted papers.
  (During most conferences’ review periods, a PC member can see a paper’s reviews
  only after completing their own review for that paper.)</p></li>\n";

        } else if ($gj->itemid === 3) {
            echo "<li><p><strong>", $hth->search_link("Examine paper scores", "show:scores"),
  "</strong>, either one at a time or en masse, and decide
  which papers will be discussed.  The ", $hth->help_link("tags", "tags"),
  " system can group papers and prepare discussion sets.
  Use ", $hth->help_link("search keywords", "keywords"), " to, for example,
  find all papers with at least two overall merit ratings of 2 or better.</p></li>\n";

        } else if ($gj->itemid === 4) {
            echo "<li><p><strong>Assign discussion orders using ", $hth->help_link("tags", "tags#values"),
  "</strong> (optional).  Common
  discussion orders include sorted by overall ranking (high-to-low,
  low-to-high, or alternating), sorted by topic, and ",
  $hth->hotlink("grouped by PC conflicts", "autoassign", "a=discorder"), ".
  Explicit tag-based orders make it easier for the PC to follow along.</p></li>\n";

        } else if ($gj->itemid === 5) {
            echo "<li><p><strong>", $hth->hotlink("Assign discussion leads (optional).", "autoassign"), "</strong> Discussion leads are expected to be able to
  summarize the paper and the reviews.  You can assign leads either ",
                $hth->hotlink("paper by paper", "assign"), " or ",
                $hth->hotlink("automatically", "autoassign"), ".</p></li>\n";

        } else if ($gj->itemid === 6) {
            echo "<li><p><strong>", $hth->setting_link("Define decision types (optional).", "decision"),
  "</strong> By default, HotCRP has two decision types,
  “accept” and “reject,” but you can add other types of acceptance and
  rejection, such as “accept as short paper.”</p></li>\n";

        } else if ($gj->itemid === 7) {
            echo "<li><p>The night before the meeting, <strong>", $hth->search_link("download all reviews onto a laptop", ""),
  "</strong> (Download &gt; All reviews) in case the
  Internet explodes and you can’t reach HotCRP from the meeting
  place.</p></li>\n";
        }
    }


    static function print_atmeeting(HelpRenderer $hth, $gj) {
        if ($gj->itemid === -1) {
            echo $hth->subhead("At the meeting");
            echo "<ol>\n";
            $hth->print_members("chair/atmeeting");
            echo "</ol>\n\n";

        } else if ($gj->itemid === 1) {
            echo "<li><p>The <b>meeting tracker</b> can keep the PC coordinated
  by sharing your browser’s status.
  Search for papers in whatever order you like (you may want an explicit ",
  $hth->help_link("discussion order", "tags#values"), "),
  navigate to the first paper in
  the order, and select “&#9759;” to activate the tracker in that tab.
  As you use that tab to navigate through the order, its current
  position is broadcast to all logged-in PC members’ browsers:</p>
  " . Ht::img("extracker.png", "[Meeting tracker]", ["style" => "max-width:714px"]) . "
  <p>Manage multiple trackers and limit PC visibility
  by shift-clicking “&#9759;” or clicking it again. You can also view the discussion
  status on the ", $hth->hotlink("discussion status page", "buzzer"), ".</p></li>\n";

        } else if ($gj->itemid === 2) {
            echo "<li><p>Scribes can capture discussions as comments for the authors’
  reference.</p></li>\n";

        } else if ($gj->itemid === 3) {
            echo "<li><p><strong>Paper decisions</strong> can be recorded on the ",
  $hth->hotlink("paper pages", "review"), " or en masse via ",
  $hth->search_link("search", ""), ".  Use ", $hth->setting_link("decision settings", "decision_visibility_author"),
  " to expose decisions to PC members if desired.</p></li>\n";

        } else if ($gj->itemid === 4) {
            echo "<li><p><strong>Shepherding (optional).</strong> If your conference uses
  shepherding for accepted papers, you can assign shepherds either ",
  $hth->hotlink("paper by paper", "paper"), " or ", $hth->hotlink("automatically", "autoassign", "t=accepted"), ".</p></li>\n";
        }
    }

    static function print_postmeeting(HelpRenderer $hth, $gj) {
        if ($gj->itemid === -1) {
            echo $hth->subhead("After the meeting");
            echo "<ol>\n";
            $hth->print_members("chair/postmeeting");
            echo "</ol>\n\n";

        } else if ($gj->itemid === 1) {
            echo "<li><p><strong>", $hth->search_link("Enter decisions", ""), " and ",
  $hth->search_link("shepherds", "dec:yes"), "</strong>
  if you didn’t do this at the meeting.</p></li>\n";

        } else if ($gj->itemid === 2) {
            echo "<li><p>Give reviewers some time to <strong>update their reviews</strong> in
  response to PC discussion (optional).</p></li>\n";

        } else if ($gj->itemid === 3) {
            echo "<li><p>Set ", $hth->setting_link("“Can <strong>authors see decisions?</strong>”", "decision_visibility_author"),
  " to Yes.";
            if (!$hth->conf->setting("shepherd_hide"))
                echo " This will also make shepherd names visible to authors.";
            echo "</p></li>\n";

        } else if ($gj->itemid === 4) {
            echo "<li><p><strong>", $hth->hotlink("Send mail to authors", "mail"), "</strong> informing them that reviews and decisions are
available.  The mail can also contain the reviews and comments
themselves.</p></li>\n";

        } else if ($gj->itemid === 5) {
            echo "<li><p><strong>", $hth->setting_link("Collect final papers (optional).", "final_open"),
"</strong> If you’re putting together the program
yourself, it can be convenient to collect final versions using HotCRP.
Authors upload final versions just as they did submissions.  You can then ",
$hth->search_link("download all final versions as a <code>.zip</code> archive", "dec:yes"),
".  (The submitted versions are archived for reference.)</p></li>\n";
        }
    }
}
