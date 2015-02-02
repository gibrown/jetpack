<?php
/**
 * Class with methods to extract metadata from a post/page about videos, images, links, mentions embedded
 * in or attached to the post/page.
 *
 * @todo Additionally, have some filters on number of items in each field
 */

class Jetpack_Media_Meta_Extractor {

	// Some consts for what to extract
	const ALL = 255;
	const LINKS = 1;
	const MENTIONS = 2;
	const IMAGES = 4;
	const SHORTCODES = 8; // Only the keeper shortcodes below
	const EMBEDS = 16;
	const HASHTAGS = 32;

	// For these, we try to extract some data from the shortcode, rather than just recording its presence (which we do for all)
	// There should be a function get_{shortcode}_id( $atts ) or static method SomethingShortcode::get_{shortcode}_id( $atts ) for these.
	private static $KEEPER_SHORTCODES = array(
		'youtube',
		'vimeo',
		'hulu',
		'ted',
		'wpvideo',
		'audio',
	);

	/**
	 * Gets the specified media and meta info from the given post.
	 * NOTE: If you have the post's HTML content already and don't need image data, use extract_from_content() instead.
	 *
	 * @param $blog_id The ID of the blog
	 * @param $post_id The ID of the post
	 * @param $what_to_extract (int) A mask of things to extract, e.g. Jetpack_Media_Meta_Extractor::IMAGES | Jetpack_Media_Meta_Extractor::MENTIONS
	 * @returns a structure containing metadata about the embedded things, or empty array if nothing found, or WP_Error on error
	 */
	static public function extract( $blog_id, $post_id, $what_to_extract = self::ALL ) {

		// multisite?
		if ( function_exists( 'switch_to_blog') )
			switch_to_blog( $blog_id );

		$post = get_post( $post_id );
		$content = $post->post_title . "\n\n" . $post->post_content;
		$char_cnt = strlen( $content );

		//prevent running extraction on really huge amounts of content
		if ( $char_cnt > 100000 ) //about 20k English words
			$content = substr( $content, 0, 100000 );

		$extracted = array();

		// Get images first, we need the full post for that
		if ( self::IMAGES & $what_to_extract ) {
			$extracted = self::get_image_fields( $post );

			// Turn off images so we can safely call extract_from_content() below
			$what_to_extract = $what_to_extract - self::IMAGES;
		}

		if ( function_exists( 'switch_to_blog') )
			restore_current_blog();

		// All of the other things besides images can be extracted from just the content
		$extracted = self::extract_from_content( $content, $what_to_extract, $extracted );

		return $extracted;
	}

	/**
	 * Gets the specified meta info from the given post content.
	 * NOTE: If you want IMAGES, call extract( $blog_id, $post_id, ...) which will give you more/better image extraction
	 * This method will give you an error if you ask for IMAGES.
	 *
	 * @param $content The HTML post_content of a post
	 * @param $what_to_extract (int) A mask of things to extract, e.g. Jetpack_Media_Meta_Extractor::IMAGES | Jetpack_Media_Meta_Extractor::MENTIONS
	 * @param $already_extracted (array) Previously extracted things, e.g. images from extract(), which can be used for x-referencing here
	 * @returns a structure containing metadata about the embedded things, or empty array if nothing found, or WP_Error on error
	 */
	static public function extract_from_content( $content, $what_to_extract = self::ALL, $already_extracted = array() ) {
		$stripped_content = self::get_stripped_content( $content );

		// Maybe start with some previously extracted things (e.g. images from extract()
		$extracted = $already_extracted;

		// Embedded media objects will have already been converted to shortcodes by pre_kses hooks on save.

 		if ( self::IMAGES & $what_to_extract ) {
			$images = Jetpack_Media_Meta_Extractor::extract_images_from_content( $stripped_content, array() );
			$extracted = array_merge( $extracted, $images );
		}

		// ----------------------------------- MENTIONS ------------------------------

		if ( self::MENTIONS & $what_to_extract ) {
			if ( preg_match_all( '/(^|\s)@(\w+)/u', $stripped_content, $matches ) ) {
				$mentions = array_values( array_unique( $matches[2] ) ); //array_unique() retains the keys!
				$mentions = array_map( 'strtolower', $mentions );
				$extracted['mention'] = array( 'name' => $mentions );
				if ( !isset( $extracted['has'] ) )
					$extracted['has'] = array();
				$extracted['has']['mention'] = count( $mentions );
			}
		}

		// ----------------------------------- HASHTAGS ------------------------------
		/** Some hosts may not compile with --enable-unicode-properties and kick a warning:
		  *   Warning: preg_match_all() [function.preg-match-all]: Compilation failed: support for \P, \p, and \X has not been compiled
		  * Therefore, we only run this code block on wpcom, not in Jetpack.
		 */
		if ( ( defined( 'IS_WPCOM' ) && IS_WPCOM ) && ( self::HASHTAGS & $what_to_extract ) ) {
			//This regex does not exactly match Twitter's
			// if there are problems/complaints we should implement this:
			//   https://github.com/twitter/twitter-text-java/blob/master/src/com/twitter/Regex.java
			if ( preg_match_all( '/(?:^|\s)#(\w*\p{L}+\w*)/u', $stripped_content, $matches ) ) {
				$hashtags = array_values( array_unique( $matches[1] ) ); //array_unique() retains the keys!
				$hashtags = array_map( 'strtolower', $hashtags );
				$extracted['hashtag'] = array( 'name' => $hashtags );
				if ( !isset( $extracted['has'] ) )
					$extracted['has'] = array();
				$extracted['has']['hashtag'] = count( $hashtags );
			}
		}

		// ----------------------------------- SHORTCODES ------------------------------

		// Always look for shortcodes.
		// If we don't want them, we'll just remove them, so we don't grab them as links below
		$shortcode_pattern = '/' . get_shortcode_regex() . '/s';
 		if ( preg_match_all( $shortcode_pattern, $content, $matches ) ) {

			$shortcode_total_count = 0;
			$shortcode_type_counts = array();
			$shortcode_types = array();
			$shortcode_details = array();

			if ( self::SHORTCODES & $what_to_extract ) {

				foreach( $matches[2] as $key => $shortcode ) {
					//Elasticsearch (and probably other things) doesn't deal well with some chars as key names
					$shortcode_name = preg_replace( '/[.,*"\'\/\\\\#+ ]/', '_', $shortcode );

					$attr = shortcode_parse_atts( $matches[3][ $key ] );

					$shortcode_total_count++;
					if ( ! isset( $shortcode_type_counts[$shortcode_name] ) )
						$shortcode_type_counts[$shortcode_name] = 0;
					$shortcode_type_counts[$shortcode_name]++;

					// Store (uniquely) presence of all shortcode regardless of whether it's a keeper (for those, get ID below)
					// @todo Store number of occurrences?
					if ( ! in_array( $shortcode_name, $shortcode_types ) )
						$shortcode_types[] = $shortcode_name;

					// For keeper shortcodes, also store the id/url of the object (e.g. youtube video, TED talk, etc.)
					if ( in_array( $shortcode, self::$KEEPER_SHORTCODES ) ) {
						unset( $id ); // Clear shortcode ID data left from the last shortcode
						// We'll try to get the salient ID from the function jetpack_shortcode_get_xyz_id()
						// If the shortcode is a class, we'll call XyzShortcode::get_xyz_id()
						$shortcode_get_id_func = "jetpack_shortcode_get_{$shortcode}_id";
						$shortcode_class_name = ucfirst( $shortcode ) . 'Shortcode';
						$shortcode_get_id_method = "get_{$shortcode}_id";
						if ( function_exists( $shortcode_get_id_func ) ) {
							$id = call_user_func( $shortcode_get_id_func, $attr );
						} else if ( method_exists( $shortcode_class_name, $shortcode_get_id_method ) ) {
							$id = call_user_func( array( $shortcode_class_name, $shortcode_get_id_method ), $attr );
						}
						if ( ! empty( $id )
							&& ( ! isset( $shortcode_details[$shortcode_name] ) || ! in_array( $id, $shortcode_details[$shortcode_name] ) ) )
							$shortcode_details[$shortcode_name][] = $id;
					}
				}

				if ( $shortcode_total_count > 0 ) {
					// Add the shortcode info to the $extracted array
					if ( !isset( $extracted['has'] ) )
						$extracted['has'] = array();
					$extracted['has']['shortcode'] = $shortcode_total_count;
					$extracted['shortcode'] = array();
					foreach ( $shortcode_type_counts as $type => $count )
						$extracted['shortcode'][$type] = array( 'count' => $count );
					if ( ! empty( $shortcode_types ) )
						$extracted['shortcode_types'] = $shortcode_types;
					foreach ( $shortcode_details as $type => $id )
						$extracted['shortcode'][$type]['id'] = $id;
				}
			}

			// Remove the shortcodes form our copy of $content, so we don't count links in them as links below.
			$content = preg_replace( $shortcode_pattern, ' ', $content );
		}

		// ----------------------------------- LINKS ------------------------------

		if ( self::LINKS & $what_to_extract ) {

			// To hold the extracted stuff we find
			$links = array();

			// @todo Get the text inside the links?

			// Grab any links, whether in <a href="..." or not, but subtract those from shortcodes and images
			// (we treat embed links as just another link)
			if ( preg_match_all( '#(?:^|\s|"|\')(https?://([^\s()<>]+(?:\([\w\d]+\)|([^[:punct:]\s]|/))))#', $content, $matches ) ) {

				foreach ( $matches[1] as $link_raw ) {
					$url = parse_url( $link_raw );

					// Data URI links
					if ( isset( $url['scheme'] ) && 'data' === $url['scheme'] )
						continue;

					// Remove large (and likely invalid) links
					if ( 4096 < strlen( $link_raw ) )
						continue;

					// Build a simple form of the URL so we can compare it to ones we found in IMAGES or SHORTCODES and exclude those
					$simple_url = $url['scheme'] . '://' . $url['host'] . ( ! empty( $url['path'] ) ? $url['path'] : '' );
					if ( isset( $extracted['image']['url'] ) ) {
						if ( in_array( $simple_url, (array) $extracted['image']['url'] ) )
							continue;
					}

					list( $proto, $link_all_but_proto ) = explode( '://', $link_raw );

					// Build a reversed hostname
					$host_parts = array_reverse( explode( '.', $url['host'] ) );
					$host_reversed = '';
					foreach ( $host_parts as $part ) {
						$host_reversed .= ( ! empty( $host_reversed ) ? '.' : '' ) . $part;
					}

					$link_analyzed = '';
					if ( !empty( $url['path'] ) ) {
						// The whole path (no query args or fragments)
						$path = substr( $url['path'], 1 ); // strip the leading '/'
						$link_analyzed .= ( ! empty( $link_analyzed ) ? ' ' : '' ) . $path;

						// The path split by /
						$path_split = explode( '/', $path );
						if ( count( $path_split ) > 1 ) {
							$link_analyzed .= ' ' . implode( ' ', $path_split );
						}

						// The fragment
						if ( ! empty( $url['fragment'] ) )
							$link_analyzed .= ( ! empty( $link_analyzed ) ? ' ' : '' ) . $url['fragment'];
					}

					// @todo Check unique before adding
					$links[] = array(
						'url' => $link_all_but_proto,
						'host_reversed' => $host_reversed,
						'host' => $url['host'],
					);
				}

			}

			$link_count = count( $links );
			if ( $link_count ) {
				$extracted[ 'link' ] = $links;
				if ( !isset( $extracted['has'] ) )
					$extracted['has'] = array();
				$extracted['has']['link'] = $link_count;
			}
		}

		// ----------------------------------- EMBEDS ------------------------------

		//Embeds are just individual links on their own line
		if ( self::EMBEDS & $what_to_extract ) {

			if ( !function_exists( '_wp_oembed_get_object' ) )
				include( ABSPATH . WPINC . '/class-oembed.php' );

			// get an oembed object
			$oembed = _wp_oembed_get_object();

			// Grab any links on their own lines that may be embeds
			if ( preg_match_all( '|^\s*(https?://[^\s"]+)\s*$|im', $content, $matches ) ) {

				// To hold the extracted stuff we find
				$embeds = array();

				foreach ( $matches[1] as $link_raw ) {
					$url = parse_url( $link_raw );

					list( $proto, $link_all_but_proto ) = explode( '://', $link_raw );

					// Check whether this "link" is really an embed.
					foreach ( $oembed->providers as $matchmask => $data ) {
						list( $providerurl, $regex ) = $data;

						// Turn the asterisk-type provider URLs into regex
						if ( !$regex ) {
							$matchmask = '#' . str_replace( '___wildcard___', '(.+)', preg_quote( str_replace( '*', '___wildcard___', $matchmask ), '#' ) ) . '#i';
							$matchmask = preg_replace( '|^#http\\\://|', '#https?\://', $matchmask );
						}

						if ( preg_match( $matchmask, $link_raw ) ) {
							$provider = str_replace( '{format}', 'json', $providerurl ); // JSON is easier to deal with than XML
							$embeds[] = $link_all_but_proto; // @todo Check unique before adding

							// @todo Try to get ID's for the ones we care about (shortcode_keepers)
							break;
						}
					}
				}

				if ( ! empty( $embeds ) ) {
					if ( !isset( $extracted['has'] ) )
						$extracted['has'] = array();
					$extracted['has']['embed'] = count( $embeds );
					$extracted['embed'] = array( 'url' => array() );
					foreach ( $embeds as $e )
						$extracted['embed']['url'][] = $e;
				}
			}
		}

		return $extracted;
	}

	/**
	 * @param $post A post object
	 * @param $args (array) Optional args, see defaults list for details
	 * @returns array Returns an array of all images meeting the specified criteria in $args
	 *
	 * Uses Jetpack Post Images
	 */
	private static function get_image_fields( $post, $args = array() ) {

		$defaults = array(
			'width'  => 200, // Required minimum width (if possible to determine)
			'height' => 200, // Required minimum height (if possible to determine)
		);

		$args = wp_parse_args( $args, $defaults );

		$image_list = array();
		$image_booleans = array();
		$image_booleans['gallery'] = 0;

		$from_featured_image = Jetpack_PostImages::from_thumbnail( $post->ID, $args['width'], $args['height'] );
		if ( ! empty( $from_featured_image ) ) {
			$srcs       = wp_list_pluck( $from_featured_image, 'src' );
			$image_list = array_merge( $image_list, array_combine( $srcs, $from_featured_image ) );
		}

		$from_slideshow = Jetpack_PostImages::from_slideshow( $post->ID, $args['width'], $args['height'] );
		if ( ! empty( $from_slideshow ) ) {
			$srcs       = wp_list_pluck( $from_slideshow, 'src' );
			$image_list = array_merge( $image_list, array_combine( $srcs, $from_slideshow ) );
		}

		$from_gallery = Jetpack_PostImages::from_gallery( $post->ID );
		if ( !empty( $from_gallery ) ) {
			$srcs       = wp_list_pluck( $from_gallery, 'src' );
			$image_list = array_merge( $image_list, array_combine( $srcs, $from_gallery ) );
			$image_booleans['gallery']++; // @todo This count isn't correct, will only every count 1
		}

		$from_attachment = Jetpack_PostImages::from_attachment( $post->ID );
		if ( !empty( $from_attachment ) ) {
			$srcs       = wp_list_pluck( $from_attachment, 'src' );
			$image_list = array_merge( $image_list, array_combine( $srcs, $from_attachment ) );
		}

		// @todo Can we check width/height of these efficiently?  Could maybe use query args at least, before we strip them out
		$image_list = Jetpack_Media_Meta_Extractor::get_images_from_html( $post->post_content, $image_list );

		return Jetpack_Media_Meta_Extractor::build_image_struct( $image_list );
	}

	public static function extract_images_from_content( $content, $image_list ) {
		$image_list = Jetpack_Media_Meta_Extractor::get_images_from_html( $content, $image_list );
		return Jetpack_Media_Meta_Extractor::build_image_struct( $image_list );
	}

	public static function build_image_struct( $image_list ) {
		if ( ! empty( $image_list ) ) {
			$retval = array( 'image' => array() );

			// Analyze various properties of the image using GD library and more
			$image_list = Jetpack_Media_Meta_Extractor::get_image_visual_properties( $image_list );

			// It was useful to index the list using image URLs to avoid duplicities,
			// but now we could rather use integer keys
			$image_list = array_values( $image_list );

			foreach ( $image_list as $index => $img_properties ) {
				foreach( $img_properties as $property => $value ) {
					$retval['image'][ $index ][ $property ] = $value;
				}
			}
			$image_booleans['image'] = count( $retval['image'] );
			if ( ! empty( $image_booleans ) )
				$retval['has'] = $image_booleans;

			return $retval;
		} else {
			return array();
		}
	}

	/**
	 * Iterates over images extracted from various sources and adds more specific info
	 * about image content (colors, transparency etc.)
	 *
	 * @param  [array] $image_list List of images, keys correspond to image srces,
	 *                             values represent additional info associated with img
	 * @return [array] The same $image_list enriched with visual properties
	 */
	public static function get_image_visual_properties( $image_list ) {
		if ( ! empty( $image_list ) && is_array( $image_list ) ) {
			foreach( $image_list as $src => $image_info ) {
				$image_list[ $src ] = self::get_single_image_visual_properties( $image_info );
			}
		}
		return $image_list;
	}

	/**
	 * Uses the GD library and Jetpack_Color class to determine various visual
	 * properties of the image
	 *
	 * @param  [array] $image_info All information we know about the image up to this point
	 * @return [array]             The same information enriched with visual properties
	 */
	public static function get_single_image_visual_properties( $image_info ) {
		if ( empty( $image_info['src'] ) ) {
			return $image_info;
		}

		$image_url = $image_info['src'];

		if ( ( $src = parse_url( $image_url ) ) && isset( $src['scheme'], $src['host'], $src['path'] ) ) {
			$clean_image_url = $src['scheme'] . '://' . $src['host'] . $src['path'];
		} elseif ( $length = strpos( $image_url, '?' ) ) {
			$clean_image_url = substr( $image_url, 0, $length );
		} else {
			$clean_image_url = $image_url;
		}

		$image_extension = pathinfo( $clean_image_url, PATHINFO_EXTENSION );

		switch ( $image_extension ) {
			case 'gif':
				$image_obj = imagecreatefromgif( $clean_image_url );
				break;
			case 'png' :
				$image_obj = imagecreatefrompng( $clean_image_url );
				break;
			case 'jpg' :
			case 'jpeg' :
				$has_transparency = false;
				$image_obj        = imagecreatefromjpeg( $clean_image_url );
				break;
			default:
				return $image_info;
		}

		$color_info = self::_image_get_color_information( $image_obj );

		$image_info = array_merge( $image_info, array(
			'dimensions'       => $color_info['dimensions'],
			'colors'           => $color_info['colors'],
			'is_grayscale'     => $color_info['is_grayscale'],
			'has_transparency' => empty( $has_transparency ) ? self::_image_has_transparency( $image_obj ) : $has_transparency,
		) );

		return $image_info;
	}

	/**
	 * Color quantization routine. This method also checks whether the whole image is grayscale or not.
	 *
	 * @param  [resource: gd] $image_obj
	 * @param  integer        $colors              How many colors should be returned. If image contains fewer colors,
	 *                                             fewer are returned.
	 * @param  integer        $grayscale_tolerance How much color deviation do we still consider to be grayscale?
	 * @return [array]                             Array containing color buckets and grayscale boolean
	 */
	private static function _image_get_color_information( $image_obj, $num_colors = 15, $grayscale_tolerance = 0 ) {
		$results = array(
			'dimensions'   => array(),
			'colors'       => array(),
			'is_grayscale' => null,
		);

		if ( ! is_resource( $image_obj ) || get_resource_type( $image_obj ) !== 'gd' ) {
			return $results;
		}

		$width  = (int) imagesx( $image_obj );
		$height = (int) imagesy( $image_obj );

		if ( $width * $height === 0 ) {
			return null; // We can't figure out the size, too bad
		}

		$results['dimensions'] = array(
			'width'  => $width,
			'height' => $height,
			'area'   => $width * $height,
		);

		if ( ! class_exists( 'Jetpack_Color' ) ) {
			jetpack_require_lib( 'class.color' ); // We need Jetpack_Color, we need it bad
		}
		if ( ! class_exists( 'ColorThief' ) ) {
			jetpack_require_lib( 'ColorThief' ); // ColorThief is lightweight advanced MMCQ library that gives
			                                     // best results in an area of color quantization
		}

		// The code below grabs colors of individual pixels. We don't want to check every pixel,
		// but ideally we'd like to check the same amount of pixels every time regardless if the
		// image is small or large. This leads to a bit more precise quantization results for
		// small images compared to the large ones.
		$max_tests     = 1500;
		$skip_constant = ceil( sqrt( ( $width * $height ) / $max_tests ) );
		$palette       = ColorThief::getPalette( $image_obj, $num_colors, $skip_constant );

		// Until disproved, we assume this is a grayscale image
		$is_grayscale  = true;

		foreach( $palette as $info ) {
			// If the dominance of this color === 0, then we don't consider this color
			// to be present in the image. This is important as ColorThief will make colors up
			// when there are too few distincts colors in it to begin with.
			if ( empty( $info['dominance'] ) ) {
				continue;
			}

			// Sanitize input coming from ColorThief
			$color = array(
				max( 0, min( 255, $info['color'][0] ) ),
				max( 0, min( 255, $info['color'][1] ) ),
				max( 0, min( 255, $info['color'][2] ) ),
			);
			$color = new Jetpack_Color( array( $color[0], $color[1], $color[2] ), 'rgb' );

			$hsv_color              = $color->toHsvInt();
			$hsv_color['dominance'] = $info['dominance'];

			// Uncomment to generate a basic color graph related to the processed image
			// echo sprintf( '<span style="background: #%s; display: block; width: %dpx;">%s</span>', $color->toHex(), $info['dominance'] * 5, $color->toHex() );

			// One of the dominant colors is not grayscale? Well, in that case we're sure this
			// is not a grayscale image
			if ( ! $color->isGrayscale( $grayscale_tolerance ) ) {
				$is_grayscale = false;
			}

			$results['colors'][] = $hsv_color;
		}
		$results['is_grayscale'] = $is_grayscale;

		return $results;
	}

	/**
	 * Checks whether the image contains transparent areas or not
	 *
	 * The result is probablistic in order to avoid checking huge images pixel by pixel,
	 * but it works for most real world use cases. We're testing all pixels on
	 * the border of the image and also all pixels on one vertical and horizontal line
	 * within the image.
	 *
	 * @param  [resource: gd] $image_obj
	 * @return [bool]         True if the image is grayscale, false if not, null if unable to decide
	 */
	private static function _image_has_transparency( $image_obj ) {
		if ( ! is_resource( $image_obj ) || get_resource_type( $image_obj ) !== 'gd' ) {
			return null;
		}

		$width  = (int) imagesx( $image_obj );
		$height = (int) imagesy( $image_obj );

		if ( $width * $height === 0 ) {
			return null; // We can't figure out the size, too bad
		}

		$alpha = 0;

		// Check out horizontal borders and one horizontal line inside the image
		for ( $i = 0; $i <= $width; $i++ ) {
			$alpha += ( imagecolorat( $image_obj, $i, 0 ) & 0x7F000000 ) >> 24;
			$alpha += ( imagecolorat( $image_obj, $i, intval( $height / 2 ) ) & 0x7F000000 ) >> 24;
			$alpha += ( imagecolorat( $image_obj, $i, $height ) & 0x7F000000 ) >> 24;
		}

		// Check out vertical borders and one vertical line inside the image
		for ( $i = 0; $i <= $height; $i++ ) {
			$alpha += ( imagecolorat( $image_obj, 0, $i ) & 0x7F000000 ) >> 24;
			$alpha += ( imagecolorat( $image_obj, intval( $width / 2 ), $i ) & 0x7F000000 ) >> 24;
			$alpha += ( imagecolorat( $image_obj, $width, $i ) & 0x7F000000 ) >> 24;
		}

		// If alpha > 0, it means that at least one pixel from the tested set is at least a little bit transparent
		return $alpha > 0 ? true : false;
	}

	/**
	 *
	 * @param string $html Some markup, possibly containing image tags
	 * @param array $images_already_extracted (just an array of image URLs without query strings, no special structure), used for de-duplication
	 * @return array Image URLs extracted from the HTML, stripped of query params and de-duped
	 */
	public static function get_images_from_html( $html, $image_list ) {
		$from_html = Jetpack_PostImages::from_html( $html );
		if ( !empty( $from_html ) ) {
			$srcs = wp_list_pluck( $from_html, 'src' );
			foreach( $srcs as $image_url ) {
				if ( ( $src = parse_url( $image_url ) ) && isset( $src['scheme'], $src['host'], $src['path'] ) ) {
					// Rebuild the URL without the query string
					$queryless = $src['scheme'] . '://' . $src['host'] . $src['path'];
				} elseif ( $length = strpos( $image_url, '?' ) ) {
					// If parse_url() didn't work, strip off the query string the old fashioned way
					$queryless = substr( $image_url, 0, $length );
				} else {
					// Failing that, there was no spoon! Err ... query string!
					$queryless = $image_url;
				}

				// Discard URLs that are longer then 4KB, these are likely data URIs or malformed HTML.
				if ( 4096 < strlen( $queryless ) ) {
					continue;
				}

				if ( ! array_key_exists( $queryless, $image_list ) ) {
					$image_list[ $queryless ] = array(
						'from' => 'html',
						'src'  => $queryless,
					);
				}
			}
		}
		return $image_list;
	}

	private static function get_stripped_content( $content ) {
		$clean_content = strip_tags( $content );
		$clean_content = html_entity_decode( $clean_content );
		//completely strip shortcodes and any content they enclose
		$clean_content = strip_shortcodes( $clean_content );
		return $clean_content;
	}
}
