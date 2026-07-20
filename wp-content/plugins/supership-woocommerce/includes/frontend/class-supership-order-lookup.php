<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Order Lookup
 *
 * Front-end shortcode [supership_order_lookup] cho phép khách (kể cả khách
 * vãng lai) nhập số điện thoại đã dùng khi đặt hàng để xem danh sách đơn.
 *
 * Bảo mật:
 * - Chỉ hiển thị thông tin rút gọn: mã đơn, ngày đặt, trạng thái, tổng tiền,
 *   trạng thái vận chuyển. Không hiển thị tên đầy đủ / địa chỉ.
 * - Giới hạn số lần tra cứu theo IP để chống dò số điện thoại.
 *
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
class SuperShip_Order_Lookup {

	const SHORTCODE      = 'supership_order_lookup';
	const NONCE_ACTION   = 'supership_order_lookup';
	const RATE_LIMIT_MAX = 10;   // lượt tra cứu tối đa...
	const RATE_LIMIT_TTL = 900;  // ...trong 15 phút, tính theo IP.
	const MAX_ORDERS     = 20;

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Render shortcode output (form + kết quả nếu có submit)
	 */
	public static function render_shortcode(): string {
		$phone_input = '';
		$error       = '';
		$orders      = null;

		if ( isset( $_POST['supership_lookup_phone'] ) ) {
			$phone_input = sanitize_text_field( wp_unslash( $_POST['supership_lookup_phone'] ) );

			if ( ! isset( $_POST['_supership_lookup_nonce'] )
				|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_supership_lookup_nonce'] ) ), self::NONCE_ACTION ) ) {
				$error = __( 'Phiên làm việc đã hết hạn, vui lòng thử lại.', 'supership-woocommerce' );
			} elseif ( ! self::check_rate_limit() ) {
				$error = __( 'Bạn đã tra cứu quá nhiều lần. Vui lòng thử lại sau ít phút.', 'supership-woocommerce' );
			} else {
				$normalized = self::normalize_phone( $phone_input );
				if ( '' === $normalized ) {
					$error = __( 'Số điện thoại không hợp lệ.', 'supership-woocommerce' );
				} else {
					$orders = self::find_orders_by_phone( $normalized );
				}
			}
		}

		ob_start();
		self::render_styles();
		self::render_form( $phone_input );

		if ( '' !== $error ) {
			echo '<p class="supership-lookup-error">' . esc_html( $error ) . '</p>';
		} elseif ( is_array( $orders ) ) {
			self::render_results( $orders );
		}

		return (string) ob_get_clean();
	}

	/**
	 * Chuẩn hóa số điện thoại VN về dạng 0xxxxxxxxx
	 *
	 * @param string $raw Input người dùng
	 * @return string Số đã chuẩn hóa, hoặc '' nếu không hợp lệ
	 */
	public static function normalize_phone( string $raw ): string {
		$digits = preg_replace( '/\D+/', '', $raw );

		if ( '' === $digits ) {
			return '';
		}

		// +84 / 84 đầu số quốc tế -> 0
		if ( 0 === strpos( $digits, '84' ) && strlen( $digits ) >= 10 ) {
			$digits = '0' . substr( $digits, 2 );
		}

		// SĐT VN: 10 số bắt đầu bằng 0 (chấp nhận 11 số cho đầu số cũ)
		if ( 0 !== strpos( $digits, '0' ) || strlen( $digits ) < 10 || strlen( $digits ) > 11 ) {
			return '';
		}

		return $digits;
	}

	/**
	 * Tìm đơn theo SĐT billing (thử cả các biến thể 0xxx / +84xxx / 84xxx)
	 *
	 * @param string $phone SĐT đã chuẩn hóa (0xxxxxxxxx)
	 * @return WC_Order[]
	 */
	private static function find_orders_by_phone( string $phone ): array {
		$variants = array(
			$phone,
			'+84' . substr( $phone, 1 ),
			'84' . substr( $phone, 1 ),
		);

		$found = array();

		foreach ( $variants as $variant ) {
			$orders = wc_get_orders( array(
				'billing_phone' => $variant,
				'limit'         => self::MAX_ORDERS,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'type'          => 'shop_order',
			) );

			foreach ( $orders as $order ) {
				$found[ $order->get_id() ] = $order;
			}
		}

		// Sắp xếp mới nhất trước, giới hạn số lượng.
		uasort( $found, static function ( $a, $b ) {
			$da = $a->get_date_created() ? $a->get_date_created()->getTimestamp() : 0;
			$db = $b->get_date_created() ? $b->get_date_created()->getTimestamp() : 0;
			return $db <=> $da;
		} );

		return array_slice( array_values( $found ), 0, self::MAX_ORDERS );
	}

	/**
	 * Rate limit theo IP: tối đa RATE_LIMIT_MAX lượt / RATE_LIMIT_TTL giây
	 */
	private static function check_rate_limit(): bool {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' === $ip ) {
			return true;
		}

		$key   = 'supership_lookup_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_MAX ) {
			return false;
		}

		set_transient( $key, $count + 1, self::RATE_LIMIT_TTL );
		return true;
	}

	private static function render_form( string $phone_input ): void {
		?>
		<form method="post" class="supership-lookup-form">
			<?php wp_nonce_field( self::NONCE_ACTION, '_supership_lookup_nonce' ); ?>
			<label for="supership-lookup-phone"><?php esc_html_e( 'Nhập số điện thoại đặt hàng', 'supership-woocommerce' ); ?></label>
			<div class="supership-lookup-row">
				<input
					type="tel"
					id="supership-lookup-phone"
					name="supership_lookup_phone"
					value="<?php echo esc_attr( $phone_input ); ?>"
					placeholder="<?php esc_attr_e( 'VD: 0912345678', 'supership-woocommerce' ); ?>"
					autocomplete="tel"
					required
				/>
				<button type="submit"><?php esc_html_e( 'Tra cứu', 'supership-woocommerce' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * @param WC_Order[] $orders Danh sách đơn tìm được
	 */
	private static function render_results( array $orders ): void {
		if ( empty( $orders ) ) {
			echo '<p class="supership-lookup-empty">' . esc_html__( 'Không tìm thấy đơn hàng nào với số điện thoại này.', 'supership-woocommerce' ) . '</p>';
			return;
		}

		?>
		<div class="supership-lookup-results">
			<table>
				<thead>
					<tr>
						<th><?php esc_html_e( 'Mã đơn', 'supership-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Ngày đặt', 'supership-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Trạng thái', 'supership-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Tổng tiền', 'supership-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Vận chuyển', 'supership-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $orders as $order ) : ?>
						<tr>
							<td>#<?php echo esc_html( $order->get_order_number() ); ?></td>
							<td>
								<?php
								$date = $order->get_date_created();
								echo esc_html( $date ? $date->date_i18n( 'd/m/Y' ) : '—' );
								?>
							</td>
							<td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
							<td><?php echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></td>
							<td><?php echo esc_html( self::get_shipping_status( $order ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Trạng thái vận chuyển SuperShip (nếu đã có vận đơn)
	 */
	private static function get_shipping_status( WC_Order $order ): string {
		$tracking = (string) $order->get_meta( '_supership_tracking_number' );
		if ( '' === $tracking ) {
			return __( 'Chưa có vận đơn', 'supership-woocommerce' );
		}

		$status_name = (string) $order->get_meta( '_supership_status_name' );

		return '' !== $status_name
			? sprintf( '%s (%s)', $status_name, $tracking )
			: $tracking;
	}

	private static function render_styles(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
			.supership-lookup-form { max-width: 480px; margin-bottom: 1.5em; }
			.supership-lookup-form label { display: block; font-weight: 600; margin-bottom: .5em; }
			.supership-lookup-row { display: flex; gap: .5em; }
			.supership-lookup-row input { flex: 1; padding: .6em .8em; border: 1px solid #ccc; border-radius: 4px; }
			.supership-lookup-row button { padding: .6em 1.4em; border: 0; border-radius: 4px; background: #2271b1; color: #fff; cursor: pointer; }
			.supership-lookup-row button:hover { background: #135e96; }
			.supership-lookup-error { color: #b32d2e; }
			.supership-lookup-empty { color: #666; }
			.supership-lookup-results { overflow-x: auto; }
			.supership-lookup-results table { width: 100%; border-collapse: collapse; }
			.supership-lookup-results th, .supership-lookup-results td { padding: .6em .8em; border-bottom: 1px solid #e0e0e0; text-align: left; }
			.supership-lookup-results th { background: #f6f7f7; }
		</style>
		<?php
	}
}
