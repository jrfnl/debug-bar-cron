<?php

/**
 * Add a new Debug Bar Panel.
 */
class ZT_Debug_Bar_Cron extends Debug_Bar_Panel {

	/**
	 * Holds all of the cron events.
	 *
	 * @var array
	 */
	private $_crons;

	/**
	 * Holds only the cron events initiated by WP core.
	 *
	 * @var array
	 */
	private $_core_crons;

	/**
	 * Holds the cron events created by plugins or themes.
	 *
	 * @var array
	 */
	private $_user_crons;

	private $_hooks;

	private $_times;

	private $_hashes;

	/**
	 * Total number of cron events.
	 *
	 * @var int
	 */
	private $_total_crons = 0;

	private $_request_url;

	/**
	 * Give the panel a title and set the enqueues.
	 */
	public function init() {
		$this->_request_url = admin_url( '/options.php' );
		$this->get_crons();
		$this->title( __( 'Cron', 'debug-bar' ) );

		add_action( 'wp_print_styles', array( $this, 'print_styles' ) );
		add_action( 'admin_print_styles', array( $this, 'print_styles' ) );

		add_action( 'admin_init', array( $this, 'process_request' ) );
	}

	/**
	 * Enqueue styles.
	 */
	public function print_styles() {
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '.dev' : '';
		wp_enqueue_style( 'zt-debug-bar-cron', plugins_url( "css/debug-bar-cron$suffix.css", __FILE__ ), array(), '20120325' );
	}

	/**
	 * Show the menu item in Debug Bar.
	 */
	public function prerender() {
		$this->set_visible( true );
	}

	/**
	 * Show the contents of the page.
	 */
	public function render() {
		$doing_cron = get_transient( 'doing_cron' ) ? 'Yes' : 'No';

		// Get the time of the next event
		$cron_times = array_keys( $this->_crons );
		$unix_time_next_cron = $cron_times[0];

		$time_next_cron = date( 'Y-m-d H:i:s', $unix_time_next_cron );
		$human_time_next_cron = human_time_diff( $unix_time_next_cron );

		echo '<div id="debug-bar-cron">';
		echo '<h2><span>' . __( 'Total Events', 'zt-debug-bar-cron' ) . ':</span>' . (int) $this->_total_crons . '</h2>';
		echo '<h2><span>' . __( 'Doing Cron', 'zt-debug-bar-cron' ) . ':</span>' . $doing_cron . '</h2>';
		echo '<h2 class="times"><span>' . __( 'Next Event', 'zt-debug-bar-cron' ) . ':</span>' . $time_next_cron . '<br />' . $unix_time_next_cron . '<br />' . $human_time_next_cron . '</h2>';
		echo '<h2><span>' . __( 'Current Time', 'zt-debug-bar-cron' ) . ':</span>' . date( 'H:i:s' ) . '</h2>';
		echo '<div class="clear"></div>';

		// Display the console if anything is available to display
		if ( $console_log = get_transient( 'zt-debug-bar-cron-console' ) ) {
			echo '<h3>' . __( 'Console', 'zt-debug-bar-cron' ) . '</h3>';

			echo '<p>The event "' . $console_log['hook'] . '" scheduled to run at "' . $console_log['time'] . '" was executed at "' . $console_log['now'] . '", taking "' . $console_log['duration'] . 's" to execute.</p>';

			if ( isset( $console_log['contents'] ) && '' != trim( $console_log['contents'] )  )
				echo '<iframe width="100%" height="200"><html><head></head><body><div class="zt-debug-bar-cron-console">' . $console_log['contents'] . '</div></body></html></iframe>';

			delete_transient( 'zt-debug-bar-cron-console' );
		}

		echo '<h3>' . __( 'Custom Events', 'zt-debug-bar-cron' ) . '</h3>';

		if ( ! is_null( $this->_user_crons ) ) {
			$this->display_events( $this->_user_crons );
		} else {
			echo '<p>' . __( 'No Custom Events scheduled.', 'zt-debug-bar-cron' ) . '</p>';
		}

		echo '<h3>' . __( 'Schedules', 'zt-debug-bar-cron' ) . '</h3>';

		$this->display_schedules();

		echo '<h3>' . __( 'Core Events', 'zt-debug-bar-cron' ) . '</h3>';

		if ( ! is_null( $this->_core_crons ) ) {
			$this->display_events( $this->_core_crons );
		} else {
			echo '<p>' . __( 'No Core Events scheduled.', 'zt-debug-bar-cron' ) . '</p>';
		}

		echo '</div>';
	}

	/**
	 * Gets all of the cron jobs.
	 *
	 * This function sorts the cron jobs into core crons, and custom crons. It also tallies
	 * a total count for the crons as this number is otherwise tough to get.
	 *
	 * @return array
	 */
	private function get_crons() {
		if ( ! is_null( $this->_crons ) )
			return $this->_crons;

		if ( ! $crons = _get_cron_array() )
			return $this->_crons;

		$this->_crons = $crons;

		// Lists all crons that are defined in WP Core
		$core_cron_hooks = array(
			'wp_scheduled_delete',
			'upgrader_scheduled_cleanup',
			'importer_scheduled_cleanup',
			'publish_future_post',
			'akismet_schedule_cron_recheck',
			'akismet_scheduled_delete',
			'do_pings',
			'wp_version_check',
			'wp_update_plugins',
			'wp_update_themes'
		);

		// Sort and count crons
		foreach ( $this->_crons as $time => $time_cron_array ) {
			foreach ( $time_cron_array as $hook => $data ) {
				$this->_total_crons++;

				if ( in_array( $hook, $core_cron_hooks ) )
					$this->_core_crons[ $time ][ $hook ] = $data;
				else
					$this->_user_crons[ $time ][ $hook ] = $data;

				$this->_hooks[] = $hook;
				$this->_times[] = $time;

				$keys = array_keys( $data );
				$hash = wp_strip_all_tags( $keys[0] );
				$this->_hashes[] = $hash;
			}
		}

		return $this->_crons;
	}

	/**
	 * Displays the events in an easy to read table.
	 *
	 * @param $events Array of events
	 * @return void|string
	 */
	private function display_events( $events ) {
		if ( is_null( $events ) || empty( $events ) )
			return '';

		$class = 'odd';

		echo '<table class="zt-debug-bar-cron-event-table" cellspacing="0">';
		echo '<thead>';
		echo '<th></th>';
		echo '<th width="180px">' . __( 'Next Execution', 'zt-debug-bar-cron' ) . '</th>';
		echo '<th width="25%">' . __( 'Hook', 'zt-debug-bar-cron' ) . '</th>';
		echo '<th width="20%">' . __( 'Interval Hook', 'zt-debug-bar-cron' ) . '</th>';
		echo '<th width="120px">' . __( 'Interval Value', 'zt-debug-bar-cron' ) . '</th>';
		echo '<th width="25%">' . __( 'Args', 'zt-debug-bar-cron' ) . '</th>';
		echo '</thead>';

		foreach ( $events as $time => $time_cron_array ) {
			foreach ( $time_cron_array as $hook => $data ) {
				$keys = array_keys( $data );
				$hash = wp_strip_all_tags( $keys[0] );

				// Prepare action URLs
				$run_url = $this->_request_url;
				$run_url = add_query_arg( 'zt-action', 'run', $run_url );
				$run_url = add_query_arg( 'zt-hook', wp_strip_all_tags( $hook ), $run_url );
				$run_url = add_query_arg( 'zt-time', wp_strip_all_tags( $time ), $run_url );
				$run_url = add_query_arg( 'zt-hash', wp_strip_all_tags( $hash ), $run_url );
				$run_url = wp_nonce_url( $run_url );

				echo '<tr class="' . $class . '">';

				/* @todo: add nonces */
				echo '<td><a href="' . esc_url( $run_url ) . '" title="Run Function"><img src="' . plugins_url( '/images/run.png', __FILE__ ) . '" alt="Run Icon" /></a><br />';
				echo '<a href="#" title="Cancel Event"><img src="' . plugins_url( '/images/delete.png', __FILE__ ) . '" alt="Delete Icon" /></a></td>';
				echo '<td valign="top">' . date( 'Y-m-d H:i:s', $time ) . '<br />' . $time . '<br />' . human_time_diff( $time ) . '</td>';
				echo '<td valign="top">' . wp_strip_all_tags( $hook ) . '</td>';

				foreach ( $data as $hash => $info ) {
					// Report the schedule
					echo '<td valign="top">';
					if ( $info['schedule'] )
						echo wp_strip_all_tags( $info['schedule'] );
					else
						echo 'Single Event';
					echo '</td>';

					// Report the interval
					echo '<td valign="top">';
					if ( isset( $info['interval'] ) ) {
						echo wp_strip_all_tags( $info['interval'] ) . 's<br />';
						echo $info['interval'] / 60 . 'm<br />';
						echo $info['interval'] / ( 60  * 60 ). 'h';
					} else {
						echo 'Single Event';
					}
					echo '</td>';

					// Report the args
					echo '<td valign="top">';
					if ( ! empty( $info['args'] ) ) {
						foreach ( $info['args'] as $key => $value ) {
							echo wp_strip_all_tags( $key ) . ' => ' . wp_strip_all_tags( $value ) . '<br />';
						}
					} else {
						echo 'No Args';
					}
					echo '</td>';
				}

				echo '</tr>';
				$class = ( 'odd' == $class ) ? 'even' : 'odd';
			}
		}

		echo '</table>';
	}

	/**
	 * Displays all of the schedules defined.
	 */
	private function display_schedules() {
		echo '<table class="zt-debug-bar-cron-event-table" cellspacing="0">';
		echo '<thead>';
		echo '<th width="180px">' . __( 'Interval Hook', 'zt-debug-bar-cron' ) . '</th>';
		echo '<th width="25%">' . __( 'Interval (S)', 'zt-debug-bar-cron' ) . '</th>';
		echo '<th width="20%">' . __( 'Interval (M)', 'zt-debug-bar-cron' ) . '</th>';
		echo '<th width="120px">' . __( 'Interval (H)', 'zt-debug-bar-cron' ) . '</th>';
		echo '<th width="25%">' . __( 'Display Name', 'zt-debug-bar-cron' ) . '</th>';
		echo '</thead>';

		$class = 'odd';

		foreach ( wp_get_schedules() as $interval_hook => $data ) {
			echo '<tr class="' . $class . '">';
			echo '<td valign="top">' . esc_html( $interval_hook ) . '</td>';
			echo '<td valign="top">' . wp_strip_all_tags( $data['interval'] ) . '</td>';
			echo '<td valign="top">' . wp_strip_all_tags( $data['interval'] ) / 60 . '</td>';
			echo '<td valign="top">' . wp_strip_all_tags( $data['interval'] ) / ( 60  * 60 ). '</td>';
			echo '<td valign="top">' . esc_html( $data['display'] ) . '</td>';
			echo '</tr>';

			$class = ( 'odd' == $class ) ? 'even' : 'odd';
		}

		echo '</table>';
	}

	public function process_request() {
		global $pagenow;
		if ( 'options.php' != $pagenow )
			return false;

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'] ) )
			return false;

		// Check that the necessary components are available
		$actions = array( 'run', 'cancel', 'reschedule' );
		if ( ! isset( $_GET['zt-action'] ) || ! in_array( $_GET['zt-action'], $actions ) ||
			! isset( $_GET['zt-hook'] ) || ! in_array( $_GET['zt-hook'], $this->_hooks ) ||
			! isset( $_GET['zt-time'] ) || ! in_array( $_GET['zt-time'], $this->_times ) ||
			! isset( $_GET['zt-hash'] ) || ! in_array( $_GET['zt-hash'], $this->_hashes ) ) {
			wp_redirect( wp_get_referer() );
			die();
		}

		// All of these are whitelisted, so don't worry about sanitizing
		$action = $_GET['zt-action'];
		$hook = $_GET['zt-hook'];
		$time = $_GET['zt-time'];
		$hash = $_GET['zt-hash'];

		$error = 'none';

		ob_start();

		$time_start = microtime( true );

		// The function returns null if it cannot be found, so set an error flag
		do_action_ref_array( $hook, $this->_crons[$time][$hook][$hash]['args'] );

		$time_end = microtime( true );
		$duration = $time_end - $time_start;

		$contents = ob_get_contents();
		ob_end_clean();

		$console_log = array(
			'error' => $error,
			'duration' => $duration,
			'hook' => $hook,
			'time' => $time,
			'hash' => $hash,
			'now' => time(),
			'contents' => $contents
		);

		// Set those contents to a transient in order to display them in the console
		set_transient( 'zt-debug-bar-cron-console', $console_log );

		wp_redirect( wp_get_referer() );
		die();
	}
}