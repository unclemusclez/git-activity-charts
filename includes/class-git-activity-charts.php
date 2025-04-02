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
                'icon' => plugins_url('assets/github/github-mark-dark.svg', GIT_ACTIVITY_CHARTS_PLUGIN_FILE)
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
                'icon' => plugins_url('assets/gitlab/gitlab-mark-dark.svg', GIT_ACTIVITY_CHARTS_PLUGIN_FILE)
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
        if ('settings_page_git-activity-charts' !== $hook) {
            return;
        }
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_script(
            'git-activity-charts-admin',
            GIT_ACTIVITY_CHARTS_PLUGIN_URL . 'assets/js/git-activity-charts-admin.js',
            ['jquery', 'wp-color-picker'],
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