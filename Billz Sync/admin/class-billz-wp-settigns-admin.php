<?php

class Billz_Wp_Sync_Admin_Setting {
    
    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    string $plugin_name       The name of this plugin.
     * @param    string $version    The version of this plugin.
     */
    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;

      
        add_filter('woocommerce_settings_tabs_array', array($this, 'add_billz_tab'), 50);
        add_action('woocommerce_settings_tabs_billz', array($this, 'billz_tab_content'));
        add_action('woocommerce_update_options_billz', array($this, 'save_custom_tab_settings'));

        add_action('wp_ajax_run_billz_cron_ajax', array($this, 'run_billz_cron_ajax_handler'));
    }


    /**
     * Display the admin settings page.
     *
     * @since    1.0.0
     */
    public function display_admin_settings_page() {
        if (isset($_POST['selected_attribute'])) {
            $selected_attribute = sanitize_text_field($_POST['selected_attribute']);
            echo '<p>Selected Attribute: ' . $selected_attribute . '</p>';
        }
        include_once plugin_dir_path(__FILE__) . 'admin-settings.php';
    }

    /**
     * Add custom tab to WooCommerce settings.
     *
     * @since    1.0.0
     */
    public function add_billz_tab($settings_tabs) {
        $settings_tabs['billz'] = __('billz', 'textdomain');
        return $settings_tabs;
    }

    /**
     * Display content for the custom tab.
     *
     * @since    1.0.0
     */
    public function billz_tab_content() {
        $saved_attributes = get_option('_new_attributes');
        $attribute_taxonomies = wc_get_attribute_taxonomies();
        
        $available_attributes = [];
        foreach ($attribute_taxonomies as $taxonomy) {
            $available_attributes[] = [
                'slug' => $taxonomy->attribute_name,
                'name' => $taxonomy->attribute_label
            ];
        }

        ?>
        <h3><?php _e('BILLZ SETTING', 'textdomain'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row" class="titledesc">
                    <label for="billz_sync_token"><?php _e('Secret token:', 'textdomain'); ?></label>
                </th>
                <td class="forminp forminp-text">
                    <input type="text" name="billz_sync_token" id="billz_sync_token" value="<?php echo esc_attr(get_option('_billz_sync_token')); ?>" class="regular-text">
                    <?php if (get_option('_billz_sync_token')) : ?>
                    <button id="billz_run-cron-button" class="button" style="float: right">Запустить синхронизацию</button>
                    <?php endif ?>
                </td>
               
            </tr>
        </table>
        <table id="attribute-table">
            <thead>
                <tr>
                    <th>Attr slug wooc</th>
                    <th>Attr billz name</th>
                    <th>for variation?</th>
                    <th>Is visible?</th>
                    <th>Delete</th>
                </tr>
            </thead>
            <tbody>
            <?php
            if (!empty($saved_attributes)) {
                foreach ($saved_attributes as $i => $attribute) {
                    ?>
                    <tr class="attribute-group">
                        <td>
                            <select name="slug_wooc[<?php echo $i; ?>]">
                                <option value="">Выберите атрибут</option>
                                <?php foreach ($available_attributes as $attr) : ?>
                                    <option value="<?php echo esc_attr($attr['slug']); ?>" <?php selected($attribute['slug_wooc'], $attr['slug']); ?>><?php echo esc_html($attr['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="attr_billz[<?php echo $i; ?>]" value="<?php echo esc_attr($attribute['attr_billz']); ?>" placeholder="Введите текст"></td>
                        <td><input type="checkbox" name="is_variation[<?php echo $i; ?>]" <?php checked($attribute['is_variation'], 1); ?>></td>
                        <td><input type="checkbox" name="is_visible[<?php echo $i; ?>]" <?php checked($attribute['is_visible'], 1); ?>></td>
                        <td><button class="delete-row">Delete</button></td>
                    </tr>
                    <?php
                }
            } else {
                ?>
                <tr class="attribute-group">
                    <td><input type="text" name="slug_wooc[0]" placeholder="Введите slug атрибута"></td>
                    <td><input type="text" name="attr_billz[0]" placeholder="Введите название атрибута"></td>
                    <td><input type="checkbox" name="is_variation[0]" value="1"></td>
                    <td><input type="checkbox" name="is_visible[0]" value="1"></td>
                    <td><button class="delete-row">Delete</button></td>
                </tr>
                <?php
            }
            ?>
            </tbody>
        </table>
        <button type="button" class="add-more">Добавить еще</button>
        <script>
        jQuery(document).ready(function($) {
            $('#billz_run-cron-button').on('click', function(e) {
                e.preventDefault();
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'run_billz_cron_ajax',
                        security: '<?php echo wp_create_nonce('run_billz_cron_ajax_nonce'); ?>'
                    },
                    success: function(response) {
                        alert(response.data);
                    },
                    error: function(xhr, status, error) {
                        alert(xhr.responseText);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Save settings for the custom tab.
     *
     * @since    1.0.0
     */
    public function save_custom_tab_settings() {
        if (isset($_POST['billz_sync_token'])) {
            update_option('_billz_sync_token', sanitize_text_field($_POST['billz_sync_token']));
        }
    
        if (isset($_POST['slug_wooc']) && isset($_POST['attr_billz'])) {
            $new_attributes = array();
            $count = count($_POST['slug_wooc']);
            
            for ($i = 0; $i < $count; $i++) {
                $slug_wooc = sanitize_text_field($_POST['slug_wooc'][$i]);
                $attr_billz = sanitize_text_field($_POST['attr_billz'][$i]);
                $is_variation = isset($_POST['is_variation'][$i]) ? 1 : 0;
                $is_visible = isset($_POST['is_visible'][$i]) ? 1 : 0;
                $new_attributes[$i] = array(
                    'slug_wooc' => $slug_wooc,
                    'attr_billz' => $attr_billz,
                    'is_variation' => $is_variation,
                    'is_visible'   => $is_visible
                );
            }
            update_option('_new_attributes', $new_attributes);
        }
    
        if (empty($new_attributes)) {
            delete_option('_new_attributes'); 
        }
    }

     /**
     * Run cron job.
     *
     * @since    1.0.0
     */

    public function run_billz_cron_ajax_handler() {

        if ( ! check_ajax_referer( 'run_billz_cron_ajax_nonce', 'security', false ) ) {
            wp_send_json_error( 'Security check failed' );
        }
    
        if ( false === as_next_scheduled_action( 'billz_force_collect_products' ) ) {
			as_schedule_single_action( strtotime( 'now' ), 'billz_force_collect_products', array(true), 'BILLZ' );
		}else {
            wp_send_json_success( 'Задача уже создана' );
        }
       
        wp_send_json_success( 'Задача создана' );
    }

}