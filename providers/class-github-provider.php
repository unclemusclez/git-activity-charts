<?php
class GitHubProvider extends ProviderBase {
    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        $url = "https://api.github.com/repos/{$username}/{$repo}/stats/commit_activity";
        $headers = ['Accept' => 'application/vnd.github.v3+json'];
        if ($api_key) {
            $headers['Authorization'] = "token {$api_key}";
        }
        $response = wp_remote_get($url, ['headers' => $headers]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
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
        return '#0366d6';
    }
}