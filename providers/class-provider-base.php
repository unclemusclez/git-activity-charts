<?php
abstract class ProviderBase {
    abstract public function fetch_activity($username, $repo, $api_key, $instance_url = '');
    abstract public function get_color();

    protected function aggregate_commits($commits, $date_key = 'committed_date') {
        if (!is_array($commits) || empty($commits)) {
            return ['labels' => [], 'commits' => []]; // Return empty data if invalid
        }

        $weekly = [];
        foreach ($commits as $commit) {
            if (!is_array($commit) || !isset($commit[$date_key]) && !isset($commit['created_at']) && !isset($commit['commit']['committer']['date'])) {
                continue; // Skip invalid commit entries
            }
            $date = strtotime($commit[$date_key] ?? $commit['created_at'] ?? $commit['commit']['committer']['date']);
            $week = date('Y-W', $date);
            $weekly[$week] = isset($weekly[$week]) ? $weekly[$week] + 1 : 1;
        }
        ksort($weekly);
        return [
            'labels' => array_keys($weekly),
            'commits' => array_values($weekly)
        ];
    }

    protected function fetch_paginated_commits($url, $headers) {
        $all_commits = [];
        $page = 1;
        do {
            $response = wp_remote_get($url . "&page={$page}", ['headers' => $headers]);
            if (is_wp_error($response)) {
                break;
            }
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!is_array($body) || empty($body)) {
                break;
            }
            $all_commits = array_merge($all_commits, $body);
            $page++;
        } while (count($body) >= 100);
        return $all_commits;
    }
}