<?php
abstract class ProviderBase {
    abstract public function fetch_activity($username, $repo, $api_key, $instance_url = '');
    abstract public function get_color();

    protected function aggregate_commits($commits, $date_key = 'committed_date') {
        $weekly = [];
        foreach ($commits as $commit) {
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
            if (!$body || empty($body)) {
                break;
            }
            $all_commits = array_merge($all_commits, $body);
            $page++;
        } while (count($body) >= 100); // Assuming 100 per page
        return $all_commits;
    }
}