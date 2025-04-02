<?php
/*
Plugin Name: Git Activity Charts
Description: Display merged activity charts for GitHub, GitLab, Gitea, and Bitbucket repositories, supporting private repos and custom logos. Aggregates user contributions for GitHub and repository commits for others.
Version: 0.1.0
Author: Devin J. Dawson 
Author URI:   # Optional: Your website
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: git-activity-charts
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('GIT_ACTIVITY_CHARTS_VERSION', '0.1.0');
define('GIT_ACTIVITY_CHARTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GIT_ACTIVITY_CHARTS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include necessary files
require_once GIT_ACTIVITY_CHARTS_PLUGIN_DIR . 'includes/class-provider-base.php';
require_once GIT_ACTIVITY_CHARTS_PLUGIN_DIR . 'includes/class-github-provider.php';
require_once GIT_ACTIVITY_CHARTS_PLUGIN_DIR . 'includes/class-gitlab-provider.php';
require_once GIT_ACTIVITY_CHARTS_PLUGIN_DIR . 'includes/class-gitea-provider.php';
require_once GIT_ACTIVITY_CHARTS_PLUGIN_DIR . 'includes/class-bitbucket-provider.php';
require_once GIT_ACTIVITY_CHARTS_PLUGIN_DIR . 'includes/class-git-activity-charts.php';

// Initialize the plugin
function git_activity_charts_init() {
    new GitActivityCharts();
}
add_action('plugins_loaded', 'git_activity_charts_init');

// Activation/Deactivation Hooks (Optional: for cleanup like transients)
register_activation_hook(__FILE__, 'git_activity_charts_activate');
register_deactivation_hook(__FILE__, 'git_activity_charts_deactivate');

function git_activity_charts_activate() {
    // Maybe set default options if they don't exist
    if (false === get_option('git_activity_accounts')) {
        update_option('git_activity_accounts', []);
    }
    if (false === get_option('git_activity_custom_css')) {
         // Add default CSS here from your original register_settings if desired
         update_option('git_activity_custom_css', '');
    }
}

function git_activity_charts_deactivate() {
    // Optional: Consider clearing all transients on deactivation
    // global $wpdb;
    // $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_git_activity_%'");
    // $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_git_activity_%'");
}