<?php
defined( 'ABSPATH' ) || exit;

/**
 * Maps internal service/error slugs to Vietnamese, user-facing copy. Internal
 * slugs remain in service contracts (and tests) for logic; this presenter is the
 * only thing the admin UI renders, so no Gate/phase/slug jargon reaches users.
 */
final class SPX_Admin_Notice_Presenter {
	/** @return array{tone:string,message:string}|null */
	public static function cancel_notice( string $slug ) {
		$map = array(
			'succeeded'            => array( 'success', __( 'Đã hủy vận đơn SPX. Đơn WooCommerce không bị hủy và không được hoàn tiền.', 'spx-express-woocommerce' ) ),
			'already_cancelled'    => array( 'success', __( 'Vận đơn SPX đã ở trạng thái đã hủy.', 'spx-express-woocommerce' ) ),
			'processing'           => array( 'info', __( 'Yêu cầu hủy đang được xử lý. Vui lòng kiểm tra lại sau.', 'spx-express-woocommerce' ) ),
			'unknown'              => array( 'warning', __( 'Chưa xác định được kết quả hủy. Hệ thống sẽ tự kiểm tra lại; không gửi lại yêu cầu.', 'spx-express-woocommerce' ) ),
			'gate_6f_b_required'   => array( 'warning', __( 'Chức năng hủy vận đơn chưa được kích hoạt.', 'spx-express-woocommerce' ) ),
			'status_ineligible'    => array( 'warning', __( 'Vận đơn hiện không còn đủ điều kiện hủy trên SPX.', 'spx-express-woocommerce' ) ),
			'ineligible'           => array( 'warning', __( 'Vận đơn hiện không còn đủ điều kiện hủy trên SPX.', 'spx-express-woocommerce' ) ),
			'invalid_tracking'     => array( 'error', __( 'Đơn hàng chưa có mã vận đơn SPX hợp lệ.', 'spx-express-woocommerce' ) ),
			'environment_mismatch' => array( 'error', __( 'Chỉ có thể hủy vận đơn ở môi trường thử nghiệm.', 'spx-express-woocommerce' ) ),
			'locked'               => array( 'info', __( 'Một yêu cầu hủy khác đang được xử lý cho đơn này.', 'spx-express-woocommerce' ) ),
			'retry_blocked'        => array( 'warning', __( 'Không thể gửi lại yêu cầu hủy lúc này.', 'spx-express-woocommerce' ) ),
			'cancel_failed'        => array( 'error', __( 'Hủy vận đơn SPX không thành công. Vui lòng thử lại sau.', 'spx-express-woocommerce' ) ),
			'failed'               => array( 'error', __( 'Hủy vận đơn SPX không thành công. Vui lòng thử lại sau.', 'spx-express-woocommerce' ) ),
		);
		return $map[ $slug ] ?? null;
	}
}
