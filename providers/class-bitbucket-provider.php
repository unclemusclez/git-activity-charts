<?php
class BitbucketProvider extends ProviderBase {
    public function fetch_activity($username, $repo, $api_key, $instance_url = '') {
        $url = "https://api.bitbucket.org/2.0/repositories/{$username}/{$repo}/commits?pagelen=100";
        $headers = $api_key ? ['Authorization' => "Bearer {$api_key}"] : [];
        $response = wp_remote_get($url, ['headers' => $headers]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!$data || empty($data['values'])) {
            return false;
        }

        $commits = $data['values'];
        while (isset($data['next'])) {
            $response = wp_remote_get($data['next'], ['headers' => $headers]);
            if (is_wp_error($response)) {
                break;
            }
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $commits = array_merge($commits, $data['values'] ?? []);
        }

        return $this->aggregate_commits($commits, 'date');
    }

    public function get_color() {
        return '#205081';
    }
}