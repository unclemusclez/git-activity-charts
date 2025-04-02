<?php

if (!defined('ABSPATH')) {
    exit;
}

class GitLabProvider extends ProviderBase {

    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        $base_url = !empty($instance_url) ? rtrim($instance_url, '/') : 'https://gitlab.com';
        $graphql_endpoint = "{$base_url}/api/graphql"; // Standard GraphQL endpoint

        // GitLab GraphQL requires a Personal Access Token with 'read_api' or 'read_user' scope
        if (empty($api_key)) {
            return ['error' => sprintf(__('API Key (Personal Access Token with read_api scope) is required for GitLab GraphQL API for %s.', 'git-activity-charts'), $repo)];
        }

        $headers = [
            'Authorization' => "Bearer {$api_key}", // Use Bearer token for GraphQL
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        ];

        $fullPath = "{$username}/{$repo}"; // Project path for GitLab GraphQL

        // We need to paginate through commits using GraphQL cursors
        $all_commits = [];
        $hasNextPage = true;
        $afterCursor = null;
        $page_count = 0; // Safety limit

        // Define the GraphQL query for commits
        $query = '
        query GetProjectCommits($fullPath: ID!, $after: String) {
          project(fullPath: $fullPath) {
            repository {
              commits(first: 100, after: $after) {
                pageInfo {
                  hasNextPage
                  endCursor
                }
                nodes {
                  committedDate # ISO 8601 format
                  sha # Optional, good for debugging
                }
              }
            }
          }
        }';


        while ($hasNextPage && $page_count < 50) { // Limit to 50 pages (5000 commits)
            $page_count++;
            $variables = [
                'fullPath' => $fullPath,
                'after' => $afterCursor,
            ];

            $response = $this->make_request(
                $graphql_endpoint,
                $headers,
                'POST',
                wp_json_encode(['query' => $query, 'variables' => $variables])
            );

            // --- Error Handling ---
            if (is_wp_error($response)) {
                 // Return error only if it happens on the very first request
                 if ($page_count === 1) {
                    return ['error' => sprintf(__('GitLab GraphQL Error for %s: %s', 'git-activity-charts'), $repo, $response->get_error_message())];
                 } else {
                    // Log subsequent errors but proceed with data gathered so far
                    error_log("Git Activity Charts - GitLab GraphQL pagination error for {$repo} on page {$page_count}: " . $response->get_error_message());
                    break;
                 }
            }
             // Check for GraphQL specific errors in the response body
             if (isset($response['errors']) && !empty($response['errors'])) {
                 $error_message = $response['errors'][0]['message'] ?? 'Unknown GraphQL error.';
                 error_log("Git Activity Charts - GitLab GraphQL Response Error: " . print_r($response['errors'], true));
                 if ($page_count === 1) {
                     // If error on first page, check if it's a "not found" type error
                     if (strpos(strtolower($error_message), 'not found') !== false || strpos(strtolower($error_message), 'could not be found') !== false) {
                         return ['error' => sprintf(__('GitLab Project "%s" not found or access denied via GraphQL.', 'git-activity-charts'), $fullPath)];
                     }
                     return ['error' => sprintf(__('GitLab GraphQL Error for %s: %s', 'git-activity-charts'), $repo, $error_message)];
                 } else {
                    error_log("Git Activity Charts - GitLab GraphQL pagination error (response) for {$repo} on page {$page_count}: " . $error_message);
                    break;
                 }
            }

            // --- Data Extraction ---
            $commits_data = $response['data']['project']['repository']['commits'] ?? null;

            if (!$commits_data || !isset($commits_data['nodes']) || !is_array($commits_data['nodes'])) {
                 // Unexpected structure or no commits found on this page
                 error_log("Git Activity Charts - GitLab GraphQL unexpected data structure for {$repo} on page {$page_count}.");
                 break;
            }

            $all_commits = array_merge($all_commits, $commits_data['nodes']);

            // --- Pagination Control ---
            $pageInfo = $commits_data['pageInfo'] ?? null;
            if ($pageInfo && $pageInfo['hasNextPage']) {
                $afterCursor = $pageInfo['endCursor'];
                $hasNextPage = true;
            } else {
                $hasNextPage = false; // No more pages
            }
        } // End while loop

        if ($page_count >= 50) {
             error_log("Git Activity Charts - GitLab GraphQL pagination limit reached for {$repo}");
        }

        if (empty($all_commits)) {
            // Check if it was a genuine error on first page or just no commits
             if ($page_count === 1 && isset($response) && !is_wp_error($response) && !isset($response['errors'])) {
                 // Likely just no commits found, not an error
                 return ['labels' => [], 'commits' => []];
             }
             // If an error occurred earlier, it would have been returned already.
             // If loop finished without commits but wasn't page 1, still treat as no data.
             return ['labels' => [], 'commits' => []];
         }


        // Aggregate commits by week using the 'committedDate' field
        return $this->aggregate_commits_by_week($all_commits, 'committedDate');
    }

    public function get_color() {
        return '#fc6d26'; // GitLab orange
    }
}