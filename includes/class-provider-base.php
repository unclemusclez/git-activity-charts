<?php

if (!defined('ABSPATH')) {
    exit;
}

abstract class ProviderBase {

    /**
     * Fetch activity data for the provider.
     * For GitHub, $repo is ignored, fetches user contributions.
     * For others, fetches commits for the specified $repo.
     *
     * @param string $username      Provider username.
     * @param string $repo          Repository name (ignored for GitHub user contribution fetch).
     * @param string $api_key       API key/token.
     * @param string $instance_url  Base URL for self-hosted instances (GitLab, Gitea).
     * @return array|false|array{error: string} Array with 'labels' and 'commits', false on initial failure, or ['error' => message] on specific error.
     */
    abstract public function fetch_activity($username, $repo, $api_key, $instance_url = '');

    /**
     * Get the default hex color code for the provider.
     *
     * @return string Hex color code (e.g., '#ff4500').
     */
    abstract public function get_color();


    /**
     * Aggregates commit data into weekly counts.
     *
     * @param array $commits Array of commit objects/arrays from the API.
     * @param string $date_key The key in the commit array that holds the date string.
     * @return array ['labels' => [week_keys...], 'commits' => [counts...]]
     */
     protected function aggregate_commits_by_week($commits, $date_key = 'committed_date') {
        if (!is_array($commits) || empty($commits)) {
            return ['labels' => [], 'commits' => []];
        }

        $weekly_counts = [];
        $min_date = null;
        $max_date = null;

        foreach ($commits as $commit) {
            $date_str = null;
             // Find the date - accommodate different structures
             if (isset($commit[$date_key])) {
                 $date_str = $commit[$date_key];
             } elseif (isset($commit['commit']['committer']['date'])) { // Common Git structure
                 $date_str = $commit['commit']['committer']['date'];
             } elseif (isset($commit['commit']['author']['date'])) { // Fallback
                 $date_str = $commit['commit']['author']['date'];
             } elseif (isset($commit['date'])) { // Bitbucket
                 $date_str = $commit['date'];
             } elseif (isset($commit['created_at'])) { // GitLab sometimes uses this
                 $date_str = $commit['created_at'];
            }

             if ($date_str) {
                 try {
                     // Handle various ISO 8601 formats, including those with timezone offsets or 'Z'
                     $dt = new DateTime($date_str);
                     $timestamp = $dt->getTimestamp();

                    // Find min/max dates for filling gaps later
                    if ($min_date === null || $timestamp < $min_date) $min_date = $timestamp;
                    if ($max_date === null || $timestamp > $max_date) $max_date = $timestamp;

                    // Group by ISO week number (YYYY-WW)
                    $week_key = $dt->format("Y-W"); // e.g., 2023-35

                    $weekly_counts[$week_key] = isset($weekly_counts[$week_key]) ? $weekly_counts[$week_key] + 1 : 1;

                 } catch (Exception $e) {
                    // Handle cases where date parsing fails, maybe log this
                     error_log("Git Activity Charts: Failed to parse date '{$date_str}'. Error: " . $e->getMessage());
                    continue;
                 }
             } else {
                 // Log commits without a recognizable date key
                 // error_log("Git Activity Charts: Commit missing recognizable date key: " . print_r($commit, true));
             }
        }

         if (empty($weekly_counts)) {
             return ['labels' => [], 'commits' => []];
         }

        // --- Fill Gaps ---
        // Sort by week key first to ensure chronological order
         uksort($weekly_counts, function($a, $b) {
            $yearA = substr($a, 0, 4); $weekA = substr($a, 5);
            $yearB = substr($b, 0, 4); $weekB = substr($b, 5);
            if ($yearA != $yearB) return $yearA <=> $yearB;
            return $weekA <=> $weekB;
         });


         $filled_weekly_counts = [];
         $start_dt = new DateTime();
         $start_dt->setISODate((int)substr(key($weekly_counts), 0, 4), (int)substr(key($weekly_counts), 5));
         $end_dt = new DateTime();
         $end_dt->setISODate((int)substr(array_key_last($weekly_counts), 0, 4), (int)substr(array_key_last($weekly_counts), 5));
         $end_dt->modify('+6 days'); // Go to end of the last week


         $current_dt = clone $start_dt;
         while ($current_dt <= $end_dt) {
            $week_key = $current_dt->format("Y-W");
            $filled_weekly_counts[$week_key] = $weekly_counts[$week_key] ?? 0; // Fill with 0 if missing
            $current_dt->modify('+1 week');
         }

        // Limit to roughly the last year (53 weeks) if too much data
        $max_weeks = 53;
        if (count($filled_weekly_counts) > $max_weeks) {
             $filled_weekly_counts = array_slice($filled_weekly_counts, -$max_weeks, null, true);
        }


        return [
            'labels' => array_keys($filled_weekly_counts),
            'commits' => array_values($filled_weekly_counts)
        ];
    }

     /**
      * Helper to make robust HTTP requests using WordPress functions.
      *
      * @param string $url
      * @param array $headers
      * @param string $method 'GET' or 'POST'
      * @param mixed $body For POST requests
      * @return array|WP_Error Decoded JSON body or WP_Error on failure.
      */
      protected function make_request($url, $headers = [], $method = 'GET', $body = null) {
        $args = [
            'headers' => $headers,
            'timeout' => 20, // Increased timeout for potentially slow API calls
            'method'  => $method,
        ];
        if ($method === 'POST' && $body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            error_log("Git Activity Charts API Error (WP_Error): " . $response->get_error_message());
            return $response; // Return WP_Error object
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code >= 300) {
            // Log API error response
            error_log("Git Activity Charts API Error (HTTP {$response_code}): URL: {$url} | Response: " . $response_body);
             // Try to decode error message if JSON
             $error_data = json_decode($response_body, true);
             $message = "API Error (HTTP {$response_code})";
             if (is_array($error_data) && isset($error_data['message'])) {
                 $message .= ': ' . $error_data['message'];
             } elseif(is_array($error_data) && isset($error_data['error_description'])) {
                 $message .= ': ' . $error_data['error_description']; // Bitbucket style
             }
             return new WP_Error('api_error', $message, ['status' => $response_code, 'body' => $response_body]);
        }

        $decoded_body = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
             error_log("Git Activity Charts API Error: Failed to decode JSON response. Body: " . $response_body);
             return new WP_Error('json_decode_error', 'Failed to decode API response.', ['body' => $response_body]);
        }

        return $decoded_body; // Return decoded PHP array/object
    }
}