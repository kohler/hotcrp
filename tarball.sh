export VERSION=2.35
perl -pi -e 's/HotCRP: Conference Review Package 2\.\d+/HotCRP: Conference Review Package '$VERSION'/' README

# check that schema.sql and updateschema.inc agree on schema version
updatenum=`grep 'settings.*allowPaperOption.*=' Code/updateschema.inc | tail -n 1 | sed 's/.*= *//;s/;.*//'`
schemanum=`grep 'allowPaperOption' Code/schema.sql | sed 's/.*, *//;s/).*//'`
if [ "$updatenum" != "$schemanum" ]; then
    echo "error: allowPaperOption schema number in Code/schema.sql ($schemanum)" 1>&2
    echo "error: differs from number in Code/updateschema.inc ($updatenum)" 1>&2
    exit 1
fi

# check that HOTCRP_VERSION is up to date -- unless first argument is -n
versionnum=`grep 'HOTCRP_VERSION' Code/header.inc | head -n 1 | sed 's/.*, "//;s/".*//'`
if [ "$versionnum" != "$VERSION" -a "$1" != "-n" ]; then
    echo "error: HOTCRP_VERSION in Code/header.inc ($versionnum)" 1>&2
    echo "error: differs from current version ($VERSION)" 1>&2
    exit 1
fi

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
NEWS
README
account.php
assign.php
autoassign.php
bulkassign.php
cacheable.php
comment.php
contactauthors.php
contacts.php
deadlines.php
doc.php
help.php
index.php
log.php
mail.php
manualassign.php
mergeaccounts.php
offline.php
paper.php
reload.sh
review.php
reviewprefs.php
scorehelp.php
script.js
search.php
sessionvar.php
settings.php
style.css
supersleight.js

Code/.htaccess
Code/backupdb.sh
Code/banal
Code/baselist.inc
Code/checkformat.inc
Code/commentview.inc
Code/conference.inc
Code/contact.inc
Code/contactlist.inc
Code/countries.inc
Code/createdb.sh
Code/distoptions.inc
Code/header.inc
Code/helpers.inc
Code/ldaplogin.inc
Code/mailtemplate.inc
Code/paperactions.inc
Code/paperexpr.inc
Code/paperlist.inc
Code/papertable.inc
Code/rank.inc
Code/review.inc
Code/reviewsetform.inc
Code/reviewtable.inc
Code/reviewtemplate.inc
Code/sample.pdf
Code/schema.sql
Code/search.inc
Code/tags.inc
Code/updateschema.inc
Code/updateschema.sql

Code/Mail-1.1.14

images/.htaccess
images/_.gif
images/GenChart.php
images/allreviews24.png
images/asprite.gif
images/ass-2.png
images/ass-1.png
images/ass0.gif
images/ass1.gif
images/ass1n.gif
images/ass2.gif
images/ass2n.gif
images/ass3.gif
images/ass3n.gif
images/ass4.gif
images/ass4n.gif
images/assign18.png
images/assign24.png
images/bendulft.png
images/check.png
images/checksum12.png
images/comment24.png
images/cross.png
images/edit.png
images/edit18.png
images/edit24.png
images/exassignone.png
images/exsearchaction.png
images/extagsnone.png
images/extagssearch.png
images/extagsset.png
images/extagvotehover.png
images/headgrad.png
images/homegrad.png
images/info45.png
images/next.png
images/override24.png
images/pageresultsex.png
images/pdf.png
images/pdff.png
images/pdf24.png
images/pdff24.png
images/postscript.png
images/postscriptf.png
images/postscript24.png
images/postscriptf24.png
images/prev.png
images/quicksearchex.png
images/review18.png
images/review24.png
images/sortdown.png
images/sortup.png
images/sprite.png
images/stophand45.png
images/timestamp12.png
images/txt.png
images/txt24.png
images/view18.png
images/view24.png
images/viewas.png

EOF
