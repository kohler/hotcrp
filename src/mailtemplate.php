<?php
// mailtemplate.php -- HotCRP mail templates
// Copyright (c) 2006-2018 Eddie Kohler; see LICENSE.

global $mailTemplates;
$mailTemplates = array
    ("createaccount" =>
     array("subject" => "[%CONFSHORTNAME%] Account information",
           "body" => "Greetings,

An account has been created for you at the %CONFNAME% submissions site.

        Site: %URL%/
       Email: %EMAIL%
    Password: %OPT(PASSWORD)%

Use the link below to sign in.

%LOGINURL%

If you already have an account under a different email address, you may merge this new account into that one. Go to your profile page and select \"Merge with another account\".

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "activateaccount" =>
     array("subject" => "[%CONFSHORTNAME%] Account information",
           "body" => "Greetings,

Your %CONTACTDBDESCRIPTION% account has been activated for the %CONFNAME% submissions site.

        Site: %URL%/
       Email: %EMAIL%

Use the link above to sign in with your %CONTACTDBDESCRIPTION% password or to reset your password.

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "accountinfo" =>
     array("subject" => "[%CONFSHORTNAME%] Account information",
           "body" => "Dear %NAME%,

Here is your account information for the %CONFNAME% submissions site.

        Site: %URL%/
       Email: %EMAIL%
    Password: %OPT(PASSWORD)%

Or use the link below to sign in.

%LOGINURL%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "resetpassword" =>
     array("subject" => "[%CONFSHORTNAME%] Password reset request",
           "body" => "Dear %NAME%,

We received a request to reset the password for your account on the %CONFNAME% submissions site. If you made this request, please use the following link to create a new password. The link will work for 3 days.

%URL%/resetpassword%PHP%/%CAPABILITY%

If you did not make this request, it's safe to ignore this email.

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "changeemail" =>
     array("subject" => "[%CONFSHORTNAME%] Email change request",
           "body" => "Dear %NAME%,

We received a request to change the email address for your account on the %CONFNAME% submissions site. If you made this request, please use the following link to update your account to use %EMAIL%. The link will work for 3 days.

%URL%/profile%PHP%?changeemail=%CAPABILITY%

If you did not make this request, please ignore this email.

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "mergeaccount" =>
     array("subject" => "[%CONFSHORTNAME%] Merged account",
           "body" => "Dear %NAME%,

Your account at the %CONFSHORTNAME% submissions site has been merged with the account of %OTHERCONTACT%. From now on, you should log in using the %OTHEREMAIL% account.

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "requestreview" =>
     array("subject" => "[%CONFSHORTNAME%] Review request for submission #%NUMBER%",
           "body" => "Dear %NAME%,

On behalf of the %CONFNAME% program committee, %REQUESTERCONTACT% has asked you to review %CONFNAME% submission #%NUMBER%.%IF(REASON)% They supplied this note: %REASON%%ENDIF%

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%)%

If you are willing to review this submission, you may enter your review on the conference site or complete a review form offline and upload it.%IF(DEADLINE(extrev_soft))% Your review is requested by %DEADLINE(extrev_soft)%.%ENDIF%

Your account information is as follows.

        Site: %URL%/
       Email: %EMAIL%
    Password: %OPT(PASSWORD)%

Or use the link below to sign in.

%LOGINURL%

Once you've decided, please take a moment to accept or decline this review request by using one of these links. You may also contact %REQUESTERNAME% directly or decline the review using the conference site.

      Accept: %URL(review, p=%NUMBER%&accept=1&%LOGINURLPARTS%)%
     Decline: %URL(review, p=%NUMBER%&decline=1&%LOGINURLPARTS%)%

Contact %ADMIN% with any questions or concerns.

Thank you for your help -- we appreciate that reviewing is hard work.
%SIGNATURE%\n"),

     "retractrequest" =>
     array("subject" => "[%CONFSHORTNAME%] Retracting review request for submission #%NUMBER%",
           "body" => "Dear %NAME%,

%REQUESTERNAME% has retracted a previous request that you review %CONFNAME% submission #%NUMBER%. There's no need to complete your review.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%

Contact %ADMIN% with any questions or concerns.

Thank you,
%SIGNATURE%\n"),

     "proposereview" =>
     array("subject" => "[%CONFSHORTNAME%] Proposed reviewer for submission #%NUMBER%",
           "body" => "Greetings,

%REQUESTERCONTACT% would like %REVIEWERCONTACT% to review %CONFNAME% submission #%NUMBER%.%IF(REASON)% They supplied this note: %REASON%%ENDIF%

Visit the assignment page to approve or deny the request.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(assign, p=%NUMBER%)%

%SIGNATURE%\n"),

     "denyreviewrequest" =>
     array("subject" => "[%CONFSHORTNAME%] Proposed reviewer for submission #%NUMBER% denied",
           "body" => "Dear %NAME%,

Your proposal that %REVIEWERCONTACT% review %CONFNAME% submission #%NUMBER% has been denied by an administrator. You may want to propose someone else.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%)%

Contact %ADMIN% with any questions or concerns.

Thank you,
%SIGNATURE%\n"),

     "refusereviewrequest" =>
     array("subject" => "[%CONFSHORTNAME%] Review request for submission #%NUMBER% declined",
           "body" => "Dear %NAME%,

%REVIEWERCONTACT% cannot complete the review you requested of %CONFNAME% submission #%NUMBER%. %IF(REASON)%They gave the following reason: %REASON% %ENDIF%You may want to find an alternate reviewer.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%)%

%SIGNATURE%\n"),

     "authorwithdraw" =>
     array("subject" => "[%CONFSHORTNAME%] Withdrawn submission #%NUMBER% %TITLEHINT%",
           "body" => "Dear %NAME%,

An author of %CONFNAME% submission #%NUMBER% has withdrawn the submission from consideration. It will not be reviewed.%IF(REASON)% They gave the following reason: %REASON%%ENDIF%

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%)%

Contact %ADMIN% with any questions or concerns.

Thank you,
%SIGNATURE%\n"),

     "adminwithdraw" =>
     array("subject" => "[%CONFSHORTNAME%] Withdrawn submission #%NUMBER% %TITLEHINT%",
           "body" => "Dear %NAME%,

%CONFNAME% submission #%NUMBER% has been withdrawn from consideration and will not be reviewed.

%IF(REASON)%The submission was withdrawn by an administrator, who provided the following reason: %REASON%%ELSE%The submission was withdrawn by an administrator.%ENDIF%

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%)%

Contact %ADMIN% with any questions or concerns.

Thank you,
%SIGNATURE%\n"),

     "withdrawreviewer" =>
     array("subject" => "[%CONFSHORTNAME%] Withdrawn submission #%NUMBER% %TITLEHINT%",
           "body" => "Dear %NAME%,

%CONFSHORTNAME% submission #%NUMBER%, which you reviewed or have been assigned to review, has been withdrawn from consideration for the conference.

Authors and administrators can withdraw submissions during the review process.%IF(REASON)% The following reason was provided: %REASON%%ENDIF%

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%)%

You are not expected to complete your review (and the system will not allow it unless the submission is revived).

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "deletepaper" =>
     array("subject" => "[%CONFSHORTNAME%] Deleted submission #%NUMBER% %TITLEHINT%",
           "body" => "Dear %NAME%,

Your %CONFNAME% submission #%NUMBER% has been removed from the submission database by an administrator. This can be done to eliminate duplicates. %IF(REASON)%The following reason was provided for deleting the submission: %REASON%%ENDIF%

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "reviewsubmit" =>
     array("subject" => "[%CONFSHORTNAME%] Submitted %REVIEWNAME(SUBJECT)% %TITLEHINT%",
           "body" => "Greetings,

%REVIEWNAME% for %CONFNAME% submission #%NUMBER% has been submitted. The review is available at the submission site.

        Site: %URL(paper, p=%NUMBER%)%
       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

For the most up-to-date reviews and comments, or to unsubscribe from email notification, see the submission site.

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%

%REVIEWS%\n"),

     "reviewupdate" =>
     array("subject" => "[%CONFSHORTNAME%] Updated %REVIEWNAME(SUBJECT)% %TITLEHINT%",
           "body" => "Greetings,

%REVIEWNAME% for %CONFNAME% submission #%NUMBER% has been updated. The review is available at the submission site.

        Site: %URL(paper, p=%NUMBER%)%
       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

For the most up-to-date reviews and comments, or to unsubscribe from email notification, see the submission site.

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%

%REVIEWS%\n"),

     "reviewapprovalrequest" =>
     array("subject" => "[%CONFSHORTNAME%] Review approval requested for submission #%NUMBER% %TITLEHINT%",
           "body" => "Greetings,

%REVIEWAUTHOR%'s review for %CONFNAME% submission #%NUMBER% has been submitted for approval.

 Review site: %URL(review, p=%NUMBER%&r=%REVIEWID%)%
       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%

%REVIEWS%\n"),

     "reviewapprovalupdate" =>
     array("subject" => "[%CONFSHORTNAME%] Review approval requested for submission #%NUMBER% %TITLEHINT%",
           "body" => "Greetings,

%REVIEWAUTHOR%'s review for %CONFNAME% submission #%NUMBER% has been resubmitted for approval.

 Review site: %URL(review, p=%NUMBER%&r=%REVIEWID%)%
       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

You can approve the review at the link above.

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%

%REVIEWS%\n"),

     "reviewpreapprovaledit" =>
     array("subject" => "[%CONFSHORTNAME%] Review edited for submission #%NUMBER% %TITLEHINT%",
           "body" => "Greetings,

%REVIEWAUTHOR%'s review for %CONFNAME% submission #%NUMBER% has been edited by its requester. The review has not yet been approved.

 Review site: %URL(review, p=%NUMBER%&r=%REVIEWID%)%
       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
   Review by: %OPT(REVIEWAUTHOR)%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%

%REVIEWS%\n"),

     "acceptnotify" =>
     array("mailtool_name" => "Accept notification",
           "mailtool_priority" => 10,
           "mailtool_recipients" => "somedec:yes",
           "subject" => "[%CONFSHORTNAME%] Accepted submission #%NUMBER% %TITLEHINT%",
           "body" => "Dear author(s),

The %CONFNAME% program committee is delighted to inform you that your submission #%NUMBER% has been accepted to appear in the conference.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%&%AUTHORVIEWCAPABILITY%)%

Your paper was one of %NUMACCEPTED% accepted out of %NUMSUBMITTED% submissions. Congratulations!

Reviews and comments on your paper are appended to this email. The submissions site also has the paper's reviews and comments, as well as more information about review scores.%LOGINNOTICE%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%

%REVIEWS%
%COMMENTS%\n"),

     "rejectnotify" =>
     array("mailtool_name" => "Reject notification",
           "mailtool_priority" => 11,
           "mailtool_recipients" => "somedec:no",
           "subject" => "[%CONFSHORTNAME%] Rejected submission #%NUMBER% %TITLEHINT%",
           "body" => "Dear author(s),

The %CONFNAME% program committee is sorry to inform you that your submission #%NUMBER% was rejected, and will not appear in the conference.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%&%AUTHORVIEWCAPABILITY%)%

%NUMACCEPTED% papers were accepted out of %NUMSUBMITTED% submissions.

Reviews and comments on your paper are appended to this email. The submissions site also has the paper's reviews and comments, as well as more information about review scores.%LOGINNOTICE%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%

%REVIEWS%
%COMMENTS%\n"),

     "commentnotify" =>
     array("subject" => "[%CONFSHORTNAME%] Comment for #%NUMBER% %TITLEHINT%",
           "body" => "A comment for %CONFNAME% submission #%NUMBER% has been posted. For the most up-to-date comments, or to unsubscribe from email notification, see the submission site.

        Site: %URL(paper, p=%NUMBER%)%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%

%COMMENTS%\n"),

     "responsenotify" =>
     array("subject" => "[%CONFSHORTNAME%] Response for #%NUMBER% %TITLEHINT%",
           "body" => "The authors' response for %CONFNAME% submission #%NUMBER% is available as shown below. The authors may still update their response; for the most up-to-date version, or to turn off notification emails, see the submission site.

        Site: %URL(paper, p=%NUMBER%)%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%

%COMMENTS%\n"),

     "responsedraftnotify" =>
     array("subject" => "[%CONFSHORTNAME%] Draft response for #%NUMBER% %TITLEHINT%",
           "body" => "The draft authors' response for %CONFNAME% submission #%NUMBER% has been updated as shown below. This response has not yet been submitted to reviewers. For the most up-to-date version, or to turn off notification emails, see the submission site.

        Site: %URL(paper, p=%NUMBER%)%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%

%COMMENTS%\n"),

     "finalsubmitnotify" =>
     array("subject" => "[%CONFSHORTNAME%] Updated final version #%NUMBER% %TITLEHINT%",
           "body" => "The final version for %CONFNAME% submission #%NUMBER% has been updated. The authors may still be able make updates; for the most up-to-date version, or to turn off notification emails, see the submission site.

        Site: %URL(paper, p=%NUMBER%)%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "genericmailtool" =>
     array("mailtool_name" => "Generic",
           "mailtool_pc" => true,
           "mailtool_priority" => 0,
           "mailtool_recipients" => "s",
           "subject" => "[%CONFSHORTNAME%] Submission #%NUMBER% %TITLEHINT%",
           "body" => "Dear %NAME%,

Your message here.

       Title: %TITLE%
        Site: %URL(paper, p=%NUMBER%)%

Use the link below to sign in to the site.

%LOGINURL%

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "reviewremind" =>
     array("mailtool_name" => "Review reminder",
           "mailtool_pc" => true,
           "mailtool_priority" => 20,
           "mailtool_recipients" => "uncrev",
           "subject" => "[%CONFSHORTNAME%] Review reminder for submission #%NUMBER% %TITLEHINT%",
           "body" => "Dear %NAME%,

This is a reminder to finish your review for %CONFNAME% submission #%NUMBER%. %IF(REVIEWDEADLINE)% Reviews are requested by %REVIEWDEADLINE%. %ENDIF% If you are unable to complete the review, please decline the review using the site or contact the person who requested the review directly.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%)%

Use the link below to sign in to the site.

%LOGINURL%

Thank you for your help -- we appreciate that reviewing is hard work.

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "myreviewremind" =>
     array("mailtool_name" => "Personalized review reminder",
           "mailtool_pc" => true,
           "mailtool_priority" => 21,
           "mailtool_recipients" => "uncmyextrev",
           "mailtool_search_type" => "req",
           "subject" => "[%CONFSHORTNAME%] Review reminder for submission #%NUMBER% %TITLEHINT%",
           "body" => "Dear %NAME%,

This is a reminder from %REQUESTERCONTACT% to finish your review for %CONFNAME% submission #%NUMBER%.%IF(REVIEWDEADLINE)% Reviews are requested by %REVIEWDEADLINE%. %ENDIF%If you are unable to complete the review, please decline the review using the site or contact %REQUESTERNAME% directly.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%)%

Use the link below to sign in to the site.

%LOGINURL%

Thank you for your help -- we appreciate that reviewing is hard work.

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "newpcrev" =>
     array("mailtool_name" => "Review assignment notification",
           "mailtool_recipients" => "newpcrev",
           "subject" => "[%CONFSHORTNAME%] New review assignments",
           "body" => "Dear %NAME%,

You have been assigned new reviews for %CONFNAME%. %IF(REVIEWDEADLINE)% Reviews are requested by %REVIEWDEADLINE%.%ENDIF%

             Site: %URL%/
     Your reviews: %URL(search, q=re:me)%
  New assignments: %NEWASSIGNMENTS%

Thank you for your help -- we appreciate that reviewing is hard work.

Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "registerpaper" =>
     array("subject" => "[%CONFSHORTNAME%] Registered #%NUMBER% %TITLEHINT%",
           "body" => "Submission #%PAPER% has been registered at the %CONFNAME% site.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%&%AUTHORVIEWCAPABILITY%)%

%NOTES%%IF(REASON)%An administrator provided the following reason for this registration: %REASON%

%ELSEIF(ADMINUPDATE)%An administrator performed this registration.

%ENDIF%Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "updatepaper" =>
     array("subject" => "[%CONFSHORTNAME%] Updated #%NUMBER% %TITLEHINT%",
           "body" => "Submission #%PAPER% has been updated at the %CONFNAME% site.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%&%AUTHORVIEWCAPABILITY%)%

%NOTES%%IF(REASON)%An administrator provided the following reason for this update: %REASON%

%ELSEIF(ADMINUPDATE)%An administrator performed this update.

%ENDIF%Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "submitpaper" =>
     array("subject" => "[%CONFSHORTNAME%] Submitted #%NUMBER% %TITLEHINT%",
           "body" => "Submission #%PAPER% has been submitted for review at the %CONFNAME% site.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%&%AUTHORVIEWCAPABILITY%)%

%NOTES%%IF(REASON)%An administrator provided the following reason for this update: %REASON%

%ELSEIF(ADMINUPDATE)%An administrator performed this update.

%ENDIF%Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n"),

     "submitfinalpaper" =>
     array("subject" => "[%CONFSHORTNAME%] Updated accepted #%NUMBER% %TITLEHINT%",
           "body" => "Accepted submission #%PAPER% has been updated at the %CONFNAME% submissions site.

       Title: %TITLE%
 %_(Authors, 11)%: %OPT(AUTHORS)%
        Site: %URL(paper, p=%NUMBER%&%AUTHORVIEWCAPABILITY%)%

%NOTES%%IF(REASON)%An administrator provided the following reason for this update: %REASON%

%ELSEIF(ADMINUPDATE)%An administrator performed this update.

%ENDIF%Contact %ADMIN% with any questions or concerns.

%SIGNATURE%\n")

);
