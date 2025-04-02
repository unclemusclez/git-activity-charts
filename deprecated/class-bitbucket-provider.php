<?php

if (!defined('ABSPATH')) {
    exit;
}

class BitbucketProvider extends ProviderBase {

    // Helper to get Workspace UUID from username/slug (might be needed for some GraphQL queries)
    // Uses REST API v2 as a fallback if GraphQL doesn't accept username directly
    private function get_workspace_uuid($username, $api_key) {
        $cache_key = 'bb_workspace_uuid_' . sanitize_key($username);
        $uuid = get_transient($cache_key);
        if (false !== $uuid) {
            return $uuid;
        }

        $url = "https://api.bitbucket.org/2.0/workspaces/" . urlencode($username);
        $headers = [
             'Accept' => 'application/json',
             'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        ];
         if (!empty($api_key)) {
             // Assuming Bearer token (OAuth or App Password used as Bearer)
             $headers['Authorization'] = "Bearer {$api_key}";
             // If using App Password as Basic Auth:
             // $headers['Authorization'] = 'Basic ' . base64_encode("{$username}:{$api_key}");
         }

        $response = $this->make_request($url, $headers);

        if (!is_wp_error($response) && isset($response['uuid'])) {
             $uuid = $response['uuid'];
             set_transient($cache_key, $uuid, DAY_IN_SECONDS); // Cache for a day
             return $uuid;
        } else {
            error_log("BitbucketProvider: Failed to get workspace UUID for username '{$username}'. Error: " . (is_wp_error($response) ? $response->get_error_message() : print_r($response, true)));
            return null; // Failed to get UUID
        }
    }


    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        // Using the main Atlassian GraphQL endpoint, assuming OAuth 2.0 Bearer token
        // Or potentially API token with the gateway URL. Test which works for your auth.
        $graphql_endpoint = 'https://api.atlassian.com/graphql';
        // Alternative for API tokens might be: "https://bitbucket.org/gateway/api/graphql" - requires testing.

        if (empty($api_key)) {
            return ['error' => sprintf(__('API Key/Token is required for Bitbucket GraphQL API for %s.', 'git-activity-charts'), $repo)];
        }

         // Attempt to get Workspace UUID - REQUIRED for most Bitbucket GraphQL queries
         // Note: Bitbucket often uses workspace SLUG (same as username for individuals) or UUID.
         // We'll try using the username as the slug first, and get UUID as fallback if needed by specific queries.
         $workspaceSlug = $username; // Assuming username is the workspace slug for individuals/teams

        $headers = [
            'Authorization' => "Bearer {$api_key}", // Assuming OAuth or App Password as Bearer
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            // May need 'X-ExperimentalApi' header if using beta fields
        ];

        $all_commits = [];
        $hasNextPage = true;
        $afterCursor = null;
        $page_count = 0; // Safety limit

        // Define the GraphQL query - Structure depends heavily on the specific Atlassian GraphQL schema version
        // This is a hypothetical query based on common patterns, VERIFY against the Explorer
         // It might require workspace UUID instead of slug.
         $query = '
         query GetBitbucketCommits($workspaceSlug: String!, $repoSlug: String!, $after: String) {
           bitbucket {
             repository(workspaceSlug: $workspaceSlug, repositorySlug: $repoSlug) {
               name # Verify field names
               commits(first: 50, after: $after) { # Check pagination limit
                 pageInfo {
                   hasNextPage
                   endCursor
                 }
                 nodes {
                   hash
                   date # ISO 8601 format
                 }
               }
             }
           }
         }';

        while ($hasNextPage && $page_count < 50) { // Limit to 50 pages (~2500 commits)
            $page_count++;
            $variables = [
                'workspaceSlug' => $workspaceSlug, // Try with slug first
                'repoSlug'      => $repo,
                'after'         => $afterCursor,
            ];

            $response = $this->make_request(
                $graphql_endpoint,
                $headers,
                'POST',
                wp_json_encode(['query' => $query, 'variables' => $variables])
            );

            // --- Error Handling ---
            if (is_wp_error($response)) {
                 if ($page_count === 1) {
                    return ['error' => sprintf(__('Bitbucket GraphQL Error for %s: %s', 'git-activity-charts'), $repo, $response->get_error_message())];
                 } else {
                    error_log("Bit Activity Charts - Bitbucket GraphQL pagination error for {$repo} on page {$page_count}: " . $response->get_error_message());
                    break;
                 }
            }
             if (isset($response['errors']) && !empty($response['errors'])) {
                 $error_message = $response['errors'][0]['message'] ?? 'Unknown GraphQL error.';
                 error_log("Git Activity Charts - Bitbucket GraphQL Response Error: " . print_r($response['errors'], true));
                 if ($page_count === 1) {
                     // Check for common errors like missing scopes or repo not found
                     if (strpos(strtolower($error_message), 'not found') !== false || strpos(strtolower($error_message), 'access denied') !== false || strpos(strtolower($error_message), 'authorization') !== false) {
                          return ['error' => sprintf(__('Bitbucket Repo "%s/%s" not found or access denied via GraphQL. Check API key scopes.', 'git-activity-charts'), $workspaceSlug, $repo)];
                     }
                    return ['error' => sprintf(__('Bitbucket GraphQL Error for %s: %s', 'git-activity-charts'), $repo, $error_message)];
                 } else {
                    error_log("Bit Activity Charts - Bitbucket GraphQL pagination error (response) for {$repo} on page {$page_count}: " . $error_message);
                    break;
                 }
            }

            // --- Data Extraction ---
            // Adjust path based on actual response structure - VERIFY this path
            $commits_data = $response['data']['bitbucket']['repository']['commits'] ?? null;

            if (!$commits_data || !isset($commits_data['nodes']) || !is_array($commits_data['nodes'])) {
                // If repository is null, it likely means not found or access denied.
                 if (isset($response['data']['bitbucket']['repository']) && $response['data']['bitbucket']['repository'] === null && $page_count === 1) {
                      return ['error' => sprintf(__('Bitbucket Repo "%s/%s" not found or access denied via GraphQL.', 'git-activity-charts'), $workspaceSlug, $repo)];
                 }
                 // Otherwise, unexpected structure or no commits.
                 error_log("Git Activity Charts - Bitbucket GraphQL unexpected data structure for {$repo} on page {$page_count}.");
                 break;
            }

            $all_commits = array_merge($all_commits, $commits_data['nodes']);

            // --- Pagination Control ---
            $pageInfo = $commits_data['pageInfo'] ?? null;
            if ($pageInfo && $pageInfo['hasNextPage']) {
                $afterCursor = $pageInfo['endCursor'];
                $hasNextPage = true;
            } else {
                $hasNextPage = false;
            }
        } // End while loop

         if ($page_count >= 50) {
             error_log("Git Activity Charts - Bitbucket GraphQL pagination limit reached for {$repo}");
         }

         if (empty($all_commits)) {
            if ($page_count === 1 && isset($response) && !is_wp_error($response) && !isset($response['errors'])) {
                 return ['labels' => [], 'commits' => []]; // No commits found
            }
             return ['labels' => [], 'commits' => []]; // Treat as no data if loop finished without commits
         }


        // Aggregate commits by week using the 'date' field
        return $this->aggregate_commits_by_week($all_commits, 'date');
    }

    public function get_color() {
        return '#0052CC'; // Bitbucket blue
    }
}