<?php
/*
Plugin Name: GMA Testimonial Photo Uploader
Description: Allows logged-in users to upload a photo to their testimonial (sets featured image) based on their email.
Version: 1.0
Author: GMA
*/

if (!defined('ABSPATH')) exit;

add_shortcode('gma_testimonial_photo_upload', function() {

    if (!is_user_logged_in()) {
        return "<p>Please log in to upload your photo.</p>";
    }

    $user = wp_get_current_user();
    $user_email = $user->user_email;

    // Find testimonial by email
    $posts = get_posts([
        'post_type' => 'wpm-testimonial',
        'posts_per_page' => 1,
        'meta_query' => [
            [
                'key' => 'email',
                'value' => $user_email,
                'compare' => '='
            ]
        ]
    ]);

    if (empty($posts)) {
        return "<p>No testimonial found for your account.</p>";
    }

    $post_id = $posts[0]->ID;

    // Handle upload
    if (isset($_POST['gma_upload_submit']) && !empty($_FILES['gma_photo']['name'])) {

        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $attachment_id = media_handle_upload('gma_photo', 0);

        if (is_wp_error($attachment_id)) {
            return "<p>Error uploading image.</p>";
        }

        set_post_thumbnail($post_id, $attachment_id);

        return "<p><strong>Thank you! Your photo has been uploaded successfully.</strong><br><br>
        You can see your testimonial at → 
        <a href='https://globalmentoringacademy.com/client-testimonials/' target='_blank'>
        Testimonials
        </a></p>";
    }

    ob_start();

    if (has_post_thumbnail($post_id)) {
        echo "<div style='margin-bottom:20px;'>";
        echo "<p><strong>Please note your testimonial already had this featured photo.</strong></p>";
        echo get_the_post_thumbnail($post_id, 'medium');
        echo "</div>";
    }
    ?>

    <form method="post" enctype="multipart/form-data">
        <p><strong>Upload your photo</strong></p>

        <input type="file" name="gma_photo" accept="image/*" required>

        <br><br>

        <button type="submit" name="gma_upload_submit">
            Upload Photo
        </button>
    </form>

    <?php

    return ob_get_clean();
});
