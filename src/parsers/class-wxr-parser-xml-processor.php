<?php
/**
 * WordPress eXtended RSS file parser implementations
 *
 * @package WordPress
 * @subpackage Importer
 */

use WordPress\DataLiberation\EntityReader\WXREntityReader;

/**
 * WXR Parser that uses the XMLProcessor component.
 */
class WXR_Parser_XML_Processor {
	public $authors       = array();
	public $posts         = array();
	public $categories    = array();
	public $tags          = array();
	public $terms         = array();
	public $base_url      = '';
	public $base_blog_url = '';

	/**
	 * Parse a WXR file
	 *
	 * @param string $file Path to WXR file
	 * @return array|WP_Error Parsed data or error object
	 */
	public function parse( $file ) {
		// Trigger a warning for non-existent files to match legacy behavior and tests.
		if ( ! is_readable( $file ) ) {
			// Intentionally trigger a PHP warning; return value is ignored.
			file_get_contents( $file );
		}
		// Initialize variables
		$this->authors       = array();
		$this->posts         = array();
		$this->categories    = array();
		$this->tags          = array();
		$this->terms         = array();
		$this->base_url      = '';
		$this->base_blog_url = '';
		$wxr_version         = '';

		try {
			$reader = WXREntityReader::create_for_wordpress_importer( $file );
			// Parse the XML document
			$last_term = null;
			while ( $reader->next_entity() ) {
				$entity       = $reader->get_entity();
				$trimmed_data = array();
				foreach ( $entity->get_data() as $k => $v ) {
					if ( ! is_string( $v ) ) {
						$trimmed_data[ $k ] = $v;
						continue;
					}
					$trimmed_data[ $k ] = $v;
				}
				switch ( $entity->get_type() ) {
					case 'wxr_version':
						$wxr_version = $trimmed_data['wxr_version'];
						break;
					case 'site_option':
						if ( isset( $trimmed_data['option_name'], $trimmed_data['option_value'] ) ) {
							switch ( $trimmed_data['option_name'] ) {
								case 'wxr_version':
									$wxr_version = $trimmed_data['option_value'];
									break;
								case 'siteurl':
									$this->base_url = $trimmed_data['option_value'];
									break;
								case 'home':
									$this->base_blog_url = $trimmed_data['option_value'];
									break;
							}
						}
						break;
					case 'user':
						$key                   = isset( $trimmed_data['author_login'] ) ? $trimmed_data['author_login'] : (
							isset( $trimmed_data['author_email'] ) ? $trimmed_data['author_email'] : (
								isset( $trimmed_data['author_id'] ) ? $trimmed_data['author_id'] : count( $this->authors )
							)
						);
						$this->authors[ $key ] = $trimmed_data;
						break;
					case 'post':
						$this->posts[] = $trimmed_data;
						break;
					case 'post_meta':
						$last_post_key = count( $this->posts ) - 1;
						if ( ! isset( $this->posts[ $last_post_key ]['postmeta'] ) ) {
							$this->posts[ $last_post_key ]['postmeta'] = array();
						}
						// Ensure only expected keys 'key' and 'value' are present to match tests
						if ( isset( $trimmed_data['post_id'] ) ) {
							unset( $trimmed_data['post_id'] );
						}
						$this->posts[ $last_post_key ]['postmeta'][] = $trimmed_data;
						break;
					case 'comment':
						$last_post_key = count( $this->posts ) - 1;
						if ( ! isset( $this->posts[ $last_post_key ]['comments'] ) ) {
							$this->posts[ $last_post_key ]['comments'] = array();
						}
						$trimmed_data['commentmeta']                 = array();
						$this->posts[ $last_post_key ]['comments'][] = $trimmed_data;
						break;
					case 'comment_meta':
						$last_post_key      = count( $this->posts ) - 1;
						$last_comment_index = count( $this->posts[ $last_post_key ]['comments'] ) - 1;
						if ( $last_comment_index >= 0 ) {
							// Do not include comment_id in the final commentmeta array to match expected shape.
							if ( isset( $trimmed_data['comment_id'] ) ) {
								unset( $trimmed_data['comment_id'] );
							}
							$this->posts[ $last_post_key ]['comments'][ $last_comment_index ]['commentmeta'][] = $trimmed_data;
						}
						break;
					case 'category':
						if ( isset( $trimmed_data['term_id'] ) ) {
							$trimmed_data['term_id'] = (int) $trimmed_data['term_id'];
						}
						unset( $trimmed_data['taxonomy'], $trimmed_data['term_description'] );
						$this->categories[] = $trimmed_data;
						$last_term_index    = count( $this->categories ) - 1;
						$last_term          = &$this->categories[ $last_term_index ];
						break;
					case 'tag':
						if ( isset( $trimmed_data['term_id'] ) ) {
							$trimmed_data['term_id'] = (int) $trimmed_data['term_id'];
						}
						unset( $trimmed_data['taxonomy'], $trimmed_data['term_description'] );
						$this->tags[]    = $trimmed_data;
						$last_term_index = count( $this->tags ) - 1;
						$last_term       = &$this->tags[ $last_term_index ];
						break;
					case 'term':
						if ( isset( $trimmed_data['term_id'] ) ) {
							$trimmed_data['term_id'] = (int) $trimmed_data['term_id'];
						}
						// unset($trimmed_data['taxonomy'], $trimmed_data['term_description']);
						// $trimmed_data['taxonomy'] id 'domain'
						// $trimmed_data['slug'] id 'nicename'
						$this->terms[]   = $trimmed_data;
						$last_term_index = count( $this->terms ) - 1;
						$last_term       = &$this->terms[ $last_term_index ];
						break;
					case 'termmeta':
					case 'term_meta':
						if ( ! isset( $last_term['termmeta'] ) ) {
							$last_term['termmeta'] = array();
						}
						$last_term['termmeta'][] = $trimmed_data;
						break;
					case 'wxr_version':
						// Support entity-style wxr_version array or raw string
						if ( isset( $trimmed_data['wxr_version'] ) ) {
							$wxr_version = $trimmed_data['wxr_version'];
						} else {
							$wxr_version = $trimmed_data;
						}
						break;
					default:
						// Ignore unknown entity types silently to avoid emitting notices.
						break;
				}
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'WXR_parse_error', $e->getMessage() );
		}

		// Normalize per-post terms to legacy shape { domain, slug, name } when needed.
		foreach ( $this->posts as $idx => $post ) {
			if ( isset( $post['terms'] ) && is_array( $post['terms'] ) ) {
				foreach ( $post['terms'] as $tidx => $term ) {
					if ( ! isset( $term['domain'] ) && isset( $term['taxonomy'] ) ) {
						$mapped                                = array(
							'domain' => $term['taxonomy'],
							'slug'   => isset( $term['slug'] ) ? $term['slug'] : '',
							'name'   => isset( $term['description'] ) ? $term['description'] : '',
						);
						$this->posts[ $idx ]['terms'][ $tidx ] = $mapped;
					}
				}
			}
		}

		// Validate WXR version
		if ( empty( $wxr_version ) || ! preg_match( '/^\d+\.\d+$/', $wxr_version ) ) {
			return new WP_Error( 'WXR_parse_error', __( 'This does not appear to be a WXR file, missing/invalid WXR version number', 'wordpress-importer' ) );
		}

		return array(
			'authors'       => $this->authors,
			'posts'         => $this->posts,
			'categories'    => $this->categories,
			'tags'          => $this->tags,
			'terms'         => $this->terms,
			'base_url'      => $this->base_url,
			'base_blog_url' => $this->base_blog_url,
			'version'       => $wxr_version,
		);
	}

}
