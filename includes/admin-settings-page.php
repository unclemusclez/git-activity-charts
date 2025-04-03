<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renders the admin settings page HTML for Git Activity Charts.
 *
 * @param GitActivityCharts $plugin The plugin instance.
 */
function git_activity_charts_settings_page_html($plugin) {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }
    ?>
    <div class="wrap git-activity-settings">
        <form method="post" action="options.php">
            <?php
            settings_fields('git_activity_options');
            $accounts = get_option('git_activity_accounts', []);
            $custom_css = get_option('git_activity_custom_css', '');
            ?>
            <h2><?php _e('Accounts', 'git-activity-charts'); ?></h2>
            <p><?php _e('Add accounts from different Git providers. API keys are needed for private repositories and to avoid rate limits.', 'git-activity-charts'); ?></p>
            <div id="accounts-container">
                <?php if (!empty($accounts)) : ?>
                    <?php foreach ($accounts as $index => $account) : ?>
                        <div class="account-group" data-index="<?php echo esc_attr($index); ?>">
                            <div class="account-header">
                                <h4><?php printf(__('Account %s', 'git-activity-charts'), $index + 1); ?> <span class="account-type-indicator"><?php echo esc_html(ucfirst($account['type'])); ?></span></h4>
                                <button type="button" class="button button-link-delete remove-account"><?php _e('Remove', 'git-activity-charts'); ?></button>
                            </div>
                            <table class="form-table">
                                <tr>
                                    <th scope="row"><label><?php _e('Provider', 'git-activity-charts'); ?></label></th>
                                    <td>
                                        <select name="git_activity_accounts[<?php echo esc_attr($index); ?>][type]" class="account-type-select">
                                            <option value="github" <?php selected($account['type'], 'github'); ?>>GitHub</option>
                                            <option value="gitlab" <?php selected($account['type'], 'gitlab'); ?>>GitLab</option>
                                            <option value="gitea" <?php selected($account['type'], 'gitea'); ?>>Gitea</option>
                                            <option value="bitbucket" <?php selected($account['type'], 'bitbucket'); ?>>Bitbucket</option>
                                            <option value="custom" <?php selected($account['type'], 'custom'); ?>>Custom</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><label><?php _e('Username', 'git-activity-charts'); ?></label></th>
                                    <td><input type="text" name="git_activity_accounts[<?php echo esc_attr($index); ?>][username]" value="<?php echo esc_attr($account['username'] ?? ''); ?>" placeholder="<?php _e('Your username on the platform', 'git-activity-charts'); ?>" required class="regular-text" /></td>
                                </tr>
                                <tr>
                                    <th scope="row"><label><?php _e('API Key/Token', 'git-activity-charts'); ?></label></th>
                                    <td>
                                        <div style="position: relative;">
                                            <input type="password" name="git_activity_accounts[<?php echo esc_attr($index); ?>][api_key]" value="<?php echo esc_attr($account['api_key'] ?? ''); ?>" placeholder="<?php _e('Personal Access Token (optional)', 'git-activity-charts'); ?>" class="regular-text api-key-input" autocomplete="new-password"/>
                                            <button type="button" class="button button-secondary toggle-api-key" style="position: absolute; right: 1px; top: 1px; height: 28px; margin:0;"><?php _e('Show', 'git-activity-charts'); ?></button>
                                        </div>
                                        <p class="description"><?php _e('Needed for private repos or to increase rate limits.', 'git-activity-charts'); ?></p>
                                    </td>
                                </tr>
                                <tr class="account-field repos-field">
                                    <th scope="row"><label><?php _e('Repositories', 'git-activity-charts'); ?></label></th>
                                    <td>
                                        <input type="text" name="git_activity_accounts[<?php echo esc_attr($index); ?>][repos]" value="<?php echo esc_attr(implode(',', $account['repos'] ?? [])); ?>" placeholder="<?php _e('repo1, repo2, another-repo', 'git-activity-charts'); ?>" class="large-text" />
                                        <p class="description"><?php _e('Comma-separated list of repository names (required for GitLab, Gitea, Bitbucket).', 'git-activity-charts'); ?></p>
                                    </td>
                                </tr>
                                <tr class="account-field instance-url-field">
                                    <th scope="row"><label><?php _e('Instance URL', 'git-activity-charts'); ?></label></th>
                                    <td>
                                        <input type="url" name="git_activity_accounts[<?php echo esc_attr($index); ?>][instance_url]" value="<?php echo esc_attr($account['instance_url'] ?? ''); ?>" placeholder="<?php _e('e.g., https://saltrivercanyon.com', 'git-activity-charts'); ?>" class="regular-text"/>
                                        <p class="description"><?php _e('Required for self-hosted GitLab, Gitea, or custom instances.', 'git-activity-charts'); ?></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row"><?php _e('Display Options', 'git-activity-charts'); ?></th>
                                    <td>
                                        <fieldset>
                                            <label>
                                                <input type="checkbox" name="git_activity_accounts[<?php echo esc_attr($index); ?>][use_color_logo]" value="1" <?php checked($account['use_color_logo'] ?? false, true); ?> />
                                                <?php _e('Use Color Logo (if available)', 'git-activity-charts'); ?>
                                            </label>
                                            <br>
                                            <label style="margin-top: 10px; display: inline-block;">
                                                <?php _e('Title Text Color:', 'git-activity-charts'); ?>
                                                <input type="text" name="git_activity_accounts[<?php echo esc_attr($index); ?>][text_color]" value="<?php echo esc_attr($account['text_color'] ?? ($plugin->get_provider_color($account['type']) ?? '#000000')); ?>" class="color-picker" data-default-color="<?php echo esc_attr($plugin->get_provider_color($account['type']) ?? '#000000'); ?>" />
                                            </label>
                                            <br>
                                            <label style="margin-top: 10px; display: inline-block;">
                                                <?php _e('Custom Logo:', 'git-activity-charts'); ?>
                                                <input type="text" name="git_activity_accounts[<?php echo esc_attr($index); ?>][custom_logo]" value="<?php echo esc_attr($account['custom_logo'] ?? ''); ?>" class="custom-logo-url regular-text" readonly />
                                                <input type="button" class="button custom-logo-upload-button" value="<?php _e('Upload/Select Logo', 'git-activity-charts'); ?>" />
                                                <input type="button" class="button custom-logo-remove-button" value="<?php _e('Remove Logo', 'git-activity-charts'); ?>" <?php echo empty($account['custom_logo']) ? 'style="display:none;"' : ''; ?> />
                                                <p class="description"><?php _e('Upload or select a logo from the media library. Overrides the default provider logo.', 'git-activity-charts'); ?></p>
                                                <?php if (!empty($account['custom_logo'])) : ?>
                                                    <img src="<?php echo esc_url($account['custom_logo']); ?>" class="custom-logo-preview" style="max-width: 100px; max-height: 100px; margin-top: 10px;" />
                                                <?php endif; ?>
                                            </label>
                                        </fieldset>
                                    </td>
                                </tr>
                            </table>
                            <hr />
                        </div>
                    <?php endforeach; ?>
                <?php else : ?>
                    <p id="no-accounts-msg"><?php _e('No accounts added yet.', 'git-activity-charts'); ?></p>
                <?php endif; ?>
            </div>
            <button type="button" id="add-account" class="button button-secondary"><?php _e('+ Add Account', 'git-activity-charts'); ?></button>
            <h2><?php _e('Appearance', 'git-activity-charts'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="git_activity_custom_css"><?php _e('Custom CSS', 'git-activity-charts'); ?></label></th>
                    <td>
                        <textarea id="git_activity_custom_css" name="git_activity_custom_css" rows="10" cols="50" class="large-text"><?php echo esc_textarea($custom_css); ?></textarea>
                        <p class="description"><?php _e('Add custom CSS rules to style the charts and container. Default styles are provided in the plugin CSS file.', 'git-activity-charts'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
<div id="account-template" style="display: none;">
    <div class="account-group" data-index="__INDEX__">
        <div class="account-header">
            <h4><?php _e('Account __INDEX__', 'git-activity-charts'); ?> <span class="account-type-indicator">GitHub</span></h4>
            <button type="button" class="button button-link-delete remove-account"><?php _e('Remove', 'git-activity-charts'); ?></button>
        </div>
        <table class="form-table">
            <tr>
                <th scope="row"><label><?php _e('Provider', 'git-activity-charts'); ?></label></th>
                <td>
                    <select name="git_activity_accounts[__INDEX__][type]" class="account-type-select">
                        <option value="github">GitHub</option>
                        <option value="gitlab">GitLab</option>
                        <option value="gitea">Gitea</option>
                        <option value="bitbucket">Bitbucket</option>
                        <option value="custom">Custom</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label><?php _e('Username', 'git-activity-charts'); ?></label></th>
                <td><input type="text" name="git_activity_accounts[__INDEX__][username]" placeholder="<?php _e('Your username on the platform', 'git-activity-charts'); ?>" required class="regular-text" /></td>
            </tr>
            <tr>
                <th scope="row"><label><?php _e('API Key/Token', 'git-activity-charts'); ?></label></th>
                <td>
                    <div style="position: relative;">
                        <input type="password" name="git_activity_accounts[__INDEX__][api_key]" placeholder="<?php _e('Personal Access Token (optional)', 'git-activity-charts'); ?>" class="regular-text api-key-input" autocomplete="new-password"/>
                        <button type="button" class="button button-secondary toggle-api-key" style="position: absolute; right: 1px; top: 1px; height: 28px; margin:0;"><?php _e('Show', 'git-activity-charts'); ?></button>
                    </div>
                    <p class="description"><?php _e('Needed for private repos or to increase rate limits.', 'git-activity-charts'); ?></p>
                </td>
            </tr>
            <tr class="account-field repos-field">
                <th scope="row"><label><?php _e('Repositories', 'git-activity-charts'); ?></label></th>
                <td>
                    <input type="text" name="git_activity_accounts[__INDEX__][repos]" placeholder="<?php _e('repo1, repo2, another-repo', 'git-activity-charts'); ?>" class="large-text" />
                    <p class="description"><?php _e('Comma-separated list of repository names (required for GitLab, Gitea, Bitbucket).', 'git-activity-charts'); ?></p>
                </td>
            </tr>
            <tr class="account-field instance-url-field">
                <th scope="row"><label><?php _e('Instance URL', 'git-activity-charts'); ?></label></th>
                <td>
                    <input type="url" name="git_activity_accounts[__INDEX__][instance_url]" placeholder="<?php _e('e.g., https://saltrivercanyon.com', 'git-activity-charts'); ?>" class="regular-text"/>
                    <p class="description"><?php _e('Required for self-hosted GitLab, Gitea, or custom instances.', 'git-activity-charts'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Display Options', 'git-activity-charts'); ?></th>
                <td>
                    <fieldset>
                        <label>
                            <input type="checkbox" name="git_activity_accounts[__INDEX__][use_color_logo]" value="1" />
                            <?php _e('Use Color Logo (if available)', 'git-activity-charts'); ?>
                        </label>
                        <br>
                        <label style="margin-top: 10px; display: inline-block;">
                            <?php _e('Title Text Color:', 'git-activity-charts'); ?>
                            <input type="text" name="git_activity_accounts[__INDEX__][text_color]" class="color-picker" data-default-color="#000000" />
                        </label>
                        <br>
                        <label style="margin-top: 10px; display: inline-block;">
                            <?php _e('Custom Logo:', 'git-activity-charts'); ?>
                            <input type="text" name="git_activity_accounts[__INDEX__][custom_logo]" value="" class="custom-logo-url regular-text" readonly />
                            <input type="button" class="button custom-logo-upload-button" value="<?php _e('Upload/Select Logo', 'git-activity-charts'); ?>" />
                            <input type="button" class="button custom-logo-remove-button" value="<?php _e('Remove Logo', 'git-activity-charts'); ?>" style="display:none;" />
                            <p class="description"><?php _e('Upload or select a logo from the media library. Overrides the default provider logo.', 'git-activity-charts'); ?></p>
                        </label>
                    </fieldset>
                </td>
            </tr>
        </table>
        <hr />
    </div>
</div>
<?php
}