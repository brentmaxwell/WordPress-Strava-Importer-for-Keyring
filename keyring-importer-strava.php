<?php
/*
Plugin Name: theBrent - Strava Keyring Importer
Description: Strava Keyring Importer
Author: Brent Maxwell
Version: 0.1
*/

// This is a horrible hack, because WordPress doesn't support dependencies/load-order.
// We wrap our entire class definition in a function, and then only call that on a hook
// where we know that the class we're extending is available. *hangs head in shame*

function Keyring_Strava_Importer() {
	

class Keyring_Strava_Importer extends Keyring_Importer_Base {
	const SLUG              = 'strava';    
	const LABEL             = 'Strava';    
	const KEYRING_SERVICE   = 'Keyring_Service_Strava';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?

	var $auto_import = false;

	function __construct() {
		parent::__construct();
		add_action( 'keyring_importer_strava_custom_options', array( $this, 'custom_options' ) );
	}

	function custom_options() {
		?>
		<?php
	}

 	function handle_request_options() {
		// Validate options and store them so they can be used in auto-imports
		if ( empty( $_POST['category'] ) || !ctype_digit( $_POST['category'] ) )
			$this->error( __( "Make sure you select a valid category to import your checkins into." ) );

		if ( empty( $_POST['author'] ) || !ctype_digit( $_POST['author'] ) )
			$this->error( __( "You must select an author to assign to all checkins." ) );

		if ( isset( $_POST['auto_import'] ) )
			$_POST['auto_import'] = true;
		else
			$_POST['auto_import'] = false;

		// If there were errors, output them, otherwise store options and start importing
		if ( count( $this->errors ) ) {
			$this->step = 'greet';
		} else {
			$this->set_option( array(
				'category'        => (int) $_POST['category'],
				'tags'            => explode( ',', $_POST['tags'] ),
				'author'          => (int) $_POST['author'],
				'auto_import'     => (bool) $_POST['auto_import'],
				'user_id'         => $this->service->get_token()->get_meta( 'user_id' ),
			) );

			$this->step = 'import';
		}
	}

	function build_request_url() {
		// Base request URL
		$url = "https://www.strava.com/api/v3/athlete/activities?";
		$params = array();
		$url = $url . http_build_query( $params );


		if ( $this->auto_import ) {
			// Locate our most recently imported Tweet, and get ones since then
			$latest = get_posts( array(
				'numberposts' => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
				'tax_query'   => array( array(
					'taxonomy' => 'keyring_services',
					'field'    => 'slug',
					'terms'    => array( $this->taxonomy->slug ),
					'operator' => 'IN',
				) ),
			) );

			// If we have already imported some, then start since the most recent
			if ( $latest ) {
				$max = new DateTime(strtotime(get_post_meta( $latest[0]->ID, 'strava_start_date', true )));
				$max =  $max->sub(new DateInterval('P30D'));
				$url = add_query_arg( 'after', $max->getTimestamp(), $url );
			}
		} else {
			// Handle page offsets (only for non-auto-import requests)
			$url = add_query_arg( 'page', $this->get_option( 'page', 1 ), $url );
			$url = add_query_arg( 'per_page', 100, $url);
		}

		return $url;
	}

	function extract_posts_from_data( $raw ) {
		global $wpdb;

		$importdata = $raw;

		if ( null === $importdata ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-strava-importer-failed-download', __( 'Failed to download your activities from Strava. Please wait a few minutes and try again.', 'keyring' ) );
		}

		// Check for API overage/errors
		if ( !empty( $importdata->error ) ) {
			$this->finished = true;
			return new Keyring_Error( 'keyring-strava-importer-throttled', __( 'You have made too many requests to Strava and have been temporarily blocked. Please try again in 1 hour (duplicate activities will be skipped).', 'keyring' ) );
		}

		// Make sure we have some tweets to parse
		if ( !is_array( $importdata ) || !count( $importdata ) ) {
			$this->finished = true;
			return;
		}

		// Get the total number of tweets we're importing
		$this->set_option( 'total', count($importdata) );

		// Parse/convert everything to WP post structs
		foreach ( $importdata as $post ) {

			// Post title can be empty for Asides, but it makes them easier to manage if they have *something*
			$post_title = strip_tags( $post->name );

			// Parse/adjust dates
			$start_date = strtotime( $post->start_date );
			$end_date = $start_date + $post->elapsed_time;
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $end_date );
			$post_date     = get_date_from_gmt( $post_date_gmt );

			// Apply selected category
			$post_category = array( $this->get_option( 'category' ) );

			// Clean up content a bit
			$post_content = $post->description;
			$post_content = esc_sql( html_entity_decode( trim( $post_content ) ) );

			// Any hashtags used in a tweet will be applied to the Post as tags in WP
			$tags = $this->get_option( 'tags' );

			// Add HTML links to URLs, usernames and hashtags
			$post_content = make_clickable( esc_html( $post_content ) );

			if ( !empty( $post->start_latlng ) )
				$geo = array(
					'lat' => $post->start_latlng[0],
					'long' => $post->start_latlng[1]
				);
			else
				$geo = array();

			$user                    = $post->athlete->id;
			$strava_id               = $post->id;
			$strava_permalink        = "https://www.strava.com/activities/{$strava_id}";
			$strava_distance         = $post->distance;
			$strava_start_date       = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $start_date ) );
			$strava_type             = $post->type;
			$strava_location         = $post->location_city . ", " . $post->location_state . ", ". $post->location_country;
			$strava_map_polyline     = $post->map->polyline;
			$strava_map_summary_polyline     = $post->map->summary_polyline;
			$post_author             = $this->get_option( 'author' );
			$post_status             = 'publish';
			$strava_raw             = $post;

			// Build the post array, and hang onto it along with the others
			$this->posts[] = compact(
				'post_author',
				'post_date',
				'post_date_gmt',
				'post_content',
				'post_title',
				'post_status',
				'post_category',
				'tags',
				'strava_id',
				'strava_permalink',
				'strava_distance',
				'strava_start_date',
				'strava_type',
				'strava_location',
				'strava_map_polyline',
				'strava_map_summary_polyline',
				'geo',
				'strava_raw'
			);
		}
	}

	function insert_posts() {
		global $wpdb;
		$imported = 0;
		$skipped  = 0;
		foreach ( $this->posts as $post ) {
			extract( $post );
			$post_id = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'strava_id' AND meta_value = %s", $strava_id ) ); 
			if ($post_id) {
				$post['ID'] = $post_id;
				wp_update_post($post);
				$skipped++;
			} else {
				$post_id = wp_insert_post( $post );
				$imported++;
			}
			if ( is_wp_error( $post_id ) )
				return $post_id;

			if ( !$post_id )
				continue;

			// Mark it as an aside
			set_post_format( $post_id, 'status' );

			// Track which Keyring service was used
			wp_set_object_terms( $post_id, self::LABEL, 'keyring_services' );

			delete_post_meta($post_id,'strava_id');
			delete_post_meta($post_id,'strava_permalink');
			delete_post_meta($post_id,'strava_distance');
			delete_post_meta($post_id,'strava_start_date');
			delete_post_meta($post_id,'strava_type');
			delete_post_meta($post_id,'strava_location');
			delete_post_meta($post_id,'strava_distance');
			delete_post_meta($post_id,'strava_map_polyline');
			delete_post_meta($post_id,'strava_map_summary_polyline');
			delete_post_meta( $post_id, 'geo_latitude');
			delete_post_meta( $post_id, 'geo_longitude');
			delete_post_meta( $post_id, 'geo_public');
			delete_post_meta( $post_id, 'raw_import_data');
			
			add_post_meta( $post_id, 'strava_id', $strava_id );
			add_post_meta( $post_id, 'strava_permalink', $strava_permalink );
			if ( !empty( $strava_distance ) )
				add_post_meta( $post_id, 'strava_distance', $strava_distance );
			if ( !empty( $strava_start_date ) )
				add_post_meta( $post_id, 'strava_start_date', $strava_start_date );
			if ( !empty( $strava_type ) )
				add_post_meta( $post_id, 'strava_type', $strava_type);
			if ( !empty( $strava_location ) )
				add_post_meta( $post_id, 'strava_location', $strava_location );
			if ( !empty( $strava_distance ) )
				add_post_meta( $post_id, 'strava_distance', $strava_distance );
			if ( !empty( $strava_map_polyline ) )
				add_post_meta( $post_id, 'strava_map_polyline', $strava_map_polyline );
			if ( !empty( $strava_map_summary_polyline ) )
				add_post_meta( $post_id, 'strava_map_summary_polyline', $strava_map_summary_polyline );

			// Update Category and Tags
			wp_set_post_categories( $post_id, $post_category );
			if ( count( $tags ) )
				wp_set_post_terms( $post_id, implode( ',', $tags ) );

			// Store geodata if it's available
			if ( !empty( $geo ) ) {
				add_post_meta( $post_id, 'geo_latitude', $geo['lat'] );
				add_post_meta( $post_id, 'geo_longitude', $geo['long'] );
				add_post_meta( $post_id, 'geo_public', 1 );
			}

			add_post_meta( $post_id, 'raw_import_data', json_encode( $strava_raw ) );

			

			do_action( 'keyring_post_imported', $post_id, static::SLUG, $post );
		}
		$this->posts = array();

		// Return, so that the handler can output info (or update DB, or whatever)
		return array( 'imported' => $imported, 'skipped' => $skipped );
	}
}

} 


add_action( 'init', function() {
	Keyring_Strava_Importer(); // Load the class code from above
	keyring_register_importer(
		'strava',
		'Keyring_Strava_Importer',
		plugin_basename( __FILE__ ),
		__( 'Import all of your activities from Strava as Posts (marked as "status") in WordPress.', 'keyring' )
	);
} );

include('filter.php');