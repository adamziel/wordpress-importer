<?php
/**
 * E2E helper MU plugin: mock HTTP responses for linked image download tests.
 *
 * Intercepts outgoing HTTP requests to old-site-e2e-test.example.com and
 * returns a minimal valid JPEG so the importer can "download" images without
 * any real network access.
 */
add_filter(
	'pre_http_request',
	function ( $preempt, $parsed_args, $url ) {
		if ( false === strpos( $url, 'old-site-e2e-test.example.com' ) ) {
			return $preempt;
		}

		// Minimal valid JPEG (1x1 pixel, ~631 bytes).
		$jpeg_bytes = base64_decode(
			'/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCABkAGQDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPwB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwB//9k'
		);

		// For streaming requests (fetch_remote_file uses stream => true),
		// write the bytes to the designated temp file.
		if ( ! empty( $parsed_args['filename'] ) ) {
			file_put_contents( $parsed_args['filename'], $jpeg_bytes );
		}

		return array(
			'headers'  => array(
				'content-length' => (string) strlen( $jpeg_bytes ),
				'content-type'   => 'image/jpeg',
			),
			'body'     => $jpeg_bytes,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	},
	10,
	3
);
