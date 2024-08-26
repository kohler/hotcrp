<?php

// This list of countries taken from Amazon.com's address entries, 2024
// with adjustments from the ISO list of country codes
// largely limited to the list of countries on Britannica
class Countries {
    public static $selector_overrides = [
        "BS" => "Bahamas, The",
        "BN" => "Brunei Darussalam",
        "CD" => "Congo, Democratic Republic of the",
        "CG" => "Congo, Republic of the",
        "GM" => "Gambia, The",
        "KP" => "Korea, North (The Democratic People's Republic of)",
        "KR" => "Korea, South (The Republic of)",
        "MK" => "Macedonia, Republic of North",
        "FM" => "Micronesia, Federated States of",
        "MD" => "Moldova, Republic of",
        "RU" => "Russian Federation",
        "TZ" => "Tanzania, United Republic of"
    ];

    public static $map = [
        "AF" => "Afghanistan",
        // "AX" => "Åland Islands",
        "AL" => "Albania",
        "DZ" => "Algeria",
        // "AS" => "American Samoa",
        "AD" => "Andorra",
        "AO" => "Angola",
        // "AI" => "Anguilla",
        // "AQ" => "Antarctica",
        "AG" => "Antigua and Barbuda",
        "AR" => "Argentina",
        "AM" => "Armenia",
        // "AW" => "Aruba",
        "AU" => "Australia",
        "AT" => "Austria",
        "AZ" => "Azerbaijan",
        "BS" => "Bahamas",
        "BH" => "Bahrain",
        "BD" => "Bangladesh",
        "BB" => "Barbados",
        "BY" => "Belarus",
        "BE" => "Belgium",
        "BZ" => "Belize",
        "BJ" => "Benin",
        // "BM" => "Bermuda",
        "BT" => "Bhutan",
        "BO" => "Bolivia",
        // "BQ" => "Bonaire, Sint Eustatius and Saba",
        "BA" => "Bosnia and Herzegovina",
        "BW" => "Botswana",
        // "BV" => "Bouvet Island",
        "BR" => "Brazil",
        // "IO" => "British Indian Ocean Territory",
        "BN" => "Brunei",
        "BG" => "Bulgaria",
        "BF" => "Burkina Faso",
        "BI" => "Burundi",
        "CV" => "Cabo Verde",
        "KH" => "Cambodia",
        "CM" => "Cameroon",
        "CA" => "Canada",
        // "IC" => "Canary Islands",
        // "KY" => "Cayman Islands",
        "CF" => "Central African Republic",
        "TD" => "Chad",
        "CL" => "Chile",
        "CN" => "China",
        // "CX" => "Christmas Island",
        // "CC" => "Cocos (Keeling) Islands",
        "CO" => "Colombia",
        "KM" => "Comoros",
        "CD" => "Congo-Kinshasa",
        "CG" => "Congo-Brazzaville",
        // "CK" => "Cook Islands",
        "CR" => "Costa Rica",
        "CI" => "Côte d'Ivoire",
        "HR" => "Croatia",
        "CU" => "Cuba",
        // "CW" => "Curaçao",
        "CY" => "Cyprus",
        "CZ" => "Czech Republic", /* Czech Republic */
        "DK" => "Denmark",
        "DJ" => "Djibouti",
        "DM" => "Dominica",
        "DO" => "Dominican Republic",
        "EC" => "Ecuador",
        "EG" => "Egypt",
        "SV" => "El Salvador",
        "GQ" => "Equatorial Guinea",
        "ER" => "Eritrea",
        "EE" => "Estonia",
        "SZ" => "Eswatini",
        "ET" => "Ethiopia",
        // "FK" => "Falkland Islands (Malvinas)",
        // "FO" => "Faroe Islands",
        "FJ" => "Fiji",
        "FI" => "Finland",
        "FR" => "France",
        // "GF" => "French Guiana",
        // "PF" => "French Polynesia",
        // "TF" => "French Southern Territories",
        "GA" => "Gabon",
        "GM" => "Gambia",
        "GE" => "Georgia",
        "DE" => "Germany",
        "GH" => "Ghana",
        // "GI" => "Gibraltar",
        "GR" => "Greece",
        "GL" => "Greenland",
        "GD" => "Grenada",
        // "GP" => "Guadeloupe",
        // "GU" => "Guam",
        "GT" => "Guatemala",
        // "GG" => "Guernsey",
        "GN" => "Guinea",
        "GW" => "Guinea-Bissau",
        "GY" => "Guyana",
        "HT" => "Haiti",
        // "HM" => "Heard Island and the McDonald Islands",
        "VA" => "Holy See",
        "HN" => "Honduras",
        "HK" => "Hong Kong",
        "HU" => "Hungary",
        "IS" => "Iceland",
        "IN" => "India",
        "ID" => "Indonesia",
        "IR" => "Iran",
        "IQ" => "Iraq",
        "IE" => "Ireland",
        // "IM" => "Isle of Man",
        "IL" => "Israel",
        "IT" => "Italy",
        "JM" => "Jamaica",
        "JP" => "Japan",
        // "JE" => "Jersey",
        "JO" => "Jordan",
        "KZ" => "Kazakhstan",
        "KE" => "Kenya",
        "KI" => "Kiribati",
        "KP" => "North Korea",
        "KR" => "South Korea",
        "XK" => "Kosovo",
        "KW" => "Kuwait",
        "KG" => "Kyrgyzstan",
        "LA" => "Laos",
        "LV" => "Latvia",
        "LB" => "Lebanon",
        "LS" => "Lesotho",
        "LR" => "Liberia",
        "LY" => "Libya",
        "LI" => "Liechtenstein",
        "LT" => "Lithuania",
        "LU" => "Luxembourg",
        // "MO" => "Macao",
        "MK" => "North Macedonia",
        "MG" => "Madagascar",
        "MW" => "Malawi",
        "MY" => "Malaysia",
        "MV" => "Maldives",
        "ML" => "Mali",
        "MT" => "Malta",
        "MH" => "Marshall Islands",
        // "MQ" => "Martinique",
        "MR" => "Mauritania",
        "MU" => "Mauritius",
        // "YT" => "Mayotte",
        "MX" => "Mexico",
        "FM" => "Micronesia",
        "MD" => "Moldova",
        "MC" => "Monaco",
        "MN" => "Mongolia",
        "ME" => "Montenegro",
        // "MS" => "Montserrat",
        "MA" => "Morocco",
        "MZ" => "Mozambique",
        "MM" => "Myanmar",
        "NA" => "Namibia",
        "NR" => "Nauru",
        "NP" => "Nepal",
        "NL" => "Netherlands",
        // "AN" => "Netherlands Antilles",
        // "NC" => "New Caledonia",
        "NZ" => "New Zealand",
        "NI" => "Nicaragua",
        "NE" => "Niger",
        "NG" => "Nigeria",
        // "NU" => "Niue",
        // "NF" => "Norfolk Island",
        // "MP" => "Northern Mariana Islands",
        "NO" => "Norway",
        "OM" => "Oman",
        "PK" => "Pakistan",
        "PW" => "Palau",
        "PS" => "Palestinian Territories",
        "PA" => "Panama",
        "PG" => "Papua New Guinea",
        "PY" => "Paraguay",
        "PE" => "Peru",
        "PH" => "Philippines",
        // "PN" => "Pitcairn",
        "PL" => "Poland",
        "PT" => "Portugal",
        // "PR" => "Puerto Rico",
        "QA" => "Qatar",
        // "RE" => "Réunion",
        "RO" => "Romania",
        "RU" => "Russia",
        "RW" => "Rwanda",
        // "BL" => "Saint Barthélemy",
        // "SH" => "Saint Helena, Ascension and Tristan da Cunha",
        "KN" => "Saint Kitts and Nevis",
        "LC" => "Saint Lucia",
        // "MF" => "Saint Martin",
        // "PM" => "Saint Pierre and Miquelon",
        "VC" => "Saint Vincent and the Grenadines",
        "WS" => "Samoa",
        "SM" => "San Marino",
        "ST" => "São Tomé and Príncipe",
        "SA" => "Saudi Arabia",
        "SN" => "Senegal",
        "RS" => "Serbia",
        "SC" => "Seychelles",
        "SL" => "Sierra Leone",
        "SG" => "Singapore",
        // "SX" => "Sint Maarten",
        "SK" => "Slovakia",
        "SI" => "Slovenia",
        "SB" => "Solomon Islands",
        "SO" => "Somalia",
        "ZA" => "South Africa",
        // "GS" => "South Georgia and the South Sandwich Islands",
        "SS" => "South Sudan",
        "ES" => "Spain",
        "LK" => "Sri Lanka",
        "SD" => "Sudan",
        "SR" => "Suriname",
        // "SJ" => "Svalbard and Jan Mayen",
        "SE" => "Sweden",
        "CH" => "Switzerland",
        "SY" => "Syria",
        "TW" => "Taiwan",
        "TJ" => "Tajikistan",
        "TZ" => "Tanzania",
        "TH" => "Thailand",
        "TL" => "Timor-Leste",
        "TG" => "Togo",
        // "TK" => "Tokelau",
        "TO" => "Tonga",
        "TT" => "Trinidad and Tobago",
        "TN" => "Tunisia",
        "TR" => "Turkey",
        "TM" => "Turkmenistan",
        // "TC" => "Turks and Caicos Islands",
        "TV" => "Tuvalu",
        "UG" => "Uganda",
        "UA" => "Ukraine",
        "AE" => "United Arab Emirates",
        "GB" => "United Kingdom",
        "US" => "United States",
        // "UM" => "United States Minor Outlying Islands",
        "UY" => "Uruguay",
        "UZ" => "Uzbekistan",
        "VU" => "Vanuatu",
        "VE" => "Venezuela",
        "VN" => "Vietnam",
        // "VG" => "Virgin Islands, British",
        // "VI" => "Virgin Islands, U.S.",
        // "WF" => "Wallis and Futuna",
        "EH" => "Western Sahara",
        "YE" => "Yemen",
        "ZM" => "Zambia",
        "ZW" => "Zimbabwe"
    ];

    /** @param ?string $code
     * @return string */
    static function code_to_name($code) {
        $code = $code ?? "";
        return self::$map[$code] ?? $code;
    }

    /** @param ?string $country
     * @return ?string */
    static function fix($country) {
        if ($country === null || isset(self::$map[$country])) {
            return $country;
        } else if (($code = array_search($country, self::$map)) !== false
                   || ($code = array_search($country, self::$selector_overrides)) !== false) {
            return $code;
        } else {
            return $country;
        }
    }

    /** @param string $name
     * @param string $country
     * @return string */
    static function selector($name, $country, $extra = []) {
        $sel_country = "";
        $opts = ['<option value=""' . ($country ? '' : ' selected') . '>(select one)</option>'];
        foreach (self::$map as $code => $text) {
            if ($country !== null && strcasecmp($country, $code) === 0) {
                $sel_country = $code;
                $country = null;
            }
            $text = self::$selector_overrides[$code] ?? $text;
            $sel = $sel_country === $code ? " selected" : "";
            $opts[] = "<option value=\"{$code}\"{$sel}>{$text}</option>";
        }
        if ($country) {
            $sel_country = $country;
            $opts[] = '<option selected>' . htmlspecialchars($country) . '</option>';
        }

        if (!isset($extra["autocomplete"])) {
            $extra["autocomplete"] = "country";
        }
        if (!isset($extra["data-default-value"])) {
            $extra["data-default-value"] = $sel_country;
        }
        return "<span class=\"select\"><select name=\"{$name}\"" . Ht::extra($extra) . ">\n"
            . join("\n", $opts) . "</select></span>";
    }
}
