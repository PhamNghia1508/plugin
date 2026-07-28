<?php
defined( 'ABSPATH' ) || exit;

/**
 * The three front-end screens: register, login, dashboard.
 *
 * Form submissions are processed on `template_redirect` rather than inside the
 * shortcode callbacks. That matters: wp_signon() sets auth cookies and both
 * flows end in a redirect, neither of which works once the theme has started
 * sending output.
 *
 * @package WC_Affiliate
 */
final class WC_Affiliate_Shortcodes {

	/** @var WP_Error|null Errors from the current request's form submission. */
	private static $errors = null;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_shortcode( 'affiliate_register', array( __CLASS__, 'render_register' ) );
		add_shortcode( 'affiliate_login', array( __CLASS__, 'render_login' ) );
		add_shortcode( 'affiliate_dashboard', array( __CLASS__, 'render_dashboard' ) );

		add_action( 'template_redirect', array( __CLASS__, 'handle_forms' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Load CSS/JS only on the plugin's own pages.
	 */
	public static function enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();
		if ( ! $post ) {
			return;
		}

		$has_shortcode = has_shortcode( $post->post_content, 'affiliate_dashboard' )
			|| has_shortcode( $post->post_content, 'affiliate_login' )
			|| has_shortcode( $post->post_content, 'affiliate_register' );

		if ( ! $has_shortcode ) {
			return;
		}

		wp_enqueue_style(
			'wc-affiliate',
			WC_AFFILIATE_URL . 'assets/css/affiliate.css',
			array(),
			WC_AFFILIATE_VERSION
		);

		wp_enqueue_script(
			'wc-affiliate',
			WC_AFFILIATE_URL . 'assets/js/affiliate.js',
			array(),
			WC_AFFILIATE_VERSION,
			true
		);

		wp_localize_script(
			'wc-affiliate',
			'wcAffiliateI18n',
			array(
				'copied'    => __( 'Đã sao chép!', 'wc-affiliate' ),
				'copyError' => __( 'Không sao chép được, vui lòng chọn và copy thủ công.', 'wc-affiliate' ),
			)
		);
	}

	/**
	 * Process the register / login forms before any output.
	 */
	public static function handle_forms(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked per-form below.
		$form = isset( $_POST['wc_aff_form'] ) ? sanitize_key( wp_unslash( $_POST['wc_aff_form'] ) ) : '';

		if ( 'register' === $form ) {
			self::process_register();
		} elseif ( 'login' === $form ) {
			self::process_login();
		}
	}

	/**
	 * Create the WordPress user plus a pending affiliate record.
	 */
	private static function process_register(): void {
		if ( ! isset( $_POST['wc_aff_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wc_aff_nonce'] ) ), 'wc_aff_register' ) ) {
			self::$errors = new WP_Error( 'nonce', __( 'Phiên làm việc đã hết hạn, vui lòng tải lại trang và thử lại.', 'wc-affiliate' ) );
			return;
		}

		$name     = isset( $_POST['aff_name'] ) ? sanitize_text_field( wp_unslash( $_POST['aff_name'] ) ) : '';
		$email    = isset( $_POST['aff_email'] ) ? sanitize_email( wp_unslash( $_POST['aff_email'] ) ) : '';
		$password = isset( $_POST['aff_password'] ) ? (string) wp_unslash( $_POST['aff_password'] ) : '';

		$errors = new WP_Error();

		if ( '' === $name ) {
			$errors->add( 'name', __( 'Vui lòng nhập họ tên.', 'wc-affiliate' ) );
		}
		if ( ! is_email( $email ) ) {
			$errors->add( 'email', __( 'Email không hợp lệ.', 'wc-affiliate' ) );
		} elseif ( email_exists( $email ) ) {
			$errors->add( 'email_exists', __( 'Email này đã được đăng ký. Bạn hãy đăng nhập thay vì đăng ký mới.', 'wc-affiliate' ) );
		}
		if ( strlen( $password ) < 6 ) {
			$errors->add( 'password', __( 'Mật khẩu cần ít nhất 6 ký tự.', 'wc-affiliate' ) );
		}

		if ( $errors->has_errors() ) {
			self::$errors = $errors;
			return;
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => $name,
				'role'         => WC_Affiliate_Role::ROLE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::$errors = $user_id;
			return;
		}

		$affiliate_id = WC_Affiliate_Repo::create( (int) $user_id );

		if ( is_wp_error( $affiliate_id ) ) {
			// Roll the user back. Without this the account exists but has no
			// affiliate profile, and because the email is now taken the person
			// can never complete registration - they'd be stuck for good.
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( (int) $user_id );

			self::$errors = $affiliate_id;
			return;
		}

		self::notify_admin_of_signup( $name, $email );

		wp_safe_redirect( add_query_arg( 'registered', '1', self::current_url() ) );
		exit;
	}

	/**
	 * Let the shop owner know somebody is waiting for approval.
	 *
	 * @param string $name  Applicant name.
	 * @param string $email Applicant email.
	 */
	private static function notify_admin_of_signup( string $name, string $email ): void {
		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] Có cộng tác viên mới chờ duyệt', 'wc-affiliate' ),
			get_bloginfo( 'name' )
		);

		$message = sprintf(
			/* translators: 1: name, 2: email, 3: admin URL */
			__( "Cộng tác viên mới đăng ký:\n\nHọ tên: %1\$s\nEmail: %2\$s\n\nDuyệt tại: %3\$s", 'wc-affiliate' ),
			$name,
			$email,
			admin_url( 'admin.php?page=wc-affiliate' )
		);

		wp_mail( get_option( 'admin_email' ), $subject, $message );
	}

	/**
	 * Sign the affiliate in and send them to their dashboard.
	 */
	private static function process_login(): void {
		if ( ! isset( $_POST['wc_aff_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wc_aff_nonce'] ) ), 'wc_aff_login' ) ) {
			self::$errors = new WP_Error( 'nonce', __( 'Phiên làm việc đã hết hạn, vui lòng tải lại trang và thử lại.', 'wc-affiliate' ) );
			return;
		}

		$login    = isset( $_POST['aff_login'] ) ? sanitize_text_field( wp_unslash( $_POST['aff_login'] ) ) : '';
		$password = isset( $_POST['aff_password'] ) ? (string) wp_unslash( $_POST['aff_password'] ) : '';

		// Validate the credentials WITHOUT signing anybody in yet.
		// wp_signon() would set the auth cookie for any valid WordPress account -
		// customers, shop managers, administrators - turning this public form
		// into a second login endpoint for the whole site, outside whatever
		// protection wp-login.php has. Authenticate first, authorise second.
		$user = wp_authenticate( $login, $password );

		if ( is_wp_error( $user ) ) {
			// WordPress's own message is HTML linking into wp-login.php, which
			// affiliates can't use - replace it with something plain.
			self::$errors = new WP_Error( 'login', __( 'Email hoặc mật khẩu không đúng.', 'wc-affiliate' ) );
			return;
		}

		$affiliate = WC_Affiliate_Repo::get_by_user_id( (int) $user->ID );

		if ( ! $affiliate ) {
			self::$errors = new WP_Error(
				'not_affiliate',
				__( 'Tài khoản này chưa đăng ký chương trình cộng tác viên. Vui lòng dùng trang đăng ký cộng tác viên.', 'wc-affiliate' )
			);
			return;
		}

		// Pending and disabled affiliates are allowed in: the dashboard shows
		// them why they can't earn yet, which is friendlier than a dead end.
		wp_set_auth_cookie( (int) $user->ID, true, is_ssl() );
		wp_set_current_user( (int) $user->ID );

		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user );

		$dashboard = WC_Affiliate_Activator::get_page_url( 'wc_affiliate_page_dashboard' );
		wp_safe_redirect( $dashboard ? $dashboard : home_url() );
		exit;
	}

	/**
	 * URL of the page being viewed, without the query string.
	 */
	private static function current_url(): string {
		$page_id = get_queried_object_id();
		return $page_id ? (string) get_permalink( $page_id ) : home_url();
	}

	/**
	 * Render any pending error messages.
	 */
	private static function render_errors(): void {
		if ( ! self::$errors || ! is_wp_error( self::$errors ) || ! self::$errors->has_errors() ) {
			return;
		}

		echo '<div class="wc-aff-notice wc-aff-notice--error"><ul>';
		foreach ( self::$errors->get_error_messages() as $message ) {
			echo '<li>' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Shortcode: [affiliate_register]
	 */
	public static function render_register(): string {
		ob_start();

		$login_url = WC_Affiliate_Activator::get_page_url( 'wc_affiliate_page_login' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['registered'] ) ) {
			?>
			<div class="wc-aff-box">
				<div class="wc-aff-notice wc-aff-notice--success">
					<strong><?php esc_html_e( 'Đăng ký thành công!', 'wc-affiliate' ); ?></strong>
					<p><?php esc_html_e( 'Hồ sơ của bạn đang chờ cửa hàng duyệt. Khi được duyệt, bạn đăng nhập để lấy link giới thiệu và theo dõi hoa hồng.', 'wc-affiliate' ); ?></p>
					<?php if ( $login_url ) : ?>
						<p><a class="wc-aff-btn" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Đăng nhập', 'wc-affiliate' ); ?></a></p>
					<?php endif; ?>
				</div>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		if ( is_user_logged_in() ) {
			$dashboard = WC_Affiliate_Activator::get_page_url( 'wc_affiliate_page_dashboard' );
			?>
			<div class="wc-aff-box">
				<p><?php esc_html_e( 'Bạn đang đăng nhập rồi.', 'wc-affiliate' ); ?></p>
				<?php if ( $dashboard ) : ?>
					<p><a class="wc-aff-btn" href="<?php echo esc_url( $dashboard ); ?>"><?php esc_html_e( 'Vào trang cộng tác viên', 'wc-affiliate' ); ?></a></p>
				<?php endif; ?>
			</div>
			<?php
			return (string) ob_get_clean();
		}
		?>
		<div class="wc-aff-box">
			<h3 class="wc-aff-title"><?php esc_html_e( 'Đăng ký làm cộng tác viên', 'wc-affiliate' ); ?></h3>
			<p class="wc-aff-lead"><?php esc_html_e( 'Chia sẻ link sản phẩm của cửa hàng và nhận hoa hồng cho mỗi đơn hàng giao thành công.', 'wc-affiliate' ); ?></p>

			<?php self::render_errors(); ?>

			<form method="post" class="wc-aff-form">
				<input type="hidden" name="wc_aff_form" value="register">
				<?php wp_nonce_field( 'wc_aff_register', 'wc_aff_nonce' ); ?>

				<label for="aff_name"><?php esc_html_e( 'Họ và tên', 'wc-affiliate' ); ?></label>
				<input type="text" id="aff_name" name="aff_name" required autocomplete="name">

				<label for="aff_email"><?php esc_html_e( 'Email', 'wc-affiliate' ); ?></label>
				<input type="email" id="aff_email" name="aff_email" required autocomplete="email">

				<label for="aff_password"><?php esc_html_e( 'Mật khẩu', 'wc-affiliate' ); ?></label>
				<input type="password" id="aff_password" name="aff_password" required minlength="6" autocomplete="new-password">

				<button type="submit" class="wc-aff-btn"><?php esc_html_e( 'Đăng ký', 'wc-affiliate' ); ?></button>
			</form>

			<?php if ( $login_url ) : ?>
				<p class="wc-aff-foot">
					<?php esc_html_e( 'Đã có tài khoản?', 'wc-affiliate' ); ?>
					<a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Đăng nhập', 'wc-affiliate' ); ?></a>
				</p>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode: [affiliate_login]
	 */
	public static function render_login(): string {
		ob_start();

		$register_url  = WC_Affiliate_Activator::get_page_url( 'wc_affiliate_page_register' );
		$dashboard_url = WC_Affiliate_Activator::get_page_url( 'wc_affiliate_page_dashboard' );

		if ( is_user_logged_in() ) {
			?>
			<div class="wc-aff-box">
				<p><?php esc_html_e( 'Bạn đang đăng nhập rồi.', 'wc-affiliate' ); ?></p>
				<?php if ( $dashboard_url ) : ?>
					<p><a class="wc-aff-btn" href="<?php echo esc_url( $dashboard_url ); ?>"><?php esc_html_e( 'Vào trang cộng tác viên', 'wc-affiliate' ); ?></a></p>
				<?php endif; ?>
			</div>
			<?php
			return (string) ob_get_clean();
		}
		?>
		<div class="wc-aff-box">
			<h3 class="wc-aff-title"><?php esc_html_e( 'Đăng nhập cộng tác viên', 'wc-affiliate' ); ?></h3>

			<?php self::render_errors(); ?>

			<form method="post" class="wc-aff-form">
				<input type="hidden" name="wc_aff_form" value="login">
				<?php wp_nonce_field( 'wc_aff_login', 'wc_aff_nonce' ); ?>

				<label for="aff_login"><?php esc_html_e( 'Email', 'wc-affiliate' ); ?></label>
				<input type="text" id="aff_login" name="aff_login" required autocomplete="username">

				<label for="aff_password"><?php esc_html_e( 'Mật khẩu', 'wc-affiliate' ); ?></label>
				<input type="password" id="aff_password" name="aff_password" required autocomplete="current-password">

				<button type="submit" class="wc-aff-btn"><?php esc_html_e( 'Đăng nhập', 'wc-affiliate' ); ?></button>
			</form>

			<p class="wc-aff-foot">
				<?php
				// Password reset deliberately reuses WordPress's own flow: it is
				// security-sensitive (token generation, expiry, rate limiting) and
				// rebuilding it would risk introducing holes. The redirect brings
				// the affiliate straight back here afterwards rather than leaving
				// them stranded on wp-login.php.
				$login_page = WC_Affiliate_Activator::get_page_url( 'wc_affiliate_page_login' );
				?>
				<a href="<?php echo esc_url( wp_lostpassword_url( $login_page ? $login_page : home_url() ) ); ?>"><?php esc_html_e( 'Quên mật khẩu?', 'wc-affiliate' ); ?></a>
				<?php if ( $register_url ) : ?>
					· <a href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Đăng ký cộng tác viên', 'wc-affiliate' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Shortcode: [affiliate_dashboard]
	 */
	public static function render_dashboard(): string {
		ob_start();

		if ( ! is_user_logged_in() ) {
			$login_url = WC_Affiliate_Activator::get_page_url( 'wc_affiliate_page_login' );
			?>
			<div class="wc-aff-box">
				<p><?php esc_html_e( 'Bạn cần đăng nhập để xem trang cộng tác viên.', 'wc-affiliate' ); ?></p>
				<?php if ( $login_url ) : ?>
					<p><a class="wc-aff-btn" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Đăng nhập', 'wc-affiliate' ); ?></a></p>
				<?php endif; ?>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		$affiliate = WC_Affiliate_Repo::get_by_user_id( get_current_user_id() );

		if ( ! $affiliate ) {
			$register_url = WC_Affiliate_Activator::get_page_url( 'wc_affiliate_page_register' );
			?>
			<div class="wc-aff-box">
				<p><?php esc_html_e( 'Tài khoản của bạn chưa đăng ký chương trình cộng tác viên.', 'wc-affiliate' ); ?></p>
				<?php if ( $register_url ) : ?>
					<p><a class="wc-aff-btn" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Đăng ký ngay', 'wc-affiliate' ); ?></a></p>
				<?php endif; ?>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		if ( WC_Affiliate_Repo::STATUS_PENDING === $affiliate['status'] ) {
			?>
			<div class="wc-aff-box">
				<div class="wc-aff-notice wc-aff-notice--info">
					<strong><?php esc_html_e( 'Hồ sơ đang chờ duyệt', 'wc-affiliate' ); ?></strong>
					<p><?php esc_html_e( 'Cửa hàng sẽ duyệt hồ sơ của bạn sớm. Sau khi được duyệt, bạn sẽ thấy link giới thiệu ở đây.', 'wc-affiliate' ); ?></p>
				</div>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		if ( WC_Affiliate_Repo::STATUS_DISABLED === $affiliate['status'] ) {
			?>
			<div class="wc-aff-box">
				<div class="wc-aff-notice wc-aff-notice--error">
					<strong><?php esc_html_e( 'Tài khoản cộng tác viên đã bị khoá', 'wc-affiliate' ); ?></strong>
					<p><?php esc_html_e( 'Vui lòng liên hệ cửa hàng để biết thêm chi tiết.', 'wc-affiliate' ); ?></p>
				</div>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		$totals = WC_Affiliate_Referral_Repo::totals( (int) $affiliate['id'] );

		// Both lists are paginated so the dashboard stays fast for a long-running
		// affiliate with thousands of commissions.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only pagers.
		$ref_page  = isset( $_GET['hh'] ) ? max( 1, absint( $_GET['hh'] ) ) : 1;
		$prod_page = isset( $_GET['sp'] ) ? max( 1, absint( $_GET['sp'] ) ) : 1;
		// phpcs:enable

		$ref_per_page  = 20;
		$prod_per_page = 20;

		$ref_total       = WC_Affiliate_Referral_Repo::count( array( 'affiliate_id' => (int) $affiliate['id'] ) );
		$ref_total_pages = max( 1, (int) ceil( $ref_total / $ref_per_page ) );
		$ref_page        = min( $ref_page, $ref_total_pages );

		$referrals = WC_Affiliate_Referral_Repo::query(
			array(
				'affiliate_id' => (int) $affiliate['id'],
				'per_page'     => $ref_per_page,
				'page'         => $ref_page,
			)
		);

		$prod_total       = WC_Affiliate_Product_Meta::count_commissionable_products();
		$prod_total_pages = max( 1, (int) ceil( $prod_total / $prod_per_page ) );
		$prod_page        = min( $prod_page, $prod_total_pages );

		$products = WC_Affiliate_Product_Meta::get_commissionable_products( $prod_per_page, $prod_page );

		$base_url = self::current_url();
		?>
		<div class="wc-aff-dashboard">
			<div class="wc-aff-header">
				<div>
					<h3 class="wc-aff-title"><?php esc_html_e( 'Trang cộng tác viên', 'wc-affiliate' ); ?></h3>
					<p class="wc-aff-lead">
						<?php esc_html_e( 'Mã giới thiệu của bạn:', 'wc-affiliate' ); ?>
						<code class="wc-aff-code"><?php echo esc_html( $affiliate['ref_code'] ); ?></code>
					</p>
				</div>
				<a class="wc-aff-logout" href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>">
					<?php esc_html_e( 'Đăng xuất', 'wc-affiliate' ); ?>
				</a>
			</div>

			<div class="wc-aff-stats">
				<div class="wc-aff-stat">
					<span class="wc-aff-stat__value"><?php echo wp_kses_post( wc_price( $totals['pending'] ) ); ?></span>
					<span class="wc-aff-stat__label"><?php esc_html_e( 'Chờ chi trả', 'wc-affiliate' ); ?></span>
				</div>
				<div class="wc-aff-stat">
					<span class="wc-aff-stat__value"><?php echo wp_kses_post( wc_price( $totals['paid'] ) ); ?></span>
					<span class="wc-aff-stat__label"><?php esc_html_e( 'Đã nhận', 'wc-affiliate' ); ?></span>
				</div>
			</div>

			<h4 class="wc-aff-subtitle"><?php esc_html_e( 'Link giới thiệu sản phẩm', 'wc-affiliate' ); ?></h4>
			<p class="wc-aff-hint"><?php esc_html_e( 'Chia sẻ link bên dưới. Khi khách bấm link và mua đúng sản phẩm đó, bạn nhận được hoa hồng sau khi đơn giao thành công.', 'wc-affiliate' ); ?></p>

			<?php if ( empty( $products ) ) : ?>
				<div class="wc-aff-notice wc-aff-notice--info">
					<p><?php esc_html_e( 'Hiện chưa có sản phẩm nào áp dụng hoa hồng. Vui lòng quay lại sau.', 'wc-affiliate' ); ?></p>
				</div>
			<?php else : ?>
				<ul class="wc-aff-products">
					<?php foreach ( $products as $product ) : ?>
						<?php $link = WC_Affiliate_Tracking::build_link( $product->get_id(), $affiliate['ref_code'] ); ?>
						<li class="wc-aff-product">
							<div class="wc-aff-product__info">
								<span class="wc-aff-product__name"><?php echo esc_html( $product->get_name() ); ?></span>
								<span class="wc-aff-product__commission">
									<?php
									printf(
										/* translators: %s: commission amount */
										esc_html__( 'Hoa hồng: %s', 'wc-affiliate' ),
										wp_kses_post( wc_price( WC_Affiliate_Product_Meta::get_commission( $product->get_id() ) ) )
									);
									?>
								</span>
							</div>
							<div class="wc-aff-product__link">
								<input type="text" readonly value="<?php echo esc_attr( $link ); ?>" class="wc-aff-linkbox">
								<button type="button" class="wc-aff-copy" data-link="<?php echo esc_attr( $link ); ?>">
									<?php esc_html_e( 'Copy', 'wc-affiliate' ); ?>
								</button>
							</div>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php self::render_pager( $base_url, 'sp', $prod_page, $prod_total_pages, $prod_page ); ?>
			<?php endif; ?>

			<h4 class="wc-aff-subtitle"><?php esc_html_e( 'Lịch sử hoa hồng', 'wc-affiliate' ); ?></h4>

			<?php if ( empty( $referrals ) ) : ?>
				<div class="wc-aff-notice wc-aff-notice--info">
					<p><?php esc_html_e( 'Bạn chưa có hoa hồng nào. Hãy bắt đầu chia sẻ link sản phẩm nhé!', 'wc-affiliate' ); ?></p>
				</div>
			<?php else : ?>
				<div class="wc-aff-table-wrap">
					<table class="wc-aff-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Ngày', 'wc-affiliate' ); ?></th>
								<th><?php esc_html_e( 'Mã đơn', 'wc-affiliate' ); ?></th>
								<th><?php esc_html_e( 'Sản phẩm', 'wc-affiliate' ); ?></th>
								<th><?php esc_html_e( 'Hoa hồng', 'wc-affiliate' ); ?></th>
								<th><?php esc_html_e( 'Trạng thái', 'wc-affiliate' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $referrals as $referral ) : ?>
								<?php
								$product = wc_get_product( (int) $referral['product_id'] );
								$order   = wc_get_order( (int) $referral['order_id'] );
								?>
								<tr>
									<td><?php echo esc_html( mysql2date( 'd/m/Y', $referral['created_at'] ) ); ?></td>
									<td>
										<?php
										// Order number only - an affiliate must never see the
										// buyer's name, address or contact details.
										echo esc_html( $order ? '#' . $order->get_order_number() : '—' );
										?>
									</td>
									<td><?php echo esc_html( $product ? $product->get_name() : '—' ); ?></td>
									<td><?php echo wp_kses_post( wc_price( (float) $referral['commission_amount'] ) ); ?></td>
									<td>
										<span class="wc-aff-badge wc-aff-badge--<?php echo esc_attr( $referral['status'] ); ?>">
											<?php echo esc_html( WC_Affiliate_Referral_Repo::status_label( $referral['status'] ) ); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php self::render_pager( $base_url, 'hh', $ref_page, $ref_total_pages, $prod_page ); ?>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Simple prev/next pager for the dashboard lists.
	 *
	 * The two lists page independently, so each link carries the other list's
	 * current page - otherwise paging the commission history would silently
	 * reset the product list back to page 1.
	 *
	 * @param string $base_url    Page permalink.
	 * @param string $param       Query arg this pager controls ('hh' or 'sp').
	 * @param int    $current     Current page.
	 * @param int    $total_pages Total pages.
	 * @param int    $other_page  Current page of the other list.
	 */
	private static function render_pager( string $base_url, string $param, int $current, int $total_pages, int $other_page ): void {
		if ( $total_pages <= 1 ) {
			return;
		}

		$other_param = 'hh' === $param ? 'sp' : 'hh';

		$url = static function ( $page ) use ( $base_url, $param, $other_param, $other_page ) {
			return add_query_arg(
				array(
					$param       => $page,
					$other_param => $other_page,
				),
				$base_url
			);
		};
		?>
		<div class="wc-aff-pager">
			<?php if ( $current > 1 ) : ?>
				<a class="wc-aff-pager__link" href="<?php echo esc_url( $url( $current - 1 ) ); ?>">&laquo; <?php esc_html_e( 'Trước', 'wc-affiliate' ); ?></a>
			<?php endif; ?>

			<span class="wc-aff-pager__info">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Trang %1$d / %2$d', 'wc-affiliate' ),
					(int) $current,
					(int) $total_pages
				);
				?>
			</span>

			<?php if ( $current < $total_pages ) : ?>
				<a class="wc-aff-pager__link" href="<?php echo esc_url( $url( $current + 1 ) ); ?>"><?php esc_html_e( 'Sau', 'wc-affiliate' ); ?> &raquo;</a>
			<?php endif; ?>
		</div>
		<?php
	}
}
