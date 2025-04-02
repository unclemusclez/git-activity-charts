<?php
class GitHubProvider extends ProviderBase {
    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        $url = "https://api.github.com/repos/{$username}/{$repo}/stats/commit_activity";
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => "token {$api_key}",
                'Accept' => 'application/vnd.github.v3+json'
            ]
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!$data) {
            return false;
        }

        $labels = array_map(function($week) {
            return date('Y-m-d', $week['week']);
        }, $data);
        $commits = array_map(function($week) {
            return $week['total'];
        }, $data);

        return ['labels' => $labels, 'commits' => $commits];
    }

    public function get_color() {
        return '#0366d6'; // GitHub blue
    }
}