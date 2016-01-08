<?php

if ( ! defined( 'WP_CLI' ) ) return;

/**
 * Programmatically test performance of various permalinks.
 */
class PerfPress_Command extends WP_CLI_Command {

	protected $permastructs = [
		'Default'        => '',
		'Day and Name'   => '/%year%/%monthnum%/%day%/%postname%/',
		'Month and Name' => '/%year%/%monthnum%/%postname%/',
		'Numeric'        => '/archives/%post_id%',
		'Post Name'      => '/%postname%/',
	];

	/**
	 * Run the tests
	 *
	 * ## OPTIONS
	 *
	 * [--posts=<count>]
	 * : Number of posts to test with in the database
	 *
	 * [--pages]
	 * Whether or not to create an equivalent number of pages for testing
	 *
	 * ## EXAMPLES
	 *
	 *     wp perfpress parse_request
	 *     wp perfpress parse_request --posts=1000
	 *     wp perfpress parse_request --pages
	 *
	 * @synopsis [--posts=<count>] [--pages]
	 * @alias pr
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function parse_request( $args, $assoc_args ) {
		$pages = isset( $assoc_args['pages'] );
		$post_count = isset( $assoc_args['posts'] ) ? (int) $assoc_args['posts'] : 1000;

		$this->setup( $post_count, $pages );
		$real_count = wp_count_posts( 'post' );
		$real_count = $real_count->publish;
		$page_count = wp_count_posts( 'page' );
		$page_count = $page_count->publish;

		// Get a post at random
		$posts = get_posts( [ 'posts_per_page' => 1, 'orderby' => 'rand' ] );
		$post = $posts[0];

		WP_CLI::line( sprintf( 'Testing with post #%s... (selected at random from %s posts)', $post->ID, $real_count ) );

		$results = [];
		foreach( $this->permastructs as $label => $permastruct ) {
			$this->set_permalinks( $permastruct );

			// Set up the path information
			$url_info = parse_url( get_the_permalink( $post ) );
			$_SERVER['PATH_INFO'] = $url_info['path'];

			$time = $this->test_structure();

			$results[ $label ] = $time;
		}

		// Print a headline
		WP_CLI::line( sprintf( 'Performance test with %s posts and %s pages:', $real_count, $page_count ) );
		WP_CLI::line();
		WP_CLI::line( "Permastruct:\tLoad time" );

		$this->print_results( $results );
	}

	/**
	 * Test the performance of creating a WP_Query inside a loop versus outside a loop.
	 *
	 * ## OPTIONS
	 *
	 * [--posts=<count>]
	 * : Number of post objects to test with int he database
	 *
	 * [--batch=<count>]
	 * : Number of posts to use as a batch
	 *
	 * [--loopback=<url>]
	 * : Remove server to ping with statistics instead of printing directly to CLI
	 *
	 * ## EXAMPLES
	 *
	 *    wp perfpress wp_query --posts=10000
	 *    wp perfpress wp_query --loopback=http://localhost:8888
	 *
	 * @synopsis [--posts=<count>] [--batch=<count>] [--loopback=<url>]
	 * @alias q
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function wp_query( $args, $assoc_args ) {
		$posts = isset( $assoc_args['posts'] ) ? intval( $assoc_args['posts'] ) : 10000;
		$batch = isset( $assoc_args['batch'] ) ? intval( $assoc_args['batch'] ) : 10;
		$loopback = isset( $assoc_args['loopback'] ) ? $assoc_args['loopback'] : false;

		// Add our posts to the database
		$this->setup( $posts, false );

		// First do a stored batch
		$this->cached_batch( $batch, $loopback );

		// Now do an uncached batch
		$this->uncached_batch( $batch, $loopback );
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
	 * @param int  $count     Number of posts with which to populate the database
	 * @param bool $add_pages Flag whether or not to add pages in addition to posts
	 */
	protected function setup( $count, $add_pages ) {
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
			$post = [
				'post_status'  => 'publish',
				'post_title'   => sprintf( 'Post title %s', $i ),
				'post_content' => sprintf( 'Post context %s', $i ),
				'post_excerpt' => sprintf( 'Post excerpt %s', $i ),
				'post_type'    => 'post',
			];

			$new_id = wp_insert_post( $post );
			if ( $new_id ) {
				clean_post_cache( $new_id );
			}

			if ( $add_pages ) {
				$post['post_type'] = 'page';

				wp_insert_post( $post );
			}
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

	/**
	 * Loop through all posts with a cached WP_Query instance.
	 *
	 * @global wpdb $wpdb
	 *
	 * @param int         $batch
	 * @param string|bool $loopback
	 */
	protected function cached_batch( $batch, $loopback ) {
		global $wpdb;

		$post_count = wp_count_posts( 'post' );
		$total = $post_count->publish;

		$this->print_memory( 'Before cached run' );

		$progress = new \cli\progress\Bar( 'Processing with a cached WP_Query', $total );
		$progress->display();

		// Set up batch args
		$page = 1;
		$migrated = 0;

		$query = new WP_Query();
		do {
			$loopback && $this->print_loopback( $loopback, 'cached' );
			$query->query( [
				'post_type'      => 'post',
				'posts_per_page' => $batch,
				'paged'          => $page,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
			] );

			$count = count( $query->posts );
			$migrated += $count;

			foreach( $query->posts as $post ) {
				// This is where we'd do some magic in a migration

				$progress->tick();

				// Clean up our cache as we go (so the next batch will be clear)
				clean_post_cache( $post->ID );
			}

			// Free memory
			$wpdb->flush();

			// Next page
			$page += 1;
		} while ( $count );

		$progress->finish();

		$this->print_memory( 'After cached run' );
	}

	/**
	 * Loop through all posts with a distinct WP_Query instances.
	 *
	 * @global wpdb $wpdb
	 *
	 * @param int         $batch
	 * @param string|bool $loopback
	 */
	protected function uncached_batch( $batch, $loopback ) {
		global $wpdb;

		$post_count = wp_count_posts( 'post' );
		$total = $post_count->publish;

		$this->print_memory( 'Before cached run' );

		$progress = new \cli\progress\Bar( 'Processing with an uncached WP_Query', $total );
		$progress->display();

		// Set up batch args
		$page = 1;
		$migrated = 0;

		do {
			$loopback && $this->print_loopback( $loopback, 'uncached' );
			$query = new WP_Query([
				'post_type'      => 'post',
				'posts_per_page' => $batch,
				'paged'          => $page,
				'post_status'    => 'publish',
				'no_found_rows'  => true,
			] );

			$count = count( $query->posts );
			$migrated += $count;

			foreach( $query->posts as $post ) {
				// This is where we'd do some magic in a migration

				$progress->tick();

				// Clean up our cache as we go (so the next batch will be clear)
				clean_post_cache( $post->ID );
			}

			// Free memory
			$wpdb->flush();

			// Next page
			$page += 1;
			unset( $query );
		} while ( $count );

		$progress->finish();

		$this->print_memory( 'After uncached run' );
	}

	/**
	 * Print our our memory usage
	 *
	 * @param string [$tag]
	 */
	protected function print_memory( $tag = '' ) {
		$mem = ( memory_get_peak_usage(false) / 1024 / 1024 ) . " MiB";

		if ( ! empty( $tag ) ) {
			$mem .= ' | ' . $tag;
		}

		WP_CLI::line( sprintf( 'Memory usage: %s', $mem ) );
	}

	/**
	 * Print memory usage to a loopback listener.
	 *
	 * @param string $loopback
	 * @param string [$tag]
	 */
	protected function print_loopback( $loopback, $tag = '' ) {
		$mem = ( memory_get_peak_usage(false) / 1024 / 1024 ) . " MiB";

		if ( ! empty( $tag ) ) {
			$mem .= ' | ' . $tag;
		}

		wp_remote_post( $loopback, array(
			'blocking' => false,
			'body'     => $mem,
		) );
	}
}

WP_CLI::add_command( 'perfpress', 'PerfPress_Command' );
