<?php
defined( 'ABSPATH' ) || exit;
final class SPX_Production_Readiness {
	public static function evaluate( array $checks ): array {
		$required = array( 'credentials', 'verification', 'sender', 'dataset', 'https', 'hpos', 'scheduler', 'compatibility' ); $failed = array();
		foreach ( $required as $key ) { if ( empty( $checks[ $key ] ) ) { $failed[] = $key; } }
		return array( 'ready' => empty( $failed ), 'failed' => $failed, 'checks' => $checks, 'warnings' => array( 'webhook_not_configured', 'fixed_rate_active', 'fee_unit_unconfirmed' ) );
	}

	public static function current(): array {
		$store = new SPX_Credential_Store(); $credentials = $store->get( 'production' );
		$credential_ok = '' !== $credentials['app_id'] && '' !== $credentials['app_secret'] && '' !== $credentials['user_id'] && '' !== $credentials['user_secret'] && '' !== $credentials['shop_id'];
		$fingerprint = $store->fingerprint( 'production' );
		$verified = function_exists( 'get_option' ) ? (string) get_option( 'spx_production_verified_fingerprint', '' ) : '';
		$sender = class_exists( 'SPX_Sender_Profile' ) ? SPX_Sender_Profile::get() : array();
		$repo = class_exists( 'SPX_Address_Repository' ) ? new SPX_Address_Repository() : null; $dataset_ok = $repo && $repo->is_available();
		$sender_ok = class_exists( 'SPX_Sender_Profile' ) && SPX_Sender_Profile::is_complete() && $dataset_ok && $repo->validate_hierarchy( (string) ( $sender['sender_province_code'] ?? '' ), (string) ( $sender['sender_district_code'] ?? '' ), (string) ( $sender['sender_ward_code'] ?? '' ) );
		$home = function_exists( 'home_url' ) ? home_url( '/' ) : ''; $site = function_exists( 'site_url' ) ? site_url( '/' ) : '';
		$site_https = 0 === strpos( strtolower( $home ), 'https://' ) && 0 === strpos( strtolower( $site ), 'https://' );
		$hpos = class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) && class_exists( 'WooCommerce' );
		$as_available = function_exists( 'as_schedule_recurring_action' ); $cron_available = function_exists( 'wp_schedule_event' ) && ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON );
		$sync_enabled = class_exists( 'SPX_Tracking_Scheduler' ) && 'yes' === ( SPX_Tracking_Scheduler::settings()['enabled'] ?? 'no' );
		if ( $as_available && function_exists( 'as_has_scheduled_action' ) && class_exists( 'SPX_Tracking_Scheduler' ) ) { $scheduler = $sync_enabled && as_has_scheduled_action( SPX_Tracking_Scheduler::HOOK, array(), SPX_Tracking_Scheduler::TEST_GROUP ); }
		else { $scheduler = $sync_enabled && $cron_available && function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( 'spx_tracking_cron' ); }
		$wp_version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '0'; $wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
		$compatibility = PHP_VERSION_ID >= 70400 && version_compare( $wp_version, '5.8', '>=' ) && version_compare( $wc_version, '6.0', '>=' );
		return self::evaluate( array( 'credentials' => $credential_ok, 'verification' => SPX_Production_State::verification_matches( $verified, $fingerprint ), 'sender' => $sender_ok, 'dataset' => $dataset_ok, 'https' => $site_https, 'hpos' => $hpos, 'scheduler' => $scheduler, 'compatibility' => $compatibility ) );
	}
}
