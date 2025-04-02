<?php

if (!defined('ABSPATH')) {
    exit;
}

class GitActivityCharts {

    private $providers = [];
    private static $default_css = "
#git-charts .chart-container {
    margin-bottom: 2em;
    padding: 1em;
    border: 1px solid #ddd;
    border-radius: 5px;
    background-color: #fff;
}
#git-charts .chart-title {
    display: flex;
    align-items: center;
    gap: 0.5em;
    margin-top: 0;
    margin-bottom: 1em;
    font-size: 1.2em;
    font-weight: bold;
}
#git-charts .provider-badge img {
    width: 24px;
    height: 24px;
    vertical-align: middle;
    margin-left: 5px; /* Adjust spacing */
}
#git-charts .error-message {
    color: #d32f2f;
    font-style: italic;
    margin-top: 0.5em;
    padding: 0.5em;
    border: 1px solid #d32f2f;
    border-radius: 3px;
    background-color: #ffebee;
}
#git-charts .loading-placeholder {
    text-align: center;
    padding: 2em;
    color: #777;
}
#git-charts .no-data {
    text-align: center;
    padding: 1em;
    color: #777;
}
";


    public function __construct() {
        $this->load_providers();
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_scripts']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_shortcode('git_activity_charts', [$this, 'render_charts_shortcode']);
    }

    private function load_providers() {
        $this->providers = [
            'github'    => new GitHubProvider(),
            'gitlab'    => new GitLabProvider(),
            'gitea'     => new GiteaProvider(),
            'bitbucket' => new BitbucketProvider(),
            // 'custom' => new CustomProvider(), // If you create one
        ];
    }
    private function init_providers() {
        $this->providers = [
            'github' => [
                'fetch' => function($username, $api_key, $instance_url = '', $repos = []) {
                    $query = 'query($userName: String!) {
                        user(login: $userName) {
                            contributionsCollection {
                                contributionCalendar {
                                    totalContributions
                                    weeks {
                                        contributionDays {
                                            contributionCount
                                            date
                                        }
                                    }
                                }
                            }
                        }
                    }';
                    $variables = ['userName' => $username];
                    $headers = [
                        'Authorization' => "Bearer {$api_key}",
                        'Content-Type' => 'application/json'
                    ];
                    $response = wp_remote_post('https://api.github.com/graphql', [
                        'headers' => $headers,
                        'body' => json_encode(['query' => $query, 'variables' => $variables])
                    ]);
                    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                        return ['data' => false];
                    }
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    $contributions = $body['data']['user']['contributionsCollection']['contributionCalendar']['weeks'] ?? [];
                    $commits = [];
                    foreach ($contributions as $week) {
                        foreach ($week['contributionDays'] as $day) {
                            if ($day['contributionCount'] > 0) {
                                $commits[] = [
                                    'message' => "Contributed {$day['contributionCount']} times",
                                    'committed_date' => $day['date'],
                                    'repo' => 'GitHub Activity',
                                    'repo_url' => "https://github.com/{$username}"
                                ];
                            }
                        }
                    }
                    return ['data' => $commits];
                },
                'color' => '#0366d6',
                'icon' => plugins_url('assets/github/github-mark-dark.svg', __FILE__)
            ],
            'gitlab' => [
                'fetch' => function($username, $api_key, $instance_url = '', $repos = []) {
                    $base_url = $instance_url ?: 'https://gitlab.com';
                    $all_commits = [];
                    foreach ($repos as $repo) {
                        $project_url = "{$base_url}/api/v4/projects/" . urlencode("{$username}/{$repo}");
                        $headers = $api_key ? ['Private-Token' => $api_key] : [];
                        $repo_response = wp_remote_get($project_url, ['headers' => $headers]);
                        $repo_url = is_wp_error($repo_response) ? "{$base_url}/{$username}/{$repo}" : json_decode(wp_remote_retrieve_body($repo_response), true)['web_url'] ?? "{$base_url}/{$username}/{$repo}";

                        $commits_url = "{$project_url}/repository/commits?per_page=100";
                        $response = wp_remote_get($commits_url, ['headers' => $headers]);
                        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                            continue;
                        }
                        $commits = json_decode(wp_remote_retrieve_body($response), true);
                        if (!$commits) continue;

                        foreach ($commits as $commit) {
                            $commit['repo_url'] = $repo_url;
                            $commit['repo'] = $repo;
                            $all_commits[] = $commit;
                        }
                    }
                    return ['data' => $all_commits];
                },
                'color' => '#ff4500',
                'icon' => plugins_url('assets/gitlab/gitlab-mark-dark.svg', __FILE__)
            ],
            'custom' => [
                'fetch' => function($username, $api_key, $instance_url = '', $repos = []) {
                    if (!$instance_url) return ['data' => false];
                    $base_url = rtrim($instance_url, '/');
                    $all_commits = [];
                    foreach ($repos as $repo) {
                        $repo_url = "{$base_url}/{$username}/{$repo}";
                        $commits_url = "{$base_url}/api/v1/repos/{$username}/{$repo}/commits?limit=100";
                        $headers = $api_key ? ['Authorization' => "token {$api_key}"] : [];
                        $response = wp_remote_get($commits_url, ['headers' => $headers]);
                        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                            continue;
                        }
                        $commits = json_decode(wp_remote_retrieve_body($response), true);
                        if (!$commits) continue;

                        foreach ($commits as $commit) {
                            $commit['repo_url'] = $repo_url;
                            $commit['repo'] = $repo;
                            $all_commits[] = $commit;
                        }
                    }
                    return ['data' => $all_commits];
                },
                'color' => '#000000',
                'icon' => null // Will be set dynamically based on instance_url
            ]
        ];
    }
    public function add_admin_menu() {
        add_options_page(
            'Git Activity Charts Settings',
            'Git Activity Charts',
            'manage_options',
            'git-activity-charts',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('git_activity_options', 'git_activity_accounts', [
            'sanitize_callback' => [$this, 'sanitize_accounts']
        ]);
        register_setting('git_activity_options', 'git_activity_show_public_only', [
            'type' => 'boolean',
            'default' => false
        ]);
        register_setting('git_activity_options', 'git_activity_custom_css', [
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => "#git-charts .chart-container {\n    margin-bottom: 2em;\n    padding: 1em;\n    border: 1px solid #ddd;\n    border-radius: 5px;\n}\n#git-charts h3 {\n    display: flex;\n    align-items: center;\n    gap: 0.5em;\n    margin-bottom: 1em;\n}\n#git-charts .activity-feed {\n    margin-top: 1em;\n}\n#git-charts .activity-feed .commit {\n    display: flex;\n    align-items: center;\n    gap: 0.5em;\n    margin-bottom: 0.5em;\n}\n#git-charts .activity-feed .commit img {\n    width: 16px;\n    height: 16px;\n}\n#git-charts .activity-feed .commit .time-ago {\n    margin-left: auto;\n    color: #666;\n    font-size: 0.9em;\n}\n.git-activity-settings .button-link-delete {\n    color: #dc3232 !important;\n    text-decoration: none;\n    border: none;\n    background: none;\n    cursor: pointer;\n}\n.git-activity-settings .button-link-delete:hover {\n    color: #a00 !important;\n    text-decoration: underline;\n}\n.git-activity-settings .form-table {\n    margin-top: 0;\n}"
        ]);
    }

    public function register_settings() {
        register_setting('git_activity_options', 'git_activity_accounts', [
            'sanitize_callback' => [$this, 'sanitize_accounts'],
            'type' => 'array',
            'default' => [],
        ]);
        // Deprecating git_activity_show_public_only as logic is more complex now
        // register_setting('git_activity_options', 'git_activity_show_public_only', [
        //     'type' => 'boolean',
        //     'default' => false
        // ]);
        register_setting('git_activity_options', 'git_activity_custom_css', [
            'sanitize_callback' => [$this,'sanitize_css'],
            'type' => 'string',
            'default' => self::$default_css,
        ]);
    }

     public function sanitize_css($input) {
        // Allow safe CSS properties
        // Using wp_strip_all_tags might be too aggressive.
        // wp_kses_post could work but might strip valid CSS.
        // A simple approach is to just strip <script> tags and allow most other things.
        $sanitized = wp_strip_all_tags($input); // Basic sanitization
        // Optionally add more specific rules if needed
        return $sanitized;
    }

    public function sanitize_accounts($input) {
        $sanitized = [];
        foreach ($input as $account) {
            $type = sanitize_text_field($account['type']);
            $sanitized[] = [
                'type' => $type,
                'username' => sanitize_text_field($account['username']),
                'api_key' => sanitize_text_field($account['api_key']),
                'repos' => array_filter(array_map('sanitize_text_field', explode(',', $account['repos']))),
                'instance_url' => isset($account['instance_url']) ? esc_url_raw($account['instance_url']) : '',
                'use_color_logo' => isset($account['use_color_logo']) ? (bool)$account['use_color_logo'] : false,
                'text_color' => isset($account['text_color']) ? sanitize_hex_color($account['text_color']) : $this->providers[$type]['color'] ?? '#000000',
                'custom_logo' => isset($account['custom_logo']) ? esc_url_raw($account['custom_logo']) : ''
            ];
        }
        return $sanitized;
    }

    public function enqueue_admin_scripts($hook) {
        // Only load on our settings page
        if ('settings_page_git-activity-charts' !== $hook) {
            return;
        }

        // Enqueue WP Color Picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // Enqueue Admin JS
        wp_enqueue_script(
            'git-activity-charts-admin',
            GIT_ACTIVITY_CHARTS_PLUGIN_URL . 'assets/js/git-activity-charts-admin.js',
            ['jquery', 'wp-color-picker'],
            GIT_ACTIVITY_CHARTS_VERSION,
            true // Load in footer
        );

        // Enqueue Admin CSS (Optional)
        wp_enqueue_style(
            'git-activity-charts-admin',
            GIT_ACTIVITY_CHARTS_PLUGIN_URL . 'assets/css/git-activity-charts-admin.css',
            [],
            GIT_ACTIVITY_CHARTS_VERSION
        );
    }

     public function enqueue_frontend_scripts() {
        // Only enqueue if the shortcode might be present (or enqueue globally if preferred)
        // A more robust check would involve checking post content for the shortcode
        // if (is_singular() && has_shortcode( get_post()->post_content, 'git_activity_charts')) {

            // Enqueue Chart.js locally
            wp_enqueue_script(
                'chart-js',
                GIT_ACTIVITY_CHARTS_PLUGIN_URL . 'assets/js/chart.min.js', // Make sure this path is correct
                [], // No dependencies for chart.js itself
                '3.9.1', // Example version - use the version you downloaded
                true // Load in footer
            );

            // Enqueue Frontend JS
            wp_enqueue_script(
                'git-activity-charts-frontend',
                GIT_ACTIVITY_CHARTS_PLUGIN_URL . 'assets/js/git-activity-charts-frontend.js',
                ['chart-js', 'jquery'], // Depends on Chart.js and jQuery (optional)
                GIT_ACTIVITY_CHARTS_VERSION,
                true // Load in footer
            );

            // Enqueue Frontend CSS
            wp_enqueue_style(
                'git-activity-charts-frontend-style',
                GIT_ACTIVITY_CHARTS_PLUGIN_URL . 'assets/css/git-activity-charts-frontend.css',
                [],
                GIT_ACTIVITY_CHARTS_VERSION
            );

            // Add custom CSS inline
            $custom_css = get_option('git_activity_custom_css', self::$default_css);
            if (!empty(trim($custom_css))) {
                 wp_add_inline_style('git-activity-charts-frontend-style', $custom_css);
            }
       // }
    }


    public function settings_page_html() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }
        ?>
        <div class="wrap git-activity-settings">
            <h1><?php _e('Git Activity Charts Settings', 'git-activity-charts'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('git_activity_options');
                $accounts = get_option('git_activity_accounts', []);
                $custom_css = get_option('git_activity_custom_css', self::$default_css);
                ?>

                <h2><?php _e('Accounts', 'git-activity-charts'); ?></h2>
                <p><?php _e('Add accounts from different Git providers. API keys are needed for private repositories and to avoid rate limits. GraphQL APIs are used where available (GitHub, GitLab, Bitbucket).', 'git-activity-charts'); ?></p>
                <p><?php _e('GitHub requires API key with `read:user` scope for contributions. GitLab requires `read_api` or `read_user`. Bitbucket often requires OAuth 2.0 or App Passwords with appropriate scopes (e.g., `repository:read`). Gitea uses REST.', 'git-activity-charts'); ?></p>
        
                <div id="accounts-container">
                    <?php if (!empty($accounts)) : ?>
                        <?php foreach ($accounts as $index => $account) : ?>
                            <?php $this->render_account_fields($index, $account); ?>
                        <?php endforeach; ?>
                    <?php else : ?>
                         <p id="no-accounts-msg"><?php _e('No accounts added yet.', 'git-activity-charts'); ?></p>
                    <?php endif; ?>
                </div>
                 <button type="button" id="add-account" class="button button-secondary">
                    <?php _e('+ Add Account', 'git-activity-charts'); ?>
                </button>


                <h2><?php _e('Appearance', 'git-activity-charts'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="git_activity_custom_css"><?php _e('Custom CSS', 'git-activity-charts'); ?></label>
                         </th>
                        <td>
                            <textarea id="git_activity_custom_css" name="git_activity_custom_css" rows="10" cols="50" class="large-text"><?php echo esc_textarea($custom_css); ?></textarea>
                            <p class="description"><?php _e('Add custom CSS rules to style the charts and container. Default styles are provided.', 'git-activity-charts'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>

        <div id="account-template" style="display: none;">
             <?php $this->render_account_fields('__INDEX__', []); ?>
        </div>
        <?php
    }

    private function render_account_fields($index, $account) {
        $type = isset($account['type']) ? $account['type'] : 'github'; // Default to GitHub
        $username = isset($account['username']) ? $account['username'] : '';
        $api_key = isset($account['api_key']) ? $account['api_key'] : '';
        $repos = isset($account['repos']) && is_array($account['repos']) ? implode(', ', $account['repos']) : '';
        $instance_url = isset($account['instance_url']) ? $account['instance_url'] : '';
        $use_color_logo = isset($account['use_color_logo']) ? (bool)$account['use_color_logo'] : false;
        $text_color = isset($account['text_color']) ? $account['text_color'] : ($this->providers[$type] ? $this->providers[$type]->get_color() : '#000000');

        // Determine if instance URL is needed for this provider type
        $needs_instance_url = in_array($type, ['gitlab', 'gitea']); // Only GitLab and Gitea might need it if self-hosted

        // Determine if repos field is needed (GitHub uses user contributions, not specific repos here)
        $needs_repos = ($type !== 'github');

        ?>
        <div class="account-group" data-index="<?php echo esc_attr($index); ?>">
            <div class="account-header">
                <h4><?php printf(__('Account %s', 'git-activity-charts'), is_numeric($index) ? $index + 1 : ''); ?> <span class="account-type-indicator"><?php echo esc_html(ucfirst($type)); ?></span></h4>
                <button type="button" class="button button-link-delete remove-account"><?php _e('Remove', 'git-activity-charts'); ?></button>
            </div>
             <table class="form-table">
                 <tr>
                     <th scope="row"><label><?php _e('Provider', 'git-activity-charts'); ?></label></th>
                     <td>
                         <select name="git_activity_accounts[<?php echo esc_attr($index); ?>][type]" class="account-type-select">
                             <option value="github" <?php selected($type, 'github'); ?>>GitHub</option>
                             <option value="gitlab" <?php selected($type, 'gitlab'); ?>>GitLab</option>
                             <option value="gitea" <?php selected($type, 'gitea'); ?>>Gitea</option>
                             <option value="bitbucket" <?php selected($type, 'bitbucket'); ?>>Bitbucket</option>
                         </select>
                     </td>
                 </tr>
                 <tr>
                     <th scope="row"><label><?php _e('Username', 'git-activity-charts'); ?></label></th>
                     <td><input type="text" name="git_activity_accounts[<?php echo esc_attr($index); ?>][username]" value="<?php echo esc_attr($username); ?>" placeholder="<?php _e('Your username on the platform', 'git-activity-charts'); ?>" required class="regular-text" /></td>
                 </tr>
                 <tr class="account-field repos-field" <?php echo $needs_repos ? '' : 'style="display: none;"'; ?>>
                     <th scope="row"><label><?php _e('Repositories', 'git-activity-charts'); ?></label></th>
                     <td>
                         <input type="text" name="git_activity_accounts[<?php echo esc_attr($index); ?>][repos]" value="<?php echo esc_attr($repos); ?>" placeholder="<?php _e('repo1, repo2, another-repo', 'git-activity-charts'); ?>" class="large-text" />
                         <p class="description"><?php _e('Comma-separated list of repository names (required for GitLab, Gitea, Bitbucket).', 'git-activity-charts'); ?></p>
                     </td>
                 </tr>
                <tr class="account-field instance-url-field" <?php echo $needs_instance_url ? '' : 'style="display: none;"'; ?>>
                    <th scope="row"><label><?php _e('Instance URL', 'git-activity-charts'); ?></label></th>
                    <td>
                        <input type="url" name="git_activity_accounts[<?php echo esc_attr($index); ?>][instance_url]" value="<?php echo esc_attr($instance_url); ?>" placeholder="<?php _e('e.g., https://gitlab.mycompany.com or https://gitea.domain.tld', 'git-activity-charts'); ?>" class="regular-text"/>
                        <p class="description"><?php _e('Required for self-hosted GitLab or Gitea instances. Leave empty for gitlab.com.', 'git-activity-charts'); ?></p>
                    </td>
                </tr>
                 <tr>
                     <th scope="row"><label><?php _e('API Key/Token', 'git-activity-charts'); ?></label></th>
                     <td>
                        <div style="position: relative;">
                             <input type="password" name="git_activity_accounts[<?php echo esc_attr($index); ?>][api_key]" value="<?php echo esc_attr($api_key); ?>" placeholder="<?php _e('Personal Access Token (optional)', 'git-activity-charts'); ?>" class="regular-text api-key-input" autocomplete="new-password"/>
                             <button type="button" class="button button-secondary toggle-api-key" style="position: absolute; right: 1px; top: 1px; height: 28px; margin:0;"><?php _e('Show', 'git-activity-charts'); ?></button>
                        </div>
                         <p class="description"><?php _e('Needed for private repos or to increase rate limits. See provider documentation for required scopes (e.g., GitHub needs `read:user`, `repo`).', 'git-activity-charts'); ?></p>
                     </td>
                 </tr>
                <tr>
                    <th scope="row"><?php _e('Display Options', 'git-activity-charts'); ?></th>
                    <td>
                        <fieldset>
                             <label>
                                <input type="checkbox" name="git_activity_accounts[<?php echo esc_attr($index); ?>][use_color_logo]" value="1" <?php checked($use_color_logo, 1); ?> />
                                <?php _e('Use Color Logo (if available)', 'git-activity-charts'); ?>
                            </label>
                            <br>
                             <label style="margin-top: 10px; display: inline-block;">
                                 <?php _e('Title Text Color:', 'git-activity-charts'); ?>
                                 <input type="text" name="git_activity_accounts[<?php echo esc_attr($index); ?>][text_color]" value="<?php echo esc_attr($text_color); ?>" class="color-picker" data-default-color="<?php echo esc_attr($text_color); ?>" />
                            </label>
                         </fieldset>
                    </td>
                </tr>
             </table>
             <hr />
        </div>
        <?php
    }


    public function show_admin_notices() {
        $accounts = get_option('git_activity_accounts', []);
        foreach ($accounts as $account) {
            foreach ($account['repos'] as $repo) {
                $cache_key = "git_activity_{$account['type']}_{$account['username']}_{$repo}";
                if ($error = get_option("git_activity_error_{$cache_key}")) {
                    echo "<div class='notice notice-error is-dismissible'><p>{$error}</p></div>";
                }
            }
        }
    }
    private function get_commit_date($commit, $date_key) {
        return isset($commit[$date_key]) ? strtotime($commit[$date_key]) : (isset($commit['created_at']) ? strtotime($commit['created_at']) : false);
    }

    private function get_fallback_repo_url($type, $username, $repo, $instance_url) {
        switch ($type) {
            case 'github':
                return "https://github.com/{$username}/{$repo}";
            case 'gitlab':
                return ($instance_url ?: 'https://gitlab.com') . "/{$username}/{$repo}";
            case 'custom':
                return $instance_url ? "{$instance_url}/{$username}/{$repo}" : '#';
            default:
                return '#';
        }
    }
    public function render_charts($atts) {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return '<p>Please log in to view activity charts.</p>';
        }

        wp_enqueue_script('cal-heatmap', 'https://cdn.jsdelivr.net/npm/cal-heatmap@4.2.1/dist/cal-heatmap.min.js', [], null, true);
        wp_enqueue_style('cal-heatmap-css', 'https://cdn.jsdelivr.net/npm/cal-heatmap@4.2.1/dist/cal-heatmap.css', [], null);
        $custom_css = get_option('git_activity_custom_css', '');
        if ($custom_css) {
            wp_add_inline_style('cal-heatmap-css', $custom_css);
        }

        $accounts = get_option('git_activity_accounts', []);
        $show_public_only = get_option('git_activity_show_public_only', false);
        $all_commits = [];
        $heatmap_data = [];
        $current_time = time();

        foreach ($accounts as $account) {
            $type = $account['type'];
            $username = $account['username'];
            $api_key = $account['api_key'];
            $instance_url = $account['instance_url'];
            $use_color_logo = $account['use_color_logo'];
            $custom_logo = $account['custom_logo'];
            $repos = $account['repos'];
            $provider = $this->providers[$type] ?? null;

            if (!$provider) continue;
            if ($show_public_only && empty($api_key)) continue;

            $result = $provider['fetch']($username, $api_key, $instance_url, $repos);
            if ($result['data']) {
                $commits = $result['data'];
            } else {
                if (current_user_can('manage_options')) {
                    $error_msg = "Failed to fetch data for {$username} ({$type}). Check API key or repository access.";
                    update_option("git_activity_error_{$type}_{$username}", $error_msg);
                }
                continue;
            }

            $icon = $custom_logo ?: ($type === 'custom' ? ($instance_url . '/favicon.ico') : $provider['icon']);
            foreach ($commits as $commit) {
                $date = $this->get_commit_date($commit, $type === 'github' ? 'committed_date' : 'committed_date');
                if (!$date) continue;

                $all_commits[] = [
                    'date' => $date,
                    'message' => $commit['message'] ?? "Contribution",
                    'repo' => $commit['repo'] ?? $username,
                    'type' => $type,
                    'username' => $username,
                    'repo_url' => $commit['repo_url'] ?? $this->get_fallback_repo_url($type, $username, $commit['repo'] ?? $username, $instance_url),
                    'logo' => $icon
                ];

                // Aggregate for heatmap
                $day = date('Y-m-d', $date);
                $heatmap_data[$day] = ($heatmap_data[$day] ?? 0) + 1;
            }
        }

        // Sort commits by date (newest first)
        usort($all_commits, function($a, $b) {
            return $b['date'] - $a['date'];
        });

        // Prepare heatmap data
        $heatmap_json = [];
        foreach ($heatmap_data as $date => $count) {
            $heatmap_json[] = ['date' => $date, 'value' => $count];
        }

        // Render merged heatmap and activity feed
        $output = '<div id="git-charts">';
        $output .= "<div class='chart-container'>";
        $output .= "<h3>Activity Across All Repos</h3>";
        $output .= "<div id='heatmap'></div>";
        $output .= "<script>
            document.addEventListener('DOMContentLoaded', function() {
                var cal = new CalHeatmap();
                cal.paint({
                    data: " . json_encode($heatmap_json) . ",
                    date: { start: new Date(new Date().setFullYear(new Date().getFullYear() - 1)) },
                    range: 12,
                    domain: { type: 'month', label: { text: 'MMM', position: 'top' } },
                    subDomain: { type: 'day', width: 10, height: 10 },
                    scale: { color: { range: ['#ebedf0', '#9be9a8', '#40c463', '#30a14e', '#216e39'], type: 'linear' } },
                    itemSelector: '#heatmap'
                });
            });
        </script>";

        // Activity feed
        $output .= "<div class='activity-feed'>";
        $output .= "<h4>Recent Activity</h4>";
        foreach (array_slice($all_commits, 0, 10) as $commit) {
            $time_ago = human_time_diff($commit['date'], $current_time) . ' ago';
            $output .= "<div class='commit'>";
            $output .= "<img src='{$commit['logo']}' alt='{$commit['type']} mark' width='16' height='16'>";
            $output .= "<span>Pushed to <a href='{$commit['repo_url']}'>{$commit['repo']}</a> on {$commit['type']} ({$commit['username']})</span>";
            $output .= "<span>" . esc_html(substr($commit['message'], 0, 50)) . (strlen($commit['message']) > 50 ? '...' : '') . "</span>";
            $output .= "<span class='time-ago'>{$time_ago}</span>";
            $output .= "</div>";
        }
        $output .= "</div>";
        $output .= "</div>";
        $output .= '</div>';

        return $output;
    }
    public function render_charts_shortcode($atts = null ) {
        // Basic attributes (optional, could be used for filtering later)
        // $atts = shortcode_atts([
        //     // 'include_types' => 'all', // e.g., 'github,gitlab'
        //     // 'include_users' => 'all', // e.g., 'user1,user2'
        // ], $atts, 'git_activity_charts');
        $atts = $atts ?? [];
        $accounts = get_option('git_activity_accounts', []);
        if (empty($accounts)) {
             return '<p>' . __('No Git accounts configured. Please check the plugin settings.', 'git-activity-charts') . '</p>';
        }

        // Prepare data structure for JavaScript
        $chart_configs = [];
        $output = '<div id="git-charts">';

        foreach ($accounts as $index => $account) {
            $type = $account['type'];
            $username = $account['username'];
            $api_key = $account['api_key']; // Use stored API key
            $instance_url = $account['instance_url'];
            $use_color_logo = $account['use_color_logo'];
            $text_color = $account['text_color']; // Use stored text color
            $repos = $account['repos'];

            if (!isset($this->providers[$type])) {
                 $output .= "<div class='chart-container'><p class='error-message'>Invalid provider type configured: " . esc_html($type) . "</p></div>";
                 continue;
            }
            $provider = $this->providers[$type];

            // --- Logo Logic ---
            $logo_base_name = $type . '-mark-';
            $logo_variant = $use_color_logo ? 'color' : 'dark'; // Prefer color if checked
            $logo_filename = $logo_base_name . $logo_variant . '.svg';
            $logo_path_relative = "assets/images/{$type}/{$logo_filename}";

            if (!file_exists(GIT_ACTIVITY_CHARTS_PLUGIN_DIR . $logo_path_relative)) {
                 // Fallback to dark/color if the preferred doesn't exist
                 $fallback_variant = ($logo_variant === 'color') ? 'dark' : 'color';
                 $logo_filename = $logo_base_name . $fallback_variant . '.svg';
                 $logo_path_relative = "assets/images/{$type}/{$logo_filename}";
                 // If even the fallback doesn't exist, there will be no image src
                 if (!file_exists(GIT_ACTIVITY_CHARTS_PLUGIN_DIR . $logo_path_relative)) {
                     $logo_path_relative = ''; // No logo found
                 }
            }
            $logo_url = $logo_path_relative ? GIT_ACTIVITY_CHARTS_PLUGIN_URL . $logo_path_relative : '';

            // --- Title & Container ---
             $container_id_base = "git-chart-{$type}-{$username}-" . sanitize_title($username); // Base ID for the container

            // Special handling for GitHub (User contributions) vs Others (Repo commits)
            if ($type === 'github') {
                $chart_id = $container_id_base . '-contributions';
                 $title = sprintf(__('%s Contributions', 'git-activity-charts'), esc_html($username));
                 $profile_url = "https://github.com/" . esc_html($username); // GitHub profile URL

                $output .= "<div id='" . esc_attr($chart_id) . "-container' class='chart-container'>";
                 $output .= "<h3 class='chart-title' style='color: " . esc_attr($text_color) . ";'>";
                 $output .= esc_html($title);
                 if ($logo_url) {
                      $output .= " <a href='" . esc_url($profile_url) . "' target='_blank' rel='noopener noreferrer' class='provider-badge'><img src='" . esc_url($logo_url) . "' alt='" . esc_attr(ucfirst($type)) . " mark'></a>";
                 }
                 $output .= "</h3>";
                 $output .= "<canvas id='" . esc_attr($chart_id) . "'></canvas>"; // Canvas inside the title div
                $output .= "<div class='loading-placeholder'>" . __('Loading activity...', 'git-activity-charts') . "</div>"; // Placeholder
                $output .= "<div class='error-message' style='display: none;'></div>"; // For JS errors
                $output .= "</div>"; // Close chart-container

                 // Fetch data for GitHub user contributions
                 $cache_key = "git_activity_{$type}_{$username}_contributions";
                 $chart_data = get_transient($cache_key);
                 $error_message = null;

                 if (false === $chart_data) {
                        $fetch_result = $provider->fetch_activity($username, $repo_name, $api_key, $instance_url);
                    if ($fetch_result === false || isset($fetch_result['error'])) {
                        $error_message = isset($fetch_result['error']) ? $fetch_result['error'] : __('Failed to fetch data.', 'git-activity-charts');
                         $chart_data = ['error' => $error_message];
                         // Cache the error state for a shorter period
                        set_transient($cache_key, $chart_data, MINUTE_IN_SECONDS * 15);
                     } elseif (empty($fetch_result['labels']) || empty($fetch_result['commits'])) {
                        $error_message = __('No contribution data found.', 'git-activity-charts');
                        $chart_data = ['error' => $error_message, 'nodata' => true];
                        set_transient($cache_key, $chart_data, HOUR_IN_SECONDS); // Cache no-data result
                     } else {
                        $chart_data = $fetch_result;
                        set_transient($cache_key, $chart_data, HOUR_IN_SECONDS * 2); // Cache successful data longer
                     }
                 }

                $chart_configs[] = [
                     'canvasId' => $chart_id,
                     'type' => 'line',
                     'data' => isset($chart_data['error']) ? null : [ // Only include data if no error
                         'labels' => $chart_data['labels'] ?? [],
                         'datasets' => [[
                             'label' => sprintf(__('%s Contributions', 'git-activity-charts'), esc_js($username)),
                             'data' => $chart_data['commits'] ?? [],
                             'borderColor' => $provider->get_color(),
                             'backgroundColor' => $provider->get_color() . '33', // Semi-transparent fill
                             'fill' => false, // Or true if you want area chart
                             'tension' => 0.1,
                         ]]
                     ],
                    'options' => [ // Basic Chart.js options
                        'responsive' => true,
                        'maintainAspectRatio' => false, // Allows canvas resizing
                         'plugins' => [
                             'legend' => [ 'display' => false ], // Hide legend for single dataset
                             'title' => [ 'display' => false ], // Title is handled by H3
                         ],
                         'scales' => [
                             'x' => [
                                'title' => [ 'display' => true, 'text' => __('Week', 'git-activity-charts') ],
                                'grid' => [ 'display' => false ]
                            ],
                             'y' => [
                                'beginAtZero' => true,
                                'title' => [ 'display' => true, 'text' => __('Contributions', 'git-activity-charts') ]
                            ]
                        ]
                    ],
                    'error' => $error_message, // Pass error message to JS
                     'nodata' => isset($chart_data['nodata']) && $chart_data['nodata'], // Indicate if no data found
                 ];


            } else { // Handle GitLab, Gitea, Bitbucket (per repo)
                if (empty($repos)) {
                    $output .= "<div class='chart-container'><p class='error-message'>" . sprintf(__('No repositories specified for %s (%s). Please configure in settings.', 'git-activity-charts'), esc_html(ucfirst($type)), esc_html($username)) . "</p></div>";
                    continue;
                }

                foreach ($repos as $repo_name) {
                     $repo_name = trim($repo_name);
                     if (empty($repo_name)) continue;

                    $chart_id = $container_id_base . '-' . sanitize_title($repo_name);
                     $title = sprintf(__('%s Activity', 'git-activity-charts'), esc_html($repo_name));
                     $repo_url = $this->get_repo_url($type, $username, $repo_name, $instance_url);

                     $output .= "<div id='" . esc_attr($chart_id) . "-container' class='chart-container'>";
                     $output .= "<h3 class='chart-title' style='color: " . esc_attr($text_color) . ";'>";
                     $output .= esc_html($title);
                    if ($logo_url) {
                         $output .= " <a href='" . esc_url($repo_url) . "' target='_blank' rel='noopener noreferrer' class='provider-badge'><img src='" . esc_url($logo_url) . "' alt='" . esc_attr(ucfirst($type)) . " mark'></a>";
                    }
                    $output .= "</h3>";
                    $output .= "<canvas id='" . esc_attr($chart_id) . "'></canvas>";
                    $output .= "<div class='loading-placeholder'>" . __('Loading activity...', 'git-activity-charts') . "</div>";
                    $output .= "<div class='error-message' style='display: none;'></div>";
                    $output .= "</div>"; // Close chart-container

                     // Fetch data for this specific repo
                     $cache_key = "git_activity_{$type}_{$username}_" . sanitize_key($repo_name);
                     $chart_data = get_transient($cache_key);
                     $error_message = null;

                    if (false === $chart_data) {
                        $fetch_result = $provider->fetch_activity($username, $repo_name, $api_key, $instance_url);

                        if ($fetch_result === false || isset($fetch_result['error'])) {
                            $error_message = isset($fetch_result['error']) ? $fetch_result['error'] : sprintf(__('Failed to fetch data for %s.', 'git-activity-charts'), $repo_name);
                            $chart_data = ['error' => $error_message];
                             set_transient($cache_key, $chart_data, MINUTE_IN_SECONDS * 15);
                         } elseif (empty($fetch_result['labels']) || empty($fetch_result['commits'])) {
                            $error_message = __('No commit data found for this repository.', 'git-activity-charts');
                             $chart_data = ['error' => $error_message, 'nodata' => true];
                             set_transient($cache_key, $chart_data, HOUR_IN_SECONDS);
                         } else {
                            $chart_data = $fetch_result;
                             set_transient($cache_key, $chart_data, HOUR_IN_SECONDS * 2);
                         }
                    }

                     $chart_configs[] = [
                         'canvasId' => $chart_id,
                         'type' => 'line',
                         'data' => isset($chart_data['error']) ? null : [
                             'labels' => $chart_data['labels'] ?? [],
                             'datasets' => [[
                                 'label' => sprintf(__('%s Commits', 'git-activity-charts'), esc_js($repo_name)),
                                 'data' => $chart_data['commits'] ?? [],
                                 'borderColor' => $provider->get_color(),
                                 'backgroundColor' => $provider->get_color() . '33',
                                 'fill' => false,
                                 'tension' => 0.1,
                             ]]
                         ],
                         'options' => [
                             'responsive' => true,
                             'maintainAspectRatio' => false,
                              'plugins' => [
                                 'legend' => [ 'display' => false ],
                                 'title' => [ 'display' => false ],
                             ],
                             'scales' => [
                                'x' => [
                                    'title' => [ 'display' => true, 'text' => __('Week', 'git-activity-charts') ],
                                    'grid' => [ 'display' => false ]
                                ],
                                 'y' => [
                                    'beginAtZero' => true,
                                    'title' => [ 'display' => true, 'text' => __('Commits', 'git-activity-charts') ]
                                ]
                             ]
                         ],
                        'error' => $error_message,
                        'nodata' => isset($chart_data['nodata']) && $chart_data['nodata'],
                     ];
                } // End foreach repo
            } // End if/else provider type

        } // End foreach account

        $output .= '</div>'; // Close #git-charts

        // Localize script data *after* the loop
        wp_localize_script(
            'git-activity-charts-frontend',
            'gitActivityData', // Object name in JavaScript
            ['charts' => $chart_configs]
        );

        return $output;
    }


    private function get_repo_url($type, $username, $repo, $instance_url) {
        $username = esc_attr($username);
        $repo = esc_attr($repo);

        switch ($type) {
            case 'github':
                return "https://github.com/{$username}/{$repo}";
            case 'gitlab':
                $base = !empty($instance_url) ? esc_url($instance_url) : 'https://gitlab.com';
                return "{$base}/{$username}/{$repo}";
            case 'gitea':
                // Gitea requires instance URL
                 return !empty($instance_url) ? rtrim(esc_url($instance_url), '/') . "/{$username}/{$repo}" : '#';
            case 'bitbucket':
                return "https://bitbucket.org/{$username}/{$repo}";
            default:
                return '#';
        }
    }
}

new GitActivityCharts();