export VERSION=2.102

# check that schema.sql and updateschema.php agree on schema version
updatenum=`grep 'settings.*allowPaperOption.*=\|update_schema_version' src/updateschema.php | tail -n 1 | sed 's/.*= *//;s/.*[(] *//;s/[;)].*//'`
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
conflictassign.php
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
batch/addusers.php
batch/checkinvariants.php
batch/deletepapers.php
batch/fixdelegation.php
batch/killinactivedoc.php
batch/s3test.php
batch/s3transfer.php
batch/s3verifyall.php
batch/savepapers.php
batch/updatecontactdb.php

conf/.htaccess

etc/.htaccess
etc/affiliationmatchers.json
etc/apifunctions.json
etc/assignmentparsers.json
etc/emojicodes.json
etc/formulafunctions.json
etc/helptopics.json
etc/listactions.json
etc/mailkeywords.json
etc/msgs.json
etc/optiontypes.json
etc/papercolumns.json
etc/profilegroups.json
etc/reviewformlibrary.json
etc/searchkeywords.json
etc/settings.json
etc/settinggroups.json
etc/submissioneditgroups.json

lib/.htaccess
lib/abbreviationmatcher.php
lib/archiveinfo.php
lib/backupdb.sh
lib/base.php
lib/cleanhtml.php
lib/column.php
lib/countmatcher.php
lib/countries.php
lib/createdb.sh
lib/csv.php
lib/curls3document.php
lib/dbhelper.sh
lib/dbl.php
lib/filer.php
lib/getopt.php
lib/ht.php
lib/icons.php
lib/intlmsgset.php
lib/json.php
lib/ldaplogin.php
lib/login.php
lib/mailer.php
lib/message.php
lib/messageset.php
lib/mime.types
lib/mimetype.php
lib/mincostmaxflow.php
lib/navigation.php
lib/qobject.php
lib/qrequest.php
lib/redirect.php
lib/restoredb.sh
lib/runsql.sh
lib/s3document.php
lib/scoreinfo.php
lib/tagger.php
lib/text.php
lib/unicodehelper.php
lib/xlsx.php
lib/zipdocument.php

pages/.htaccess
pages/adminhome.php
pages/home.php

src/.htaccess
src/api/api_alltags.php
src/api/api_error.php
src/api/api_requestreview.php
src/api/api_search.php
src/api/api_searchconfig.php
src/api/api_taganno.php
src/api/api_user.php
src/assigners/a_conflict.php
src/assigners/a_decision.php
src/assigners/a_lead.php
src/assigners/a_preference.php
src/assigners/a_status.php
src/assigners/a_tag.php
src/assignmentset.php
src/author.php
src/authormatcher.php
src/autoassigner.php
src/banal
src/capability.php
src/checkformat.php
src/commentinfo.php
src/conference.php
src/conflict.php
src/contact.php
src/contactlist.php
src/contactsearch.php
src/distoptions.php
src/documentinfo.php
src/filefilter.php
src/formatspec.php
src/formula.php
src/formulagraph.php
src/groupedextensions.php
src/help/h_chairsguide.php
src/help/h_formulas.php
src/help/h_keywords.php
src/help/h_ranking.php
src/help/h_revrate.php
src/help/h_revround.php
src/help/h_scoresort.php
src/help/h_search.php
src/help/h_tags.php
src/help/h_votetags.php
src/helpers.php
src/hotcrpmailer.php
src/init.php
src/initweb.php
src/listaction.php
src/listactions/la_assign.php
src/listactions/la_decide.php
src/listactions/la_getallrevpref.php
src/listactions/la_getdocument.php
src/listactions/la_getjson.php
src/listactions/la_getjsonrqc.php
src/listactions/la_getrevpref.php
src/listactions/la_get_rev.php
src/listactions/la_get_sub.php
src/listactions/la_mail.php
src/listactions/la_tag.php
src/listsorter.php
src/mailclasses.php
src/mailtemplate.php
src/meetingtracker.php
src/mergecontacts.php
src/messages.csv
src/multiconference.php
src/paperapi.php
src/papercolumn.php
src/papercolumns/pc_administrator.php
src/papercolumns/pc_commenters.php
src/papercolumns/pc_conflict.php
src/papercolumns/pc_conflictmatch.php
src/papercolumns/pc_desirability.php
src/papercolumns/pc_formula.php
src/papercolumns/pc_formulagraph.php
src/papercolumns/pc_lead.php
src/papercolumns/pc_option.php
src/papercolumns/pc_pagecount.php
src/papercolumns/pc_pcconflicts.php
src/papercolumns/pc_preference.php
src/papercolumns/pc_reviewdelegation.php
src/papercolumns/pc_shepherd.php
src/papercolumns/pc_tagreport.php
src/papercolumns/pc_timestamp.php
src/papercolumns/pc_topics.php
src/papercolumns/pc_topicscore.php
src/paperevents.php
src/paperinfo.php
src/paperlist.php
src/paperoption.php
src/papersaver.php
src/papersearch.php
src/paperstatus.php
src/papertable.php
src/paperrank.php
src/review.php
src/reviewdiffinfo.php
src/reviewinfo.php
src/reviewtable.php
src/reviewtimes.php
src/sample.pdf
src/schema.sql
src/search/st_author.php
src/search/st_comment.php
src/search/st_conflict.php
src/search/st_decision.php
src/search/st_editfinal.php
src/search/st_formula.php
src/search/st_option.php
src/search/st_paperpc.php
src/search/st_paperstatus.php
src/search/st_pdf.php
src/search/st_review.php
src/search/st_reviewtoken.php
src/search/st_revpref.php
src/search/st_tag.php
src/search/st_topic.php
src/searchselection.php
src/sessionlist.php
src/settings/s_basics.php
src/settings/s_decisions.php
src/settings/s_decisionvisibility.php
src/settings/s_finalversions.php
src/settings/s_messages.php
src/settings/s_options.php
src/settings/s_responses.php
src/settings/s_reviewform.php
src/settings/s_reviews.php
src/settings/s_reviewvisibility.php
src/settings/s_submissions.php
src/settings/s_subform.php
src/settings/s_tags.php
src/settings/s_topics.php
src/settings/s_tracks.php
src/settings/s_users.php
src/settingvalues.php
src/textformat.php
src/updateschema.php
src/useractions.php
src/userstatus.php

devel/hotcrp.vim

images/.htaccess
images/_.gif
images/assign48.png
images/buzzer.mp3
images/check.png
images/comment48.png
images/cross.png
images/edit48.png
images/exassignone.png
images/exsearchaction.png
images/extagcolors.png
images/extagseditkw.png
images/extagssearch.png
images/extagsset.png
images/extagvotehover.png
images/extracker.png
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
images/pdffx.png
images/pdffx24.png
images/pdfx.png
images/pdfx24.png
images/postscript.png
images/postscript24.png
images/postscriptf.png
images/postscriptf24.png
images/prev.png
images/quicksearchex.png
images/review24.png
images/review48.png
images/sortdown.png
images/sortup.png
images/stophand45.png
images/txt.png
images/txt24.png
images/view48.png
images/viewas.png

scripts/.htaccess
scripts/d3.min.js
scripts/d3-hotcrp.min.js
scripts/buzzer.js
scripts/graph.js
scripts/jquery-1.12.4.min.js
scripts/jquery-1.12.4.min.map
scripts/jquery-3.3.1.min.js
scripts/script.js
scripts/settings.js

stylesheets/.htaccess
stylesheets/style.css
stylesheets/mobile.css

EOF
