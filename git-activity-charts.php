<?php
/*
Plugin Name: Git Activity Charts
Description: Display merged activity charts for GitHub, GitLab, Gitea, and Bitbucket repositories, supporting private repos and custom logos. Aggregates user contributions for GitHub and repository commits for others.
Version: 0.0.0
Author: Devin J. Dawson
Author URI: https://devinjdawson.com
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: git-activity-charts
*/

// Guard clause to prevent multiple inclusions
if (defined('GIT_ACTIVITY_CHARTS_LOADED')) {
    error_log("Git Activity Charts already loaded - skipping");
    return;
}
define('GIT_ACTIVITY_CHARTS_LOADED', true);

if (!defined('ABSPATH')) {
    exit;
}

define('GIT_ACTIVITY_CHARTS_VERSION', '0.0.0');
define('GIT_ACTIVITY_CHARTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GIT_ACTIVITY_CHARTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GIT_ACTIVITY_CHARTS_PLUGIN_FILE', (__FILE__));

// Debug: Log when the plugin file is loaded
error_log("Git Activity Charts plugin file loaded: " . __FILE__);

// Include necessary files
require_once GIT_ACTIVITY_CHARTS_PLUGIN_DIR . 'includes/class-git-activity-charts.php';
require_once GIT_ACTIVITY_CHARTS_PLUGIN_DIR . 'includes/admin-settings-page.php';

register_activation_hook(__FILE__, 'git_activity_charts_activate');
register_deactivation_hook(__FILE__, 'git_activity_charts_deactivate');

function git_activity_charts_activate() {
    if (false === get_option('git_activity_accounts')) {
        update_option('git_activity_accounts', []);
    }
    if (false === get_option('git_activity_custom_css')) {
        update_option('git_activity_custom_css', '');
    }
}
