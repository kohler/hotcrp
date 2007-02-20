export VERSION=2.0b6

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
LICENSE
README
account.php
assign.php
autoassign.php
comment.php
contacts.php
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
Code/backupdb.sh
Code/conference.inc
Code/contact.inc
Code/contactlist.inc
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

Code/Mail-1.1.14

images/_.gif
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
images/exassignone.png
images/extagsnone.png
images/extagssearch.png
images/extagsset.png
images/info45.png
images/next.png
images/pageresultsex.png
images/pdf.png
images/pdf24.png
images/postscript.png
images/postscript24.png
images/prev.png
images/quicksearchex.png
images/sortdown.png
images/sortup.png
images/stophand45.png

Assistant/.htaccess
Assistant/DumpAllReviews.php
Assistant/ModifyUserNames.php
Assistant/PrepareFacesheets.php
Assistant/PrintAllAbstracts.php
Assistant/PrintAllReviews.php
Assistant/PrintSomeReviews.php
Assistant/index.php

Chair/.htaccess
Chair/AskForReview.php
Chair/AssignPapers.php
Chair/AverageReviewerScore.php
Chair/BecomeSomeoneElse.php
Chair/Code.inc
Chair/DumpDatabase.php
Chair/GradeAllPapers.php
Chair/ListReviews.php
Chair/SendMail.php
Chair/SendMail2.php
Chair/SetReviewForm.php
Chair/ShowCalendar.php
Chair/SpotProblems.php
Chair/ViewActionLog.php
Chair/ViewButtons.php
Chair/index.php
Chair/sampleforms.inc

Download/.htaccess
Download/GetPaper
Download/GetPaper.php

PC/.htaccess
PC/CheckReviewStatus.php
PC/GradePapers.php
PC/gradeNames.inc
PC/index.php
PC/reviewprefs.php

EOF
