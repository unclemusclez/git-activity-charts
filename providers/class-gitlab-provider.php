<?php
class GitLabProvider extends ProviderBase {
    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        $base_url = $instance_url ?: 'https://gitlab.com';
        $url = "{$base_url}/api/v4/projects/" . urlencode("{$username}/{$repo}") . "/repository/commits?per_page=100";
        $response = wp_remote_get($url, [
            'headers' => [
                'Private-Token' => $api_key
            ]
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $commits = json_decode(wp_remote_retrieve_body($response), true);
        if (!$commits) {
            return false;
        }

        return $this->aggregate_commits($commits);
    }

    public function get_color() {
        return '#ff4500'; // GitLab orange
    }
}