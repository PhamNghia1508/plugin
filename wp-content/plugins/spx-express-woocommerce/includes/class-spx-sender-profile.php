<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin-level SPX sender profile (NOT a Shipping Zone instance setting and NOT
 * stored per order). Held in a single non-autoloaded option. Business defaults:
 * service_type=1, payment_role=1, collect_type=2 (drop-off).
 */
final class SPX_Sender_Profile {
	const OPTION = 'spx_sender_profile';

	public static function defaults(): array {
		return array(
			'sender_name'            => '',
			'sender_phone'           => '',
			'sender_detail_address'  => '',
			'sender_province_code'   => '',
			'sender_province_name'   => '',
			'sender_district_code'   => '',
			'sender_district_name'   => '',
			'sender_ward_code'       => '',
			'sender_ward_name'       => '',
			'sender_email'           => '',
			'service_type'           => 1,
			'payment_role'           => 1,
			'collect_type'           => 2,
			'default_parcel_length_cm' => 0,
			'default_parcel_width_cm'  => 0,
			'default_parcel_height_cm' => 0,
			'default_declared_value'   => 0,
		);
	}

	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	public static function is_complete(): bool {
		$p = self::get();
		foreach ( array( 'sender_name', 'sender_phone', 'sender_detail_address', 'sender_province_code', 'sender_district_code', 'sender_ward_code' ) as $k ) {
			if ( '' === trim( (string) $p[ $k ] ) ) { return false; }
		}
		return true;
	}

	/**
	 * Validate + persist. Location codes are validated against the dataset.
	 * @return array{ok:bool,errors:array<string,string>}
	 */
	public static function save( array $input, SPX_Address_Repository $repo ): array {
		$errors = array();
		$name   = sanitize_text_field( $input['sender_name'] ?? '' );
		$phone  = self::sanitize_phone( $input['sender_phone'] ?? '' );
		$addr   = sanitize_text_field( $input['sender_detail_address'] ?? '' );
		$pcode  = sanitize_text_field( $input['sender_province_code'] ?? '' );
		$dcode  = sanitize_text_field( $input['sender_district_code'] ?? '' );
		$wcode  = sanitize_text_field( $input['sender_ward_code'] ?? '' );
		$email  = sanitize_email( $input['sender_email'] ?? '' );

		if ( '' === $name ) { $errors['sender_name'] = __( 'Sender name is required.', 'spx-express-woocommerce' ); }
		elseif ( mb_strlen( $name ) > 64 ) { $errors['sender_name'] = __( 'Sender name must be at most 64 characters.', 'spx-express-woocommerce' ); }
		if ( '' === $phone ) { $errors['sender_phone'] = __( 'Sender phone is required.', 'spx-express-woocommerce' ); }
		elseif ( ! self::is_valid_vn_phone( $phone ) ) { $errors['sender_phone'] = __( 'Sender phone format is not valid for Vietnam.', 'spx-express-woocommerce' ); }
		if ( '' === $addr ) { $errors['sender_detail_address'] = __( 'Sender detail address is required.', 'spx-express-woocommerce' ); }
		elseif ( mb_strlen( $addr ) > 256 ) { $errors['sender_detail_address'] = __( 'Sender detail address must be at most 256 characters.', 'spx-express-woocommerce' ); }

		if ( ! $repo->is_available() ) {
			$errors['dataset'] = __( 'Import the SPX address dataset before saving a sender location.', 'spx-express-woocommerce' );
		} elseif ( '' === $pcode || '' === $dcode || '' === $wcode ) {
			$errors['location'] = __( 'Select province, district and ward.', 'spx-express-woocommerce' );
		} elseif ( ! $repo->validate_hierarchy( $pcode, $dcode, $wcode ) ) {
			$errors['location'] = __( 'The selected province/district/ward is not a valid SPX combination.', 'spx-express-woocommerce' );
		}

		if ( ! empty( $errors ) ) { return array( 'ok' => false, 'errors' => $errors ); }

		// Resolve official display names from codes (never trust posted labels).
		$ward     = $repo->get_ward( $dcode, $wcode );
		$prov_nm  = '';
		$dist_nm  = '';
		foreach ( $repo->get_provinces() as $p ) { if ( $p['code'] === $pcode ) { $prov_nm = $p['name']; } }
		foreach ( $repo->get_districts( $pcode ) as $d ) { if ( $d['code'] === $dcode ) { $dist_nm = $d['name']; } }

		$profile = array_merge( self::get(), array(
			'sender_name'           => $name,
			'sender_phone'          => $phone,
			'sender_detail_address' => $addr,
			'sender_email'          => $email,
			'sender_province_code'  => $pcode,
			'sender_province_name'  => $prov_nm,
			'sender_district_code'  => $dcode,
			'sender_district_name'  => $dist_nm,
			'sender_ward_code'      => $wcode,
			'sender_ward_name'      => $ward ? $ward['name'] : '',
			'default_parcel_length_cm' => max( 0, (int) ( $input['default_parcel_length_cm'] ?? 0 ) ),
			'default_parcel_width_cm'  => max( 0, (int) ( $input['default_parcel_width_cm'] ?? 0 ) ),
			'default_parcel_height_cm' => max( 0, (int) ( $input['default_parcel_height_cm'] ?? 0 ) ),
			'default_declared_value'   => max( 0, (int) ( $input['default_declared_value'] ?? 0 ) ),
			'service_type'          => 1,
			'payment_role'          => 1,
			'collect_type'          => 2,
		) );
		update_option( self::OPTION, $profile, false );
		return array( 'ok' => true, 'errors' => array() );
	}

	public static function sanitize_phone( string $phone ): string {
		$phone = trim( $phone );
		$plus  = ( '' !== $phone && '+' === $phone[0] );
		$digits = preg_replace( '/\D+/', '', $phone );
		return ( $plus ? '+' : '' ) . $digits;
	}

	/** VN rules per SPX docs; treats a leading +84 like 84. */
	public static function is_valid_vn_phone( string $phone ): bool {
		$p = ltrim( $phone, '+' );
		if ( ! preg_match( '/^\d+$/', $p ) ) { return false; }
		$len = strlen( $p );
		if ( 0 === strpos( $p, '1800' ) || 0 === strpos( $p, '1900' ) ) { return 8 === $len || 10 === $len; }
		if ( 0 === strpos( $p, '84' ) ) { return 11 === $len || 12 === $len; }
		if ( 0 === strpos( $p, '0' ) ) { return 10 === $len || 11 === $len; }
		return false;
	}
}
