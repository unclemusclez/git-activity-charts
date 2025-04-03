<?php
if (!defined('ABSPATH')) {
    exit;
}

class GitActivityCharts {
    private $providers = [];

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
                'icon' => plugins_url('assets/github/github-mark-dark.svg', GIT_ACTIVITY_CHARTS_PLUGIN_DIR)
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
                'icon' => plugins_url('assets/gitlab/gitlab-mark-dark.svg', GIT_ACTIVITY_CHARTS_PLUGIN_DIR)
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
            function() {
                require_once GIT_ACTIVITY_CHARTS_PLUGIN_DIR . 'includes/admin-settings-page.php';
                git_activity_charts_settings_page_html($this);
            }
        );
    }

    public function register_settings() {
        register_setting('git_activity_options', 'git_activity_accounts', [
            'sanitize_callback' => [$this, 'sanitize_accounts'],
            'type' => 'array',
            'default' => [],
        ]);
        register_setting('git_activity_options', 'git_activity_custom_css', [
            'sanitize_callback' => [$this, 'sanitize_css'],
            'type' => 'string',
            'default' => ''
        ]);
    }

    public function sanitize_css($input) {
        return wp_strip_all_tags($input);
    }
    public function sanitize_accounts($input) {
        $sanitized = [];
        foreach ($input as $account) {
            $type = sanitize_text_field($account['type']);
            // Handle repos: it could be a string (from form) or an array (from saved option)
            $repos = $account['repos'];
            if (is_string($repos)) {
                $repos = array_filter(array_map('sanitize_text_field', explode(',', $repos)));
            } elseif (is_array($repos)) {
                $repos = array_filter(array_map('sanitize_text_field', $repos));
            } else {
                $repos = [];
            }
            $sanitized[] = [
                'type' => $type,
                'username' => sanitize_text_field($account['username']),
                'api_key' => sanitize_text_field($account['api_key']),
                'repos' => $repos,
                'instance_url' => isset($account['instance_url']) ? esc_url_raw($account['instance_url']) : '',
                'use_color_logo' => isset($account['use_color_logo']) ? (bool)$account['use_color_logo'] : false,
                'text_color' => isset($account['text_color']) ? sanitize_hex_color($account['text_color']) : $this->providers[$type]['color'] ?? '#000000',
                'custom_logo' => isset($account['custom_logo']) ? esc_url_raw($account['custom_logo']) : ''
            ];
        }
        return $sanitized;
    }

    public function enqueue_admin_scripts($hook) {
        if ('settings_page_git-activity-charts' !== $hook) {
            return;
        }
        // Enqueue WordPress media uploader
        wp_enqueue_media();
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_script(
            'git-activity-charts-admin',
            GIT_ACTIVITY_CHARTS_PLUGIN_URL . 'assets/js/git-activity-charts-admin.js',
            ['jquery', 'wp-color-picker', 'media-upload'],
            GIT_ACTIVITY_CHARTS_VERSION,
            true
        );
        wp_enqueue_style(
            'git-activity-charts-admin',
            GIT_ACTIVITY_CHARTS_PLUGIN_URL . 'assets/css/git-activity-charts-admin.css',
            [],
            GIT_ACTIVITY_CHARTS_VERSION
        );
    }

    public function enqueue_frontend_scripts() {
        wp_enqueue_script(
            'cal-heatmap',
            'https://cdn.jsdelivr.net/npm/cal-heatmap@4.2.1/dist/cal-heatmap.min.js',
            [],
            null,
            true
        );
        wp_enqueue_style(
            'cal-heatmap-css',
            'https://cdn.jsdelivr.net/npm/cal-heatmap@4.2.1/dist/cal-heatmap.css',
            [],
            null
        );
        wp_enqueue_style(
            'git-activity-charts-frontend',
            GIT_ACTIVITY_CHARTS_PLUGIN_URL . 'assets/css/git-activity-charts-frontend.css',
            [],
            GIT_ACTIVITY_CHARTS_VERSION
        );
        $custom_css = get_option('git_activity_custom_css', '');
        if (!empty(trim($custom_css))) {
            wp_add_inline_style('git-activity-charts-frontend', $custom_css);
        }
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

    public function get_provider_color($type) {
        return $this->providers[$type]['color'] ?? '#000000';
    }
    private function get_commit_date($commit, $date_key) {
        return isset($commit[$date_key]) ? strtotime($commit[$date_key]) : (isset($commit['created_at']) ? strtotime($commit['created_at']) : false);
    }

    private function get_fallback_repo_url($type, $username, $repo, $instance_url) {
        $username = esc_attr($username);
        $repo = esc_attr($repo);
        switch ($type) {
            case 'github':
                return "https://github.com/{$username}/{$repo}";
            case 'gitlab':
                $base = !empty($instance_url) ? esc_url($instance_url) : 'https://gitlab.com';
                return "{$base}/{$username}/{$repo}";
            case 'custom':
                return $instance_url ? rtrim(esc_url($instance_url), '/') . "/{$username}/{$repo}" : '#';
            default:
                return '#';
        }
    }
        
    public function render_charts_shortcode($atts = null) {
        // Enqueue scripts and styles with cache-busting
        $cache_buster = time();
        wp_enqueue_script('d3', plugins_url('assets/js/d3.min.js', GIT_ACTIVITY_CHARTS_PLUGIN_FILE) . "?v=$cache_buster", [], '7.8.5', true);
        wp_enqueue_script('cal-heatmap', plugins_url('assets/js/cal-heatmap.min.js', GIT_ACTIVITY_CHARTS_PLUGIN_FILE) . "?v=$cache_buster", ['d3'], '4.2.1', true);
        wp_enqueue_style('cal-heatmap-css', plugins_url('assets/css/cal-heatmap.css', GIT_ACTIVITY_CHARTS_PLUGIN_FILE) . "?v=$cache_buster", [], '4.2.1');

        $accounts = get_option('git_activity_accounts', []);
        if (empty($accounts)) {
            return '<p>' . __('No Git accounts configured. Please check the plugin settings.', 'git-activity-charts') . '</p>';
        }

        $all_commits = [];
        $heatmap_data = [];
        $current_time = time();

        foreach ($accounts as $account) {
            $type = $account['type'];
            $username = $account['username'];
            $api_key = $account['api_key'];
            $instance_url = $account['instance_url'];
            $use_color_logo = isset($account['use_color_logo']) ? (bool)$account['use_color_logo'] : false;
            $custom_logo = isset($account['custom_logo']) ? $account['custom_logo'] : '';
            $repos = $account['repos'];
            $provider = $this->providers[$type] ?? null;

            if (!$provider) continue;

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

            $icon = $custom_logo;
            if (!$icon) {
                if ($type === 'custom') {
                    $icon = plugins_url('assets/images/default-mark-dark.svg', GIT_ACTIVITY_CHARTS_PLUGIN_FILE);
                } else {
                    $logo_variant = $use_color_logo ? 'color' : 'dark';
                    $logo_filename = "{$type}-mark-{$logo_variant}.svg";
                    $logo_path = "assets/images/{$logo_filename}";
                    if (!file_exists(GIT_ACTIVITY_CHARTS_PLUGIN_DIR . $logo_path)) {
                        $logo_variant = ($logo_variant === 'color') ? 'dark' : 'color';
                        $logo_filename = "{$type}-mark-{$logo_variant}.svg";
                        $logo_path = "assets/images/{$logo_filename}";
                    }
                    $icon = plugins_url($logo_path, GIT_ACTIVITY_CHARTS_PLUGIN_FILE);
                }
            }

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

                $day = date('Y-m-d', $date);
                // Use contributionCount for GitHub, default to 1 for others
                $contribution_count = $type === 'github' ? ($commit['contributionCount'] ?? 1) : 1;
                $heatmap_data[$day] = ($heatmap_data[$day] ?? 0) + $contribution_count;
            }
        }

        usort($all_commits, function($a, $b) {
            return $b['date'] - $a['date'];
        });

        $heatmap_json = [];
        foreach ($heatmap_data as $date => $count) {
            $heatmap_json[] = ['date' => $date, 'value' => $count];
        }

        $max_value = max(array_column($heatmap_json, 'value', 1)) ?: 1;

        $output = '<div id="git-charts">';
        $output .= '<div class="chart-container">';
        $output .= '<h3>Activity Across All Repos</h3>';
        $output .= '<div style="max-width: 100%; overflow-x: auto;">'; // Enable horizontal scrolling
        $output .= '<div id="heatmap" style="min-height: 150px;"></div>';

        if (empty($heatmap_json)) {
            $output .= '<p class="no-data">No activity data available for the past year.</p>';
        } else {
            // Inline CSS to match GitHub's style
            $output .= '<style>
                .cal-heatmap-container { width: 100%; }
                .cal-heatmap-container .graph { margin: 0; }
                .cal-heatmap-container .graph .subdomain { margin: 0; padding: 0; }
                .cal-heatmap-container .graph-rect { stroke: none; }
                .cal-heatmap-container .subdomain { fill: #ebedf0; width: 10px; height: 10px; border-radius: 1px; }
                .cal-heatmap-container .domain-label { font-size: 10px; }
                .cal-heatmap-legend { display: flex; align-items: center; justify-content: flex-end; margin-top: 10px; }
                .cal-heatmap-legend span { margin-right: 5px; }
                .cal-heatmap-legend .legend-square { display: inline-block; width: 10px; height: 10px; margin-right: 5px; border-radius: 1px; }
            </style>';
            $output .= '<div id="heatmap-legend" class="cal-heatmap-legend"></div>';
            $output .= "<script type='text/javascript'>
                console.log('Script loaded at: ' + new Date().toISOString());
                
                window.addEventListener('load', function() {
                    console.log('Window loaded at: ' + new Date().toISOString());
                    if (typeof d3 === 'undefined') {
                        console.error('D3.js not loaded.');
                        document.getElementById('heatmap').innerHTML = '<p>Error: D3.js not loaded.</p>';
                        return;
                    }
                    if (typeof CalHeatmap === 'undefined') {
                        console.error('CalHeatmap not loaded.');
                        document.getElementById('heatmap').innerHTML = '<p>Error: CalHeatmap not loaded.</p>';
                        return;
                    }

                    var startDate = new Date();
                    startDate.setFullYear(startDate.getFullYear() - 1);
                    startDate.setDate(startDate.getDate() + 1);
                    var endDate = new Date();
                    console.log('Heatmap start date: ' + startDate.toISOString());
                    console.log('Heatmap end date: ' + endDate.toISOString());
                    console.log('Heatmap data:', " . json_encode($heatmap_json) . ");
                    console.log('Max contribution value: " . $max_value . "');
                    console.log('Raw commits:', " . json_encode($all_commits) . ");

                    try {
                        var cal = new CalHeatmap();
                        cal.paint({
                            data: " . json_encode($heatmap_json) . ",
                            date: { start: startDate, weekStartOn: 0 }, // Start week on Sunday (0 = Sunday, 1 = Monday)
                            range: 53,
                            domain: { type: 'week', gutter: 3, label: { text: 'MMM', position: 'top' } },
                            subDomain: { type: 'day', width: 10, height: 10, radius: 1, gutter: 3 },
                            scale: { 
                                color: { 
                                    range: ['#ebedf0', '#9be9a8', '#40c463', '#30a14e', '#216e39'], 
                                    type: 'linear',
                                    domain: [0, " . $max_value . "]
                                } 
                            },
                            itemSelector: '#heatmap',
                            tooltip: { 
                                enabled: true, 
                                text: function(date, value) { 
                                    return value + ' contribution' + (value === 1 ? '' : 's') + ' on ' + date.toLocaleDateString(); 
                                } 
                            }
                        });

                        var legend = document.getElementById('heatmap-legend');
                        legend.innerHTML = '<span>Less</span>' +
                            '<div class=\"legend-square\" style=\"background-color: #ebedf0;\"></div>' +
                            '<div class=\"legend-square\" style=\"background-color: #9be9a8;\"></div>' +
                            '<div class=\"legend-square\" style=\"background-color: #40c463;\"></div>' +
                            '<div class=\"legend-square\" style=\"background-color: #30a14e;\"></div>' +
                            '<div class=\"legend-square\" style=\"background-color: #216e39;\"></div>' +
                            '<span>More</span>';

                        console.log('Heatmap initialized successfully.');
                    } catch (e) {
                        console.error('Heatmap initialization failed:', e);
                        document.getElementById('heatmap').innerHTML = '<p>Error rendering heatmap: ' + e.message + '</p>';
                    }
                });
            </script>";
        }

        $output .= '</div>'; // Close scrolling div

        $output .= '<div class="activity-feed">';
        $output .= '<h4>Recent Activity</h4>';
        foreach (array_slice($all_commits, 0, 10) as $commit) {
            $time_ago = human_time_diff($commit['date'], $current_time) . ' ago';
            $output .= '<div class="commit">';
            $output .= "<img src='{$commit['logo']}' alt='{$commit['type']} mark' width='16' height='16'>";
            $output .= "<span>Pushed to <a href='{$commit['repo_url']}'>{$commit['repo']}</a> on {$commit['type']} ({$commit['username']})</span>";
            $output .= '<span>' . esc_html(substr($commit['message'], 0, 50)) . (strlen($commit['message']) > 50 ? '...' : '') . '</span>';
            $output .= "<span class='time-ago'>{$time_ago}</span>";
            $output .= '</div>';
        }
        $output .= '</div>';
        $output .= '</div>';
        $output .= '</div>';

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