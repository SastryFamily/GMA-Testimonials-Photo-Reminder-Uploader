<?php
/*
Plugin Name: GMA Testimonial Photo Uploader
Description: v1.1 - Allows logged-in users to upload a photo to their testimonial (sets featured image) based on their email. Adds real error reporting, oversized-upload detection, nonce security, file size/type validation, and old-photo cleanup.
Version: 1.1
Author: GMA
*/

if (!defined('ABSPATH')) exit;

add_shortcode('gma_testimonial_photo_upload', function() {

    // ── Config ────────────────────────────────────────────────────────────────
    $max_mb        = 5; // maximum upload size in MB
    $max_bytes     = $max_mb * 1024 * 1024;
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowed_label = 'JPG, PNG, GIF or WebP';

    if (!is_user_logged_in()) {
        return "<p>Please log in to upload your photo.</p>";
    }

    $user       = wp_get_current_user();
    $user_email = $user->user_email;

    // Find testimonial by email
    $posts = get_posts([
        'post_type'      => 'wpm-testimonial',
        'posts_per_page' => 1,
        'meta_query'     => [
            [
                'key'     => 'email',
                'value'   => $user_email,
                'compare' => '='
            ]
        ]
    ]);

    if (empty($posts)) {
        return "<p>No testimonial found for your account.</p>";
    }

    $post_id = $posts[0]->ID;
    $error   = '';

    // ── Detect oversized POST (PHP discards everything, $_POST arrives empty) ─
    // If the request was a POST but $_POST is empty, the upload exceeded
    // post_max_size and PHP threw the whole request away silently.
    if (
        isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
        && empty($_POST) && empty($_FILES)
        && isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > 0
    ) {
        $error = "Your photo is too large for the server to accept. Please resize it to under {$max_mb} MB and try again.";
    }

    // ── Handle upload ─────────────────────────────────────────────────────────
    if (!$error && isset($_POST['gma_upload_submit'])) {

        // Security: verify nonce
        if (
            !isset($_POST['gma_upload_nonce'])
            || !wp_verify_nonce($_POST['gma_upload_nonce'], 'gma_upload_photo_' . $post_id)
        ) {
            $error = "Your session expired. Please try uploading again.";

        } elseif (empty($_FILES['gma_photo']['name'])) {
            $error = "Please choose a photo before clicking Upload.";

        } else {

            $file = $_FILES['gma_photo'];

            // PHP-level upload errors (partial upload, ini size limit, etc.)
            if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
                switch ($file['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $error = "Your photo is too large. Please resize it to under {$max_mb} MB and try again.";
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error = "The upload was interrupted. Please check your connection and try again.";
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $error = "Please choose a photo before clicking Upload.";
                        break;
                    default:
                        $error = "The upload failed (code {$file['error']}). Please try again or contact support.";
                }

            // Size check (our own limit, clearer than the server default)
            } elseif ((int) $file['size'] > $max_bytes) {
                $mb    = round($file['size'] / 1048576, 1);
                $error = "Your photo is {$mb} MB, which is over the {$max_mb} MB limit. Please resize it and try again.";

            } else {

                // Type check on real file content, not just the filename
                $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
                if (empty($check['type']) || !in_array($check['type'], $allowed_types, true)) {
                    $error = "That file type isn't supported. Please upload a {$allowed_label} image. "
                           . "Tip: iPhone photos in HEIC format need to be converted to JPG first "
                           . "(or change your iPhone camera setting to 'Most Compatible').";

                } else {

                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    require_once ABSPATH . 'wp-admin/includes/media.php';
                    require_once ABSPATH . 'wp-admin/includes/image.php';

                    // Remember the current photo so we can clean it up on success
                    $old_thumb_id = get_post_thumbnail_id($post_id);

                    $attachment_id = media_handle_upload('gma_photo', 0);

                    if (is_wp_error($attachment_id)) {
                        // Surface the real reason instead of a generic message
                        $error = "Upload failed: " . esc_html($attachment_id->get_error_message())
                               . " Please try again or contact support.";
                    } else {

                        // Mark this attachment as created by this plugin
                        update_post_meta($attachment_id, '_gma_photo_upload', 1);

                        set_post_thumbnail($post_id, $attachment_id);

                        // Clean up the previous photo, but ONLY if it was also
                        // uploaded via this plugin (never touch other media)
                        if (
                            $old_thumb_id
                            && $old_thumb_id != $attachment_id
                            && get_post_meta($old_thumb_id, '_gma_photo_upload', true)
                        ) {
                            wp_delete_attachment($old_thumb_id, true);
                        }

                        return "<p><strong>Thank you! Your photo has been uploaded successfully.</strong><br><br>
                        You can see your testimonial at →
                        <a href='https://globalmentoringacademy.com/client-testimonials/' target='_blank'>
                        Testimonials
                        </a></p>";
                    }
                }
            }
        }
    }

    // ── Render form ───────────────────────────────────────────────────────────
    ob_start();

    if ($error) {
        echo "<div style='margin-bottom:20px;padding:12px 16px;border-left:4px solid #d63638;background:#fcf0f1;'>";
        echo "<p style='margin:0;'><strong>" . wp_kses_post($error) . "</strong></p>";
        echo "</div>";
    }

    if (has_post_thumbnail($post_id)) {
        echo "<div style='margin-bottom:20px;'>";
        echo "<p><strong>Please note your testimonial already had this featured photo.</strong> Uploading a new one will replace it.</p>";
        echo get_the_post_thumbnail($post_id, 'medium');
        echo "</div>";
    }
    ?>

    <form method="post" enctype="multipart/form-data">
        <p><strong>Upload your photo</strong></p>
        <p style="color:#666;font-size:0.9em;">Accepted formats: <?php echo esc_html($allowed_label); ?> &middot; Maximum size: <?php echo (int) $max_mb; ?> MB</p>

        <input type="hidden" name="gma_upload_nonce" value="<?php echo esc_attr(wp_create_nonce('gma_upload_photo_' . $post_id)); ?>">
        <input type="file" name="gma_photo" accept="image/jpeg,image/png,image/gif,image/webp" required>

        <br><br>

        <button type="submit" name="gma_upload_submit">
            Upload Photo
        </button>
    </form>

    <?php

    return ob_get_clean();
});
