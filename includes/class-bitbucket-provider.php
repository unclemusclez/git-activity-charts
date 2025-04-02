<?php

if (!defined('ABSPATH')) {
    exit;
}

class BitbucketProvider extends ProviderBase {

    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        // Instance URL is ignored for Bitbucket Cloud API v2.0
        $base_api_url = "https://api.bitbucket.org/2.0/repositories/{$username}/{$repo}/commits";

        $headers = [
            'Accept' => 'application/json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        ];
        if (!empty($api_key)) {
            // Bitbucket Cloud API v2 uses OAuth 2 Bearer tokens primarily,
            // but App Passwords might be passed as basic auth (user:app_password) or bearer.
            // Assuming Bearer token for simplicity here.
             // For App Passwords used as basic auth, the header would be:
             // 'Authorization' => 'Basic ' . base64_encode("{$username}:{$api_key}")
            $headers['Authorization'] = "Bearer {$api_key}";
        }

        $all_commits = [];
        $next_url = add_query_arg([
            'pagelen' => 100, // Max allowed by Bitbucket API v2
            'fields' => 'values.date,values.hash,next', // Request only necessary fields + next link
             // 'sort' => '-date' // Default is reverse chronological, explicitly stating if needed
        ], $base_api_url);
        $page_count = 0; // Safety counter

        do {
            $response = $this->make_request($next_url, $headers);

             if (is_wp_error($response)) {
                 if (empty($all_commits)) { // Error on the first request
                     return ['error' => sprintf(__('Bitbucket API Error for %s: %s', 'git-activity-charts'), $repo, $response->get_error_message())];
                 } else { // Error on subsequent pages
                    error_log("Git Activity Charts - Bitbucket pagination error for {$repo}: " . $response->get_error_message());
                    break; // Stop pagination
                 }
             }

            if (!is_array($response) || !isset($response['values']) || !is_array($response['values'])) {
                 error_log("Git Activity Charts - Bitbucket unexpected response structure for {$repo}: " . print_r($response, true));
                 break; // Unexpected response structure
            }

            $all_commits = array_merge($all_commits, $response['values']);

            // Check for the 'next' link for pagination
            if (isset($response['next']) && !empty($response['next'])) {
                $next_url = $response['next'];
            } else {
                $next_url = null; // No more pages
            }

            $page_count++;
             // Safety break
             if ($page_count > 50) { // Limit to 5000 commits
                 error_log("Git Activity Charts - Bitbucket pagination limit reached for {$repo}");
                 break;
             }

        } while ($next_url);

        if (empty($all_commits) && $page_count <= 1 && !isset($response['error'])) {
            // No commits found, not necessarily an error
             return ['labels' => [], 'commits' => []];
         }

        // Aggregate commits by week - Bitbucket uses 'date' key directly in the commit object
        return $this->aggregate_commits_by_week($all_commits, 'date');
    }

    public function get_color() {
        return '#0052CC'; // Bitbucket blue
    }
}