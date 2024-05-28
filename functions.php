/*****
 * LinkedIn/Proxycurl integration
 *****/

// Create a button shortcode to trigger the LinkedIn data sync process
function linkedin_sync_button() {
    if (is_user_logged_in()) {
        return '<button id="linkedin-sync-button" class="btn secondary">Sync LinkedIn Data</button>';
    }
    return '';
}
add_shortcode('linkedin_sync_button', 'linkedin_sync_button');

function linkedin_sync_button_script() {
    if (is_user_logged_in()) {
        ?>
        <script type="text/javascript">
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';

            jQuery(document).ready(function($) {
                $('#linkedin-sync-button').on('click', function(e) {
                    e.preventDefault();

                    var linkedinUrl = $('#linkedin-3025').val();
                    console.log('Starting AJAX request with LinkedIn URL:', linkedinUrl);

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'linkedin_sync_action',
                            linkedin_url: linkedinUrl // Using the correct LinkedIn field ID
                        },
                        timeout: 30000, // Set timeout to 30 seconds
                    });
                });
            });
        </script>
        <?php
    }
}
add_action('wp_footer', 'linkedin_sync_button_script');

function sync_linkedin_data($user_id) {
    if (!is_numeric($user_id)) {
        $user_id = um_profile_id();
        if (!$user_id) {
            error_log('LinkedIn Sync Error: Invalid user ID received and um_profile_id() did not return a valid ID.');
            return;
        }
    }
    
    error_log('LinkedIn Sync: sync_linkedin_data function called for user ID ' . $user_id);

    $linkedin_url = get_user_meta($user_id, 'linkedin', true);
    error_log('LinkedIn Sync: LinkedIn URL: ' . $linkedin_url);

    if (empty($linkedin_url) || !filter_var($linkedin_url, FILTER_VALIDATE_URL)) {
        update_user_meta($user_id, 'linkedin_sync_error', 'Invalid LinkedIn URL provided.');
        error_log('LinkedIn Sync Error: Invalid LinkedIn URL provided for user ID ' . $user_id);
        return;
    }

    error_log('LinkedIn Sync: LinkedIn URL is valid');

    $api_response = fetch_linkedin_data($linkedin_url);

    error_log('LinkedIn Sync: API response received');

    if (is_wp_error($api_response)) {
        update_user_meta($user_id, 'linkedin_sync_error', $api_response->get_error_message());
        error_log('LinkedIn Sync Error: ' . $api_response->get_error_message() . ' for user ID ' . $user_id);
        return;
    }

    error_log('LinkedIn Sync: API response is valid');

    update_linkedin_profile_data($user_id, $api_response);

    update_user_meta($user_id, 'linkedin_sync_success', true);
    error_log('LinkedIn Sync Success: Data synced successfully for user ID ' . $user_id);
}

function fetch_linkedin_data($linkedin_url) {
    $api_url = 'https://nubela.co/proxycurl/api/v2/linkedin';
    $api_key = 'bL7AimuhrD-fEnwATyN5ew'; // Replace with your Proxycurl API key

    $response = wp_remote_get($api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_key,
        ),
        'body' => array(
            'url' => $linkedin_url,
            'fallback_to_cache' => 'on-error',
            'use_cache' => 'if-present',
            'skills' => 'include'
        )
    ));

    if (is_wp_error($response)) {
        error_log('LinkedIn Sync Error: ' . $response->get_error_message());
        return $response;
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('LinkedIn Sync Error: Failed to parse API response.');
        return new WP_Error('json_error', 'Failed to parse API response.');
    }

    return $data;
}

function update_linkedin_profile_data($user_id, $api_response) {
    if (isset($api_response['occupation'])) {
        update_user_meta($user_id, 'title', sanitize_text_field($api_response['occupation']));
    }

    if (isset($api_response['summary'])) {
        update_user_meta($user_id, 'bio', sanitize_textarea_field($api_response['summary']));
    }

    if (isset($api_response['skills'])) {
        $skills = array_map('sanitize_text_field', $api_response['skills']);
        update_user_tags($user_id, $skills);
    }

    error_log('LinkedIn Sync: User profile fields updated for user ID ' . $user_id);
}

function update_user_tags($user_id, $skills) {
    $parent_tag_id = 28; // Update this ID based on your taxonomy structure

    foreach ($skills as $skill) {
        $skill = ucwords(strtolower($skill));
        $term = get_term_by('name', $skill, 'um_user_tag', ARRAY_A);

        if (!$term || strtolower($term['parent']) != strtolower($parent_tag_id)) {
            $term = wp_insert_term($skill, 'um_user_tag', array(
                'slug' => sanitize_title($skill),
                'parent' => $parent_tag_id,
            ));
        }

        if (!is_wp_error($term)) {
            $term_id = $term['term_id'] ?? $term;
            wp_set_object_terms($user_id, (int)$term_id, 'um_user_tag', true);
            error_log('LinkedIn Sync: Assigned term ID ' . $term_id . ' for skill "' . $skill . '" to user ID ' . $user_id);
        } else {
            error_log('LinkedIn Sync Error: Failed to create or assign term for skill "' . $skill . '".');
        }
    }
}

add_action('um_user_edit_profile', 'custom_redirect_after_profile_update', 10, 2);

function custom_redirect_after_profile_update($args, $form_data) {
    $user_id = isset($args['user_id']) ? $args['user_id'] : get_current_user_id();
    $profile_url = um_user_profile_url($user_id);
    $edit_profile_url = add_query_arg('um_action', 'edit', $profile_url);

    // Redirect to the edit profile URL
    wp_safe_redirect($edit_profile_url);
    exit;
}
function linkedin_sync_action() {
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        $linkedin_url = isset($_POST['linkedin_url']) ? esc_url_raw($_POST['linkedin_url']) : '';

        // Validate the LinkedIn URL
        if (empty($linkedin_url) || !filter_var($linkedin_url, FILTER_VALIDATE_URL) || !strpos($linkedin_url, 'linkedin.com')) {
            error_log('LinkedIn Sync Error: Invalid LinkedIn URL: ' . $linkedin_url);
            wp_send_json_error(array('message' => 'Invalid LinkedIn URL.'));
        }

        // Proceed with fetching and syncing LinkedIn data
        $api_response = fetch_linkedin_data($linkedin_url);

        if (is_wp_error($api_response)) {
            error_log('LinkedIn Sync Error: ' . $api_response->get_error_message());
            wp_send_json_error(array('message' => $api_response->get_error_message()));
        }

        update_linkedin_profile_data($user_id, $api_response);

        // Trigger profile update and redirect
        do_action('um_user_edit_profile', array('user_id' => $user_id), array());

        // Generate the edit profile URL
        $redirect_url = um_user_profile_url($user_id);
        error_log('LinkedIn Sync: Redirect URL: ' . $redirect_url);

        wp_send_json_success(array('message' => 'LinkedIn data synced.', 'redirect_url' => $redirect_url));
    } else {
        error_log('LinkedIn Sync Error: User not logged in.');
        wp_send_json_error(array('message' => 'User not logged in.'));
    }
}
add_action('wp_ajax_linkedin_sync_action', 'linkedin_sync_action');
