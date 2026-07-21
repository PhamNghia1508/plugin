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
	const PAGE_OPTION    = 'supership_order_lookup_page_id';
	const RATE_LIMIT_MAX = 10;   // lượt tra cứu tối đa...
	const RATE_LIMIT_TTL = 900;  // ...trong 15 phút, tính theo IP.
	const MAX_ORDERS     = 20;

	public static function init(): void {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Tạo sẵn trang "Tra cứu đơn hàng" (chứa shortcode) khi kích hoạt plugin,
	 * để chủ shop KHÔNG phải tự tạo trang / dán shortcode thủ công.
	 *
	 * Idempotent: lưu page ID vào option, lần kích hoạt sau kiểm tra trang còn
	 * tồn tại (chưa bị xoá vĩnh viễn) thì thôi. Nếu trang bị chuyển vào thùng
	 * rác thì khôi phục lại; nếu bị xoá hẳn thì tạo trang mới.
	 *
	 * @return int ID trang tra cứu (0 nếu không tạo được).
	 */
	public static function install(): int {
		$existing_id = (int) get_option( self::PAGE_OPTION, 0 );

		if ( $existing_id > 0 ) {
			$page = get_post( $existing_id );
			if ( $page && 'page' === $page->post_type ) {
				// wp_untrash_post() khôi phục về 'draft' (hoặc trạng thái trước
				// khi xoá), không phải 'publish' - nên phải publish lại thủ công,
				// nếu không trang khôi phục vẫn ẩn với khách.
				if ( in_array( $page->post_status, array( 'trash', 'draft', 'pending' ), true ) ) {
					if ( 'trash' === $page->post_status ) {
						wp_untrash_post( $existing_id );
					}
					wp_update_post(
						array(
							'ID'          => $existing_id,
							'post_status' => 'publish',
						)
					);
				}
				return $existing_id;
			}
		}

		// Có thể chủ shop đã tự tạo trang chứa shortcode - dùng lại thay vì tạo trùng.
		$found = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				's'              => '[' . self::SHORTCODE . ']',
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $found ) ) {
			update_option( self::PAGE_OPTION, (int) $found[0] );
			return (int) $found[0];
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'Tra cứu đơn hàng', 'supership-woocommerce' ),
				'post_name'    => 'tra-cuu-don-hang',
				'post_content' => '[' . self::SHORTCODE . ']',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			)
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( self::PAGE_OPTION, (int) $page_id );
			return (int) $page_id;
		}

		return 0;
	}

	/**
	 * URL trang tra cứu (để dùng ở admin/thông báo). '' nếu chưa có.
	 */
	public static function get_page_url(): string {
		$page_id = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
			return (string) get_permalink( $page_id );
		}
		return '';
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
		<div class="supership-lookup-hero">
			<span class="supership-lookup-hero__icon">📦</span>
			<p class="supership-lookup-hero__text">
				<?php esc_html_e( 'Nhập số điện thoại bạn đã dùng khi đặt hàng để xem tình trạng giao hàng mới nhất.', 'supership-woocommerce' ); ?>
			</p>
		</div>

		<form method="post" class="supership-lookup-form">
			<?php wp_nonce_field( self::NONCE_ACTION, '_supership_lookup_nonce' ); ?>
			<label for="supership-lookup-phone"><?php esc_html_e( 'Số điện thoại đặt hàng', 'supership-woocommerce' ); ?></label>
			<div class="supership-lookup-row">
				<input
					type="tel"
					id="supership-lookup-phone"
					name="supership_lookup_phone"
					value="<?php echo esc_attr( $phone_input ); ?>"
					placeholder="<?php esc_attr_e( 'VD: 0912 345 678', 'supership-woocommerce' ); ?>"
					autocomplete="tel"
					inputmode="tel"
					required
				/>
				<button type="submit"><?php esc_html_e( 'Tra cứu', 'supership-woocommerce' ); ?></button>
			</div>
		</form>
		<?php
	}

	/**
	 * Kết quả tra cứu: mỗi đơn một thẻ (card) thay vì bảng - đọc dễ hơn hẳn
	 * trên điện thoại (đa số khách tra cứu bằng điện thoại) và không bao giờ
	 * bị tràn/cuộn ngang như bảng.
	 *
	 * @param WC_Order[] $orders Danh sách đơn tìm được
	 */
	private static function render_results( array $orders ): void {
		if ( empty( $orders ) ) {
			?>
			<div class="supership-lookup-empty">
				<span class="supership-lookup-empty__icon">🔍</span>
				<p><?php esc_html_e( 'Không tìm thấy đơn hàng nào với số điện thoại này.', 'supership-woocommerce' ); ?></p>
				<p class="supership-lookup-empty__hint"><?php esc_html_e( 'Kiểm tra lại số điện thoại bạn đã dùng khi đặt hàng nhé.', 'supership-woocommerce' ); ?></p>
			</div>
			<?php
			return;
		}

		?>
		<div class="supership-lookup-results">
			<p class="supership-lookup-count">
				<?php
				printf(
					/* translators: %s: number of orders found */
					esc_html( _n( 'Tìm thấy %s đơn hàng', 'Tìm thấy %s đơn hàng', count( $orders ), 'supership-woocommerce' ) ),
					esc_html( number_format_i18n( count( $orders ) ) )
				);
				?>
			</p>
			<?php foreach ( $orders as $order ) : ?>
				<?php self::render_order_card( $order ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Một thẻ đơn hàng trong kết quả tra cứu.
	 *
	 * @param WC_Order $order Đơn hàng
	 */
	private static function render_order_card( WC_Order $order ): void {
		$date     = $order->get_date_created();
		$tracking = (string) $order->get_meta( '_supership_tracking_number' );
		$current  = self::resolve_current_status( $order, $tracking );
		?>
		<div class="supership-lookup-card">
			<div class="supership-lookup-card__head">
				<strong class="supership-lookup-card__number">#<?php echo esc_html( $order->get_order_number() ); ?></strong>
				<span class="supership-lookup-card__date">
					<?php echo esc_html( $date ? $date->date_i18n( 'd/m/Y' ) : '—' ); ?>
				</span>
			</div>

			<?php self::render_order_products( $order ); ?>

			<div class="supership-lookup-card__row">
				<span class="supership-lookup-card__label"><?php esc_html_e( 'Vận chuyển', 'supership-woocommerce' ); ?></span>
				<span class="supership-lookup-badge supership-lookup-badge--<?php echo esc_attr( $current['tone'] ); ?>">
					<?php echo esc_html( $current['name'] ); ?>
				</span>
			</div>

			<?php if ( '' !== $tracking ) : ?>
				<div class="supership-lookup-card__row">
					<span class="supership-lookup-card__label"><?php esc_html_e( 'Mã vận đơn', 'supership-woocommerce' ); ?></span>
					<code class="supership-lookup-card__code"><?php echo esc_html( $tracking ); ?></code>
				</div>
			<?php endif; ?>

			<div class="supership-lookup-card__row">
				<span class="supership-lookup-card__label"><?php esc_html_e( 'Trạng thái đơn', 'supership-woocommerce' ); ?></span>
				<span><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span>
			</div>

			<div class="supership-lookup-card__row supership-lookup-card__row--total">
				<span class="supership-lookup-card__label"><?php esc_html_e( 'Tổng tiền', 'supership-woocommerce' ); ?></span>
				<span class="supership-lookup-card__total"><?php echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></span>
			</div>

			<?php self::render_journey( $order ); ?>
		</div>
		<?php
	}

	/**
	 * Danh sách sản phẩm trong đơn (tên + số lượng + ảnh nhỏ) để khách nhận ra
	 * ngay đơn nào là đơn nào khi có nhiều đơn.
	 *
	 * @param WC_Order $order Đơn hàng.
	 */
	private static function render_order_products( WC_Order $order ): void {
		$items = $order->get_items();

		if ( empty( $items ) ) {
			return;
		}
		?>
		<ul class="supership-lookup-products">
			<?php foreach ( $items as $item ) : ?>
				<?php
				$product = $item->get_product();
				$thumb   = ( $product && $product->get_image_id() ) ? $product->get_image( array( 44, 44 ) ) : '';
				?>
				<li class="supership-lookup-product">
					<?php if ( $thumb ) : ?>
						<span class="supership-lookup-product__thumb"><?php echo wp_kses_post( $thumb ); ?></span>
					<?php endif; ?>
					<span class="supership-lookup-product__name"><?php echo esc_html( $item->get_name() ); ?></span>
					<span class="supership-lookup-product__qty">×<?php echo esc_html( $item->get_quantity() ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Hành trình đơn hàng (đơn đang ở đâu) - hiện dạng gập/mở để thẻ gọn khi
	 * chưa cần, mở ra là thấy timeline từng chặng: thời gian, trạng thái, khu
	 * vực (Quận/Huyện - Tỉnh/Thành). Chỉ hiện khi đã có dữ liệu hành trình từ
	 * SuperShip (sau khi vận đơn được tạo và cập nhật ít nhất một lần).
	 *
	 * @param WC_Order $order Đơn hàng.
	 */
	private static function render_journey( WC_Order $order ): void {
		$journeys = $order->get_meta( '_supership_journeys' );

		if ( empty( $journeys ) || ! is_array( $journeys ) ) {
			return;
		}

		// Mới nhất lên đầu.
		$journeys = array_reverse( $journeys );
		?>
		<details class="supership-lookup-journey">
			<summary>
				<?php esc_html_e( 'Xem hành trình đơn hàng', 'supership-woocommerce' ); ?>
				<span class="supership-lookup-journey__count"><?php echo esc_html( sprintf( '(%d)', count( $journeys ) ) ); ?></span>
			</summary>
			<ol class="supership-lookup-timeline">
				<?php foreach ( $journeys as $index => $event ) : ?>
					<li class="supership-lookup-timeline__item<?php echo 0 === $index ? ' is-current' : ''; ?>">
						<span class="supership-lookup-timeline__dot" aria-hidden="true"></span>
						<div class="supership-lookup-timeline__body">
							<strong class="supership-lookup-timeline__status"><?php echo esc_html( isset( $event['status'] ) ? $event['status'] : '' ); ?></strong>
							<?php
							$time = isset( $event['time'] ) ? $event['time'] : '';
							if ( '' !== $time ) :
								$ts = strtotime( $time );
								?>
								<span class="supership-lookup-timeline__time"><?php echo esc_html( $ts ? wp_date( 'H:i - d/m/Y', $ts ) : $time ); ?></span>
							<?php endif; ?>
							<?php
							$place = trim( ( isset( $event['district'] ) ? $event['district'] : '' ) . ', ' . ( isset( $event['province'] ) ? $event['province'] : '' ), ', ' );
							if ( '' !== $place ) :
								?>
								<span class="supership-lookup-timeline__place">📍 <?php echo esc_html( $place ); ?></span>
							<?php endif; ?>
							<?php if ( ! empty( $event['note'] ) ) : ?>
								<span class="supership-lookup-timeline__note"><?php echo esc_html( $event['note'] ); ?></span>
							<?php endif; ?>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</details>
		<?php
	}

	/**
	 * Trạng thái hiển thị trên badge headline.
	 *
	 * Ưu tiên CHẶNG MỚI NHẤT trong hành trình (đúng cái khách thấy ở dòng đầu
	 * timeline ngay bên dưới), thay vì trạng thái tổng của SuperShip. Lý do:
	 * trạng thái tổng đôi khi "chạy trước" chặng thực tế - vd tổng trả về
	 * "Đang Vận Chuyển" (đơn đã vào hệ thống) trong khi chặng mới nhất mới là
	 * "Chờ Lấy Hàng" - khiến badge và timeline đá nhau, gây khó hiểu cho khách.
	 *
	 * @param WC_Order $order    Đơn hàng.
	 * @param string   $tracking Mã vận đơn ('' nếu chưa có).
	 * @return array{name:string,tone:string}
	 */
	private static function resolve_current_status( WC_Order $order, string $tracking ): array {
		if ( '' === $tracking ) {
			return array(
				'name' => __( 'Đang chuẩn bị hàng', 'supership-woocommerce' ),
				'tone' => 'none',
			);
		}

		$name = '';
		$code = (int) $order->get_meta( '_supership_status' );

		// Chặng mới nhất: mảng journeys lưu cũ -> mới, nên phần tử cuối là mới nhất.
		$journeys = $order->get_meta( '_supership_journeys' );
		if ( is_array( $journeys ) && ! empty( $journeys ) ) {
			$latest = end( $journeys );
			if ( is_array( $latest ) && ! empty( $latest['status'] ) ) {
				$name = (string) $latest['status'];
				// Suy ngược ra mã trạng thái từ tên chặng để lấy đúng màu badge.
				if ( class_exists( 'SuperShip_Status_Mapper' ) ) {
					$match = array_search( $name, SuperShip_Status_Mapper::get_supership_statuses(), true );
					if ( false !== $match ) {
						$code = (int) $match;
					}
				}
			}
		}

		// Không có hành trình -> dùng trạng thái tổng đã lưu.
		if ( '' === $name ) {
			$name = (string) $order->get_meta( '_supership_status_name' );
			if ( '' === $name ) {
				$name = __( 'Đã tạo vận đơn', 'supership-woocommerce' );
			}
		}

		return array(
			'name' => $name,
			'tone' => self::tone_from_code( $code ),
		);
	}

	/**
	 * Tông màu badge từ mã trạng thái SuperShip: xanh lá = đã giao, vàng =
	 * đang giao, đỏ = huỷ/hoàn/sự cố, xanh dương = đang trên đường.
	 *
	 * @param int $code Mã trạng thái SuperShip.
	 * @return string Hậu tố class CSS.
	 */
	private static function tone_from_code( int $code ): string {
		if ( ! class_exists( 'SuperShip_Status_Mapper' ) ) {
			return 'transit';
		}

		switch ( SuperShip_Status_Mapper::get_status_category( $code ) ) {
			case 'delivered':
				return 'delivered';
			case 'delivering':
				return 'delivering';
			case 'cancelled':
			case 'failed':
			case 'returned':
				return 'problem';
			default:
				return 'transit';
		}
	}

	private static function render_styles(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
			/* Cùng hệ thống thiết kế với checkout-modern.css (đỏ thương hiệu, thẻ bo góc). */
			.supership-lookup-hero,
			.supership-lookup-form,
			.supership-lookup-results,
			.supership-lookup-error,
			.supership-lookup-empty { max-width: 560px; margin-left: auto; margin-right: auto; }

			.supership-lookup-hero {
				display: flex; align-items: center; gap: 12px;
				background: #fdecef; border: 1px solid #f6c9d1; border-radius: 12px;
				padding: 14px 18px; margin-bottom: 20px;
			}
			.supership-lookup-hero__icon { font-size: 26px; line-height: 1; }
			.supership-lookup-hero__text { margin: 0; font-size: 14.5px; color: #1a1f27; line-height: 1.5; }

			.supership-lookup-form { margin-bottom: 24px; }
			.supership-lookup-form label {
				display: block; font-weight: 600; font-size: 14px;
				color: #1a1f27; margin-bottom: 8px;
			}
			.supership-lookup-row { display: flex; gap: 10px; }
			.supership-lookup-row input {
				flex: 1; min-width: 0; padding: 13px 16px; font-size: 15px;
				border: 1.5px solid #e5e7eb; border-radius: 8px;
				transition: border-color .15s ease, box-shadow .15s ease;
			}
			.supership-lookup-row input:focus {
				outline: none; border-color: #c8102e;
				box-shadow: 0 0 0 3px rgba(200, 16, 46, .12);
			}
			.supership-lookup-row button {
				padding: 13px 26px; border: 0; border-radius: 8px;
				background: #c8102e; color: #fff; font-size: 15px; font-weight: 700;
				cursor: pointer; transition: background-color .15s ease;
				white-space: nowrap;
			}
			.supership-lookup-row button:hover { background: #a10d25; }

			.supership-lookup-error {
				background: #fdecea; border: 1px solid #f5c6cb; color: #611a15;
				border-radius: 8px; padding: 13px 18px;
			}

			.supership-lookup-empty {
				text-align: center; background: #fff; border: 1px solid #e5e7eb;
				border-radius: 12px; padding: 36px 24px;
				box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 4px 14px rgba(16, 24, 40, .06);
			}
			.supership-lookup-empty__icon { font-size: 34px; display: block; margin-bottom: 8px; }
			.supership-lookup-empty p { margin: 0 0 4px; color: #1a1f27; font-weight: 600; }
			.supership-lookup-empty .supership-lookup-empty__hint { color: #6b7280; font-weight: 400; font-size: 13.5px; }

			.supership-lookup-count { color: #6b7280; font-size: 13.5px; margin: 0 0 12px; }

			.supership-lookup-card {
				background: #fff; border: 1px solid #e5e7eb; border-radius: 12px;
				box-shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 4px 14px rgba(16, 24, 40, .06);
				padding: 18px 20px 8px; margin-bottom: 14px;
			}
			.supership-lookup-card__head {
				display: flex; justify-content: space-between; align-items: baseline;
				padding-bottom: 12px; margin-bottom: 4px; border-bottom: 1.5px solid #f0f0f1;
			}
			.supership-lookup-card__number { font-size: 17px; color: #1a1f27; }
			.supership-lookup-card__date { color: #6b7280; font-size: 13px; }

			.supership-lookup-products { list-style: none; margin: 10px 0 4px; padding: 0; }
			.supership-lookup-product {
				display: flex; align-items: center; gap: 10px;
				padding: 6px 0; font-size: 13.5px; color: #1a1f27;
			}
			.supership-lookup-product__thumb { flex-shrink: 0; line-height: 0; }
			.supership-lookup-product__thumb img {
				width: 44px; height: 44px; object-fit: cover;
				border-radius: 8px; border: 1px solid #e5e7eb; display: block;
			}
			.supership-lookup-product__name { flex: 1; line-height: 1.4; }
			.supership-lookup-product__qty { flex-shrink: 0; color: #6b7280; font-weight: 600; }

			.supership-lookup-card__row {
				display: flex; justify-content: space-between; align-items: center;
				gap: 12px; padding: 9px 0; font-size: 14px; color: #1a1f27;
			}
			.supership-lookup-card__label { color: #6b7280; font-size: 13px; flex-shrink: 0; }
			.supership-lookup-card__code {
				background: #f0f0f1; border-radius: 5px; padding: 3px 8px;
				font-size: 12.5px; word-break: break-all; text-align: right;
			}
			.supership-lookup-card__row--total { border-top: 1.5px solid #f0f0f1; margin-top: 2px; }
			.supership-lookup-card__total { font-size: 17px; font-weight: 800; color: #c8102e; }

			.supership-lookup-badge {
				display: inline-block; padding: 4px 12px; border-radius: 999px;
				font-size: 12.5px; font-weight: 700; line-height: 1.5; text-align: right;
			}
			.supership-lookup-badge--none       { background: #f0f0f1; color: #3c434a; }
			.supership-lookup-badge--transit    { background: #e5f0f8; color: #135e96; }
			.supership-lookup-badge--delivering { background: #fcf3d8; color: #8a6116; }
			.supership-lookup-badge--delivered  { background: #e9f7ee; color: #1e7e34; }
			.supership-lookup-badge--problem    { background: #fcebea; color: #b32d2e; }

			/* ── Hành trình đơn hàng (đơn đang ở đâu) ─────────────────── */
			.supership-lookup-journey { margin-top: 4px; border-top: 1.5px solid #f0f0f1; }
			.supership-lookup-journey summary {
				list-style: none; cursor: pointer; user-select: none;
				padding: 12px 0 10px; font-size: 13.5px; font-weight: 600; color: #c8102e;
				display: flex; align-items: center; gap: 6px;
			}
			.supership-lookup-journey summary::-webkit-details-marker { display: none; }
			.supership-lookup-journey summary::before {
				content: "▸"; font-size: 11px; transition: transform .15s ease; color: #c8102e;
			}
			.supership-lookup-journey[open] summary::before { transform: rotate(90deg); }
			.supership-lookup-journey__count { color: #9ca3af; font-weight: 400; }

			.supership-lookup-timeline { list-style: none; margin: 4px 0 14px; padding: 0; }
			.supership-lookup-timeline__item {
				position: relative; padding: 0 0 16px 22px;
				border-left: 2px solid #e5e7eb;
			}
			.supership-lookup-timeline__item:last-child { border-left-color: transparent; padding-bottom: 2px; }
			.supership-lookup-timeline__dot {
				position: absolute; left: -7px; top: 2px;
				width: 12px; height: 12px; border-radius: 50%;
				background: #c3c4c7; border: 2px solid #fff; box-shadow: 0 0 0 1px #e5e7eb;
			}
			.supership-lookup-timeline__item.is-current .supership-lookup-timeline__dot {
				background: #c8102e; box-shadow: 0 0 0 3px rgba(200, 16, 46, .18);
			}
			.supership-lookup-timeline__body { display: flex; flex-direction: column; gap: 2px; }
			.supership-lookup-timeline__status { font-size: 14px; color: #1a1f27; }
			.supership-lookup-timeline__item.is-current .supership-lookup-timeline__status { color: #c8102e; }
			.supership-lookup-timeline__time { font-size: 12.5px; color: #6b7280; }
			.supership-lookup-timeline__place { font-size: 13px; color: #374151; }
			.supership-lookup-timeline__note { font-size: 12.5px; color: #6b7280; }

			@media (max-width: 480px) {
				.supership-lookup-row { flex-direction: column; }
				.supership-lookup-row button { width: 100%; }
			}
		</style>
		<?php
	}
}
