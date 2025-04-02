<?php
class GitLabProvider extends ProviderBase {
    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        $base_url = $instance_url ?: 'https://gitlab.com';
        $url = "{$base_url}/api/v4/projects/" . urlencode("{$username}/{$repo}") . "/repository/commits?per_page=100";
        $headers = $api_key ? ['Private-Token' => $api_key] : [];
        $commits = $this->fetch_paginated_commits($url, $headers);

        if (empty($commits)) {
            return false;
        }

        return $this->aggregate_commits($commits);
    }

    public function get_color() {
        return '#ff4500';
    }
}