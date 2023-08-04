<?php

/**
 * Plugin Name: Image Optimizer
 * Description: Optimize images on upload and create webp versions.
 * Version: 1.0
 * Author: Vontainment
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * List uploaded images + intermediate
 */
function optimize_uploaded_image($metadata, $attachment_id)
{
	$file = get_attached_file($attachment_id);
	optimize_image($file);
	foreach($metadata['sizes'] as $size => $value){
		$upload_folder = wp_upload_dir();
		$image = image_get_intermediate_size($attachment_id, $size); 
		optimize_image(path_join($upload_folder['basedir'],$image['path']));
	}
	return $metadata;
}

/**
 * Optimizes images on upload using jpegoptim, optipng, and pngquant.
 */
function optimize_image($file){
	$image_type = wp_check_filetype($file);
	// Only optimize supported image types.
	$supported_types = ['jpg', 'jpeg', 'png'];
	if (in_array($image_type['ext'], $supported_types)) {
		// Optimize image using jpegoptim, optipng, or pngquant.
		switch ($image_type['ext']) {
			case 'jpg':
			case 'jpeg':
				exec("jpegoptim -q --strip-all --preserve --force --max=80 '{$file}'");
				break;
			case 'png':
				exec("optipng -quiet -preserve -o7 -strip all '{$file}'");
				exec("pngquant -f --ext .png --quality=60-80 --speed=1 '{$file}'");
				break;
		}
	
		// Create a webp version of the image.
		if (function_exists('imagewebp')) {
			$webp_file = "{$file}.webp";
			$image = wp_get_image_editor($file);
	
			if (!is_wp_error($image)) {
				$image->set_quality(90);
				$image->resize(0, 1200);
				$image->save($webp_file, 'image/webp');
			}else{
				error_log(json_encode($image));
			}
		}
	}
}

add_filter('wp_generate_attachment_metadata', 'optimize_uploaded_image', 10, 2);

/**
 * Deletes webp version of an image when it's deleted from media library.
 */
function delete_webp_image($post_id)
{
	$metadata = wp_get_attachment_metadata($post_id);
	$file = get_attached_file($post_id);
	$webp_file = "{$file}.webp";

	if (file_exists($webp_file)) {
		unlink($webp_file);
	}
	foreach($metadata['sizes'] as $size => $value){
		$upload_folder = wp_upload_dir();
		$image = image_get_intermediate_size($post_id, $size); 
		$file = path_join($upload_folder['basedir'],$image['path']);
		
		$webp_file = "{$file}.webp";
		
		if (file_exists($webp_file)) {
			unlink($webp_file);
		}
	}
}

add_action('delete_attachment', 'delete_webp_image');