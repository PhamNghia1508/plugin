<?php
defined( 'ABSPATH' ) || exit;

/**
 * Resolves a WooCommerce order address to an SPX province/district/ward.
 * Precedence: saved order override → shipping fields → billing fields → known
 * ward meta keys. Never guesses a ward from free text and never calls any
 * external service. Unresolved/ambiguous levels are reported, not fabricated.
 */
final class SPX_WC_Address_Resolver {
	/** @var SPX_Address_Repository */
	private $repo;

	public function __construct( SPX_Address_Repository $repo ) {
		$this->repo = $repo;
	}

	/** Common ward meta keys used by VN address plugins (best-effort, optional). */
	private static function ward_meta_keys(): array {
		return array( '_shipping_ward', '_billing_ward', 'shipping_ward', 'billing_ward', '_spx_shipping_ward' );
	}

	public function resolve( WC_Order $order ): array {
		if ( SPX_Order_Address::has_override( $order ) ) {
			$ov = SPX_Order_Address::get( $order );
			if ( $this->repo->validate_hierarchy( $ov['province_code'], $ov['district_code'], $ov['ward_code'] ) ) {
				return $this->result( true, SPX_Checkout_Address_Snapshot::SOURCE_ADMIN_OVERRIDE, $ov['province_code'], $ov['province_name'], $ov['district_code'], $ov['district_name'], $ov['ward_code'], $ov['ward_name'], array(), array() );
			}
		}

		if ( class_exists( 'SPX_Checkout_Address_Snapshot' ) && class_exists( 'SPX_Checkout_Address_Service' ) ) {
			$service = new SPX_Checkout_Address_Service( $this->repo );
			if ( SPX_Checkout_Address_Snapshot::is_current_and_valid( $order, $service ) ) {
				$s = SPX_Checkout_Address_Snapshot::read_from_order( $order );
				return $this->result( true, $s['source'], $s['province_id'], $s['province_name'], $s['district_id'], $s['district_name'], $s['ward_id'], $s['ward_name'], array(), array() );
			}
		}

		$shipping = $this->extract( $order, 'shipping' );
		$has_ship = '' !== $shipping['province'] || '' !== $shipping['district'];
		$raw      = $has_ship ? $shipping : $this->extract( $order, 'billing' );
		$out           = $this->resolve_from_names( $raw, $this->repo );
		$out['source'] = SPX_Checkout_Address_Snapshot::SOURCE_RESOLVER;
		return $out;
	}

	/** Pure name → code resolution. Unit-testable without WooCommerce. */
	public function resolve_from_names( array $raw, SPX_Address_Repository $repo ): array {
		$errors   = array();
		$warnings = array();
		$pcode = $pname = $dcode = $dname = $wcode = $wname = '';

		$p = $repo->find_province_by_name( (string) ( $raw['province'] ?? '' ) );
		if ( 'matched' === $p['status'] ) { $pcode = $p['code']; $pname = $p['name']; }
		elseif ( 'ambiguous' === $p['status'] ) { $warnings[] = 'recipient_province_ambiguous'; $errors[] = 'recipient_province_unresolved'; }
		else { $errors[] = 'recipient_province_unresolved'; }

		if ( '' !== $pcode ) {
			$d = $repo->find_district_by_name( $pcode, (string) ( $raw['district'] ?? '' ) );
			if ( 'matched' === $d['status'] ) { $dcode = $d['code']; $dname = $d['name']; }
			elseif ( 'ambiguous' === $d['status'] ) { $warnings[] = 'recipient_district_ambiguous'; $errors[] = 'recipient_district_unresolved'; }
			else { $errors[] = 'recipient_district_unresolved'; }
		}

		if ( '' !== $dcode ) {
			$ward_raw = trim( (string) ( $raw['ward'] ?? '' ) );
			if ( '' === $ward_raw ) {
				$errors[] = 'recipient_ward_unresolved';
			} else {
				$w = $repo->find_ward_by_name( $dcode, $ward_raw );
				if ( 'matched' === $w['status'] ) { $wcode = $w['code']; $wname = $w['name']; }
				elseif ( 'ambiguous' === $w['status'] ) { $warnings[] = 'recipient_ward_ambiguous'; $errors[] = 'recipient_ward_unresolved'; }
				else { $errors[] = 'recipient_ward_unresolved'; }
			}
		}

		$resolved = ( '' !== $pcode && '' !== $dcode && '' !== $wcode );
		return $this->result( $resolved, 'names', $pcode, $pname, $dcode, $dname, $wcode, $wname, $errors, $warnings );
	}

	private function extract( WC_Order $order, string $type ): array {
		if ( 'shipping' === $type ) {
			$state = $order->get_shipping_state();
			$city  = $order->get_shipping_city();
		} else {
			$state = $order->get_billing_state();
			$city  = $order->get_billing_city();
		}
		$province = self::state_name( $state );
		$ward     = '';
		foreach ( self::ward_meta_keys() as $key ) {
			$v = (string) $order->get_meta( $key, true );
			if ( '' !== trim( $v ) ) { $ward = $v; break; }
		}
		return array( 'province' => $province, 'district' => (string) $city, 'ward' => $ward );
	}

	/** Convert a WooCommerce VN state code to its province name when possible. */
	private static function state_name( string $state ): string {
		if ( '' === $state ) { return ''; }
		if ( function_exists( 'WC' ) && WC() && isset( WC()->countries ) ) {
			$states = WC()->countries->get_states( 'VN' );
			if ( is_array( $states ) && isset( $states[ $state ] ) ) { return (string) $states[ $state ]; }
		}
		return $state; // already a name
	}

	private function result( bool $resolved, string $source, string $pcode, string $pname, string $dcode, string $dname, string $wcode, string $wname, array $errors, array $warnings ): array {
		return array(
			'resolved'      => $resolved,
			'source'        => $source,
			'province_code' => $pcode,
			'province_name' => $pname,
			'district_code' => $dcode,
			'district_name' => $dname,
			'ward_code'     => $wcode,
			'ward_name'     => $wname,
			'errors'        => array_values( array_unique( $errors ) ),
			'warnings'      => array_values( array_unique( $warnings ) ),
		);
	}
}
