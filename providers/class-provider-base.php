<?php
abstract class ProviderBase {
    abstract public function fetch_activity($username, $repo, $api_key, $instance_url = '');
    abstract public function get_color();

    protected function aggregate_commits($commits) {
        $weekly = [];
        foreach ($commits as $commit) {
            $date = strtotime($commit['committed_date'] ?? $commit['created_at'] ?? $commit['commit']['committer']['date']);
            $week = date('Y-W', $date);
            $weekly[$week] = isset($weekly[$week]) ? $weekly[$week] + 1 : 1;
        }
        ksort($weekly);
        return [
            'labels' => array_keys($weekly),
            'commits' => array_values($weekly)
        ];
    }
}