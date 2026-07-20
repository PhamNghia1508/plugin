<?php
defined( 'ABSPATH' ) || exit;

final class SPX_Admin_Cancel {
	const CAP='manage_woocommerce';const NONCE='spx_cancel_shipment';
	public static function init():void{add_action('admin_post_spx_cancel_shipment',array(__CLASS__,'handle'));}
	public static function render($order):void{
		if(!$order instanceof WC_Order||!current_user_can(self::CAP)){return;}$tracking=(string)$order->get_meta('_spx_tracking_number',true);if(!SPX_Tracking_Service::is_real_tracking($tracking)||'test'!==(string)$order->get_meta('_spx_shipment_environment',true)){return;}$state=(string)$order->get_meta('_spx_cancel_state',true);$masked=self::mask($tracking);
		echo '<div class="spx-cancel-admin"><h3>'.esc_html__('Hủy vận đơn SPX','spx-express-woocommerce').'</h3><p><strong>'.esc_html__('Hành động này chỉ hủy vận đơn SPX, không hủy đơn WooCommerce và không hoàn tiền.','spx-express-woocommerce').'</strong></p><p>'.esc_html(sprintf(__('Mã vận đơn: %s','spx-express-woocommerce'),$masked)).'</p>';
		if('succeeded'===$state){echo '<p>'.esc_html__('Vận đơn đã được hủy.','spx-express-woocommerce').'</p></div>';return;}if('unknown'===$state){echo '<p>'.esc_html__('Kết quả hủy chưa xác định. Chỉ kiểm tra lại trạng thái; không gửi lại yêu cầu hủy.','spx-express-woocommerce').'</p></div>';return;}
		if('1001'===(string)$order->get_meta('_spx_shipment_status_code',true)){echo wp_nonce_field(self::NONCE.'_'.$order->get_id(),'spx_cancel_nonce',true,false).'<input type="hidden" name="order_id" value="'.esc_attr((string)$order->get_id()).'"><button type="submit" class="button delete" name="action" value="spx_cancel_shipment" formaction="'.esc_url(admin_url('admin-post.php')).'" formmethod="post" onclick="return confirm('.esc_attr(wp_json_encode(__('Hành động này chỉ hủy vận đơn SPX; không hủy đơn WooCommerce và không hoàn tiền. Tiếp tục?','spx-express-woocommerce'))).');">'.esc_html__('Hủy vận đơn SPX','spx-express-woocommerce').'</button>';}
		echo '<p class="description">'.esc_html__('Chức năng hủy vận đơn chưa được kích hoạt.','spx-express-woocommerce').'</p></div>';
	}
	public static function handle():void{
		if('POST'!==($_SERVER['REQUEST_METHOD']??'')){wp_die(esc_html__('Invalid request.','spx-express-woocommerce'),'',array('response'=>405));}if(!current_user_can(self::CAP)){wp_die(esc_html__('Permission denied.','spx-express-woocommerce'),'',array('response'=>403));}$order_id=absint($_POST['order_id']??0);check_admin_referer(self::NONCE.'_'.$order_id,'spx_cancel_nonce');$order=wc_get_order($order_id);if(!$order instanceof WC_Order){wp_die(esc_html__('Order not found.','spx-express-woocommerce'),'',array('response'=>404));}
		$result=(new SPX_Cancel_Manager())->request_cancel($order);$notice=sanitize_key((string)($result['state']??($result['error_code']??'failed')));wp_safe_redirect(add_query_arg('spx_cancel_notice',$notice,$order->get_edit_order_url()));exit;
	}
	private static function mask(string$tracking):string{$len=strlen($tracking);return $len<=8?str_repeat('*',$len):substr($tracking,0,5).str_repeat('*',max(3,$len-9)).substr($tracking,-4);}
}
