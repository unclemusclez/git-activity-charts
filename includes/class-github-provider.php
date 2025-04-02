<?php

if (!defined('ABSPATH')) {
    exit;
}

class GitHubProvider extends ProviderBase {

    /**
     * Fetches user's contribution calendar data via GraphQL.
     * Ignores $repo parameter. Requires API key for private data.
     */
    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        // Instance URL is ignored for github.com
        $api_endpoint = 'https://api.github.com/graphql';

        if (empty($api_key)) {
            // Cannot fetch contribution data without a token
            return ['error' => __('API Key is required to fetch GitHub contribution data.', 'git-activity-charts')];
        }

        // GraphQL Query to get the contribution calendar for the last year
        $query = 'query($userName: String!) {
            user(login: $userName) {
                contributionsCollection {
                    contributionCalendar {
                        totalContributions
                        weeks {
                            contributionDays {
                                contributionCount
                                date # YYYY-MM-DD format
                            }
                        }
                    }
                }
            }
        }';

        $variables = ['userName' => $username];

        $headers = [
            'Authorization' => "Bearer {$api_key}",
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
             'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        ];

        $response = $this->make_request(
            $api_endpoint,
            $headers,
            'POST',
            wp_json_encode(['query' => $query, 'variables' => $variables])
        );

        if (is_wp_error($response)) {
            // Propagate the error message
            return ['error' => sprintf(__('GitHub API Error: %s', 'git-activity-charts'), $response->get_error_message())];
        }

        // Check for GraphQL specific errors
        if (isset($response['errors']) && !empty($response['errors'])) {
            $error_message = $response['errors'][0]['message'] ?? 'Unknown GraphQL error.';
             error_log("Git Activity Charts - GitHub GraphQL Error: " . print_r($response['errors'], true));
             return ['error' => sprintf(__('GitHub GraphQL Error: %s', 'git-activity-charts'), $error_message)];
        }

        // Extract the contribution days
        $weeks = $response['data']['user']['contributionsCollection']['contributionCalendar']['weeks'] ?? [];
        if (empty($weeks)) {
            return ['labels' => [], 'commits' => []]; // No contribution data found
        }

        $daily_contributions = [];
        foreach ($weeks as $week) {
            if (isset($week['contributionDays']) && is_array($week['contributionDays'])) {
                 foreach ($week['contributionDays'] as $day) {
                     // Keyed by date (YYYY-MM-DD)
                     $daily_contributions[$day['date']] = $day['contributionCount'];
                 }
            }
        }

        if (empty($daily_contributions)) {
             return ['labels' => [], 'commits' => []];
        }

        // Aggregate daily contributions into weekly counts (using YYYY-W format)
        $weekly_counts = [];
         $first_date = key($daily_contributions);
         $last_date = array_key_last($daily_contributions);


        try {
            $start_dt = new DateTime($first_date);
            $end_dt = new DateTime($last_date);
            $current_dt = clone $start_dt;

            while($current_dt <= $end_dt) {
                $week_key = $current_dt->format("Y-W");
                 if (!isset($weekly_counts[$week_key])) {
                     $weekly_counts[$week_key] = 0;
                 }
                // Find the Sunday of the current week to sum up contributions
                 $day_of_week_dt = clone $current_dt;
                 for ($i=0; $i<7; $i++) {
                      $day_key = $day_of_week_dt->format('Y-m-d');
                      if (isset($daily_contributions[$day_key])) {
                           $weekly_counts[$week_key] += $daily_contributions[$day_key];
                      }
                      $day_of_week_dt->modify('+1 day'); // Check next day in week
                      if ($day_of_week_dt > $end_dt) break; // Don't go past last day
                 }

                // Move to the next week's start
                 $current_dt->modify('next monday'); // Go to start of next week
                 // This logic might slightly differ based on how GitHub defines weeks,
                 // but grouping by YYYY-W from the daily data is generally sound.
             }

             // Ensure weeks with 0 contributions within the range are included (already handled by loop)
             // Sort by week key
             uksort($weekly_counts, function($a, $b) {
                $yearA = substr($a, 0, 4); $weekA = substr($a, 5);
                $yearB = substr($b, 0, 4); $weekB = substr($b, 5);
                if ($yearA != $yearB) return $yearA <=> $yearB;
                return $weekA <=> $weekB;
             });

        } catch (Exception $e) {
             error_log("Git Activity Charts: Error processing GitHub dates: " . $e->getMessage());
             return ['error' => __('Error processing GitHub contribution dates.', 'git-activity-charts')];
        }

        return [
            'labels' => array_keys($weekly_counts),
            'commits' => array_values($weekly_counts) // 'commits' key used for consistency, represents contributions
        ];
    }

    public function get_color() {
        return '#171515'; // GitHub dark color (often associated) - or use '#0366d6' if preferred blue
    }
}