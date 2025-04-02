<?php

if (!defined('ABSPATH')) {
    exit;
}

class GiteaProvider extends ProviderBase {

    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        if (empty($instance_url)) {
            return ['error' => __('Instance URL is required for Gitea.', 'git-activity-charts')];
        }

        $base_url = rtrim($instance_url, '/');
        // Gitea API path structure
        $commits_url = "{$base_url}/api/v1/repos/{$username}/{$repo}/commits";

        $headers = [
            'Accept' => 'application/json',
             'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        ];
        if (!empty($api_key)) {
             // Gitea uses 'token' keyword or 'Authorization: Bearer <token>'
             // Let's try standard Authorization Bearer first, fallback to token query param if needed
             $headers['Authorization'] = "Bearer {$api_key}";
             // Alternative: $commits_url = add_query_arg('access_token', $api_key, $commits_url);
        }

        $all_commits = [];
        $page = 1;
        $limit = 100; // Gitea often uses 'limit' instead of 'per_page'

        do {
            $paged_url = add_query_arg([
                'limit' => $limit,
                'page' => $page,
                // Gitea might not support ordering, commits are usually reverse-chronological
            ], $commits_url);

            $response = $this->make_request($paged_url, $headers);

             if (is_wp_error($response)) {
                 if ($page === 1) {
                    return ['error' => sprintf(__('Gitea API Error for %s: %s', 'git-activity-charts'), $repo, $response->get_error_message())];
                 } else {
                    error_log("Git Activity Charts - Gitea pagination error for {$repo} on page {$page}: " . $response->get_error_message());
                    break;
                 }
             }

            if (!is_array($response) || empty($response)) {
                break; // No more commits
            }

            $all_commits = array_merge($all_commits, $response);
            $page++;

            // Gitea API might not give a clear indicator of the last page other than returning fewer items than the limit.
            if (count($response) < $limit) {
                break;
            }

             // Safety break
            if ($page > 50) {
                 error_log("Git Activity Charts - Gitea pagination limit reached for {$repo}");
                 break;
            }

        } while (true);

         if (empty($all_commits) && $page === 1) {
              return ['labels' => [], 'commits' => []];
         }

        // Aggregate commits by week - Gitea usually has commit->committer->date or commit->author->date
        // The base class helper should handle these common structures.
        return $this->aggregate_commits_by_week($all_commits); // Let helper find date
    }

    public function get_color() {
        return '#609926'; // Gitea green
    }
}