<?php
/**
 * Subscriptions Report Cache Manager
 *
 * Update report data caches on appropriate events, like renewal order payment.
 *
 * @class    WCS_Cache_Manager
 * @since    2.1
 * @package  WooCommerce Subscriptions/Classes
 * @category Class
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class WCS_Report_Cache_Manager {

	/**
	 * Array of event => report classes to determine which reports need to be updated on certain events.
	 *
	 * The index for each report's class is specified as its used later to determine when to schedule the report and we want
	 * it to be consistently at the same time, regardless of the hook which triggered the cache update. The indexes are based
	 * on the order of the reports in the menu on the WooCommerce > Reports > Subscriptions screen, which is why the indexes
	 * are not sequential (because not all reports need caching).
	 *
	 */
	private $update_events_and_classes = array(
		'woocommerce_subscriptions_reports_schedule_cache_updates' => array( // a custom hook that can be called to schedule a full cache update, used by WC_Subscriptions_Upgrader
			0 => 'WC_Report_Subscription_Events_By_Date',
			1 => 'WC_Report_Upcoming_Recurring_Revenue',
			3 => 'WC_Report_Subscription_By_Product',
			4 => 'WC_Report_Subscription_By_Customer',
		),
		'woocommerce_subscription_payment_complete' => array( // this hook takes care of renewal, switch and initial payments
			0 => 'WC_Report_Subscription_Events_By_Date',
			4 => 'WC_Report_Subscription_By_Customer',
		),
		'woocommerce_subscriptions_switch_completed' => array(
			0 => 'WC_Report_Subscription_Events_By_Date',
		),
		'woocommerce_subscription_status_changed' => array(
			0 => 'WC_Report_Subscription_Events_By_Date', // we really only need cancelled, expired and active status here, but we'll use a more generic hook for convenience
			4 => 'WC_Report_Subscription_By_Customer',
		),
		'woocommerce_subscription_status_active' => array(
			1 => 'WC_Report_Upcoming_Recurring_Revenue',
		),
		'woocommerce_order_add_product' => array(
			3 => 'WC_Report_Subscription_By_Product',
		),
		'woocommerce_order_edit_product' => array(
			3 => 'WC_Report_Subscription_By_Product',
		),
	);

	/**
	 * Record of all the report calsses to need to have the cache updated during this request. Prevents duplicate updates in the same request for different events.
	 */
	private $reports_to_update = array();

	/**
	 * The hook name to use for our WP-Cron entry for updating report cache.
	 */
	private $cron_hook = 'wcs_report_update_cache';

	/**
	 * The hook name to use for our WP-Cron entry for updating report cache.
	 */
	protected $use_large_site_cache;

	/**
	 * Attach callbacks to manage cache updates
	 *
	 * @since 2.1
	 * @return null
	 */
	public function __construct() {

		add_action( $this->cron_hook, array( $this, 'update_cache' ), 10, 1 );

		foreach ( $this->update_events_and_classes as $event_hook => $report_classes ) {
			add_action( $event_hook, array( $this, 'set_reports_to_update' ), 10 );
		}

		add_action( 'shutdown', array( $this, 'schedule_cache_updates' ), 10 );

		// Notify store owners that report data can be out-of-date
		add_action( 'admin_notices', array( $this, 'admin_notices' ), 0 );
	}

	/**
	 * Check if the given hook has reports associated with it, and if so, add them to our $this->reports_to_update
	 * property so we know to schedule an event to update their cache at the end of the request.
	 *
	 * This function is attached as a callback on the events in the $update_events_and_classes property.
	 *
	 * @since 2.1
	 * @return null
	 */
	public function set_reports_to_update() {
		if ( isset( $this->update_events_and_classes[ current_filter() ] ) ) {
			$this->reports_to_update = array_unique( array_merge( $this->reports_to_update, $this->update_events_and_classes[ current_filter() ] ) );
		}
	}

	/**
	 * At the end of the request, schedule cache updates for any events that occured during this request.
	 *
	 * For large sites, cache updates are run only once per day to avoid overloading the DB where the queries are very resource intensive
	 * (as reported during beta testing in https://github.com/Prospress/woocommerce-subscriptions/issues/1732). We do this at 4am in the
	 * site's timezone, which helps avoid running the queries during busy periods and also runs them after all the renewals for synchronised
	 * subscriptions should have finished for the day (which begins at 3am and rarely takes more than 1 hours of processing to get through
	 * an entire queue).
	 *
	 * This function is attached as a callback on 'shutdown' and will schedule cache updates for any reports found to need updates by
	 * @see $this->set_reports_to_update().
	 *
	 * @since 2.1
	 * @return null
	 */
	public function schedule_cache_updates() {

		if ( ! empty( $this->reports_to_update ) ) {

			// On large sites, we want to run the cache update once at 4am in the site's timezone
			if ( $this->use_large_site_cache() ) {

				$four_am_site_time = new DateTime( '4 am', wcs_get_sites_timezone() );

				// Convert to a UTC timestamp for scheduling
				$cache_update_timestamp = $four_am_site_time->format( 'U' );

				// PHP doesn't support a "next 4am" time format equivalent, so we need to manually handle getting 4am from earlier today (which will always happen when this is run after 4am and before midnight in the site's timezone)
				if ( $cache_update_timestamp <= gmdate( 'U' ) ) {
					$cache_update_timestamp += DAY_IN_SECONDS;
				}

				// Schedule one update event for each class to avoid updating cache more than once for the same class for different events
				foreach ( $this->reports_to_update as $index => $report_class ) {

					$cron_args = array( 'report_class' => $report_class );

					if ( false === wp_next_scheduled( $this->cron_hook, $cron_args ) ) {
						// Use the index to space out caching of each report to make them 15 minutes apart so that on large sites, where we assume they'll get a request at least once every few minutes, we don't try to update the caches of all reports in the same request
						wp_schedule_single_event( $cache_update_timestamp + 15 * MINUTE_IN_SECONDS * ( $index + 1 ), $this->cron_hook, $cron_args );
					}
				}
			} else { // Otherwise, run it 10 minutes after the last cache invalidating event

				// Schedule one update event for each class to avoid updating cache more than once for the same class for different events
				foreach ( $this->reports_to_update as $index => $report_class ) {

					$cron_args = array( 'report_class' => $report_class );

					if ( false !== ( $next_scheduled = wp_next_scheduled( $this->cron_hook, $cron_args ) ) ) {
						wp_unschedule_event( $next_scheduled, $this->cron_hook, $cron_args );
					}

					// Use the index to space out caching of each report to make them 5 minutes apart so that on large sites, where we assume they'll get a request at least once every few minutes, we don't try to update the caches of all reports in the same request
					wp_schedule_single_event( gmdate( 'U' ) + MINUTE_IN_SECONDS * ( $index + 1 ) * 5, $this->cron_hook, $cron_args );
				}
			}
		}
	}

	/**
	 * Update the cache data for a given report, as specified with $report_class, by call it's get_data() method.
	 *
	 * @since 2.1
	 * @return null
	 */
	public function update_cache( $report_class ) {

		// Validate the report class
		$valid_report_class = false;

		foreach ( $this->update_events_and_classes as $event_hook => $report_classes ) {
			if ( in_array( $report_class, $report_classes ) ) {
				$valid_report_class = true;
				break;
			}
		}

		if ( false === $valid_report_class ) {
			return;
		}

		// Load report class dependencies
		require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
		require_once( WC()->plugin_path() . '/includes/admin/reports/class-wc-admin-report.php' );

		$report_name = strtolower( str_replace( '_', '-', str_replace( 'WC_Report_', '', $report_class ) ) );
		$report_path = WCS_Admin_Reports::initialize_reports_path( '', $report_name, $report_class );

		require_once( $report_path );

		$reflector = new ReflectionMethod( $report_class, 'get_data' );

		// Some report classes extend WP_List_Table which has a constructor using methods not available on WP-Cron (and unable to be loaded with a __doing_it_wrong() notice), so they have a static get_data() method and do not need to be instantiated
		if ( $reflector->isStatic() ) {

			call_user_func( array( $report_class, 'get_data' ), array( 'no_cache' => true ) );

		} else {

			$report = new $report_class();

			// Classes with a non-static get_data() method can be displayed for different time series, so we need to update the cache for each of those ranges
			foreach ( array( 'year', 'last_month', 'month', '7day' ) as $range ) {
				$report->calculate_current_range( $range );
				$report->get_data( array( 'no_cache' => true ) );
			}
		}
	}

	/**
	 * Boolean flag to check whether to use a the large site cache method or not, which is determined based on the number of
	 * subscriptions and orders on the site (using arbitrary counts).
	 *
	 * @since 2.1
	 * @return bool
	 */
	protected function use_large_site_cache() {

		if ( null === $this->use_large_site_cache ) {

			if ( false == get_option( 'wcs_report_use_large_site_cache' ) ) {

				$subscription_counts = (array) wp_count_posts( 'shop_subscription' );
				$order_counts        = (array) wp_count_posts( 'shop_order' );

				if ( array_sum( $subscription_counts ) > 3000 || array_sum( $order_counts ) > 25000 ) {

					update_option( 'wcs_report_use_large_site_cache', 'true', false );

					$this->use_large_site_cache = true;
				} else {
					$this->use_large_site_cache = false;
				}
			} else {
				$this->use_large_site_cache = true;
			}
		}

		return apply_filters( 'wcs_report_use_large_site_cache', $this->use_large_site_cache );
	}

	/**
	 * Make it clear to store owners that data for some reports can be out-of-date.
	 *
	 * @since 2.1
	 */
	public function admin_notices() {

		$screen       = get_current_screen();
		$wc_screen_id = sanitize_title( __( 'WooCommerce', 'woocommerce-subscriptions' ) );

		if ( in_array( $screen->id, apply_filters( 'woocommerce_reports_screen_ids', array( $wc_screen_id . '_page_wc-reports', 'dashboard' ) ) ) && isset( $_GET['tab'] ) && 'subscriptions' == $_GET['tab'] && ( ! isset( $_GET['report'] ) || in_array( $_GET['report'], array( 'subscription_events_by_date', 'upcoming_recurring_revenue', 'subscription_by_product', 'subscription_by_customer' ) ) ) && $this->use_large_site_cache() ) {
			wcs_add_admin_notice( __( 'Please note: data for this report is cached. The data displayed may be out of date by up to 24 hours. The cache is updated each morning at 4am in your site\'s timezone.', 'woocommerce-subscriptions' ) );
		}
	}
}
return new WCS_Report_Cache_Manager();
