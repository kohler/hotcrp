export VERSION=2.99

# check that schema.sql and updateschema.php agree on schema version
updatenum=`grep 'settings.*allowPaperOption.*=\|update_schema_version' src/updateschema.php | tail -n 1 | sed 's/.*= *//;s/.*, *//;s/[;)].*//'`
schemanum=`grep 'allowPaperOption' src/schema.sql | sed 's/.*, *//;s/).*//'`
if [ "$updatenum" != "$schemanum" ]; then
    echo "error: allowPaperOption schema number in src/schema.sql ($schemanum)" 1>&2
    echo "error: differs from number in src/updateschema.php ($updatenum)" 1>&2
    exit 1
fi

# check that HOTCRP_VERSION is up to date -- unless first argument is -n
versionnum=`grep 'HOTCRP_VERSION' src/init.php | head -n 1 | sed 's/.*, "//;s/".*//'`
if [ "$versionnum" != "$VERSION" -a "$1" != "-n" ]; then
    echo "error: HOTCRP_VERSION in src/init.php ($versionnum)" 1>&2
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

    export COPY_EXTENDED_ATTRIBUTES_DISABLE=true COPYFILE_DISABLE=true
    tar --exclude='.DS_Store' --exclude='._*' -czf $crpd.tar.gz $crpd
    rm -rf $crpd
}

mkdistdir <<EOF

.htaccess
.user.ini
LICENSE
NEWS.md
README.md
api.php
assign.php
autoassign.php
bulkassign.php
buzzer.php
cacheable.php
checkupdates.php
comment.php
deadlines.php
doc.php
graph.php
help.php
index.php
log.php
mail.php
manualassign.php
mergeaccounts.php
offline.php
paper.php
profile.php
resetpassword.php
review.php
reviewprefs.php
scorechart.php
search.php
settings.php
users.php

batch/.htaccess
batch/adddoc.php
batch/addusers.php
batch/checkinvariants.php
batch/killinactivedoc.php
batch/s3test.php
batch/s3transfer.php
batch/s3verifyall.php
batch/savepapers.php
batch/updatecontactdb.php

conf/.htaccess

lib/.htaccess
lib/backupdb.sh
lib/base.php
lib/cleanhtml.php
lib/column.php
lib/countries.php
lib/createdb.sh
lib/csv.php
lib/dbhelper.sh
lib/dbl.php
lib/filer.php
lib/getopt.php
lib/ht.php
lib/json.php
lib/ldaplogin.php
lib/login.php
lib/mailer.php
lib/message.php
lib/mimetype.php
lib/mincostmaxflow.php
lib/navigation.php
lib/qobject.php
lib/redirect.php
lib/restoredb.sh
lib/runsql.sh
lib/s3document.php
lib/scoreinfo.php
lib/tagger.php
lib/text.php
lib/unicodehelper.php
lib/xlsx.php

pages/.htaccess
pages/adminhome.php
pages/home.php

src/.htaccess
src/assigners.php
src/autoassigner.php
src/banal
src/capability.php
src/checkformat.php
src/commentinfo.php
src/conference.php
src/conflict.php
src/contact.php
src/contactlist.php
src/distoptions.php
src/formula.php
src/formulagraph.php
src/helpers.php
src/hotcrpdocument.php
src/hotcrpmailer.php
src/init.php
src/initweb.php
src/listsorter.php
src/mailclasses.php
src/mailtemplate.php
src/meetingtracker.php
src/messages.csv
src/multiconference.php
src/paperactions.php
src/paperapi.php
src/papercolumn.php
src/paperinfo.php
src/paperlist.php
src/paperoption.php
src/papersearch.php
src/paperstatus.php
src/papertable.php
src/paperrank.php
src/review.php
src/reviewformlibrary.json
src/reviewsetform.php
src/reviewtable.php
src/reviewtimes.php
src/sample.pdf
src/schema.sql
src/searchactions.php
src/settinginfo.json
src/updateschema.php
src/useractions.php
src/userstatus.php

extra/hotcrp.vim

images/.htaccess
images/_.gif
images/allreviews24.png
images/assign18.png
images/assign24.png
images/bendulft.png
images/buzzer.mp3
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
images/generic.png
images/generic24.png
images/genericf.png
images/genericf24.png
images/headgrad.png
images/homegrad.png
images/info45.png
images/next.png
images/override24.png
images/pageresultsex.png
images/pdf.png
images/pdf24.png
images/pdff.png
images/pdff24.png
images/postscript.png
images/postscript24.png
images/postscriptf.png
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

scripts/.htaccess
scripts/d3.min.js
scripts/graph.js
scripts/jquery-1.12.0.min.js
scripts/jquery-1.12.0.min.map
scripts/script.js
scripts/settings.js

stylesheets/.htaccess
stylesheets/style.css
stylesheets/mobile.css

EOF
