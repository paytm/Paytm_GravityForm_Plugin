<?php
/**
 * Plugin Name: Paytm Gravity Forms Payment
 * Description: Integrates Gravity Forms with Paytm Form, enabling end users to purchase goods and services through Gravity Forms.
 * Version: 3.1.0
 * Author: Paytm
 * Requires at least: 3.5
 * Tags: Paytm, Paytm Payments, PayWithPaytm, Paytm Gravity Forms, Paytm Payment Gateway
 * Tested up to: 7.0.1
 * Requires PHP: 7.4.0
 * Text Domain: Paytm Gravity Form
 */ 
require(dirname(__FILE__) . '/lib/PaytmChecksum.php');
require(dirname(__FILE__) . '/lib/PaytmHelper.php');

//add_action('wp',  array('GFPaytmForm', 'maybe_thankyou_page'), 5);

add_action('init',  array('GFPaytmForm', 'init'));

add_action('init', 'maybe_thankyou_page');


function maybe_thankyou_page(){

    if (isset($_GET['paytmcallback']) && $_GET['paytmcallback']==true) {
    
                
         //if(!self::is_gravityforms_supported())
           // return;
        
        if(! empty($_POST))
        {
            $callback_post = wp_unslash($_POST);
            $str = RGForms::get("gf_paytm_form_return");
            $str = base64_decode($str);
                    
            $settings = get_option("gf_paytm_form_settings");
            $paytm_key = rgar($settings,"paytm_key");
            
            $paytmChecksum = isset($_POST["CHECKSUMHASH"]) ? $_POST["CHECKSUMHASH"] : ""; //Sent by Paytm pg

            unset($_POST['CHECKSUMHASH']);
            $isValidChecksum = PaytmChecksum::verifySignature($_POST, $paytm_key, $paytmChecksum);

                            
            if($isValidChecksum == true)
            {
                        $objGravity = new GFPaytmForm(); 

                        $custom = isset($_POST['ORDERID']) ? $_POST['ORDERID'] : '';
                        list($vv,$entry_id) = explode("-", $custom);
                        if (class_exists('GFAPI')) {
                            $entry = GFAPI::get_entry($entry_id);
                            if (is_wp_error($entry)) {
                                $entry = false;
                            }
                        } else {
                            $entry = RGFormsModel::get_lead($entry_id);
                        }


                            /* print_r($entry); */
                        if(!$entry){
                            GFPaytmForm::log_error("Entry could not be found. Entry ID: {$entry_id}. Aborting.");
                            return;
                        }
                        GFPaytmForm::save_callback_response($entry['id'], $callback_post);
                        $objGravity->log_debug("Entry has been found." . print_r($entry, true));
                        $config = $objGravity->get_config_by_entry($entry);
                    if(!$config){
                        GFPaytmForm::log_error("Form no longer is configured with Paytm Form Addon. Form ID: {$entry["form_id"]}. Aborting.");
                        return;
                    }
                        $settings = get_option("gf_paytm_form_settings");

                        $order_id = isset($_POST['ORDERID']) ? sanitize_text_field(wp_unslash($_POST['ORDERID'])) : '';
                        $status_check = GFPaytmForm::confirm_paytm_transaction($entry, $config, $order_id);

                        $cancel = apply_filters("gform_paytm_form_pre_ipn", false, $_POST, $entry, $config);

                        if($cancel) {
                            $objGravity->log_debug("IPN processing cancelled by the gform_paytm_form_pre_ipn filter. Aborting.");
                        }
                        else if(!empty($status_check['confirmed'])) {
                            $objGravity->log_debug("Paytm transaction status confirmed. Setting payment status...");
                            $objGravity->set_payment_status($config, $entry, "SUCCESS", $order_id, null, $status_check['amount']);
                        }
                        else if(!empty($status_check['failed'])) {
                            $objGravity->log_debug("Paytm transaction status is not successful. " . $status_check['message']);
                            $objGravity->set_payment_status($config, $entry, "FAILED", $order_id, null, $status_check['amount']);
                        }
                        else{
                            $objGravity->log_debug("Paytm payment was not confirmed. " . $status_check['message']);
                        }

                        $return_page_id = rgar($settings, 'paytm_return_page');
                        $redirect_url = $return_page_id ? get_permalink($return_page_id) : home_url('/');
                        $is_success = !$cancel && !empty($status_check['confirmed']);
                        $redirect_message = $is_success ? '' : $status_check['message'];
                        $redirect_url = paytm_build_return_redirect_url($redirect_url, $callback_post, $is_success, $redirect_message);
                        wp_redirect($redirect_url);
                        exit;   
                            
            }
            else 
            {
                /*  if(isset($_POST['RESPCODE'])){
                if (!empty($callback_post['ORDERID'])) {
                    $order_parts = explode('-', $callback_post['ORDERID']);
                    $failed_entry_id = absint(end($order_parts));
                    if ($failed_entry_id) {
                        GFPaytmForm::save_callback_response($failed_entry_id, $callback_post);
                    }
                } */
                $return_page_id = rgar($settings, 'paytm_return_page');
                $redirect_url = $return_page_id ? get_permalink($return_page_id) : home_url('/');
                $redirect_url = paytm_build_return_redirect_url($redirect_url, $callback_post, false, __('Security error! Invalid payment response.', 'paytm-gravity-forms'));
                wp_redirect($redirect_url);
                exit; 
            }
        }
    }
    

}





register_activation_hook( __FILE__, array("GFPaytmForm", "add_permissions"));

add_action('wp_footer', 'paytm_render_return_modal', 20);

function paytm_build_return_redirect_url($base_url, $post_data = array(), $is_success = false, $fallback_message = '') {
    if (empty($base_url)) {
        $base_url = home_url('/');
    }

    $args = array(
        'paytm_return' => '1',
        'paytm_status' => $is_success ? 'success' : 'failed',
    );

    $field_map = array(
        'paytm_orderid'   => 'ORDERID',
        'paytm_txnid'     => 'TXNID',
        'paytm_txnamount' => 'TXNAMOUNT',
        'paytm_txndate'   => 'TXNDATE',
        'paytm_respmsg'   => 'RESPMSG',
    );

    foreach ($field_map as $query_key => $post_key) {
        if (!empty($post_data[$post_key])) {
            $args[$query_key] = base64_encode(sanitize_text_field($post_data[$post_key]));
        }
    }

    if (empty($args['paytm_respmsg']) && !empty($fallback_message)) {
        $args['paytm_respmsg'] = $fallback_message;
    } elseif (empty($args['paytm_respmsg'])) {
        $args['paytm_respmsg'] = $is_success
            ? __('Payment completed successfully.', 'paytm-gravity-forms')
            : __('Payment failed. Please try again.', 'paytm-gravity-forms');
    }

    return add_query_arg($args, $base_url);
}

function paytm_render_return_modal() {
    if (!isset($_GET['paytm_return']) || $_GET['paytm_return'] !== '1') {
        return;
    }

    $status = (isset($_GET['paytm_status']) && $_GET['paytm_status'] === 'success') ? 'success' : 'failed';
    $is_success = ($status === 'success');
    $title = $is_success
        ? __('Payment Successful', 'paytm-gravity-forms')
        : __('Payment Failed', 'paytm-gravity-forms');

    $fields = array(
        'ORDERID'   => isset($_GET['paytm_orderid']) ? sanitize_text_field(wp_unslash($_GET['paytm_orderid'])) : '',
        'TXNID'     => isset($_GET['paytm_txnid']) ? sanitize_text_field(wp_unslash($_GET['paytm_txnid'])) : '',
        'TXNAMOUNT' => isset($_GET['paytm_txnamount']) ? sanitize_text_field(wp_unslash($_GET['paytm_txnamount'])) : '',
        'TXNDATE'   => isset($_GET['paytm_txndate']) ? sanitize_text_field(wp_unslash($_GET['paytm_txndate'])) : '',
        'RESPMSG'   => isset($_GET['paytm_respmsg']) ? sanitize_text_field(wp_unslash($_GET['paytm_respmsg'])) : '',
    );

    $has_details = false;
    foreach ($fields as $value) {
        if ($value !== '') {
            $has_details = true;
            break;
        }
    }
    ?>
    <div id="paytm-return-modal-overlay" class="paytm-return-modal-overlay" role="presentation"></div>
    <div id="paytm-return-modal" class="paytm-return-modal paytm-return-modal--<?php echo esc_attr($status); ?>" role="dialog" aria-modal="true" aria-labelledby="paytm-return-modal-title">
        <div class="paytm-return-modal__content">
            <h2 id="paytm-return-modal-title" class="paytm-return-modal__title"><?php echo esc_html($title); ?></h2>
            <?php if ($has_details) : ?>
                <table class="paytm-return-modal__table">
                    <tbody>
                        <?php foreach ($fields as $label => $value) : ?>
                            <?php if ($value !== '') : ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html($label); ?></th>
                                    <td><?php echo esc_html(base64_decode($value)); ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
            <button type="button" id="paytm-return-modal-ok" class="paytm-return-modal__button"><?php esc_html_e('OK', 'paytm-gravity-forms'); ?></button>
        </div>
    </div>
    <style type="text/css">
        .paytm-return-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.55);
            z-index: 100000;
        }
        .paytm-return-modal {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 100001;
            width: 92%;
            max-width: 520px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        .paytm-return-modal__content {
            padding: 24px;
        }
        .paytm-return-modal__title {
            margin: 0 0 16px;
            font-size: 22px;
            line-height: 1.3;
        }
        .paytm-return-modal--success .paytm-return-modal__title {
            color: #1b7f3b;
        }
        .paytm-return-modal--failed .paytm-return-modal__title {
            color: #b42318;
        }
        .paytm-return-modal__table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .paytm-return-modal__table th,
        .paytm-return-modal__table td {
            padding: 8px 10px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            vertical-align: top;
            font-size: 14px;
            line-height: 1.4;
        }
        .paytm-return-modal__table th {
            width: 35%;
            color: #374151;
            font-weight: 600;
        }
        .paytm-return-modal__table td {
            color: #111827;
            word-break: break-word;
        }
        .paytm-return-modal__button {
            display: inline-block;
            min-width: 120px;
            padding: 10px 18px;
            border: 0;
            border-radius: 4px;
            background: #2271b1;
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .paytm-return-modal__button:hover,
        .paytm-return-modal__button:focus {
            background: #135e96;
        }
    </style>
    <script type="text/javascript">
    (function() {
        var okButton = document.getElementById('paytm-return-modal-ok');
        if (!okButton) {
            return;
        }
        okButton.addEventListener('click', function() {
            var url = new URL(window.location.href);
            [
                'paytm_return',
                'paytm_status',
                'paytm_orderid',
                'paytm_txnid',
                'paytm_txnamount',
                'paytm_txndate',
                'paytm_respmsg',
                'resp_msg',
                'type'
            ].forEach(function(param) {
                url.searchParams.delete(param);
            });
            window.location.href = url.toString();
        });
    })();
    </script>
    <?php
}
if(!defined("GF_PAYTM_FORM_PLUGIN_PATH"))
    define("GF_PAYTM_FORM_PLUGIN_PATH", dirname( plugin_basename( __FILE__ ) ) );
    
if(!defined("GF_PAYTM_FORM_PLUGIN"))
    define("GF_PAYTM_FORM_PLUGIN", dirname( plugin_basename( __FILE__ ) ) . "/paytm-form.php" );

if(!defined("GF_PAYTM_FORM_BASE_URL"))
    define("GF_PAYTM_FORM_BASE_URL", plugins_url(null, __FILE__) );
    
if(!defined("GF_PAYTM_FORM_BASE_PATH"))
    define("GF_PAYTM_FORM_BASE_PATH", WP_PLUGIN_DIR . "/" . basename(dirname(__FILE__)) );

class GFPaytmForm {

    private static $path = GF_PAYTM_FORM_PLUGIN;
    private static $url = "https://www.paytmpayments.com";
    private static $slug = "paytm-gravity-forms";
    private static $version = "3.1.0";
    private static $min_gravityforms_version = "1.6.4";
    private static $supported_fields = array("checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title", "post_tags", "post_custom_field", "post_content", "post_excerpt");

    //Plugin starting point. Will load appropriate files
    public static function init(){
        //supports logging
        add_filter("gform_logging_supported", array("GFPaytmForm", "set_logging_supported"));

        if(basename($_SERVER['PHP_SELF']) == "plugins.php") {

            //loading translations
            load_plugin_textdomain('paytm-gravity-forms', FALSE, GF_PAYTM_FORM_PLUGIN_PATH . '/languages' );

        }

        if(!self::is_gravityforms_supported())
           return;

        self::ensure_permissions();

        if(is_admin()){
            //loading translations
            load_plugin_textdomain('paytm-gravity-forms', FALSE,'/languages' );

            //integrating with Members plugin
            if(function_exists('members_get_capabilities'))
                add_filter('members_get_capabilities', array("GFPaytmForm", "members_get_capabilities"));

            //creates the subnav left menu
            add_filter("gform_addon_navigation", array('GFPaytmForm', 'create_menu'));

            //add actions to allow the payment status to be modified
            add_action('gform_payment_status', array('GFPaytmForm','admin_edit_payment_status'), 3, 3);
            add_action('gform_entry_info', array('GFPaytmForm','admin_edit_payment_status_details'), 4, 2);
            add_action('gform_entry_detail_sidebar_middle', array('GFPaytmForm', 'admin_display_paytm_callback_details'), 10, 2);
            add_action('gform_after_update_entry', array('GFPaytmForm','admin_update_payment'), 4, 2);


            if(self::is_paytm_form_page()){

                //loading Gravity Forms tooltips
                require_once(GFCommon::get_base_path() . "/tooltips.php");
                add_filter('gform_tooltips', array('GFPaytmForm', 'tooltips'));

                //enqueueing sack for AJAX requests
                wp_enqueue_script(array("sack"));

                //loading data lib
                require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");

                //runs the setup when version changes
                self::setup();

            }
            else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

                //loading data class
                require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");

                add_action('wp_ajax_gf_paytm_form_update_feed_active', array('GFPaytmForm', 'update_feed_active'));
                add_action('wp_ajax_gf_select_paytm_form_form', array('GFPaytmForm', 'select_paytm_form_form'));
                add_action('wp_ajax_gf_paytm_form_load_notifications', array('GFPaytmForm', 'load_notifications'));

            }
            else if(RGForms::get("page") == "gf_settings"){
                RGForms::add_settings_page("Paytm Form", array("GFPaytmForm", "settings_page"), GF_PAYTM_FORM_BASE_URL . "/images/paytm_form_wordpress_icon_32.jpg");
            }
        }
        else{
            //loading data class
            require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");

            //handling post submission.
            add_filter("gform_confirmation", array("GFPaytmForm", "send_to_paytm_form"), 1000, 4);
            add_action("gform_enqueue_scripts", array("GFPaytmForm", "enqueue_confirmation_script"), 10, 2);

            //setting some entry metas
            //add_action("gform_after_submission", array("GFPaytmForm", "set_entry_meta"), 5, 2);

            add_filter("gform_disable_post_creation", array("GFPaytmForm", "delay_post"), 10, 3);
            add_filter("gform_disable_user_notification", array("GFPaytmForm", "delay_autoresponder"), 10, 3);
            add_filter("gform_disable_admin_notification", array("GFPaytmForm", "delay_admin_notification"), 10, 3);
            add_filter("gform_disable_notification", array("GFPaytmForm", "delay_notification"), 10, 4);

            // ManageWP premium update filters
            add_filter( 'mwp_premium_update_notification', array('GFPaytmForm', 'premium_update_push') );
            add_filter( 'mwp_premium_perform_update', array('GFPaytmForm', 'premium_update') );
        }
    }

    public static function update_feed_active(){
        check_ajax_referer('gf_paytm_form_update_feed_active','gf_paytm_form_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFPaytmFormData::get_feed($id);
        GFPaytmFormData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //-------------- Automatic upgrade ---------------------------------------


    //Integration with ManageWP
    public static function premium_update_push( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        $update = GFCommon::get_version_info();
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['type'] = 'plugin';
            $plugin_data['slug'] = self::$path;
            $plugin_data['new_version'] = isset($update['version']) ? $update['version'] : false ;
            $premium_update[] = $plugin_data;
        }

        return $premium_update;
    }

    //Integration with ManageWP
    public static function premium_update( $premium_update ){

        if( !function_exists( 'get_plugin_data' ) )
            include_once( ABSPATH.'wp-admin/includes/plugin.php');

        $update = GFCommon::get_version_info();
        if( $update["is_valid_key"] == true && version_compare(self::$version, $update["version"], '<') ){
            $plugin_data = get_plugin_data( __FILE__ );
            $plugin_data['slug'] = self::$path;
            $plugin_data['type'] = 'plugin';
            $plugin_data['url'] = isset($update["url"]) ? $update["url"] : false; // OR provide your own callback function for managing the update

            array_push($premium_update, $plugin_data);
        }
        return $premium_update;
    }
    
    private static function get_key(){
        if(self::is_gravityforms_supported())
            return GFCommon::get_key();
        else
            return "";
    }
    //------------------------------------------------------------------------

    //Creates Paytm Form left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("paytm-gravity-forms");
        if(!empty($permission))
            $menus[] = array("name" => "gf_paytm_form", "label" => __("Paytm Form", "paytm-gravity-forms"), "callback" =>  array("GFPaytmForm", "paytm_form_page"), "permission" => $permission);

        return $menus;
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){
        if(get_option("gf_paytm_form_version") != self::$version)
            GFPaytmFormData::update_table();

        update_option("gf_paytm_form_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $paytm_form_tooltips = array(
            'paytm_form_installation_id' => self::format_tooltip(
                __('Paytm Form Installation ID', 'paytm-gravity-forms'),
                __('Enter the Paytm Form Installation ID where payment should be received.', 'paytm-gravity-forms')
            ),
            'paytm_form_mode' => self::format_tooltip(
                __('Mode', 'paytm-gravity-forms'),
                __('Select Production to receive live payments. Select Test for testing purposes when using the Paytm Form development sandbox.', 'paytm-gravity-forms')
            ),
            'paytm_form_transaction_type' => self::format_tooltip(
                __('Transaction Type', 'paytm-gravity-forms'),
                __('Choose how this feed should process payments. Donations are mapped to Paytm checkout for one-time contribution flows.', 'paytm-gravity-forms')
            ),
            'paytm_form_gravity_form' => self::format_tooltip(
                __('Gravity Form', 'paytm-gravity-forms'),
                __('Select the Gravity Form that should send submissions to Paytm for payment collection.', 'paytm-gravity-forms')
            ),
            'paytm_form_customer' => self::format_tooltip(
                __('Customer', 'paytm-gravity-forms'),
                __('Map Gravity Form fields to Paytm customer fields such as name, email, phone, and amount.', 'paytm-gravity-forms')
            ),
            'paytm_form_cancel_url' => self::format_tooltip(
                __('Cancel URL', 'paytm-gravity-forms'),
                __('Optional landing page URL if the user cancels before completing Paytm payment.', 'paytm-gravity-forms')
            ),
            'paytm_form_options' => self::format_tooltip(
                __('Options', 'paytm-gravity-forms'),
                __('Turn on or off the available Paytm Form checkout options.', 'paytm-gravity-forms')
            ),
            'paytm_form_delay_admin_notification' => self::format_tooltip(
                __('Admin Notification', 'paytm-gravity-forms'),
                __('When enabled, the admin notification is sent only after Paytm confirms a successful payment.', 'paytm-gravity-forms')
            ),
            'paytm_form_delay_user_notification' => self::format_tooltip(
                __('User Notification', 'paytm-gravity-forms'),
                __('When enabled, the user/autoresponder notification is sent only after Paytm confirms a successful payment.', 'paytm-gravity-forms')
            ),
            'paytm_form_delay_post' => self::format_tooltip(
                __('Delay Post Creation', 'paytm-gravity-forms'),
                __('Create the WordPress post from this submission only after payment is successfully received.', 'paytm-gravity-forms')
            ),
            'paytm_form_update_post' => self::format_tooltip(
                __('Update Post on Cancel', 'paytm-gravity-forms'),
                __('Choose what should happen to the related post when a subscription is cancelled.', 'paytm-gravity-forms')
            ),
            'paytm_form_notifications' => self::format_tooltip(
                __('Notifications', 'paytm-gravity-forms'),
                __('Delay selected Gravity Forms notifications until Paytm reports a successful payment.', 'paytm-gravity-forms')
            ),
            'paytm_form_conditional' => self::format_tooltip(
                __('Paytm Form Condition', 'paytm-gravity-forms'),
                __('When enabled, submissions are sent to Paytm only when the condition is met. When disabled, every submission uses Paytm.', 'paytm-gravity-forms')
            ),
            'paytm_form_edit_payment_amount' => self::format_tooltip(
                __('Amount', 'paytm-gravity-forms'),
                __('Enter the amount the user paid for this transaction.', 'paytm-gravity-forms')
            ),
            'paytm_form_edit_payment_date' => self::format_tooltip(
                __('Date', 'paytm-gravity-forms'),
                __('Enter the date of this transaction.', 'paytm-gravity-forms')
            ),
            'paytm_form_edit_payment_transaction_id' => self::format_tooltip(
                __('Transaction ID', 'paytm-gravity-forms'),
                __('The transaction ID returned by Paytm that uniquely identifies this payment.', 'paytm-gravity-forms')
            ),
            'paytm_form_edit_payment_status' => self::format_tooltip(
                __('Status', 'paytm-gravity-forms'),
                __('Set the payment status. This can only be changed if it is not already Approved.', 'paytm-gravity-forms')
            ),
        );

        return array_merge($tooltips, $paytm_form_tooltips);
    }

    private static function format_tooltip($title, $body) {
        return '<div class="paytm-tooltip">'
            . '<div class="paytm-tooltip__title">' . esc_html($title) . '</div>'
            . '<div class="paytm-tooltip__body">' . esc_html($body) . '</div>'
            . '</div>';
    }

    public static function delay_post($is_disabled, $form, $lead){
        //loading data class
        require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");

        $config = GFPaytmFormData::get_feed_by_form($form["id"]);
        if(!$config)
            return $is_disabled;

        $config = $config[0];
        if(!self::has_paytm_form_condition($form, $config, $lead))
            return $is_disabled;

        return $config["meta"]["delay_post"] == true;
    }

    //Kept for backwards compatibility
    public static function delay_admin_notification($is_disabled, $form, $lead){
        $config = self::get_active_config($form, $lead);

        if(!$config)
            return $is_disabled;

        return isset($config["meta"]["delay_notification"]) ? $config["meta"]["delay_notification"] == true : $is_disabled;
    }

    //Kept for backwards compatibility
    public static function delay_autoresponder($is_disabled, $form, $lead){
        $config = self::get_active_config($form, $lead);

        if(!$config)
            return $is_disabled;

        return isset($config["meta"]["delay_autoresponder"]) ? $config["meta"]["delay_autoresponder"] == true : $is_disabled;
    }

    public static function delay_notification($is_disabled, $notification, $form, $lead){
        $config = self::get_active_config($form, $lead);

        if(!$config)
            return $is_disabled;

        $selected_notifications = is_array(rgar($config["meta"], "selected_notifications")) ? rgar($config["meta"], "selected_notifications") : array();

        return isset($config["meta"]["delay_notifications"]) && in_array($notification["id"], $selected_notifications) ? true : $is_disabled;
    }

    private static function get_selected_notifications($config, $form){
        $selected_notifications = is_array(rgar($config['meta'], 'selected_notifications')) ? rgar($config['meta'], 'selected_notifications') : array();

        if(empty($selected_notifications)){
            //populating selected notifications so that their delayed notification settings get carried over
            //to the new structure when upgrading to the new Paytm Form Add-On
            if(!rgempty("delay_autoresponder", $config['meta'])){
                $user_notification = self::get_notification_by_type($form, "user");
                if($user_notification)
                    $selected_notifications[] = $user_notification["id"];
            }

            if(!rgempty("delay_notification", $config['meta'])){
                $admin_notification = self::get_notification_by_type($form, "admin");
                if($admin_notification)
                    $selected_notifications[] = $admin_notification["id"];
            }
        }

        return $selected_notifications;
    }

    private static function get_notification_by_type($form, $notification_type){
        if(!is_array($form["notifications"]))
            return false;

        foreach($form["notifications"] as $notification){
            if($notification["type"] == $notification_type)
                return $notification;
        }

        return false;

    }

    public static function paytm_form_page() {
        if (!self::has_access('paytm-gravity-forms')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'paytm-gravity-forms'));
        }

        // edit_page / stats_page read the feed id from the request themselves.
        switch (sanitize_key((string) rgget('view'))) {
            case 'edit':
                self::edit_page();
                break;
            default:
                self::list_page();
                break;
        }
    }

    //Displays the paytm_form feeds list page
    private static function list_page() {
        if (!self::is_gravityforms_supported()) {
            wp_die(
                sprintf(
                    /* translators: 1: minimum Gravity Forms version, 2: opening anchor, 3: closing anchor */
                    esc_html__('Paytm Form Add-On requires Gravity Forms %1$s. Upgrade automatically on the %2$sPlugin page%3$s.', 'paytm-gravity-forms'),
                    esc_html(self::$min_gravityforms_version),
                    '<a href="' . esc_url(admin_url('plugins.php')) . '">',
                    '</a>'
                )
            );
        }

        $notice = '';
        if (rgpost('action') === 'delete') {
            check_admin_referer('list_action', 'gf_paytm_form_list');
            GFPaytmFormData::delete_feed(absint(rgpost('action_argument')));
            $notice = __('Feed deleted.', 'paytm-gravity-forms');
        } elseif (rgpost('bulk_action') === 'delete') {
            check_admin_referer('list_action', 'gf_paytm_form_list');
            $selected_feeds = rgpost('feed');
            if (is_array($selected_feeds)) {
                foreach ($selected_feeds as $feed_id) {
                    GFPaytmFormData::delete_feed(absint($feed_id));
                }
            }
            $notice = __('Feeds deleted.', 'paytm-gravity-forms');
        }

        $feeds       = GFPaytmFormData::get_feeds();
        $settings    = get_option('gf_paytm_form_settings');
        $paytm_mid   = rgar($settings, 'paytm_mid');
        $add_new_url = admin_url('admin.php?page=gf_paytm_form&view=edit&id=0');
        $settings_url = admin_url('admin.php?page=gf_settings&addon=Paytm%20Form');

        $delete_feed_confirm = esc_js(__("Delete this feed? 'Cancel' to stop, 'OK' to delete.", 'paytm-gravity-forms'));
        $bulk_delete_confirm = esc_js(__("Delete selected feeds? 'Cancel' to stop, 'OK' to delete.", 'paytm-gravity-forms'));
        $label_active        = esc_attr__('Active', 'paytm-gravity-forms');
        $label_inactive      = esc_attr__('Inactive', 'paytm-gravity-forms');
        $label_edit          = esc_attr__('Edit', 'paytm-gravity-forms');
        $label_stats         = esc_attr__('View Stats', 'paytm-gravity-forms');
        $label_entries       = esc_attr__('View Entries', 'paytm-gravity-forms');
        $label_delete        = esc_attr__('Delete', 'paytm-gravity-forms');
        ?>
<div class="wrap">
    <?php if ($notice) : ?>
        <div class="updated fade" style="padding:6px"><?php echo esc_html($notice); ?></div>
    <?php endif; ?>

    <img alt="<?php echo esc_attr__('Paytm Form Transactions', 'paytm-gravity-forms'); ?>" src="<?php echo esc_url(GF_PAYTM_FORM_BASE_URL . '/images/paytm_form_wordpress_icon_32.jpg'); ?>" style="float:left; margin:15px 7px 0 0;"/>
    <h2 style="float:left; width: 100%;"><?php esc_html_e('Paytm Form List', 'paytm-gravity-forms'); ?></h2>

    <form id="feed_form" method="post">
        <?php wp_nonce_field('list_action', 'gf_paytm_form_list'); ?>
        <input type="hidden" id="action" name="action" value=""/>
        <input type="hidden" id="action_argument" name="action_argument" value=""/>

        <div class="tablenav">
            <div class="alignleft actions" style="padding:8px 0 7px 0;">
                <label class="screen-reader-text" for="bulk_action"><?php esc_html_e('Bulk action', 'paytm-gravity-forms'); ?></label>
                <select name="bulk_action" id="bulk_action">
                    <option value=""><?php esc_html_e('Bulk action', 'paytm-gravity-forms'); ?></option>
                    <option value="delete"><?php esc_html_e('Delete', 'paytm-gravity-forms'); ?></option>
                </select>
                <input type="submit" class="button" value="<?php echo esc_attr__('Apply', 'paytm-gravity-forms'); ?>" onclick="if (jQuery('#bulk_action').val() === 'delete' && !confirm('<?php echo $bulk_delete_confirm; ?>')) { return false; } return true;"/>
                <a style="margin-top: 3px;" class="button add-new-h2" href="<?php echo esc_url($add_new_url); ?>"><?php esc_html_e('Add New', 'paytm-gravity-forms'); ?></a>
            </div>
        </div>

        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-cb check-column"><input type="checkbox" /></th>
                    <th scope="col" class="manage-column check-column"></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Form', 'paytm-gravity-forms'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Transaction Type', 'paytm-gravity-forms'); ?></th>
                </tr>
            </thead>
            <tfoot>
                <tr>
                    <th scope="col" class="manage-column column-cb check-column"><input type="checkbox" /></th>
                    <th scope="col" class="manage-column check-column"></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Form', 'paytm-gravity-forms'); ?></th>
                    <th scope="col" class="manage-column"><?php esc_html_e('Transaction Type', 'paytm-gravity-forms'); ?></th>
                </tr>
            </tfoot>
            <tbody class="list:user user-list">
                <?php if (empty($paytm_mid)) : ?>
                    <tr>
                        <td colspan="4" style="padding:20px;">
                            <?php
                            echo wp_kses(
                                sprintf(
                                    /* translators: 1: opening anchor, 2: closing anchor */
                                    __('To get started, please configure your %1$sPaytm Form Settings%2$s.', 'paytm-gravity-forms'),
                                    '<a href="' . esc_url($settings_url) . '">',
                                    '</a>'
                                ),
                                array('a' => array('href' => true))
                            );
                            ?>
                        </td>
                    </tr>
                <?php elseif (!empty($feeds) && is_array($feeds)) : ?>
                    <?php foreach ($feeds as $setting) :
                        $feed_id   = absint($setting['id']);
                        $form_id   = absint($setting['form_id']);
                        $is_active = !empty($setting['is_active']);
                        $status    = $is_active ? $label_active : $label_inactive;
                        $edit_url  = admin_url('admin.php?page=gf_paytm_form&view=edit&id=' . $feed_id);
                        $stats_url = admin_url('admin.php?page=gf_paytm_form&view=stats&id=' . $feed_id);
                        $entries_url = admin_url('admin.php?page=gf_entries&view=entries&id=' . $form_id);
                        $type_label = '';
                        switch (rgars($setting, 'meta/type')) {
                            case 'product':
                                $type_label = __('Product and Services', 'paytm-gravity-forms');
                                break;
                            case 'donation':
                                $type_label = __('Donation', 'paytm-gravity-forms');
                                break;
                        }
                        ?>
                        <tr class="author-self status-inherit" valign="top">
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="feed[]" value="<?php echo esc_attr($feed_id); ?>"/>
                            </th>
                            <td>
                                <img
                                    src="<?php echo esc_url(GF_PAYTM_FORM_BASE_URL . '/images/active' . ($is_active ? '1' : '0') . '.png'); ?>"
                                    alt="<?php echo esc_attr($status); ?>"
                                    title="<?php echo esc_attr($status); ?>"
                                    onclick="ToggleActive(this, <?php echo (int) $feed_id; ?>);"
                                />
                            </td>
                            <td class="column-title">
                                <a href="<?php echo esc_url($edit_url); ?>" title="<?php echo $label_edit; ?>"><?php echo esc_html($setting['form_title']); ?></a>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a title="<?php echo $label_edit; ?>" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'paytm-gravity-forms'); ?></a> |
                                    </span>
                                    <span class="view">
                                        <a title="<?php echo $label_entries; ?>" href="<?php echo esc_url($entries_url); ?>"><?php esc_html_e('Entries', 'paytm-gravity-forms'); ?></a> |
                                    </span>
                                    <span class="trash">
                                        <a title="<?php echo $label_delete; ?>" href="#" onclick="if (confirm('<?php echo $delete_feed_confirm; ?>')) { DeleteSetting(<?php echo (int) $feed_id; ?>); } return false;"><?php esc_html_e('Delete', 'paytm-gravity-forms'); ?></a>
                                    </span>
                                </div>
                            </td>
                            <td class="column-date"><?php echo esc_html($type_label); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else : ?>
                    <tr>
                        <td colspan="4" style="padding:20px;">
                            <?php
                            echo wp_kses(
                                sprintf(
                                    /* translators: 1: opening anchor, 2: closing anchor */
                                    __('You don\'t have any Paytm Form feeds configured. Let\'s go %1$screate one%2$s!', 'paytm-gravity-forms'),
                                    '<a href="' . esc_url($add_new_url) . '">',
                                    '</a>'
                                ),
                                array('a' => array('href' => true))
                            );
                            ?>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </form>
</div>
<script type="text/javascript">
    function DeleteSetting(id) {
        jQuery('#action_argument').val(id);
        jQuery('#action').val('delete');
        jQuery('#feed_form')[0].submit();
    }

    function ToggleActive(img, feed_id) {
        var is_active = img.src.indexOf('active1.png') >= 0;
        if (is_active) {
            img.src = img.src.replace('active1.png', 'active0.png');
            jQuery(img).attr('title', '<?php echo esc_js(__('Inactive', 'paytm-gravity-forms')); ?>').attr('alt', '<?php echo esc_js(__('Inactive', 'paytm-gravity-forms')); ?>');
        } else {
            img.src = img.src.replace('active0.png', 'active1.png');
            jQuery(img).attr('title', '<?php echo esc_js(__('Active', 'paytm-gravity-forms')); ?>').attr('alt', '<?php echo esc_js(__('Active', 'paytm-gravity-forms')); ?>');
        }

        var mysack = new sack(ajaxurl);
        mysack.execute = 1;
        mysack.method = 'POST';
        mysack.setVar('action', 'gf_paytm_form_update_feed_active');
        mysack.setVar('gf_paytm_form_update_feed_active', '<?php echo esc_js(wp_create_nonce('gf_paytm_form_update_feed_active')); ?>');
        mysack.setVar('feed_id', feed_id);
        mysack.setVar('is_active', is_active ? 0 : 1);
        mysack.onError = function() {
            alert('<?php echo esc_js(__('Ajax error while updating feed', 'paytm-gravity-forms')); ?>');
        };
        mysack.runAJAX();

        return true;
    }
</script>
        <?php
    }

    public static function load_notifications(){
        check_ajax_referer('gf_paytm_form_load_notifications', 'gf_paytm_form_load_notifications');

        if (!self::has_access('paytm-gravity-forms')) {
            wp_die(-1, '', array('response' => 403));
        }

        $form_id = isset($_POST['form_id']) ? absint(wp_unslash($_POST['form_id'])) : 0;
        $form = RGFormsModel::get_form_meta($form_id);
        $notifications = array();
        if(is_array(rgar($form, "notifications"))){
            foreach($form["notifications"] as $notification){
                $notifications[] = array("name" => $notification["name"], "id" => $notification["id"]);
            }
        }
        die(json_encode($notifications));
    }


    public static function getDefaultCallbackUrl(){
        return get_site_url().'/?gf_paytm_form_return=true&paytmcallback=true';
    }

    // get all pages
    
    public static function get_pages($title = false, $indent = true) {
        $wp_pages = get_pages('sort_column=menu_order');
        $page_list = array();
        if ($title) $page_list[] = $title;
        foreach ($wp_pages as $page) {
            $prefix = '';
            // show indented child pages?
            if ($indent) {
                $has_parent = $page->post_parent;
                while($has_parent) {
                    $prefix .=  ' - ';
                    $next_page = get_page($has_parent);
                    $has_parent = $next_page->post_parent;
                }
            }
            // add to page list array array
            $page_list[$page->ID] = $prefix . $page->post_title;
        }
        return $page_list;
    }


    public static function settings_page(){

        if(rgpost("uninstall")){
            check_admin_referer("uninstall", "gf_paytm_form_uninstall");
            self::uninstall();

            ?>
<div class="updated fade" style="padding:20px;"><?php esc_attr_e(sprintf("Gravity Forms Paytm Form Add-On have been successfully uninstalled. It can be re-activated from the %splugins page%s.", "<a href='plugins.php'>","</a>"), "paytm-gravity-forms")?></div>
            <?php
            return;
        }
        else if(isset($_POST["gf_paytm_form_submit"])){
            check_admin_referer("update", "gf_paytm_form_update");
            $settings = array(  
                "paytm_env" => rgpost("gf_paytm_form_env"),
                "paytm_mid" => rgpost("gf_paytm_form_paytm_mid"),
                "paytm_key" => rgpost("gf_paytm_form_paytm_key"),
                "paytm_website" => rgpost("gf_paytm_form_website"),
                "paytm_channel_id" => rgpost("gf_paytm_form_channel_id"),
                "paytm_industry_type_id" => rgpost("gf_paytm_form_industry_type_id"),
                "paytm_custom_callback" => rgpost("gf_paytm_form_custom_callback"),
                "paytm_callback_url" => rgpost("gf_paytm_form_callback_url"),
                "paytm_return_page" => rgpost("gf_paytm_form_return_page"),
            );


            update_option("gf_paytm_form_settings", $settings);
        }
        else{
            $settings = get_option("gf_paytm_form_settings");
        }

        ?>
<style>
    .valid_credentials{color:green;}
    .invalid_credentials{color:red;}
    .size-1{width:400px;}
</style>

<form method="post" action="">
            <?php wp_nonce_field("update", "gf_paytm_form_update") ?>

    <h3><?php esc_attr_e("Paytm Form Information", "paytm-gravity-forms") ?></h3>
    <p style="text-align: left;">
                <?php esc_attr_e(sprintf("Paytm Form allows you to accept credit card payments on their PCI compliant servers securely."), "paytm-gravity-forms") ?>
    </p>

    <table class="form-table">



        <tr>
            <th scope="row" nowrap="nowrap"><label for="gf_paytm_form_env"><?php esc_attr_e("Paytm Environment", "paytm-gravity-forms"); ?></label> </th>
            <td width="88%">
                <select name="gf_paytm_form_env">
                    <option value="0" <?php echo rgar($settings, 'paytm_env') == "0" ? "selected" : "" ?>><?php esc_attr_e("Stage", "paytm-gravity-forms"); ?></option>
                    <option value="1" <?php echo rgar($settings, 'paytm_env') == "1" ? "selected" : "" ?>><?php esc_attr_e("Live", "paytm-gravity-forms"); ?></option>
                </select>
                <br/>
                <i>Select Paytm environment you want to use.</i>
            </td>
        </tr>





        <tr>
            <th scope="row" nowrap="nowrap"><label for="gf_paytm_form_paytm_mid"><?php esc_attr_e("Merchant ID", "paytm-gravity-forms"); ?></label> </th>
            <td width="88%">
                <input class="size-1" id="gf_paytm_form_paytm_mid" name="gf_paytm_form_paytm_mid" value="<?php echo esc_attr(rgar($settings,"paytm_mid")) ?>" />
                <br/>
                <i>Please Enter Merchant Id Provided by Paytm.</i>
            </td>
        </tr>

        <tr>
            <th scope="row" nowrap="nowrap"><label for="gf_paytm_form_paytm_key"><?php esc_attr_e("Merchant Key", "paytm-gravity-forms"); ?></label> </th>
            <td width="88%">
                <input class="size-1" id="gf_paytm_form_paytm_key" name="gf_paytm_form_paytm_key" value="<?php echo esc_attr(rgar($settings,"paytm_key")) ?>" />
                <br/>
                <i>Please Enter Merchant Secret Key Provided by Paytm.</i>
            </td>
        </tr>

        <tr>
            <th scope="row" nowrap="nowrap"><label for="gf_paytm_form_website"><?php esc_attr_e("Website", "paytm-gravity-forms"); ?></label> </th>
            <td width="88%">
                <input class="size-1" id="gf_paytm_form_website" name="gf_paytm_form_website" value="<?php echo esc_attr(rgar($settings,"paytm_website")) ?>" />
                <br/>
                <i>Please Enter Website Name Provided by Paytm.</i>
            </td>
        </tr>

        <tr>
            <th scope="row" nowrap="nowrap"><label for="gf_paytm_form_industry_type_id"><?php esc_attr_e("Industry Type ID", "paytm-gravity-forms"); ?></label> </th>
            <td width="88%">
                <input class="size-1" id="gf_paytm_form_industry_type_id" name="gf_paytm_form_industry_type_id" value="<?php echo esc_attr(rgar($settings,"paytm_industry_type_id")) ?>" />
                <br/>
                <i>Please Enter Industry Type Provided by Paytm.</i>
            </td>
        </tr>

        <tr>
            <th scope="row" nowrap="nowrap"><label for="gf_paytm_form_channel_id"><?php esc_attr_e("Channel Id", "paytm-gravity-forms"); ?></label> </th>
            <td width="88%">
                <input class="size-1" id="gf_paytm_form_channel_id" name="gf_paytm_form_channel_id" value="<?php echo esc_attr(rgar($settings,"paytm_channel_id")) ?>" />
                <br/>
                <i>Please Enter Channel ID Provided by Paytm.</i>
            </td>
        </tr>




        <tr>
            <th scope="row" nowrap="nowrap"><label for="gf_paytm_form_custom_callback"><?php esc_attr_e("Custom Callback", "paytm-gravity-forms"); ?></label> </th>
            <td width="88%">
                <select name="gf_paytm_form_custom_callback">
                    <option value="0" <?php echo rgar($settings, 'paytm_custom_callback') == "0" ? "selected" : "" ?>><?php esc_attr_e("Disable", "paytm-gravity-forms"); ?></option>
                    <option value="1" <?php echo rgar($settings, 'paytm_custom_callback') == "1" ? "selected" : "" ?>><?php esc_attr_e("Enable", "paytm-gravity-forms"); ?></option>
                </select>
                <br/>
                <i>Enable this if you want to change Default Paytm Callback URL.</i>
            </td>
        </tr>

        <tr>
            <th scope="row" nowrap="nowrap"><label for="gf_paytm_form_callback_url"><?php esc_attr_e("Callback URL", "paytm-gravity-forms"); ?></label> </th>
            <td width="88%">
                <input class="size-1" id="gf_paytm_form_callback_url" name="gf_paytm_form_callback_url" value="<?php echo esc_attr(rgar($settings,"paytm_callback_url")) ?>" />
                <br/>
                <i>Please Enter Custom Callback URL.</i>
            </td>
        </tr>

        <tr>
            <th scope="row" nowrap="nowrap"><label for="gf_paytm_form_return_page"><?php esc_attr_e("Return Page", "paytm-gravity-forms"); ?></label> </th>
            <td width="88%">
                <select name="gf_paytm_form_return_page">
                        <?php
                            $pages = self::get_pages('Select Page');
                            foreach($pages as $k=>$v){
                                $selected = rgar($settings, 'paytm_return_page') == $k? 'selected' : '';
                                echo "<option value='".$k."' ".$selected.">".$v."</option>";
                            }
                        ?>
                </select>
                <br/>
                <i>Please Select Page That You Want to Display After Payment.</i>
            </td>
        </tr>

        <tr>
            <td colspan="2" ><input type="submit" name="gf_paytm_form_submit" class="button-primary" value="<?php esc_attr_e("Save Settings", "paytm-gravity-forms") ?>" /></td>
        </tr>

    </table>
            <?php
            $last_updated = "";
            $path = plugin_dir_path( __FILE__ ) . "/paytm_version.txt";
            if(file_exists($path)){
                $handle = fopen($path, "r");
                if($handle !== false){
                    $date = fread($handle, 10); // i.e. DD-MM-YYYY or 25-04-2018
                    $last_updated = '<p>Last Updated: '. date("d F Y", strtotime($date)) .'</p>';
                }
            }

            $footer_text = '<div style="text-align: center;"><hr/>'.$last_updated.'<p>Gravity Form Version: ' .GFCommon::$version.'</p></div>';

            echo $footer_text;
            ?>

            <?php
            echo '<script>
                    var default_callback_url = "'. self::getDefaultCallbackUrl() .'";
                    function toggleCallbackUrl(){
                        if(jQuery("select[name=\"gf_paytm_form_custom_callback\"]").val() == "1"){
                            jQuery("input[name=\"gf_paytm_form_callback_url\"]").prop("readonly", false).parents("tr").removeClass("hidden");
                        } else {
                            jQuery("input[name=\"gf_paytm_form_callback_url\"]").val(default_callback_url).prop("readonly", true).parents("tr").addClass("hidden");
                        }
                    }

                    jQuery(document).on("change", "select[name=\"gf_paytm_form_custom_callback\"]", function(){
                        toggleCallbackUrl();
                    });
                    toggleCallbackUrl();
                    
                </script>';
            ?>
</form>

        <?php
    }

    private static function get_product_field_options($productFields, $selectedValue){
        $options = "<option value=''>" . esc_attr_e("Select a product", "paytm-gravity-forms") . "</option>";
        foreach($productFields as $field){
            $label = GFCommon::truncate_middle($field["label"], 30);
            $selected = $selectedValue == $field["id"] ? "selected='selected'" : "";
            $options .= "<option value='{$field["id"]}' {$selected}>{$label}</option>";
        }

        return $options;
    }

   
    private function get_graph_timestamp($local_datetime){
        $local_timestamp = mysql2date("G", $local_datetime); //getting timestamp with timezone adjusted
        $local_date_timestamp = mysql2date("G", gmdate("Y-m-d 23:59:59", $local_timestamp)); //setting time portion of date to midnight (to match the way Javascript handles dates)
        $timestamp = ($local_date_timestamp - (24 * 60 * 60) + 1) * 1000; //adjusting timestamp for Javascript (subtracting a day and transforming it to milliseconds
        return $timestamp;
    }

    private static function matches_current_date($format, $js_timestamp){
        $target_date = $format == "YW" ? $js_timestamp : date($format, $js_timestamp / 1000);

        $current_date = gmdate($format, GFCommon::get_local_timestamp(time()));
        return $target_date == $current_date;
    }

    private static function daily_chart_info($config){
        global $wpdb;

        $tz_offset = self::get_mysql_tz_offset();
        $form_id = isset($config["form_id"]) ? absint($config["form_id"]) : 0;

        $results = $wpdb->get_results($wpdb->prepare("SELECT CONVERT_TZ(t.date_created, '+00:00', %s) as date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                        FROM {$wpdb->prefix}rg_lead l
                                        INNER JOIN {$wpdb->prefix}rg_paytm_form_transaction t ON l.id = t.entry_id
                                        WHERE form_id=%d AND t.transaction_type='payment'
                                        GROUP BY date(date)
                                        ORDER BY payment_date desc
                                        LIMIT 30", $tz_offset, $form_id));

        $sales_today = 0;
        $revenue_today = 0;
        $tooltips = "";

        if(!empty($results)){

            $data = "[";

            foreach($results as $result){
                $timestamp = self::get_graph_timestamp($result->date);
                if(self::matches_current_date("Y-m-d", $timestamp)){
                    $sales_today += $result->new_sales;
                    $revenue_today += $result->amount_sold;
                }
                $data .="[{$timestamp},{$result->amount_sold}],";

                $sales_line = "<div class='paytm_form_tooltip_sales'><span class='paytm_form_tooltip_heading'>" . esc_attr_e("Orders", "paytm-gravity-forms") . ": </span><span class='paytm_form_tooltip_value'>" . $result->new_sales . "</span></div>";
                
                $tooltips .= "\"<div class='paytm_form_tooltip_date'>" . GFCommon::format_date($result->date, false, "", false) . "</div>{$sales_line}<div class='paytm_form_tooltip_revenue'><span class='paytm_form_tooltip_heading'>" . esc_attr_e("Revenue", "paytm-gravity-forms") . ": </span><span class='paytm_form_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
            }
            $data = substr($data, 0, strlen($data)-1);
            $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
            $data .="]";

            $series = "[{data:" . $data . "}]";
            $month_names = self::get_chart_month_names();
            $options ="
            {
                xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %d', minTickSize:[1, 'day']},
                yaxis: {tickFormatter: convertToMoney},
                bars: {show:true, align:'right', barWidth: (24 * 60 * 60 * 1000) - 10000000},
                colors: ['#a3bcd3', '#14568a'],
                grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
            }";
        }
        switch($config["meta"]["type"]){
            case "product" :
                $sales_label = esc_attr_e("Orders Today", "paytm-gravity-forms");
            break;

            case "donation" :
                $sales_label = esc_attr_e("Donations Today", "paytm-gravity-forms");
            break;

            case "subscription" :
                $sales_label = esc_attr_e("Subscriptions Today", "paytm-gravity-forms");
            break;
        }
        $revenue_today = GFCommon::to_money($revenue_today);
        return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => esc_attr_e("Revenue Today", "paytm-gravity-forms"), "revenue" => $revenue_today, "sales_label" => $sales_label, "sales" => $sales_today);
    }

    private static function weekly_chart_info($config){
            global $wpdb;

            $tz_offset = self::get_mysql_tz_offset();
            $form_id = isset($config["form_id"]) ? absint($config["form_id"]) : 0;

            $results = $wpdb->get_results($wpdb->prepare("SELECT yearweek(CONVERT_TZ(t.date_created, '+00:00', %s)) week_number, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_paytm_form_transaction t ON l.id = t.entry_id
                                            WHERE form_id=%d AND t.transaction_type='payment'
                                            GROUP BY week_number
                                            ORDER BY week_number desc
                                            LIMIT 30", $tz_offset, $form_id));
            $sales_week = 0;
            $revenue_week = 0;
            $tooltips = "";
            if(!empty($results))
            {
                $data = "[";

                foreach($results as $result){
                    if(self::matches_current_date("YW", $result->week_number)){
                        $sales_week += $result->new_sales;
                        $revenue_week += $result->amount_sold;
                    }
                    $data .="[{$result->week_number},{$result->amount_sold}],";

                    $sales_line = "<div class='paytm_form_tooltip_sales'><span class='paytm_form_tooltip_heading'>" . esc_attr_e("Orders", "paytm-gravity-forms") . ": </span><span class='paytm_form_tooltip_value'>" . $result->new_sales . "</span></div>";
                    
                    $tooltips .= "\"<div class='paytm_form_tooltip_date'>" . substr($result->week_number, 0, 4) . ", " . esc_attr_e("Week",  "paytm-gravity-forms") . " " . substr($result->week_number, strlen($result->week_number)-2, 2) . "</div>{$sales_line}<div class='paytm_form_tooltip_revenue'><span class='paytm_form_tooltip_heading'>" . esc_attr_e("Revenue", "paytm-gravity-forms") . ": </span><span class='paytm_form_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {tickFormatter: formatWeeks, tickDecimals: 0},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth:0.95},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }

            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = esc_attr_e("Orders this Week", "paytm-gravity-forms");
                break;

                case "donation" :
                    $sales_label = esc_attr_e("Donations this Week", "paytm-gravity-forms");
                break;
                
            }
            $revenue_week = GFCommon::to_money($revenue_week);

            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => esc_attr_e("Revenue this Week", "paytm-gravity-forms"), "revenue" => $revenue_week, "sales_label" => $sales_label , "sales" => $sales_week);
    }

    private static function monthly_chart_info($config){
            global $wpdb;
            $tz_offset = self::get_mysql_tz_offset();
            $form_id = isset($config["form_id"]) ? absint($config["form_id"]) : 0;

            $results = $wpdb->get_results($wpdb->prepare("SELECT date_format(CONVERT_TZ(t.date_created, '+00:00', %s), '%%Y-%%m-02') date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_paytm_form_transaction t ON l.id = t.entry_id
                                            WHERE form_id=%d AND t.transaction_type='payment'
                                            group by date
                                            order by date desc
                                            LIMIT 30", $tz_offset, $form_id));

            $sales_month = 0;
            $revenue_month = 0;
            $tooltips = "";
            if(!empty($results)){

                $data = "[";

                foreach($results as $result){
                    $timestamp = self::get_graph_timestamp($result->date);
                    if(self::matches_current_date("Y-m", $timestamp)){
                        $sales_month += $result->new_sales;
                        $revenue_month += $result->amount_sold;
                    }
                    $data .="[{$timestamp},{$result->amount_sold}],";

                    $sales_line = "<div class='paytm_form_tooltip_sales'><span class='paytm_form_tooltip_heading'>" . esc_attr_e("Orders", "paytm-gravity-forms") . ": </span><span class='paytm_form_tooltip_value'>" . $result->new_sales . "</span></div>";
                    
                    $tooltips .= "\"<div class='paytm_form_tooltip_date'>" . GFCommon::format_date($result->date, false, "F, Y", false) . "</div>{$sales_line}<div class='paytm_form_tooltip_revenue'><span class='paytm_form_tooltip_heading'>" . esc_attr_e("Revenue", "paytm-gravity-forms") . ": </span><span class='paytm_form_tooltip_value'>" . GFCommon::to_money($result->amount_sold) . "</span></div>\",";
                }
                $data = substr($data, 0, strlen($data)-1);
                $tooltips = substr($tooltips, 0, strlen($tooltips)-1);
                $data .="]";

                $series = "[{data:" . $data . "}]";
                $month_names = self::get_chart_month_names();
                $options ="
                {
                    xaxis: {mode: 'time', monthnames: $month_names, timeformat: '%b %y', minTickSize: [1, 'month']},
                    yaxis: {tickFormatter: convertToMoney},
                    bars: {show:true, align:'center', barWidth: (24 * 60 * 60 * 30 * 1000) - 130000000},
                    colors: ['#a3bcd3', '#14568a'],
                    grid: {hoverable: true, clickable: true, tickColor: '#F1F1F1', backgroundColor:'#FFF', borderWidth: 1, borderColor: '#CCC'}
                }";
            }
            switch($config["meta"]["type"]){
                case "product" :
                    $sales_label = esc_attr_e("Orders this Month", "paytm-gravity-forms");
                break;

                case "donation" :
                    $sales_label = esc_attr_e("Donations this Month", "paytm-gravity-forms");
                break;
            }
            $revenue_month = GFCommon::to_money($revenue_month);
            return array("series" => $series, "options" => $options, "tooltips" => "[$tooltips]", "revenue_label" => esc_attr_e("Revenue this Month", "paytm-gravity-forms"), "revenue" => $revenue_month, "sales_label" => $sales_label, "sales" => $sales_month);
    }

    private static function get_mysql_tz_offset(){
        $tz_offset = get_option("gmt_offset");

        //add + if offset starts with a number
        if(is_numeric(substr($tz_offset, 0, 1)))
            $tz_offset = "+" . $tz_offset;

        return $tz_offset . ":00";
    }

    private static function get_chart_month_names(){
        return "['" . esc_attr_e("Jan", "paytm-gravity-forms") ."','" . esc_attr_e("Feb", "paytm-gravity-forms") ."','" . esc_attr_e("Mar", "paytm-gravity-forms") ."','" . esc_attr_e("Apr", "paytm-gravity-forms") ."','" . esc_attr_e("May", "paytm-gravity-forms") ."','" . esc_attr_e("Jun", "paytm-gravity-forms") ."','" . esc_attr_e("Jul", "paytm-gravity-forms") ."','" . esc_attr_e("Aug", "paytm-gravity-forms") ."','" . esc_attr_e("Sep", "paytm-gravity-forms") ."','" . esc_attr_e("Oct", "paytm-gravity-forms") ."','" . esc_attr_e("Nov", "paytm-gravity-forms") ."','" . esc_attr_e("Dec", "paytm-gravity-forms") ."']";
    }

    // Edit Page
    private static function edit_page() {
        $id = !empty($_POST['paytm_form_setting_id']) ? absint($_POST['paytm_form_setting_id']) : absint(rgget('id'));
        $config = empty($id) ? array('meta' => array(), 'is_active' => true, 'form_id' => 0) : GFPaytmFormData::get_feed($id);
        if (!is_array($config)) {
            $config = array('meta' => array(), 'is_active' => true, 'form_id' => 0);
        }
        if (!isset($config['meta']) || !is_array($config['meta'])) {
            $config['meta'] = array();
        }

        $is_validation_error = false;
        $saved_notice = '';

        if (rgpost('gf_paytm_form_submit')) {
            check_admin_referer('gf_paytm_form_edit_feed', 'gf_paytm_form_edit_feed');

            $config['form_id'] = absint(rgpost('gf_paytm_form_form'));
            $form = $config['form_id'] ? RGFormsModel::get_form_meta($config['form_id']) : array();
            if (!is_array($form)) {
                $form = array();
            }

            $config['meta']['type'] = sanitize_key(rgpost('gf_paytm_form_type'));
            $config['meta']['cancel_url'] = esc_url_raw(rgpost('gf_paytm_form_cancel_url'));
            $config['meta']['delay_post'] = rgpost('gf_paytm_form_delay_post') ? '1' : '';
            $config['meta']['update_post_action'] = sanitize_key(rgpost('gf_paytm_form_update_action'));

            if (isset($form['notifications'])) {
                $config['meta']['delay_notifications'] = rgpost('gf_paytm_form_delay_notifications') ? '1' : '';
                $selected_notifications = rgpost('gf_paytm_form_selected_notifications');
                $config['meta']['selected_notifications'] = ($config['meta']['delay_notifications'] && is_array($selected_notifications))
                    ? array_map('sanitize_text_field', $selected_notifications)
                    : array();

                unset($config['meta']['delay_autoresponder'], $config['meta']['delay_notification']);
            } else {
                $config['meta']['delay_notification'] = rgpost('gf_paytm_form_delay_notification') ? '1' : '';
                $config['meta']['delay_autoresponder'] = rgpost('gf_paytm_form_delay_autoresponder') ? '1' : '';
            }

            $config['meta']['paytm_form_conditional_enabled'] = rgpost('gf_paytm_form_conditional_enabled') ? '1' : '';
            $config['meta']['paytm_form_conditional_field_id'] = sanitize_text_field(rgpost('gf_paytm_form_conditional_field_id'));
            $config['meta']['paytm_form_conditional_operator'] = sanitize_text_field(rgpost('gf_paytm_form_conditional_operator'));
            $config['meta']['paytm_form_conditional_value'] = sanitize_text_field(rgpost('gf_paytm_form_conditional_value'));

            $config['meta']['customer_fields'] = array();
            foreach (self::get_customer_fields() as $field) {
                $field_key = 'paytm_form_customer_field_' . $field['name'];
                $config['meta']['customer_fields'][$field['name']] = isset($_POST[$field_key])
                    ? sanitize_text_field(wp_unslash($_POST[$field_key]))
                    : '';
            }

            $config = apply_filters('gform_paytm_form_save_config', $config);
            $is_validation_error = (bool) apply_filters('gform_paytm_form_config_validation', false, $config);

            if (!$is_validation_error) {
                $id = GFPaytmFormData::update_feed($id, $config['form_id'], !empty($config['is_active']), $config['meta']);
                $saved_notice = sprintf(
                    /* translators: 1: opening anchor, 2: closing anchor */
                    __('Feed updated. %1$sBack to list%2$s', 'paytm-gravity-forms'),
                    '<a href="' . esc_url(admin_url('admin.php?page=gf_paytm_form')) . '">',
                    '</a>'
                );
            }
        } else {
            $config['form_id'] = absint(rgar($config, 'form_id'));
            $form = $config['form_id'] ? RGFormsModel::get_form_meta($config['form_id']) : array();
            if (!is_array($form)) {
                $form = array();
            }
        }

        $feed_type = rgars($config, 'meta/type');
        $has_type = !empty($feed_type);
        $has_form = !empty($config['form_id']);
        $display_post_fields = !empty($form) && !empty($form['fields']) && GFCommon::has_post_field($form['fields']);
        $has_delayed_notifications = rgar($config['meta'], 'delay_notifications') || rgar($config['meta'], 'delay_notification') || rgar($config['meta'], 'delay_autoresponder');
        $list_url = admin_url('admin.php?page=gf_paytm_form');
        $plugin_img = GF_PAYTM_FORM_BASE_URL . '/images/paytm_form_wordpress_icon_32.jpg';
        $loading_img = GF_PAYTM_FORM_BASE_URL . '/images/loading.gif';
        $selected_notifications = (!empty($form) && isset($form['notifications'])) ? self::get_selected_notifications($config, $form) : array();
        $submit_label = empty($id) ? __('Save Feed', 'paytm-gravity-forms') : __('Update Feed', 'paytm-gravity-forms');
        ?>
<style>
    .paytm-feed-edit {
        --paytm-accent: #00b9f5;
        --paytm-ink: #0f2b46;
        --paytm-muted: #5b6b7c;
        --paytm-border: #d9e2ec;
        --paytm-soft: #f4f8fb;
        --paytm-card: #ffffff;
        max-width: 920px;
        margin-top: 12px;
    }
    .paytm-feed-edit__header {
        display: flex;
        align-items: center;
        gap: 14px;
        margin: 8px 0 22px;
        padding: 18px 20px;
        border-radius: 14px;
        background:
            radial-gradient(circle at top right, rgba(0, 185, 245, 0.18), transparent 42%),
            linear-gradient(135deg, #0f2b46 0%, #163a5f 55%, #0f2b46 100%);
        color: #fff;
        box-shadow: 0 10px 28px rgba(15, 43, 70, 0.18);
    }
    .paytm-feed-edit__header img {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: #fff;
        padding: 4px;
        box-sizing: border-box;
    }
    .paytm-feed-edit__header h2 {
        margin: 0;
        padding: 0;
        border: 0;
        color: #fff;
        font-size: 22px;
        font-weight: 600;
        letter-spacing: 0.2px;
    }
    .paytm-feed-edit__header p {
        margin: 4px 0 0;
        color: rgba(255,255,255,0.78);
        font-size: 13px;
    }
    .paytm-feed-card {
        background: var(--paytm-card);
        border: 1px solid var(--paytm-border);
        border-radius: 14px;
        padding: 20px 22px;
        margin-bottom: 16px;
        box-shadow: 0 1px 2px rgba(15, 43, 70, 0.04);
    }
    .paytm-feed-card__title {
        margin: 0;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: var(--paytm-accent);
    }
    .paytm-feed-card__subtitle {
        margin: 6px 0 16px;
        color: var(--paytm-muted);
        font-size: 13px;
        line-height: 1.5;
        font-weight: 400;
        text-transform: none;
        letter-spacing: 0;
    }
    .paytm-feed-label {
        float: none;
        width: 200px;
        flex: 0 0 200px;
        margin: 0;
    }
    .paytm-feed-label label,
    .paytm-feed-label .left_header {
        display: block;
        float: none;
        width: auto;
        margin: 0 0 4px;
        font-weight: 600;
        color: var(--paytm-ink);
    }
    .paytm-feed-subtitle {
        margin: 0;
        color: var(--paytm-muted);
        font-size: 12.5px;
        line-height: 1.45;
        font-weight: 400;
    }
    .paytm-feed-row {
        display: flex;
        align-items: flex-start;
        gap: 18px;
        margin: 0 0 18px;
    }
    .paytm-feed-row:last-child { margin-bottom: 0; }
    .paytm-feed-row .left_header {
        float: none;
        width: 200px;
        flex: 0 0 200px;
        margin: 8px 0 0;
        font-weight: 600;
        color: var(--paytm-ink);
    }
    .paytm-feed-row__control { flex: 1; min-width: 0; }
    .paytm-feed-edit select,
    .paytm-feed-edit input[type="text"] {
        min-width: 280px;
        max-width: 100%;
        border-radius: 8px;
        border-color: var(--paytm-border);
        padding: 6px 10px;
        box-shadow: none;
    }
    .paytm-feed-edit select:focus,
    .paytm-feed-edit input[type="text"]:focus {
        border-color: var(--paytm-accent);
        box-shadow: 0 0 0 1px var(--paytm-accent);
    }
    .paytm-feed-options {
        margin: 0;
        padding: 12px 14px;
        list-style: none;
        background: var(--paytm-soft);
        border: 1px solid var(--paytm-border);
        border-radius: 10px;
    }
    .paytm-feed-options li {
        margin: 0 0 10px;
        padding: 0;
    }
    .paytm-feed-options li:last-child { margin-bottom: 0; }
    .paytm-feed-options label.inline {
        font-weight: 500;
        color: var(--paytm-ink);
    }
    #gf_paytm_form_notification_container {
        margin: 10px 0 0;
        padding: 10px 12px !important;
        background: #fff;
        border: 1px dashed var(--paytm-border);
        border-radius: 8px;
    }
    #paytm_form_customer_fields table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        overflow: hidden;
        border: 1px solid var(--paytm-border);
        border-radius: 10px;
        background: #fff;
    }
    .paytm_form_col_heading {
        padding: 10px 14px;
        border-bottom: 1px solid var(--paytm-border);
        background: var(--paytm-soft);
        font-weight: 700;
        width: auto;
        color: var(--paytm-ink);
    }
    .paytm_form_field_cell {
        padding: 10px 14px;
        margin: 0;
        border-bottom: 1px solid var(--paytm-border);
        color: var(--paytm-muted);
    }
    #paytm_form_customer_fields tr:last-child .paytm_form_field_cell {
        border-bottom: 0;
    }
    .paytm_form_validation_error {
        background: #fff5f5;
        margin: 0 0 16px;
        padding: 12px 14px;
        border: 1px solid #f1b7b7;
        border-radius: 10px;
        color: #9b1c1c;
    }
    .gf_paytm_form_invalid_form {
        margin-top: 14px;
        background: #fff5f5;
        border: 1px solid #f1b7b7;
        border-radius: 10px;
        padding: 12px 14px;
        width: auto;
        max-width: 640px;
        color: #9b1c1c;
    }
    #paytm_form_submit_container {
        clear: both;
        display: flex;
        gap: 10px;
        align-items: center;
        margin-top: 8px;
    }
    #paytm_form_submit_container .button-primary {
        background: var(--paytm-accent);
        border-color: #00a7dd;
        text-shadow: none;
        box-shadow: none;
        border-radius: 8px;
        padding: 0 18px;
        height: 36px;
        line-height: 34px;
    }
    #paytm_form_submit_container .button-primary:hover,
    #paytm_form_submit_container .button-primary:focus {
        background: #00a7dd;
        border-color: #0095c5;
    }
    #paytm_form_submit_container .button {
        border-radius: 8px;
        height: 36px;
        line-height: 34px;
    }
    #gf_paytm_form_conditional_container {
        margin-top: 10px;
        padding: 12px 14px;
        background: var(--paytm-soft);
        border: 1px solid var(--paytm-border);
        border-radius: 10px;
    }
    #gf_paytm_form_conditional_fields {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        align-items: center;
    }
    .width-1 { width: min(100%, 420px); }
    .margin_vertical_10, .margin_vertical_30 { margin: 0; padding: 0; }
    .left_header { float: left; width: 200px; }

    @media (max-width: 782px) {
        .paytm-feed-row { flex-direction: column; gap: 8px; }
        .paytm-feed-row .left_header,
        .paytm-feed-label { width: auto; flex: none; margin: 0; }
        .paytm-feed-edit select,
        .paytm-feed-edit input[type="text"] { min-width: 0; width: 100%; }
    }
</style>
<script type="text/javascript">
    var form = [];
    function ToggleNotifications() {
        var container = jQuery('#gf_paytm_form_notification_container');
        var isChecked = jQuery('#gf_paytm_form_delay_notifications').is(':checked');

        if (isChecked) {
            container.slideDown();
            if (jQuery('.gf_paytm_form_notification').length === 0) {
                container.html("<li><img src='<?php echo esc_url($loading_img); ?>' title='<?php echo esc_js(__('Please wait...', 'paytm-gravity-forms')); ?>' alt=''/></li>");
                jQuery.post(ajaxurl, {
                    action: 'gf_paytm_form_load_notifications',
                    gf_paytm_form_load_notifications: '<?php echo esc_js(wp_create_nonce('gf_paytm_form_load_notifications')); ?>',
                    form_id: form['id']
                }, function(response) {
                    var notifications = jQuery.parseJSON(response);
                    if (!notifications) {
                        container.html("<li><div class='error'><?php echo esc_js(__('Notifications could not be loaded. Please try again later or contact support', 'paytm-gravity-forms')); ?></div></li>");
                    } else if (notifications.length === 0) {
                        container.html("<li><div class='error'><?php echo esc_js(__('The form selected does not have any notifications.', 'paytm-gravity-forms')); ?></div></li>");
                    } else {
                        var str = '';
                        for (var i = 0; i < notifications.length; i++) {
                            str += "<li class='gf_paytm_form_notification'>"
                                + "<input type='checkbox' value='" + notifications[i]['id'] + "' name='gf_paytm_form_selected_notifications[]' id='gf_paytm_form_selected_notifications_" + i + "' checked='checked' /> "
                                + "<label class='inline' for='gf_paytm_form_selected_notifications_" + i + "'>" + notifications[i]['name'] + "</label>"
                                + "</li>";
                        }
                        container.html(str);
                    }
                });
            }
            jQuery('.gf_paytm_form_notification input').prop('checked', true);
        } else {
            container.slideUp();
            jQuery('.gf_paytm_form_notification input').prop('checked', false);
        }
    }
</script>
<div class="wrap paytm-feed-edit">
    <div class="paytm-feed-edit__header">
        <img alt="<?php echo esc_attr__('Paytm Form', 'paytm-gravity-forms'); ?>" src="<?php echo esc_url($plugin_img); ?>"/>
        <div>
            <h2><?php esc_html_e('Paytm Form Transaction Settings', 'paytm-gravity-forms'); ?></h2>
            <p><?php esc_html_e('Map a Gravity Form to Paytm donations and payment notifications.', 'paytm-gravity-forms'); ?></p>
        </div>
    </div>

    <?php if ($saved_notice) : ?>
        <div class="updated fade" style="padding:10px 14px; border-radius:10px;"><?php echo wp_kses($saved_notice, array('a' => array('href' => true))); ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field('gf_paytm_form_edit_feed', 'gf_paytm_form_edit_feed'); ?>
        <input type="hidden" name="paytm_form_setting_id" value="<?php echo esc_attr($id); ?>" />

        <?php if ($is_validation_error) : ?>
            <div class="paytm_form_validation_error">
                <span><?php esc_html_e('There was an issue saving your feed. Please address the errors below and try again.', 'paytm-gravity-forms'); ?></span>
            </div>
        <?php endif; ?>

        <div class="paytm-feed-card">
            <h3 class="paytm-feed-card__title"><?php esc_html_e('Feed Setup', 'paytm-gravity-forms'); ?></h3>
            <p class="paytm-feed-card__subtitle"><?php esc_html_e('Choose the payment type and connect it to a Gravity Form.', 'paytm-gravity-forms'); ?></p>

            <div class="paytm-feed-row margin_vertical_10">
                <div class="paytm-feed-label">
                    <label class="left_header" for="gf_paytm_form_type"><?php esc_html_e('Transaction Type', 'paytm-gravity-forms'); ?></label>
                    <p class="paytm-feed-subtitle"><?php esc_html_e('Select how this feed should process payments, such as donations.', 'paytm-gravity-forms'); ?></p>
                </div>
                <div class="paytm-feed-row__control">
                    <select id="gf_paytm_form_type" name="gf_paytm_form_type" onchange="SelectType(jQuery(this).val());">
                        <option value=""><?php esc_html_e('Select a transaction type', 'paytm-gravity-forms'); ?></option>
                        <option value="donation" <?php selected($feed_type, 'donation'); ?>><?php esc_html_e('Donations', 'paytm-gravity-forms'); ?></option>
                    </select>
                </div>
            </div>

            <div id="paytm_form_form_container" class="paytm-feed-row margin_vertical_10" <?php echo $has_type ? '' : "style='display:none;'"; ?>>
                <div class="paytm-feed-label">
                    <label for="gf_paytm_form_form" class="left_header"><?php esc_html_e('Gravity Form', 'paytm-gravity-forms'); ?></label>
                    <p class="paytm-feed-subtitle"><?php esc_html_e('Pick the form that should send submissions to Paytm for payment.', 'paytm-gravity-forms'); ?></p>
                </div>
                <div class="paytm-feed-row__control">
                    <select id="gf_paytm_form_form" name="gf_paytm_form_form" onchange="SelectForm(jQuery('#gf_paytm_form_type').val(), jQuery(this).val(), '<?php echo esc_js(rgar($config, 'id')); ?>');">
                        <option value=""><?php esc_html_e('Select a form', 'paytm-gravity-forms'); ?></option>
                        <?php
                        $active_form = rgar($config, 'form_id');
                        $available_forms = GFPaytmFormData::get_available_forms($active_form);
                        foreach ($available_forms as $current_form) :
                            ?>
                            <option value="<?php echo esc_attr(absint($current_form->id)); ?>" <?php selected(absint($current_form->id), absint($active_form)); ?>><?php echo esc_html($current_form->title); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <img src="<?php echo esc_url($loading_img); ?>" id="paytm_form_wait" alt="" style="display:none; vertical-align:middle; margin-left:8px;"/>
                    <div id="gf_paytm_form_invalid_product_form" class="gf_paytm_form_invalid_form" style="display:none;">
                        <?php esc_html_e('The form selected does not have any Product fields. Please add a Product field to the form and try again.', 'paytm-gravity-forms'); ?>
                    </div>
                    <div id="gf_paytm_form_invalid_donation_form" class="gf_paytm_form_invalid_form" style="display:none;">
                        <?php esc_html_e('The form selected does not have any Product fields. Please add a Product field to the form and try again.', 'paytm-gravity-forms'); ?>
                    </div>
                </div>
            </div>
        </div>

        <div id="paytm_form_field_group" <?php echo ($has_type && $has_form) ? '' : "style='display:none;'"; ?>>
            <div class="paytm-feed-card">
                <h3 class="paytm-feed-card__title"><?php esc_html_e('Customer Mapping', 'paytm-gravity-forms'); ?></h3>
                <p class="paytm-feed-card__subtitle"><?php esc_html_e('Map customer details from your form fields and set a cancel redirect.', 'paytm-gravity-forms'); ?></p>
                <div class="paytm-feed-row margin_vertical_10">
                    <div class="paytm-feed-label">
                        <label class="left_header"><?php esc_html_e('Customer', 'paytm-gravity-forms'); ?></label>
                        <p class="paytm-feed-subtitle"><?php esc_html_e('Match Gravity Form fields to Paytm fields like name, email, phone, and amount.', 'paytm-gravity-forms'); ?></p>
                    </div>
                    <div class="paytm-feed-row__control" id="paytm_form_customer_fields">
                        <?php
                        if (!empty($form)) {
                            echo self::get_customer_information($form, $config);
                        }
                        ?>
                    </div>
                </div>

                <div class="paytm-feed-row margin_vertical_10">
                    <div class="paytm-feed-label">
                        <label class="left_header" for="gf_paytm_form_cancel_url"><?php esc_html_e('Cancel URL', 'paytm-gravity-forms'); ?></label>
                        <p class="paytm-feed-subtitle"><?php esc_html_e('Optional page URL if the user cancels before completing payment.', 'paytm-gravity-forms'); ?></p>
                    </div>
                    <div class="paytm-feed-row__control">
                        <input type="text" name="gf_paytm_form_cancel_url" id="gf_paytm_form_cancel_url" class="width-1" value="<?php echo esc_attr(rgars($config, 'meta/cancel_url')); ?>"/>
                    </div>
                </div>
            </div>

            <div class="paytm-feed-card">
                <h3 class="paytm-feed-card__title"><?php esc_html_e('Payment Actions', 'paytm-gravity-forms'); ?></h3>
                <p class="paytm-feed-card__subtitle"><?php esc_html_e('Control when notifications and related actions run after Paytm payment.', 'paytm-gravity-forms'); ?></p>
                <div class="margin_vertical_10" style="display:none;">
                    <ul class="paytm-feed-options">
                        <li id="paytm_form_delay_notification" <?php echo isset($form['notifications']) ? "style='display:none;'" : ''; ?>>
                            <input type="checkbox" name="gf_paytm_form_delay_notification" id="gf_paytm_form_delay_notification" value="1" <?php checked(!empty(rgar($config['meta'], 'delay_notification'))); ?> />
                            <label class="inline" for="gf_paytm_form_delay_notification"><?php esc_html_e('Send admin notification only when payment is received.', 'paytm-gravity-forms'); ?></label>
                        </li>
                        <li id="paytm_form_delay_autoresponder" <?php echo isset($form['notifications']) ? "style='display:none;'" : ''; ?>>
                            <input type="checkbox" name="gf_paytm_form_delay_autoresponder" id="gf_paytm_form_delay_autoresponder" value="1" <?php checked(!empty(rgar($config['meta'], 'delay_autoresponder'))); ?> />
                            <label class="inline" for="gf_paytm_form_delay_autoresponder"><?php esc_html_e('Send user notification only when payment is received.', 'paytm-gravity-forms'); ?></label>
                        </li>
                        <li id="paytm_form_post_action" <?php echo $display_post_fields ? '' : "style='display:none;'"; ?>>
                            <input type="checkbox" name="gf_paytm_form_delay_post" id="gf_paytm_form_delay_post" value="1" <?php checked(!empty(rgar($config['meta'], 'delay_post'))); ?> />
                            <label class="inline" for="gf_paytm_form_delay_post"><?php esc_html_e('Create post only when payment is received.', 'paytm-gravity-forms'); ?></label>
                        </li>
                        <li id="paytm_form_post_update_action" <?php echo ($display_post_fields && $feed_type === 'subscription') ? '' : "style='display:none;'"; ?>>
                            <input type="checkbox" name="gf_paytm_form_update_post" id="gf_paytm_form_update_post" value="1" <?php checked(!empty(rgar($config['meta'], 'update_post_action'))); ?> onclick="var action = this.checked ? 'draft' : ''; jQuery('#gf_paytm_form_update_action').val(action);" />
                            <label class="inline" for="gf_paytm_form_update_post"><?php esc_html_e('Update Post when subscription is cancelled.', 'paytm-gravity-forms'); ?></label>
                            <select id="gf_paytm_form_update_action" name="gf_paytm_form_update_action" onchange="var checked = jQuery(this).val() ? 'checked' : false; jQuery('#gf_paytm_form_update_post').attr('checked', checked);">
                                <option value=""></option>
                                <option value="draft" <?php selected(rgar($config['meta'], 'update_post_action'), 'draft'); ?>><?php esc_html_e('Mark Post as Draft', 'paytm-gravity-forms'); ?></option>
                                <option value="delete" <?php selected(rgar($config['meta'], 'update_post_action'), 'delete'); ?>><?php esc_html_e('Delete Post', 'paytm-gravity-forms'); ?></option>
                            </select>
                        </li>
                        <?php do_action('gform_paytm_form_action_fields', $config, $form); ?>
                    </ul>
                </div>

                <div class="paytm-feed-row margin_vertical_10" id="gf_paytm_form_notifications" <?php echo !isset($form['notifications']) ? "style='display:none;'" : ''; ?>>
                    <div class="paytm-feed-label">
                        <label class="left_header"><?php esc_html_e('Notifications', 'paytm-gravity-forms'); ?></label>
                        <p class="paytm-feed-subtitle"><?php esc_html_e('Delay selected notifications until Paytm confirms a successful payment.', 'paytm-gravity-forms'); ?></p>
                    </div>
                    <div class="paytm-feed-row__control">
                        <input type="checkbox" name="gf_paytm_form_delay_notifications" id="gf_paytm_form_delay_notifications" value="1" onclick="ToggleNotifications();" <?php checked(true, (bool) $has_delayed_notifications); ?> />
                        <label class="inline" for="gf_paytm_form_delay_notifications"><?php esc_html_e('Send notifications only when payment is received.', 'paytm-gravity-forms'); ?></label>
                        <ul id="gf_paytm_form_notification_container" style="padding-left:20px; <?php echo $has_delayed_notifications ? '' : 'display:none;'; ?>">
                            <?php
                            if (!empty($form) && is_array(rgar($form, 'notifications'))) {
                                foreach ($form['notifications'] as $index => $notification) {
                                    $notification_id = rgar($notification, 'id');
                                    $checkbox_id = 'gf_paytm_form_selected_notifications_' . esc_attr($index);
                                    ?>
                                    <li class="gf_paytm_form_notification">
                                        <input type="checkbox" name="gf_paytm_form_selected_notifications[]" id="<?php echo $checkbox_id; ?>" value="<?php echo esc_attr($notification_id); ?>" <?php checked(true, in_array($notification_id, $selected_notifications, true)); ?> />
                                        <label class="inline" for="<?php echo $checkbox_id; ?>"><?php echo esc_html(rgar($notification, 'name')); ?></label>
                                    </li>
                                    <?php
                                }
                            }
                            ?>
                        </ul>
                    </div>
                </div>

                <?php do_action('gform_paytm_form_add_option_group', $config, $form); ?>
            </div>

            <div class="paytm-feed-card">
                <h3 class="paytm-feed-card__title"><?php esc_html_e('Conditional Logic', 'paytm-gravity-forms'); ?></h3>
                <p class="paytm-feed-card__subtitle"><?php esc_html_e('Optionally send submissions to Paytm only when a condition is met.', 'paytm-gravity-forms'); ?></p>
                <div id="gf_paytm_form_conditional_section" class="paytm-feed-row margin_vertical_10">
                    <div class="paytm-feed-label">
                        <label for="gf_paytm_form_conditional_enabled" class="left_header"><?php esc_html_e('Paytm Form Condition', 'paytm-gravity-forms'); ?></label>
                        <p class="paytm-feed-subtitle"><?php esc_html_e('When enabled, only matching submissions are sent to Paytm. When disabled, all submissions use Paytm.', 'paytm-gravity-forms'); ?></p>
                    </div>
                    <div class="paytm-feed-row__control" id="gf_paytm_form_conditional_option">
                        <input type="checkbox" id="gf_paytm_form_conditional_enabled" name="gf_paytm_form_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_paytm_form_conditional_container').fadeIn('fast');} else{ jQuery('#gf_paytm_form_conditional_container').fadeOut('fast'); }" <?php checked(!empty(rgar($config['meta'], 'paytm_form_conditional_enabled'))); ?>/>
                        <label for="gf_paytm_form_conditional_enabled"><?php esc_html_e('Enable', 'paytm-gravity-forms'); ?></label>

                        <div id="gf_paytm_form_conditional_container" <?php echo empty(rgar($config['meta'], 'paytm_form_conditional_enabled')) ? "style='display:none'" : ''; ?>>
                            <div id="gf_paytm_form_conditional_fields" style="display:none">
                                <?php esc_html_e('Send to Paytm Form if', 'paytm-gravity-forms'); ?>
                                <select id="gf_paytm_form_conditional_field_id" name="gf_paytm_form_conditional_field_id" class="optin_select" onchange='jQuery("#gf_paytm_form_conditional_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'></select>
                                <select id="gf_paytm_form_conditional_operator" name="gf_paytm_form_conditional_operator">
                                    <option value="is" <?php selected(rgar($config['meta'], 'paytm_form_conditional_operator'), 'is'); ?>><?php esc_html_e('is', 'paytm-gravity-forms'); ?></option>
                                    <option value="isnot" <?php selected(rgar($config['meta'], 'paytm_form_conditional_operator'), 'isnot'); ?>><?php esc_html_e('is not', 'paytm-gravity-forms'); ?></option>
                                    <option value=">" <?php selected(rgar($config['meta'], 'paytm_form_conditional_operator'), '>'); ?>><?php esc_html_e('greater than', 'paytm-gravity-forms'); ?></option>
                                    <option value="<" <?php selected(rgar($config['meta'], 'paytm_form_conditional_operator'), '<'); ?>><?php esc_html_e('less than', 'paytm-gravity-forms'); ?></option>
                                    <option value="contains" <?php selected(rgar($config['meta'], 'paytm_form_conditional_operator'), 'contains'); ?>><?php esc_html_e('contains', 'paytm-gravity-forms'); ?></option>
                                    <option value="starts_with" <?php selected(rgar($config['meta'], 'paytm_form_conditional_operator'), 'starts_with'); ?>><?php esc_html_e('starts with', 'paytm-gravity-forms'); ?></option>
                                    <option value="ends_with" <?php selected(rgar($config['meta'], 'paytm_form_conditional_operator'), 'ends_with'); ?>><?php esc_html_e('ends with', 'paytm-gravity-forms'); ?></option>
                                </select>
                                <div id="gf_paytm_form_conditional_value_container" name="gf_paytm_form_conditional_value_container" style="display:inline;"></div>
                            </div>
                            <div id="gf_paytm_form_conditional_message" style="display:none">
                                <?php esc_html_e('To create a registration condition, your form must have a field supported by conditional logic.', 'paytm-gravity-forms'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="paytm_form_submit_container" class="paytm-feed-card margin_vertical_30">
                <input type="submit" name="gf_paytm_form_submit" value="<?php echo esc_attr($submit_label); ?>" class="button-primary"/>
                <input type="button" value="<?php echo esc_attr__('Cancel', 'paytm-gravity-forms'); ?>" class="button" onclick="document.location='<?php echo esc_js($list_url); ?>'" />
            </div>
        </div>
    </form>
</div>

<script type="text/javascript">
    jQuery(document).ready(function(){
        SetPeriodNumber('#gf_paytm_form_billing_cycle_number', jQuery('#gf_paytm_form_billing_cycle_type').val());
        SetPeriodNumber('#gf_paytm_form_trial_period_number', jQuery('#gf_paytm_form_trial_period_type').val());
    });

    function SelectType(type){
        jQuery('#paytm_form_field_group').slideUp();
        jQuery('#paytm_form_field_group input[type="text"], #paytm_form_field_group select').val('');
        jQuery('#gf_paytm_form_trial_period_type, #gf_paytm_form_billing_cycle_type').val('M');
        jQuery('#paytm_form_field_group input:checked').attr('checked', false);

        if (type) {
            jQuery('#paytm_form_form_container').slideDown();
            jQuery('#gf_paytm_form_form').val('');
        } else {
            jQuery('#paytm_form_form_container').slideUp();
        }
    }

    function SelectForm(type, formId, settingId){
        if (!formId) {
            jQuery('#paytm_form_field_group').slideUp();
            return;
        }

        jQuery('#paytm_form_wait').show();
        jQuery('#paytm_form_field_group').slideUp();

        var mysack = new sack(ajaxurl);
        mysack.execute = 1;
        mysack.method = 'POST';
        mysack.setVar('action', 'gf_select_paytm_form_form');
        mysack.setVar('gf_select_paytm_form_form', '<?php echo esc_js(wp_create_nonce('gf_select_paytm_form_form')); ?>');
        mysack.setVar('type', type);
        mysack.setVar('form_id', formId);
        mysack.setVar('setting_id', settingId);
        mysack.onError = function() {
            jQuery('#paytm_form_wait').hide();
            alert('<?php echo esc_js(__('Ajax error while selecting a form', 'paytm-gravity-forms')); ?>');
        };
        mysack.runAJAX();

        return true;
    }

    function EndSelectForm(form_meta, customer_fields, recurring_amount_options){
        form = form_meta;
        var type = jQuery('#gf_paytm_form_type').val();

        jQuery('.gf_paytm_form_invalid_form').hide();
        if ((type == 'product' || type == 'subscription') && GetFieldsByType(['product']).length == 0) {
            jQuery('#gf_paytm_form_invalid_product_form').show();
            jQuery('#paytm_form_wait').hide();
            return;
        } else if (type == 'donation' && GetFieldsByType(['product', 'donation']).length == 0) {
            jQuery('#gf_paytm_form_invalid_donation_form').show();
            jQuery('#paytm_form_wait').hide();
            return;
        }

        jQuery('.paytm_form_field_container').hide();
        jQuery('#paytm_form_customer_fields').html(customer_fields);
        jQuery('#gf_paytm_form_recurring_amount').html(recurring_amount_options);

        var post_fields = GetFieldsByType(['post_title', 'post_content', 'post_excerpt', 'post_category', 'post_custom_field', 'post_image', 'post_tag']);
        if (post_fields.length > 0) {
            jQuery('#paytm_form_post_action').show();
        } else {
            jQuery('#gf_paytm_form_delay_post').attr('checked', false);
            jQuery('#paytm_form_post_action').hide();
        }

        if (type == 'subscription' && post_fields.length > 0) {
            jQuery('#paytm_form_post_update_action').show();
        } else {
            jQuery('#gf_paytm_form_update_post').attr('checked', false);
            jQuery('#paytm_form_post_update_action').hide();
        }

        SetPeriodNumber('#gf_paytm_form_billing_cycle_number', jQuery('#gf_paytm_form_billing_cycle_type').val());
        SetPeriodNumber('#gf_paytm_form_trial_period_number', jQuery('#gf_paytm_form_trial_period_type').val());

        jQuery(document).trigger('paytm_formFormSelected', [form]);

        jQuery('#gf_paytm_form_conditional_enabled').attr('checked', false);
        SetPaytmFormCondition('', '');

        if (form['notifications']) {
            jQuery('#gf_paytm_form_notifications').show();
            jQuery('#paytm_form_delay_autoresponder, #paytm_form_delay_notification').hide();
        } else {
            jQuery('#paytm_form_delay_autoresponder, #paytm_form_delay_notification').show();
            jQuery('#gf_paytm_form_notifications').hide();
        }

        jQuery('#paytm_form_field_container_' + type).show();
        jQuery('#paytm_form_field_group').slideDown();
        jQuery('#paytm_form_wait').hide();
    }

    function SetPeriodNumber(element, type){
        var prev = jQuery(element).val();
        var min = 1;
        var max = 0;
        switch (type) {
            case 'D': max = 100; break;
            case 'W': max = 52; break;
            case 'M': max = 12; break;
            case 'Y': max = 5; break;
        }
        var str = '';
        for (var i = min; i <= max; i++) {
            var selected = prev == i ? "selected='selected'" : '';
            str += "<option value='" + i + "' " + selected + '>' + i + '</option>';
        }
        jQuery(element).html(str);
    }

    function GetFieldsByType(types){
        var fields = [];
        if (!form || !form['fields']) {
            return fields;
        }
        for (var i = 0; i < form['fields'].length; i++) {
            if (IndexOf(types, form['fields'][i]['type']) >= 0) {
                fields.push(form['fields'][i]);
            }
        }
        return fields;
    }

    function IndexOf(ary, item){
        for (var i = 0; i < ary.length; i++) {
            if (ary[i] == item) {
                return i;
            }
        }
        return -1;
    }
</script>

<script type="text/javascript">
    <?php if (!empty($config['form_id'])) : ?>
        form = <?php echo GFCommon::json_encode($form); ?>;
        jQuery(document).ready(function(){
            var selectedField = <?php echo wp_json_encode((string) rgar($config['meta'], 'paytm_form_conditional_field_id')); ?>;
            var selectedValue = <?php echo wp_json_encode((string) rgar($config['meta'], 'paytm_form_conditional_value')); ?>;
            SetPaytmFormCondition(selectedField, selectedValue);
        });
    <?php endif; ?>

    function SetPaytmFormCondition(selectedField, selectedValue){
        jQuery('#gf_paytm_form_conditional_field_id').html(GetSelectableFields(selectedField, 20));
        var optinConditionField = jQuery('#gf_paytm_form_conditional_field_id').val();
        var checked = jQuery('#gf_paytm_form_conditional_enabled').attr('checked');

        if (optinConditionField) {
            jQuery('#gf_paytm_form_conditional_message').hide();
            jQuery('#gf_paytm_form_conditional_fields').show();
            jQuery('#gf_paytm_form_conditional_value_container').html(GetFieldValues(optinConditionField, selectedValue, 20));
            jQuery('#gf_paytm_form_conditional_value').val(selectedValue);
        } else {
            jQuery('#gf_paytm_form_conditional_message').show();
            jQuery('#gf_paytm_form_conditional_fields').hide();
        }

        if (!checked) {
            jQuery('#gf_paytm_form_conditional_container').hide();
        }
    }

    function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
        if (!fieldId) {
            return '';
        }

        var str = '';
        var field = GetFieldById(fieldId);
        if (!field) {
            return '';
        }

        var isAnySelected = false;

        if (field['type'] == 'post_category' && field['displayAllCategories']) {
            str += '<?php $dd = wp_dropdown_categories(array('class' => 'optin_select', 'orderby' => 'name', 'id' => 'gf_paytm_form_conditional_value', 'name' => 'gf_paytm_form_conditional_value', 'hierarchical' => true, 'hide_empty' => 0, 'echo' => false)); echo str_replace("\n", '', str_replace("'", "\\'", $dd)); ?>';
        } else if (field.choices) {
            str += '<select id="gf_paytm_form_conditional_value" name="gf_paytm_form_conditional_value" class="optin_select">';
            for (var i = 0; i < field.choices.length; i++) {
                var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                var isSelected = fieldValue == selectedValue;
                var selected = isSelected ? "selected='selected'" : '';
                if (isSelected) {
                    isAnySelected = true;
                }
                str += "<option value='" + fieldValue.replace(/'/g, '&#039;') + "' " + selected + '>' + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + '</option>';
            }
            if (!isAnySelected && selectedValue) {
                str += "<option value='" + selectedValue.replace(/'/g, '&#039;') + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + '</option>';
            }
            str += '</select>';
        } else {
            selectedValue = selectedValue ? selectedValue.replace(/'/g, '&#039;') : '';
            str += "<input type='text' placeholder='<?php echo esc_js(__('Enter value', 'paytm-gravity-forms')); ?>' id='gf_paytm_form_conditional_value' name='gf_paytm_form_conditional_value' value='" + selectedValue.replace(/'/g, '&#039;') + "'>";
        }

        return str;
    }

    function GetFieldById(fieldId){
        for (var i = 0; i < form.fields.length; i++) {
            if (form.fields[i].id == fieldId) {
                return form.fields[i];
            }
        }
        return null;
    }

    function TruncateMiddle(text, maxCharacters){
        if (!text) {
            return '';
        }
        if (text.length <= maxCharacters) {
            return text;
        }
        var middle = parseInt(maxCharacters / 2, 10);
        return text.substr(0, middle) + '...' + text.substr(text.length - middle, middle);
    }

    function GetSelectableFields(selectedFieldId, labelMaxCharacters){
        var str = '';
        for (var i = 0; i < form.fields.length; i++) {
            var fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
            if (IsConditionalLogicField(form.fields[i])) {
                var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : '';
                str += "<option value='" + form.fields[i].id + "' " + selected + '>' + TruncateMiddle(fieldLabel, labelMaxCharacters) + '</option>';
            }
        }
        return str;
    }

    function IsConditionalLogicField(field){
        var inputType = field.inputType ? field.inputType : field.type;
        var supported_fields = ['checkbox', 'radio', 'select', 'text', 'website', 'textarea', 'email', 'hidden', 'number', 'phone', 'multiselect', 'post_title', 'post_tags', 'post_custom_field', 'post_content', 'post_excerpt'];
        return jQuery.inArray(inputType, supported_fields) >= 0;
    }
</script>
        <?php
    }

    public static function select_paytm_form_form(){

        check_ajax_referer("gf_select_paytm_form_form", "gf_select_paytm_form_form");

        $type = $_POST["type"];
        $form_id =  intval($_POST["form_id"]);
        $setting_id =  intval($_POST["setting_id"]);

        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);

        $customer_fields = self::get_customer_information($form);
        $recurring_amount_fields = self::get_product_options($form, "");

        die("EndSelectForm(" . GFCommon::json_encode($form) . ", " . GFCommon::json_encode($customer_fields) . ", " . GFCommon::json_encode($recurring_amount_fields) . ");");
    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "paytm-gravity-forms");
        $wp_roles->add_cap("administrator", "paytm-gravity-forms_uninstall");
    }

    private static function ensure_permissions(){
        global $wp_roles;
        if(!isset($wp_roles) || !$wp_roles->get_role("administrator"))
            return;

        if(!$wp_roles->get_role("administrator")->has_cap("paytm-gravity-forms"))
            self::add_permissions();
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("paytm-gravity-forms", "paytm-gravity-forms_uninstall"));
    }

    public static function get_active_config($form, $entry = null){

        require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");

        $configs = GFPaytmFormData::get_feed_by_form($form["id"], true);
        if(!$configs)
            return false;

        foreach($configs as $config){
            if(self::has_paytm_form_condition($form, $config, $entry))
                return $config;
        }

        return false;
    }

    private static $confirmation_script_enqueued = false;

    public static function enqueue_confirmation_script($form, $is_ajax = false) {
        if (self::$confirmation_script_enqueued || empty($form['id'])) {
            return;
        }

        if (!class_exists('GFPaytmFormData')) {
            require_once(GF_PAYTM_FORM_BASE_PATH . '/data.php');
        }

        if (!GFPaytmFormData::get_feed_by_form($form['id'])) {
            return;
        }

        self::$confirmation_script_enqueued = true;
        wp_enqueue_script('jquery');

        $script = "(function($){
    function getPaytmWrap() {
        var \$wraps = $('.paytm-gf-checkout-wrap');
        if (\$wraps.length > 1) {
            \$wraps.slice(0, -1).remove();
        }
        return $('.paytm-gf-checkout-wrap').last();
    }

    function loadPaytmScript(url, callback) {
        if (window.Paytm && window.Paytm.CheckoutJS) {
            callback();
            return;
        }
        var existing = document.querySelector('script[data-paytm-checkout=\"1\"]');
        if (existing) {
            existing.addEventListener('load', callback);
            return;
        }
        var script = document.createElement('script');
        script.type = 'application/javascript';
        script.crossOrigin = 'anonymous';
        script.src = url;
        script.setAttribute('data-paytm-checkout', '1');
        script.onload = callback;
        document.head.appendChild(script);
    }

    function initPaytmCheckout() {
        var \$wrap = getPaytmWrap();
        if (!\$wrap.length || \$wrap.data('paytm-inited')) {
            return;
        }

        var checkoutUrl = \$wrap.data('checkout-url');
        var orderId = \$wrap.data('order-id');
        var txnToken = \$wrap.data('token');
        var amount = \$wrap.data('amount');
        var formId = \$wrap.data('form-id');
        var initKey = 'paytmGfInit_' + formId + '_' + \$wrap.attr('id');

        if (!checkoutUrl || !orderId || !txnToken) {
            return;
        }

        if (window[initKey]) {
            \$wrap.data('paytm-inited', true);
            return;
        }

        window[initKey] = true;
        \$wrap.data('paytm-inited', true);

        loadPaytmScript(checkoutUrl, function() {
            if (!window.Paytm || !window.Paytm.CheckoutJS) {
                return;
            }

            var config = {
                root: '',
                flow: 'DEFAULT',
                data: {
                    orderId: orderId,
                    token: txnToken,
                    tokenType: 'TXN_TOKEN',
                    amount: amount
                },
                integration: {
                    platform: 'Wordpress GF',
                    version: \$wrap.data('gf-version') || ''
                },
                handler: {
                    notifyMerchant: function(eventName) {
                        if (eventName === 'APP_CLOSED') {
                            \$wrap.find('.paytm-pg-loader, .paytm-overlay').hide();
                        }
                    }
                }
            };

            window.Paytm.CheckoutJS.onLoad(function() {
                window.Paytm.CheckoutJS.init(config).then(function() {
                    if (parseInt(\$wrap.data('auto-invoke'), 10) === 1) {
                        window.Paytm.CheckoutJS.invoke();
                        \$wrap.find('.paytm-pg-loader, .paytm-overlay').hide();
                    }
                }).catch(function(error) {
                    console.log('Paytm checkout error:', error);
                });
            });

            \$wrap.find('#invovkePayment').off('click.paytm').on('click.paytm', function(e) {
                e.preventDefault();
                window.Paytm.CheckoutJS.invoke();
                return false;
            });
        });
    }

    function bootPaytmCheckout() {
        getPaytmWrap();
        initPaytmCheckout();
    }

    $(document).on('gform_confirmation_loaded', bootPaytmCheckout);
    $(bootPaytmCheckout);
})(jQuery);";

        wp_add_inline_script('jquery', $script);
    }

    public static function send_to_paytm_form($confirmation, $form, $entry, $ajax){

        // ignore requests that are not the current form's submissions
            error_reporting(1); 
      if(RGForms::post("gform_submit") != $form["id"]){
        return $confirmation;
          }

            $settings = get_option("gf_paytm_form_settings");
            $paytm_mid = rgar($settings,"paytm_mid");
            $paytm_key = rgar($settings,"paytm_key");
            $paytm_website = rgar($settings,"paytm_website");
            $paytm_channel_id = rgar($settings,"paytm_channel_id");
            $paytm_industry_type_id = rgar($settings,"paytm_industry_type_id");
            $paytm_custom_callback = rgar($settings,"paytm_custom_callback");
            $paytm_callback_url = rgar($settings,"paytm_callback_url");
            $paytm_env = rgar($settings,"paytm_env");
            $config = self::get_active_config($form, $entry);

      if(!$config){
        self::log_debug("NOT sending to Paytm Form: No Paytm Form setup matching condition for form_id = {$form['id']}.");
        return $confirmation;
            }

            // updating entry meta with current feed id
      gform_update_meta($entry["id"], "paytm_form_feed_id", $config["id"]);

      // updating entry meta with current payment gateway
      gform_update_meta($entry["id"], "payment_gateway", "paytmform");

      //updating lead's payment_status to Processing
      RGFormsModel::update_lead_property($entry["id"], "payment_status", 'Processing');
        
      $invoice_id = apply_filters("gform_paytm_form_invoice", "", $form, $entry);
            
            $red = $entry['id'];
        
        $invoice = empty($invoice_id) ? $red : $invoice_id;

        //Current Currency
      $currency = GFCommon::get_currency();
        
        //Customer fields
      $fields = "";
      $first_name = "";
      $last_name = "";
      $phone = "";
      $email = "";

      foreach(self::get_customer_fields() as $field){


        $field_id = $config["meta"]["customer_fields"][$field["name"]];
        $value = rgar($entry,$field_id);
                if( $field["name"] == "first_name" ){
                    $first_name = $value;
                    $value = '';
                }else if( $field["name"] == "last_name" ){
                    $last_name = $value;
                    $value = '';
                }else if( $field["name"] == "phone" ){
                    $phone = $value;
                    $value = '';
                }else   if( $field["name"] == "email" ){
                    $email = $value;
                    $value = '';
                }else   if( $field["name"] == "amount" ){
                    $amount = $value;
                    $value = '';
                }
      }

      if (empty($amount) && $amount <= 0) {
           $amount = GFCommon::get_order_total($form, $entry);
      }



        $time_stamp = date("ymdHis");
        $orderid = $time_stamp . "-" . $invoice;
        if (!empty($email)) {
           $cust_id = $email;
        }elseif (!empty($phone)) {
           $cust_id = $phone;
        }else{
           $cust_id = $time_stamp;

        }

        $paytmParams["body"] = array(
                "requestType" => "Payment",
                "mid" => $paytm_mid,
                "websiteName" => $paytm_website,
                "orderId" => $orderid,
                "callbackUrl" => self::getDefaultCallbackUrl(),
                "txnAmount" => array(
                    "value" => (float) filter_var( $amount, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION ),
                    "currency" => "INR",
                ),
                "userInfo" => array(
                    "custId" => $cust_id,
                ),
        );

        gform_update_meta($entry["id"], "paytm_order_id", $orderid);
        gform_update_meta($entry["id"], "paytm_initiated_amount", $paytmParams["body"]["txnAmount"]["value"]);
            
        $checksum = PaytmChecksum::generateSignature(json_encode($paytmParams["body"], JSON_UNESCAPED_SLASHES), $paytm_key); 
            
        $paytmParams["head"] = array(
                "signature" => $checksum
        );
            
        /* prepare JSON string for request */
        $post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);

      // print_r($paytmParams);  exit; 

        $url = PaytmHelper::getPaytmURL(PaytmConstantsGF::INITIATE_TRANSACTION_URL, $paytm_env) . '?mid='.$paytmParams["body"]["mid"].'&orderId='.$paytmParams["body"]["orderId"];
            
        $res= PaytmHelper::executecUrl($url, $paytmParams);
            
        if(!empty($res['body']['resultInfo']['resultStatus']) && $res['body']['resultInfo']['resultStatus'] == 'S'){
                $data['txnToken']= $res['body']['txnToken'];
        }
        else
        {
                $data['txnToken']="";
        }


 
        //If page is HTTPS, set return mode to 2 (meaning Paytm Form will post info back to page)
        //If page is not HTTPS, set return mode to 1 (meaning Paytm Form will redirect back to page) to avoid security warning
        
        $return_url = self::return_url($form["id"], $entry["id"]);

        //Cancel URL
        $cancel_url = !empty($config["meta"]["cancel_url"]) ? $config["meta"]["cancel_url"] : "";

        //URL that will listen to notifications from Paytm Form
        $ipn_url = get_bloginfo("url") . "/?page=gf_paytm_form_ipn";
        
        $url = apply_filters("gform_paytm_form_request_{$form['id']}", apply_filters("gform_paytm_form_request", $url, $form, $entry), $form, $entry);

        self::log_debug("Token generated sending payment request to Paytm ");
                
        //wp_die("<pre>".print_r($test123,TRUE)."</pre><pre>".print_r($paytm_arg,TRUE)."</pre>"); exit;
        
        $ajax = TRUE;
        
        if(headers_sent() || $ajax){
            
            $paytm_arg_array = array();

            $checkout_url = str_replace('MID',$paytm_mid, PaytmHelper::getPaytmURL(PaytmConstantsGF::CHECKOUT_JS_URL,$paytm_env));
            $paytm_wrap_id = 'paytm-gf-wrap-' . absint($form['id']) . '-' . absint($entry['id']);

            $confirmation = '<div id="' . esc_attr($paytm_wrap_id) . '" class="paytm-gf-checkout-wrap"'
                . ' data-form-id="' . esc_attr($form['id']) . '"'
                . ' data-checkout-url="' . esc_attr($checkout_url) . '"'
                . ' data-order-id="' . esc_attr($orderid) . '"'
                . ' data-token="' . esc_attr($data['txnToken']) . '"'
                . ' data-amount="' . esc_attr($paytmParams["body"]['txnAmount']['value']) . '"'
                . ' data-gf-version="' . esc_attr(get_bloginfo('version') . '|2.1.0') . '"'
                . ' data-auto-invoke="1">'
                . '<button type="button" class="button btn btn-info" id="invovkePayment">Pay</button>'
                . '<a class="button cancel btn btn-danger" href="#">Cancel</a>'
                . '<div id="paytm-pg-spinner" class="paytm-pg-loader">'
                . '<div class="bounce1"></div><div class="bounce2"></div><div class="bounce3"></div><div class="bounce4"></div><div class="bounce5"></div>'
                . '</div>'
                . '<div class="paytm-overlay paytm-pg-loader"></div>'
                . '<style type="text/css">
.btn-info { color: #fff; background-color: #5bc0de; border-color: #46b8da; }
.btn-danger { color: #fff; background-color: #d9534f; border-color: #d43f3a; text-decoration: none; }
.btn { display: inline-block; margin-bottom: 0; font-weight: 400; text-align: center; white-space: nowrap; vertical-align: middle; cursor: pointer; background-image: none; border: 1px solid transparent; padding: 6px 12px; font-size: 14px; line-height: 1.42857143; border-radius: 4px; user-select: none; }
#paytm-pg-spinner { margin: 0 auto; width: 70px; text-align: center; z-index: 999999; position: relative; }
#paytm-pg-spinner > div { width: 10px; height: 10px; background-color: #012b71; border-radius: 100%; display: inline-block; animation: sk-bouncedelay 1.4s infinite ease-in-out both; }
#paytm-pg-spinner .bounce1 { animation-delay: -0.64s; }
#paytm-pg-spinner .bounce2 { animation-delay: -0.48s; }
#paytm-pg-spinner .bounce3 { animation-delay: -0.32s; }
#paytm-pg-spinner .bounce4 { animation-delay: -0.16s; }
#paytm-pg-spinner .bounce4, #paytm-pg-spinner .bounce5 { background-color: #48baf5; }
@keyframes sk-bouncedelay { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1.0); } }
.paytm-overlay { width: 100%; position: fixed; top: 0; opacity: .4; height: 100%; background: #000; left: 0; z-index: 999999; }
</style></div>';

            self::enqueue_confirmation_script($form, $ajax);
        }
        
RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(esc_attr_e("Payment has been initiated. Amount: %s. Transaction Id: %s", "paytm-gravity-forms"), GFCommon::to_money($paytmParams["body"]['txnAmount']['value'], $entry["currency"]), $orderid));        


return $confirmation;
    }




    public static function fetch_paytm_transaction_status($order_id){
        $order_id = sanitize_text_field((string) $order_id);
        if ($order_id === '') {
            return array();
        }

        $settings = get_option("gf_paytm_form_settings");
        $paytm_mid = (string) rgar($settings, "paytm_mid");
        $paytm_key = (string) rgar($settings, "paytm_key");
        $paytm_env = rgar($settings, "paytm_env");

        if ($paytm_mid === '' || $paytm_key === '') {
            return array();
        }

        $request = array(
            "MID" => $paytm_mid,
            "ORDERID" => $order_id,
        );
        $request["CHECKSUMHASH"] = PaytmChecksum::generateSignature($request, $paytm_key);

        $url = PaytmHelper::getTransactionStatusURL($paytm_env);
        $response = PaytmHelper::executecUrl($url, $request);

        return is_array($response) ? $response : array();
    }

    public static function confirm_paytm_transaction($entry, $config, $order_id){
        $result = array(
            'confirmed' => false,
            'failed' => false,
            'amount' => 0,
            'message' => __('Payment could not be verified. Please try again.', 'paytm-gravity-forms'),
        );

        $order_id = sanitize_text_field((string) $order_id);
        if ($order_id === '' || empty($entry['id'])) {
            $result['message'] = __('Security error! Invalid payment response.', 'paytm-gravity-forms');
            return $result;
        }

        $stored_order_id = (string) gform_get_meta($entry['id'], 'paytm_order_id');
        if ($stored_order_id !== '' && !hash_equals($stored_order_id, $order_id)) {
            self::log_error('Paytm callback order id does not match the initiated order. Entry ID: ' . absint($entry['id']));
            $result['message'] = __('Security error! Invalid payment response.', 'paytm-gravity-forms');
            return $result;
        }

        $status = self::fetch_paytm_transaction_status($order_id);
        $result_status = isset($status['STATUS']) ? (string) $status['STATUS'] : '';
        $result_code = isset($status['RESPCODE']) ? (string) $status['RESPCODE'] : '';
        $api_order_id = isset($status['ORDERID']) ? (string) $status['ORDERID'] : '';
        $api_mid = isset($status['MID']) ? (string) $status['MID'] : '';
        $api_amount = isset($status['TXNAMOUNT']) ? $status['TXNAMOUNT'] : 0;

        self::log_debug('Paytm transaction status for entry ' . absint($entry['id']) . ': ' . $result_status . ' / ' . $result_code);

        if ($result_status === '') {
            $result['message'] = __('Payment could not be verified with Paytm. Please try again.', 'paytm-gravity-forms');
            return $result;
        }

        if ($api_order_id === '' || !hash_equals($order_id, $api_order_id)) {
            self::log_error('Paytm status order id mismatch. Entry ID: ' . absint($entry['id']));
            $result['message'] = __('Security error! Invalid payment response.', 'paytm-gravity-forms');
            return $result;
        }

        $settings = get_option('gf_paytm_form_settings');
        $merchant_mid = (string) rgar($settings, 'paytm_mid');
        if ($api_mid !== '' && $merchant_mid !== '' && !hash_equals($merchant_mid, $api_mid)) {
            self::log_error('Paytm status merchant id mismatch. Entry ID: ' . absint($entry['id']));
            $result['message'] = __('Security error! Invalid payment response.', 'paytm-gravity-forms');
            return $result;
        }

        $result['amount'] = $api_amount;

        if ($result_status === 'TXN_SUCCESS' && $result_code === '01') {
            $expected = self::get_expected_payment_amount($config, $entry);
            if (!self::payment_amount_covers($api_amount, $expected)) {
                self::log_error('Paytm amount mismatch for entry ' . absint($entry['id']) . '. Paid ' . $api_amount . ', expected ' . $expected);
                $result['message'] = __('Security error! Amount mismatched.', 'paytm-gravity-forms');
                return $result;
            }

            $result['confirmed'] = true;
            $result['message'] = '';
            return $result;
        }

        if ($result_status === 'TXN_FAILURE') {
            $result['failed'] = true;
            $result['message'] = __('Payment failed. Please try again.', 'paytm-gravity-forms');
            return $result;
        }

        $result['message'] = __('Payment is pending confirmation from Paytm.', 'paytm-gravity-forms');
        return $result;
    }

    private static function get_expected_payment_amount($config, $entry){
        $stored_amount = gform_get_meta($entry['id'], 'paytm_initiated_amount');
        $has_stored_amount = ($stored_amount !== '' && $stored_amount !== null && $stored_amount !== false && is_numeric($stored_amount));
        $initiated_amount = $has_stored_amount ? (float) $stored_amount : 0;

        $form = RGFormsModel::get_form_meta($entry['form_id']);
        $order_total = (is_array($form) && class_exists('GFCommon')) ? (float) GFCommon::get_order_total($form, $entry) : 0;
        $feed_type = rgars($config, 'meta/type');

        if ($feed_type === 'donation') {
            return $has_stored_amount ? $initiated_amount : $order_total;
        }

        if ($order_total > 0) {
            return ($has_stored_amount && $initiated_amount > $order_total) ? $initiated_amount : $order_total;
        }

        return $initiated_amount;
    }

    private static function payment_amount_covers($paid, $expected){
        if (!is_numeric($paid) || !is_numeric($expected)) {
            return false;
        }

        return round((float) $paid, 2) + 0.001 >= round((float) $expected, 2);
    }

     public static function set_payment_status($config, $entry, $status, $transaction_id, $parent_transaction_id, $amount){
        if(!class_exists("GFPaytmFormData")){
            require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");
        }

        global $current_user;
        $user_id = 0;
        $user_name = "System";
        if($current_user && $user_data = get_userdata($current_user->ID)){
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }
        self::log_debug("Payment status: {$status} - Transaction ID: {$transaction_id} - Parent Transaction: {$parent_transaction_id} - Amount: {$amount}");
        self::log_debug("Entry: " . print_r($entry, true));

        //handles products and donation
        switch($status){
            case "SUCCESS" :
                self::log_debug("Processing a completed payment");
                if($entry["payment_status"] != "Success"){
                    
                    
                        self::log_debug("Entry is not already Success. Proceeding...");
                        $entry["payment_status"] = "Paid";
                        $entry["payment_amount"] = $amount;
                        $entry["payment_date"] = gmdate("y-m-d H:i:s");
                        $entry["transaction_id"] = $transaction_id;
                        $entry["transaction_type"] = 1; //payment

                        if(!$entry["is_fulfilled"]){
                            self::log_debug("Payment has been made. Fulfilling order.");
                            self::fulfill_order($entry, $transaction_id, $amount);
                            self::log_debug("Order has been fulfilled");
                            $entry["is_fulfilled"] = true;
                        }

                        self::log_debug("Updating entry.");
                       // RGFormsModel::update_lead($entry);
                         GFAPI::update_entry($entry);
                        self::log_debug("Adding note.");
                        RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(esc_attr_e("Payment has been approved. Amount: %s. Transaction Id: %s", "paytm-gravity-forms"), GFCommon::to_money($entry["payment_amount"], $entry["currency"]), $transaction_id));
                    
                }
                self::log_debug("Inserting transaction.");
                GFPaytmFormData::insert_transaction($entry["id"], "payment", $transaction_id, $parent_transaction_id, $amount);
                
                
            break;

            

            case "FAILED" :
            
                $StatusDetail = isset($_POST['RESPMSG']) ? $_POST['RESPMSG'] : '';
                
                self::log_debug("Processed a Failed request.");
                if($entry["payment_status"] != "Failed"){
                    if(empty($entry["transaction_type"])){
                        $entry["payment_status"] = "Failed";
                        self::log_debug("Setting entry as Failed.");
                        if(class_exists('GFAPI')){
                            GFAPI::update_entry_property($entry['id'], 'payment_status', 'Failed');
                        }else{
                            RGFormsModel::update_lead_property($entry['id'], 'payment_status', 'Failed');
                        }
                    }
                    if(!empty($StatusDetail)){
                        RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has Failed. %s Transaction Id: %s", "paytm-gravity-forms"), $StatusDetail, $transaction_id));
                    }else{
                        RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(__("Payment has Failed. Failed payments occur when they are made via your customer's bank account and could not be completed. Transaction Id: %s", "paytm-gravity-forms"), $transaction_id));
                    }
                }

                GFPaytmFormData::insert_transaction($entry["id"], "failed", $transaction_id, $parent_transaction_id, $amount);

            break;

        }
                
        self::log_debug("Before gform_post_payment_status.");
        do_action("gform_post_payment_status", $config, $entry, $status, $transaction_id, $amount);
    }

    

    
    public static function has_paytm_form_condition($form, $config, $entry = null) {

        $config = $config["meta"];

        $operator = isset($config["paytm_form_conditional_operator"]) ? $config["paytm_form_conditional_operator"] : "";
        $field = RGFormsModel::get_field($form, rgar($config, "paytm_form_conditional_field_id"));

        // Condition disabled or field unavailable: always send to Paytm.
        if (empty($field) || empty($config["paytm_form_conditional_enabled"])) {
            return true;
        }

        if (!empty($entry) && is_array($entry)) {
            $is_visible  = !RGFormsModel::is_field_hidden($form, $field, array(), $entry);
            $field_value = RGFormsModel::get_lead_field_value($entry, $field);
        } else {
            // Fallback for hooks that run before a full entry object exists.
            $is_visible  = !RGFormsModel::is_field_hidden($form, $field, array());
            $field_value = RGFormsModel::get_field_value($field, array());
        }

        // If the condition field is hidden by GF conditional logic, do not send to Paytm.
        if (!$is_visible) {
            return false;
        }

        return RGFormsModel::is_value_match($field_value, rgar($config, "paytm_form_conditional_value"), $operator);
    }

    public static function get_config($form_id){
        if(!class_exists("GFPaytmFormData"))
            require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");

        //Getting paytm_form settings associated with this transaction
        $config = GFPaytmFormData::get_feed_by_form($form_id);

        //Ignore IPN messages from forms that are no longer configured with the Paytm Form add-on
        if(!$config)
            return false;

        return $config[0]; //only one feed per form is supported (left for backwards compatibility)
    }

    public static function get_config_by_entry($entry) {

        if(!class_exists("GFPaytmFormData"))
            require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");

        $feed_id = gform_get_meta($entry["id"], "paytm_form_feed_id");
        $feed = GFPaytmFormData::get_feed($feed_id);

        return !empty($feed) ? $feed : false;
    }



    

    public static function fulfill_order(&$entry, $transaction_id, $amount){

        $config = self::get_config_by_entry($entry);
        if(!$config){
            self::log_error("Order can't be fulfilled because feed wasn't found for form: {$entry["form_id"]}");
            return;
        }

        $form = RGFormsModel::get_form_meta($entry["form_id"]);
        if($config["meta"]["delay_post"]){
            self::log_debug("Creating post.");
            RGFormsModel::create_post($form, $entry);
        }

        if(isset($config["meta"]["delay_notifications"])){
            //sending delayed notifications
            GFCommon::send_notifications($config["meta"]["selected_notifications"], $form, $entry, true, "form_submission");

        }
        else{

            //sending notifications using the legacy structure
            if($config["meta"]["delay_notification"]){
               self::log_debug("Sending admin notification.");
               GFCommon::send_admin_notification($form, $entry);
            }

            if($config["meta"]["delay_autoresponder"]){
               self::log_debug("Sending user notification.");
               GFCommon::send_user_notification($form, $entry);
            }
        }

        self::log_debug("Before gform_paytm_form_fulfillment.");
        do_action("gform_paytm_form_fulfillment", $entry, $config, $transaction_id, $amount);
    }

    private static function customer_query_string($config, $lead){
        $fields = "";
        $first_name = "";
        $last_name = "";
        foreach(self::get_customer_fields() as $field){
            $field_id = $config["meta"]["customer_fields"][$field["name"]];
            $value = rgar($lead,$field_id);

            if($field["name"] == "country")
                $value = GFCommon::get_country_code($value);
            else if($field["name"] == "state")
                $value = GFCommon::get_us_state_code($value);
                
            if( $field["name"] == "first_name" ){
                $first_name = $value;
                $value = '';
            }
                
            if( $field["name"] == "last_name" ){
                $last_name = $value;
                $value = '';
            }

            if(!empty($value))
                $fields .="&{$field["name"]}=" . urlencode($value);
        }
        
        if(!empty($first_name) && !empty($last_name))
                $fields .="&name=" . urlencode($first_name.' '.$last_name);

        return $fields;
    }

    private static function is_valid_initial_payment_amount($config, $lead){

        $form = RGFormsModel::get_form_meta($lead["form_id"]);
        $products = GFCommon::get_product_fields($form, $lead, true);
        
        $payment_amount = $_POST['TXN_AMOUNT'];

        $product_amount = 0;
        switch($config["meta"]["type"]){
            case "product" :
                $product_amount = GFCommon::get_order_total($form, $lead);
            break;

            case "donation" :
                $query_string = self::get_donation_query_string($form, $lead);
                parse_str($query_string, $donation_info);
                $product_amount = $donation_info["amount"];

            break;

        }

        //initial payment is valid if it is equal to or greater than product/subscription amount
        if(floatval($payment_amount) >= floatval($product_amount)){
            return true;
        }

        return false;

    }

    private static function get_product_query_string($form, $entry){
        $fields = "";
        $products = GFCommon::get_product_fields($form, $entry, true);
        $product_index = 1;
        $total = 0;
        $discount = 0;

        foreach($products["products"] as $product){
            $option_fields = "";
            $price = GFCommon::to_number($product["price"]);
            if(is_array(rgar($product,"options"))){
                $option_index = 1;
                foreach($product["options"] as $option){
                    $field_label = urlencode($option["field_label"]);
                    $option_name = urlencode($option["option_name"]);
                    $option_fields .= "&on{$option_index}_{$product_index}={$field_label}&os{$option_index}_{$product_index}={$option_name}";
                    $price += GFCommon::to_number($option["price"]);
                    $option_index++;
                }
            }

            $name = urlencode($product["name"]);
            if($price > 0)
            {
                //$fields .= "&item_name_{$product_index}={$name}&amount_{$product_index}={$price}&quantity_{$product_index}={$product["quantity"]}{$option_fields}";
                $total += $price * $product['quantity'];
                $product_index++;
            }
            else{
                $discount += abs($price) * $product['quantity'];
            }

        }

        if($discount > 0){
            $total = $total - $discount;
        }

        $total = !empty($products["shipping"]["price"]) ? $total + $products["shipping"]["price"] : $total;
        
        $desc = urlencode("Order #".$entry['id']);
        $fields .= "&amount={$total}&desc={$desc}";
        
        return $total > 0 && $total > $discount ? $total : false;
    }

    private static function get_donation_query_string($form, $entry){
        $fields = "";

        //getting all donation fields
        $donations = GFCommon::get_fields_by_type($form, array("donation"));
        $total = 0;
        $purpose = "";
        foreach($donations as $donation){
            $value = RGFormsModel::get_lead_field_value($entry, $donation);
            list($name, $price) = explode("|", $value);
            if(empty($price)){
                $price = $name;
                $name = $donation["label"];
            }
            $purpose .= $name . ", ";
            $price = GFCommon::to_number($price);
            $total += $price;
        }

        //using product fields for donation if there aren't any legacy donation fields in the form
        if($total == 0){
            //getting all product fields
            $products = GFCommon::get_product_fields($form, $entry, true);
            foreach($products["products"] as $product){
                $options = "";
                if(is_array($product["options"]) && !empty($product["options"])){
                    $options = " (";
                    foreach($product["options"] as $option){
                        $options .= $option["option_name"] . ", ";
                    }
                    $options = substr($options, 0, strlen($options)-2) . ")";
                }
                $quantity = GFCommon::to_number($product["quantity"]);
                $quantity_label = $quantity > 1 ? $quantity . " " : "";
                $purpose .= $quantity_label . $product["name"] . $options . ", ";
            }

            $total = GFCommon::get_order_total($form, $entry);
        }

        if(!empty($purpose))
            $purpose = substr($purpose, 0, strlen($purpose)-2);

        $purpose = urlencode($purpose);

        //truncating to maximum length allowed by Paytm Form
        if(strlen($purpose) > 127)
            $purpose = substr($purpose, 0, 124) . "...";

        $fields = "&amount={$total}&desc={$purpose}";

        return $total > 0 ? $fields : false;
    }

    public static function uninstall(){

        //loading data lib
        require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");

        if(!GFPaytmForm::has_access("paytm-gravity-forms_uninstall"))
            die(esc_attr_e("You don't have adequate permission to uninstall the Paytm Form Add-On.", "paytm-gravity-forms"));

        //droping all tables
        GFPaytmFormData::drop_tables();

        //removing options
        delete_option("gf_paytm_form_site_name");
        delete_option("gf_paytm_form_auth_token");
        delete_option("gf_paytm_form_version");

        //Deactivating plugin
        $plugin = GF_PAYTM_FORM_PLUGIN;
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    protected static function has_access($required_permission){
        if(current_user_can("gform_full_access"))
            return "gform_full_access";

        if(current_user_can("gravityforms_edit_forms"))
            return "gravityforms_edit_forms";

        if(function_exists('members_get_capabilities') && current_user_can($required_permission))
            return $required_permission;

        return false;
    }

    private static function get_customer_information($form, $config=null){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form);

        $str = "<table cellpadding='0' cellspacing='0'><tr><td class='paytm_form_col_heading'>" . esc_attr__("Paytm Form Fields", "paytm-gravity-forms") . "</td><td class='paytm_form_col_heading'>" . esc_attr__("Form Fields", "paytm-gravity-forms") . "</td></tr>";
        $customer_fields = self::get_customer_fields();
        foreach($customer_fields as $field){
            $selected_field = $config ? $config["meta"]["customer_fields"][$field["name"]] : "";
            $str .= "<tr><td class='paytm_form_field_cell'>" . $field["label"]  . "</td><td class='paytm_form_field_cell'>" . self::get_mapped_field_list($field["name"], $selected_field, $form_fields) . "</td></tr>";
        }
        $str .= "</table>";

        return $str;
    }

    private static function get_customer_fields(){
        return array(
                        array("name" => "first_name" , "label" => "First Name"),
                        array("name" => "last_name" , "label" =>"Last Name"),
                        array("name" => "email" , "label" =>"Email"),
                    array("name" => "phone" , "label" =>"Phone"),
                        array("name" => "amount" , "label" =>"Amount")
                    );
    }

    private static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "paytm_form_customer_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''></option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    private static function get_product_options($form, $selected_field){
        $str = "<option value=''>" . esc_attr__("Select a field", "paytm-gravity-forms") ."</option>";
        $fields = GFCommon::get_fields_by_type($form, array("product"));

        foreach($fields as $field){
            $field_id = $field["id"];
            $field_label = RGFormsModel::get_label($field);

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }

        $selected = $selected_field == 'all' ? "selected='selected'" : "";
        $str .= "<option value='all' " . $selected . ">" . esc_attr__("Form Total", "paytm-gravity-forms") ."</option>";

        return $str;
    }

    private static function get_form_fields($form){
        $fields = array();

        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(isset($field["inputs"]) && is_array($field["inputs"])){

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(!rgar($field, 'displayOnly')){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function return_url($form_id, $lead_id) {
        //$pageURL = GFCommon::is_ssl() ? "https://" : "http://";

        if ($_SERVER["SERVER_PORT"] != "80")
            $pageURL = str_replace( 'https:', 'http:', home_url( '/' ) );
        else
            $pageURL = str_replace( 'https:', 'http:', home_url( '/' ) );

        $ids_query = "ids={$form_id}|{$lead_id}";
        $ids_query .= "&hash=" . wp_hash($ids_query);

        return add_query_arg("gf_paytm_form_return", base64_encode($ids_query), $pageURL);
    }
        
        private static function get_page_url() {
            if ($_SERVER["SERVER_PORT"] != "80")
                $pageURL = str_replace( 'https:', 'http:', home_url( '/' ) );
            else
                $pageURL = str_replace( 'https:', 'http:', home_url( '/' ) );
            return $pageURL;
        }                   

    private static function is_paytm_form_page(){
        $current_page = trim(strtolower(RGForms::get("page")));
        return in_array($current_page, array("gf_paytm_form"));
    }

    private static function is_paytm_payment_gateway($payment_gateway) {
        return in_array($payment_gateway, array('paytmform', 'paytm_form'), true);
    }

    public static function save_callback_response($entry_id, $post_data) {
        $entry_id = absint($entry_id);
        if (!$entry_id || empty($post_data) || !is_array($post_data)) {
            return;
        }

        $sanitized = array();
        foreach ($post_data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            $sanitized[sanitize_key($key)] = sanitize_text_field((string) $value);
        }

        if (!empty($sanitized)) {
            gform_update_meta($entry_id, 'paytm_callback_response', $sanitized);
            gform_update_meta($entry_id, 'paytm_callback_received_at', current_time('mysql'));
        }
    }

    public static function get_callback_response($entry_id) {
        $callback_data = gform_get_meta($entry_id, 'paytm_callback_response');
        return is_array($callback_data) ? $callback_data : array();
    }

    public static function render_paytm_callback_details_table($entry_id) {
        $entry_id = absint($entry_id);
        static $rendered_entries = array();

        if (!$entry_id || !empty($rendered_entries[$entry_id])) {
            return;
        }

        $rendered_entries[$entry_id] = true;
        $callback_data = self::get_callback_response($entry_id);
        if (empty($callback_data)) {
            echo '<p>' . esc_html__('No Paytm callback data received yet.', 'paytm-gravity-forms') . '</p>';
            return;
        }

        $received_at = gform_get_meta($entry_id, 'paytm_callback_received_at');
        ksort($callback_data);
        ?>
        <h4 style="margin:0 0 10px;"><?php esc_html_e('Paytm Callback Response', 'paytm-gravity-forms'); ?></h4>
        <?php if (!empty($received_at)) : ?>
            <p style="margin:0 0 10px;"><strong><?php esc_html_e('Received At', 'paytm-gravity-forms'); ?>:</strong> <?php echo esc_html($received_at); ?></p>
        <?php endif; ?>
        <table class="widefat striped paytm-callback-response-table">
            <thead>
                <tr>
                    <th scope="col"><?php esc_html_e('Field', 'paytm-gravity-forms'); ?></th>
                    <th scope="col"><?php esc_html_e('Value', 'paytm-gravity-forms'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($callback_data as $key => $value) : ?>
                    <tr>
                        <th scope="row"><?php echo esc_html(strtoupper($key)); ?></th>
                        <td style="word-break:break-word;"><?php echo esc_html($value); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public static function admin_display_paytm_callback_details($form, $lead) {
        if (empty($lead['id'])) {
            return;
        }

        $payment_gateway = gform_get_meta($lead['id'], 'payment_gateway');
        if (!self::is_paytm_payment_gateway($payment_gateway)) {
            return;
        }

        echo '<div class="paytm-callback-details-wrap" style="margin:12px 0;">';
        self::render_paytm_callback_details_table($lead['id']);
        echo '</div>';
    }

    public static function admin_edit_payment_status($payment_status, $form_id, $lead)
    {
        //allow the payment status to be edited when for paytm_form, not set to Approved, and not a subscription
        $payment_gateway = gform_get_meta($lead["id"], "payment_gateway");
        require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");
        //get the transaction type out of the feed configuration, do not allow status to be changed when subscription
        $paytm_form_feed_id = gform_get_meta($lead["id"], "paytm_form_feed_id");
        $feed_config = GFPaytmFormData::get_feed($paytm_form_feed_id);
        $transaction_type = rgars($feed_config, "meta/type");
        if (!self::is_paytm_payment_gateway($payment_gateway) || strtolower(rgpost("save")) <> "edit" || $payment_status == "Approved" || $transaction_type == "subscription")
            return $payment_status;

        //create drop down for payment status
        $payment_string = gform_tooltip("paytm_form_edit_payment_status","",true);
        $payment_string .= '<select id="payment_status" name="payment_status">';
        $payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
        $payment_string .= '<option value="Approved">Approved</option>';
        $payment_string .= '</select>';
        return $payment_string;
    }
    public static function admin_edit_payment_status_details($form_id, $lead)
    {
        $payment_gateway = gform_get_meta($lead["id"], "payment_gateway");
        if (!self::is_paytm_payment_gateway($payment_gateway)) {
            return;
        }

        ?>
<div id="paytm_callback_response_details" style="display:block; margin-bottom:20px;">
        <?php self::render_paytm_callback_details_table($lead['id']); ?>
</div>
        <?php

        $form_action = strtolower(rgpost("save"));
        if ($form_action !== "edit") {
            return;
        }

        //get data from entry to pre-populate fields
        $payment_amount = rgar($lead, "payment_amount");
        if (empty($payment_amount))
        {
            $form = RGFormsModel::get_form_meta($form_id);
            $payment_amount = GFCommon::get_order_total($form,$lead);
        }
        $transaction_id = rgar($lead, "transaction_id");
        $payment_date = rgar($lead, "payment_date");
        if (empty($payment_date))
        {
            $payment_date = gmdate("y-m-d H:i:s");
        }

        //display edit fields
        ?>
<div id="edit_payment_status_details" style="display:block">
    <table>
        <tr>
            <td colspan="2"><strong>Payment Information</strong></td>
        </tr>

        <tr>
            <td>Date:<?php gform_tooltip("paytm_form_edit_payment_date") ?></td>
            <td><input type="text" id="payment_date" name="payment_date" value="<?php echo $payment_date?>"></td>
        </tr>
        <tr>
            <td>Amount:<?php gform_tooltip("paytm_form_edit_payment_amount") ?></td>
            <td><input type="text" id="payment_amount" name="payment_amount" value="<?php echo $payment_amount?>"></td>
        </tr>
        <tr>
            <td nowrap>Transaction ID:<?php gform_tooltip("paytm_form_edit_payment_transaction_id") ?></td>
            <td><input type="text" id="paytm_form_transaction_id" name="paytm_form_transaction_id" value="<?php echo $transaction_id?>"></td>
        </tr>
    </table>
</div>
        <?php
    }

    public static function admin_update_payment($form, $lead_id)
    {
        check_admin_referer('gforms_save_entry', 'gforms_save_entry');
        //update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
        //check meta to see if this entry is paytm_form
        $payment_gateway = gform_get_meta($lead_id, "payment_gateway");
        $form_action = strtolower(rgpost("save"));
        if (!self::is_paytm_payment_gateway($payment_gateway) || $form_action <> "update")
            return;
        //get lead
        $lead = RGFormsModel::get_lead($lead_id);
        //get payment fields to update
        $payment_status = rgpost("payment_status");
        //when updating, payment status may not be editable, if no value in post, set to lead payment status
        if (empty($payment_status))
        {
            $payment_status = $lead["payment_status"];
        }

        $payment_amount = rgpost("payment_amount");
        $payment_transaction = rgpost("paytm_form_transaction_id");
        $payment_date = rgpost("payment_date");
        if (empty($payment_date))
        {
            $payment_date = gmdate("y-m-d H:i:s");
        }
        else
        {
            //format date entered by user
            $payment_date = date("Y-m-d H:i:s", strtotime($payment_date));
        }

        global $current_user;
        $user_id = 0;
        $user_name = "System";
        if($current_user && $user_data = get_userdata($current_user->ID)){
            $user_id = $current_user->ID;
            $user_name = $user_data->display_name;
        }

        $lead["payment_status"] = $payment_status;
        $lead["payment_amount"] = $payment_amount;
        $lead["payment_date"] =   $payment_date;
        $lead["transaction_id"] = $payment_transaction;

        // if payment status does not equal approved or the lead has already been fulfilled, do not continue with fulfillment
        if($payment_status == 'Approved' && !$lead["is_fulfilled"])
        {
            //call fulfill order, mark lead as fulfilled
            self::fulfill_order($lead, $payment_transaction, $payment_amount);
            $lead["is_fulfilled"] = true;
        }
        //update lead, add a note
        RGFormsModel::update_lead($lead);
        RGFormsModel::add_note($lead["id"], $user_id, $user_name, sprintf(esc_attr_e("Payment information was manually updated. Status: %s. Amount: %s. Transaction Id: %s. Date: %s", "paytm-gravity-forms"), $lead["payment_status"], GFCommon::to_money($lead["payment_amount"], $lead["currency"]), $payment_transaction, $lead["payment_date"]));
    }

    function set_logging_supported($plugins)
    {
        $plugins[self::$slug] = "Paytm Form";
        return $plugins;
    }

    public static function log_error($message){
        if(class_exists("GFLogging"))
        {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
        }
    }

    public static function log_debug($message){
        if(class_exists("GFLogging"))
        {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
        }
    }
}

if(!function_exists("rgget")){
function rgget($name, $array=null){
    if(!isset($array))
        $array = $_GET;

    if(isset($array[$name]))
        return $array[$name];

    return "";
}
}

if(!function_exists("rgpost")){
function rgpost($name, $do_stripslashes=true){
    if(isset($_POST[$name]))
        return $do_stripslashes ? stripslashes_deep($_POST[$name]) : $_POST[$name];

    return "";
}
}

if(!function_exists("rgar")){
function rgar($array, $name){
    if(isset($array[$name]))
        return $array[$name];

    return '';
}
}

if(!function_exists("rgars")){
function rgars($array, $name){
    $names = explode("/", $name);
    $val = $array;
    foreach($names as $current_name){
        $val = rgar($val, $current_name);
    }
    return $val;
}
}

if(!function_exists("rgempty")){
function rgempty($name, $array = null){
    if(!$array)
        $array = $_POST;

    $val = rgget($name, $array);
    return empty($val);
}
}

if(!function_exists("rgblank")){
function rgblank($text){
    return empty($text) && strval($text) != "0";
}
}

?>
