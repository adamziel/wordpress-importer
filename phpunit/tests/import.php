<?php

require_once __DIR__ . '/base.php';

/**
 * @group import
 */
class Tests_Import_Import extends WP_Import_UnitTestCase {

	protected $previous_uploads_structure;
	protected $mocked_attachment_url;
	protected $mocked_attachment_body;

	public function set_up() {
		parent::set_up();

		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true );
		}

		if ( ! defined( 'WP_LOAD_IMPORTERS' ) ) {
			define( 'WP_LOAD_IMPORTERS', true );
		}

		add_filter( 'import_allow_create_users', '__return_true' );

		global $wpdb;
		// Crude but effective: make sure there's no residual data in the main tables.
		foreach ( array( 'posts', 'postmeta', 'comments', 'terms', 'term_taxonomy', 'term_relationships', 'users', 'usermeta' ) as $table ) {
			$wpdb->query( "DELETE FROM {$wpdb->$table}" );
		}
	}

	public function tear_down() {
		remove_filter( 'import_allow_create_users', '__return_true' );

		if ( null !== $this->previous_uploads_structure ) {
			update_option( 'uploads_use_yearmonth_folders', $this->previous_uploads_structure );
			$this->previous_uploads_structure = null;
		}

		parent::tear_down();
	}

	/**
	 * @covers WP_Import::import
	 */
	public function test_small_import() {
		global $wpdb;

		$authors = array(
			'admin'  => false,
			'editor' => false,
			'author' => false,
		);
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/small-export.xml', $authors );

		// Ensure that authors were imported correctly.
		$user_count = count_users();
		$this->assertSame( 3, $user_count['total_users'] );
		$admin = get_user_by( 'login', 'admin' );
		$this->assertSame( 'admin', $admin->user_login );
		$this->assertSame( 'local@host.null', $admin->user_email );
		$editor = get_user_by( 'login', 'editor' );
		$this->assertSame( 'editor', $editor->user_login );
		$this->assertSame( 'editor@example.org', $editor->user_email );
		$this->assertSame( 'FirstName', $editor->user_firstname );
		$this->assertSame( 'LastName', $editor->user_lastname );
		$author = get_user_by( 'login', 'author' );
		$this->assertSame( 'author', $author->user_login );
		$this->assertSame( 'author@example.org', $author->user_email );

		// Check that terms were imported correctly.
		$this->assertSame( '30', wp_count_terms( array( 'taxonomy' => 'category' ) ) );
		$this->assertSame( '3', wp_count_terms( array( 'taxonomy' => 'post_tag' ) ) );
		$foo = get_term_by( 'slug', 'foo', 'category' );
		$this->assertSame( 0, $foo->parent );
		$bar     = get_term_by( 'slug', 'bar', 'category' );
		$foo_bar = get_term_by( 'slug', 'foo-bar', 'category' );
		$this->assertSame( $bar->term_id, $foo_bar->parent );

		// Check that posts/pages were imported correctly.
		$post_count = wp_count_posts( 'post' );
		$this->assertSame( '5', $post_count->publish );
		$this->assertSame( '1', $post_count->private );
		$page_count = wp_count_posts( 'page' );
		$this->assertSame( '4', $page_count->publish );
		$this->assertSame( '1', $page_count->draft );
		$comment_count = wp_count_comments();
		$this->assertSame( 1, $comment_count->total_comments );

		$posts = get_posts(
			array(
				'numberposts' => 20,
				'post_type'   => 'any',
				'post_status' => 'any',
				'orderby'     => 'ID',
			)
		);
		$this->assertCount( 11, $posts );

		$post = $posts[0];
		$this->assertSame( 'Many Categories', $post->post_title );
		$this->assertSame( 'many-categories', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID );
		$this->assertCount( 27, $cats );

		$post = $posts[1];
		$this->assertSame( 'Non-standard post format', $post->post_title );
		$this->assertSame( 'non-standard-post-format', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID );
		$this->assertCount( 1, $cats );
		$this->assertTrue( has_post_format( 'aside', $post->ID ) );

		$post = $posts[2];
		$this->assertSame( 'Top-level Foo', $post->post_title );
		$this->assertSame( 'top-level-foo', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );
		$this->assertCount( 1, $cats );
		$this->assertSame( 'foo', $cats[0]->slug );

		$post = $posts[3];
		$this->assertSame( 'Foo-child', $post->post_title );
		$this->assertSame( 'foo-child', $post->post_name );
		$this->assertSame( (string) $editor->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );
		$this->assertCount( 1, $cats );
		$this->assertSame( 'foo-bar', $cats[0]->slug );

		$post = $posts[4];
		$this->assertSame( 'Private Post', $post->post_title );
		$this->assertSame( 'private-post', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'private', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID );
		$this->assertCount( 1, $cats );
		$tags = wp_get_post_tags( $post->ID );
		$this->assertCount( 3, $tags );
		$this->assertSame( 'tag1', $tags[0]->slug );
		$this->assertSame( 'tag2', $tags[1]->slug );
		$this->assertSame( 'tag3', $tags[2]->slug );

		$post = $posts[5];
		$this->assertSame( '1-col page', $post->post_title );
		$this->assertSame( '1-col-page', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$this->assertSame( 'onecolumn-page.php', get_post_meta( $post->ID, '_wp_page_template', true ) );

		$post = $posts[6];
		$this->assertSame( 'Draft Page', $post->post_title );
		$this->assertSame( '', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$this->assertSame( 'default', get_post_meta( $post->ID, '_wp_page_template', true ) );

		$post = $posts[7];
		$this->assertSame( 'Parent Page', $post->post_title );
		$this->assertSame( 'parent-page', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$this->assertSame( 'default', get_post_meta( $post->ID, '_wp_page_template', true ) );

		$post = $posts[8];
		$this->assertSame( 'Child Page', $post->post_title );
		$this->assertSame( 'child-page', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( $posts[7]->ID, $post->post_parent );
		$this->assertSame( 'default', get_post_meta( $post->ID, '_wp_page_template', true ) );

		$post = $posts[9];
		$this->assertSame( 'Sample Page', $post->post_title );
		$this->assertSame( 'sample-page', $post->post_name );
		$this->assertSame( (string) $admin->ID, $post->post_author );
		$this->assertSame( 'page', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$this->assertSame( 'default', get_post_meta( $post->ID, '_wp_page_template', true ) );

		$post = $posts[10];
		$this->assertSame( 'Hello world!', $post->post_title );
		$this->assertSame( 'hello-world', $post->post_name );
		$this->assertSame( (string) $author->ID, $post->post_author );
		$this->assertSame( 'post', $post->post_type );
		$this->assertSame( 'publish', $post->post_status );
		$this->assertSame( 0, $post->post_parent );
		$cats = wp_get_post_categories( $post->ID );
		$this->assertCount( 1, $cats );
	}

	/**
	 * @covers WP_Import::import
	 */
	public function test_double_import() {
		$authors = array(
			'admin'  => false,
			'editor' => false,
			'author' => false,
		);
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/small-export.xml', $authors );
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/small-export.xml', $authors );

		$user_count = count_users();
		$this->assertSame( 3, $user_count['total_users'] );
		$admin = get_user_by( 'login', 'admin' );
		$this->assertSame( 'admin', $admin->user_login );
		$this->assertSame( 'local@host.null', $admin->user_email );
		$editor = get_user_by( 'login', 'editor' );
		$this->assertSame( 'editor', $editor->user_login );
		$this->assertSame( 'editor@example.org', $editor->user_email );
		$this->assertSame( 'FirstName', $editor->user_firstname );
		$this->assertSame( 'LastName', $editor->user_lastname );
		$author = get_user_by( 'login', 'author' );
		$this->assertSame( 'author', $author->user_login );
		$this->assertSame( 'author@example.org', $author->user_email );

		$this->assertSame( '30', wp_count_terms( array( 'taxonomy' => 'category' ) ) );
		$this->assertSame( '3', wp_count_terms( array( 'taxonomy' => 'post_tag' ) ) );
		$foo = get_term_by( 'slug', 'foo', 'category' );
		$this->assertSame( 0, $foo->parent );
		$bar     = get_term_by( 'slug', 'bar', 'category' );
		$foo_bar = get_term_by( 'slug', 'foo-bar', 'category' );
		$this->assertSame( $bar->term_id, $foo_bar->parent );

		$post_count = wp_count_posts( 'post' );
		$this->assertSame( '5', $post_count->publish );
		$this->assertSame( '1', $post_count->private );
		$page_count = wp_count_posts( 'page' );
		$this->assertSame( '4', $page_count->publish );
		$this->assertSame( '1', $page_count->draft );
		$comment_count = wp_count_comments();
		$this->assertSame( 1, $comment_count->total_comments );
	}

	/**
	 * @ticket 21007
	 *
	 * @covers WP_Import::import
	 */
	public function test_slashes_should_not_be_stripped() {
		global $wpdb;

		$authors = array( 'admin' => false );
		$this->_import_wp( DIR_TESTDATA_WP_IMPORTER . '/slashes.xml', $authors );

		$alpha = get_term_by( 'slug', 'alpha', 'category' );
		$this->assertSame( 'a \"great\" category', $alpha->name );

		$tag1 = get_term_by( 'slug', 'tag1', 'post_tag' );
		$this->assertSame( "foo\'bar", $tag1->name );

		$posts = get_posts(
			array(
				'post_type'   => 'any',
				'post_status' => 'any',
			)
		);
		$this->assertNotEmpty( $posts );
		$this->assertSame( 'Slashes aren\\\'t \"cool\"', $posts[0]->post_content );

		$comments = get_comments(
			array(
				'post_id' => $posts[0]->post_ID,
			)
		);
		$this->assertNotEmpty( $comments );
		$this->assertSame( '\o/ ¯\_(ツ)_/¯', $comments[0]->comment_content );
	}

	/**
	 * Ensure no PHP 8.1 deprecation notice is thrown when a URL is passed without a path component.
	 *
	 * Note: this test doesn't test anything else of the functionality in the `WP_Import::fetch_remote_file()` method!
	 */
	public function test_fetch_remote_file_php81_deprecation() {
		$importer = new WP_Import();
		$result   = $importer->fetch_remote_file( 'https://example.com', array() );

		$this->assertWPError( $result, 'Call to fetch_remote_file() did not return expected WP Error object' );
		$this->assertSame(
			'Sorry, this file type is not permitted for security reasons.',
			$result->get_error_message(),
			'The WP Error object did not contain the expected error'
		);
	}

	/**
	 * @dataProvider data_flat_attachment_import_rewrites_attachment_url
	 */
	public function test_flat_attachment_import_rewrites_attachment_url( $file, $rewrite_urls ) {
		$this->previous_uploads_structure = get_option( 'uploads_use_yearmonth_folders', true );
		update_option( 'uploads_use_yearmonth_folders', 0 );

		$remote_url  = 'https://wpthemetestdata.files.wordpress.com/2008/06/canola2.jpg';
		$image_bytes = base64_decode(
			'/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCABkAGQDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPwB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwB//9k',
			true
		);
		$this->assertNotFalse( $image_bytes, 'Failed to decode mock attachment image.' );

		$this->mocked_attachment_url  = $remote_url;
		$this->mocked_attachment_body = $image_bytes;

		add_filter( 'pre_http_request', array( $this, 'filter_mock_attachment_request' ), 10, 3 );

		try {
			$this->_import_wp( $file, array(), true, $rewrite_urls );
		} finally {
			remove_filter( 'pre_http_request', array( $this, 'filter_mock_attachment_request' ), 10 );
			$this->mocked_attachment_url  = '';
			$this->mocked_attachment_body = '';
		}

		$attachments = get_posts(
			array(
				'numberposts' => -1,
				'post_type'   => 'attachment',
				'post_status' => 'inherit',
			)
		);
		$this->assertCount( 1, $attachments, 'Expected the attachment post to be imported.' );

		$attachment    = $attachments[0];
		$attached_file = get_attached_file( $attachment->ID );
		$this->assertNotFalse( $attached_file );
		$this->assertFileExists( $attached_file );

		$localized_url = wp_get_attachment_url( $attachment->ID );
		$this->assertStringStartsWith( 'http://example.org/wp-content/uploads/canola2', $localized_url );

		$posts = get_posts(
			array(
				'post_type'   => 'post',
				'post_status' => 'any',
			)
		);
		$this->assertCount( 1, $posts, 'Expected the post to be imported.' );

		$post = $posts[0];
		$this->assertStringContainsString( 'http://example.org/wp-content/uploads/canola2', $post->post_content );
	}

	public static function data_flat_attachment_import_rewrites_attachment_url() {
		return array(
			'Same-site attachments with URL rewriting'     => array( DIR_TESTDATA_WP_IMPORTER . '/wxr-flat-attachment-same-site.xml', true ),
			'Same-site attachments with no URL rewriting'  => array( DIR_TESTDATA_WP_IMPORTER . '/wxr-flat-attachment-same-site.xml', false ),
			'Cross-site attachments with URL rewriting'    => array( DIR_TESTDATA_WP_IMPORTER . '/wxr-flat-attachment-different-site.xml', true ),
			'Cross-site attachments with no URL rewriting' => array( DIR_TESTDATA_WP_IMPORTER . '/wxr-flat-attachment-different-site.xml', false ),
		);
	}

	/**
	 * Provides a mocked HTTP response when the importer downloads attachments.
	 *
	 * @param false|array|WP_Error $preempt     Preempted response.
	 * @param array                $parsed_args Parsed HTTP arguments.
	 * @param string               $url         Requested URL.
	 * @return false|array|WP_Error HTTP response override when mocking, otherwise original value.
	 */
	public function filter_mock_attachment_request( $preempt, $parsed_args, $url ) {
		if ( $url !== $this->mocked_attachment_url || '' === $this->mocked_attachment_body ) {
			return $preempt;
		}

		if ( empty( $parsed_args['filename'] ) ) {
			return $preempt;
		}

		$result = file_put_contents( $parsed_args['filename'], $this->mocked_attachment_body );
		if ( false === $result ) {
			return new WP_Error( 'test_mock_http_write_failed', 'Failed to write mocked attachment response.' );
		}

		return array(
			'headers'  => array(
				'content-length' => (string) strlen( $this->mocked_attachment_body ),
				'content-type'   => 'image/jpeg',
			),
			'body'     => '',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
		);
	}

	/**
	 * Checks whether the BlockMarkupUrlProcessor is available. It requires
	 * WP_HTML_Tag_Processor::next_token() which was introduced in WordPress 6.5,
	 * and PHP 7.4+ for the underlying URL parser to function correctly.
	 */
	private function skip_if_block_markup_url_processor_unavailable() {
		if ( PHP_VERSION_ID < 70400 ) {
			$this->markTestSkipped( 'Linked images feature requires PHP 7.4+.' );
		}
		if ( ! method_exists( 'WP_HTML_Tag_Processor', 'next_token' ) ) {
			$this->markTestSkipped( 'BlockMarkupUrlProcessor requires WP_HTML_Tag_Processor::next_token() (WordPress 6.5+).' );
		}
	}

	/**
	 * @covers WP_Import::extract_image_urls_from_content
	 */
	public function test_extract_image_urls_finds_img_src() {
		$this->skip_if_block_markup_url_processor_unavailable();
		$importer           = new WP_Import();
		$importer->base_url = 'https://old-site.example.com';

		$content = '<p>Hello</p><img src="https://old-site.example.com/photo.jpg" alt="photo" />';
		$urls    = $importer->extract_image_urls_from_content( $content );

		$this->assertContains( 'https://old-site.example.com/photo.jpg', $urls );
	}

	/**
	 * @covers WP_Import::extract_image_urls_from_content
	 */
	public function test_extract_image_urls_finds_css_background_image() {
		$this->skip_if_block_markup_url_processor_unavailable();
		$importer           = new WP_Import();
		$importer->base_url = 'https://old-site.example.com';

		$content = '<div style="background-image: url(https://old-site.example.com/hero.png)">text</div>';
		$urls    = $importer->extract_image_urls_from_content( $content );

		$this->assertContains( 'https://old-site.example.com/hero.png', $urls );
	}

	/**
	 * @covers WP_Import::extract_image_urls_from_content
	 */
	public function test_extract_image_urls_finds_css_background_shorthand() {
		$this->skip_if_block_markup_url_processor_unavailable();
		$importer           = new WP_Import();
		$importer->base_url = 'https://old-site.example.com';

		$content = '<div style="background: url(https://old-site.example.com/bg.jpg) center / cover no-repeat">text</div>';
		$urls    = $importer->extract_image_urls_from_content( $content );

		$this->assertContains( 'https://old-site.example.com/bg.jpg', $urls );
	}

	/**
	 * CSS url() in a non-background property like cursor should NOT be extracted.
	 *
	 * @covers WP_Import::extract_image_urls_from_content
	 */
	public function test_extract_image_urls_ignores_non_background_css_url() {
		$this->skip_if_block_markup_url_processor_unavailable();
		$importer           = new WP_Import();
		$importer->base_url = 'https://old-site.example.com';

		$content = '<div style="cursor: url(https://old-site.example.com/cursor.cur), auto">text</div>';
		$urls    = $importer->extract_image_urls_from_content( $content );

		$this->assertNotContains( 'https://old-site.example.com/cursor.cur', $urls );
	}

	/**
	 * @covers WP_Import::extract_image_urls_from_content
	 */
	public function test_extract_image_urls_ignores_css_data_uri() {
		$this->skip_if_block_markup_url_processor_unavailable();
		$importer           = new WP_Import();
		$importer->base_url = 'https://old-site.example.com';

		$content = '<div style="background-image: url(data:image/png;base64,iVBOR)">text</div>';
		$urls    = $importer->extract_image_urls_from_content( $content );

		$this->assertEmpty( $urls );
	}

	/**
	 * @covers WP_Import::extract_image_urls_from_content
	 */
	public function test_extract_image_urls_finds_block_image_attribute() {
		$this->skip_if_block_markup_url_processor_unavailable();
		$importer           = new WP_Import();
		$importer->base_url = 'https://old-site.example.com';

		$content = '<!-- wp:image {"url":"https://old-site.example.com/block-photo.jpg"} -->'
			. '<figure class="wp-block-image"><img src="https://old-site.example.com/block-photo.jpg" alt=""/></figure>'
			. '<!-- /wp:image -->';
		$urls    = $importer->extract_image_urls_from_content( $content );

		$this->assertContains( 'https://old-site.example.com/block-photo.jpg', $urls );
	}

	/**
	 * Links (<a href>) should NOT be treated as image URLs.
	 *
	 * @covers WP_Import::extract_image_urls_from_content
	 */
	public function test_extract_image_urls_ignores_links() {
		$this->skip_if_block_markup_url_processor_unavailable();
		$importer           = new WP_Import();
		$importer->base_url = 'https://old-site.example.com';

		$content = '<p><a href="https://old-site.example.com/page">Click here</a></p>';
		$urls    = $importer->extract_image_urls_from_content( $content );

		$this->assertNotContains( 'https://old-site.example.com/page', $urls );
	}

	/**
	 * @covers WP_Import::extract_image_urls_from_content
	 */
	public function test_extract_image_urls_resolves_relative_src() {
		$this->skip_if_block_markup_url_processor_unavailable();
		$importer           = new WP_Import();
		$importer->base_url = 'https://old-site.example.com';

		$content = '<img src="/images/relative.jpg" alt="" />';
		$urls    = $importer->extract_image_urls_from_content( $content );

		$this->assertContains( 'https://old-site.example.com/images/relative.jpg', $urls );
	}

	/**
	 * @covers WP_Import::extract_image_urls_from_content
	 */
	public function test_extract_image_urls_deduplicates() {
		$this->skip_if_block_markup_url_processor_unavailable();
		$importer           = new WP_Import();
		$importer->base_url = 'https://old-site.example.com';

		// Same image URL used twice: in block attribute and in <img> tag.
		$content = '<!-- wp:image {"url":"https://old-site.example.com/photo.jpg"} -->'
			. '<figure class="wp-block-image"><img src="https://old-site.example.com/photo.jpg" alt=""/></figure>'
			. '<!-- /wp:image -->';
		$urls    = $importer->extract_image_urls_from_content( $content );

		$this->assertCount( 1, $urls );
		$this->assertContains( 'https://old-site.example.com/photo.jpg', $urls );
	}

	/**
	 * Integration test: import a WXR with no attachment posts and verify that
	 * linked images are downloaded and post content is updated.
	 *
	 * @covers WP_Import::process_linked_images
	 */
	public function test_download_linked_images_creates_attachments_and_remaps_urls() {
		$this->skip_if_block_markup_url_processor_unavailable();
		$this->previous_uploads_structure = get_option( 'uploads_use_yearmonth_folders', true );
		update_option( 'uploads_use_yearmonth_folders', 0 );

		// Minimal valid JPEG bytes for the mock.
		$image_bytes = base64_decode(
			'/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCABkAGQDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPwB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwB//9k',
			true
		);

		// Mock HTTP responses for both image URLs in the test fixture.
		$mock_urls = array(
			'https://old-site.example.com/images/photo.jpg',
			'https://old-site.example.com/images/hero.png',
			'https://old-site.example.com/images/block-photo.jpg',
		);

		$mock_callback = function ( $preempt, $parsed_args, $url ) use ( $mock_urls, $image_bytes ) {
			if ( ! in_array( $url, $mock_urls, true ) ) {
				return $preempt;
			}
			if ( empty( $parsed_args['filename'] ) ) {
				return $preempt;
			}

			file_put_contents( $parsed_args['filename'], $image_bytes );

			// Derive content-type from the URL extension.
			$ext          = pathinfo( parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION );
			$content_type = 'png' === $ext ? 'image/png' : 'image/jpeg';

			return array(
				'headers'  => array(
					'content-length' => (string) strlen( $image_bytes ),
					'content-type'   => $content_type,
				),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
			);
		};

		add_filter( 'pre_http_request', $mock_callback, 10, 3 );

		try {
			$importer = new WP_Import();
			$file     = realpath( DIR_TESTDATA_WP_IMPORTER . '/wxr-linked-images.xml' );

			$_POST = array(
				'imported_authors' => array( 0 => 'admin' ),
				'user_map'         => array(),
				'user_new'         => array(),
			);

			ob_start();
			$importer->fetch_attachments      = false;
			$importer->download_linked_images = true;
			$importer->import( $file, array(
				'rewrite_urls'           => false,
				'download_linked_images' => true,
			) );
			ob_end_clean();

			$_POST = array();
		} finally {
			remove_filter( 'pre_http_request', $mock_callback, 10 );
		}

		// Verify attachment posts were created for the linked images.
		$attachments = get_posts( array(
			'numberposts' => -1,
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
		) );
		$this->assertGreaterThanOrEqual( 2, count( $attachments ), 'Expected at least 2 attachment posts for downloaded linked images.' );

		// Verify that the downloaded images exist on disk.
		foreach ( $attachments as $attachment ) {
			$attached_file = get_attached_file( $attachment->ID );
			$this->assertFileExists( $attached_file );
		}

		// Verify that post content was updated to reference local URLs.
		$posts = get_posts( array(
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => -1,
		) );
		$this->assertCount( 2, $posts, 'Expected 2 posts to be imported.' );

		foreach ( $posts as $post ) {
			$this->assertStringNotContainsString(
				'old-site.example.com/images/',
				$post->post_content,
				'Post content should no longer reference the old site image URLs after backfill.'
			);
		}
	}

	/**
	 * When download_linked_images is off, no extra attachments should be created.
	 *
	 * @covers WP_Import::process_linked_images
	 */
	public function test_download_linked_images_disabled_by_default() {
		$importer = new WP_Import();
		$file     = realpath( DIR_TESTDATA_WP_IMPORTER . '/wxr-linked-images.xml' );

		$_POST = array(
			'imported_authors' => array( 0 => 'admin' ),
			'user_map'         => array(),
			'user_new'         => array(),
		);

		ob_start();
		$importer->fetch_attachments = false;
		$importer->import( $file, array( 'rewrite_urls' => false ) );
		ob_end_clean();

		$_POST = array();

		$attachments = get_posts( array(
			'numberposts' => -1,
			'post_type'   => 'attachment',
			'post_status' => 'inherit',
		) );
		$this->assertCount( 0, $attachments, 'No attachments should be created when download_linked_images is disabled.' );

		// Original image URLs should still be in the content.
		$post = get_posts( array(
			'post_type'   => 'post',
			'post_status' => 'any',
			'numberposts' => 1,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		) );
		$this->assertStringContainsString(
			'old-site.example.com/images/photo.jpg',
			$post[0]->post_content,
			'Original image URLs should remain when download is disabled.'
		);
	}
}
