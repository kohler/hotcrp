<?php

use Phan\Config;

/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 *
 * @see src/Phan/Config.php
 * See Config for all configurable options.
 *
 * A Note About Paths
 * ==================
 *
 * Files referenced from this file should be defined as
 *
 * ```
 *   Config::projectPath('relative_path/to/file')
 * ```
 *
 * where the relative path is relative to the root of the
 * project which is defined as either the working directory
 * of the phan executable or a path passed in via the CLI
 * '-d' flag.
 */
return (function () {
$config = [
    "minimum_target_php_version" => "7.0",
    "target_php_version" => "8.2",

    // If true, missing properties will be created when
    // they are first seen. If false, we'll report an
    // error message.
    "allow_missing_properties" => false,

    // Allow null to be cast as any type and for any
    // type to be cast to null.
    "null_casts_as_any_type" => true,

    // Backwards Compatibility Checking
    "backward_compatibility_checks" => false,

    //"redundant_condition_detection" => true,
    //"dead_code_detection" => true,

    // Only emit critical issues to start with
    // (0 is low severity, 5 is normal severity, 10 is critical)
    "minimum_severity" => 0,

    "enable_internal_return_type_plugins" => true,
    //"enable_extended_internal_return_type_plugins" => true,

    //"redundant_condition_detection" => true,
    //"unused_variable_detection" => true,

    // A list of directories that should be parsed for class and
    // method information. After excluding the directories
    // defined in exclude_analysis_directory_list, the remaining
    // files will be statically analyzed for errors.
    //
    // Thus, both first-party and third-party code being used by
    // your application should be included in this list.
    "directory_list" => [
        Config::projectPath("lib"),
        Config::projectPath("src"),
        Config::projectPath("batch"),
        Config::projectPath("test"),
        Config::projectPath(".phan/stubs")
    ],

    "file_list" => [
        Config::projectPath("api.php"),
        Config::projectPath("assign.php"),
        Config::projectPath("autoassign.php"),
        Config::projectPath("bulkassign.php"),
        Config::projectPath("buzzer.php"),
        Config::projectPath("cacheable.php"),
        Config::projectPath("checkupdates.php"),
        Config::projectPath("conflictassign.php"),
        Config::projectPath("deadlines.php"),
        Config::projectPath("doc.php"),
        Config::projectPath("graph.php"),
        Config::projectPath("help.php"),
        Config::projectPath("index.php"),
        Config::projectPath("log.php"),
        Config::projectPath("mail.php"),
        Config::projectPath("manualassign.php"),
        Config::projectPath("mergeaccounts.php"),
        Config::projectPath("newaccount.php"),
        Config::projectPath("offline.php"),
        Config::projectPath("paper.php"),
        Config::projectPath("profile.php"),
        Config::projectPath("resetpassword.php"),
        Config::projectPath("review.php"),
        Config::projectPath("reviewprefs.php"),
        Config::projectPath("scorechart.php"),
        Config::projectPath("search.php"),
        Config::projectPath("settings.php"),
        Config::projectPath("users.php")
    ],

    "exclude_file_list" => [
        Config::projectPath(".phan/config.php"),
        Config::projectPath("batch/downgradedb.php"),
        Config::projectPath("lib/collatorshim.php"),
        Config::projectPath("lib/polyfills.php")
    ],

    "suppress_issue_types" => [
        "PhanUnusedPublicMethodParameter",
        "PhanUnusedVariableValueOfForeachWithKey",
        "PhanParamReqAfterOpt", // remove when PHP 7.0 is not supported
        "PhanUndeclaredClassAttribute"
    ],

    "plugins" => [
        //".phan/plugins/RedundantDblResultPlugin.php"
    ]
];

if (file_exists(Config::projectPath(".phan/hotcrp-config.php"))) {
    include(Config::projectPath(".phan/hotcrp-config.php"));
}

return $config;
})();
