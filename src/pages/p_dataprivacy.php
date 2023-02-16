<?php
// pages/p_home.php -- HotCRP home page
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class Dataprivacy_Page {
    /** @var Conf
     * @readonly */
    public $conf;
    /** @var Contact
     * @readonly */
    public $user;
    /** @var int */
    private $_nh2 = 0;
    /** @var bool */
    private $_has_sidebar = false;
    private $_in_reviews;
    /** @var ?list<ReviewField> */
    private $_rfs;
    /** @var int */
    private $_r_num_submitted = 0;
    /** @var int */
    private $_r_num_needs_submit = 0;
    /** @var list<int> */
    private $_r_unsubmitted_rounds;
    /** @var list<int|float> */
    private $_rf_means;
    private $_tokens_done;

    function __construct(Contact $user) {
        $this->conf = $user->conf;
        $this->user = $user;
    }

    function print_head(Contact $user, Qrequest $qreq, $gx) {
        if ($user->is_empty()) {
            $qreq->print_header("Sign in", "home");
        } else {
            $qreq->print_header("Home", "home");
        }
        if ($qreq->signedout && $user->is_empty()) {
            $user->conf->success_msg("<0>You have been signed out of the site");
        }
        $gx->push_print_cleanup("__footer");
        echo '<noscript><div class="msg msg-error"><strong>This site requires JavaScript.</strong> Your browser does not support JavaScript.<br><a href="https://github.com/kohler/hotcrp/">Report bad compatibility problems</a></div></noscript>', "\n";
        if ($user->privChair) {
            echo '<div id="msg-clock-drift" class="homegrp hidden"></div>';
        }
    }

    function print_content(Contact $user, Qrequest $qreq, $gx) {
        echo '<main class="dataprivacy-content">';
        ob_start();
        $gx->print_group("dataprivacy/sidebar");
        if (($t = ob_get_clean()) !== "") {
            echo '<nav class="home-sidebar">', $t, '</nav>';
            $this->_has_sidebar = true;
        }
        echo '<div class="imprint-main">';
        echo <<<ENDHTML

            <h2>1. Data protection at a glance</h2>
            <h3>General information</h3> <p>The following information provides a simple overview of what happens to your personal data when you visit this website. Personal data is all data with which you can be personally identified. Detailed information on the subject of data protection can be found in our data protection declaration below this text.</p>
            <h3>Data collection on this website</h3> <h4>Who is responsible for data collection on this website?</h4> <p>The data processing on this website is carried out by the website operator. You can find their contact details in the section "Notice on the responsible body" in this data protection declaration.</p>
            <h4>How do we collect your data?</h4> <p>Data is collected automatically or with your consent by our IT systems when you visit the website. This is primarily technical data (e.g. internet browser, operating system or time of the page view). This data is collected automatically as soon as you enter this website.</p>
            <h4>What do we use your data for?</h4> <p>Some of the data is collected to ensure that the website is provided correctly. Other data can be used to analyze your user behavior.</p>
            <h4>What are your rights regarding your data?</h4> <p>You have the right to free information about the origin, recipient and purpose of your stored personal data at any time to obtain. You also have the right to request the correction or deletion of this data. If you have given your consent to data processing, you can revoke this consent at any time for the future. You also have the right, under certain circumstances, to request that the processing of your personal data be restricted. You also have the right to lodge a complaint with the responsible supervisory authority.</p>
            <p>You can contact us at any time with regard to this and other questions on the subject of data protection.</p>
            
            <h2>2. Hosting</h2>
            <p>We host the content of our website with the following provider:</p>
            <h3>External Hosting</h3>
            <p>This website is hosted externally. The personal data collected on this website is stored on the hoster's servers. This can be IP addresses, contact requests, meta and communication data, contract data, contact details, names, website access and other data generated via a website.</p>
            <p>The external hosting is for the purpose of fulfilling the contract with our potential and existing customers (Art. 6 Para. 1 lit. b GDPR) and in the interest of a secure, fast and efficient provision of our online offer by a professional provider (Art. 6 Para. 1 lit. f GDPR). If a corresponding consent was requested, the processing takes place exclusively on the basis of Art. 6 Para. 1 lit. a GDPR and § 25 Para B. TTDPA, insofar as the consent includes the storage of cookies or access to information in the user's end device (e.g. device fingerprinting) within the meaning of the TTDPA. The consent can be revoked at any time.</p>
            <p>Our host(s) will only process your data to the extent necessary to fulfill their performance obligations and follow our instructions in relation to this data.</p>
            <p>We use the following host:</p>
            <p>netcup GmbH<br />Daimlerstraße 25<br />76185 Karlsruhe<br />Germany</p>
            
            <h2>3. General information and mandatory information</h2>        
            <h3>Data protection</h3> <p>The operators of these pages take the protection of your personal data very seriously. We treat your personal data confidentially and in accordance with the legal data protection regulations and this data protection declaration.</p>
            <p>When you use this website, various personal data are collected. Personal data is data with which you can be personally identified. This data protection declaration explains what data we collect and what we use it for. It also explains how and for what purpose this happens.</p>
            <p>We would like to point out that data transmission on the Internet (e.g. when communicating by e-mail) can have security gaps. A complete protection of the data against access by third parties is not possible.</p>
            <h3>Note on the responsible body</h3> <p>The body responsible for data processing on this website is the person responsible for the content of this website, mentioned in the <a href="imprint.php">imprint</a>.</p>
            <h3>Right to object to data collection in special cases and to direct advertising (Art. 21 GDPR)</h3> <p>IF THE DATA PROCESSING IS BASED ON ART. 6 ABS. 1 LIT. E OR F GDPR, YOU HAVE THE RIGHT TO OBJECT TO THE PROCESSING OF YOUR PERSONAL DATA AT ANY TIME FOR REASONS ARISING FROM YOUR PARTICULAR SITUATION; THIS ALSO APPLIES TO PROFILING BASED ON THESE PROVISIONS. THE RESPECTIVE LEGAL BASIS ON WHICH A PROCESSING IS BASED CAN BE FOUND IN THIS DATA PRIVACY POLICY. IF YOU OBJECT, WE WILL NO LONGER PROCESS YOUR CONCERNED PERSONAL DATA UNLESS WE CAN PROVE COMPREHENSIVE GROUNDS FOR THE PROCESSING THAT OVERRIDE YOUR INTERESTS, RIGHTS AND FREEDOM OBJECTION ACCORDING TO ARTICLE 21 (1) GDPR).</p>
            <p>IF YOUR PERSONAL DATA IS PROCESSED IN ORDER TO USE DIRECT ADVERTISING, YOU HAVE THE RIGHT TO OBJECT AT ANY TIME TO THE PROCESSING OF YOUR PERSONAL DATA FOR THE PURPOSES OF SUCH ADVERTISING; THIS ALSO APPLIES TO PROFILING TO THE EXTENT RELATED TO SUCH DIRECT ADVERTISING. IF YOU OBJECT, YOUR PERSONAL DATA WILL NO LONGER BE USED FOR DIRECT ADVERTISING PURPOSES (OBJECTION ACCORDING TO ART. 21 (2) GDPR).</p>
            <h3>Right of appeal to the competent supervisory authority</h3> <p>In the event of violations of the GDPR, those affected have the right to appeal to a supervisory authority, in particular in the member state of their habitual residence, their place of work or the place of the alleged violation. The right to lodge a complaint is without prejudice to other administrative or judicial remedies.</p>
            <h3>Right to data transferability</h3> <p>You have the right to have data that we process automatically on the basis of your consent or in fulfillment of a contract handed over to you or to a third party in a common, machine-readable format. If you request the direct transfer of the data to another person responsible, this will only be done to the extent that it is technically feasible.</p>
            <h3>Information, deletion and correction</h3> <p>Within the scope of the applicable legal provisions, you have the right to free information about your stored personal data, its origin and recipient and the purpose of the data processing and, if applicable, a right to Correction or deletion of this data. You can contact us at any time with regard to this and other questions on the subject of personal data.</p>
            <h3>Right to restriction of processing</h3> <p>You have the right to request that the processing of your personal data be restricted. You can contact us at any time for this. The right to restriction of processing exists in the following cases:</p>
            <ul> <li>If you dispute the accuracy of your personal data stored by us, we usually need time to check this. For the duration of the examination, you have the right to demand the restriction of the processing of your personal data.</li> <li>If the processing of your personal data happened/is happening unlawfully, you can demand the restriction of the data processing instead of deletion.</li> <li>If we no longer need your personal data, but you need them to exercise, defend or assert legal claims, you have the right to demand that the processing of your personal data be restricted instead of being deleted.</li> <li>If you have lodged an objection in accordance with Art. 21 (1) GDPR, your interests and ours must be weighed up. As long as it has not yet been determined whose interests prevail, you have the right to demand that the processing of your personal data be restricted.</li> </ul> <p>If you have restricted the processing of your personal data, this data -  apart from their storage - may only processed with your consent or to assert, exercise or defend legal claims or to protect the rights of another natural or legal person or for reasons of important public interest of the European Union or a member state.</p>
            
            <h2>4. Data collection on this website</h2>
            <h3>Cookies</h3><p>Our website uses so-called "cookies". Cookies are small data packages and do not damage your end device. They are stored on your end device either temporarily for the duration of a session (session cookies) or permanently (permanent cookies). Session cookies are automatically deleted after your visit. Permanent cookies remain stored on your end device until you delete them yourself or until they are automatically deleted by your web browser.</p>
            <p>Cookies can come from us (first-party cookies) or from third-party companies (so-called third-party cookies). Third-party cookies enable the integration of certain services from third-party companies within websites (e.g. cookies for processing payment services).</p>
            <p>Cookies have different functions. Numerous cookies are technically necessary because certain website functions would not work without them (e.g. the shopping cart function or the display of videos). Other cookies can be used to evaluate user behavior or for advertising purposes.</p>
            <p>Cookies that are used to carry out the electronic communication process, to provide certain functions you want (e.g. for the shopping cart function) or to optimize the Website (e.g. cookies for measuring the web audience) are required (necessary cookies) are stored on the basis of Article 6 (1) (f) GDPR, unless another legal basis is specified. The website operator has a legitimate interest in the storage of necessary cookies for the technically error-free and optimized provision of its services. If consent to the storage of cookies and comparable recognition technologies was requested, processing takes place exclusively on the basis of this consent (Art. 6 Para. 1 lit. a GDPR and § 25 Para. 1 TTDPA); Consent can be revoked at any time.</p>
            <p>You can set your browser so that you are informed about the setting of cookies and only allow cookies in individual cases, exclude the acceptance of cookies for certain cases or in general and automatic deletion enable cookies when closing the browser. If cookies are deactivated, the functionality of this website may be restricted.</p>
            <p>You can find out which cookies and services are used on this website from this data protection declaration.</p>
            <h3>Inquiry by e-mail, telephone or fax</h3>
            <p>If you contact us by e-mail, telephone or fax, your inquiry including all resulting personal data (name, enquiry) is stored and processed by us for the purpose of processing your request. We do not pass on this data without your consent.</p>
            <p>The processing of this data takes place on the basis of Art 6 Para. 1 lit. b GDPR, if your request is related to the fulfillment of a contract or is necessary to carry out pre-contractual measures. In all other cases, the processing is based on our legitimate interest in the effective processing of inquiries addressed to us (Art. 6 Para. 1 lit. f GDPR) or on your consent (Art. 6 Para. 1 lit. a GDPR) if this was queried; the consent can be revoked at any time.</p>
            <p>The data you send to us via contact requests will remain with us until you request deletion, revoke your consent to storage or the purpose for data storage no longer applies (e.g. after your request has been processed). Mandatory legal provisions - in particular legal retention periods - remain unaffected.</p>
            <h3>Data collection for paper review purposes</h3>
            <p>As this system is designed for collecting and reviewing papers for the conference, we collect personal data on authors of submitted papers (name and contact data, as they would be published if the paper is accepted, as well as scientific conflicts with reviewers) and the reviewers (name and contact data, scientific conflicts). Reviewer data are published on the public reviewer list and shared with the authors in order to enable them to select conflicts. Author data of unpublished papers are not shared with anyone, except after accepting a paper for publication, the reviewers will be able to see the authors of the paper as it will be de-anonymized with publication. During the review phase all papers will only be shared with reviewers in an anonymous way, also non-accepted papers will not be de-anonymized. After selecting papers for acceptance, the data on accepted papers (author names, contact data) will be shared with ACM (1601 Broadway, 10th Floor, New York, NY 10019-7434, United States) for publication. When submitting their paper to the conference, authors accept that these data will be shared with ACM for publication purposes, keeping in mind that the United States are not considered as safe third party country under EU data protection laws / GDPR.
            
            <h2>5. Plugins and Tools</h2>
            <p>No external plugins and/or tools are used, data are not given to external services except mentioned before in part 4</p>
            <p></p>
            <p><em>Source: based on the generator by <a href="https://www.e-recht24.de">https://www.e-recht24.de</a>, translated by Google Translate</em></p>
        

            ENDHTML;
        echo "</div></main>\n";
    }
}
