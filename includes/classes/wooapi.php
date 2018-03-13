<?php 
/**
* @package     Priority Woocommerce API
* @author      Ante Laca <ante.laca@gmail.com>
* @copyright   2018 Roi Holdings
*/

namespace PriorityWoocommerceAPI;


class WooAPI extends \PriorityAPI\API
{

    private static $instance; // api instance
    private $countries = []; // countries list
    private static $priceList = []; // price lists
    /**
    * PriorityAPI initialize
    * 
    */
    public static function instance()
    {
        if (is_null(static::$instance)) {
            static::$instance = new static();    
        }
        
        return static::$instance;
    }
 
    private function __construct()
    {
        // get countries
        $this->countries = include(P18AW_INCLUDES_DIR . 'countries.php');

        /**
         * Schedule auto syncs
         */
        $syncs = [
            'sync_items_priority'     => 'syncItemsPriority',
            'sync_items_web'          => 'syncItemsWeb',
            'sync_inventory_priority' => 'syncInventoryPriority',
            'sync_pricelist_priority' => 'syncPriceLists',
            'sync_receipts_priority'  => 'syncReceipts'
        ];

        foreach ($syncs as $hook => $action) {
            // Schedule sync
            if ($this->option('auto_' . $hook, false)) {

                add_action($hook, [$this, $action]);

                if ( ! wp_next_scheduled($hook)) {
                    wp_schedule_event(time(), $this->option('auto_' . $hook), $hook); 
                }

            }

        }

    } 

    public function run()
    {
        return is_admin() ? $this->backend(): $this->frontend();
    }


    /**
     * Frontend 
     *
     */
    private function frontend()
    {
        // Sync customer and order data after order is proccessed
        add_action('woocommerce_checkout_order_processed', [$this, 'syncDataAfterOrder']);

        // sync user to priority after registration
        add_action('user_register', [$this, 'syncCustomer']);

        // filter products regarding to price list
        add_filter('loop_shop_post_in', [$this, 'filterProductsByPriceList'], 9999);

        // filter product price regarding to price list
        add_filter('woocommerce_product_get_price', function( $price, $product) {

            $data = $this->getProductDataBySku($product->get_sku());

            return $data['price_list_price'];

        }, 10, 2);


        // set shop currency regarding to price list currency 
        if($user_id = get_current_user_id()) {

            $meta = get_user_meta($user_id, '_priority_price_list');

            if ( ! empty($meta)) {

                $data = $this->getPriceListData($meta[0]);

                add_filter('woocommerce_currency', function($currency) use($data) {

                    if ($data['price_list_currency'] == '$') {
                        return 'USD';
                    }
                     
                    if ($data['price_list_currency'] == 'ש"ח') {
                        return 'ILS';
                    }

                    return $data['price_list_currency'];
        
                }, 9999);
        
            }

        }

        // regular price list ?

    }

    /**
    * Backend - PriorityAPI Admin
    * 
    */
    private function backend()
    {    
        // load language
        load_plugin_textdomain('p18a', false, plugin_basename(P18AW_DIR) . '/languages');
        // init admin
        add_action('init', function(){

            // check priority data
            if ( ! $this->option('application') || ! $this->option('environment') || ! $this->option('url')) {
                return $this->notify('Priority API data not set', 'error');
            }
          
            // admin page
            add_action('admin_menu', function(){

                // list tables classes
                include P18AW_CLASSES_DIR . 'pricelist.php';
                include P18AW_CLASSES_DIR . 'productpricelist.php';

                add_menu_page(P18AW_PLUGIN_NAME, P18AW_PLUGIN_NAME, 'manage_options', P18AW_PLUGIN_ADMIN_URL, function(){ 

                    switch($this->get('tab')) {

                        case 'syncs':
                            include P18AW_ADMIN_DIR . 'syncs.php';
                            break;

                        case 'pricelist':

                            
                            include P18AW_ADMIN_DIR . 'pricelist.php';

                            break;
                        
                        case 'show-products':

                            $data = $GLOBALS['wpdb']->get_row('
                                SELECT price_list_name 
                                FROM ' . $GLOBALS['wpdb']->prefix . 'p18a_pricelists 
                                WHERE price_list_code = ' .  intval($this->get('list')) .
                                ' AND blog_id = ' . get_current_blog_id()
                            ); 

                            if (empty($data)) {
                                wp_redirect(admin_url('admin.php?page=' . P18AW_PLUGIN_ADMIN_URL) . '&tab=pricelist');
                            }

                            include P18AW_ADMIN_DIR . 'show_products.php';

                            break;

                        default:

                            include P18AW_ADMIN_DIR . 'settings.php';
                    }
                     
                });
                
            });

            // admin actions
            add_action('admin_init', function(){
                // enqueue admin scripts
                wp_enqueue_script('p18aw-admin-js', P18AW_ASSET_URL . 'admin.js', ['jquery']);
                wp_localize_script('p18aw-admin-js', 'P18AW', [
                    'nonce'         => wp_create_nonce('p18aw_request'),
                    'working'       => __('Working', 'p18a'),
                    'sync'          => __('Sync', 'p18a'),
                    'asset_url'     => P18AW_ASSET_URL
                ]);
                    
            });

            // add post customers button
            add_action('restrict_manage_users', function(){
                printf(' &nbsp; <input id="post-query-submit" class="button" type="submit" value="' . __('Post Customers', 'p18a') . '" name="priority-post-customers">');
            });
            
            // add post orders button
            add_action('restrict_manage_posts', function($type){
                if ($type == 'shop_order') {
                    printf('<input id="post-query-submit" class="button alignright" type="submit" value="' . __('Post orders', 'p18a') . '" name="priority-post-orders">');
                }
            });


            // add column
            add_filter('manage_users_columns', function($column) {

                $column['priority_customer'] = __('Priority Customer Number', 'p18a');
                $column['priority_price_list'] = __('Price List', 'p18a');

                return $column;

            });

            // add attach list form to admin footer
            add_action('admin_footer', function(){
                echo '<form id="attach_list_form" name="attach_list_form" method="post" action="' . admin_url('users.php?paged=' . $this->get('paged')) . '"></form>';
            });

            // get column data
            add_filter('manage_users_custom_column', function($value, $name, $user_id) {

                switch ($name) {

                    case 'priority_customer':

                        $meta = get_user_meta($user_id, '_priority_customer_number');

                        if ( ! empty($meta)) {
                            return $meta[0];
                        }

                        break;

                    
                    case 'priority_price_list':

                        $lists = $this->getPriceLists();
                        $meta  = get_user_meta($user_id, '_priority_price_list');

                        $html  = '<input type="hidden" name="attach-list-nonce" value="' . wp_create_nonce('attach-list') . '" form="attach_list_form" />';
                        $html .= '<select name="price_list[' . $user_id . ']" onchange="window.attach_list_form.submit();" form="attach_list_form">';
                        foreach($lists as $list) {

                            $selected = (isset($meta[0]) && $meta[0] == $list['price_list_code']) ? 'selected' : '';

                            $html .= '<option  value="' . urlencode($list['price_list_code']) . '" ' . $selected . '>' . $list['price_list_name'] . '</option>' . PHP_EOL;
                        }

                        $html .= '</select>';

                        return $html;

                        break;

                    default:

                        return $value;

                }

            }, 10, 3);

            // save settings
            if ($this->post('p18aw-save-settings') && wp_verify_nonce($this->post('p18aw-nonce'), 'save-settings')) {

                $this->updateOption('walkin_number',  $this->post('walkin_number'));

                // save shipping conversion table
                foreach($this->post('shipping') as $key => $value) {
                    $this->updateOption('shipping_' . $key, $value);
                }

                // save payment conversion table
                foreach($this->post('payment') as $key => $value) {
                    $this->updateOption('payment_' . $key, $value);
                }

                $this->notify('Settings saved');

            }

            // save sync settings
            if ($this->post('p18aw-save-sync') && wp_verify_nonce($this->post('p18aw-nonce'), 'save-sync')) {

                $this->updateOption('log_items_priority',           $this->post('log_items_priority'));
                $this->updateOption('auto_sync_items_priority',     $this->post('auto_sync_items_priority'));
                $this->updateOption('log_items_web',                $this->post('log_items_web'));
                $this->updateOption('auto_sync_items_web',          $this->post('auto_sync_items_web'));
                $this->updateOption('log_inventory_priority',       $this->post('log_inventory_priority'));
                $this->updateOption('auto_sync_inventory_priority', $this->post('auto_sync_inventory_priority'));
                $this->updateOption('log_pricelist_priority',       $this->post('log_pricelist_priority'));
                $this->updateOption('auto_sync_pricelist_priority', $this->post('auto_sync_pricelist_priority'));
                $this->updateOption('log_receipts_priority',       $this->post('log_receipts_priority'));
                $this->updateOption('auto_sync_receipts_priority', $this->post('auto_sync_receipts_priority'));
                $this->updateOption('log_customers_web',            $this->post('log_customers_web'));
                $this->updateOption('log_shipping_methods',         $this->post('log_shipping_methods'));
                $this->updateOption('log_orders_web',               $this->post('log_orders_web'));
                $this->updateOption('sync_onorder_receipts',        $this->post('sync_onorder_receipts'));

                $this->notify('Sync settings saved');
            }


            // attach price list
            if ($this->post('price_list') && wp_verify_nonce($this->post('attach-list-nonce'), 'attach-list')) {

                foreach($this->post('price_list') as $user_id => $list_id) {
                    update_user_meta($user_id, '_priority_price_list', urldecode($list_id));
                }

                $this->notify('User price list changed');

            }

            // post customers to priority
            if ($this->get('priority-post-customers') && $this->get('users')) {

                foreach($this->get('users') as $id) {
                    $this->syncCustomer($id);
                }

                // redirect, otherwise will run twice
                if ( wp_redirect(admin_url('users.php?notice=synced'))) {
                    exit;
                }
                
            }

            // post orders to priority
            if ($this->get('priority-post-orders') && $this->get('post')) {

                foreach($this->get('post') as $id) {
                    $this->syncOrder($id);
                }

                // redirect
                if ( wp_redirect(admin_url('edit.php?post_type=shop_order&notice=synced'))) {
                    exit;
                }
                
            }

            // display notice
            if ($this->get('notice') == 'synced') {
                $this->notify('Data synced');
            }

        });

        // ajax action for manual syncs
        add_action('wp_ajax_p18aw_request', function(){

            // check nonce
            check_ajax_referer('p18aw_request', 'nonce');

            set_time_limit(420);

            // switch syncs
            switch($_POST['sync']) {

                case 'sync_items_priority':

                    try {
                        $this->syncItemsPriority();
                    } catch(Exception $e) {
                        exit(json_encode(['status' => 0, 'msg' => $e->getMessage()]));
                    }

                    break;
                case 'sync_items_web':

                    try {
                        $this->syncItemsWeb();
                    } catch(Exception $e) {
                        exit(json_encode(['status' => 0, 'msg' => $e->getMessage()]));
                    }

                    break;
                case 'sync_inventory_priority':


                    try {
                        $this->syncInventoryPriority();
                    } catch(Exception $e) {
                        exit(json_encode(['status' => 0, 'msg' => $e->getMessage()]));
                    }

                    break;

                case 'sync_pricelist_priority':


                    try {
                        $this->syncPriceLists();
                    } catch(Exception $e) {
                        exit(json_encode(['status' => 0, 'msg' => $e->getMessage()]));
                    }

                    break;

                case 'sync_receipts_priority':

                    try {

                        $this->syncReceipts();               

                    } catch(Exception $e) {
                        exit(json_encode(['status' => 0, 'msg' => $e->getMessage()]));
                    }

                    break;

                case 'sync_customers_web':
                
                    try {
                        
                        $customers = get_users(['role' => 'customer']);

                        foreach ($customers as $customer) {
                            $this->syncCustomer($customer->ID);
                        }

                    } catch(Exception $e) {
                        exit(json_encode(['status' => 0, 'msg' => $e->getMessage()]));
                    }

                    break;


                default: 

                    exit(json_encode(['status' => 0, 'msg' => 'Unknown method ' . $_POST['sync']]));

            }

            exit(json_encode(['status' => 1, 'timestamp' => date('d/m/Y H:i:s')]));


        });
        

    }  
 

    /**
     * sync items from priority
     */
    public function syncItemsPriority()
    {

        $response = $this->makeRequest('GET', 'LOGPART', [], $this->option('log_items_priority', true));

        // check response status
        if ($response['status']) {

            $response_data = json_decode($response['body_raw'], true);

            foreach($response_data['value'] as $item) {

                $data = [
                    'post_content' => '',
                    'post_status'  => 'publish',
                    'post_title'   => $item['PARTDES'],
                    'post_parent'  => '',
                    'post_type'    => 'product'
                ];

                // if product exsits, update
                if ($product_id = wc_get_product_id_by_sku($item['PARTNAME'])) {

                    $data['ID'] = $product_id;
                    // Update post
                    $id = wp_update_post($data);

                } else {
                    // Insert product
                    $id = wp_insert_post($data);

                    if ($id) {
                        update_post_meta($id, '_stock', 0);
                        update_post_meta($id, '_stock_status', 'outofstock');
                    }
                    

                }
                
                // update product meta
                if ($id) {
                    update_post_meta($id, '_sku', $item['PARTNAME']);
                    update_post_meta($id, '_regular_price', $item['BASEPLPRICE']);
                    update_post_meta($id, '_price', $item['BASEPLPRICE']);
                    update_post_meta($id, '_manage_stock', ($item['INVFLAG'] == 'Y') ? 'yes' : 'no');
                }

            }

            // add timestamp
            $this->updateOption('items_priority_update', time());

        }

    }


    /**
     * sync items from web to priority
     *
     */
    public function syncItemsWeb()
    {
        // get all items from priority
        $response = $this->makeRequest('GET', 'LOGPART');

        $data = json_decode($response['body_raw'], true);

        $SKU = []; // Priority items SKU numbers

        // collect all SKU numbers
        foreach($data['value'] as $item) {
            $SKU[] = $item['PARTNAME'];
        }

        // get all products from woocommerce
        $products = get_posts(['post_type' => 'product', 'posts_per_page' => -1]); 

        $requests      = [];
        $json_requests = [];


        // loop trough products
        foreach($products as $product) {

            $meta   = get_post_meta($product->ID);
            $method = in_array($meta['_sku'][0], $SKU) ? 'PATCH' : 'POST';
            
            $json = json_encode([
                'PARTNAME'    => $meta['_sku'][0],
                'PARTDES'     => $product->post_title,
                'BASEPLPRICE' => (float) $meta['_regular_price'][0],
                'INVFLAG'     => ($meta['_manage_stock'][0] == 'yes') ? 'Y' : 'N'
            ]);  


            $this->makeRequest($method, 'LOGPART', ['body' => $json], $this->option('log_items_web', true));

        }

        // add timestamp
        $this->updateOption('items_web_update', time());


    }


    /**
     * sync inventory from priority
     */
    public function syncInventoryPriority()
    {

        $response = $this->makeRequest('GET', 'LOGPART?$expand=LOGCOUNTERS_SUBFORM', [], $this->option('log_inventory_priority', true));

        // check response status
        if ($response['status']) {

            $data = json_decode($response['body_raw'], true);

            foreach($data['value'] as $item) {

                if ($id = wc_get_product_id_by_sku($item['PARTNAME'])) {
                    update_post_meta($id, '_sku', $item['PARTNAME']);
                    update_post_meta($id, '_stock', $item['LOGCOUNTERS_SUBFORM'][0]['DIFF']);

                    if (intval($item['LOGCOUNTERS_SUBFORM'][0]['DIFF']) > 0) {
                        update_post_meta($id, '_stock_status', 'instock');
                    } else {
                        update_post_meta($id, '_stock_status', 'outofstock');
                    }
                }
                
            }

            // add timestamp
            $this->updateOption('inventory_priority_update', time());

        }

    }


    /**
     * sync Customer by given ID
     *
     * @param [int] $id
     */
    public function syncCustomer($id)
    {
        // check user
        if ($user = get_userdata($id)) {

            $meta = get_user_meta($id);

            $json_request = json_encode([
                'CUSTNAME'    => ($user->data->ID == 0) ? $this->option('walkin_number') : (string) $user->data->ID, // walkin customer or registered one
                'CUSTDES'     => isset($meta['first_name'], $meta['last_name']) ? $meta['first_name'][0] . ' ' . $meta['last_name'][0] : '',
                'EMAIL'       => $user->data->user_email,
                'ADDRESS'     => isset($meta['billing_address_1']) ? $meta['billing_address_1'][0] : '',
                'ADDRESS2'    => isset($meta['billing_address_2']) ? $meta['billing_address_2'][0] : '',
                'STATEA'      => isset($meta['billing_city'])      ? $meta['billing_city'][0] : '',
                'ZIP'         => isset($meta['billing_postcode'])  ? $meta['billing_postcode'][0] : '',
                'COUNTRYNAME' => isset($meta['billing_country'])   ? $this->countries[$meta['billing_country'][0]] : '',
                'PHONE'       => isset($meta['billing_phone'])     ? $meta['billing_phone'][0] : '',
            ]);
    
            $method = isset($meta['_priority_customer_number']) ? 'PATCH' : 'POST';
    
            $response = $this->makeRequest($method, 'CUSTOMERS', ['body' => $json_request], $this->option('log_customers_web', true));

            // set priority customer id
            if ($response['status']) {
                add_user_meta($id, '_priority_customer_number', $id, true); 
            }
    
            // add timestamp
            $this->updateOption('customers_web_update', time());
    
        }

    }


    /**
     * Sync order by id
     *
     * @param [int] $id
     */
    public function syncOrder($id)
    {
        $order = new \WC_Order($id);

        $data = [
            'CUSTNAME' => ( ! $order->get_customer_id()) ? $this->option('walkin_number') : (string) $order->get_customer_id(),
            'CDES'     => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'CURDATE'  => date('Y-m-d', strtotime($order->get_date_created())),
            'BOOKNUM'  => $order->get_order_number(),

        ];

        $shipping_data = [
            'NAME'        => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
            'PHONENUM'    => $order->get_billing_phone(),
            'ADDRESS'     => $order->get_shipping_address_1(),
            'STATE'       => $order->get_shipping_city(),
            'COUNTRYNAME' => $this->countries[$order->get_shipping_country()],
            'ZIP'         => $order->get_shipping_postcode(),
        ];

        // add second address if entered
        if ( ! empty($order->get_shipping_address_2())) {
            $shipping_data['ADDRESS2'] = $order->get_shipping_address_2();
        }

        $data['SHIPTO2_SUBFORM'][] = $shipping_data;

        // get shipping id
        $shipping_method    = $order->get_shipping_methods();
        $shipping_method    = array_shift($shipping_method);
        $shipping_method_id = str_replace(':', '_', $shipping_method['method_id']);

        // get ordered items
        foreach ($order->get_items() as $item) {

            $product = $item->get_product();

            if ($product) {
                $data['ORDERITEMS_SUBFORM'][] = [
                    'PARTNAME' => $product->get_sku(),
                    'TQUANT'   => (int) $item->get_quantity(),
                    'VATPRICE' => (float) $item->get_total()
                ];
            }
            
        }

        $data['ORDERITEMS_SUBFORM'][] = [
            'PARTNAME' => $this->option('shipping_' . $shipping_method_id, $order->get_shipping_method()),
            'TQUANT'   => 1,
            'VATPRICE' => (float)  $order->get_total_shipping()
        ];

        // payment info
        $data['PAYMENTDEF_SUBFORM'][] = [
            'PAYMENTCODE' => $this->option('payment_' . $order->get_payment_method(), $order->get_payment_method()),
            'QPRICE'      => floatval($order->get_total()),
            'PAYACCOUNT'  => '',
            'PAYCODE'     => ''
        ];

        // make request
        $this->makeRequest('POST', 'ORDERS', ['body' => json_encode($data)], $this->option('log_orders_web', true));

        // add timestamp
        $this->updateOption('orders_web_update', time());


    }


    /**
     * Sync customer data and order data
     *
     * @param [int] $order_id
     */
    public function syncDataAfterOrder($order_id)
    {
        // get order
        $order = new \WC_Order($order_id);

        // sync customer if it's signed in / registered
        // guest user will have id 0
        if ($customer_id = $order->get_customer_id()) {
            $this->syncCustomer($customer_id);
        }

        // sync order
        $this->syncOrder($order_id);

        if($this->option('sync_onorder_receipts')) {
            // sync receipts 
            $this->syncReceipt($order_id);
        }

    }


    /**
     * Sync price lists from priority to web
     */
    public function syncPriceLists()
    {
        $response = $this->makeRequest('GET', 'PRICELIST?$expand=PLISTCUSTOMERS_SUBFORM,PARTPRICE2_SUBFORM', [], $this->option('log_pricelist_priority', true));

        // check response status
        if ($response['status']) {

            // allow multisite
            $blog_id =  get_current_blog_id();

            // price lists table
            $table =  $GLOBALS['wpdb']->prefix . 'p18a_pricelists';

            // delete all existing data from price list table
            $GLOBALS['wpdb']->query('DELETE FROM ' . $table);

            // decode raw response
            $data = json_decode($response['body_raw'], true);

            $priceList = [];

            if (isset($data['value'])) {

                foreach($data['value'] as $list)
                {
                    /* 

                    Assign user to price list, no needed for now

                    // update customers price list
                    foreach($list['PLISTCUSTOMERS_SUBFORM'] as $customer) {
                        update_user_meta($customer['CUSTNAME'], '_priority_price_list', $list['PLNAME']);
                    }
                    */

                    // products price lists
                    foreach($list['PARTPRICE2_SUBFORM'] as $product) {

                        $GLOBALS['wpdb']->insert($table, [
                            'product_sku' => $product['PARTNAME'],
                            'price_list_code' => $list['PLNAME'],
                            'price_list_name' => $list['PLDES'],
                            'price_list_currency' => $list['CODE'],
                            'price_list_price' => $product['VATPRICE'],
                            'blog_id' => $blog_id
                        ]); 

                    }
                    
                }

                // add timestamp
                $this->updateOption('pricelist_priority_update', time());

            }


        }

    }


    /**
     * Sync receipt from web to priority for given order id
     *
     * @param [int] $id order id
     */
    public function syncReceipt($order_id)
    {

        $order = new \WC_Order($order_id);

        $data = [
            'CUSTNAME' => ( ! $order->get_customer_id()) ? $this->option('walkin_number') : (string) $order->get_customer_id(),
            'CDES' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'IVDATE' => date('Y-m-d', strtotime($order->get_date_created())),
            'BOOKNUM' => $order->get_order_number(),

        ];

        // cash payment
        if(strtolower($order->get_payment_method()) == 'cod') {

            $data['CASHPAYMENT'] = floatval($order->get_total());

        } else {

             // payment info
            $data['TPAYMENT2_SUBFORM'][] = [
                'PAYMENTCODE' => $this->option('payment_' . $order->get_payment_method(), $order->get_payment_method()),
                'QPRICE'      => floatval($order->get_total()),
                'PAYACCOUNT'  => '',
                'PAYCODE'     => ''
            ];
            
        }


        // make request
        $this->makeRequest('POST', 'TINVOICES', ['body' => json_encode($data)], $this->option('log_receipts_priority', true));

        // add timestamp
        $this->updateOption('receipts_priority_update', time());
        
    }


    /**
     * Sync receipts for completed orders
     *
     * @return void
     */
    public function syncReceipts()
    {
        // get all completed orders
        $orders = wc_get_orders(['status' => 'completed']);
        
        foreach($orders as $order) {
            $this->syncReceipt($order->get_id());
        }
    }

    // filter products by user price list
    public function filterProductsByPriceList()
    {

        if($user_id = get_current_user_id()) {

            $meta = get_user_meta($user_id, '_priority_price_list');

            if ( ! empty($meta)) {

                $products = $GLOBALS['wpdb']->get_results('
                    SELECT product_sku
                    FROM ' . $GLOBALS['wpdb']->prefix . 'p18a_pricelists
                    WHERE price_list_code = "' . $meta[0] . '"
                    AND blog_id = ' . get_current_blog_id(), 
                    ARRAY_A
                );
        
                $ids = [];
        
                // get product id
                foreach($products as $product) {
                    if ($id = wc_get_product_id_by_sku($product['product_sku'])) {
                        $ids[] = $id;
                    }
                }

                return $ids;

            }


        }

        return [];
    }


    /**
     * Get all price lists
     *
     */
    public function getPriceLists()
    {
        if (empty(static::$priceList))
        {
            static::$priceList = $GLOBALS['wpdb']->get_results('
                SELECT DISTINCT price_list_code, price_list_name FROM ' . $GLOBALS['wpdb']->prefix . 'p18a_pricelists
                WHERE blog_id = ' . get_current_blog_id(), 
                ARRAY_A
            );
        }

        return static::$priceList;
    }

    /**
     * Get price list data by price list code
     *
     * @param  $code
     */
    public function getPriceListData($code)
    {
        $data = $GLOBALS['wpdb']->get_row('
            SELECT *
            FROM ' . $GLOBALS['wpdb']->prefix . 'p18a_pricelists
            WHERE price_list_code = "' . $code . '"
            AND blog_id = ' . get_current_blog_id(), 
            ARRAY_A
        );

        return $data;

    }

    /**
     * Get product data regarding to price list assigned for user
     *
     * @param $id product id
     */
    public function getProductDataBySku($sku)
    {

        if($user_id = get_current_user_id()) {

            $meta = get_user_meta($user_id, '_priority_price_list');

            if ( ! empty($meta)) {

                $data = $GLOBALS['wpdb']->get_row('
                    SELECT price_list_price, price_list_currency
                    FROM ' . $GLOBALS['wpdb']->prefix . 'p18a_pricelists
                    WHERE product_sku = "' . $sku . '"
                    AND price_list_code = "' . $meta[0] . '"
                    AND blog_id = ' . get_current_blog_id(), 
                    ARRAY_A
                );

                return $data;

            }

        }

        return false;

    }

   

}