<?php
class BitbucketProvider extends ProviderBase {
    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        // Bitbucket uses workspace/repo-slug; assuming username is workspace
        $url = "https://api.bitbucket.org/2.0/repositories/{$username}/{$repo}/commits?pagelen=100";
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => "Bearer {$api_key}"
            ]
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!$data || empty($data['values'])) {
            return false;
        }

        return $this->aggregate_commits($data['values']);
    }

    public function get_color() {
        return '#205081'; // Bitbucket blue
    }
}