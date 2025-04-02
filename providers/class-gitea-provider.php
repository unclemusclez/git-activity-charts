<?php
class GiteaProvider extends ProviderBase {
    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        if (!$instance_url) {
            return false; // Gitea requires a custom instance URL
        }
        $url = "{$instance_url}/api/v1/repos/{$username}/{$repo}/commits?limit=100";
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => "token {$api_key}"
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
        return '#00aabb'; // Gitea teal-ish
    }
}