<?php

/**
 * Output Open Graph protocol for consumption by Facebook and other consuming agents
 *
 * @since 1.1
 * @link http://ogp.me/ Open Graph protocol
 */
class Facebook_Open_Graph_Protocol {
	/**
	 * Base IRI of Open Graph protocol RDFa properties
	 *
	 * @since 1.1
	 * @var string
	 */
	const OGP_NS = 'http://ogp.me/ns#';

	/**
	 * Base IRI of Facebook RDFa properties
	 *
	 * @since 1.1
	 * @var string
	 */
	const FB_NS = 'http://ogp.me/ns/fb#';

	/**
	 * Base IRI of Open Graph protocol article object global properties
	 *
	 * @since 1.1
	 * @var string
	 */
	const ARTICLE_NS = 'http://ogp.me/ns/article#';

	/**
	 * Base IRI of Open Graph protocol profile object global properties
	 *
	 * @since 1.1
	 * @var string
	 */
	const PROFILE_NS = 'http://ogp.me/ns/profile#';

	/**
	 * Minimum edge of an acceptable Open Graph protocol image in whole pixels
	 *
	 * @since 1.1.9
	 * @var int
	 */
	const MIN_IMAGE_DIMENSION = 200;

	/**
	 * Only list the first N eligible Open Graph protocol images
	 * Facebook helps a person choose one of three parsed images when a link is pasted into a message
	 * Other consumers of Open Graph protocol data (Google, Twitter, etc.) may index additional images; increase this number to trade-off speed for coverage
	 *
	 * @since 1.5
	 @var int
	 */
	const MAX_IMAGE_COUNT = 3;

	/**
	 * Recursively build RDFa <meta> elements used for Open Graph protocol
	 *
	 * @since 1.0
	 * @param string $property whitespace separated list of CURIEs placed in a property attribute
	 * @param mixed content attribute value for the given property. use an array for array property values or structured properties
	 */
	 public static function meta_elements( $property, $content ) {
		if ( empty( $property ) || empty( $content ) )
			return;

		// array of property values or structured property
		if ( is_array( $content ) ) {
			// account for the special structured property of url which is equivalent to the root tag and sets up the structure
			// must appear before other attributes
			if ( isset( $content['url'] ) ) {
				self::meta_elements( $property, $content['url'] );
				unset( $content['url'] );
			}
			foreach( $content as $structured_property => $content_value ) {
				// handle numeric keys from regular arrays
				if ( ! is_string( $structured_property ) )
					self::meta_elements( $property, $content_value );
				else
					self::meta_elements( $property . ':' . $structured_property, $content_value );
			}
		} else {
			echo '<meta property="' . $property . '" content="' . esc_attr( $content ) . '"';

			// do not use trailing slash if HTML5
			// http://wiki.whatwg.org/wiki/FAQ#Should_I_close_empty_elements_with_.2F.3E_or_.3E.3F
			if ( current_theme_supports('html5') )
				echo '>';
			else
				echo ' />';
			echo "\n";
		}
	}

	/**
	 * Clean post description text in preparation for Open Graph protocol description or Facebook post caption|description
	 *
	 * @since 1.1
	 * @uses strip_shortcodes()
	 * @uses wp_trim_words()
	 * @param string $description description text
	 * @return string description text passed through WordPress Core description cleaners, possibly trimmed
	 */
	public static function clean_description( $description, $trimmed = true ) {
		$description = trim( $description );
		if ( ! $description )
			return '';

		// basic filters from wp_trim_excerpt() that should apply to both excerpt and content
		// note: the_content filter is so polluted with extra third-party stuff we purposely avoid to better represent page content
		$description = strip_shortcodes( $description ); // remove any shortcodes that may exist, such as an image macro
		$description = str_replace( ']]>', ']]&gt;', $description );
		$description = trim( $description );

		if ( ! $description )
			return '';

		if ( $trimmed ) {
			// use the built-in WordPress function for localized support of words vs. characters
			// pass in customizations based on WordPress with publisher overrides
			$description = wp_trim_words(
				$description,
				apply_filters( 'facebook_excerpt_length', apply_filters( 'excerpt_length', 55 ) ), // standard excerpt length, which may be customized by the publisher via the core filter, passed through another filter for our context
				apply_filters( 'facebook_excerpt_more', __( '&hellip;' ) ) // filter the wp_trim_words default for publisher customization
			);
		} else {
			$description = wp_strip_all_tags( $description );
		}

		if ( $description )
			return $description;

		return '';
	}

	/**
	 * Convert full IRI Open Graph protocol values to CURIE prefixed values
	 * Mapping CURIEs is presumed to occur in a parent element of the group of <meta>s
	 *
	 * @since 1.1.6
	 * @link http://www.w3.org/TR/rdfa-syntax/#s_curies RDFa 1.1 Core CURIEs
	 * @param array $ogp associative array of full IRI key and OGP value
	 * @return array associative array of full IRI replaced with prefix where mapped and the same OGP value passed in
	 */
	public static function prefixed_properties( $ogp ) {
		global $facebook_loader;

		if ( ! is_array( $ogp ) || empty( $ogp ) )
			return array();

		// map the referenced used by this plugin as well as OGP globals
		$curies = apply_filters( 'facebook_rdfa_mappings', array(
			self::OGP_NS => array( 'prefix' => 'og' ),
			self::FB_NS => array( 'prefix' => 'fb' ),
			self::ARTICLE_NS => array( 'prefix' => 'article' ),
			self::PROFILE_NS => array( 'prefix' => 'profile' ),
			'http://ogp.me/ns/book#' => array( 'prefix' => 'book' ),
			'http://ogp.me/ns/music#' => array( 'prefix' => 'music' ),
			'http://ogp.me/ns/video#' => array( 'prefix' => 'video' )
		) );
		if ( isset( $facebook_loader->credentials['app_namespace'] ) ) {
			$app_ns_url = esc_url_raw( 'http://ogp.me/ns/fb/' . $facebook_loader->credentials['app_namespace'] . '#', array( 'http' ) );
			if ( $app_ns_url && ! isset( $curies[$app_ns_url] ) )
				$curies[$app_ns_url] = array( 'prefix' => $facebook_loader->credentials['app_namespace'] );
		}

		foreach ( $curies as $reference => $properties ) {
			if ( ! isset( $properties['prefix'] ) ) {
				unset( $curies[$reference] );
				continue;
			}
			$properties['key_length'] = strlen( $reference );
			$curies[$reference] = $properties;
		}

		$ogp_prefixed = array();

		foreach ( $ogp as $property => $value ) {
			$prefixed_property_set = false;
			$property_length = strlen( $property );
			foreach ( $curies as $reference => $properties ) {
				if ( $property_length > $properties['key_length'] && substr_compare( $property, $reference, 0, $properties['key_length'] ) === 0 ) {
					$ogp_prefixed[ $properties['prefix'] . ':' . substr( $property , $properties['key_length'] ) ] = $value;
					$prefixed_property_set = true;
					break;
				}
			}
			unset( $property_length );

			// pass through unmapped values
			if ( ! $prefixed_property_set )
				$ogp_prefixed[$property] = $value;

			unset( $prefixed_property_set );
		}

		return $ogp_prefixed;
	}

	/**
	 * Add Open Graph protocol markup to <head>
	 * We use full IRIs for consistent mapping between mapped CURIE prefixes defined in a parent element and self-contained properties using a full IRI
	 *
	 * @since 1.0
	 * @link http://www.w3.org/TR/rdfa-syntax/#s_curieprocessing RDFa Core 1.1 CURIE and IRI processing
	 */
	public static function add_og_protocol() {
		global $post, $facebook_loader;

		$meta_tags = array(
			self::OGP_NS . 'site_name' => get_bloginfo( 'name' ),
			self::OGP_NS . 'type' => 'website'
		);

		if ( isset( $facebook_loader ) ) {
			if ( isset( $facebook_loader->locale ) )
				$meta_tags[ self::OGP_NS . 'locale' ] = $facebook_loader->locale;
			if ( isset( $facebook_loader->credentials ) && isset( $facebook_loader->credentials['app_id'] ) && $facebook_loader->credentials['app_id'] )
				$meta_tags[ self::FB_NS . 'app_id' ] = $facebook_loader->credentials['app_id'];
		}

		if ( is_home() || is_front_page() ) {
			$meta_tags[ self::OGP_NS . 'title' ] = get_bloginfo( 'name' );
			$meta_tags[ self::OGP_NS . 'description' ] = get_bloginfo( 'description' );
			$meta_tags[ self::OGP_NS . 'url' ] = home_url();
		} else if ( is_single() && empty( $post->post_password ) ) {
			setup_postdata( $post );
			$post_type = get_post_type();
			$meta_tags[ self::OGP_NS . 'url' ] = apply_filters( 'facebook_rel_canonical', get_permalink() );
			if ( post_type_supports( $post_type, 'title' ) )
				$meta_tags[ self::OGP_NS . 'title' ] = get_the_title();
			if ( post_type_supports( $post_type, 'excerpt' ) ) {
				$description = '';

				// did the publisher specify a custom excerpt? use it
				if ( ! empty( $post->post_excerpt ) )
					$description = apply_filters( 'get_the_excerpt', $post->post_excerpt );
				else
					$description = $post->post_content;

				$description = self::clean_description( $description );
				if ( $description )
					$meta_tags[ self::OGP_NS . 'description' ] = $description;
			}

			$meta_tags[ self::OGP_NS . 'type' ] = 'article';
			$meta_tags[ self::ARTICLE_NS . 'published_time' ] = date( 'c', strtotime( $post->post_date_gmt ) );
			$meta_tags[ self::ARTICLE_NS . 'modified_time' ] = date( 'c', strtotime( $post->post_modified_gmt ) );

			if ( post_type_supports( $post_type, 'author' ) && isset( $post->post_author ) ) {
				$meta_tags[ self::ARTICLE_NS . 'author' ] = get_author_posts_url( $post->post_author );
				// adding an fb:admin grants comment moderation permissions for Comment Box
				if ( get_option( 'facebook_comments_enabled' ) && user_can( $post->post_author, 'moderate_comments' ) ) {
					if ( ! class_exists( 'Facebook_Comments' ) )
						require_once( dirname(__FILE__) . '/social-plugins/class-facebook-comments.php' );
					if ( Facebook_Comments::comments_enabled_for_post_type( $post ) ) {
						if ( ! class_exists( 'Facebook_User' ) )
							require_once( dirname(__FILE__) . '/facebook-user.php' );
						$facebook_user_data = Facebook_User::get_user_meta( $post->post_author, 'fb_data', true );
						if ( is_array( $facebook_user_data ) && isset( $facebook_user_data['fb_uid'] ) )
							$meta_tags[ self::FB_NS . 'admins' ] = $facebook_user_data['fb_uid'];
						unset( $facebook_user_data );
					}
				}
			}

			// add the first category as a section. all other categories as tags
			$cat_ids = get_the_category();

			if ( ! empty( $cat_ids ) ) {
				$no_category = apply_filters( 'the_category', __( 'Uncategorized' ) );

				$cat = get_category( $cat_ids[0] );

				if ( ! empty( $cat ) && isset( $cat->name ) && $cat->name !== $no_category )
					$meta_tags[ self::ARTICLE_NS . 'section' ] = $cat->name;

				//output the rest of the categories as tags
				unset( $cat_ids[0] );

				if ( ! empty( $cat_ids ) ) {
					$meta_tags[ self::ARTICLE_NS . 'tag' ] = array();
					foreach( $cat_ids as $cat_id ) {
						$cat = get_category( $cat_id );
						if ( isset( $cat->name ) && $cat->name !== $no_category )
							$meta_tags[ self::ARTICLE_NS . 'tag' ][] = $cat->name;
						unset( $cat );
					}
				}
			}

			// add tags. treat tags as lower priority than multiple categories
			$tags = get_the_tags();

			if ( $tags ) {
				if ( ! array_key_exists( self::ARTICLE_NS . 'tag', $meta_tags ) )
					$meta_tags[ self::ARTICLE_NS . 'tag' ] = array();

				foreach ( $tags as $tag ) {
					$meta_tags[ self::ARTICLE_NS . 'tag' ][] = $tag->name;
				}
			}

			$images = self::get_og_images( $post );
			if ( ! empty( $images ) )
				$meta_tags[ self::OGP_NS . 'image' ] = array_values( $images );
			unset( $images );
		} else if ( is_author() ) {
			$author = get_queried_object();
			if ( $author && isset( $author->ID ) ) {
				$author_id = $author->ID;

				$meta_tags[ self::OGP_NS . 'type' ] = 'profile';
				$meta_tags[ self::OGP_NS . 'title' ] = get_the_author_meta( 'display_name', $author_id );
				$meta_tags[ self::OGP_NS . 'url' ] = get_author_posts_url( get_the_author_meta( 'ID', $author_id ) );
				$meta_tags[ self::PROFILE_NS . 'first_name' ] = get_the_author_meta( 'first_name', $author_id );
				$meta_tags[ self::PROFILE_NS . 'last_name'] = get_the_author_meta( 'last_name', $author_id );

				$description = self::clean_description( get_the_author_meta( 'description', $author_id ) );
				if ( $description )
					$meta_tags[ self::OGP_NS . 'description'] = $description;

				if ( ! class_exists( 'Facebook_User' ) )
					require_once( dirname(__FILE__) . '/facebook-user.php' );

				$facebook_user_data = Facebook_User::get_user_meta( $author_id, 'fb_data', true );
				if ( is_array( $facebook_user_data ) && isset( $facebook_user_data['third_party_id'] ) )
					$meta_tags[ self::FB_NS . 'profile_id' ] = $facebook_user_data['third_party_id'];
				unset( $facebook_user_data );

				// no need to show username if there is only one
				if ( is_multi_author() )
					$meta_tags[ self::PROFILE_NS . 'username' ] = get_the_author_meta( 'login', $author_id );
			}
			unset( $author );
		} else if ( is_page() ) {
			$meta_tags[ self::OGP_NS . 'type' ] = 'article';
			$meta_tags[ self::OGP_NS . 'title' ] = get_the_title();
			$meta_tags[ self::OGP_NS . 'url' ] = apply_filters( 'facebook_rel_canonical', get_permalink() );
		}

		$meta_tags = apply_filters( 'fb_meta_tags', $meta_tags, $post );

		// default: true while Facebook crawler corrects its indexing of full IRI values
		if ( apply_filters( 'facebook_ogp_prefixed', true ) )
			$meta_tags = self::prefixed_properties( $meta_tags );

		foreach ( $meta_tags as $property => $content ) {
			self::meta_elements( $property, $content );
		}
	}

	/**
	 * Extract Open Graph protocol image information from a WordPress image attachment
	 *
	 * @since 1.5
	 * @param int $attachment_id post id of the attachment object
	 * @param string $size requested size. one of thumbnail, medium, large, or full
	 * @return array associative array with URL, width, height or empty array if minimum requirements not met
	 */
	public static function attachment_to_og_image( $attachment_id, $size = 'full' ) {
		if ( ! ( is_string( $size ) && $size ) )
			$size = 'full';

		$image = array();
		list( $attachment_url, $attachment_width, $attachment_height ) = wp_get_attachment_image_src( $attachment_id, $size );
		if ( empty( $attachment_url ) )
			return $image;

		$image['url'] = $attachment_url;

		if ( ! empty( $attachment_width ) ) {
			$image['width'] = absint( $attachment_width );
			if ( ! $image['width'] )
				unset( $image['width'] );
			else if ( $image['width'] < self::MIN_IMAGE_DIMENSION )
				return array();
		}
		if ( ! empty( $attachment_height ) ) {
			$image['height'] = absint( $attachment_height );
			if ( ! $image['height'] )
				unset( $image['height'] );
			else if ( $image['height'] < self::MIN_IMAGE_DIMENSION )
				return array();
		}

		return $image;
	}

	/**
	 * Identify images attachments related to the post
	 * Request the full size of the attachment, not necessarily the same as the size used in the post
	 *
	 * @since 1.5
	 * @param WP_Post $post WordPress post of interest
	 */
	public static function get_og_images( $post ) {
		$og_images = array();

		if ( ! $post || ! isset( $post->ID ) )
			return $og_images;

		// does current post type and the current theme support post thumbnails?
		if ( post_type_supports( get_post_type( $post ), 'thumbnail' ) && function_exists( 'has_post_thumbnail' ) && has_post_thumbnail() ) {
			list( $post_thumbnail_url, $post_thumbnail_width, $post_thumbnail_height ) = wp_get_attachment_image_src( get_post_thumbnail_id( $post->ID ), 'full' );

			$image = self::attachment_to_og_image( get_post_thumbnail_id( $post->ID ) );
			if ( isset( $image['url'] ) && ! isset( $og_images[ $image['url'] ] ) )
				$og_images[ $image['url'] ] = $image;
			unset( $image );
		}

		// get image attachments
		if ( function_exists( 'get_attached_media' ) ) {
			$images = get_attached_media( 'image', $post->ID );
			if ( ! empty( $images ) ) {
				foreach( $images as $i ) {
					if ( ! isset( $i->ID ) )
						continue;

					$image = self::attachment_to_og_image( $i->ID );
					if ( ! isset( $image['url'] ) || isset( $og_images[ $image['url'] ] ) )
						continue;

					$og_images[ $image['url'] ] = $image;
					if ( count( $og_images ) === self::MAX_IMAGE_COUNT )
						return $og_images;
				}
			}
			unset( $images );
		}

		// add gallery content
		if ( function_exists( 'get_post_galleries' ) ) {
			// use get_post_galleries function from WP 3.6+
			$galleries = get_post_galleries( $post, false );
			foreach( $galleries as $gallery ) {
				// use ids to request full-size image URI
				if ( ! ( isset( $gallery['ids'] ) && $gallery['ids'] ) )
					continue;

				$gallery['ids'] = explode( ',', $gallery['ids'] );
				foreach( $gallery['ids'] as $attachment_id ) {
					$image = self::attachment_to_og_image( $attachment_id );
					if ( ! isset( $image['url'] ) || isset( $og_images[ $image['url'] ] ) )
						continue;

					$og_images[ $image['url'] ] = $image;
					if ( count( $og_images ) === self::MAX_IMAGE_COUNT )
						return $og_images;

					unset( $image );
				}
			}
			unset( $galleries );
		} else {
			// extract full-size images from gallery shortcode
			$og_images = self::gallery_images( $post, $og_images );
		}

		return $og_images;
	}

	/**
	 * Find gallery shortcodes in the post. Build Open Graph protocol image results.
	 *
	 * @since 1.1.9
	 * @param stdClass $post current post object
	 * @return array array of arrays containing Open Graph protocol image markup
	 */
	public static function gallery_images( $post, $existing_images = array() ) {
		global $shortcode_tags;

		if ( ! is_array( $existing_images ) )
			$existing_images = array();

		$og_images = array();

		if ( ! ( isset( $shortcode_tags['gallery'] ) && isset( $post->post_content ) && $post->post_content ) )
			return $og_images;

		$first_gallery = strpos( $post->post_content, '[gallery' );
		if ( $first_gallery === false )
			return $og_images;

		// use regex finder with only the gallery shortcode
		$old_shortcodes = $shortcode_tags;
		$shortcode_tags = array( 'gallery' => $shortcode_tags['gallery'] );
		// find all the gallery shortcodes in the post content
		preg_match_all( '/' . get_shortcode_regex() . '/s', $post->post_content, $galleries, PREG_SET_ORDER, $first_gallery );
		// reset
		$shortcode_tags = $old_shortcodes;

		foreach( $galleries as $gallery_shortcode ) {
			// request the full-sized image
			if ( empty( $gallery_shortcode[3] ) ) {
				$gallery_shortcode[3] = ' size="full"';
			} else {
				// break it down
				$parsed_attributes = shortcode_parse_atts( $gallery_shortcode[3] );
				// add new attribute or override existing
				$parsed_attributes['size'] = 'full';
				// build it up again
				$attr_str = '';
				foreach ( $parsed_attributes as $attribute => $value ) {
					$attr_str .= ' ' . $attribute . '="' . $value . '"';
				}
				unset( $parsed_attributes );
				if ( $attr_str )
					$gallery_shortcode[3] = $attr_str;
				unset( $attr_str );
			}

			// pass the shortcode through all filters and actors
			$gallery_html = do_shortcode_tag( $gallery_shortcode );
			if ( ! $gallery_html )
				continue;

			$gallery_html = strip_tags( $gallery_html, '<img>' );
			if ( ! $gallery_html )
				continue;

			$first_image = strpos( $gallery_html, '<img' );
			if ( $first_image === false )
				continue;

			preg_match_all( '/<img[^>]+>/i', $gallery_html, $images, PREG_PATTERN_ORDER, $first_image );
			unset( $gallery_html );

			if ( ! ( is_array( $images ) && is_array( $images[0] ) ) )
				continue;
			$images = $images[0];

			foreach ( $images as $image ) {
				preg_match_all( '/(src|width|height)="([^"]*)"/i', $image, $image_attributes, PREG_SET_ORDER );
				$og_image = array();
				foreach( $image_attributes as $parsed_attributes ) {
					if ( $parsed_attributes[1] === 'src' ) {
						$url = esc_url_raw( wp_specialchars_decode( $parsed_attributes[2] ), array( 'http', 'https' ) );
						if ( $url )
							$og_image['url'] = $url;
						unset( $url );
					} else if ( $parsed_attributes[1] === 'width' || $parsed_attributes[1] === 'height' ) {
						$pixels = absint( $parsed_attributes[2] );
						if ( $pixels > 0 )
							$og_image[ $parsed_attributes[1] ] = $pixels;
						unset( $pixels );
					}
				}
				if ( ! isset( $og_image['url'] ) || isset( $existing_images[ $og_image['url'] ] ) || ( isset( $og_image['width'] ) && $og_image['width'] < self::MIN_IMAGE_DIMENSION ) || ( isset( $og_image['height'] ) && $og_image['height'] < self::MIN_IMAGE_DIMENSION ) )
					continue;

				$existing_images[ $og_image['url'] ] = $og_image;
				unset( $og_image );
				if ( count( $existing_images ) === self::MAX_IMAGE_COUNT )
					return $existing_images;
			}
		}

		return $existing_images;
	}
}
?>
