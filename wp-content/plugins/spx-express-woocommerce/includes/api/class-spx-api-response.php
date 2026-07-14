<?php
defined( 'ABSPATH' ) || exit;

/**
 * Normalises every SPX HTTP outcome into a single internal contract.
 * Its log array intentionally excludes request/response bodies and secrets.
 */
final class SPX_API_Response {
	/** @var bool */   private $success;
	/** @var int */    private $http_status;
	/** @var int|null */ private $ret_code;
	/** @var string */ private $message;
	/** @var mixed */  private $data;
	/** @var bool */   private $retryable;
	/** @var string */ private $category;
	/** @var string */ private $path = '';
	/** @var int */    private $duration_ms = 0;
	/** @var string */ private $environment = '';

	private function __construct( bool $success, int $http_status, $ret_code, string $message, $data, bool $retryable, string $category ) {
		$this->success     = $success;
		$this->http_status = $http_status;
		$this->ret_code    = $ret_code;
		$this->message     = $message;
		$this->data        = $data;
		$this->retryable   = $retryable;
		$this->category    = $category;
	}

	public static function from_wp_error( $error ): SPX_API_Response {
		$message = is_wp_error( $error ) ? $error->get_error_message() : 'HTTP transport error.';
		return new self( false, 0, null, sanitize_text_field( $message ), null, true, SPX_API_Error_Mapper::CAT_TRANSPORT );
	}

	public static function from_http( int $status, string $raw ): SPX_API_Response {
		if ( $status < 200 || $status >= 300 ) {
			return new self( false, $status, null, __( 'SPX returned an unexpected HTTP status.', 'spx-express-woocommerce' ), null, ( $status >= 500 ), SPX_API_Error_Mapper::CAT_TEMPORARY );
		}
		if ( '' === trim( $raw ) ) {
			return new self( false, $status, null, __( 'SPX returned an empty response.', 'spx-express-woocommerce' ), null, false, SPX_API_Error_Mapper::CAT_PERMANENT );
		}
		$json = json_decode( $raw, true );
		if ( ! is_array( $json ) || ! array_key_exists( 'ret_code', $json ) ) {
			return new self( false, $status, null, __( 'SPX returned an unreadable response.', 'spx-express-woocommerce' ), null, false, SPX_API_Error_Mapper::CAT_PERMANENT );
		}
		$ret_code = (int) $json['ret_code'];
		$data     = array_key_exists( 'data', $json ) ? $json['data'] : null;
		if ( 0 === $ret_code ) {
			return new self( true, $status, 0, 'success.', $data, false, '' );
		}
		return new self(
			false,
			$status,
			$ret_code,
			SPX_API_Error_Mapper::safe_message( $ret_code ),
			null,
			SPX_API_Error_Mapper::is_retryable( $ret_code ),
			SPX_API_Error_Mapper::category( $ret_code )
		);
	}

	/** Local failure that never reached (or should never reach) the network. */
	public static function config_error( string $category, string $message ): SPX_API_Response {
		return new self( false, 0, null, sanitize_text_field( $message ), null, false, $category );
	}

	public function set_context( string $path, int $duration_ms, string $environment ): void {
		$this->path        = $path;
		$this->duration_ms = $duration_ms;
		$this->environment = $environment;
	}

	public function is_success(): bool { return $this->success; }
	public function get_http_status(): int { return $this->http_status; }
	public function get_ret_code() { return $this->ret_code; }
	public function get_message(): string { return $this->message; }
	public function get_data() { return $this->data; }
	public function is_retryable(): bool { return $this->retryable; }
	public function get_category(): string { return $this->category; }

	/** Safe structure for logging: no bodies, no secrets, no raw debug_msg. */
	public function to_log_array(): array {
		return array(
			'success'     => $this->success,
			'environment' => $this->environment,
			'path'        => $this->path,
			'http_status' => $this->http_status,
			'ret_code'    => $this->ret_code,
			'message'     => $this->message,
			'category'    => $this->category,
			'retryable'   => $this->retryable,
			'duration_ms' => $this->duration_ms,
		);
	}
}
