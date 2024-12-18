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
class Billz_Wp_Sync_Products {

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

    private $wpdb;
    private $products_table_name;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param      string $plugin_name       The name of the plugin.
     * @param      string $version    The version of this plugin.
     */
    public function __construct( $plugin_name, $version ) {
        global $wpdb;
        $this->plugin_name         = $plugin_name;
        $this->version             = $version;
        $this->wpdb                = $wpdb;
        $this->products_table_name = $wpdb->prefix . BILLZ_WP_SYNC_PRODUCTS_TABLE;
        $this->run();
    }

    private function run() {
        $products = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM $this->products_table_name WHERE state = %d", 0 ), ARRAY_A );

        if ( $products ) {
            foreach ( $products as $product ) {
                $product['categories'] = ! empty( $product['categories'] ) ? maybe_unserialize( $product['categories'] ) : '';
                $product['images']     = ! empty( $product['images'] ) ? maybe_unserialize( $product['images'] ) : '';
                $product['attributes'] = ! empty( $product['attributes'] ) ? maybe_unserialize( $product['attributes'] ) : '';
                $product['variations'] = ! empty( $product['variations'] ) ? maybe_unserialize( $product['variations'] ) : '';
                $product['taxonomies'] = ! empty( $product['taxonomies'] ) ? maybe_unserialize( $product['taxonomies'] ) : '';
                $product['meta']       = ! empty( $product['meta'] ) ? maybe_unserialize( $product['meta'] ) : '';

                $exist_product = $this->get_exist_product( $product );

                if ( $exist_product ) {
                    if ( 'simple' === $exist_product['type'] && ( 'variable' === $product['type'] || $exist_product['remote_product_id'] !== $product['remote_product_id'] ) ) {
                        $exist_remote_product = $this->get_exist_remote_product( $exist_product['remote_product_id'] );

                        if ( $exist_remote_product ) {
                            $exist_remote_product['images']     = ! empty( $exist_remote_product['images'] ) ? maybe_unserialize( $exist_remote_product['images'] ) : '';
                            $exist_remote_product['attributes'] = ! empty( $exist_remote_product['attributes'] ) ? maybe_unserialize( $exist_remote_product['attributes'] ) : '';
                            $exist_remote_product['variations'] = ! empty( $exist_remote_product['variations'] ) ? maybe_unserialize( $exist_remote_product['variations'] ) : '';

                            $product['type']              = 'variable';
                            $product['remote_product_id'] = '';
                            $product['sku']               = '';
                            $product['qty']               = '';
                            $product['regular_price']     = '';
                            $product['sale_price']        = '';
                            $product['images']            = array_merge( $product['images'], $exist_remote_product['images'] );
                            $product['variations']        = array_merge( $product['variations'], $exist_remote_product['variations'] );
                            $product['attributes']        = array_merge_recursive( (array) $product['attributes'], (array) $exist_remote_product['attributes'] );

                            $product['attributes'] = (object) array_map(
                                function( $attr ) {
                                    $attr['term_names']    = array_unique( $attr['term_names'] );
                                    $attr['is_visible']    = (bool) array_unique( $attr['is_visible'] )[0];
                                    $attr['for_variation'] = (bool) array_unique( $attr['for_variation'] )[0];
                                    return $attr;
                                },
                                (array) $product['attributes']
                            );

                            wp_trash_post( $exist_product['ID'] );
                            $product_id = $this->create_product( $product );
                        }
                    } else {
                        if ( 'variation' === $exist_product['type'] && 'simple' === $product['type'] ) {
                            $product['type'] = 'variable';
                        }
                        $product_id = $this->update_product( $exist_product, $product );
                    }
                } else {
                    if ( ! empty( $product['images'] ) || apply_filters( 'billz_wp_sync_create_product_without_images', false ) ) {
                        $product_id = $this->create_product( $product );
                    }
                }
                $this->wpdb->update(
                    $this->products_table_name,
                    array(
                        'state'    => 1,
                        'imported' => current_time( 'mysql' ),
                    ),
                    array( 'ID' => $product['ID'] )
                );
            }
            do_action( 'billz_wp_sync_sync_complete', $products );
        }
    }

    private function get_exist_product( $product ) {
        $remote_product_id = $product['remote_product_id'];
        if ( ! $remote_product_id ) {
            $remote_product_id = $product['variations'][0]['remote_product_id'];
        }
        $query = $this->wpdb->prepare(
            "SELECT p.ID,
                    p.post_parent,
                    COUNT(pv.post_parent) AS product_type,
                    pm.meta_value AS remote_product_id
            FROM {$this->wpdb->posts} p
            LEFT JOIN {$this->wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = '_remote_product_id')
            LEFT JOIN {$this->wpdb->posts} pv ON (p.ID = pv.post_parent AND pv.post_type = 'product_variation')
            WHERE (
                    (pm.meta_key = '_remote_product_id' AND pm.meta_value = %s) OR 
                    (pm.meta_key = '_billz_grouping_value' AND pm.meta_value = %s)
                  )
                  AND p.post_type IN ('product', 'product_variation')
                  AND (p.post_status = 'publish' OR p.post_status = 'draft')
            GROUP BY p.ID
            ORDER BY p.ID DESC
            LIMIT 1",
            $remote_product_id,
            $product['grouping_value']
        );

        $exist_product = $this->wpdb->get_row($query);
        if ( ! $exist_product ) {
            return false;
        } elseif ( intval( $exist_product->post_parent ) === 0 && intval( $exist_product->product_type ) === 0 ) {
            return array(
                'ID'                => $exist_product->ID,
                'type'              => 'simple',
                'remote_product_id' => $exist_product->remote_product_id,
            );
        } elseif ( intval( $exist_product->post_parent ) === 0 ) {
            return array(
                'ID'                => $exist_product->ID,
                'type'              => 'variable',
                'remote_product_id' => $remote_product_id,
            );
        } else {
            return array(
                'ID'                => $exist_product->post_parent,
                'type'              => 'variation',
                'remote_product_id' => $exist_product->remote_product_id,
            );
        }
    }

    private function get_variation_id_by( $by, $value, $parent_id ) {
        $product_id = $this->wpdb->get_var( $this->wpdb->prepare( "SELECT p.ID FROM {$this->wpdb->posts} p LEFT JOIN {$this->wpdb->postmeta} m on(p.id = m.post_id) WHERE m.meta_key='%s' AND m.meta_value='%s' AND p.post_type = '%s' AND p.post_parent = '%s' LIMIT 1", $by, $value, 'product_variation', $parent_id ) );
        if ( $product_id ) {
            return $product_id;
        } else {
            return false;
        }
    }

    private function get_exist_remote_product( $remote_product_id ) {
        $product = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM $this->products_table_name WHERE remote_product_id = '%s' AND state = 1 ORDER BY ID DESC LIMIT 1", $remote_product_id ), ARRAY_A );
        if ( $product ) {
            return $product;
        } else {
            return false;
        }
    }

    private function update_product( $exist_product, $args ) {
        $product_id = $exist_product['ID'];
        $product    = wc_get_product( $product_id );

        if ( isset( $args['sku'] ) ) {
            $product->set_sku( $args['sku'] );
        }

        if ( ! empty( $args['slug'] ) && apply_filters( 'billz_wp_sync_update_product_slug', false ) ) {
            $product->set_slug( $args['slug'] );
        }

        $is_variable   = 'variable' === $args['type'];
        $variation_ids = array();

        if ( $is_variable ) {
            if ( $args['variations'] ) {
                foreach ( $args['variations'] as $variation ) {
                    $variation_id = $this->get_variation_id_by( '_remote_product_id', $variation['remote_product_id'], $product_id );

                    if ( $variation_id ) {
                        $obj_variation   = wc_get_product( $variation_id );
                        $variation_exist = true;
                    } else {
                        $obj_variation   = new WC_Product_Variation();
                        $obj_variation->set_parent_id( $product_id );
                        $variation_exist = false;
                    }

                    $obj_variation->set_parent_id( $product_id );
                    if ( $variation['qty'] > 0 ) {
                        $obj_variation->set_regular_price( $variation['regular_price'] );
                        $obj_variation->set_sale_price( isset( $variation['sale_price'] ) && intval( $variation['sale_price'] ) > 0 ? $variation['sale_price'] : '' );
                        $obj_variation->set_price( isset( $variation['sale_price'] ) && intval( $variation['sale_price'] ) > 0 ? $variation['sale_price'] : $variation['regular_price'] );
                    }

                    if ( apply_filters( 'billz_wp_sync_update_product_sku', true ) && isset( $variation['sku'] ) && $variation['sku'] ) {
                        $obj_variation->set_sku( $variation['sku'] );
                    }

                    $obj_variation->set_manage_stock( true );
                    $obj_variation->set_stock_quantity( $variation['qty'] );
                    $obj_variation->set_stock_status( $variation['qty'] > 0 ? 'instock' : 'outofstock' );
                    $update_attributes = apply_filters( 'billz_wp_sync_update_product_attributes', true );
                    if ( ( ! $update_attributes && ! $variation_exist ) || $update_attributes ) {
                        $var_attributes = array();
                        foreach ( $variation['attributes'] as $vattribute ) {
                            $taxonomy                    = 'pa_' . $vattribute['name'];
                            $attr_val_slug               = wc_sanitize_taxonomy_name( stripslashes( $vattribute['option'] ) );
                            $var_attributes[ $taxonomy ] = $attr_val_slug;
                        }
                        $obj_variation->set_attributes( $var_attributes );
                    }

                    $variation_id = $obj_variation->save();

                    if ( apply_filters( 'billz_wp_sync_update_product_images', true ) && $variation['images'] ) {
                        update_post_meta( $variation_id, '_thumbnail_id', $variation['images'][0] );
                        array_shift( $variation['images'] );
                        update_post_meta( $variation_id, 'rtwpvg_images', $variation['images'] );
                    } elseif ( ! $variation['images'] && apply_filters( 'billz_wp_sync_remove_product_images_if_empty', true ) ) {
                        update_post_meta( $variation_id, '_thumbnail_id', '' );
                        update_post_meta( $variation_id, 'rtwpvg_images', array() );
                    }

                    if ( isset( $variation['remote_product_id'] ) ) {
                        update_post_meta( $variation_id, '_remote_product_id', $variation['remote_product_id'] );
                    }

                    if ( $variation['meta'] ) {
                        foreach ( $variation['meta'] as $meta_key => $meta_value ) {
                            update_post_meta( $variation_id, $meta_key, $meta_value );
                        }
                    }
                    if ( $variation['qty'] > 0 ) {
                        $variation_ids[] = $variation_id;
                        if ( $variation_exist ) {
                            wp_update_post(
                                array(
                                    'ID'          => $variation_id,
                                    'post_status' => 'publish',
                                )
                            );
                        }
                    }
                }

                if ( apply_filters( 'billz_wp_sync_disable_outofstock_variations', false ) ) {
                    $all_variation_ids    = $product->get_children();
                    $delete_variation_ids = array_diff( $all_variation_ids, $variation_ids );
                    if ( $delete_variation_ids ) {
                        foreach ( $delete_variation_ids as $delete_variation_id ) {
                            wp_update_post(
                                array(
                                    'ID'          => $delete_variation_id,
                                    'post_status' => 'private',
                                )
                            );
                        }
                    }
                    $available_variations            = $product->get_available_variations();
                    $available_variations_attributes = array();
                    if ( $available_variations ) {
                        $av = 0;
                        foreach ( $available_variations as $available_variation ) {
                            if ( ! empty( $available_variation['attributes'] ) ) {
                                foreach ( $available_variation['attributes'] as $attr_key => $attr_val ) {
                                    $av_attr_name = str_replace( 'attribute_', '', $attr_key );
                                    if ( 0 === $av ) {
                                        $args['attributes'][ $av_attr_name ]['term_names'] = array();
                                    }
                                    $attr_val_term = get_term_by( 'slug', $attr_val, $av_attr_name );
                                    if ( $attr_val_term && false === array_search( $attr_val_term->name, $args['attributes'][ $av_attr_name ]['term_names'] ) ) {
                                        $args['attributes'][ $av_attr_name ]['term_names'][] = $attr_val_term->name;
                                        $av++;
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if ( apply_filters( 'billz_wp_sync_update_product_name', true ) ) {
            $product->set_name( $args['name'] );
        }
        if ( apply_filters( 'billz_wp_sync_update_product_description', true ) ) {
            $product->set_description( $args['description'] );
        }
        if ( apply_filters( 'billz_wp_sync_update_product_short_description', true ) ) {
            $product->set_short_description( $args['short_description'] );
        }
        $product->set_status( isset( $args['status'] ) ? $args['status'] : 'publish' );
        $product->set_catalog_visibility( isset( $args['visibility'] ) ? $args['visibility'] : 'visible' );

        if ( 'simple' === $args['type'] ) {
            if ( apply_filters( 'billz_wp_sync_update_product_sku', true ) && isset( $args['sku'] ) && $args['sku'] ) {
                $product->set_sku( $args['sku'] );
            }
            $product->set_regular_price( $args['regular_price'] );
            $product->set_sale_price( isset( $args['sale_price'] ) && intval( $args['sale_price'] ) > 0 ? $args['sale_price'] : '' );
            $product->set_price( isset( $args['sale_price'] ) && intval( $args['sale_price'] ) > 0 ? $args['sale_price'] : $args['regular_price'] );
            $product->set_weight( isset( $args['weight'] ) ? $args['weight'] : '' );
            $product->set_length( isset( $args['length'] ) ? $args['length'] : '' );
            $product->set_width( isset( $args['width'] ) ? $args['width'] : '' );
            $product->set_height( isset( $args['height'] ) ? $args['height'] : '' );
            $product->set_stock_quantity( $args['qty'] );
            $product->set_stock_status( $args['qty'] > 0 ? 'instock' : 'outofstock' );
        }

        if ( apply_filters( 'billz_wp_sync_update_product_attributes', true ) && isset( $args['attributes'] ) ) {
            $product->set_attributes( $this->get_attribute_ids( $args['attributes'], true, $product_id ) );
        }

        if ( isset( $args['default_attributes'] ) ) {
            $product->set_default_attributes( $args['default_attributes'] );
        }

        if ( isset( $args['menu_order'] ) ) {
            $product->set_menu_order( $args['menu_order'] );
        }

        if ( apply_filters( 'billz_wp_sync_update_product_categories', true ) && isset( $args['categories'] ) ) {
            $cat_ids = $args['categories'];
            if ( apply_filters( 'billz_wp_sync_merge_product_categories', false ) ) {
                $cat_ids = array_merge( $cat_ids, $product->get_category_ids() );
            }
            $product->set_category_ids( $cat_ids );
        }

        if ( apply_filters( 'billz_wp_sync_update_product_images', true ) && $args['images'] ) {
            $product->set_image_id( $args['images'][0] );
            array_shift( $args['images'] );
            if ( $args['images'] ) {
                $product->set_gallery_image_ids( $args['images'] );
            }
            // Обновляем мета-поле _thumbnail_id
            update_post_meta( $product_id, '_thumbnail_id', $product->get_image_id() );
        } elseif ( ! $args['images'] && apply_filters( 'billz_wp_sync_remove_product_images_if_empty', true ) ) {
            $product->set_image_id();
            $product->set_gallery_image_ids( array() );
            // Удаляем мета-поле _thumbnail_id, если изображения отсутствуют
            delete_post_meta( $product_id, '_thumbnail_id' );
        }

        $product_id = $product->save();

        if ( $args['meta'] ) {
            foreach ( $args['meta'] as $meta_key => $meta_value ) {
                update_post_meta( $product_id, $meta_key, $meta_value );
            }
        }

        if ( apply_filters( 'billz_wp_sync_update_product_taxonomies', true ) && $args['taxonomies'] ) {
            foreach ( $args['taxonomies'] as $tax => $terms ) {
                wp_set_post_terms( $product_id, $terms, $tax, true );
            }
        }

        if ( isset( $args['remote_product_id'] ) ) {
            update_post_meta( $product_id, '_remote_product_id', $args['remote_product_id'] );
        }

        if ( isset( $args['grouping_value'] ) ) {
            update_post_meta( $product_id, '_billz_grouping_value', $args['grouping_value'] );
        }

        return $product_id;
    }

    private function create_product( $args ) {
        if ( 'variable' === $args['type'] ) {
            $product = new WC_Product_Variable();
        } else {
            $product = new WC_Product();
        }

        if ( isset( $args['sku'] ) ) {
            $product->set_sku( $args['sku'] );
        }

        $product_id = $product->save();

        $product->set_name( $args['name'] );
        $product->set_description( $args['description'] );
        $product->set_short_description( $args['short_description'] );
        $product->set_status( isset( $args['status'] ) ? $args['status'] : 'publish' );
        $product->set_catalog_visibility( isset( $args['visibility'] ) ? $args['visibility'] : 'visible' );

        if ( 'simple' === $args['type'] ) {
            $product->set_regular_price( $args['regular_price'] );
            $product->set_sale_price( isset( $args['sale_price'] ) && intval( $args['sale_price'] ) > 0 ? $args['sale_price'] : '' );
            $product->set_price( isset( $args['sale_price'] ) && intval( $args['sale_price'] ) > 0 ? $args['sale_price'] : $args['regular_price'] );
            $product->set_weight( isset( $args['weight'] ) ? $args['weight'] : '' );
            $product->set_length( isset( $args['length'] ) ? $args['length'] : '' );
            $product->set_width( isset( $args['width'] ) ? $args['width'] : '' );
            $product->set_height( isset( $args['height'] ) ? $args['height'] : '' );
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $args['qty'] );
            $product->set_stock_status( 'instock' );
        }

        if ( ! empty( $args['slug'] ) ) {
            $product->set_slug( $args['slug'] );
        }

        if ( isset( $args['attributes'] ) ) {
            $product->set_attributes( $this->get_attribute_ids( $args['attributes'] ) );
        }

        if ( isset( $args['default_attributes'] ) ) {
            $product->set_default_attributes( $args['default_attributes'] );
        }

        if ( isset( $args['menu_order'] ) ) {
            $product->set_menu_order( $args['menu_order'] );
        }

        if ( isset( $args['categories'] ) ) {
            $product->set_category_ids( $args['categories'] );
        }

        if ( $args['images'] ) {
            $product->set_image_id( $args['images'][0] );
            array_shift( $args['images'] );
            if ( $args['images'] ) {
                $product->set_gallery_image_ids( $args['images'] );
            }
            // Обновляем мета-поле _thumbnail_id
            update_post_meta( $product_id, '_thumbnail_id', $product->get_image_id() );
        }

        $product->save();

        if ( ! empty( $args['user_id'] ) ) {
            wp_update_post(
                array(
                    'ID'          => $product_id,
                    'post_author' => $args['user_id'],
                )
            );
        }

        if ( ! empty( $args['meta'] ) ) {
            foreach ( $args['meta'] as $meta_key => $meta_value ) {
                update_post_meta( $product_id, $meta_key, $meta_value );
            }
        }

        if ( ! empty( $args['taxonomies'] ) ) {
            foreach ( $args['taxonomies'] as $tax => $terms ) {
                wp_set_post_terms( $product_id, $terms, $tax, true );
            }
        }

        if ( ! empty( $args['variations'] ) && 'variable' === $args['type'] ) {
            foreach ( $args['variations'] as $variation ) {
                $obj_variation = new WC_Product_Variation();
                $obj_variation->set_parent_id( $product_id );

                $obj_variation->set_regular_price( $variation['regular_price'] );
                $obj_variation->set_sale_price( isset( $variation['sale_price'] ) && intval( $variation['sale_price'] ) > 0 ? $variation['sale_price'] : '' );
                $obj_variation->set_price( isset( $variation['sale_price'] ) && intval( $variation['sale_price'] ) > 0 ? $variation['sale_price'] : $variation['regular_price'] );

                if ( isset( $variation['sku'] ) && $variation['sku'] ) {
                    $obj_variation->set_sku( $variation['sku'] );
                }

                $obj_variation->set_manage_stock( true );
                $obj_variation->set_stock_quantity( $variation['qty'] );
                $obj_variation->set_stock_status( 'instock' );
                $var_attributes = array();
                foreach ( $variation['attributes'] as $vattribute ) {
                    $taxonomy                    = 'pa_' . wc_sanitize_taxonomy_name( stripslashes( $vattribute['name'] ) );
                    $attr_val_slug               = wc_sanitize_taxonomy_name( stripslashes( $vattribute['option'] ) );
                    $var_attributes[ $taxonomy ] = $attr_val_slug;
                }
                $obj_variation->set_attributes( $var_attributes );
                $variation_id = $obj_variation->save();

                if ( $variation['images'] ) {
                    update_post_meta( $variation_id, '_thumbnail_id', $variation['images'][0] );
                    array_shift( $variation['images'] );
                    update_post_meta( $variation_id, 'rtwpvg_images', $variation['images'] );
                }

                if ( isset( $variation['remote_product_id'] ) ) {
                    update_post_meta( $variation_id, '_remote_product_id', $variation['remote_product_id'] );
                }

                if ( $variation['meta'] ) {
                    foreach ( $variation['meta'] as $meta_key => $meta_value ) {
                        update_post_meta( $variation_id, $meta_key, $meta_value );
                    }
                }
            }
        }

        if ( isset( $args['remote_product_id'] ) ) {
            update_post_meta( $product_id, '_remote_product_id', $args['remote_product_id'] );
        }

        if ( isset( $args['grouping_value'] ) ) {
            update_post_meta( $product_id, '_billz_grouping_value', $args['grouping_value'] );
        }

        return $product_id;
    }

    private function get_attribute_ids( $attributes, $append = false, $product_id = false ) {
        $data = array();
        $position = 0;

        // Проверка, что $attributes является массивом
        if ( ! is_array( $attributes ) ) {
            $logger = wc_get_logger();
            $logger->error( 'Ошибка: $attributes не является массивом.', array( 'source' => 'billz-wp-sync-error' ) );
            return $data; // Возвращаем пустой массив или можно обработать ошибку иначе
        }

        foreach ( $attributes as $taxonomy => $values ) {
            if ( ! taxonomy_exists( $taxonomy ) || empty( $values['term_names'] ) ) {
                continue;
            }

            $attribute = new WC_Product_Attribute();
            $term_ids  = array();

            if ( $append && $product_id ) {
                $term_ids = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'ids' ) );
            }

            foreach ( $values['term_names'] as $term_name ) {
                if ( term_exists( $term_name, $taxonomy ) ) {
                    $term_ids[] = get_term_by( 'name', $term_name, $taxonomy )->term_id;
                } else {
                    $term = wp_insert_term( $term_name, $taxonomy, array( 'slug' => wc_sanitize_taxonomy_name( $term_name ) ) );
                    if ( is_wp_error( $term ) ) {
                        $logger = wc_get_logger();
                        $logger->info( $term->get_error_message(), array( 'source' => 'billz-wp-sync-error' ) );
                        continue;
                    }
                    $term_ids[] = $term['term_id'];
                }
            }

            $taxonomy_id = wc_attribute_taxonomy_id_by_name( $taxonomy );

            $attribute->set_id( $taxonomy_id );
            $attribute->set_name( $taxonomy );
            $attribute->set_options( $term_ids );
            $attribute->set_position( $position );
            $attribute->set_visible( $values['is_visible'] );
            $attribute->set_variation( $values['for_variation'] );

            $data[ $taxonomy ] = $attribute;
            $position++;
        }

        return $data;
    }

}
