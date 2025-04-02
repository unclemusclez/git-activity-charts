<?php
class GiteaProvider extends ProviderBase {
    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        if (!$instance_url) {
            return false;
        }
        $url = "{$instance_url}/api/v1/repos/{$username}/{$repo}/commits?limit=100";
        $headers = $api_key ? ['Authorization' => "token {$api_key}"] : [];
        $commits = $this->fetch_paginated_commits($url, $headers);

        if (empty($commits)) {
            return false;
        }

        return $this->aggregate_commits($commits);
    }

    public function get_color() {
        return '#00aabb';
    }
}