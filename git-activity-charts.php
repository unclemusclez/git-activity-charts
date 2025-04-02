" />
                                        <input type="text" name="git_activity_accounts[<?php echo $index; ?>][custom_logo]" value="<?php echo esc_attr($account['custom_logo']); ?>" placeholder="Custom Logo URL (e.g., https://example.com/logo.svg)" />
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
                            <p class="description">Customize the appearance of charts and activity feed</p>
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
                        <option value="custom">Custom</option>
                    </select>
                    <input type="text" name="git_activity_accounts[${index}][username]" placeholder="Username" />
                    <input type="password" name="git_activity_accounts[${index}][api_key]" placeholder="API Key" class="api-key-input" />
                    <button type="button" class="toggle-api-key">Show</button>
                    <input type="text" name="git_activity_accounts[${index}][repos]" placeholder="Repos (comma-separated)" />
                    <input type="url" name="git_activity_accounts[${index}][instance_url]" placeholder="Instance URL (e.g., https://gitea.example.com)" />
                    <label><input type="checkbox" name="git_activity_accounts[${index}][use_color_logo]" value="1" /> Use Color Logo</label>
                    <input type="text" name="git_activity_accounts[${index}][text_color]" placeholder="Hex Color (e.g., #0366d6)" class="color-picker" />
                    <input type="text" name="git_activity_accounts[${index}][custom_logo]" placeholder="Custom Logo URL (e.g., https://example.com/logo.svg)" />
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

            $cache_key = "git_activity_{$type}_{$username}";
            $cached_data = get_transient($cache_key);

            if ($cached_data === false) {
                $result = $provider['fetch']($username, $api_key, $instance_url, $repos);
                if ($result['data']) {
                    $commits = $result['data'];
                    set_transient($cache_key, $commits, HOUR_IN_SECONDS);
                } else {
                    if (current_user_can('manage_options')) {
                        $error_msg = "Failed to fetch data for {$username} ({$type}). Check API key or repository access.";
                        update_option("git_activity_error_{$cache_key}", $error_msg);
                    }
                    continue;
                }
            } else {
                $commits = $cached_data;
            }

            foreach ($commits as $commit) {
                $date = $this->get_commit_date($commit, $type === 'bitbucket' ? 'date' : ($type === 'github' ? 'committed_date' : 'committed_date'));
                if (!$date) continue;

                $all_commits[] = [
                    'date' => $date,
                    'message' => $commit['message'] ?? "Contribution",
                    'repo' => $commit['repo'] ?? $username,
                    'type' => $type,
                    'username' => $username,
                    'repo_url' => $commit['repo_url'] ?? $this->get_fallback_repo_url($type, $username, $commit['repo'] ?? $username, $instance_url),
                    'logo' => $custom_logo ?: plugins_url("assets/{$type}/{$type}-mark-" . ($use_color_logo && file_exists(plugin_dir_path(__FILE__) . "assets/{$type}/{$type}-mark-color.svg") ? 'color' : (file_exists(plugin_dir_path(__FILE__) . "assets/{$type}/{$type}-mark-dark.svg") ? 'dark' : 'white')) . ".svg", __FILE__)
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

    private function get_commit_date($commit, $date_key) {
        if ($date_key === 'commit.committer.date') {
            return isset($commit['commit']['committer']['date']) ? strtotime($commit['commit']['committer']['date']) : false;
        }
        return isset($commit[$date_key]) ? strtotime($commit[$date_key]) : (isset($commit['created_at']) ? strtotime($commit['created_at']) : false);
    }

    private function get_fallback_repo_url($type, $username, $repo, $instance_url) {
        switch ($type) {
            case 'github':
                return "https://github.com/{$username}/{$repo}";
            case 'gitlab':
                return ($instance_url ?: 'https://gitlab.com') . "/{$username}/{$repo}";
            case 'gitea':
                return $instance_url . "/{$username}/{$repo}";
            case 'bitbucket':
                return "https://bitbucket.org/{$username}/{$repo}";
            case 'custom':
                return $instance_url ? "{$instance_url}/{$username}/{$repo}" : '#';
            default:
                return '#';
        }
    }
}

new GitActivityCharts();