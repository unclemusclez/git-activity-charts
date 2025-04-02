<?php
/*
Plugin Name: Git Activity Charts
Description: Display activity charts for GitHub, GitLab, Gitea, and Bitbucket repositories.
Version: 1.4
Author: Your Name
*/

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'providers/class-provider-base.php';
require_once plugin_dir_path(__FILE__) . 'providers/class-github-provider.php';
require_once plugin_dir_path(__FILE__) . 'providers/class-gitlab-provider.php';
require_once plugin_dir_path(__FILE__) . 'providers/class-gitea-provider.php';
require_once plugin_dir_path(__FILE__) . 'providers/class-bitbucket-provider.php';

class GitActivityCharts {
    private $providers = [];

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_shortcode('git_activity_charts', [$this, 'render_charts']);
        $this->init_providers();
    }

    private function init_providers() {
        $this->providers = [
            'github' => new GitHubProvider(),
            'gitlab' => new GitLabProvider(),
            'gitea' => new GiteaProvider(),
            'bitbucket' => new BitbucketProvider(),
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
            'default' => "#git-charts .chart-container {\n    margin-bottom: 2em;\n    padding: 1em;\n    border: 1px solid #ddd;\n    border-radius: 5px;\n}\n#git-charts h3 {\n    display: flex;\n    align-items: center;\n    gap: 0.5em;\n    margin-bottom: 1em;\n}\n.provider-badge img {\n    width: 24px;\n    height: 24px;\n    vertical-align: middle;\n}\n.error {\n    color: #d32f2f;\n    font-style: italic;\n    margin-top: 0.5em;\n}"
        ]);
    }

    public function sanitize_accounts($input) {
        $sanitized = [];
        foreach ($input as $account) {
            $type = sanitize_text_field($account['type']);
            $sanitized[] = [
                'type' => $type,
                'username' => sanitize_text_field($account['username']),
                'api_key' => sanitize_text_field($account['api_key']),
                'repos' => array_filter(array_map('sanitize_text_field', explode(',', $account['repos']))), // Filter out empty repos
                'instance_url' => isset($account['instance_url']) ? esc_url_raw($account['instance_url']) : '',
                'use_color_logo' => isset($account['use_color_logo']) ? (bool)$account['use_color_logo'] : false,
                'text_color' => isset($account['text_color']) ? sanitize_hex_color($account['text_color']) : $this->providers[$type]->get_color()
            ];
        }
        return $sanitized;
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Git Activity Charts Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('git_activity_options');
                $accounts = get_option('git_activity_accounts', []);
                $show_public_only = get_option('git_activity_show_public_only', false);
                $custom_css = get_option('git_activity_custom_css', '');
                ?>
                <table class="form-table">
                    <tr>
                        <th>Accounts</th>
                        <td>
                            <div id="accounts">
                                <?php foreach ($accounts as $index => $account) : ?>
                                    <div class="account">
                                        <select name="git_activity_accounts[<?php echo $index; ?>][type]">
                                            <option value="github" <?php selected($account['type'], 'github'); ?>>GitHub</option>
                                            <option value="gitlab" <?php selected($account['type'], 'gitlab'); ?>>GitLab</option>
                                            <option value="gitea" <?php selected($account['type'], 'gitea'); ?>>Gitea</option>
                                            <option value="bitbucket" <?php selected($account['type'], 'bitbucket'); ?>>Bitbucket</option>
                                        </select>
                                        <input type="text" name="git_activity_accounts[<?php echo $index; ?>][username]" value="<?php echo esc_attr($account['username']); ?>" placeholder="Username" />
                                        <input type="password" name="git_activity_accounts[<?php echo $index; ?>][api_key]" value="<?php echo esc_attr($account['api_key']); ?>" placeholder="API Key" class="api-key-input" />
                                        <button type="button" class="toggle-api-key">Show</button>
                                        <input type="text" name="git_activity_accounts[<?php echo $index; ?>][repos]" value="<?php echo esc_attr(implode(',', $account['repos'])); ?>" placeholder="Repos (comma-separated)" />
                                        <input type="url" name="git_activity_accounts[<?php echo $index; ?>][instance_url]" value="<?php echo esc_attr($account['instance_url']); ?>" placeholder="Instance URL (e.g., https://gitea.example.com)" />
                                        <label><input type="checkbox" name="git_activity_accounts[<?php echo $index; ?>][use_color_logo]" value="1" <?php checked($account['use_color_logo'], 1); ?> /> Use Color Logo</label>
                                        <input type="text" name="git_activity_accounts[<?php echo $index; ?>][text_color]" value="<?php echo esc_attr($account['text_color']); ?>" placeholder="Hex Color (e.g., #0366d6)" class="color-picker" />
                                        <button type="button" class="remove-account">Remove</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" id="add-account">Add Account</button>
                        </td>
                    </tr>
                    <tr>
                        <th>Show Public Repos Only</th>
                        <td>
                            <input type="checkbox" name="git_activity_show_public_only" value="1" <?php checked($show_public_only, 1); ?> />
                            <label>Limit display to public repositories</label>
                        </td>
                    </tr>
                    <tr>
                        <th>Custom CSS</th>
                        <td>
                            <textarea name="git_activity_custom_css" rows="10" cols="50"><?php echo esc_textarea($custom_css); ?></textarea>
                            <p class="description">Customize the appearance of charts and badges</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
            document.getElementById('add-account').addEventListener('click', function() {
                const index = document.querySelectorAll('.account').length;
                const div = document.createElement('div');
                div.className = 'account';
                div.innerHTML = `
                    <select name="git_activity_accounts[${index}][type]">
                        <option value="github">GitHub</option>
                        <option value="gitlab">GitLab</option>
                        <option value="gitea">Gitea</option>
                        <option value="bitbucket">Bitbucket</option>
                    </select>
                    <input type="text" name="git_activity_accounts[${index}][username]" placeholder="Username" />
                    <input type="password" name="git_activity_accounts[${index}][api_key]" placeholder="API Key" class="api-key-input" />
                    <button type="button" class="toggle-api-key">Show</button>
                    <input type="text" name="git_activity_accounts[${index}][repos]" placeholder="Repos (comma-separated)" />
                    <input type="url" name="git_activity_accounts[${index}][instance_url]" placeholder="Instance URL (e.g., https://gitea.example.com)" />
                    <label><input type="checkbox" name="git_activity_accounts[${index}][use_color_logo]" value="1" /> Use Color Logo</label>
                    <input type="text" name="git_activity_accounts[${index}][text_color]" placeholder="Hex Color (e.g., #0366d6)" class="color-picker" />
                    <button type="button" class="remove-account">Remove</button>
                `;
                document.getElementById('accounts').appendChild(div);
            });

            document.addEventListener('click', function(e) {
                if (e.target.className === 'remove-account') {
                    e.target.parentElement.remove();
                } else if (e.target.className === 'toggle-api-key') {
                    const input = e.target.previousElementSibling;
                    input.type = input.type === 'password' ? 'text' : 'password';
                    e.target.textContent = input.type === 'password' ? 'Show' : 'Hide';
                }
            });
        </script>
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

    public function render_charts() {
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            return '<p>Please log in to view activity charts.</p>';
        }

        wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
        wp_enqueue_style('git-activity-charts-style', plugins_url('assets/style.css', __FILE__), [], '1.0', 'all');
        $custom_css = get_option('git_activity_custom_css', '');
        if ($custom_css) {
            wp_add_inline_style('git-activity-charts-style', $custom_css);
        }

        $accounts = get_option('git_activity_accounts', []);
        $show_public_only = get_option('git_activity_show_public_only', false);
        $output = '<div id="git-charts">';

        foreach ($accounts as $account) {
            $type = $account['type'];
            $username = $account['username'];
            $api_key = $account['api_key'];
            $instance_url = $account['instance_url'];
            $use_color_logo = $account['use_color_logo'];
            $text_color = $account['text_color'];
            $provider = $this->providers[$type] ?? null;

            if (!$provider) {
                $output .= "<p>Invalid provider type: {$type}</p>";
                continue;
            }

            foreach ($account['repos'] as $repo) {
                if (empty($repo)) continue; // Skip empty repo names

                $cache_key = "git_activity_{$type}_{$username}_{$repo}";
                $cached_data = get_transient($cache_key);

                if ($cached_data === false) {
                    $data = $provider->fetch_activity($username, $repo, $api_key, $instance_url);
                    if ($data && !empty($data['labels']) && !empty($data['commits'])) {
                        set_transient($cache_key, $data, HOUR_IN_SECONDS);
                    } else {
                        if (current_user_can('manage_options')) {
                            $error_msg = "Failed to fetch data for {$repo} ({$type} - {$username}). Check API key or repository access.";
                            update_option("git_activity_error_{$cache_key}", $error_msg);
                        }
                        $data = ['labels' => [], 'commits' => []];
                    }
                } else {
                    $data = $cached_data;
                }

                if ($show_public_only && empty($api_key)) {
                    continue;
                }

                $output .= "<div class='chart-container'>";
                $logo_variant = $use_color_logo && file_exists(plugin_dir_path(__FILE__) . "assets/{$type}/{$type}-mark-color.svg") ? 'color' : (file_exists(plugin_dir_path(__FILE__) . "assets/{$type}/{$type}-mark-dark.svg") ? 'dark' : 'white');
                $logo_path = plugins_url("assets/{$type}/{$type}-mark-{$logo_variant}.svg", __FILE__);
                $repo_url = $this->get_repo_url($type, $username, $repo, $instance_url);
                $output .= "<h3 style='color: {$text_color};'>{$repo} ({$type} - {$username}) <a href='{$repo_url}' class='provider-badge'><img src='{$logo_path}' alt='{$type} mark' width='24' height='24'></a></h3>";

                if (!empty($data['labels']) && !empty($data['commits'])) {
                    $canvas_id = "chart-{$type}-{$username}-{$repo}";
                    $output .= "<canvas id='{$canvas_id}'></canvas>";
                    $output .= "<script>
                        document.addEventListener('DOMContentLoaded', function() {
                            new Chart(document.getElementById('{$canvas_id}'), {
                                type: 'line',
                                data: {
                                    labels: " . json_encode($data['labels']) . ",
                                    datasets: [{
                                        label: 'Commits',
                                        data: " . json_encode($data['commits']) . ",
                                        borderColor: '" . $provider->get_color() . "',
                                        fill: false,
                                        tension: 0.1
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    scales: {
                                        x: { title: { display: true, text: 'Date' } },
                                        y: { title: { display: true, text: 'Commits' } }
                                    },
                                    plugins: {
                                        tooltip: {
                                            callbacks: {
                                                title: function() { return '{$type} - {$username}'; }
                                            }
                                        }
                                    }
                                }
                            });
                        });
                    </script>";
                } else {
                    $output .= "<p>No activity data available for {$repo}.</p>";
                    if ($error = get_option("git_activity_error_{$cache_key}")) {
                        $output .= current_user_can('manage_options') ? "<p class='error'>{$error}</p>" : '';
                        delete_option("git_activity_error_{$cache_key}");
                    }
                }
                $output .= "</div>";
            }
        }

        $output .= '</div>';
        return $output;
    }

    private function get_repo_url($type, $username, $repo, $instance_url) {
        switch ($type) {
            case 'github':
                return "https://github.com/{$username}/{$repo}";
            case 'gitlab':
                return ($instance_url ?: 'https://gitlab.com') . "/{$username}/{$repo}";
            case 'gitea':
                return $instance_url . "/{$username}/{$repo}";
            case 'bitbucket':
                return "https://bitbucket.org/{$username}/{$repo}";
            default:
                return '#';
        }
    }
}

new GitActivityCharts();