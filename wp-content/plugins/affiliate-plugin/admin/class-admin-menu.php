<?php
defined( 'ABSPATH' ) || exit;

/**
 * The "Cộng tác viên" admin area: approve affiliates, review commissions and
 * mark payouts as paid.
 *
 * Every screen is gated behind `manage_woocommerce` and every state-changing
 * action is nonce-checked.
 *
 * @package WC_Affiliate
 */
final class WC_Affiliate_Admin_Menu {

	const CAP       = 'manage_woocommerce';
	const SLUG      = 'wc-affiliate';
	const SLUG_REFS = 'wc-affiliate-referrals';
	const SLUG_PAY  = 'wc-affiliate-payouts';

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	/**
	 * Add the top-level menu and its sub-pages.
	 */
	public static function add_menu(): void {
		add_menu_page(
			__( 'Cộng tác viên', 'wc-affiliate' ),
			__( 'Cộng tác viên', 'wc-affiliate' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render_affiliates' ),
			'dashicons-groups',
			56
		);

		add_submenu_page(
			self::SLUG,
			__( 'Danh sách cộng tác viên', 'wc-affiliate' ),
			__( 'Cộng tác viên', 'wc-affiliate' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render_affiliates' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Hoa hồng', 'wc-affiliate' ),
			__( 'Hoa hồng', 'wc-affiliate' ),
			self::CAP,
			self::SLUG_REFS,
			array( __CLASS__, 'render_referrals' )
		);

		add_submenu_page(
			self::SLUG,
			__( 'Chi trả', 'wc-affiliate' ),
			__( 'Chi trả', 'wc-affiliate' ),
			self::CAP,
			self::SLUG_PAY,
			array( __CLASS__, 'render_payouts' )
		);
	}

	/**
	 * Handle approve / disable / mark-paid / CSV export.
	 *
	 * Runs on admin_init because the CSV export has to write headers before any
	 * page output has started.
	 */
	public static function handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked per-action below.
		$action = isset( $_GET['wc_aff_action'] ) ? sanitize_key( wp_unslash( $_GET['wc_aff_action'] ) ) : '';

		if ( '' === $action || ! current_user_can( self::CAP ) ) {
			return;
		}

		check_admin_referer( 'wc_aff_' . $action );

		switch ( $action ) {
			case 'approve':
			case 'disable':
			case 'reactivate':
				$id     = isset( $_GET['affiliate'] ) ? absint( $_GET['affiliate'] ) : 0;
				$status = 'disable' === $action ? WC_Affiliate_Repo::STATUS_DISABLED : WC_Affiliate_Repo::STATUS_ACTIVE;

				if ( $id ) {
					WC_Affiliate_Repo::update_status( $id, $status );
				}

				wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '&updated=1' ) );
				exit;

			case 'mark_paid':
				$id = isset( $_GET['affiliate'] ) ? absint( $_GET['affiliate'] ) : 0;

				if ( $id ) {
					WC_Affiliate_Referral_Repo::mark_affiliate_paid( $id );
				}

				wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG_PAY . '&paid=1' ) );
				exit;

			case 'export_csv':
				self::export_csv();
				exit;
		}
	}

	/**
	 * Stream the pending payouts as a CSV download.
	 */
	private static function export_csv(): void {
		$rows = WC_Affiliate_Referral_Repo::pending_payouts();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=hoa-hong-cho-tra-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );

		// UTF-8 BOM so Excel opens Vietnamese names correctly instead of mojibake.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv(
			$out,
			array(
				__( 'Cộng tác viên', 'wc-affiliate' ),
				__( 'Email', 'wc-affiliate' ),
				__( 'Mã giới thiệu', 'wc-affiliate' ),
				__( 'Số đơn', 'wc-affiliate' ),
				__( 'Tổng hoa hồng (VND)', 'wc-affiliate' ),
			)
		);

		foreach ( $rows as $row ) {
			$affiliate = WC_Affiliate_Repo::get_by_id( (int) $row['affiliate_id'] );
			if ( ! $affiliate ) {
				continue;
			}

			$user = get_userdata( (int) $affiliate['user_id'] );

			fputcsv(
				$out,
				array(
					WC_Affiliate_Repo::display_name( $affiliate ),
					$user ? $user->user_email : '',
					$affiliate['ref_code'],
					(int) $row['referral_count'],
					(int) $row['total'],
				)
			);
		}

		fclose( $out );
	}

	/**
	 * Build a nonce-protected action URL.
	 *
	 * @param string $action Action key.
	 * @param array  $args   Extra query args.
	 * @return string
	 */
	private static function action_url( string $action, array $args = array() ): string {
		$url = add_query_arg(
			array_merge(
				array(
					'page'           => self::SLUG,
					'wc_aff_action'  => $action,
				),
				$args
			),
			admin_url( 'admin.php' )
		);

		return wp_nonce_url( $url, 'wc_aff_' . $action );
	}

	/**
	 * Screen 1: the affiliate list, with approve / disable actions.
	 */
	public static function render_affiliates(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$affiliates = WC_Affiliate_Repo::get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Cộng tác viên', 'wc-affiliate' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Đã cập nhật.', 'wc-affiliate' ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Cộng tác viên mới đăng ký sẽ ở trạng thái "Chờ duyệt" và chưa nhận được hoa hồng. Bấm "Duyệt" để họ bắt đầu hoạt động.', 'wc-affiliate' ); ?>
			</p>

			<?php if ( empty( $affiliates ) ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						$register_url = WC_Affiliate_Activator::get_page_url( 'wc_affiliate_page_register' );
						esc_html_e( 'Chưa có ai đăng ký làm cộng tác viên.', 'wc-affiliate' );
						if ( $register_url ) {
							echo ' ';
							printf(
								/* translators: %s: registration page URL */
								esc_html__( 'Chia sẻ trang đăng ký này cho họ: %s', 'wc-affiliate' ),
								'<a href="' . esc_url( $register_url ) . '" target="_blank">' . esc_html( $register_url ) . '</a>'
							);
						}
						?>
					</p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Cộng tác viên', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Email', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Mã giới thiệu', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Trạng thái', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Chờ chi trả', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Đã trả', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Ngày đăng ký', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Thao tác', 'wc-affiliate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $affiliates as $affiliate ) : ?>
							<?php
							$user   = get_userdata( (int) $affiliate['user_id'] );
							$totals = WC_Affiliate_Referral_Repo::totals( (int) $affiliate['id'] );
							?>
							<tr>
								<td><strong><?php echo esc_html( WC_Affiliate_Repo::display_name( $affiliate ) ); ?></strong></td>
								<td><?php echo esc_html( $user ? $user->user_email : '—' ); ?></td>
								<td><code><?php echo esc_html( $affiliate['ref_code'] ); ?></code></td>
								<td><?php echo esc_html( WC_Affiliate_Repo::status_label( $affiliate['status'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $totals['pending'] ) ); ?></td>
								<td><?php echo wp_kses_post( wc_price( $totals['paid'] ) ); ?></td>
								<td><?php echo esc_html( mysql2date( 'd/m/Y', $affiliate['created_at'] ) ); ?></td>
								<td>
									<?php if ( WC_Affiliate_Repo::STATUS_PENDING === $affiliate['status'] ) : ?>
										<a class="button button-primary button-small"
											href="<?php echo esc_url( self::action_url( 'approve', array( 'affiliate' => $affiliate['id'] ) ) ); ?>">
											<?php esc_html_e( 'Duyệt', 'wc-affiliate' ); ?>
										</a>
									<?php elseif ( WC_Affiliate_Repo::STATUS_ACTIVE === $affiliate['status'] ) : ?>
										<a class="button button-small"
											href="<?php echo esc_url( self::action_url( 'disable', array( 'affiliate' => $affiliate['id'] ) ) ); ?>">
											<?php esc_html_e( 'Khoá', 'wc-affiliate' ); ?>
										</a>
									<?php else : ?>
										<a class="button button-small"
											href="<?php echo esc_url( self::action_url( 'reactivate', array( 'affiliate' => $affiliate['id'] ) ) ); ?>">
											<?php esc_html_e( 'Mở lại', 'wc-affiliate' ); ?>
										</a>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Screen 2: every commission, with filters.
	 */
	public static function render_referrals(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$filter_affiliate = isset( $_GET['affiliate_id'] ) ? absint( $_GET['affiliate_id'] ) : 0;
		$filter_status    = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$filter_from      = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$filter_to        = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		// phpcs:enable

		$referrals  = WC_Affiliate_Referral_Repo::query(
			array(
				'affiliate_id' => $filter_affiliate,
				'status'       => $filter_status,
				'date_from'    => $filter_from,
				'date_to'      => $filter_to,
			)
		);
		$affiliates = WC_Affiliate_Repo::get_all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Hoa hồng', 'wc-affiliate' ); ?></h1>

			<form method="get" style="margin:16px 0;display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG_REFS ); ?>">

				<label>
					<span style="display:block;font-size:12px;"><?php esc_html_e( 'Cộng tác viên', 'wc-affiliate' ); ?></span>
					<select name="affiliate_id">
						<option value="0"><?php esc_html_e( 'Tất cả', 'wc-affiliate' ); ?></option>
						<?php foreach ( $affiliates as $affiliate ) : ?>
							<option value="<?php echo esc_attr( $affiliate['id'] ); ?>" <?php selected( $filter_affiliate, (int) $affiliate['id'] ); ?>>
								<?php echo esc_html( WC_Affiliate_Repo::display_name( $affiliate ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span style="display:block;font-size:12px;"><?php esc_html_e( 'Trạng thái', 'wc-affiliate' ); ?></span>
					<select name="status">
						<option value=""><?php esc_html_e( 'Tất cả', 'wc-affiliate' ); ?></option>
						<?php foreach ( array( WC_Affiliate_Referral_Repo::STATUS_PENDING, WC_Affiliate_Referral_Repo::STATUS_PAID, WC_Affiliate_Referral_Repo::STATUS_VOID ) as $status ) : ?>
							<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $filter_status, $status ); ?>>
								<?php echo esc_html( WC_Affiliate_Referral_Repo::status_label( $status ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</label>

				<label>
					<span style="display:block;font-size:12px;"><?php esc_html_e( 'Từ ngày', 'wc-affiliate' ); ?></span>
					<input type="date" name="date_from" value="<?php echo esc_attr( $filter_from ); ?>">
				</label>

				<label>
					<span style="display:block;font-size:12px;"><?php esc_html_e( 'Đến ngày', 'wc-affiliate' ); ?></span>
					<input type="date" name="date_to" value="<?php echo esc_attr( $filter_to ); ?>">
				</label>

				<button type="submit" class="button"><?php esc_html_e( 'Lọc', 'wc-affiliate' ); ?></button>
			</form>

			<?php if ( empty( $referrals ) ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Chưa có hoa hồng nào khớp bộ lọc.', 'wc-affiliate' ); ?></p></div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Ngày', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Cộng tác viên', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Đơn hàng', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Sản phẩm', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Hoa hồng', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Trạng thái', 'wc-affiliate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $referrals as $referral ) : ?>
							<?php
							$affiliate = WC_Affiliate_Repo::get_by_id( (int) $referral['affiliate_id'] );
							$order     = wc_get_order( (int) $referral['order_id'] );
							$product   = wc_get_product( (int) $referral['product_id'] );
							?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $referral['created_at'] ) ); ?></td>
								<td><?php echo esc_html( $affiliate ? WC_Affiliate_Repo::display_name( $affiliate ) : '—' ); ?></td>
								<td>
									<?php if ( $order ) : ?>
										<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $product ? $product->get_name() : '—' ); ?></td>
								<td><?php echo wp_kses_post( wc_price( (float) $referral['commission_amount'] ) ); ?></td>
								<td><?php echo esc_html( WC_Affiliate_Referral_Repo::status_label( $referral['status'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Screen 3: payouts - unpaid commission grouped by affiliate.
	 */
	public static function render_payouts(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$payouts = WC_Affiliate_Referral_Repo::pending_payouts();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Chi trả hoa hồng', 'wc-affiliate' ); ?></h1>

			<?php if ( ! empty( $payouts ) ) : ?>
				<a class="page-title-action" href="<?php echo esc_url( self::action_url( 'export_csv' ) ); ?>">
					<?php esc_html_e( 'Xuất CSV', 'wc-affiliate' ); ?>
				</a>
			<?php endif; ?>

			<hr class="wp-header-end">

			<?php if ( isset( $_GET['paid'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Đã đánh dấu chi trả xong.', 'wc-affiliate' ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Plugin không tự chuyển tiền. Sau khi bạn chuyển khoản cho cộng tác viên, bấm "Đã trả" để ghi nhận.', 'wc-affiliate' ); ?>
			</p>

			<?php if ( empty( $payouts ) ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'Hiện không có hoa hồng nào chờ chi trả.', 'wc-affiliate' ); ?></p></div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Cộng tác viên', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Email', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Số đơn', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Tổng chờ trả', 'wc-affiliate' ); ?></th>
							<th><?php esc_html_e( 'Thao tác', 'wc-affiliate' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $payouts as $payout ) : ?>
							<?php
							$affiliate = WC_Affiliate_Repo::get_by_id( (int) $payout['affiliate_id'] );
							if ( ! $affiliate ) {
								continue;
							}
							$user = get_userdata( (int) $affiliate['user_id'] );
							?>
							<tr>
								<td><strong><?php echo esc_html( WC_Affiliate_Repo::display_name( $affiliate ) ); ?></strong></td>
								<td><?php echo esc_html( $user ? $user->user_email : '—' ); ?></td>
								<td><?php echo esc_html( number_format_i18n( (int) $payout['referral_count'] ) ); ?></td>
								<td><strong><?php echo wp_kses_post( wc_price( (float) $payout['total'] ) ); ?></strong></td>
								<td>
									<a class="button button-primary button-small"
										href="<?php echo esc_url( self::action_url( 'mark_paid', array( 'affiliate' => $affiliate['id'] ) ) ); ?>"
										onclick="return confirm('<?php echo esc_js( __( 'Xác nhận đã chuyển tiền cho cộng tác viên này?', 'wc-affiliate' ) ); ?>');">
										<?php esc_html_e( 'Đã trả', 'wc-affiliate' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
