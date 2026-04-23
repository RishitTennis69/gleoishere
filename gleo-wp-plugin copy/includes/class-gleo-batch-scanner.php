<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Gleo_Batch_Scanner {

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		$namespace = 'gleo/v1';

		register_rest_route( $namespace, '/scan/start', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'start_scan' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		) );

		register_rest_route( $namespace, '/scan/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_webhook' ),
			'permission_callback' => '__return_true', // webhook from node
		) );

		register_rest_route( $namespace, '/scan/status', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'get_status' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	public function start_scan( $request ) {
		$params = $request->get_json_params();
		$post_ids = isset( $params['post_ids'] ) ? $params['post_ids'] : array();

		if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
			return new WP_Error( 'no_posts', 'No posts selected.', array( 'status' => 400 ) );
		}

		// get selected published posts
		$posts = get_posts( array(
			'post__in'    => $post_ids,
			'numberposts' => -1,
			'post_status' => 'publish',
		) );

		if ( empty( $posts ) ) {
			return new WP_Error( 'no_posts', 'No valid published posts found.', array( 'status' => 404 ) );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'gleo_scans';

		$payload = array(
			'batch_id' => uniqid('batch_'),
			'webhook'  => rest_url( 'gleo/v1/scan/webhook' ),
			'site_url' => get_site_url(),
			'posts'    => array(),
		);

		$api_client = new Gleo_API_Client();

		foreach ( $posts as $post ) {
			// Mark as pending WITHOUT wiping scan_result — this keeps JSON-LD
			// schema injectable into the live HTML fetch that follows.
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$table_name} (post_id, scan_status)
				 VALUES (%d, 'pending')
				 ON DUPLICATE KEY UPDATE scan_status = 'pending'",
				$post->ID
			) );

			// Fetch the live rendered HTML of the post (schema is now present in <head>)
			$permalink = get_permalink( $post->ID );
			$response  = null;

			if ( $permalink ) {
				$response = wp_remote_get( $permalink, array( 'timeout' => 15 ) );
			}

			$html_content = '';
			if ( $response && ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
				$html_content = wp_remote_retrieve_body( $response );
			} else {
				// Fallback to basic content if the live fetch fails
				$html_content = $api_client->sanitize_content( $post->post_content );

				// Inject schema proxy so cheerio can still identify it
				$global_override = get_option( 'gleo_override_schema', false );
				$post_override   = get_post_meta( $post->ID, '_gleo_schema_override', true );
				if ( $global_override || $post_override ) {
					$html_content .= "\n<script type=\"application/ld+json\"></script>";
				}
			}

			$payload['posts'][] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'content' => $html_content,
			);
		}

		// Send request to new endpoint
		$response = $api_client->send_request( '/v1/analyze/start', $payload );

		if ( is_wp_error( $response ) ) {
            // Cleanup database immediately to prevent indefinite polling locking up the UI
            $wpdb->query( "DELETE FROM $table_name WHERE scan_status = 'pending'" );
			return $response;
		}

		return rest_ensure_response( array( 'success' => true, 'message' => 'Scan started.', 'batch_id' => $payload['batch_id'] ) );
	}

	public function handle_webhook( $request ) {
		// Verify signature if needed, here we'll just check if basic is valid
		$params = $request->get_json_params();

        // Node sends back an array of post results
        if ( isset( $params['results'] ) && is_array( $params['results'] ) ) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'gleo_scans';

            foreach ( $params['results'] as $result ) {
                $wpdb->update(
                    $table_name,
                    array(
                        'scan_status' => 'completed',
                        'scan_result' => wp_json_encode( $result['data'] ),
                    ),
                    array( 'post_id' => $result['id'] ),
                    array( '%s', '%s' ),
                    array( '%d' )
                );

                // Log to history for the analytics graph
                $geo_score  = isset( $result['data']['geo_score'] ) ? $result['data']['geo_score'] : 0;
                $brand_rate = isset( $result['data']['brand_inclusion_rate'] ) ? $result['data']['brand_inclusion_rate'] : 0;
                Gleo_Analytics::log_scan( $result['id'], $geo_score, $brand_rate );
            }
        }

		return rest_ensure_response( array( 'success' => true ) );
	}

	public function get_status( $request ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'gleo_scans';

        // Auto-heal: If a batch has been stuck in 'pending' for over 1 minute, clear it out.
        $wpdb->query( "DELETE FROM $table_name WHERE scan_status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 1 MINUTE)" );

		$pending_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE scan_status = 'pending'" );
		$completed_count = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE scan_status = 'completed'" );

		$total = $pending_count + $completed_count;

		$results = array();
		if ( $pending_count == 0 && $completed_count > 0 ) {
			$results_rows = $wpdb->get_results( "SELECT post_id, scan_result FROM $table_name WHERE scan_status = 'completed'" );
			foreach ( $results_rows as $row ) {
				$results[] = array(
					'post_id' => $row->post_id,
					'result'  => json_decode( $row->scan_result, true ),
				);
			}
		}

		$progress = $total > 0 ? ( $completed_count / $total ) * 100 : 0;

		return rest_ensure_response( array(
			'is_scanning' => (int) $pending_count > 0,
			'progress'    => $progress,
			'total'       => $total,
			'completed'   => $completed_count,
			'results'     => $results,
		) );
	}
}

new Gleo_Batch_Scanner();
