<?php
if(!defined('ABSPATH')) {
	exit;
}

/**
 * WP_Event_Manager_Cache_Helper class.
 */
class WP_Event_Manager_Cache_Helper {

	public static function init() {
		add_action('save_post', array(__CLASS__, 'flush_get_event_listings_cache'));
		add_action('delete_post', array(__CLASS__, 'flush_get_event_listings_cache'));
		add_action('trash_post', array(__CLASS__, 'flush_get_event_listings_cache'));
		
		add_action('event_manager_my_event_do_action', array(__CLASS__, 'event_manager_my_event_do_action'));

		add_action('set_object_terms', array(__CLASS__, 'set_term'), 10, 4);

		add_action('edited_term', array(__CLASS__, 'edited_term'), 10, 3);

		add_action('create_term', array(__CLASS__, 'edited_term'), 10, 3);

		add_action('delete_term', array(__CLASS__, 'edited_term'), 10, 3);

		add_action('event_manager_clear_expired_transients', array(__CLASS__, 'clear_expired_transients'), 10);
		add_action('transition_post_status', array(__CLASS__, 'maybe_clear_count_transients'), 10, 3);
	}

	/**
	 * Flush the cache.
	 */
	public static function flush_get_event_listings_cache($post_id) {
		if('event_listing' === get_post_type($post_id)) {
			self::get_transient_version('get_event_listings', true);
		}
	}

	/**
	 * Flush the cache.
	 */
	public static function event_manager_my_event_do_action($action) {
		if('mark_cancelled' === $action || 'mark_not_cancelled' === $action) {
			self::get_transient_version('get_event_listings', true);
		}
	}

	/**
	 * When any post has a term set.
	 */
	public static function set_term($object_id = '', $terms = '', $tt_ids = '', $taxonomy = '') {
		self::get_transient_version('em_get_' . sanitize_text_field($taxonomy), true);
	}

	/**
	 * When any term is edited.
	 */
	public static function edited_term($term_id = '', $tt_id = '', $taxonomy = '') {
		self::get_transient_version('em_get_' . sanitize_text_field($taxonomy), true);
	}

	/**
	 * Get transient version
	 *
	 * When using transients with unpredictable names, e.g. those containing an md5
	 * hash in the name, we need a way to invalidate them all at once.
	 *
	 * When using default WP transients we're able to do this with a DB query to
	 * delete transients manually.
	 *
	 * With external cache however, this isn't possible. Instead, this function is used
	 * to append a unique string (based on time()) to each transient. When transients
	 * are invalidated, the transient version will increment and data will be regenerated.
	 *
	 * @param  string  $group   Name for the group of transients we need to invalidate
	 * @param  boolean $refresh true to force a new version
	 * @return string transient version based on time(), 10 digits
	 */
	public static function get_transient_version($group, $refresh = false) {
		$transient_name  = $group . '-transient-version';
		$transient_value = get_transient($transient_name);

		if(false === $transient_value || true === $refresh) {
			self::delete_version_transients($transient_value);
			set_transient($transient_name, $transient_value = time());
		}
		return $transient_value;
	}

	/**
	 * When the transient version increases, this is used to remove all past transients to avoid filling the DB.
	 *
	 * Note; this only works on transients appended with the transient version, and when object caching is not being used.
	 */
	private static function delete_version_transients($version) {
		if(!wp_using_ext_object_cache() && !empty($version)) {
			global $wpdb;
			$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s;", "\_transient\_%" . $version));
		}
	}

    /**
	 * Clear expired transients.
	 */
	public static function clear_expired_transients() {
		global $wpdb;
		if(!wp_using_ext_object_cache() && !defined('WP_SETUP_CONFIG') && !defined('WP_INSTALLING')) {
			$sql= "
			    DELETE a, b FROM $wpdb->options a, $wpdb->options b	
 				WHERE a.option_name LIKE %s	
 				AND a.option_name NOT LIKE %s
 				AND b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
				AND b.option_value < %s;";
				$wpdb->query($wpdb->prepare($sql, $wpdb->esc_like('_transient_em_') . '%', $wpdb->esc_like('_transient_timeout_em_') . '%', time()));
 		}
	}
	
	/**
	 * Maybe remove pending count transients.
	 *
	 * When a supported post type status is updated, check if any cached count transients
	 * need to be removed, and remove the
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function maybe_clear_count_transients($new_status, $old_status, $post) {
		global $wpdb;
		
		/**
		 * Get supported post types for count caching.
		 * @param array   $post_types Post types that should be cached.
		 * @param string  $new_status New post status.
		 * @param string  $old_status Old post status.
		 * @param WP_Post $post       Post object.
		 */
		$post_types = apply_filters('wp_eventmanager_count_cache_supported_post_types', array('event_listing'), $new_status, $old_status, $post);
		
		// Only proceed when statuses do not match, and post type is supported post type
		if($new_status === $old_status || !in_array($post->post_type, $post_types)) {
			return;
		}
		
		/**
		 * Get supported post statuses for count caching.
		 * @param array   $post_statuses Post statuses that should be cached.
		 * @param string  $new_status    New post status.
		 * @param string  $old_status    Old post status.
		 * @param WP_Post $post          Post object.
		 */
		$valid_statuses = apply_filters('wp_eventmanager_count_cache_supported_statuses', array('pending'), $new_status, $old_status, $post);
		
		$rlike = array();
		// New status transient option name
		if(in_array($new_status, $valid_statuses)){
			$rlike[] = "^_transient_em_{$new_status}_{$post->post_type}_count_user_";
		}
		// Old status transient option name
		if(in_array($old_status, $valid_statuses)){
			$rlike[] = "^_transient_em_{$old_status}_{$post->post_type}_count_user_";
		}
		
		if(empty($rlike)) {
			return;
		}
		
		$sql        = $wpdb->prepare("SELECT option_name FROM $wpdb->options WHERE option_name RLIKE '%s'", implode('|', $rlike));
		$transients = $wpdb->get_col($sql);
		
		// For each transient...
		foreach ($transients as $transient) {
			// Strip away the WordPress prefix in order to arrive at the transient key.
			$key = str_replace('_transient_', '', $transient);
			// Now that we have the key, use WordPress core to the delete the transient.
			delete_transient($key);
		}
		
		// Sometimes transients are not in the DB, so we have to do this too:
		wp_cache_flush();
	}
	
	/**
	 * Get Listings Count from Cache.
	 *
	 * @param string $post_type
	 * @param string $status
	 * @param bool   $force Force update cache
	 *
	 * @return int
	 */
	public static function get_listings_count($post_type = 'event_listing', $status = 'pending', $force = false) {
		
		// Get user based cache transient
		$user_id   = get_current_user_id();
		$transient = "em_{$status}_{$post_type}_count_user_{$user_id}";
		
		// Set listings_count value from cache if exists, otherwise set to 0 as default
		$status_count = ($cached_count = get_transient($transient)) ? $cached_count : 0;
		
		// $cached_count will be false if transient does not exist
		if($cached_count === false || $force) {
			$count_posts = wp_count_posts($post_type, 'readable');
			// Default to 0 $status if object does not have a value
			$status_count = isset($count_posts->$status) ? $count_posts->$status : 0;
			set_transient($transient, $status_count, DAY_IN_SECONDS * 7);
		}
		return $status_count;
	}
}
WP_Event_Manager_Cache_Helper::init();