<?php

if (!defined('ABSPATH')) {
    exit;
}

class GitLabProvider extends ProviderBase {

    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        $base_url = !empty($instance_url) ? rtrim($instance_url, '/') : 'https://gitlab.com';
        $project_path = urlencode("{$username}/{$repo}"); // Needs to be URL encoded
        $commits_url = "{$base_url}/api/v4/projects/{$project_path}/repository/commits";

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        ];
        if (!empty($api_key)) {
            $headers['Private-Token'] = $api_key;
        }

        $all_commits = [];
        $page = 1;
        $per_page = 100; // Max allowed by GitLab

        do {
            $paged_url = add_query_arg([
                'per_page' => $per_page,
                'page' => $page,
                 'order' => 'default', // Ensure chronological order if needed, default is usually reverse chrono
                 'all' => 'true' // Try to get commits from all branches, might need specific branch name if this fails
            ], $commits_url);


            $response = $this->make_request($paged_url, $headers);

            if (is_wp_error($response)) {
                 // Return error if the first page fails
                 if ($page === 1) {
                    return ['error' => sprintf(__('GitLab API Error for %s: %s', 'git-activity-charts'), $repo, $response->get_error_message())];
                 } else {
                    // Log error for subsequent pages but continue with what we have
                    error_log("Git Activity Charts - GitLab pagination error for {$repo} on page {$page}: " . $response->get_error_message());
                    break; // Stop pagination on error
                 }
            }

            if (!is_array($response) || empty($response)) {
                break; // No more commits or unexpected response
            }

            $all_commits = array_merge($all_commits, $response);
            $page++;

            // Check if the number of results fetched is less than per_page, indicating the last page
            if (count($response) < $per_page) {
                break;
            }

             // Safety break for infinite loop potential
             if ($page > 50) { // Limit to 5000 commits max
                 error_log("Git Activity Charts - GitLab pagination limit reached for {$repo}");
                 break;
             }

        } while (true);

        if (empty($all_commits) && $page === 1) {
             // Handle case where repo exists but has no commits, or access denied without error msg
             // Could check the initial project endpoint first, but let's assume no commits for now.
              return ['labels' => [], 'commits' => []];
        }


        // Aggregate commits by week - GitLab usually provides 'committed_date'
        return $this->aggregate_commits_by_week($all_commits, 'committed_date');
    }

    public function get_color() {
        return '#fc6d26'; // GitLab orange
    }
}