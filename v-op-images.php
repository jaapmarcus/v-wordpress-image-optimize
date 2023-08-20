<?php

/**
 * Plugin Name: Image Optimizer
 * Description: This plugin is designed to optimize images upon upload and create webp versions.
 * 'VWPIMGOP' defined as 'true' in Wordpress config to enable webp creation on cron
 * Version: 2.0
 * Author: Vontainment
 */

// Abort the execution if the Wordpress URL is not set
if (!defined('ABSPATH')) {
	exit;
}

// The function is called daily if VWPIMGOP is defined as `true`
if (defined('VWPIMGOP') && VWPIMGOP === true) {
	if (!wp_next_scheduled('check_and_manage_webp_images')) {
		wp_schedule_event(time(), 'daily', 'check_and_manage_webp_images');
	}

	add_action('check_and_manage_webp_images', 'check_and_manage_webp_images_function');
}

// List uploaded images + intermediate sizes for optimization
function optimize_uploaded_image($metadata, $attachment_id)
{
	$file = get_attached_file($attachment_id); // Original Image
	if(file_is_valid_image($file)){
		optimize_image($file); // Optimize Original Image
		foreach ($metadata['sizes'] as $size => $value) {
			$upload_folder = wp_upload_dir();
			$image = image_get_intermediate_size($attachment_id, $size);
			optimize_image(path_join($upload_folder['basedir'], $image['path'])); // Optimize resized images
		}
	}
	return $metadata; // Return metadata for database update
}

//Function to optimize images on upload and create webp version. Employ jpegoptim, optipng, and pngquant shell commands for image compression
function optimize_image($file)
{
	$image_type = wp_check_filetype($file);

	// Only optimize supported image types.
	$supported_types = ['jpg', 'jpeg', 'png'];
	if (in_array($image_type['ext'], $supported_types)) {

		switch ($image_type['ext']) {
			case 'jpg':
			case 'jpeg':
				// Optimization using jpegoptim
				$output = null;
				$return_var = null;
				exec("jpegoptim -q --strip-all --preserve --force --max=80 '{$file}'", $output, $return_var);
				if ($return_var !== 0) {
					error_log("jpegoptim failed for file '{$file}'. Output: " . implode("\n", $output));
				}
				break;
			case 'png':
				// Optimization using optipng and pngquant
				$output = null;
				$return_var = null;
				exec("optipng -quiet -preserve -o7 -strip all '{$file}'", $output, $return_var);
				if ($return_var !== 0) {
					error_log("optipng failed for file '{$file}'. Output: " . implode("\n", $output));
				}
				$output = null;
				$return_var = null;
				exec("pngquant -f --ext .png --quality=60-80 --speed=1 '{$file}'", $output, $return_var);
				if ($return_var !== 0) {
					error_log("pngquant failed for file '{$file}'. Output: " . implode("\n", $output));
				}
				break;
		}

		// Create a webp version of the image.
		if (function_exists('imagewebp')) {
			$webp_file = "{$file}.webp";
			$image = wp_get_image_editor($file);

			if (!is_wp_error($image)) {
				$image->set_quality(90);
				$image->resize(0, 1200);
				$image->save($webp_file, 'image/webp'); // Saved as webp
			} else {
				error_log("WebP creation failed for file '{$file}'. Error: " . json_encode($image)); // Error while converting to WebP
			}
		}
	}
}

// Apply function to uploaded images
add_filter('wp_generate_attachment_metadata', 'optimize_uploaded_image', 10, 2);

// Deletes webp version of an image when original image deleted from media library.
function delete_webp_image($post_id)
{
	$metadata = wp_get_attachment_metadata($post_id);

	$file = get_attached_file($post_id);
	$webp_file = "{$file}.webp";

	// Check and delete webp file
	if (file_exists($webp_file)) {
		if (!unlink($webp_file)) {
			error_log("Failed to delete WebP file '{$webp_file}'.");
		}
	}

	// Check and delete webp files for all image sizes
	if(!empty($metadata['sizes'])){
		foreach ($metadata['sizes'] as $size => $value) {
			$upload_folder = wp_upload_dir();
			$image = image_get_intermediate_size($post_id, $size);
			$file = path_join($upload_folder['basedir'], $image['path']);
	
			$webp_file = "{$file}.webp";
	
			if (file_exists($webp_file)) {
				if (!unlink($webp_file)) {
					error_log("Failed to delete WebP file '{$webp_file}'.");
				}
			}
		}
	}
}

// Action to delete a webp image when original image is deleted
add_action('delete_attachment', 'delete_webp_image');

// Function to manage WebP images is defined
function check_and_manage_webp_images_function()
{
	// Exit if VWPIMGOP is not defined or not true
	if (!defined('VWPIMGOP') || VWPIMGOP !== true) {
		return;
	}

	$upload_folder = wp_upload_dir();
	$upload_path = $upload_folder['basedir'];

	// Get all image files
	$images = glob($upload_path . '/*.{jpg,jpeg,png}', GLOB_BRACE);

	// Routine to check and remove orphaned .webp files
	$webp_files = glob($upload_path . '/*.webp');
	foreach ($webp_files as $webp_file) {
		$original_file = str_replace('.webp', '', $webp_file);
		if (!file_exists($original_file)) {
			// Attempt to delete orphaned WebP file
			if (!unlink($webp_file)) {
				error_log("Failed to delete orphaned WebP file '{$webp_file}'.");
			}
		}
	}

	// Check and create .webp versions for images
	foreach ($images as $image_file) {
		$webp_file = "{$image_file}.webp";
		if (!file_exists($webp_file)) {
			optimize_image($image_file); // Optimizes image and creates webp version
		}
	}
}
