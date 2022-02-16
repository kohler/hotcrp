<?php
// t_fixcollaborators.php -- HotCRP tests
// Copyright (c) 2006-2022 Eddie Kohler; see LICENSE.

class FixCollaborators_Tester {
    function test_quoted_list() {
        xassert_eqq(AuthorMatcher::fix_collaborators("\"University of California, San Diego\", \"University of California, Los Angeles\", \"David Culler (University of California, Berkeley)\", \"Kamin Whitehouse (University of Virginia)\", \"Yuvraj Agarwal (Carnegie Mellon University)\", \"Mario Berges (Carnegie Mellon University)\", \"Joern Ploennigs (IBM)\", \"Mikkel Baun Kjaergaard (Southern Denmark University)\", \"Donatella Sciuto (Politecnico di Milano)\", \"Santosh Kumar (University of Memphis)\""),
            "All (University of California, San Diego)
All (University of California, Los Angeles)
David Culler (University of California, Berkeley)
Kamin Whitehouse (University of Virginia)
Yuvraj Agarwal (Carnegie Mellon University)
Mario Berges (Carnegie Mellon University)
Joern Ploennigs (IBM)
Mikkel Baun Kjaergaard (Southern Denmark University)
Donatella Sciuto (Politecnico di Milano)
Santosh Kumar (University of Memphis)");
    }

    function test_missing_all() {
        xassert_eqq(AuthorMatcher::fix_collaborators("University of California, San Diego
University of California, Los Angeles
David Culler (University of California, Berkeley)
Kamin Whitehouse (University of Virginia)
Yuvraj Agarwal (Carnegie Mellon University)
Mario Berges (Carnegie Mellon University)
Joern Ploennigs (IBM)
Mikkel Baun Kjaergaard (Southern Denmark University)
Donatella Sciuto (Politecnico di Milano)
Santosh Kumar (University of Memphis)\n"),
            "All (University of California, San Diego)
All (University of California, Los Angeles)
David Culler (University of California, Berkeley)
Kamin Whitehouse (University of Virginia)
Yuvraj Agarwal (Carnegie Mellon University)
Mario Berges (Carnegie Mellon University)
Joern Ploennigs (IBM)
Mikkel Baun Kjaergaard (Southern Denmark University)
Donatella Sciuto (Politecnico di Milano)
Santosh Kumar (University of Memphis)");
    }

    function test_notes() {
        xassert_eqq(AuthorMatcher::fix_collaborators("University of Wisconsin-Madison
AMD Research
University of Illinois at Urbana-Champaign
Sarita Adve (UIUC) - PhD advisor
Karu Sankaralingam (Wisconsin) - MS Advisor
Rakesh Komuravelli (Qualcomm) - recent collaborator (last publication together: 9/2016 (ISPASS))
Tony Gutierrez (AMD Research) – recent collaborator (last publication: 2/2018)
Brad Beckmann (AMD Research) – recent collaborator (last publication: 2/2018)
Alex Dutu (AMD Research) – recent collaborator (last publication: 2/2018)
Joe Gross (Samsung Research) – recent collaborator (last publication: 2/2018)
John Kalamatianos (AMD Research) – recent collaborator (last publication: 2/2018)
Onur Kayiran (AMD Research) – recent collaborator (last publication: 2/2018)
Michael LeBeane (AMD Research) – recent collaborator (last publication: 2/2018)
Matthew Poremba (AMD Research) – recent collaborator (last publication: 2/2018)
Brandon Potter (AMD Research) – recent collaborator (last publication: 2/2018)
Sooraj Puthoor (AMD Research) – recent collaborator (last publication: 2/2018)
Mark Wyse (Washington) – recent collaborator (last publication: 2/2018)
Jieming Yin (AMD Research) – recent collaborator (last publication: 2/2018)
Xianwei Zhang (AMD Research) – recent collaborator (last publication: 2/2018)
Akshay Jain (Qualcomm) – recent collaborator (last publication: 2/2018)
Tim Rogers (Purdue) – recent collaborator (last publication: 2/2018)
"),
            "All (University of Wisconsin-Madison)
All (AMD Research)
All (University of Illinois at Urbana-Champaign)
Sarita Adve (UIUC) - PhD advisor
Karu Sankaralingam (Wisconsin) - MS Advisor
Rakesh Komuravelli (Qualcomm) - recent collaborator (last publication together: 9/2016 (ISPASS))
Tony Gutierrez (AMD Research) - recent collaborator (last publication: 2/2018)
Brad Beckmann (AMD Research) - recent collaborator (last publication: 2/2018)
Alex Dutu (AMD Research) - recent collaborator (last publication: 2/2018)
Joe Gross (Samsung Research) - recent collaborator (last publication: 2/2018)
John Kalamatianos (AMD Research) - recent collaborator (last publication: 2/2018)
Onur Kayiran (AMD Research) - recent collaborator (last publication: 2/2018)
Michael LeBeane (AMD Research) - recent collaborator (last publication: 2/2018)
Matthew Poremba (AMD Research) - recent collaborator (last publication: 2/2018)
Brandon Potter (AMD Research) - recent collaborator (last publication: 2/2018)
Sooraj Puthoor (AMD Research) - recent collaborator (last publication: 2/2018)
Mark Wyse (Washington) - recent collaborator (last publication: 2/2018)
Jieming Yin (AMD Research) - recent collaborator (last publication: 2/2018)
Xianwei Zhang (AMD Research) - recent collaborator (last publication: 2/2018)
Akshay Jain (Qualcomm) - recent collaborator (last publication: 2/2018)
Tim Rogers (Purdue) - recent collaborator (last publication: 2/2018)");
    }

    function test_stupid_1() {
        xassert_eqq(AuthorMatcher::fix_collaborators("T. Arselins (LLNL) S. Bagchi (Purdue) D. Bailey (LBL) D. Bailey (Williams) A. Baker (Colorado) D. Beckingsale (U. Warwick) A. Bhatele (LLNL) B. Bihari (LLNL) S. Biswas (LLNL) D. Boehme (LLNL) P.-T. Bremer (LLNL) G. Bronevetsky (LLNL) L. Carrington (SDSC) A. Cook (LLNL) B. de Supinski (LLNL) E. Draeger (LLNL) E. Elnozahy (IBM) M. Fagan (Rice) R. Fowler (UNC) S. Futral (LLNL) J. Galarowicz (Krell) J. Glosli (LLNL) J. Gonzalez (BSC) G. Gopalakrishnan (Utah) W. Gropp (Illinois) J. Gunnels (IBM)", 1),
            "T. Arselins (LLNL)
S. Bagchi (Purdue)
D. Bailey (LBL)
D. Bailey (Williams)
A. Baker (Colorado)
D. Beckingsale (U. Warwick)
A. Bhatele (LLNL)
B. Bihari (LLNL)
S. Biswas (LLNL)
D. Boehme (LLNL)
P.-T. Bremer (LLNL)
G. Bronevetsky (LLNL)
L. Carrington (SDSC)
A. Cook (LLNL)
B. de Supinski (LLNL)
E. Draeger (LLNL)
E. Elnozahy (IBM)
M. Fagan (Rice)
R. Fowler (UNC)
S. Futral (LLNL)
J. Galarowicz (Krell)
J. Glosli (LLNL)
J. Gonzalez (BSC)
G. Gopalakrishnan (Utah)
W. Gropp (Illinois)
J. Gunnels (IBM)");
    }

    function test_commas() {
        xassert_eqq(AuthorMatcher::fix_collaborators("Sal Stolfo, Guofei Gu, Manos Antonakakis, Roberto Perdisci, Weidong Cui, Xiapu Luo, Rocky Chang, Kapil Singh, Helen Wang, Zhichun Li, Junjie Zhang, David Dagon, Nick Feamster, Phil Porras."),
            "Sal Stolfo
Guofei Gu
Manos Antonakakis
Roberto Perdisci
Weidong Cui
Xiapu Luo
Rocky Chang
Kapil Singh
Helen Wang
Zhichun Li
Junjie Zhang
David Dagon
Nick Feamster
Phil Porras.");
    }

    function test_tabs() {
        xassert_eqq(AuthorMatcher::fix_collaborators("UTEXAS
UT Austin
Doe Hyun Yoon \t(Google)
Evgeni Krimer \t(NVIDIA)
Min Kyu Jeong\t(Oracle Labs)
Minsoo Rhu\t(NVIDIA)
Michael Sullivan\t(NVIDIA)"), "All (UTEXAS)
All (UT Austin)
Doe Hyun Yoon (Google)
Evgeni Krimer (NVIDIA)
Min Kyu Jeong (Oracle Labs)
Minsoo Rhu (NVIDIA)
Michael Sullivan (NVIDIA)");
    }

    function test_tabs_2() {
        xassert_eqq(AuthorMatcher::fix_collaborators("Vishal Misra\t\tColumbia University
Columbia
Francois Baccelli\tINRIA-ENS\t,\t
Guillaume Bichot\tThomson\t\t
Bartlomiej Blaszczyszyn\tInria-Ens\t\t
Jeffrey Bloom\tThomson Research\t\t
Guillaume Boisson\tThomson\t\t
Olivier Bonaventure\tUniversitÈ catholique de Louvain\t\t
Charles Bordenave\tINRIA / ENS\t\t\n"), "Vishal Misra (Columbia University)
All (Columbia)
Francois Baccelli (INRIA-ENS)
Guillaume Bichot (Thomson)
Bartlomiej Blaszczyszyn (Inria-Ens)
Jeffrey Bloom (Thomson Research)
Guillaume Boisson (Thomson)
Olivier Bonaventure (UniversitÈ catholique de Louvain)
Charles Bordenave (INRIA / ENS)");
    }

    function test_quotes() {
        xassert_eqq(AuthorMatcher::fix_collaborators("\"Princeton University\"
\"NVIDIA\"
\"Google\"
\"Microsoft Research\"
Agarwal, Anuradha (MIT)
Amarasinghe, Saman (MIT)
Andoni, Alexandr (Columbia)
Arvind (MIT)
Badam, Anirudh (Microsoft)
Banerjee, Kaustav (UCSB)
Beckmann, Nathan (CMU)
Bhanja, Sanjukta (USF)
Carbin, Michael (MIT)
Chakrabarti, Chaitali (ASU)
Chandrakasan, Anantha (MIT)
Chang, Mung (Perdue)
Devadas, Srini (MIT)
Doppa, Jana (Washington State U.)
Elmroth, Erik (Umea University)
Fletcher, Chris (UIUC)
Freedman, Michael (Princeton)
Gu, Tian (MIT)
Heo, Deuk (Washington State U.)
Hoffmann, Henry (University of Chicago)
Hu, Juejun (MIT)
Kalyanaraman, Ananth (Washington State U.)
Kim, Martha (Columbia)
Kim, Nam Sung (UIUC)
Klein, Cristian (Umea University)
Lee, Walter (Google)
Li, Hai (Duke)
Liu, Jifeng (Dartmouth)
Lucia, Brandon (CMU)
Marculescu, Diana (CMU)
Marculescu, Radu (CMU)
Martonosi, Margaret (Princeton)
Miller, Jason (MIT)
Mittal, Prateek (Princeton)
Ogras, Umit (ASU)
Ozev, Sule (ASU)
Pande, Partha (Washington State U.)
Rand, Barry (Princeton)
Sanchez, Daniel (MIT)
Shepard, Kenneth (Columbia)
Sherwood, Timothy (UCSB)
Solar-Lezama, Armando (MIT)
Srinivasa, Sidhartha (CMU)
Strauss, Karen (Microsoft)
Sun, Andy (LaXense)
Taylor, Michael (University of Washington)
Wagh, Sameer Wagh (Princeton)
Yeung, Donald (University of Maryland)
Zheng, Liang (Princeton)
Batten, Christopher (Cornell)
Lam, Patrick (University of Waterloo)"),
            "All (Princeton University)
All (NVIDIA)
All (Google)
All (Microsoft Research)
Agarwal, Anuradha (MIT)
Amarasinghe, Saman (MIT)
Andoni, Alexandr (Columbia)
Arvind (MIT)
Badam, Anirudh (Microsoft)
Banerjee, Kaustav (UCSB)
Beckmann, Nathan (CMU)
Bhanja, Sanjukta (USF)
Carbin, Michael (MIT)
Chakrabarti, Chaitali (ASU)
Chandrakasan, Anantha (MIT)
Chang, Mung (Perdue)
Devadas, Srini (MIT)
Doppa, Jana (Washington State U.)
Elmroth, Erik (Umea University)
Fletcher, Chris (UIUC)
Freedman, Michael (Princeton)
Gu, Tian (MIT)
Heo, Deuk (Washington State U.)
Hoffmann, Henry (University of Chicago)
Hu, Juejun (MIT)
Kalyanaraman, Ananth (Washington State U.)
Kim, Martha (Columbia)
Kim, Nam Sung (UIUC)
Klein, Cristian (Umea University)
Lee, Walter (Google)
Li, Hai (Duke)
Liu, Jifeng (Dartmouth)
Lucia, Brandon (CMU)
Marculescu, Diana (CMU)
Marculescu, Radu (CMU)
Martonosi, Margaret (Princeton)
Miller, Jason (MIT)
Mittal, Prateek (Princeton)
Ogras, Umit (ASU)
Ozev, Sule (ASU)
Pande, Partha (Washington State U.)
Rand, Barry (Princeton)
Sanchez, Daniel (MIT)
Shepard, Kenneth (Columbia)
Sherwood, Timothy (UCSB)
Solar-Lezama, Armando (MIT)
Srinivasa, Sidhartha (CMU)
Strauss, Karen (Microsoft)
Sun, Andy (LaXense)
Taylor, Michael (University of Washington)
Wagh, Sameer Wagh (Princeton)
Yeung, Donald (University of Maryland)
Zheng, Liang (Princeton)
Batten, Christopher (Cornell)
Lam, Patrick (University of Waterloo)");
    }

    function test_note_no_dash() {
        xassert_eqq(AuthorMatcher::fix_collaborators("University of Illinois, Urbana-Champaign
Marcelo Cintra  (Intel)  advisor/student
Michael Huang   (University of Rochester)  advisor/student
Jose Martinez   (Cornell University)  advisor/student
Anthony Nguyen  (Intel Corporation) advisor/student\n"),
            "All (University of Illinois, Urbana-Champaign)
Marcelo Cintra (Intel) - advisor/student
Michael Huang (University of Rochester) - advisor/student
Jose Martinez (Cornell University) - advisor/student
Anthony Nguyen (Intel Corporation) - advisor/student");
    }

    function test_none() {
        xassert_eqq(AuthorMatcher::fix_collaborators("none\n"), "None");
        xassert_eqq(AuthorMatcher::fix_collaborators("none.\n"), "None");
        xassert_eqq(AuthorMatcher::fix_collaborators("NONE.\n"), "None");
    }

    function test_miscellany() {
        xassert_eqq(AuthorMatcher::fix_collaborators("G.-Y. (Ken) Lueh"),
            "G.-Y. (Ken) Lueh (unknown)");
        xassert_eqq(AuthorMatcher::fix_collaborators("University of New South Wales (UNSW)"), "All (University of New South Wales)");
        xassert_eqq(AuthorMatcher::fix_collaborators("Gennaro Parlato, University of Southampton, UK"), "Gennaro Parlato (University of Southampton, UK)");
        xassert_eqq(AuthorMatcher::fix_collaborators("G.-Y. (Ken (Butt)) Lueh"), "G.-Y. (Ken (Butt)) Lueh (unknown)");
        xassert_eqq(AuthorMatcher::fix_collaborators("G.-Y. (Ken (Butt)) Lueh (France Telecom)"), "G.-Y. (Ken (Butt)) Lueh (France Telecom)");
        xassert_eqq(AuthorMatcher::fix_collaborators("All (Fucktown, Fuckville, Fuck City, Fuck Prefecture, Fuckovia)"), "All (Fucktown, Fuckville, Fuck City, Fuck Prefecture, Fuckovia)");
        xassert_eqq(AuthorMatcher::fix_collaborators("Sriram Rajamani (MSR), Aditya Nori (MSR), Akash Lal (MSR), Ganesan Ramalingam (MSR)"),
            "Sriram Rajamani (MSR)
Aditya Nori (MSR)
Akash Lal (MSR)
Ganesan Ramalingam (MSR)");
        xassert_eqq(AuthorMatcher::fix_collaborators("University of Southern California (USC), Universidade de Brasilia (UnB)", 1),
            "All (University of Southern California)
Universidade de Brasilia (UnB)");
        xassert_eqq(AuthorMatcher::fix_collaborators("Schur, Lisa"), "Schur, Lisa");
        xassert_eqq(AuthorMatcher::fix_collaborators("Lisa Schur, Lisa"), "Lisa Schur (Lisa)");
        xassert_eqq(AuthorMatcher::fix_collaborators("Danfeng(Daphne)Yao; Virginia Tech, USA", 1), "Danfeng(Daphne)Yao (Virginia Tech, USA)");
        xassert_eqq(AuthorMatcher::fix_collaborators("Danfeng(Daphne)Yao (Virginia Tech, USA)"), "Danfeng(Daphne)Yao (Virginia Tech, USA)");
        xassert_eqq(AuthorMatcher::fix_collaborators("Danfeng(Daphne)Yao (Virginia Tech, USA)", 1), "Danfeng(Daphne)Yao (Virginia Tech, USA)");
    }
}
