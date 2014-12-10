<?php

if ( ! defined( 'WP_CLI' ) ) return;

/**
 * Programmatically test performance of various permalinks.
 */
class PerfPress_Command extends WP_CLI_Command {

	protected $permastructs = array(
		'Default'        => '',
		'Day and Name'   => '/%year%/%monthnum%/%day%/%postname%/',
		'Month and Name' => '/%year%/%monthnum%/%postname%/',
		'Numeric'        => '/archives/%post_id%',
		'Post Name'      => '/%postname%/',
	);

	/**
	 * Run the tests
	 *
	 * ## OPTIONS
	 *
	 * [--posts=<count>]
	 * : Number of posts to test with in the database
	 *
	 * ## EXAMPLES
	 *
	 *     wp perfpress parse_request
	 *     wp perfpress parse_request --posts=1000
	 *
	 * @synopsis [--posts=<count>]
	 * @alias pr
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function parse_request( $args, $assoc_args ) {
		$post_count = isset( $assoc_args['posts'] ) ? (int) $assoc_args['posts'] : 1000;

		$this->setup( $post_count );

		// Get a post at random
		$posts = get_posts( array( 'posts_per_page' => 1, 'orderby' => 'rand' ) );
		$post = $posts[0];

		$results = array();
		foreach( $this->permastructs as $label => $permastruct ) {
			$this->set_permalinks( $permastruct );

			// Set up the path information
			$url_info = parse_url( get_the_permalink( $post ) );
			$_SERVER['PATH_INFO'] = $url_info['path'];

			$time = $this->test_structure();

			$results[ $label ] = $time;
		}

		// Print a headline
		WP_CLI::line( sprintf( 'Performance test with %s posts:', $post_count ) );
		WP_CLI::line();
		WP_CLI::line( "Permastruct:\tLoad time" );

		$this->print_results( $results );
	}

	/**
	 * Output the test results
	 *
	 * @param array $results
	 */
	protected function print_results( $results ) {
		$best = '';
		$bestval = 0;
		$worst = '';
		$worstval = 0;
		foreach( $results as $index => $value ) {
			$value = (double) $value;

			if ( 0 === $bestval || $value < $bestval ) {
				$best = $index;
				$bestval = $value;
				continue;
			}

			if ( 0 === $worstval || $value > $worstval ) {
				$worst = $index;
				$worstval = $value;
			}
		}

		foreach( $results as $index => $value ) {
			// Round the value to 6 decimal places
			$value = (double) $value;
			$value = round( $value, 6 );

			$line = sprintf( "%s:\t%ss", $index, $value );

			if ( $best == $index ) {
				$line = sprintf( "%s:\t%%g%ss%%n", $index, $value );
				$line = WP_CLI::colorize( $line );
			} elseif( $worst == $index ) {
				$line = sprintf( "%s:\t%%r%ss%%n", $index, $value );
				$line = WP_CLI::colorize( $line );
			}

			WP_CLI::line( $line );
		}
	}

	/**
	 * Set up the database environment with a certain number of posts
	 *
	 * @param array $count Number of posts with which to populate the database
	 */
	protected function setup( $count ) {
		global $wpdb;
		$wpdb->suppress_errors = false;
		$wpdb->show_errors = true;
		$wpdb->db_connect();
		ini_set('display_errors', 1 );

		// Set up transaction
		$wpdb->query( 'SET autocommit = 0;' );
		$wpdb->query( 'START TRANSACTION;' );

		// Create a bunch of posts
		for ( $i = 0; $i < $count; $i ++ ) {
			$post = array(
				'post_status'  => 'publish',
				'post_title'   => sprintf( 'Post title %s', $i ),
				'post_content' => sprintf( 'Post context %s', $i ),
				'post_excerpt' => sprintf( 'Post excerpt %s', $i ),
				'post_type'    => 'post',
			);

			wp_insert_post( $post );
		}
	}

	/**
	 * Clean up the database so we are back to a pristine environment
	 */
	protected function teardown() {
		global $wpdb;

		// Roll back the transaction
		$wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Set up our permalinks.
	 *
	 * @global WP_Rewrite $wp_rewrite
	 *
	 * @param $structure
	 */
	protected function set_permalinks( $structure ) {
		global $wp_rewrite;

		// Set permalinks
		$wp_rewrite->set_permalink_structure( $structure );

		// Flush permalinks
		flush_rewrite_rules();
	}

	/**
	 * Test a specific permalink structure
	 *
	 * @return string
	 */
	protected function test_structure() {
		// Set up a timer
		$timer = microtime( true );

		for ( $i = 0; $i < 1000; $i++ ) {
			// Create a new WP object and parse the server request
			$wp = new WP();
			$wp->parse_request();

			// Clean things up for the sake of garbage collection
			unset( $wp );
		}

		$total = microtime( true ) - $timer;

		return (string) $total;
	}
}

WP_CLI::add_command( 'perfpress', 'PerfPress_Command' );