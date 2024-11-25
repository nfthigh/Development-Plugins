<?php 
/**
 * Billz WP Sync Functions
 *
 * @package    Billz_Wp_Sync
 * @subpackage Billz_Wp_Sync/functions
 */

// Определение желаемого shop_id как константы для удобства управления.
define('BILLZ_DESIRED_SHOP_ID', '2298e022-5f7b-4ff9-ade6-fef8dacc3e93');

/**
 * Получение ID категорий из продуктов.
 *
 * @param array $product_categories Категории продуктов.
 * @return array ID категорий.
 */
function billz_wp_sync_get_category_ids_from_product_categories($product_categories) {
    $cat_names = array();
    $category_ids = array();

    foreach ($product_categories as $categories) {
        $cat_names[] = explode(' > ', $categories['name']);
    }

    foreach ($cat_names as $cat_name) {
        $category_ids = array_merge($category_ids, billz_wp_sync_get_category_ids($cat_name));
    }

    return $category_ids;
}

/**
 * Получение ID категорий по именам.
 *
 * @param array $cats Имена категорий.
 * @return array ID категорий.
 */
function billz_wp_sync_get_category_ids($cats) {
    $cat_ids = array();
    $product_cat_tax = 'product_cat';
    $parent = 0;

    foreach ($cats as $cat) {
        $category_title = isset($cat) ? preg_replace('/\s+/', ' ', $cat) : '';

        if ($category_title) {
            $category = term_exists($category_title, $product_cat_tax, $parent);
            if (!$category) {
                $category = wp_insert_term($category_title, $product_cat_tax, array('parent' => $parent));
            }
            if (is_array($category)) { // Проверка успешности вставки термина
                $cat_ids[] = $category['term_id'];
                $parent = $category['term_id'];
            }
        }
    }

    return $cat_ids;
}

/**
 * Получение ID изображений продукта.
 *
 * @param array $product Данные продукта.
 * @return array ID изображений.
 */
function billz_wp_sync_get_image_ids( $product ) {
    $image_ids = array();
    $logger = wc_get_logger();

    foreach ( $product['photos'] as $image ) {
        $image_url = $image['photo_url']; // Новый URL изображения
        $image_name = pathinfo( $image_url, PATHINFO_FILENAME ); // Имя файла изображения

        // Проверка наличия изображения по оригинальному URL
        $attachment_id = get_attachment_id_by_meta('_original_image_url', $image_url);

        // Если изображение не найдено по URL, проверяем по имени файла
        if ( is_null( $attachment_id ) ) {
            $attachment_id = get_attachment_id_by_meta('_wp_attached_file', '%' . $image_name . '%', true);
        }

        // Если изображение не найдено ни по URL, ни по имени, загружаем его
        if ( is_null( $attachment_id ) ) {
            $upload = wc_rest_upload_image_from_url( $image_url );
            if ( is_wp_error( $upload ) ) {
                $logger->error( $upload->get_error_message(), array( 'source' => 'billz-wp-sync-error' ) );
                continue;
            } else {
                $attachment_id = wc_rest_set_uploaded_image_as_attachment( $upload );
                update_post_meta( $attachment_id, '_wp_attachment_image_alt', $product['name'] );
                // Сохранение оригинального URL для будущих проверок
                update_post_meta( $attachment_id, '_original_image_url', $image_url );
                add_post_meta( $attachment_id, 'delete_billz_product_photo_flag', '1', true );
            }
        }

        $image_ids[] = $attachment_id;
    }

    return $image_ids;
}

/**
 * Получение ID вложения по мета-ключу и значению.
 *
 * @param string $meta_key Ключ мета-данных.
 * @param string $meta_value Значение мета-данных.
 * @param bool $like Использовать LIKE вместо точного совпадения.
 * @return int|null ID вложения или null, если не найдено.
 */
function get_attachment_id_by_meta($meta_key, $meta_value, $like = false) {
    global $wpdb;

    if ($like) {
        $query = $wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value LIKE %s", $meta_key, $meta_value);
    } else {
        $query = $wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}postmeta WHERE meta_key = %s AND meta_value = %s", $meta_key, $meta_value);
    }

    $attachment_id = $wpdb->get_var( $query );

    return $attachment_id ? (int)$attachment_id : null;
}

/**
 * Удаление изображений, отмеченных для удаления.
 */
function billz_wp_sync_delete_images() {
    $attachments = get_posts( array(
        'post_type'      => 'attachment',
        'meta_key'       => 'delete_billz_product_photo_flag',
        'meta_value'     => '1',
        'posts_per_page' => -1,
    ) );

    foreach ( $attachments as $attachment ) {
        wp_delete_attachment( $attachment->ID, true );
    }
}

/**
 * Получение ID терминов по данным.
 *
 * @param array $data Данные.
 * @return array ID терминов.
 */
function billz_wp_sync_get_term_ids( $data ) {
    $result = array();

    foreach ( $data as $tax => $attrs ) {
        if ( $attrs ) {
            $tax_terms = array();
            foreach ( $attrs as $attr ) {
                $term = term_exists( $attr, $tax );
                if ( ! $term ) {
                    $term = wp_insert_term( $attr, $tax );
                }
                if ( is_array($term) ) { // Проверка успешности вставки термина
                    $tax_terms[] = $term['term_id'];
                }
            }
            $result[ $tax ] = $tax_terms;
        }
    }

    return $result;
}

/**
 * Обновление токена доступа.
 */
function billz_update_token() {
    $secret_token = get_option('_billz_sync_token');
    if ($secret_token) {
        $url = "https://api-admin.billz.ai/v1/auth/login";
        $args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'WordPress'
            ),
            'body' => json_encode(array(
                'secret_token' => $secret_token
            ))
        );

        $response = wp_remote_post($url, $args);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
    
        if (isset($data['data']['access_token'])) {    
            update_option('_billz_access_token', $data['data']['access_token']);
            update_option('_billz_access_token_expires_in', time() + $data['data']['expires_in']);
        }
    }   
}

/**
 * Отправка запроса к API Billz.
 *
 * @param string $url URL запроса.
 * @param array  $args Аргументы запроса.
 * @param bool   $retry Повторная попытка в случае ошибки.
 * @return mixed Ответ API или false в случае ошибки.
 */
function send_billz_api_request($url, $args, $retry = true) {
    $access_token = get_option('_billz_access_token');
    if (!$access_token) {
        billz_update_token();
        $access_token = get_option('_billz_access_token');
    }

    $expires_in = get_option('_billz_access_token_expires_in');
    $current_time = time();
    if ($expires_in && $current_time > $expires_in) {
        billz_update_token();
        $access_token = get_option('_billz_access_token');
    }

    $headers = array(
        'Accept' => 'application/json',
        'Authorization' => 'Bearer ' . $access_token,
    );
    $args['headers'] = $headers;

    $logger = wc_get_logger();
    // Убрано логирование заголовков и аргументов запроса
    $logger->info('Отправка запроса к API: ' . $url, array('source' => 'billz-wp-sync'));

    $response = wp_remote_request($url, $args);

    if (is_wp_error($response)) {
        $logger->error('Ошибка запроса: ' . $response->get_error_message(), array('source' => 'billz-wp-sync'));
        if ($retry) {
            $logger->info('Повторный запрос...', array('source' => 'billz-wp-sync'));
            return send_billz_api_request($url, $args, false);
        } else {
            return false;
        }
    } else {
        $body = wp_remote_retrieve_body($response);

        // Логирование полного ответа от API только в случае ошибки
        $parsed_data = json_decode($body, true);
        if ($parsed_data === null) {
            $logger->error('Ошибка парсинга JSON в теле ответа: ' . json_last_error_msg(), array('source' => 'billz-wp-sync'));
            return false;
        } else {
            // Проверяем, содержит ли ответ ошибку
            if (isset($parsed_data['error'])) {
                $logger->error('Ошибка от API: ' . print_r($parsed_data['error'], true), array('source' => 'billz-wp-sync'));
                return false;
            }

            // Логирование только в случае критических данных или при необходимости
            // Например, если требуется, раскомментируйте следующую строку для отладки
            // $logger->debug('Ответ от API: ' . $body, array('source' => 'billz-wp-sync'));

            return $parsed_data;
        }
    }
}

/**
 * Создание заказа в API Billz.
 *
 * @param string $shop_id ID магазина.
 * @return mixed Ответ API или завершение с ошибкой.
 */
function billz_create_order($shop_id) {
    $url = 'https://api-admin.billz.ai/v1/orders';
    $body = json_encode( array(
        'method' => 'order.create',
        'params' => array(
            'shop_id' => $shop_id
        )
    ));

    $args = array(
        'method' => 'POST',
        'body' => $body,
        'timeout' => 300,
    );

    $order = send_billz_api_request($url, $args);

    if ($order !== false) {
        return $order;
    } else {
        wp_send_json_error( "Ошибка при создании заказа");
    }
}

/**
 * Добавление товара в заказ.
 *
 * @param string $product_id ID продукта.
 * @param float  $measurement_value Значение измерения.
 * @param string $order_id ID заказа.
 * @return mixed Ответ API или завершение с ошибкой.
 */
function billz_order_add_item($product_id, $measurement_value, $order_id) {
    $url = 'https://api-admin.billz.ai/v1/orders';
    $body = json_encode( array(
        'method' => 'order.add_item',
        'params' => array(
            'product_id' => $product_id,
            'measurement_value' => $measurement_value,
            'order_id'    => $order_id
        )
    ));
    
    $args = array(
        'method' => 'POST',
        'body' => $body,
        'timeout' => 300,
    );

    $order = send_billz_api_request($url, $args);

    if ($order !== false) {
        return $order;
    } else {
        wp_send_json_error( "Ошибка в попытке добавить товар к заказу id: " . $product_id );
    }
}

/**
 * Обработка атрибутов продукта.
 *
 * @param array $product_attributes Атрибуты продукта.
 * @param array $product_custom_fields Пользовательские поля продукта.
 * @return array Обработанные атрибуты.
 */
function process_product_attributes($product_attributes, $product_custom_fields) {
    $attributes = array();
    $option_attributes = get_option('_new_attributes');
    // Merge custom fields if available
    if ($product_custom_fields) {
        $product_attributes = array_merge($product_attributes, $product_custom_fields);
    }

    foreach ($option_attributes as $item) {
        $attr_billz = $item['attr_billz'];
        
        foreach ($product_attributes as $attribute) {
            if (isset($attribute['attribute_name']) && $attribute['attribute_name'] === $attr_billz) {
                $term_names = array($attribute['attribute_value']);
                if (isset($attribute['custom_field_value'])) {
                    $term_names[] = $attribute['custom_field_value'];
                }

                $attributes['pa_' . $item['slug_wooc']] = array(
                    'term_names' => $term_names,
                    'is_visible' => $item['is_visible'],
                    'for_variation' => $item['is_variation'] ? 1 : ''
                );
                break;
            } elseif (isset($attribute['custom_field_name']) && $attribute['custom_field_name'] === $attr_billz) {
                $attributes['pa_' . $item['slug_wooc']] = array(
                    'term_names' => array($attribute['custom_field_value']),
                    'is_visible' => $item['is_visible'],
                    'for_variation' => $item['is_variation'] ? 1 : ''
                );
                break;
            }
        }
    }

    return $attributes;
}

/**
 * Синхронизация продуктов из API Billz.
 *
 * @param bool $force Принудительная синхронизация.
 */
function synchronize_products_from_billz_api($force = false) {
    global $wpdb;
    $logger = new WC_Logger(); // Инициализация логгера
    $billz_next_sync_time = current_time('timestamp');

    $desired_shop_id = BILLZ_DESIRED_SHOP_ID; // Желаемый shop_id

    $url = 'https://api-admin.billz.ai/v2/products';
    $limit = 100;  // Лимит товаров на запрос
    $page = 1;     // Номер страницы
    $i = 0;        // Инициализация переменной $i

    $logger->info('Начало синхронизации продуктов с API Billz.', array('source' => 'billz-wp-sync'));

    $all_products = array();  // Здесь будут собраны все товары

    do {
        $request_url = add_query_arg( array(
            'limit' => $limit,
            'page'  => $page
        ), $url );
        $logger->debug('URL для запроса: ' . $request_url, array('source' => 'billz-wp-sync')); // Изменено на debug

        $args = array(
            'method' => 'GET',
            'timeout' => 300,
        );

        $logger->debug('Отправка запроса к API: ' . $request_url, array('source' => 'billz-wp-sync')); // Изменено на debug
        $result = send_billz_api_request($request_url, $args);

        if ($result === false) {
            $logger->error('Ошибка получения данных от API.', array('source' => 'billz-wp-sync'));
            return;
        }

        if (!isset($result['products']) || !is_array($result['products'])) {
            $logger->error('Отсутствуют продукты в ответе API или данные не являются массивом.', array('source' => 'billz-wp-sync'));
            return;
        }

        $logger->info('Данные успешно получены от API. Получено товаров: ' . count($result['products']), array('source' => 'billz-wp-sync'));

        // Фильтрация продуктов по shop_id
        foreach ($result['products'] as $product) {
            // Проверяем, есть ли среди shop_prices нужный shop_id
            $has_desired_shop = false;
            foreach ($product['shop_prices'] as $shop_price) {
                if ($shop_price['shop_id'] === $desired_shop_id) {
                    $has_desired_shop = true;
                    // Добавляем поле с выбранным shop_price для дальнейшей обработки
                    $product['selected_shop_price'] = $shop_price;
                    break;
                }
            }
            if ($has_desired_shop) {
                $all_products[] = $product;
            }
        }

        // Увеличиваем номер страницы для следующего запроса
        $page++;

    } while (count($result['products']) == $limit); // Продолжаем запросы, пока получаем полный лимит товаров

    // Теперь $all_products содержит только продукты из желаемого склада
    $logger->info('Всего товаров для синхронизации после фильтрации: ' . count($all_products), array('source' => 'billz-wp-sync'));

    // Обработка и сохранение товаров в базу данных
    $products = $all_products;
    $attributes_map = get_option('_new_attributes');

    foreach ($products as $product) {
        $group_by_value = !empty($product['parent_id']) ? $product['parent_id'] : $product['id'];
        $remote_product_id = $product['id'];
        $names = explode("/", $product['name']);
        $found_key = array_search($group_by_value, array_column($products, 'grouping_value'));

        // Используем выбранный shop_price
        $selected_shop_price = isset($product['selected_shop_price']) ? $product['selected_shop_price'] : null;
        if (!$selected_shop_price) {
            continue; // Пропускаем этот продукт, ошибка уже залогирована ранее
        }

        $price = $selected_shop_price['retail_price'];
        $sale = 0;

        // Установка количества на основе только "Store MarkRydenClub"
        $qty = 0;
        if ($product['shop_measurement_values']) {
            foreach($product['shop_measurement_values'] as $measurement) {
                if ($measurement['shop_id'] === $desired_shop_id) {
                    $qty = $measurement['active_measurement_value'];
                    break; // Прекращаем цикл после нахождения нужного магазина
                }
            }
        }

        $product_attributes = $product['product_attributes'];
        $product_custom_fields = $product['custom_fields'];

        $attributes = process_product_attributes($product_attributes, $product_custom_fields);
        $images = billz_wp_sync_get_image_ids($product);

        if (false === $found_key) {
            $found_key = $i;
            $products[$found_key] = array(
                'type' => '',
                'remote_product_id' => $remote_product_id,
                'name' => $names[0],
                'sku' => $product['sku'],
                'description' => isset($product['description']) ? $product['description'] : '',
                'short_description' => '',
                'qty' => $qty,
                'regular_price' => $price,
                'sale_price' => $sale,
                'grouping_value' => $group_by_value,
                'categories' => billz_wp_sync_get_category_ids_from_product_categories($product['categories']),
                'menu_order' => 0,
                'images' => $images,
                'attributes' => $attributes,
                'variations' => array(),
                'meta' => array(
                    '_billz_wp_sync_offices' => $product['shop_measurement_values']
                )
            );
            $i++;
        }

        if (!$products[$found_key]['images']) {
            $products[$found_key]['images'] = billz_wp_sync_get_image_ids($product);
        }

        $variation_attributes = array();
        foreach ($product['product_attributes'] as $attribute) {
            foreach ($attributes_map as $item) {
                if ($item['attr_billz'] == $attribute['attribute_name']) {
                    $term_names = array($attribute['attribute_value']);
                    if (isset($attributes['pa_' . $item['slug_wooc']]) && !in_array($attribute['attribute_value'], $products[$found_key]['attributes']['pa_' . $item['slug_wooc']]['term_names'])) {
                        $products[$found_key]['attributes']['pa_' . $item['slug_wooc']]['term_names'][] = $attribute['attribute_value'];
                    } else {
                        $products[$found_key]['attributes']['pa_' . $item['slug_wooc']] = array(
                            'term_names' => $term_names,
                            'is_visible' => $item['is_visible'],
                            'for_variation' => $item['is_variation'] ? 1 : ''
                        );
                    }

                    $variation_attributes[] = array(
                        'name' => $item['slug_wooc'],
                        'option' => $attribute['attribute_value']
                    );
                }
            }
        }

        $variation = array(
            'remote_product_id' => $remote_product_id,
            'regular_price' => $price,
            'sale_price' => $sale,
            'sku' => $product['sku'],
            'attributes' => $variation_attributes,
            'qty' => $qty,
            'images' => $images,
            'meta' => array(
                '_billz_wp_sync_offices' => $product['shop_measurement_values']
            )
        );

        $products[$found_key]['variations'][] = $variation;

        // Логирование только при наличии изменений (тут логируем лишь факт обработки)
        // Можно добавить дополнительную проверку, если необходимо
    }

    $table_name = $wpdb->prefix . 'billz_sync_products'; // Убедитесь, что таблица существует

    foreach ($products as $d) {
        // Определение типа продукта
        // Разворачиваем сериализованные данные вариаций для подсчета
        $variations = maybe_unserialize($d['variations']);
        $type = (is_array($variations) && count($variations) > 1) ? 'variable' : 'simple';

        if ('variable' === $type) {
            // Пропускаем вариативные товары без логирования
            continue; // Пропускаем переменные товары
        }

        $d['type'] = $type;
        $d['created'] = current_time('mysql');
        $d['user_id'] = 0;
        $d['categories'] = !empty($d['categories']) ? serialize($d['categories']) : '';
        $d['images'] = !empty($d['images']) ? serialize($d['images']) : '';
        $d['attributes'] = !empty($d['attributes']) ? serialize($d['attributes']) : '';
        $d['variations'] = !empty($d['variations']) ? serialize($d['variations']) : '';
        $d['taxonomies'] = !empty($d['taxonomies']) ? serialize($d['taxonomies']) : '';
        $d['meta'] = !empty($d['meta']) ? serialize($d['meta']) : '';

        // Проверка существования продукта по remote_product_id
        if (!empty($d['remote_product_id'])) { // Убедимся, что remote_product_id не пустой
            $existing_product = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE remote_product_id = %s",
                $d['remote_product_id']
            ));
        } else {
            $existing_product = 0; // Для переменных продуктов или без remote_product_id
        }

        if ($existing_product > 0) {
            // Обновление существующего продукта
            $update_data = $d;
            // Удаляем поля, которые не должны обновляться или могут вызвать конфликты
            unset($update_data['remote_product_id']); // Обычно это ключ для WHERE
            unset($update_data['created']); // Не обновляем дату создания
            unset($update_data['user_id']); // Не обновляем пользователя

            // Определяем форматы данных для обновления
            // Предполагается, что 'qty' является целым числом, 'regular_price' и 'sale_price' - числа с плавающей точкой, остальные поля - строки
            $formats = array();
            foreach ($update_data as $key => $value) {
                if ($key === 'qty') {
                    $formats[] = '%d';
                } elseif (in_array($key, ['regular_price', 'sale_price'])) {
                    $formats[] = '%f';
                } else {
                    $formats[] = '%s';
                }
            }

            $updated = $wpdb->update(
                $table_name,
                $update_data, // Данные для обновления
                array( 'remote_product_id' => $d['remote_product_id'] ), // Условие
                $formats, // Форматы данных
                array( '%s' ) // Формат условия
            );

            // Логирование только при успешном обновлении
            if ($updated > 0) {
                $logger->info("Продукт обновлен в базе данных: {$d['name']} (ID: {$d['remote_product_id']})", array('source' => 'billz-wp-sync'));
            }
        } else {
            // Вставка нового продукта
            $inserted = $wpdb->insert($table_name, $d, array(
                '%s', // type
                '%s', // remote_product_id
                '%s', // name
                '%s', // sku
                '%s', // description
                '%s', // short_description
                '%d', // qty
                '%f', // regular_price
                '%f', // sale_price
                '%s', // grouping_value
                '%s', // categories
                '%d', // menu_order
                '%s', // images
                '%s', // attributes
                '%s', // variations
                '%s', // meta
                '%s', // created
                '%d'  // user_id
            ));

            // Логирование только при успешной вставке
            if ($inserted !== false) {
                $logger->info("Продукт добавлен в базу данных: {$d['name']} (ID: {$d['remote_product_id']})", array('source' => 'billz-wp-sync'));
            }
        }

        // После обновления или вставки в кастомную таблицу, обновляем запас в WooCommerce

        // Найти WooCommerce продукт по _remote_product_id, remote_product_id или SKU
        $args = array(
            'post_type' => 'product',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_remote_product_id', // meta_key в postmeta начинается с нижнего подчёркивания
                    'value' => $d['remote_product_id'],
                    'compare' => '='
                ),
                array(
                    'key' => 'remote_product_id', // meta_key без нижнего подчёркивания
                    'value' => $d['remote_product_id'],
                    'compare' => '='
                ),
                array(
                    'key' => '_sku',
                    'value' => $d['sku'],
                    'compare' => '='
                )
            )
        );
        $query = new WP_Query($args);

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product_id_wc = get_the_ID();
                $product_wc = wc_get_product($product_id_wc);

                if ($product_wc) {
                    // Получаем текущее значение '_stock'
                    $current_stock = (int) get_post_meta($product_id_wc, '_stock', true);
                    $desired_qty = (int) $d['qty'];

                    // Приведение типов данных и сравнение
                    if ($current_stock === $desired_qty) {
                        continue; // Ничего не делаем, если запасы совпадают
                    }

                    // Включение управления запасом
                    $product_wc->set_manage_stock(true);

                    // Обновление количества на складе
                    $product_wc->set_stock_quantity($desired_qty);

                    // Обновление статуса наличия
                    if ($desired_qty > 0) {
                        $product_wc->set_stock_status('instock');
                    } else {
                        $product_wc->set_stock_status('outofstock');
                    }

                    // Сохранение изменений
                    $product_wc->save();

                    // Обновляем meta_key '_remote_product_id' и 'remote_product_id'
                    update_post_meta($product_id_wc, '_remote_product_id', $d['remote_product_id']);
                    update_post_meta($product_id_wc, 'remote_product_id', $d['remote_product_id']);

                    // === Новое: Установка главной картинки из базы данных ===
                    if (!empty($d['images'])) {
                        // Разбираем сериализованные данные
                        $images = maybe_unserialize($d['images']);
                        if (is_array($images) && !empty($images)) {
                            $main_image_id = $images[0]; // Предполагаем, что первый элемент - главная картинка
                            $product_wc->set_image_id($main_image_id);
                            $product_wc->save(); // Сохраняем изменения
                            $logger->info("WooCommerce продукт обновлен: {$d['name']} (ID: {$d['remote_product_id']}) установлена главная картинка (ID: {$main_image_id})", array('source' => 'billz-wp-sync'));
                        }
                    }
                    // === Конец изменений ===

                    // Логирование только при обновлении запасов
                    $logger->info("WooCommerce продукт обновлен: {$d['name']} (ID: {$d['remote_product_id']}) запас установлен на {$desired_qty}", array('source' => 'billz-wp-sync'));
                }
            }
            wp_reset_postdata();
        } else {
            // Если продукт не найден, устанавливаем запас в 0, только если он существует на сайте
            // Найти WooCommerce продукт по _remote_product_id
            $args_missing = array(
                'post_type' => 'product',
                'meta_query' => array(
                    array(
                        'key' => '_remote_product_id',
                        'value' => $d['remote_product_id'],
                        'compare' => '='
                    )
                )
            );
            $query_missing = new WP_Query($args_missing);

            if ($query_missing->have_posts()) {
                while ($query_missing->have_posts()) {
                    $query_missing->the_post();
                    $product_id_wc = get_the_ID();
                    $product_wc = wc_get_product($product_id_wc);

                    if ($product_wc) {
                        // Получаем текущее значение '_stock'
                        $current_stock = (int) get_post_meta($product_id_wc, '_stock', true);

                        if ($current_stock !== 0) {
                            // Включение управления запасом
                            $product_wc->set_manage_stock(true);

                            // Обновление количества на складе
                            $product_wc->set_stock_quantity(0);

                            // Обновление статуса наличия
                            $product_wc->set_stock_status('outofstock');

                            // Сохранение изменений
                            $product_wc->save();

                            // Обновляем meta_key '_stock' с помощью WordPress функции
                            update_post_meta($product_id_wc, '_stock', 0);

                            // Обновляем meta_key '_remote_product_id' и 'remote_product_id'
                            update_post_meta($product_id_wc, '_remote_product_id', $d['remote_product_id']);
                            update_post_meta($product_id_wc, 'remote_product_id', $d['remote_product_id']);

                            // === Новое: Установка главной картинки из базы данных ===
                            if (!empty($d['images'])) {
                                // Разбираем сериализованные данные
                                $images = maybe_unserialize($d['images']);
                                if (is_array($images) && !empty($images)) {
                                    $main_image_id = $images[0]; // Предполагаем, что первый элемент - главная картинка
                                    $product_wc->set_image_id($main_image_id);
                                    $product_wc->save(); // Сохраняем изменения
                                    $logger->info("WooCommerce продукт обновлен: {$d['name']} (ID: {$d['remote_product_id']}) установлена главная картинка (ID: {$main_image_id})", array('source' => 'billz-wp-sync'));
                                }
                            }
                            // === Конец изменений ===

                            // Логирование только при обновлении запасов
                            $logger->info("WooCommerce продукт обновлен: {$d['name']} (ID: {$d['remote_product_id']}) запас установлен на 0", array('source' => 'billz-wp-sync'));
                        }
                    }
                }
                wp_reset_postdata();
            } else {
                // Если продукт не найден на сайте, ничего не делаем
            }

            // Обновляем запас в базе данных 'billz_sync_products' только если продукт существует на сайте и текущий запас не 0
            // Получаем текущий запас из базы данных
            $current_db_qty = $wpdb->get_var( $wpdb->prepare(
                "SELECT qty FROM $table_name WHERE remote_product_id = %s",
                $d['remote_product_id']
            ));

            if ( $current_db_qty > 0 ) {
                $updated_db = $wpdb->update(
                    $table_name,
                    array( 'qty' => 0 ),
                    array( 'remote_product_id' => $d['remote_product_id'] ),
                    array( '%d' ),
                    array( '%s' )
                );

                if ( $updated_db !== false && $updated_db > 0 ) {
                    $logger->info("Запас установлен на 0 в базе данных для remote_product_id: {$d['remote_product_id']}", array('source' => 'billz-wp-sync'));
                }
            }
        }

        // После обновления запасов, устанавливаем главную картинку из базы данных
        // Это необходимо для продуктов, у которых уже есть записи в WooCommerce
        // Здесь проверяем, есть ли изображения в базе данных и устанавливаем первую из них как главную
        // Уже реализовано выше внутри цикла

    }

    // Обработка отсутствующих продуктов в API Billz
    // Получаем все remote_product_id из базы данных
    $all_remote_ids = $wpdb->get_col( "SELECT remote_product_id FROM $table_name" );

    // Получаем remote_product_id из API ответа
    $api_remote_ids = wp_list_pluck( $products, 'remote_product_id' );

    // Находим remote_product_id, которые есть в базе данных, но отсутствуют в API
    $missing_remote_ids = array_diff( $all_remote_ids, $api_remote_ids );

    if ( ! empty( $missing_remote_ids ) ) {
        foreach ( $missing_remote_ids as $missing_id ) {
            // Найти WooCommerce продукт по _remote_product_id
            $args_missing = array(
                'post_type' => 'product',
                'meta_query' => array(
                    array(
                        'key' => '_remote_product_id',
                        'value' => $missing_id,
                        'compare' => '='
                    )
                )
            );
            $query_missing = new WP_Query($args_missing);

            if ($query_missing->have_posts()) {
                while ($query_missing->have_posts()) {
                    $query_missing->the_post();
                    $product_id_wc = get_the_ID();
                    $product_wc = wc_get_product($product_id_wc);

                    if ($product_wc) {
                        // Получаем текущее значение '_stock'
                        $current_stock = (int) get_post_meta($product_id_wc, '_stock', true);

                        if ($current_stock !== 0) {
                            // Включение управления запасом
                            $product_wc->set_manage_stock(true);

                            // Обновление количества на складе
                            $product_wc->set_stock_quantity(0);

                            // Обновление статуса наличия
                            $product_wc->set_stock_status('outofstock');

                            // Сохранение изменений
                            $product_wc->save();

                            // Обновляем meta_key '_stock' с помощью WordPress функции
                            update_post_meta($product_id_wc, '_stock', 0);

                            // Обновляем meta_key '_remote_product_id' и 'remote_product_id'
                            update_post_meta($product_id_wc, '_remote_product_id', $missing_id);
                            update_post_meta($product_id_wc, 'remote_product_id', $missing_id);

                            // === Новое: Установка главной картинки из базы данных ===
                            // Получаем данные продукта из кастомной таблицы
                            $product_data = $wpdb->get_row( $wpdb->prepare(
                                "SELECT images FROM $table_name WHERE remote_product_id = %s",
                                $missing_id
                            ), ARRAY_A );

                            if ($product_data && !empty($product_data['images'])) {
                                $images = maybe_unserialize($product_data['images']);
                                if (is_array($images) && !empty($images)) {
                                    $main_image_id = $images[0]; // Предполагаем, что первый элемент - главная картинка
                                    $product_wc->set_image_id($main_image_id);
                                    $product_wc->save(); // Сохраняем изменения
                                    $logger->info("WooCommerce продукт обновлен: ID {$product_id_wc} (remote_product_id: {$missing_id}) установлена главная картинка (ID: {$main_image_id})", array('source' => 'billz-wp-sync'));
                                }
                            }
                            // === Конец изменений ===

                            // Логирование только при обновлении запасов
                            $logger->info("WooCommerce продукт обновлен: ID {$product_id_wc} (remote_product_id: {$missing_id}) запас установлен на 0", array('source' => 'billz-wp-sync'));
                        }
                    }
                }
                wp_reset_postdata();
            } else {
                // Если продукт не найден на сайте, ничего не делаем
            }

            // Проверяем, существует ли продукт на сайте перед обновлением базы данных
            $product_exists_on_site = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}posts 
                 JOIN {$wpdb->prefix}postmeta ON {$wpdb->prefix}posts.ID = {$wpdb->prefix}postmeta.post_id 
                 WHERE {$wpdb->prefix}posts.post_type = 'product' 
                 AND {$wpdb->prefix}posts.post_status = 'publish' 
                 AND {$wpdb->prefix}postmeta.meta_key = '_remote_product_id' 
                 AND {$wpdb->prefix}postmeta.meta_value = %s",
                $missing_id
            )) > 0;

            if ( $product_exists_on_site ) {
                // Получаем текущий запас из базы данных
                $current_db_qty = $wpdb->get_var( $wpdb->prepare(
                    "SELECT qty FROM $table_name WHERE remote_product_id = %s",
                    $missing_id
                ));

                if ( $current_db_qty > 0 ) {
                    $updated_db = $wpdb->update(
                        $table_name,
                        array( 'qty' => 0 ),
                        array( 'remote_product_id' => $missing_id ),
                        array( '%d' ),
                        array( '%s' )
                    );

                    if ( $updated_db !== false && $updated_db > 0 ) {
                        $logger->info("Запас установлен на 0 в базе данных для remote_product_id: {$missing_id}", array('source' => 'billz-wp-sync'));
                    }
                }
            }
        }
    }

    update_option('billz_last_sync_time', $billz_next_sync_time);

    $logger->info('Синхронизация продуктов завершена.', array('source' => 'billz-wp-sync'));
}

/**
 * Добавление ссылки на настройки плагина в список действий.
 *
 * @param array  $links Существующие ссылки.
 * @param string $plugin_file Имя файла плагина.
 * @return array Обновлённые ссылки.
 */
function add_billz_plugin_action_link($links, $plugin_file) {
    $current_domain = $_SERVER['HTTP_HOST'];
    $settings_link = '<a href="https://' . $current_domain . '/wp-admin/admin.php?page=wc-settings&tab=billz">Settings</a>';
    array_push($links, $settings_link);
    return $links;
}

?>
