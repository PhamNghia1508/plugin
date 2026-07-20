<?php
defined( 'ABSPATH' ) || exit;

/**
 * SuperShip Admin Settings
 * 
 * Main settings page with tabbed interface.
 * 
 * Tabs:
 * 1. Credentials - Authentication settings
 * 2. Warehouse - Pickup location management
 * 3. Shipping - Service settings & rates
 * 4. Webhook - Webhook URL & logs
 * 5. Advanced - Debug, logging, production gate
 * 
 * @package SuperShip_WooCommerce
 * @version 0.1.0
 */
final class SuperShip_Admin_Settings {
	
	const MENU_SLUG = 'supership-settings';
	const OPTION_PREFIX = 'supership_';

	/**
	 * Initialize admin settings
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_supership_register_webhook', array( $this, 'ajax_register_webhook' ) );
		add_action( 'wp_ajax_supership_register_account', array( $this, 'ajax_register_account' ) );
	}

	/**
	 * AJAX: register a brand-new SuperShip account (POST /v1/partner/auth/register).
	 */
	public function ajax_register_account(): void {
		check_ajax_referer( 'supership_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này', 'supership-woocommerce' ) ) );
		}

		if ( ! class_exists( 'SuperShip_Auth_Manager' ) ) {
			wp_send_json_error( array( 'message' => __( 'Không khởi tạo được trình quản lý đăng nhập', 'supership-woocommerce' ) ) );
		}

		$params = array(
			'project'  => isset( $_POST['project'] ) ? sanitize_text_field( wp_unslash( $_POST['project'] ) ) : '',
			'name'     => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'phone'    => isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '',
			'email'    => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'password' => isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '',
			'partner'  => isset( $_POST['partner'] ) ? sanitize_text_field( wp_unslash( $_POST['partner'] ) ) : '',
		);

		$result = SuperShip_Auth_Manager::register( $params );

		if ( $result['success'] ) {
			delete_transient( 'supership_connection_verify' );
			wp_send_json_success(
				array(
					'message' => __( 'Đăng ký thành công! Đã tự động lưu Personal Access Token - đang tải lại trang...', 'supership-woocommerce' ),
				)
			);
		} else {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}
	}

	/**
	 * AJAX: register our webhook URL with SuperShip (POST /v1/partner/webhooks/create).
	 */
	public function ajax_register_webhook(): void {
		check_ajax_referer( 'supership_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Bạn không có quyền thực hiện thao tác này', 'supership-woocommerce' ) ) );
		}

		if ( ! class_exists( 'SuperShip_Webhook_Handler' ) ) {
			wp_send_json_error( array( 'message' => __( 'Không tìm thấy bộ xử lý cập nhật tự động', 'supership-woocommerce' ) ) );
		}

		$result = SuperShip_Webhook_Handler::register_with_supership();

		if ( $result['success'] ) {
			wp_send_json_success(
				array(
					'message' => __( 'Đã đăng ký nhận cập nhật tự động từ SuperShip', 'supership-woocommerce' ),
					'url'     => $result['url'],
				)
			);
		} else {
			wp_send_json_error( array( 'message' => $result['error'] ) );
		}
	}

	/**
	 * Sanitize + encrypt a secret settings field before it's stored.
	 *
	 * WordPress's Settings API submits the whole form on every save, so a
	 * password-style field left blank (by design - we never echo secrets
	 * back into value="") must NOT overwrite the existing stored value.
	 * When a new value IS submitted, it's encrypted via
	 * SuperShip_Secret_Storage before being persisted - matching how
	 * SuperShip_Auth_Config::from_options() already expects to read it
	 * back (checks for the "v1:" envelope prefix and decrypts).
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string Encrypted value to store, or the previous value if left blank.
	 */
	public static function sanitize_encrypted_field( $value ): string {
		// Any credentials field being re-saved invalidates the cached
		// "is this token actually valid" check (see verify_connection()) -
		// otherwise fixing a bad token still shows the stale failure for
		// up to 5 minutes.
		delete_transient( 'supership_connection_verify' );

		$option_name = str_replace( 'sanitize_option_', '', current_filter() );
		$value       = is_string( $value ) ? trim( $value ) : '';

		if ( '' === $value ) {
			return (string) get_option( $option_name, '' );
		}

		// Already in our envelope format (shouldn't normally happen from a form post) - keep as-is.
		if ( 0 === strpos( $value, 'v1:' ) ) {
			return $value;
		}

		if ( ! class_exists( 'SuperShip_Secret_Storage' ) ) {
			return (string) get_option( $option_name, '' );
		}

		$storage   = new SuperShip_Secret_Storage();
		$encrypted = $storage->encrypt( $value );

		// If encryption failed (missing sodium/openssl), refuse to store plaintext -
		// keep the previous value rather than silently downgrading security.
		return '' !== $encrypted ? $encrypted : (string) get_option( $option_name, '' );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Cài đặt SuperShip', 'supership-woocommerce' ),
			__( 'Cài đặt SuperShip', 'supership-woocommerce' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Enqueue admin assets
	 * 
	 * @param string $hook Current admin page hook
	 */
	public function enqueue_assets( string $hook ): void {
		// Only load on our settings page
		if ( 'woocommerce_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		
		wp_enqueue_style(
			'supership-admin',
			SUPERSHIP_WC_URL . 'assets/css/admin.css',
			array(),
			SUPERSHIP_WC_VERSION
		);
		
		wp_enqueue_script(
			'supership-admin',
			SUPERSHIP_WC_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			SUPERSHIP_WC_VERSION,
			true
		);
		
		wp_localize_script(
			'supership-admin',
			'supershipAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'supership_admin' ),
			)
		);
	}

	/**
	 * Register settings
	 */
	public function register_settings(): void {
		// Auth settings
		register_setting( 'supership_auth', 'supership_auth_mode' );
		register_setting(
			'supership_auth',
			'supership_personal_token',
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_encrypted_field' ) )
		);
		register_setting(
			'supership_auth',
			'supership_username',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'supership_auth',
			'supership_password',
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_encrypted_field' ) )
		);
		register_setting(
			'supership_auth',
			'supership_client_id',
			array( 'sanitize_callback' => 'sanitize_text_field' )
		);
		register_setting(
			'supership_auth',
			'supership_client_secret',
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_encrypted_field' ) )
		);
		register_setting(
			'supership_auth',
			'supership_partner_code',
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_encrypted_field' ) )
		);

		// Warehouse settings
		register_setting( 'supership_warehouse', 'supership_default_warehouse' );
		
		// Shipping settings
		register_setting( 'supership_shipping', 'supership_default_service' );
		register_setting( 'supership_shipping', 'supership_default_config' );
		register_setting( 'supership_shipping', 'supership_default_payer' );
		register_setting( 'supership_shipping', 'supership_insurance_enabled' );
		
		// Webhook settings
		register_setting( 'supership_webhook', 'supership_webhook_enabled' );
		
		// Advanced settings
		register_setting( 'supership_advanced', 'supership_debug_mode' );
		register_setting( 'supership_advanced', 'supership_auto_tracking_enabled' );
		register_setting( 'supership_advanced', 'supership_auto_tracking_interval' );
	}

	/**
	 * Render settings page
	 */
	public function render_settings_page(): void {
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'credentials';
		
		?>
		<div class="wrap supership-settings">
			<h1><?php esc_html_e( 'Cài đặt SuperShip', 'supership-woocommerce' ); ?></h1>

			<?php $this->render_connection_status(); ?>

			<nav class="nav-tab-wrapper woo-nav-tab-wrapper">
				<?php
				$tabs = array(
					'credentials' => __( 'Kết nối', 'supership-woocommerce' ),
					'warehouse'   => __( 'Kho lấy hàng', 'supership-woocommerce' ),
					'shipping'    => __( 'Vận chuyển', 'supership-woocommerce' ),
					'webhook'     => __( 'Tự động cập nhật', 'supership-woocommerce' ),
					'advanced'    => __( 'Nâng cao', 'supership-woocommerce' ),
				);
				
				foreach ( $tabs as $tab => $label ) {
					$url = add_query_arg(
						array(
							'page' => self::MENU_SLUG,
							'tab'  => $tab,
						),
						admin_url( 'admin.php' )
					);
					
					$class = ( $active_tab === $tab ) ? 'nav-tab nav-tab-active' : 'nav-tab';
					
					printf(
						'<a href="%s" class="%s">%s</a>',
						esc_url( $url ),
						esc_attr( $class ),
						esc_html( $label )
					);
				}
				?>
			</nav>
			
			<div class="supership-tab-content">
				<?php
				switch ( $active_tab ) {
					case 'credentials':
						$this->render_credentials_tab();
						break;
					case 'warehouse':
						$this->render_warehouse_tab();
						break;
					case 'shipping':
						$this->render_shipping_tab();
						break;
					case 'webhook':
						$this->render_webhook_tab();
						break;
					case 'advanced':
						$this->render_advanced_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render connection status banner.
	 *
	 * Does a real (briefly cached) API call rather than just checking that a
	 * token STRING is saved - confirmed live that a saved-but-invalid/expired
	 * token still passes `is_authenticated()` (which only checks presence),
	 * showing a misleading green "Connected" banner while every real API call
	 * (rate calc, warehouses, etc.) actually fails with a 401 from SuperShip
	 * and silently falls back to flat-rate shipping - very confusing to
	 * debug without this distinction visible in Settings.
	 */
	private function render_connection_status(): void {
		$auth_manager     = new SuperShip_Auth_Manager();
		$is_authenticated = $auth_manager->is_authenticated();

		if ( ! $is_authenticated ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Chưa kết nối với SuperShip', 'supership-woocommerce' ); ?></strong> —
					<?php esc_html_e( 'Vui lòng nhập thông tin đăng nhập ở tab "Kết nối".', 'supership-woocommerce' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		$verify = self::verify_connection();

		if ( $verify['success'] ) {
			$status = $auth_manager->get_status();
			?>
			<div class="notice notice-success">
				<p>
					<strong><?php esc_html_e( '✓ Đã kết nối với SuperShip', 'supership-woocommerce' ); ?></strong>
					<?php
					$remaining = isset( $status['token_info']['time_remaining'] ) ? (int) $status['token_info']['time_remaining'] : 0;
					if ( $remaining > 0 ) {
						printf(
							' — %s',
							sprintf(
								/* translators: %s: human-readable time until token expiry */
								esc_html__( 'Token còn hạn %s nữa', 'supership-woocommerce' ),
								esc_html( human_time_diff( time(), time() + $remaining ) )
							)
						);
					}
					?>
				</p>
			</div>
			<?php
		} else {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( '✗ Token đã lưu nhưng SuperShip từ chối', 'supership-woocommerce' ); ?></strong><br>
					<?php echo esc_html( $verify['error'] ); ?><br>
					<?php esc_html_e( 'Kiểm tra lại Token trong tab Credentials - có thể đã sai, hết hạn, hoặc bị dán thiếu/thừa ký tự. Trong lúc này, cước vận chuyển sẽ dùng mức phí dự phòng (fallback), không phải giá tính thật từ SuperShip.', 'supership-woocommerce' ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Verify the stored token actually works, via a real (lightweight) API
	 * call - cached briefly so this doesn't hit SuperShip on every admin
	 * page load.
	 *
	 * @return array {success: bool, error?: string}
	 */
	private static function verify_connection(): array {
		$cache_key = 'supership_connection_verify';
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( ! class_exists( 'SuperShip_HTTP_Client' ) || ! class_exists( 'SuperShip_API_Error_Mapper' ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Không khởi tạo được trình gọi API SuperShip', 'supership-woocommerce' ),
			);
		}

		$client   = new SuperShip_HTTP_Client();
		$response = $client->get( '/v1/partner/warehouses' );

		$result = $response->is_success()
			? array( 'success' => true )
			: array(
				'success' => false,
				'error'   => SuperShip_API_Error_Mapper::get_safe_message( $response ),
			);

		set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * Render Credentials tab
	 */
	private function render_credentials_tab(): void {
		$auth_mode      = get_option( 'supership_auth_mode', 'personal_token' );
		$has_token      = '' !== get_option( 'supership_personal_token', '' );
		$username       = get_option( 'supership_username', '' );
		$client_id      = get_option( 'supership_client_id', '' );
		$has_secret     = '' !== get_option( 'supership_client_secret', '' );
		$has_password   = '' !== get_option( 'supership_password', '' );
		$has_partner    = '' !== get_option( 'supership_partner_code', '' );
		$saved_placeholder = __( '•••••••• (đã lưu - để trống nếu không đổi)', 'supership-woocommerce' );

		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'supership_auth' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Cách kết nối', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<fieldset>
							<label>
								<input type="radio" class="supership-auth-mode-radio" name="supership_auth_mode" value="personal_token" <?php checked( $auth_mode, 'personal_token' ); ?>>
								<?php esc_html_e( 'Token truy cập cá nhân', 'supership-woocommerce' ); ?>
							</label>
							<br>
							<label>
								<input type="radio" class="supership-auth-mode-radio" name="supership_auth_mode" value="password_grant" <?php checked( $auth_mode, 'password_grant' ); ?>>
								<?php esc_html_e( 'Password Grant (Sàn TMĐT / đa khách hàng)', 'supership-woocommerce' ); ?>
							</label>
						</fieldset>
						<p class="description">
							<?php esc_html_e( 'Personal Token dành cho 1 shop (khuyến nghị - đa số dùng cách này). Password Grant chỉ dành cho Sàn TMĐT/phần mềm có nhiều khách hàng cần kết nối SuperShip.', 'supership-woocommerce' ); ?>
						</p>
					</td>
				</tr>

				<tr class="supership-auth-field" data-auth-mode="personal_token">
					<th scope="row">
						<label for="supership_personal_token"><?php esc_html_e( 'Token truy cập cá nhân', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<input type="password"
							   id="supership_personal_token"
							   name="supership_personal_token"
							   value=""
							   class="regular-text"
							   autocomplete="off"
							   placeholder="<?php echo esc_attr( $has_token ? $saved_placeholder : __( 'Dán Personal Access Token vào đây', 'supership-woocommerce' ) ); ?>">
						<p class="description">
							<?php
							printf(
								wp_kses_post( __( 'Lấy token tại mục <a href="%s" target="_blank">API trên SuperShip Dashboard</a>. Chưa có tài khoản? Xem mục "Đăng ký tài khoản mới" bên dưới.', 'supership-woocommerce' ) ),
								'https://khachhang.supership.vn/apis'
							);
							?>
						</p>
					</td>
				</tr>

				<tr class="supership-auth-field" data-auth-mode="password_grant">
					<th scope="row">
						<label for="supership_client_id"><?php esc_html_e( 'Client ID', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<input type="text"
							   id="supership_client_id"
							   name="supership_client_id"
							   value="<?php echo esc_attr( $client_id ); ?>"
							   class="regular-text"
							   autocomplete="off">
						<p class="description"><?php esc_html_e( 'Client ID do SuperShip cấp cho Đối Tác.', 'supership-woocommerce' ); ?></p>
					</td>
				</tr>

				<tr class="supership-auth-field" data-auth-mode="password_grant">
					<th scope="row">
						<label for="supership_client_secret"><?php esc_html_e( 'Client Secret', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<input type="password"
							   id="supership_client_secret"
							   name="supership_client_secret"
							   value=""
							   class="regular-text"
							   autocomplete="off"
							   placeholder="<?php echo esc_attr( $has_secret ? $saved_placeholder : '' ); ?>">
					</td>
				</tr>

				<tr class="supership-auth-field" data-auth-mode="password_grant">
					<th scope="row">
						<label for="supership_username"><?php esc_html_e( 'Tên đăng nhập (Email shop)', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<input type="text"
							   id="supership_username"
							   name="supership_username"
							   value="<?php echo esc_attr( $username ); ?>"
							   class="regular-text"
							   autocomplete="username">
					</td>
				</tr>

				<tr class="supership-auth-field" data-auth-mode="password_grant">
					<th scope="row">
						<label for="supership_password"><?php esc_html_e( 'Mật khẩu', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<input type="password"
							   id="supership_password"
							   name="supership_password"
							   value=""
							   class="regular-text"
							   autocomplete="new-password"
							   placeholder="<?php echo esc_attr( $has_password ? $saved_placeholder : __( 'Nhập mật khẩu mới để thay đổi', 'supership-woocommerce' ) ); ?>">
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="supership_partner_code"><?php esc_html_e( 'Mã Bí Mật (Partner Code)', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<input type="password"
							   id="supership_partner_code"
							   name="supership_partner_code"
							   value=""
							   class="regular-text"
							   autocomplete="off"
							   placeholder="<?php echo esc_attr( $has_partner ? $saved_placeholder : '' ); ?>">
						<p class="description"><?php esc_html_e( 'Chỉ dành cho Đối Tác Thương Mại Điện Tử lớn với SuperShip - áp dụng cho cả 2 chế độ xác thực (gửi kèm khi tạo đơn/kho hàng và bật Webhook tự động). Bỏ trống nếu không có.', 'supership-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Lưu & Kiểm tra kết nối', 'supership-woocommerce' ) ); ?>
		</form>

		<hr>

		<h3>
			<button type="button" class="button-link supership-toggle-register">
				<?php esc_html_e( 'Chưa có tài khoản SuperShip? Đăng ký tài khoản mới ▾', 'supership-woocommerce' ); ?>
			</button>
		</h3>
		<div class="supership-register-account-form" style="display:none; max-width: 500px;">
			<p class="description">
				<?php esc_html_e( 'Lưu ý: API đăng ký của SuperShip yêu cầu bắt buộc phải có Mã Bí Mật (Partner Code) - chỉ dùng được nếu bạn đã được SuperShip cấp mã này. Nếu chưa có, vui lòng tự tạo tài khoản trực tiếp tại khachhang.supership.vn rồi lấy Personal Access Token dán ở trên.', 'supership-woocommerce' ); ?>
			</p>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="supership_reg_project"><?php esc_html_e( 'Tên Shop/Công Ty', 'supership-woocommerce' ); ?></label></th>
					<td><input type="text" id="supership_reg_project" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="supership_reg_name"><?php esc_html_e( 'Họ tên người đại diện', 'supership-woocommerce' ); ?></label></th>
					<td><input type="text" id="supership_reg_name" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="supership_reg_phone"><?php esc_html_e( 'Số điện thoại', 'supership-woocommerce' ); ?></label></th>
					<td><input type="text" id="supership_reg_phone" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="supership_reg_email"><?php esc_html_e( 'Email', 'supership-woocommerce' ); ?></label></th>
					<td><input type="email" id="supership_reg_email" class="regular-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="supership_reg_password"><?php esc_html_e( 'Mật khẩu', 'supership-woocommerce' ); ?></label></th>
					<td><input type="password" id="supership_reg_password" class="regular-text" autocomplete="new-password"></td>
				</tr>
				<tr>
					<th scope="row"><label for="supership_reg_partner"><?php esc_html_e( 'Mã Bí Mật (Partner Code)', 'supership-woocommerce' ); ?></label></th>
					<td><input type="password" id="supership_reg_partner" class="regular-text" autocomplete="off"></td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary supership-submit-register">
					<?php esc_html_e( 'Đăng ký', 'supership-woocommerce' ); ?>
				</button>
				<span class="supership-register-result"></span>
			</p>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			function toggleAuthFields() {
				var mode = $('.supership-auth-mode-radio:checked').val();
				$('.supership-auth-field').each(function() {
					$(this).toggle($(this).data('auth-mode') === mode);
				});
			}
			$('.supership-auth-mode-radio').on('change', toggleAuthFields);
			toggleAuthFields();

			$('.supership-toggle-register').on('click', function() {
				$('.supership-register-account-form').slideToggle();
			});

			$('.supership-submit-register').on('click', function() {
				var $btn = $(this);
				var $result = $('.supership-register-result');

				$btn.prop('disabled', true);
				$result.text('<?php echo esc_js( __( 'Đang đăng ký...', 'supership-woocommerce' ) ); ?>');

				$.post(supershipAdmin.ajaxUrl, {
					action: 'supership_register_account',
					nonce: supershipAdmin.nonce,
					project: $('#supership_reg_project').val(),
					name: $('#supership_reg_name').val(),
					phone: $('#supership_reg_phone').val(),
					email: $('#supership_reg_email').val(),
					password: $('#supership_reg_password').val(),
					partner: $('#supership_reg_partner').val()
				}, function(response) {
					$btn.prop('disabled', false);
					if (response.success) {
						$result.css('color', '#46b450').text(response.data.message);
						setTimeout(function() { location.reload(); }, 1200);
					} else {
						$result.css('color', '#dc3232').text(response.data.message || '<?php echo esc_js( __( 'Đăng ký thất bại', 'supership-woocommerce' ) ); ?>');
					}
				}).fail(function() {
					$btn.prop('disabled', false);
					$result.css('color', '#dc3232').text('<?php echo esc_js( __( 'Lỗi kết nối', 'supership-woocommerce' ) ); ?>');
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render Warehouse tab
	 */
	private function render_warehouse_tab(): void {
		$warehouse_service = new SuperShip_Warehouse_Service();
		$result = $warehouse_service->get_warehouses();
		$default_warehouse = get_option( 'supership_default_warehouse', '' );
		
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'supership_warehouse' ); ?>
			
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="supership_default_warehouse"><?php esc_html_e( 'Kho lấy hàng mặc định', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<?php if ( $result['success'] && ! empty( $result['warehouses'] ) ) : ?>
							<select id="supership_default_warehouse" name="supership_default_warehouse" class="regular-text">
								<option value=""><?php esc_html_e( '— Chọn kho lấy hàng —', 'supership-woocommerce' ); ?></option>
								<?php foreach ( $result['warehouses'] as $warehouse ) : ?>
									<option value="<?php echo esc_attr( $warehouse['code'] ); ?>" <?php selected( $default_warehouse, $warehouse['code'] ); ?>>
										<?php echo esc_html( $warehouse['name'] . ' - ' . $warehouse['address'] ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Kho mặc định dùng khi tạo vận đơn mới', 'supership-woocommerce' ); ?>
							</p>
						<?php else : ?>
							<p class="description">
								<?php
								printf(
									__( 'Chưa có kho nào. <a href="%s" target="_blank">Thêm kho trên trang quản trị SuperShip</a>', 'supership-woocommerce' ),
									'https://khachhang.supership.vn'
								);
								?>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>
			
			<?php if ( $result['success'] && ! empty( $result['warehouses'] ) ) : ?>
				<h3><?php esc_html_e( 'Danh sách kho', 'supership-woocommerce' ); ?></h3>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tên kho', 'supership-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Địa chỉ', 'supership-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Số điện thoại', 'supership-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Mã kho', 'supership-woocommerce' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['warehouses'] as $warehouse ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $warehouse['name'] ); ?></strong></td>
								<td><?php echo esc_html( $warehouse['address'] . ', ' . $warehouse['commune'] . ', ' . $warehouse['district'] . ', ' . $warehouse['province'] ); ?></td>
								<td><?php echo esc_html( $warehouse['phone'] ); ?></td>
								<td><code><?php echo esc_html( $warehouse['code'] ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render Shipping tab
	 */
	private function render_shipping_tab(): void {
		$default_service = get_option( 'supership_default_service', '1' );
		$default_config = get_option( 'supership_default_config', '1' );
		$default_payer = get_option( 'supership_default_payer', '1' );
		$insurance_enabled = get_option( 'supership_insurance_enabled', 'yes' );
		
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'supership_shipping' ); ?>
			
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="supership_default_service"><?php esc_html_e( 'Dịch vụ mặc định', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<select id="supership_default_service" name="supership_default_service" class="regular-text">
							<option value="1" <?php selected( $default_service, '1' ); ?>><?php esc_html_e( 'Tốc Hành (mã 1 - giá trị duy nhất được SuperShip tài liệu hóa)', 'supership-woocommerce' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'API tạo đơn nhận field "service" dạng mã (String). Hiện SuperShip chỉ công bố mã "1" = Tốc Hành.', 'supership-woocommerce' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="supership_default_config"><?php esc_html_e( 'Tuỳ chọn giao hàng', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<select id="supership_default_config" name="supership_default_config" class="regular-text">
							<option value="1" <?php selected( $default_config, '1' ); ?>><?php esc_html_e( '1 - Cho xem không cho thử', 'supership-woocommerce' ); ?></option>
							<option value="2" <?php selected( $default_config, '2' ); ?>><?php esc_html_e( '2 - Cho thử hàng', 'supership-woocommerce' ); ?></option>
							<option value="3" <?php selected( $default_config, '3' ); ?>><?php esc_html_e( '3 - Không cho xem hàng', 'supership-woocommerce' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Quyền xem/thử hàng khi giao (field "config" trong API tạo đơn)', 'supership-woocommerce' ); ?></p>
					</td>
				</tr>
				
				<tr>
					<th scope="row">
						<label for="supership_default_payer"><?php esc_html_e( 'Bên trả phí ship', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<select id="supership_default_payer" name="supership_default_payer" class="regular-text">
							<option value="1" <?php selected( $default_payer, '1' ); ?>><?php esc_html_e( 'Người gửi trả (shop)', 'supership-woocommerce' ); ?></option>
							<option value="2" <?php selected( $default_payer, '2' ); ?>><?php esc_html_e( 'Người nhận trả (khách)', 'supership-woocommerce' ); ?></option>
						</select>
					</td>
				</tr>
				
				<tr>
					<th scope="row">
						<label for="supership_insurance_enabled"><?php esc_html_e( 'Bảo hiểm hàng hoá', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" 
								   id="supership_insurance_enabled" 
								   name="supership_insurance_enabled" 
								   value="yes" 
								   <?php checked( $insurance_enabled, 'yes' ); ?>>
							<?php esc_html_e( 'Tự động mua bảo hiểm cho vận đơn', 'supership-woocommerce' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Phí bảo hiểm tính theo giá trị khai báo của đơn hàng', 'supership-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>
			
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Render Webhook tab
	 */
	private function render_webhook_tab(): void {
		$webhook_enabled  = get_option( 'supership_webhook_enabled', 'yes' );
		$webhook_url      = class_exists( 'SuperShip_Webhook_Handler' ) ? SuperShip_Webhook_Handler::get_webhook_url() : '';
		$registered       = class_exists( 'SuperShip_Webhook_Handler' ) ? SuperShip_Webhook_Handler::get_registered_with_supership() : array( 'success' => false );

		?>
		<p style="max-width:640px;">
			<?php esc_html_e( 'Khi đơn hàng có biến động bên SuperShip (đã lấy hàng, đang giao, đã giao, hoàn...), SuperShip sẽ tự báo về website và trạng thái đơn tự cập nhật - bạn không cần bấm gì cả. Thiết lập một lần duy nhất bằng nút "Đăng ký" bên dưới.', 'supership-woocommerce' ); ?>
		</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'supership_webhook' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Địa chỉ nhận cập nhật', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<input type="text"
							   value="<?php echo esc_attr( $webhook_url ); ?>"
							   class="large-text code"
							   readonly>
						<button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $webhook_url ); ?>')">
							<?php esc_html_e( 'Sao chép', 'supership-woocommerce' ); ?>
						</button>
						<p class="description">
							<?php esc_html_e( 'URL này chứa một mã bí mật riêng của site bạn (do plugin tự sinh) - SuperShip không cung cấp cơ chế xác thực webhook nên plugin tự bảo vệ bằng cách này. Không chia sẻ URL này công khai.', 'supership-woocommerce' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label><?php esc_html_e( 'Trạng thái đăng ký tại SuperShip', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<?php if ( $registered['success'] ) : ?>
							<?php if ( '' === $registered['url'] ) : ?>
								<p><span class="dashicons dashicons-warning" style="color:#dc3232;"></span> <?php esc_html_e( 'Chưa có webhook nào được đăng ký với SuperShip.', 'supership-woocommerce' ); ?></p>
							<?php elseif ( $registered['url'] === $webhook_url ) : ?>
								<p><span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span> <?php esc_html_e( 'Đã đăng ký đúng URL hiện tại.', 'supership-woocommerce' ); ?></p>
							<?php else : ?>
								<p><span class="dashicons dashicons-warning" style="color:#dc3232;"></span> <?php esc_html_e( 'URL đã đăng ký KHÁC với URL hiện tại - bấm nút bên dưới để cập nhật:', 'supership-woocommerce' ); ?></p>
								<p><code><?php echo esc_html( $registered['url'] ); ?></code></p>
							<?php endif; ?>
						<?php else : ?>
							<p class="description"><?php echo esc_html( sprintf( __( 'Không kiểm tra được trạng thái đăng ký: %s', 'supership-woocommerce' ), $registered['error'] ?? '' ) ); ?></p>
						<?php endif; ?>

						<p>
							<button type="button" class="button button-primary supership-register-webhook">
								<?php esc_html_e( 'Đăng ký / Cập nhật Webhook với SuperShip', 'supership-woocommerce' ); ?>
							</button>
							<span class="supership-register-webhook-result"></span>
						</p>
						<p class="description">
							<?php esc_html_e( 'Chỉ cần bấm một lần - plugin tự đăng ký với SuperShip, bạn không phải vào trang SuperShip dán gì thủ công.', 'supership-woocommerce' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="supership_webhook_enabled"><?php esc_html_e( 'Trạng thái tự động cập nhật', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox"
								   id="supership_webhook_enabled"
								   name="supership_webhook_enabled"
								   value="yes"
								   <?php checked( $webhook_enabled, 'yes' ); ?>>
							<?php esc_html_e( 'Cho phép SuperShip tự động báo trạng thái về website', 'supership-woocommerce' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Khi tắt, SuperShip vẫn nhận HTTP 200 (tránh bị retry) nhưng plugin sẽ bỏ qua nội dung, không cập nhật đơn hàng.', 'supership-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('.supership-register-webhook').on('click', function(e) {
				e.preventDefault();
				var $btn = $(this);
				var $result = $('.supership-register-webhook-result');

				$btn.prop('disabled', true);
				$result.text('<?php echo esc_js( __( 'Đang đăng ký...', 'supership-woocommerce' ) ); ?>');

				$.post(supershipAdmin.ajaxUrl, {
					action: 'supership_register_webhook',
					nonce: supershipAdmin.nonce
				}, function(response) {
					$btn.prop('disabled', false);
					if (response.success) {
						$result.css('color', '#46b450').text(response.data.message);
						location.reload();
					} else {
						$result.css('color', '#dc3232').text(response.data.message || '<?php echo esc_js( __( 'Đăng ký thất bại', 'supership-woocommerce' ) ); ?>');
					}
				}).fail(function() {
					$btn.prop('disabled', false);
					$result.css('color', '#dc3232').text('<?php echo esc_js( __( 'Lỗi kết nối', 'supership-woocommerce' ) ); ?>');
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render Advanced tab
	 */
	private function render_advanced_tab(): void {
		$debug_mode = get_option( 'supership_debug_mode', 'no' );
		$auto_tracking = get_option( 'supership_auto_tracking_enabled', 'yes' );
		$tracking_interval = get_option( 'supership_auto_tracking_interval', '60' );
		
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'supership_advanced' ); ?>
			
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="supership_debug_mode"><?php esc_html_e( 'Chế độ ghi nhật ký', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" 
								   id="supership_debug_mode" 
								   name="supership_debug_mode" 
								   value="yes" 
								   <?php checked( $debug_mode, 'yes' ); ?>>
							<?php esc_html_e( 'Ghi lại chi tiết các lần gọi API', 'supership-woocommerce' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Lưu nội dung gọi/nhận API vào nhật ký WooCommerce (WooCommerce → Trạng thái → Nhật ký). Chỉ nên bật khi cần tìm lỗi.', 'supership-woocommerce' ); ?></p>
					</td>
				</tr>
				
				<tr>
					<th scope="row">
						<label for="supership_auto_tracking_enabled"><?php esc_html_e( 'Tự động kiểm tra trạng thái', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<label>
							<input type="checkbox" 
								   id="supership_auto_tracking_enabled" 
								   name="supership_auto_tracking_enabled" 
								   value="yes" 
								   <?php checked( $auto_tracking, 'yes' ); ?>>
							<?php esc_html_e( 'Tự động kiểm tra trạng thái vận đơn theo định kỳ', 'supership-woocommerce' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Dùng WP Cron để định kỳ hỏi SuperShip trạng thái mới nhất của các vận đơn chưa kết thúc', 'supership-woocommerce' ); ?></p>
					</td>
				</tr>
				
				<tr>
					<th scope="row">
						<label for="supership_auto_tracking_interval"><?php esc_html_e( 'Tần suất kiểm tra', 'supership-woocommerce' ); ?></label>
					</th>
					<td>
						<input type="number" 
							   id="supership_auto_tracking_interval" 
							   name="supership_auto_tracking_interval" 
							   value="<?php echo esc_attr( $tracking_interval ); ?>" 
							   min="15" 
							   max="1440" 
							   class="small-text"> 
						<?php esc_html_e( 'phút', 'supership-woocommerce' ); ?>
						<p class="description"><?php esc_html_e( 'Bao lâu kiểm tra một lần (từ 15 đến 1440 phút)', 'supership-woocommerce' ); ?></p>
					</td>
				</tr>
			</table>
			
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Get option with prefix
	 * 
	 * @param string $key Option key (without prefix)
	 * @param mixed  $default Default value
	 * @return mixed
	 */
	public static function get_option( string $key, $default = '' ) {
		return get_option( self::OPTION_PREFIX . $key, $default );
	}

	/**
	 * Update option with prefix
	 * 
	 * @param string $key Option key (without prefix)
	 * @param mixed  $value Option value
	 * @return bool
	 */
	public static function update_option( string $key, $value ): bool {
		return update_option( self::OPTION_PREFIX . $key, $value );
	}
}
