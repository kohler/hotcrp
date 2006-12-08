export VERSION=2.0b2

mkdistdir () {
    crpd=hotcrp-$VERSION
    rm -rf $crpd
    mkdir $crpd
    
    while read f; do
	if [ -n "$f" ]; then
	    d=`echo "$f" | sed 's/[^\/]*$//'`
	    [ -n "$d" -a ! -d "$crpd/$d" ] && mkdir "$crpd/$d"
	    if [ -f "$f" ]; then
		ln "$f" "$crpd/$f"
	    else
		cp -r "$f" "$crpd/$d"
	    fi
	fi
    done

    tar czf $crpd.tar.gz $crpd
    rm -rf $crpd
}

mkdistdir <<EOF

.htaccess
README
account.php
assign.php
comment.php
contactauthors.php
deadlines.php
help.php
index.php
login.php
logout.php
mergeaccounts.php
offline.php
paper.php
pc.php
reload.sh
review.php
script.js
search.php
sessionvar.php
settings.php
style.css

Code/.htaccess
Code/Calendar.inc
Code/conference.inc
Code/contact.inc
Code/createdb.sh
Code/header.inc
Code/helpers.inc
Code/options.inc
Code/paperlist.inc
Code/papertable.inc
Code/review.inc
Code/reviewtable.inc
Code/schema.sql
Code/search.inc
Code/tags.inc

Code/MDB2-2.3.0
Code/Mail-1.1.14

images/GenChart.php
images/GeneralChart.php
images/ass-1.png
images/ass0.png
images/ass1.png
images/ass1n.png
images/ass2.png
images/ass2n.png
images/ass3.png
images/ass3n.png
images/ass4.png
images/ass4n.png
images/bendulft.png
images/info45.png
images/next.png
images/pageresultsex.png
images/pdf.png
images/postscript.png
images/prev.png
images/sortdown.png
images/sortup.png
images/stophand45.png

Assistant/.htaccess
Assistant/DumpAllReviews.php
Assistant/ListReviewers.php
Assistant/ModifyUserNames.php
Assistant/PrepareFacesheets.php
Assistant/PrintAllAbstracts.php
Assistant/PrintAllReviews.php
Assistant/PrintSomeReviews.php
Assistant/index.php

Author/.htaccess
Author/SubmitResponse.php
Author/index.php

Chair/.htaccess
Chair/AskForReview.php
Chair/AssignPapers.php
Chair/AveragePaperScore.php
Chair/AverageReviewerScore.php
Chair/BecomeSomeoneElse.php
Chair/CheckOnPCProgress.php
Chair/CheckOnSinglePCProgress.php
Chair/Code.inc
Chair/DumpDatabase.php
Chair/FindPCConflicts.php
Chair/GradeAllPapers.php
Chair/ListPC.php
Chair/ListReviewers.php
Chair/ListReviews.php
Chair/SendMail.php
Chair/SendMail2.php
Chair/SetReviewForm.php
Chair/ShowCalendar.php
Chair/SpotProblems.php
Chair/ViewActionLog.php
Chair/ViewButtons.php
Chair/index.php

Download/.htaccess
Download/GetPaper
Download/GetPaper.php

PC/.htaccess
PC/CheckOnPCProgress.php
PC/CheckReviewStatus.php
PC/GradePapers.php
PC/SeeAllGrades.php
PC/SpotMyProblems.php
PC/gradeNames.inc
PC/index.php
PC/reviewprefs.php

EOF
