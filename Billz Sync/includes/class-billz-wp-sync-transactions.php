<?php
/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://billz.uz
 * @since      1.0.0
 *
 * @package    Billz_Wp_Sync
 * @subpackage Billz_Wp_Sync/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Billz_Wp_Sync
 * @subpackage Billz_Wp_Sync/public
 * @author     Davletbaev Emil <emildv.0704@gmail.com>
 */
class Billz_Wp_Sync_Transactions {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string $plugin_name       The name of the plugin.
	 * @param      string $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
	}

	public function add_meta_boxes() {
		add_meta_box( 'billz-wp-sync-order-section', 'BILLZ', array( $this, 'billz_wp_sync_order_section_view' ), 'woocommerce_page_wc-orders', 'side', 'low' );
	}

	public function billz_wp_sync_order_section_view() {
		$post = get_post($_GET['id']);
		$transaction_ids      = get_post_meta( $post->ID, '_billz_wp_sync_transaction_ids', true );
		$transaction_finished = get_post_meta( $post->ID, '_billz_wp_sync_transaction_finished', true );
		?>
			<div id="billz-form">
				<button class="button button-primary" style="width: 100%;"
								id="billz-park-transaction" <?php disabled( true, ! empty( $transaction_ids ) ); ?>>
				<?php if ( $transaction_ids && $transaction_finished ) : ?>
						Товары проданы
					<?php elseif ( $transaction_ids && ! $transaction_finished ) : ?>
						Товары отложены
					<?php else : ?>
						Отложить товары
					<?php endif; ?>
				</button>
			</div>
			<script type="text/javascript">
						(function ($) {
								let orderID = <?php echo $post->ID; ?>;
								$('#billz-park-transaction').on('click', function (e) {
										e.preventDefault();
										$.ajax({
												url: ajaxurl,
												data: {
														action: 'billz_wp_sync_create_transaction',
														order_id: orderID,
														parked: true
												},
												method: 'POST',
												context: $(this),
												dataType: 'json',
												success: function (res) {
														if (res.success) {
																alert('Отложка успешно создана');
																$(this).text('Товары отложены');
																$('#billz-create-transaction').text('Продать отложенные товары');
																$(this).attr('disabled', 'disabled');
														} else {
																alert(res.data);
														}
												}
										});
								});
						})(jQuery);
			</script>
		<?php
	}

	public function create_transaction_ajax() {
		$order_id        = ! empty( $_POST['order_id'] ) ? intval( $_POST['order_id'] ) : 0;
		$order           = wc_get_order( $order_id );

		if ( $order->get_items() ) {
			
			foreach ( $order->get_items() as $order_item_product ) {

				$order_item_product_id = $order_item_product->get_id();
				$product_id            = $order_item_product->get_product_id();
				$variation_id          = $order_item_product->get_variation_id();
				
				$billz_shop_id         = wc_get_order_item_meta( $order_item_product_id, '_billz_wp_sync_office_id', true );
				$product_id_for_meta   = ( $variation_id ) ? $variation_id : $product_id;

				$billz_products[$billz_shop_id][] = array(
					'product_id' => get_post_meta( $product_id_for_meta, '_remote_product_id', true ),
					'measurement_value' => $order_item_product->get_quantity()
				);		
				if ( ! $billz_shop_id ) {
					wp_send_json_error( "1) Выберите магазины отгрузки\n2) Нажмите на кнопку обновить\n3) Попробуйте заново" );
				}
			}
		}
		if ( $billz_products ) {
			foreach($billz_products as $shop_id => $products) {
				$order_id = billz_create_order($shop_id);
				if ($order_id) {
					foreach($products as $product) {
						$order = billz_order_add_item($product['product_id'], $product['measurement_value'], $order_id['result']);
					}
				}	
			}
			wp_send_json_success( 'Processing completed successfully', 200 );
		}
	}

	public function woocommerce_admin_order_item_headers() {
		echo '<th class="item_shop">Магазин отгрузки</th>';
	}

	public function woocommerce_admin_order_item_values( $product, $item, $item_id ) {
		$url = 'https://api-admin.billz.ai/v1/shop?limit=100&only_allowed=true';
	
		$args = array(
			'method' => 'GET',
			'timeout' => 300,
		);
	
		$result = send_billz_api_request($url, $args);
		
		if ($result !== false) {
			$billz_shops = $result['shops'];
		} else {
			$billz_shops = array(); 
		}
	
		

		if ( empty( $product ) ) {
			echo '<td class="shop"></td>';
		} else {
			$product_id       = $product->get_id();
			$billz_shop_stock = get_post_meta( $product_id, '_billz_wp_sync_offices', true );
			$billz_shop_id    = wc_get_order_item_meta( $item_id, '_billz_wp_sync_office_id', true );
		
			?>
			<td class="shop">
				<select name="billz_office[<?php echo esc_attr( $item_id ); ?>]">
					<option value="">Выберите магазин</option>
					<?php if ( $billz_shop_stock ) : ?>
						<?php foreach ( $billz_shop_stock as $shop ) : ?>                                
								<option value="<?php echo esc_attr( $shop['shop_id'] ); ?>" <?php selected( $billz_shop_id, $shop['shop_id'] ); ?>>
									<?php echo esc_attr( $shop['shop_name'] ); ?>
									(<?php echo esc_attr( $shop['active_measurement_value'] ); ?> шт.)
								</option>
						<?php endforeach; ?>
	
						
					<?php endif; ?>
				</select>
			</td>
			<?php
		}
	}

	public function woocommerce_hidden_order_itemmeta( $hidden_order_itemmeta ) {
		$hidden_order_itemmeta[] = '_billz_wp_sync_office_id';
		return $hidden_order_itemmeta;
	}

	public function save_post_shop_order( $order_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $order_id;
		}
		if ( ! current_user_can( 'edit_post', $order_id ) ) {
			return $order_id;
		}
		
		if ( ! empty( $_POST['billz_office'] ) ) {
			foreach ( $_POST['billz_office'] as $item_id => $shop_id ) {
				wc_update_order_item_meta( $item_id, '_billz_wp_sync_office_id', $shop_id );
			}
		}
	}

	public function pre_post_update( $order_id ) {
		global $post;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $order_id;
		}
		if ( ! current_user_can( 'edit_post', $order_id ) ) {
			return $order_id;
		}

		if ( empty( $post->post_type ) || 'shop_order' !== $post->post_type ) {
			return $order_id;
		}

		if ( 'wc-completed' === $_POST['order_status'] ) {
			foreach ( $_POST['billz_office'] as $item_id => $shop_id ) {
				if ( ! $shop_id ) {
					wp_die( 'Выберите магазин отправки' );
				}
			}
		}
	}

}
