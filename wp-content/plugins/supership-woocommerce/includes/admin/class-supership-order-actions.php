<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Order Actions
 * 
 * Admin order page integration:
 * - Metabox with shipment info
 * - Create shipment button
 * - Print label button
 * - Cancel shipment button
 * - Tracking timeline
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Order_Actions {
	
	/**
	 * Initialize order actions
	 */
	public function init(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_toast_assets' ) );
		// Add metabox
		add_action( 'add_meta_boxes', array( $this, 'add_metabox' ) );
		
		// Handle AJAX actions
		add_action( 'wp_ajax_supership_create_shipment', array( $this, 'ajax_create_shipment' ) );
		add_action( 'wp_ajax_supership_cancel_shipment', array( $this, 'ajax_cancel_shipment' ) );
		add_action( 'wp_ajax_supership_refresh_tracking', array( $this, 'ajax_refresh_tracking' ) );
		
		// Add order actions dropdown
		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_actions' ) );
		add_action( 'woocommerce_order_action_supership_create_shipment', array( $this, 'process_create_shipment' ) );
	}

	/**
	 * Add metabox to order edit page
	 */
	/**
	 * Toast + confirm-dialog assets for the order edit screens (both order
	 * storage modes). The metabox's inline JS uses window.SuperShipToast for
	 * feedback instead of the browser's alert()/confirm().
	 *
	 * @param string $hook Current admin page hook (unused - screen id is checked).
	 */
	public function enqueue_toast_assets( string $hook ): void {
		$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$targets = array( 'shop_order' );

		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$targets[] = wc_get_page_screen_id( 'shop-order' );
		}

		if ( ! $screen || ! in_array( $screen->id, array_unique( $targets ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'supership-toast',
			SUPERSHIP_WC_URL . 'assets/css/supership-toast.css',
			array(),
			SUPERSHIP_WC_VERSION
		);

		wp_enqueue_script(
			'supership-toast',
			SUPERSHIP_WC_URL . 'assets/js/supership-toast.js',
			array(),
			SUPERSHIP_WC_VERSION,
			true
		);
	}

	public function add_metabox(): void {
		add_meta_box(
			'supership-shipment',
			__( 'Vận đơn SuperShip', 'supership-woocommerce' ),
			array( $this, 'render_metabox' ),
			'shop_order',
			'side',
			'high'
		);
		
		// HPOS compatibility
		if ( class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) 
		     && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			add_meta_box(
				'supership-shipment',
				__( 'Vận đơn SuperShip', 'supership-woocommerce' ),
				array( $this, 'render_metabox' ),
				wc_get_page_screen_id( 'shop-order' ),
				'side',
				'high'
			);
		}
	}

	/**
	 * Render the real shipping fee (shop-pays mode) so admin knows the internal cost
	 *
	 * @param WC_Order $order Order object
	 */
	private function render_actual_shipping_fee( WC_Order $order ): void {
		$actual_fee   = null;
		$service_name = '';

		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$fee = $item->get_meta( '_supership_actual_fee' );
			if ( '' !== $fee && null !== $fee ) {
				$actual_fee   = (float) $fee;
				$service_name = (string) $item->get_meta( '_supership_service_name' );
				break;
			}
		}

		if ( null === $actual_fee ) {
			return;
		}

		?>
		<p class="supership-actual-fee" style="background:#fff8e5;border:1px solid #f0c33c;border-radius:4px;padding:8px 10px;">
			<strong><?php esc_html_e( 'Phí ship thực tế (shop chịu):', 'supership-woocommerce' ); ?></strong>
			<?php echo wp_kses_post( wc_price( $actual_fee ) ); ?>
			<?php if ( '' !== $service_name ) : ?>
				<br><small><?php echo esc_html( sprintf( __( 'Gói dịch vụ: %s', 'supership-woocommerce' ), $service_name ) ); ?></small>
			<?php endif; ?>
			<br><small><?php esc_html_e( 'Khách hàng thấy miễn phí vận chuyển ở thanh toán.', 'supership-woocommerce' ); ?></small>
		</p>
		<?php
	}

	/**
	 * Render metabox content
	 *
	 * @param WP_Post|WC_Order $post_or_order Post or Order object
	 */
	public function render_metabox( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		
		if ( ! $order ) {
			return;
		}
		
		$tracking_number = $order->get_meta( '_supership_tracking_number' );
		$has_shipment = ! empty( $tracking_number );
		
		?>
		<div class="supership-metabox" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
			<?php $this->render_actual_shipping_fee( $order ); ?>
			<?php if ( $has_shipment ) : ?>
				<?php $this->render_shipment_info( $order, $tracking_number ); ?>
			<?php else : ?>
				<?php $this->render_create_shipment_form( $order ); ?>
			<?php endif; ?>
		</div>
		
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			var nonce = '<?php echo esc_js( wp_create_nonce( 'supership_order_actions' ) ); ?>';

			// Graceful fallbacks if the toast script somehow didn't load.
			function toast(msg, type)  { window.SuperShipToast ? SuperShipToast.show(msg, type) : window.alert(msg); }
			function flash(msg, type)  { if (window.SuperShipToast) { SuperShipToast.flash(msg, type); } }
			function ask(msg, opts)    {
				return window.SuperShipToast
					? SuperShipToast.confirm(msg, opts)
					: Promise.resolve(window.confirm(msg));
			}

			// Create shipment
			$('.supership-create-shipment').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);

				$btn.prop('disabled', true).text('<?php esc_attr_e( 'Đang tạo...', 'supership-woocommerce' ); ?>');

				$.post(ajaxurl, {
					action: 'supership_create_shipment',
					order_id: $btn.data('order-id'),
					commune: $('.supership-commune-input').val() || '',
					nonce: nonce
				}, function(response) {
					if (response.success) {
						flash('<?php echo esc_js( __( 'Đã tạo vận đơn thành công. Kiểm tra mã vận đơn trong ô SuperShip.', 'supership-woocommerce' ) ); ?>', 'success');
						location.reload();
					} else {
						toast(response.data && response.data.message || '<?php echo esc_js( __( 'Tạo vận đơn thất bại', 'supership-woocommerce' ) ); ?>', 'error');
						$btn.prop('disabled', false).text('<?php esc_attr_e( 'Tạo vận đơn', 'supership-woocommerce' ); ?>');
					}
				}).fail(function() {
					toast('<?php echo esc_js( __( 'Lỗi kết nối - vui lòng thử lại.', 'supership-woocommerce' ) ); ?>', 'error');
					$btn.prop('disabled', false).text('<?php esc_attr_e( 'Tạo vận đơn', 'supership-woocommerce' ); ?>');
				});
			});

			// Cancel shipment
			$('.supership-cancel-shipment').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);

				ask('<?php echo esc_js( __( 'Huỷ vận đơn này? SuperShip sẽ ngừng lấy/giao đơn hàng.', 'supership-woocommerce' ) ); ?>', {
					yes: '<?php echo esc_js( __( 'Huỷ vận đơn', 'supership-woocommerce' ) ); ?>',
					no: '<?php echo esc_js( __( 'Không', 'supership-woocommerce' ) ); ?>',
					danger: true
				}).then(function(confirmed) {
					if (!confirmed) {
						return;
					}

					$btn.prop('disabled', true);

					$.post(ajaxurl, {
						action: 'supership_cancel_shipment',
						order_id: $btn.data('order-id'),
						nonce: nonce
					}, function(response) {
						if (response.success) {
							flash('<?php echo esc_js( __( 'Đã huỷ vận đơn.', 'supership-woocommerce' ) ); ?>', 'success');
							location.reload();
						} else {
							toast(response.data && response.data.message || '<?php echo esc_js( __( 'Huỷ vận đơn thất bại', 'supership-woocommerce' ) ); ?>', 'error');
							$btn.prop('disabled', false);
						}
					}).fail(function() {
						toast('<?php echo esc_js( __( 'Lỗi kết nối - vui lòng thử lại.', 'supership-woocommerce' ) ); ?>', 'error');
						$btn.prop('disabled', false);
					});
				});
			});

			// Refresh tracking
			$('.supership-refresh-tracking').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);

				$btn.prop('disabled', true);

				$.post(ajaxurl, {
					action: 'supership_refresh_tracking',
					order_id: $btn.data('order-id'),
					nonce: nonce
				}, function(response) {
					if (response && response.success) {
						flash('<?php echo esc_js( __( 'Đã cập nhật trạng thái mới nhất từ SuperShip.', 'supership-woocommerce' ) ); ?>', 'success');
						location.reload();
					} else {
						toast(response && response.data && response.data.message || '<?php echo esc_js( __( 'Cập nhật thất bại', 'supership-woocommerce' ) ); ?>', 'error');
						$btn.prop('disabled', false);
					}
				}).fail(function() {
					toast('<?php echo esc_js( __( 'Lỗi kết nối - vui lòng thử lại.', 'supership-woocommerce' ) ); ?>', 'error');
					$btn.prop('disabled', false);
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render shipment info
	 * 
	 * @param WC_Order $order Order object
	 * @param string   $tracking_number Tracking number
	 */
	private function render_shipment_info( WC_Order $order, string $tracking_number ): void {
		$status = $order->get_meta( '_supership_status' );
		$status_name = $order->get_meta( '_supership_status_name' );
		
		?>
		<div class="supership-shipment-info">
			<p>
				<strong><?php esc_html_e( 'Mã vận đơn:', 'supership-woocommerce' ); ?></strong><br>
				<code style="font-size: 14px;"><?php echo esc_html( $tracking_number ); ?></code>
				<button type="button" class="button-link" onclick="navigator.clipboard.writeText('<?php echo esc_js( $tracking_number ); ?>')">
					<?php esc_html_e( 'Sao chép', 'supership-woocommerce' ); ?>
				</button>
			</p>
			
			<?php if ( $status_name ) : ?>
				<p>
					<strong><?php esc_html_e( 'Trạng thái:', 'supership-woocommerce' ); ?></strong><br>
					<span class="supership-status" style="color: <?php echo esc_attr( SuperShip_Status_Mapper::get_status_color( (int) $status ) ); ?>">
						<?php echo esc_html( $status_name ); ?>
					</span>
				</p>
			<?php endif; ?>

			<?php $this->render_journeys_timeline( $order ); ?>

			<p class="supership-actions">
				<a href="<?php echo esc_url( $this->get_label_url( $order ) ); ?>" 
				   class="button" 
				   target="_blank">
					<?php esc_html_e( 'In phiếu gửi', 'supership-woocommerce' ); ?>
				</a>
				
				<button type="button" 
						class="button supership-refresh-tracking" 
						data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
					<?php esc_html_e( 'Cập nhật', 'supership-woocommerce' ); ?>
				</button>
				
				<?php if ( SuperShip_Status_Mapper::get_status_category( (int) $status ) !== 'cancelled' ) : ?>
					<button type="button"
							class="button button-secondary supership-cancel-shipment"
							data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
						<?php esc_html_e( 'Huỷ vận đơn', 'supership-woocommerce' ); ?>
					</button>
				<?php else : ?>
					<button type="button"
							class="button button-primary supership-create-shipment"
							data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
						<?php esc_html_e( 'Tạo vận đơn mới', 'supership-woocommerce' ); ?>
					</button>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render create shipment form
	 * 
	 * @param WC_Order $order Order object
	 */
	private function render_create_shipment_form( WC_Order $order ): void {
		?>
		<div class="supership-create-form">
			<p><?php esc_html_e( 'Chưa tạo vận đơn cho đơn hàng này.', 'supership-woocommerce' ); ?></p>

			<?php $this->render_missing_commune_field( $order ); ?>

			<p>
				<button type="button"
						class="button button-primary supership-create-shipment"
						data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
					<?php esc_html_e( 'Tạo vận đơn', 'supership-woocommerce' ); ?>
				</button>
			</p>
		</div>
		<?php
	}

	/**
	 * When the order has no Phường/Xã (e.g. placed through the block-based
	 * checkout, which doesn't collect it), SuperShip refuses to create the
	 * shipment. Render a commune picker right in the metabox so the admin can
	 * fill it in without leaving the page. Prefers a dropdown of canonical
	 * commune names from the Areas API; falls back to a text input when the
	 * order's province/district can't be resolved.
	 *
	 * @param WC_Order $order Order object
	 */
	private function render_missing_commune_field( WC_Order $order ): void {
		$commune = (string) $order->get_meta( '_shipping_commune' );
		if ( '' !== $commune ) {
			return;
		}

		$communes = array();
		if ( class_exists( 'SuperShip_Address_Repository' ) ) {
			$repo     = new SuperShip_Address_Repository();
			$province = $repo->find_province_by_name( $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state() );
			if ( ! empty( $province['code'] ) ) {
				$district = $repo->find_district_by_name( $province['code'], $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city() );
				if ( ! empty( $district['code'] ) ) {
					$communes = $repo->get_communes( $district['code'] );
				}
			}
		}

		?>
		<p class="supership-missing-commune" style="background:#fcf9e8;border:1px solid #f0c33c;border-radius:4px;padding:8px 10px;">
			<strong><?php esc_html_e( 'Thiếu Phường/Xã người nhận', 'supership-woocommerce' ); ?></strong><br>
			<small><?php esc_html_e( 'SuperShip cần Phường/Xã để tạo vận đơn. Chọn bên dưới rồi bấm Tạo vận đơn.', 'supership-woocommerce' ); ?></small><br>
			<?php if ( ! empty( $communes ) ) : ?>
				<select class="supership-commune-input" style="width:100%;margin-top:6px;">
					<option value=""><?php esc_html_e( '— Chọn Phường/Xã —', 'supership-woocommerce' ); ?></option>
					<?php foreach ( $communes as $c ) : ?>
						<option value="<?php echo esc_attr( $c['name'] ); ?>"><?php echo esc_html( $c['name'] ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input type="text" class="supership-commune-input" style="width:100%;margin-top:6px;"
					placeholder="<?php esc_attr_e( 'VD: Phường Võ Thị Sáu', 'supership-woocommerce' ); ?>" />
			<?php endif; ?>
		</p>
		<?php
	}

	/**
	 * AJAX: Create shipment
	 */
	public function ajax_create_shipment(): void {
		check_ajax_referer( 'supership_order_actions', 'nonce' );
		
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Không tìm thấy đơn hàng', 'supership-woocommerce' ) ) );
		}

		// Commune supplied inline from the metabox (orders from the block
		// checkout don't collect Phường/Xã) - save it before creating.
		$commune = isset( $_POST['commune'] ) ? sanitize_text_field( wp_unslash( $_POST['commune'] ) ) : '';
		if ( '' !== $commune && '' === (string) $order->get_meta( '_shipping_commune' ) ) {
			$order->update_meta_data( '_shipping_commune', $commune );
			$order->save();
		}

		$result = $this->create_shipment_for_order( $order );
		
		if ( $result['success'] ) {
			wp_send_json_success( array( 'message' => __( 'Đã tạo vận đơn thành công', 'supership-woocommerce' ) ) );
		} else {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
	}

	/**
	 * AJAX: Cancel shipment
	 */
	public function ajax_cancel_shipment(): void {
		check_ajax_referer( 'supership_order_actions', 'nonce' );
		
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Không tìm thấy đơn hàng', 'supership-woocommerce' ) ) );
		}
		
		$tracking_number = $order->get_meta( '_supership_tracking_number' );
		
		if ( empty( $tracking_number ) ) {
			wp_send_json_error( array( 'message' => __( 'Đơn này chưa có vận đơn để huỷ', 'supership-woocommerce' ) ) );
		}
		
		$cancel_service = new SuperShip_Cancel_Service();
		$result = $cancel_service->cancel( $tracking_number );
		
		if ( $result['success'] ) {
			// Sync the cached shipment status so the metabox reflects the
			// cancellation immediately (0 = Huỷ in SuperShip_Status_Mapper).
			$order->update_meta_data( '_supership_status', 0 );
			$order->update_meta_data( '_supership_status_name', __( 'Huỷ', 'supership-woocommerce' ) );
			$order->add_order_note( __( 'Đã huỷ vận đơn SuperShip', 'supership-woocommerce' ) );
			$order->save();
			wp_send_json_success( array( 'message' => __( 'Đã huỷ vận đơn thành công', 'supership-woocommerce' ) ) );
		} else {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
	}

	/**
	 * AJAX: Refresh tracking
	 */
	public function ajax_refresh_tracking(): void {
		check_ajax_referer( 'supership_order_actions', 'nonce' );
		
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Không tìm thấy đơn hàng', 'supership-woocommerce' ) ) );
		}
		
		$tracking_number = $order->get_meta( '_supership_tracking_number' );
		
		if ( empty( $tracking_number ) ) {
			wp_send_json_error( array( 'message' => __( 'Đơn này chưa có mã vận đơn', 'supership-woocommerce' ) ) );
		}
		
		$tracking_service = new SuperShip_Tracking_Service();
		$result = $tracking_service->get_tracking( $tracking_number );
		
		if ( $result['success'] ) {
			$tracking = $result['tracking'];
			$order->update_meta_data( '_supership_status', $tracking['status'] );
			$order->update_meta_data( '_supership_status_name', $tracking['status_name'] );
			if ( isset( $tracking['journeys'] ) && is_array( $tracking['journeys'] ) ) {
				$order->update_meta_data( '_supership_journeys', $tracking['journeys'] );
			}
			$order->save();

			wp_send_json_success( array( 'message' => __( 'Đã cập nhật trạng thái vận đơn', 'supership-woocommerce' ) ) );
		} else {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
	}

	/**
	 * Render the SuperShip journey (tracking history) timeline, if we have
	 * one cached from the last successful tracking refresh.
	 *
	 * @param WC_Order $order Order object.
	 */
	private function render_journeys_timeline( WC_Order $order ): void {
		$journeys = $order->get_meta( '_supership_journeys' );

		if ( empty( $journeys ) || ! is_array( $journeys ) ) {
			return;
		}

		?>
		<div class="supership-journeys">
			<strong><?php esc_html_e( 'Lịch trình:', 'supership-woocommerce' ); ?></strong>
			<ul style="margin: 6px 0 12px; padding-left: 18px; border-left: 2px solid #ccd0d4;">
				<?php foreach ( array_reverse( $journeys ) as $event ) : ?>
					<li style="margin-bottom: 8px;">
						<span style="display:block; font-size:12px; color:#666;">
							<?php
							$time = isset( $event['time'] ) ? $event['time'] : '';
							echo esc_html( $time ? mysql2date( 'd/m/Y H:i', gmdate( 'Y-m-d H:i:s', strtotime( $time ) ) ) : '' );
							?>
						</span>
						<strong><?php echo esc_html( isset( $event['status'] ) ? $event['status'] : '' ); ?></strong>
						<?php if ( ! empty( $event['note'] ) ) : ?>
							<br><span style="color:#555;"><?php echo esc_html( $event['note'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $event['district'] ) || ! empty( $event['province'] ) ) : ?>
							<br><span style="color:#999; font-size:12px;">
								<?php echo esc_html( trim( ( $event['district'] ?? '' ) . ', ' . ( $event['province'] ?? '' ), ', ' ) ); ?>
							</span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	/**
	 * Create shipment for order
	 * 
	 * @param WC_Order $order Order object
	 * @return array {success: bool, error?: string}
	 */
	private function create_shipment_for_order( WC_Order $order ): array {
		$shipment_service = new SuperShip_Shipment_Service();
		
		// Build shipment params
		$params = $this->build_shipment_params( $order );
		
		// Create shipment
		$result = $shipment_service->create( $params );
		
		if ( ! $result['success'] ) {
			return $result;
		}
		
		// Save tracking info to order
		$shipment = $result['shipment'];
		$order->update_meta_data( '_supership_tracking_number', $shipment['tracking_number'] );
		$order->update_meta_data( '_supership_status', $shipment['status'] );
		$order->update_meta_data( '_supership_status_name', $shipment['status_name'] );
		$order->save();
		
		// Add order note
		$order->add_order_note( sprintf(
			__( 'Đã tạo vận đơn SuperShip. Mã vận đơn: %s', 'supership-woocommerce' ),
			$shipment['tracking_number']
		) );
		
		return array( 'success' => true );
	}

	/**
	 * Build shipment params from order
	 * 
	 * @param WC_Order $order Order object
	 * @return array Shipment params
	 */
	private function build_shipment_params( WC_Order $order ): array {
		$default_warehouse = get_option( 'supership_default_warehouse', '' );
		
		return array(
			// Receiver
			'receiver_name'     => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
			'receiver_phone'    => $order->get_billing_phone(),
			'receiver_address'  => $order->get_shipping_address_1(),
			'receiver_province' => $order->get_shipping_state(),
			'receiver_district' => $order->get_shipping_city(),
			'receiver_commune'  => $order->get_meta( '_shipping_commune' ), // Need to add commune field to checkout
			
			// Pickup
			'pickup_code'       => $default_warehouse,
			
			// Package
			'weight_grams'      => $this->calculate_order_weight( $order ),
			'declared_value'    => (int) $order->get_total(),
			'cod_amount'        => $order->get_payment_method() === 'cod' ? (int) $order->get_total() : 0,
			
			// Service
			'service'           => get_option( 'supership_default_service', '1' ),
			'config'            => get_option( 'supership_default_config', '1' ),
			'payer'             => get_option( 'supership_default_payer', '1' ),
			'product_type'      => '1',
			'product'           => $this->get_order_items_string( $order ),
			
			// Idempotency
			'soc'               => 'WC-' . $order->get_id(),

			// Optional: large e-commerce partner code (also enables SuperShip's
			// automatic webhook for this order - see class-supership-webhook-handler.php).
			'partner'           => class_exists( 'SuperShip_Auth_Config' ) ? SuperShip_Auth_Config::get_stored_partner_code() : '',
		);
	}

	/**
	 * Calculate order weight
	 * 
	 * @param WC_Order $order Order object
	 * @return int Weight in grams
	 */
	private function calculate_order_weight( WC_Order $order ): int {
		$weight = 0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				// Convert from the store's weight unit to grams (see the same
				// fix in SuperShip_Shipping_Method::calculate_package_weight).
				// Previously hard-coded *1000 (kg), which broke stores set to "g".
				$grams   = wc_get_weight( (float) $product->get_weight(), 'g' );
				$weight += $grams * $item->get_quantity();
			}
		}

		return max( 100, (int) round( $weight ) );
	}

	/**
	 * Get order items as string
	 * 
	 * @param WC_Order $order Order object
	 * @return string Items string
	 */
	private function get_order_items_string( WC_Order $order ): string {
		$items = array();
		
		foreach ( $order->get_items() as $item ) {
			$items[] = $item->get_name() . ' x' . $item->get_quantity();
		}
		
		return implode( ', ', $items );
	}

	/**
	 * Get label print URL
	 * 
	 * @param WC_Order $order Order object
	 * @return string Label URL
	 */
	private function get_label_url( WC_Order $order ): string {
		$tracking_number = $order->get_meta( '_supership_tracking_number' );
		
		if ( empty( $tracking_number ) ) {
			return '';
		}
		
		$label_service = new SuperShip_Label_Service();
		$result = $label_service->get_print_url( array( $tracking_number ) );
		
		return $result['success'] ? $result['print_url'] : '';
	}

	/**
	 * Add order actions to dropdown
	 * 
	 * @param array $actions Existing actions
	 * @return array Modified actions
	 */
	public function add_order_actions( array $actions ): array {
		$actions['supership_create_shipment'] = __( 'Tạo vận đơn SuperShip', 'supership-woocommerce' );
		return $actions;
	}

	/**
	 * Process create shipment action
	 * 
	 * @param WC_Order $order Order object
	 */
	public function process_create_shipment( WC_Order $order ): void {
		$this->create_shipment_for_order( $order );
	}
}
