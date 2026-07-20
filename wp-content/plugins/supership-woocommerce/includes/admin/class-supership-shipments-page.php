<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Shipments (Vận đơn) admin page.
 *
 * A single place to see and manage every SuperShip shipment. Without this,
 * tracking is only visible one order at a time inside each order's metabox,
 * which makes the everyday question - "đơn nào đang giao, đơn nào đang hoàn?" -
 * require opening orders one by one.
 *
 * SuperShip's own 28 statuses are too granular to scan quickly, so they are
 * folded into 5 groups that match how a shop owner actually thinks about a
 * parcel's life (chờ lấy -> đang vận chuyển -> đang giao -> đã giao -> hoàn/sự cố).
 * The precise SuperShip status name is still shown on each row.
 *
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Shipments_Page {

	const MENU_SLUG   = 'supership-shipments';
	const PER_PAGE    = 20;
	const META_CODE   = '_supership_tracking_number';
	const META_STATUS = '_supership_status';
	const META_NAME   = '_supership_status_name';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_supership_sync_shipments', array( __CLASS__, 'ajax_sync_shipments' ) );
		add_action( 'wp_ajax_supership_print_label', array( __CLASS__, 'ajax_print_label' ) );

		// Make the tracking number searchable - both in this page's search box
		// (which routes through wc_order_search) and in the main Orders list.
		// One filter per order-storage mode; names verified against WC source.
		add_filter(
			'woocommerce_shop_order_search_fields', // Legacy posts table.
			array( __CLASS__, 'add_tracking_to_search_fields' )
		);
		add_filter(
			'woocommerce_order_table_search_query_meta_keys', // HPOS.
			array( __CLASS__, 'add_tracking_to_search_fields' )
		);

		// "Vận đơn" column in the main Orders list, so delivery state is
		// visible without opening each order. Both storage modes: HPOS
		// (wc-orders screen) and legacy posts table.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_orders_list_column' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_orders_list_column' ), 10, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_orders_list_column' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_orders_list_column' ), 10, 2 );
	}

	/**
	 * Let order search match the SuperShip tracking number.
	 *
	 * @param array $fields Meta keys WooCommerce already searches.
	 * @return array
	 */
	public static function add_tracking_to_search_fields( array $fields ): array {
		$fields[] = self::META_CODE;
		return array_unique( $fields );
	}

	/**
	 * AJAX (link, not XHR): resolve the SuperShip print URL for an order and
	 * redirect straight to it - so "In phiếu gửi" works as a plain one-click
	 * link from the shipments list without loading the order screen first.
	 * The print URL comes from a live API call (short-lived token), which is
	 * why rows can't just embed it directly.
	 */
	public static function ajax_print_label(): void {
		check_ajax_referer( 'supership_print_label' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bạn không có quyền thực hiện thao tác này.', 'supership-woocommerce' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		$code     = $order ? (string) $order->get_meta( self::META_CODE ) : '';

		if ( '' === $code ) {
			wp_die( esc_html__( 'Đơn này chưa có mã vận đơn.', 'supership-woocommerce' ) );
		}

		$label_service = new SuperShip_Label_Service();
		$result        = $label_service->get_print_url( array( $code ) );

		if ( empty( $result['success'] ) || empty( $result['print_url'] ) ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %s: error detail */
						__( 'Không lấy được phiếu gửi từ SuperShip: %s', 'supership-woocommerce' ),
						isset( $result['error'] ) ? $result['error'] : __( 'Lỗi không xác định', 'supership-woocommerce' )
					)
				)
			);
		}

		wp_redirect( $result['print_url'] ); // phpcs:ignore WordPress.Security.SafeRedirect -- external SuperShip print URL by design.
		exit;
	}

	/**
	 * One-click print link for a shipment row.
	 *
	 * @param int $order_id Order ID.
	 * @return string
	 */
	private static function get_print_label_link( int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'supership_print_label',
					'order_id' => $order_id,
				),
				admin_url( 'admin-ajax.php' )
			),
			'supership_print_label'
		);
	}

	/**
	 * Insert the "Vận đơn" column right after the order status column.
	 *
	 * @param array $columns Existing list-table columns.
	 * @return array
	 */
	public static function add_orders_list_column( array $columns ): array {
		$updated = array();

		foreach ( $columns as $key => $label ) {
			$updated[ $key ] = $label;
			if ( 'order_status' === $key ) {
				$updated['supership'] = __( 'Vận đơn', 'supership-woocommerce' );
			}
		}

		// Status column missing (customized screens) - append at the end instead.
		if ( ! isset( $updated['supership'] ) ) {
			$updated['supership'] = __( 'Vận đơn', 'supership-woocommerce' );
		}

		return $updated;
	}

	/**
	 * Render one cell of the "Vận đơn" column.
	 *
	 * @param string           $column Column key being rendered.
	 * @param WC_Order|int|null $order  Order object (HPOS) or post ID (legacy).
	 */
	public static function render_orders_list_column( string $column, $order ): void {
		if ( 'supership' !== $column ) {
			return;
		}

		if ( ! $order instanceof WC_Order ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return;
		}

		$code = (string) $order->get_meta( self::META_CODE );

		if ( '' === $code ) {
			echo '<span class="supership-cell-none">—</span>';
			return;
		}

		$status_code = (int) $order->get_meta( self::META_STATUS );
		$status_name = (string) $order->get_meta( self::META_NAME );
		$group       = self::get_group_for_code( $status_code );

		if ( '' === $status_name ) {
			$statuses    = SuperShip_Status_Mapper::get_supership_statuses();
			$status_name = isset( $statuses[ $status_code ] ) ? $statuses[ $status_code ] : __( 'Chưa rõ', 'supership-woocommerce' );
		}

		printf(
			'<span class="supership-badge supership-badge--%1$s">%2$s</span><span class="supership-cell-code">%3$s</span>',
			esc_attr( '' !== $group ? $group : 'unknown' ),
			esc_html( $status_name ),
			esc_html( $code )
		);
	}

	/**
	 * Add the submenu entry under WooCommerce.
	 */
	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Vận đơn SuperShip', 'supership-woocommerce' ),
			__( 'Vận đơn SuperShip', 'supership-woocommerce' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Load styles/scripts on this page only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( string $hook ): void {
		$is_own_page   = 'woocommerce_page_' . self::MENU_SLUG === $hook;
		$is_orders_list = in_array( $hook, array( 'woocommerce_page_wc-orders', 'edit.php' ), true );

		if ( ! $is_own_page && ! $is_orders_list ) {
			return;
		}

		// Legacy orders screen is edit.php for many post types - narrow it down.
		if ( 'edit.php' === $hook ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( ! $screen || 'shop_order' !== $screen->post_type ) {
				return;
			}
		}

		// Both screens need the stylesheet (status badges); only our own page
		// has the sync button that needs the scripts.
		wp_enqueue_style(
			'supership-shipments',
			SUPERSHIP_WC_URL . 'assets/css/admin-shipments.css',
			array(),
			SUPERSHIP_WC_VERSION
		);

		if ( ! $is_own_page ) {
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

		wp_enqueue_script(
			'supership-shipments',
			SUPERSHIP_WC_URL . 'assets/js/admin-shipments.js',
			array( 'jquery', 'supership-toast' ),
			SUPERSHIP_WC_VERSION,
			true
		);

		wp_localize_script(
			'supership-shipments',
			'supershipShipments',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'supership_sync_shipments' ),
				'i18n'    => array(
					'syncing' => __( 'Đang cập nhật...', 'supership-woocommerce' ),
					'failed'  => __( 'Cập nhật thất bại. Vui lòng thử lại.', 'supership-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Status groups, in the order a parcel moves through them.
	 *
	 * @return array {slug => {label, codes}}
	 */
	public static function get_status_groups(): array {
		return array(
			'pending'    => array(
				'label' => __( 'Chờ lấy hàng', 'supership-woocommerce' ),
				'codes' => array( 1, 2, 3, 5 ),
			),
			'transit'    => array(
				'label' => __( 'Đang vận chuyển', 'supership-woocommerce' ),
				'codes' => array( 4, 7, 8, 9, 10, 23 ),
			),
			'delivering' => array(
				'label' => __( 'Đang giao', 'supership-woocommerce' ),
				'codes' => array( 11 ),
			),
			'delivered'  => array(
				'label' => __( 'Đã giao', 'supership-woocommerce' ),
				'codes' => array( 12, 13, 16 ),
			),
			'problem'    => array(
				'label' => __( 'Hoàn / Sự cố', 'supership-woocommerce' ),
				'codes' => array( 0, 6, 14, 15, 17, 18, 19, 20, 21, 22, 24, 25, 26, 27 ),
			),
		);
	}

	/**
	 * Resolve a SuperShip status code to its group slug.
	 *
	 * @param int $code SuperShip status code.
	 * @return string Group slug, or '' when the code is unknown.
	 */
	public static function get_group_for_code( int $code ): string {
		foreach ( self::get_status_groups() as $slug => $group ) {
			if ( in_array( $code, $group['codes'], true ) ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Base meta query: order has a non-empty tracking number, optionally
	 * narrowed to one of our status groups.
	 *
	 * @param array|null $status_codes SuperShip status codes to match, null for all.
	 * @return array
	 */
	private static function build_meta_query( array $status_codes = null ): array {
		$query = array(
			array(
				'key'     => self::META_CODE,
				'compare' => 'EXISTS',
			),
			array(
				'key'     => self::META_CODE,
				'value'   => '',
				'compare' => '!=',
			),
		);

		if ( null !== $status_codes ) {
			$query[] = array(
				'key'     => self::META_STATUS,
				// Meta values live as strings in the DB regardless of what was written.
				'value'   => array_map( 'strval', $status_codes ),
				'compare' => 'IN',
			);
		}

		return $query;
	}

	/**
	 * COUNT(*) of shipments, optionally per status group - a real SQL count,
	 * not a load-everything-and-count. Storage-mode agnostic (HPOS + legacy).
	 *
	 * @param array|null $status_codes Status codes to count, null for all.
	 * @return int
	 */
	private static function count_shipments( array $status_codes = null ): int {
		$result = wc_get_orders(
			array(
				'limit'      => 1,
				'paginate'   => true,
				'return'     => 'ids',
				'meta_query' => self::build_meta_query( $status_codes ),
			)
		);

		return isset( $result->total ) ? (int) $result->total : 0;
	}

	/**
	 * One page of shipments, paginated in SQL.
	 *
	 * @param string $group Group slug filter, '' for all.
	 * @param int    $paged 1-based page number.
	 * @return object {orders: WC_Order[], total: int, total_pages: int}
	 */
	private static function query_shipments_page( string $group, int $paged ) {
		$groups = self::get_status_groups();
		$codes  = isset( $groups[ $group ] ) ? $groups[ $group ]['codes'] : null;

		$result = wc_get_orders(
			array(
				'limit'      => self::PER_PAGE,
				'page'       => $paged,
				'paginate'   => true,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => self::build_meta_query( $codes ),
			)
		);

		return (object) array(
			'orders'      => isset( $result->orders ) ? $result->orders : array(),
			'total'       => isset( $result->total ) ? (int) $result->total : 0,
			'total_pages' => isset( $result->max_num_pages ) ? max( 1, (int) $result->max_num_pages ) : 1,
		);
	}

	/**
	 * Search path: WooCommerce's own order search (billing name, phone,
	 * order number - and, via the search-field filters registered in init(),
	 * the SuperShip tracking number too), then narrowed to shipments and the
	 * active group in PHP. Search result sets are small, so the per-ID loads
	 * here stay cheap; the default (non-search) view never takes this path.
	 *
	 * @param string $search Search term.
	 * @param string $group  Group slug filter, '' for all.
	 * @return WC_Order[]
	 */
	private static function search_shipments( string $search, string $group ): array {
		$ids = wc_order_search( $search );

		if ( empty( $ids ) ) {
			return array();
		}

		$matches = array();
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( '' === (string) $order->get_meta( self::META_CODE ) ) {
				continue;
			}
			if ( '' !== $group && self::get_group_for_code( (int) $order->get_meta( self::META_STATUS ) ) !== $group ) {
				continue;
			}
			$matches[] = $order;
		}

		return $matches;
	}

	/**
	 * Render the page.
	 */
	public static function render_page(): void {
		$groups = self::get_status_groups();

		$active_group = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : '';
		$search       = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged        = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;

		// Summary strip: one SQL COUNT per card - never loads order objects.
		$all_count = self::count_shipments();
		$counts    = array();
		foreach ( $groups as $slug => $group ) {
			$counts[ $slug ] = self::count_shipments( $group['codes'] );
		}

		if ( '' !== $search ) {
			// Search: small ID set from WooCommerce's order search, paged in PHP.
			$found       = self::search_shipments( $search, $active_group );
			$total       = count( $found );
			$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
			$paged       = min( $paged, $total_pages );
			$page_orders = array_slice( $found, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		} else {
			// Default view: paginated in SQL - constant memory however many orders exist.
			$page        = self::query_shipments_page( $active_group, $paged );
			$total       = $page->total;
			$total_pages = $page->total_pages;
			$paged       = min( $paged, $total_pages );
			$page_orders = $page->orders;
		}
		?>
		<div class="wrap supership-shipments">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Vận đơn SuperShip', 'supership-woocommerce' ); ?></h1>
			<button type="button" class="page-title-action" id="supership-sync-all">
				<?php esc_html_e( 'Cập nhật trạng thái', 'supership-woocommerce' ); ?>
			</button>
			<span id="supership-sync-status" class="supership-sync-status"></span>
			<hr class="wp-header-end">

			<?php if ( 0 === $all_count && '' === $search ) : ?>
				<div class="supership-empty">
					<h2><?php esc_html_e( 'Chưa có vận đơn nào', 'supership-woocommerce' ); ?></h2>
					<p>
						<?php esc_html_e( 'Vận đơn sẽ xuất hiện ở đây sau khi bạn tạo vận đơn SuperShip cho một đơn hàng.', 'supership-woocommerce' ); ?>
					</p>
					<p>
						<?php esc_html_e( 'Cách tạo: mở một đơn hàng trong WooCommerce, tìm ô "SuperShip" bên phải và bấm "Tạo vận đơn".', 'supership-woocommerce' ); ?>
					</p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders' ) ); ?>">
						<?php esc_html_e( 'Tới danh sách đơn hàng', 'supership-woocommerce' ); ?>
					</a>
				</div>
			<?php else : ?>

				<div class="supership-summary">
					<?php
					$all_url    = remove_query_arg( array( 'group', 'paged' ) );
					$all_active = '' === $active_group ? ' is-active' : '';
					?>
					<a class="supership-card<?php echo esc_attr( $all_active ); ?>" href="<?php echo esc_url( $all_url ); ?>">
						<span class="supership-card__count"><?php echo esc_html( number_format_i18n( $all_count ) ); ?></span>
						<span class="supership-card__label"><?php esc_html_e( 'Tất cả', 'supership-woocommerce' ); ?></span>
					</a>
					<?php foreach ( $groups as $slug => $group ) : ?>
						<?php
						$url    = add_query_arg( 'group', $slug, remove_query_arg( 'paged' ) );
						$active = $active_group === $slug ? ' is-active' : '';
						?>
						<a class="supership-card supership-card--<?php echo esc_attr( $slug ); ?><?php echo esc_attr( $active ); ?>" href="<?php echo esc_url( $url ); ?>">
							<span class="supership-card__count"><?php echo esc_html( number_format_i18n( $counts[ $slug ] ) ); ?></span>
							<span class="supership-card__label"><?php echo esc_html( $group['label'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</div>

				<form method="get" class="supership-search">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
					<?php if ( '' !== $active_group ) : ?>
						<input type="hidden" name="group" value="<?php echo esc_attr( $active_group ); ?>">
					<?php endif; ?>
					<input
						type="search"
						name="s"
						value="<?php echo esc_attr( $search ); ?>"
						placeholder="<?php esc_attr_e( 'Tìm mã vận đơn, tên khách, số điện thoại...', 'supership-woocommerce' ); ?>"
					>
					<button type="submit" class="button"><?php esc_html_e( 'Tìm', 'supership-woocommerce' ); ?></button>
					<?php if ( '' !== $search ) : ?>
						<a class="button-link" href="<?php echo esc_url( remove_query_arg( array( 's', 'paged' ) ) ); ?>">
							<?php esc_html_e( 'Xoá tìm kiếm', 'supership-woocommerce' ); ?>
						</a>
					<?php endif; ?>
				</form>

				<?php if ( empty( $page_orders ) ) : ?>
					<div class="supership-empty supership-empty--small">
						<p><?php esc_html_e( 'Không tìm thấy vận đơn nào khớp với bộ lọc.', 'supership-woocommerce' ); ?></p>
					</div>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped supership-table">
						<thead>
							<tr>
								<th class="col-order"><?php esc_html_e( 'Đơn hàng', 'supership-woocommerce' ); ?></th>
								<th class="col-customer"><?php esc_html_e( 'Người nhận', 'supership-woocommerce' ); ?></th>
								<th class="col-code"><?php esc_html_e( 'Mã vận đơn', 'supership-woocommerce' ); ?></th>
								<th class="col-status"><?php esc_html_e( 'Trạng thái', 'supership-woocommerce' ); ?></th>
								<th class="col-total"><?php esc_html_e( 'Giá trị đơn', 'supership-woocommerce' ); ?></th>
								<th class="col-date"><?php esc_html_e( 'Ngày tạo', 'supership-woocommerce' ); ?></th>
								<th class="col-actions"><?php esc_html_e( 'Thao tác', 'supership-woocommerce' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $page_orders as $order ) : ?>
								<?php self::render_row( $order ); ?>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php self::render_pagination( $paged, $total_pages, $total ); ?>
				<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render one table row.
	 *
	 * @param WC_Order $order Order to render.
	 */
	private static function render_row( WC_Order $order ): void {
		$code        = (string) $order->get_meta( self::META_CODE );
		$status_code = (int) $order->get_meta( self::META_STATUS );
		$status_name = (string) $order->get_meta( self::META_NAME );
		$group       = self::get_group_for_code( $status_code );
		$groups      = self::get_status_groups();

		if ( '' === $status_name ) {
			$statuses    = SuperShip_Status_Mapper::get_supership_statuses();
			$status_name = isset( $statuses[ $status_code ] ) ? $statuses[ $status_code ] : __( 'Chưa rõ', 'supership-woocommerce' );
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$date = $order->get_date_created();
		?>
		<tr>
			<td class="col-order">
				<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
					<strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong>
				</a>
			</td>
			<td class="col-customer">
				<?php echo esc_html( '' !== $name ? $name : __( '(không có tên)', 'supership-woocommerce' ) ); ?>
				<?php if ( $order->get_billing_phone() ) : ?>
					<span class="supership-phone"><?php echo esc_html( $order->get_billing_phone() ); ?></span>
				<?php endif; ?>
			</td>
			<td class="col-code">
				<code><?php echo esc_html( $code ); ?></code>
			</td>
			<td class="col-status">
				<span class="supership-badge supership-badge--<?php echo esc_attr( '' !== $group ? $group : 'unknown' ); ?>">
					<?php echo esc_html( $status_name ); ?>
				</span>
				<?php if ( isset( $groups[ $group ] ) ) : ?>
					<span class="supership-group"><?php echo esc_html( $groups[ $group ]['label'] ); ?></span>
				<?php endif; ?>
			</td>
			<td class="col-total"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
			<td class="col-date">
				<?php echo esc_html( $date ? $date->date_i18n( 'd/m/Y H:i' ) : '—' ); ?>
			</td>
			<td class="col-actions">
				<a class="button button-small" href="<?php echo esc_url( self::get_print_label_link( $order->get_id() ) ); ?>" target="_blank">
					<?php esc_html_e( 'In phiếu gửi', 'supership-woocommerce' ); ?>
				</a>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render the pager.
	 *
	 * @param int $paged       Current page.
	 * @param int $total_pages Total pages.
	 * @param int $total       Total rows.
	 */
	private static function render_pagination( int $paged, int $total_pages, int $total ): void {
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: number of shipments */
						esc_html( _n( '%s vận đơn', '%s vận đơn', $total, 'supership-woocommerce' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
				<?php if ( $total_pages > 1 ) : ?>
					<span class="pagination-links">
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $paged,
								)
							)
						);
						?>
					</span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX: refresh tracking for every open shipment.
	 *
	 * Reuses the scheduler's sync so the manual button and the cron job can
	 * never drift apart in behaviour.
	 */
	public static function ajax_sync_shipments(): void {
		check_ajax_referer( 'supership_sync_shipments', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này.', 'supership-woocommerce' ) ) );
		}

		if ( ! class_exists( 'SuperShip_Tracking_Scheduler' ) ) {
			wp_send_json_error( array( 'message' => __( 'Không tìm thấy bộ đồng bộ theo dõi đơn.', 'supership-woocommerce' ) ) );
		}

		SuperShip_Tracking_Scheduler::sync();

		wp_send_json_success( array( 'message' => __( 'Đã cập nhật xong.', 'supership-woocommerce' ) ) );
	}
}
