<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Admin_Label {
	const CAP='manage_woocommerce'; const ACTION='spx_get_label'; const NONCE='spx_get_label';
	public static function init():void{add_action('admin_post_spx_get_label',array(__CLASS__,'handle'));}
	public static function render($order):void{
		if(!$order instanceof WC_Order||!current_user_can(self::CAP)){return;}$tracking=(string)$order->get_meta('_spx_tracking_number',true);if(!SPX_Tracking_Service::is_real_tracking($tracking)||'test'!==(string)$order->get_meta('_spx_shipment_environment',true)||'cancelled'===(string)$order->get_meta('_spx_shipment_status',true)){return;}
		echo '<div class="spx-label-admin"><h3>'.esc_html__('Nhãn vận chuyển SPX','spx-express-woocommerce').'</h3>';
		$cached=(new SPX_Label_Manager())->cached_for_order($order->get_id());if($cached){echo '<p><a class="button button-primary" href="'.esc_url($cached['url']).'" target="_blank" rel="noopener noreferrer">'.esc_html__('Mở nhãn SPX','spx-express-woocommerce').'</a> <span class="description">'.esc_html__('Liên kết tạm thời; lấy nhãn mới nếu đã hết hạn.','spx-express-woocommerce').'</span></p>';}
		$notice=isset($_GET['spx_label_notice'])?sanitize_key(wp_unslash($_GET['spx_label_notice'])):'';if($notice){$message='succeeded'===$notice?__('Đã lấy nhãn SPX. Liên kết chỉ dành cho quản trị viên và có thời hạn.','spx-express-woocommerce'):__('Không thể lấy nhãn SPX. Vui lòng kiểm tra trạng thái vận đơn.','spx-express-woocommerce');echo '<p class="'.('succeeded'===$notice?'notice-success':'notice-error').'">'.esc_html($message).'</p>';}
		echo wp_nonce_field(self::NONCE.'_'.$order->get_id(),'spx_label_nonce',true,false).'<input type="hidden" name="order_id" value="'.esc_attr((string)$order->get_id()).'"><button type="submit" class="button" name="action" value="'.esc_attr(self::ACTION).'" formaction="'.esc_url(admin_url('admin-post.php')).'" formmethod="post">'.esc_html__('Lấy/In nhãn SPX','spx-express-woocommerce').'</button></div>';
	}
	public static function handle():void{
		if('POST'!==($_SERVER['REQUEST_METHOD']??'')){wp_die(esc_html__('Invalid request.','spx-express-woocommerce'),'',array('response'=>405));}if(!current_user_can(self::CAP)){wp_die(esc_html__('Permission denied.','spx-express-woocommerce'),'',array('response'=>403));}$order_id=absint($_POST['order_id']??0);check_admin_referer(self::NONCE.'_'.$order_id,'spx_label_nonce');$order=wc_get_order($order_id);if(!$order instanceof WC_Order){wp_die(esc_html__('Order not found.','spx-express-woocommerce'),'',array('response'=>404));}
		$result=(new SPX_Label_Manager())->request_for_order($order);self::redirect($order,empty($result['success'])?'failed':'succeeded');
	}
	private static function redirect(WC_Order$order,string$notice):void{$url=add_query_arg('spx_label_notice',sanitize_key($notice),$order->get_edit_order_url());wp_safe_redirect($url);exit;}
}
