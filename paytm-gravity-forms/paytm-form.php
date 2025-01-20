<?php
/**
 * Plugin Name: Paytm Gravity Forms Payment
 * Description: Integrates Gravity Forms with Paytm Form, enabling end users to purchase goods and services through Gravity Forms.
 * Version: 2.1.0
 * Author: Paytm
 * Requires at least: 3.5
 * Tags: Paytm, Paytm Payments, PayWithPaytm, Paytm Gravity Forms, Paytm Payment Gateway
 * Tested up to: 6.7.1
 * Requires PHP: 5.6
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
        
        if(! empty($_POST)){
            $str = RGForms::get("gf_paytm_form_return");
            $str = base64_decode($str);
                        
                $settings = get_option("gf_paytm_form_settings");
                $paytm_key = rgar($settings,"paytm_key");
                
                $paytmChecksum = isset($_POST["CHECKSUMHASH"]) ? $_POST["CHECKSUMHASH"] : ""; //Sent by Paytm pg

                unset($_POST['CHECKSUMHASH']);
                $isValidChecksum = PaytmChecksum::verifySignature($_POST, $paytm_key, $paytmChecksum);

                                
                if($isValidChecksum == true){


                            $objGravity = new GFPaytmForm(); 

                            $custom = $_POST['ORDERID'];
                            list($vv,$entry_id) = explode("-", $custom);
                            $entry = RGFormsModel::get_lead($entry_id); 


                            print_r($entry);
                            if(!$entry){
                               $objGravity->log_error("Entry could not be found. Entry ID: {$entry_id}. Aborting.");
                                return;
                            }
                            $objGravity->log_debug("Entry has been found." . print_r($entry, true));
                            $config = $objGravity->get_config_by_entry($entry);
                      if(!$config){
                         $objGravity->log_error("Form no longer is configured with Paytm Form Addon. Form ID: {$entry["form_id"]}. Aborting.");
                        return;
                      }
                            $settings = get_option("gf_paytm_form_settings");
                            
                          // GFPaytmForm::log_debug("Form {$entry["form_id"]} is properly configured.");
                            if($_POST['RESPCODE'] == "01"){
                                $payment_status = "SUCCESS";
                            }else{
                                $payment_status = "FAILED";
                            }
                            
                            $cancel = apply_filters("gform_paytm_form_pre_ipn", false, $_POST, $entry, $config);
                            
                            if(!$cancel) {
                               
               $objGravity->log_debug("Setting payment status...");
               $objGravity->set_payment_status($config, $entry, $payment_status, $_POST['ORDERID'], null, $_POST['TXNAMOUNT'] );
              }
              else{
              $objGravity->log_debug("IPN processing cancelled by the gform_paytm_form_pre_ipn filter. Aborting.");
              }
              //  list($form_id, $lead_id) = explode("|", $query["ids"]);
                            //  add_action('the_content', array('GFSagePayForm', 'paytmShowMessage'));
                
                if($_POST["STATUS"] == "TXN_SUCCESS"){
                    $redirect_url = get_permalink( rgar($settings, 'paytm_return_page'));
                } else {
                    // $redirect_url = get_bloginfo("url") . '/?resp_msg=' . urlencode($_POST['RESPMSG']);
                    $redirect_url = get_permalink( rgar($settings, 'paytm_return_page')) . '/?resp_msg=' . urlencode($_POST['RESPMSG']);
                    
                }
               
                wp_redirect( $redirect_url );
                exit;   
                                
            }else if(isset($_POST['RESPCODE'])){
                $redirect_url = get_bloginfo("url") . '/?resp_msg=' . urlencode("Security error!");
                // $redirect_url = get_permalink( rgar($settings, 'paytm_return_page')). '/?resp_msg=' . urlencode("Security error!");
                wp_redirect( $redirect_url );
              exit; 
            }
        }else{
                             
        


        }
    }
    

}





register_activation_hook( __FILE__, array("GFPaytmForm", "add_permissions"));

if(isset($_GET['resp_msg']) && $_GET['resp_msg']!=''){
    add_action('the_content', 'paytmShowMessage');
}

 function paytmShowMessage($content){
        return '<div class="box '.htmlentities($_GET['type']).'-box">'.htmlentities(urldecode($_GET['resp_msg'])).'</div>'.$content;
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
    private static $version = "2.1.0";
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
            $menus[] = array("name" => "gf_paytm_form", "label" => esc_attr_e("Paytm Form", "paytm-gravity-forms"), "callback" =>  array("GFPaytmForm", "paytm_form_page"), "permission" => $permission);

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
            "paytm_form_installation_id" => "<h6>" . esc_attr_e("Paytm Form Installation ID", "paytm-gravity-forms") . "</h6>" . esc_attr_e("Enter the Paytm Form Installation ID where payment should be received.", "paytm-gravity-forms"),
            "paytm_form_mode" => "<h6>" . esc_attr_e("Mode", "paytm-gravity-forms") . "</h6>" . esc_attr_e("Select Production to receive live payments. Select Test for testing purposes when using the Paytm Form development sandbox.", "paytm-gravity-forms"),
            "paytm_form_transaction_type" => "<h6>" . esc_attr_e("Transaction Type", "paytm-gravity-forms") . "</h6>" . esc_attr_e("Select which Paytm Form transaction type should be used. Products and Services, Donations.", "paytm-gravity-forms"),
            "paytm_form_gravity_form" => "<h6>" . esc_attr_e("Gravity Form", "paytm-gravity-forms") . "</h6>" . esc_attr_e("Select which Gravity Forms you would like to integrate with Paytm Form.", "paytm-gravity-forms"),
            "paytm_form_customer" => "<h6>" . esc_attr_e("Customer", "paytm-gravity-forms") . "</h6>" . esc_attr_e("Map your Form Fields to the available Paytm Form customer information fields.", "paytm-gravity-forms"),
            "paytm_form_cancel_url" => "<h6>" . esc_attr_e("Cancel URL", "paytm-gravity-forms") . "</h6>" . esc_attr_e("Enter the URL the user should be sent to should they cancel before completing their Paytm Form payment.", "paytm-gravity-forms"),
            "paytm_form_options" => "<h6>" . esc_attr_e("Options", "paytm-gravity-forms") . "</h6>" . esc_attr_e("Turn on or off the available Paytm Form checkout options.", "paytm-gravity-forms"),
            "paytm_form_conditional" => "<h6>" . esc_attr_e("Paytm Form Condition", "paytm-gravity-forms") . "</h6>" . esc_attr_e("When the Paytm Form condition is enabled, form submissions will only be sent to Paytm Form when the condition is met. When disabled all form submissions will be sent to Paytm Form.", "paytm-gravity-forms"),
            "paytm_form_edit_payment_amount" => "<h6>" . esc_attr_e("Amount", "paytm-gravity-forms") . "</h6>" . esc_attr_e("Enter the amount the user paid for this transaction.", "paytm-gravity-forms"),
            "paytm_form_edit_payment_date" => "<h6>" . esc_attr_e("Date", "paytm-gravity-forms") . "</h6>" . esc_attr_e("Enter the date of this transaction.", "paytm-gravity-forms"),
            "paytm_form_edit_payment_transaction_id" => "<h6>" . esc_attr_e("Transaction ID", "paytm-gravity-forms") . "</h6>" . esc_attr_e("The transacation id is returned from Paytm Form and uniquely identifies this payment.", "paytm-gravity-forms"),
            "paytm_form_edit_payment_status" => "<h6>" . esc_attr_e("Status", "paytm-gravity-forms") . "</h6>" . esc_attr_e("Set the payment status. This status can only be altered if not currently set to Approved.", "paytm-gravity-forms")
        );
        return array_merge($tooltips, $paytm_form_tooltips);
    }

    public static function delay_post($is_disabled, $form, $lead){
        //loading data class
        require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");

        $config = GFPaytmFormData::get_feed_by_form($form["id"]);
        if(!$config)
            return $is_disabled;

        $config = $config[0];
        if(!self::has_paytm_form_condition($form, $config))
            return $is_disabled;

        return $config["meta"]["delay_post"] == true;
    }

    //Kept for backwards compatibility
    public static function delay_admin_notification($is_disabled, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config)
            return $is_disabled;

        return isset($config["meta"]["delay_notification"]) ? $config["meta"]["delay_notification"] == true : $is_disabled;
    }

    //Kept for backwards compatibility
    public static function delay_autoresponder($is_disabled, $form, $lead){
        $config = self::get_active_config($form);

        if(!$config)
            return $is_disabled;

        return isset($config["meta"]["delay_autoresponder"]) ? $config["meta"]["delay_autoresponder"] == true : $is_disabled;
    }

    public static function delay_notification($is_disabled, $notification, $form, $lead){
        $config = self::get_active_config($form);

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

    public static function paytm_form_page(){
        $view = rgget("view");
        if($view == "edit")
            self::edit_page(rgget("id"));
        else if($view == "stats")
            self::stats_page(rgget("id"));
        else
            self::list_page();
    }

    //Displays the paytm_form feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(esc_attr_e(sprintf("Paytm Form Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "paytm-gravity-forms"));
        }

        if(rgpost('action') == "delete"){
            check_admin_referer("list_action", "gf_paytm_form_list");

            $id = absint($_POST["action_argument"]);
            GFPaytmFormData::delete_feed($id);
            ?>
<div class="updated fade" style="padding:6px"><?php esc_attr_e("Feed deleted.", "paytm-gravity-forms") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_paytm_form_list");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFPaytmFormData::delete_feed($feed_id);
            }
            ?>
<div class="updated fade" style="padding:6px"><?php esc_attr_e("Feeds deleted.", "paytm-gravity-forms") ?></div>
            <?php
        }

        ?>
<div class="wrap">
    <img alt="<?php esc_attr_e("Paytm Form Transactions", "paytm-gravity-forms") ?>" src="<?php echo esc_attr(GF_PAYTM_FORM_BASE_URL)?>/images/paytm_form_wordpress_icon_32.jpg" style="float:left; margin:15px 7px 0 0;"/>
    <h2><?php
            esc_attr_e("Paytm Form List", "paytm-gravity-forms");
                    ?>
    </h2>

    <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_paytm_form_list') ?>
        <input type="hidden" id="action" name="action"/>
        <input type="hidden" id="action_argument" name="action_argument"/>

        <div class="tablenav">
            <div class="alignleft actions" style="padding:8px 0 7px 0;">
                <label class="hidden" for="bulk_action"><?php esc_attr_e("Bulk action", "paytm-gravity-forms") ?></label>
                <select name="bulk_action" id="bulk_action">
                    <option value=''> <?php esc_attr_e("Bulk action", "paytm-gravity-forms") ?> </option>
                    <option value='delete'><?php esc_attr_e("Delete", "paytm-gravity-forms") ?></option>
                </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . esc_attr_e("Apply", "paytm-gravity-forms") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . esc_attr_e("Delete selected feeds? ", "paytm-gravity-forms") . esc_attr_e("\'Cancel\' to stop, \'OK\' to delete.", "paytm-gravity-forms") .'\')) { return false; } return true;"/>';
                        ?>
                <a style="margin-top: 3px;" class="button add-new-h2" href="admin.php?page=gf_paytm_form&view=edit&id=0"><?php esc_attr_e("Add New", "paytm-gravity-forms") ?></a>
            </div>
        </div>
        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                    <th scope="col" id="active" class="manage-column check-column"></th>
                    <th scope="col" class="manage-column"><?php esc_attr_e("Form", "paytm-gravity-forms") ?></th>
                    <th scope="col" class="manage-column"><?php esc_attr_e("Transaction Type", "paytm-gravity-forms") ?></th>
                </tr>
            </thead>

            <tfoot>
                <tr>
                    <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                    <th scope="col" id="active" class="manage-column check-column"></th>
                    <th scope="col" class="manage-column"><?php esc_attr_e("Form", "paytm-gravity-forms") ?></th>
                    <th scope="col" class="manage-column"><?php esc_attr_e("Transaction Type", "paytm-gravity-forms") ?></th>
                </tr>
            </tfoot>

            <tbody class="list:user user-list">
                        <?php


                        $settings = GFPaytmFormData::get_feeds();
                        $paytm_mid = get_option("gf_paytm_form_settings");
                        $inst_id = rgar($paytm_mid,"paytm_mid");
                        if(empty($inst_id)){
                            ?>
                <tr>
                    <td colspan="3" style="padding:20px;">
                                    <?php echo sprintf(esc_attr_e("To get started, please configure your %sPaytm Form Settings%s.", "paytm-gravity-forms"), '<a href="admin.php?page=gf_settings&addon=Paytm Form">', "</a>"); ?>
                    </td>
                </tr>
                            <?php
                        }
                        else if(is_array($settings) && sizeof($settings) > 0){
                            foreach($settings as $setting){
                                ?>
                <tr class='author-self status-inherit' valign="top">
                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                    <td><img src="<?php echo esc_attr(GF_PAYTM_FORM_BASE_URL) ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? esc_attr_e("Active", "paytm-gravity-forms") : esc_attr_e("Inactive", "paytm-gravity-forms");?>" title="<?php echo $setting["is_active"] ? esc_attr_e("Active", "paytm-gravity-forms") : esc_attr_e("Inactive", "paytm-gravity-forms");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                    <td class="column-title">
                        <a href="admin.php?page=gf_paytm_form&view=edit&id=<?php echo $setting["id"] ?>" title="<?php esc_attr_e("Edit", "paytm-gravity-forms") ?>"><?php echo $setting["form_title"] ?></a>
                        <div class="row-actions">
                            <span class="edit">
                                <a title="<?php esc_attr_e("Edit", "paytm-gravity-forms")?>" href="admin.php?page=gf_paytm_form&view=edit&id=<?php echo $setting["id"] ?>" ><?php esc_attr_e("Edit", "paytm-gravity-forms") ?></a>
                                |
                            </span>
                            <span class="view">
                                <a title="<?php esc_attr_e("View Stats", "paytm-gravity-forms")?>" href="admin.php?page=gf_paytm_form&view=stats&id=<?php echo $setting["id"] ?>"><?php esc_attr_e("Stats", "paytm-gravity-forms") ?></a>
                                |
                            </span>
                            <span class="view">
                                <a title="<?php esc_attr_e("View Entries", "paytm-gravity-forms")?>" href="admin.php?page=gf_entries&view=entries&id=<?php echo $setting["form_id"] ?>"><?php esc_attr_e("Entries", "paytm-gravity-forms") ?></a>
                                |
                            </span>
                            <span class="trash">
                                <a title="<?php esc_attr_e("Delete", "paytm-gravity-forms") ?>" href="javascript: if(confirm('<?php esc_attr_e("Delete this feed? ", "paytm-gravity-forms") ?> <?php esc_attr_e("\'Cancel\' to stop, \'OK\' to delete.", "paytm-gravity-forms") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php esc_attr_e("Delete", "paytm-gravity-forms")?></a>
                            </span>
                        </div>
                    </td>
                    <td class="column-date">
                                        <?php
                                            switch($setting["meta"]["type"]){
                                                case "product" :
                                                    esc_attr_e("Product and Services", "paytm-gravity-forms");
                                                break;

                                                case "donation" :
                                                    esc_attr_e("Donation", "paytm-gravity-forms");
                                                break;

                                            }
                                        ?>
                    </td>
                </tr>
                                <?php
                            }
                        }
                        else{
                            ?>
                <tr>
                    <td colspan="4" style="padding:20px;">
                                    <?php echo sprintf(esc_attr_e("You don't have any Paytm Form feeds configured. Let's go %screate one%s!", "paytm-gravity-forms"), '<a href="admin.php?page=gf_paytm_form&view=edit&id=0">', "</a>"); ?>
                    </td>
                </tr>
                            <?php
                        }
                        ?>
            </tbody>
        </table>
    </form>
</div>
<script type="text/javascript">
    function DeleteSetting(id){
        jQuery("#action_argument").val(id);
        jQuery("#action").val("delete");
        jQuery("#feed_form")[0].submit();
    }
    function ToggleActive(img, feed_id){
        var is_active = img.src.indexOf("active1.png") >=0
        if(is_active){
            img.src = img.src.replace("active1.png", "active0.png");
            jQuery(img).attr('title','<?php esc_attr_e("Inactive", "paytm-gravity-forms") ?>').attr('alt', '<?php esc_attr_e("Inactive", "paytm-gravity-forms") ?>');
        }
        else{
            img.src = img.src.replace("active0.png", "active1.png");
            jQuery(img).attr('title','<?php esc_attr_e("Active", "paytm-gravity-forms") ?>').attr('alt', '<?php esc_attr_e("Active", "paytm-gravity-forms") ?>');
        }

        var mysack = new sack(ajaxurl);
        mysack.execute = 1;
        mysack.method = 'POST';
        mysack.setVar( "action", "gf_paytm_form_update_feed_active" );
        mysack.setVar( "gf_paytm_form_update_feed_active", "<?php echo wp_create_nonce("gf_paytm_form_update_feed_active") ?>" );
        mysack.setVar( "feed_id", feed_id );
        mysack.setVar( "is_active", is_active ? 0 : 1 );
        mysack.onError = function() { alert('<?php esc_attr_e("Ajax error while updating feed", "paytm-gravity-forms" ) ?>' )};
        mysack.runAJAX();

        return true;
    }


</script>
        <?php
    }

    public static function load_notifications(){
        $form_id = $_POST["form_id"];
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

<form action="" method="post">
            <?php wp_nonce_field("uninstall", "gf_paytm_form_uninstall") ?>
            <?php if(GFCommon::current_user_can_any("paytm-gravity-forms_uninstall")){ ?>
    <div class="hr-divider"></div>

    <h3><?php esc_attr_e("Uninstall Paytm Form Add-On", "paytm-gravity-forms") ?></h3>
    <div class="delete-alert"><?php esc_attr_e("Warning! This operation deletes ALL Paytm Form Feeds.", "paytm-gravity-forms") ?>
                    <?php
                    $uninstall_button = '<input type="submit" name="uninstall" value="' . esc_attr_e("Uninstall Paytm Form Add-On", "paytm-gravity-forms") . '" class="button" onclick="return confirm(\'' . esc_attr_e("Warning! ALL Paytm Form Feeds will be deleted. This cannot be undone. \'OK\' to delete, \'Cancel\' to stop", "paytm-gravity-forms") . '\');"/>';
                    echo apply_filters("gform_paytm_form_uninstall_button", $uninstall_button);
                    ?>
    </div>
            <?php } ?>
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

    private static function stats_page(){
        ?>
<style>
    .paytm_form_graph_container{clear:both; padding-left:5px; min-width:789px; margin-right:50px;}
    .paytm_form_message_container{clear: both; padding-left:5px; text-align:center; padding-top:120px; border: 1px solid #CCC; background-color: #FFF; width:100%; height:160px;}
    .paytm_form_summary_container {margin:30px 60px; text-align: center; min-width:740px; margin-left:50px;}
    .paytm_form_summary_item {width:160px; background-color: #FFF; border: 1px solid #CCC; padding:14px 8px; margin:6px 3px 6px 0; display: -moz-inline-stack; display: inline-block; zoom: 1; *display: inline; text-align:center;}
    .paytm_form_summary_value {font-size:20px; margin:5px 0; font-family:Georgia,"Times New Roman","Bitstream Charter",Times,serif}
    .paytm_form_summary_title {}
    #paytm_form_graph_tooltip {border:4px solid #b9b9b9; padding:11px 0 0 0; background-color: #f4f4f4; text-align:center; -moz-border-radius: 4px; -webkit-border-radius: 4px; border-radius: 4px; -khtml-border-radius: 4px;}
    #paytm_form_graph_tooltip .tooltip_tip {width:14px; height:14px; background-image:url(<?php echo esc_attr(GF_PAYTM_FORM_BASE_URL) ?>/images/tooltip_tip.png); background-repeat: no-repeat; position: absolute; bottom:-14px; left:68px;}

    .paytm_form_tooltip_date {line-height:130%; font-weight:bold; font-size:13px; color:#21759B;}
    .paytm_form_tooltip_sales {line-height:130%;}
    .paytm_form_tooltip_revenue {line-height:130%;}
    .paytm_form_tooltip_revenue .paytm_form_tooltip_heading {}
    .paytm_form_tooltip_revenue .paytm_form_tooltip_value {}
    .paytm_form_trial_disclaimer {clear:both; padding-top:20px; font-size:10px;}
</style>
<script type="text/javascript" src="<?php echo esc_attr(GF_PAYTM_FORM_BASE_URL) ?>/flot/jquery.flot.min.js"></script>
<script type="text/javascript" src="<?php echo esc_attr(GF_PAYTM_FORM_BASE_URL) ?>/js/currency.js"></script>

<div class="wrap">
    <img alt="<?php esc_attr_e("Paytm Form", "paytm-gravity-forms") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo esc_attr(GF_PAYTM_FORM_BASE_URL) ?>/images/paytm_form_wordpress_icon_32.jpg"/>
    <h2><?php esc_attr_e("Paytm Form Stats", "paytm-gravity-forms") ?></h2>

    <form method="post" action="">
        <ul class="subsubsub">
            <li><a class="<?php echo (!RGForms::get("tab") || RGForms::get("tab") == "daily") ? "current" : "" ?>" href="?page=gf_paytm_form&view=stats&id=<?php echo $_GET["id"] ?>"><?php esc_attr_e("Daily", "paytm-gravity-forms"); ?></a> | </li>
            <li><a class="<?php echo RGForms::get("tab") == "weekly" ? "current" : ""?>" href="?page=gf_paytm_form&view=stats&id=<?php echo $_GET["id"] ?>&tab=weekly"><?php esc_attr_e("Weekly", "paytm-gravity-forms"); ?></a> | </li>
            <li><a class="<?php echo RGForms::get("tab") == "monthly" ? "current" : ""?>" href="?page=gf_paytm_form&view=stats&id=<?php echo $_GET["id"] ?>&tab=monthly"><?php esc_attr_e("Monthly", "paytm-gravity-forms"); ?></a></li>
        </ul>
                <?php
                $config = GFPaytmFormData::get_feed(RGForms::get("id"));

                switch(RGForms::get("tab")){
                    case "monthly" :
                        $chart_info = self::monthly_chart_info($config);
                    break;

                    case "weekly" :
                        $chart_info = self::weekly_chart_info($config);
                    break;

                    default :
                        $chart_info = self::daily_chart_info($config);
                    break;
                }

                if(!$chart_info["series"]){
                    ?>
        <div class="paytm_form_message_container"><?php esc_attr_e("No payments have been made yet.", "paytm-gravity-forms") ?> <?php echo $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"]) ? " **" : ""?></div>
                    <?php
                }
                else{
                    ?>
        <div class="paytm_form_graph_container">
            <div id="graph_placeholder" style="width:100%;height:300px;"></div>
        </div>

        <script type="text/javascript">
            var paytm_form_graph_tooltips = <?php echo $chart_info["tooltips"] ?>;

            jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
            jQuery(window).resize(function(){
                jQuery.plot(jQuery("#graph_placeholder"), <?php echo $chart_info["series"] ?>, <?php echo $chart_info["options"] ?>);
            });

            var previousPoint = null;
            jQuery("#graph_placeholder").bind("plothover", function (event, pos, item) {
                startShowTooltip(item);
            });

            jQuery("#graph_placeholder").bind("plotclick", function (event, pos, item) {
                startShowTooltip(item);
            });

            function startShowTooltip(item){
                if (item) {
                    if (!previousPoint || previousPoint[0] != item.datapoint[0]) {
                        previousPoint = item.datapoint;

                        jQuery("#paytm_form_graph_tooltip").remove();
                        var x = item.datapoint[0].toFixed(2),
                            y = item.datapoint[1].toFixed(2);

                        showTooltip(item.pageX, item.pageY, paytm_form_graph_tooltips[item.dataIndex]);
                    }
                }
                else {
                    jQuery("#paytm_form_graph_tooltip").remove();
                    previousPoint = null;
                }
            }

            function showTooltip(x, y, contents) {
                jQuery('<div id="paytm_form_graph_tooltip">' + contents + '<div class="tooltip_tip"></div></div>').css( {
                    position: 'absolute',
                    display: 'none',
                    opacity: 0.90,
                    width:'150px',
                    height:'<?php echo $config["meta"]["type"] == "subscription" ? "75px" : "60px" ;?>',
                    top: y - <?php echo $config["meta"]["type"] == "subscription" ? "100" : "89" ;?>,
                    left: x - 79
                }).appendTo("body").fadeIn(200);
            }


            function convertToMoney(number){
                var currency = getCurrentCurrency();
                return currency.toMoney(number);
            }
            function formatWeeks(number){
                number = number + "";
                return "<?php esc_attr_e("Week ", "paytm-gravity-forms") ?>" + number.substring(number.length-2);
            }

            function getCurrentCurrency(){
                <?php
                if(!class_exists("RGCurrency"))
                    require_once(ABSPATH . "/" . PLUGINDIR . "/gravityforms/currency.php");

                $current_currency = RGCurrency::get_currency(GFCommon::get_currency());
                ?>
                var currency = new Currency(<?php echo GFCommon::json_encode($current_currency)?>);
                return currency;
            }
        </script>
                <?php
                }
                $payment_totals = RGFormsModel::get_form_payment_totals($config["form_id"]);
                $transaction_totals = GFPaytmFormData::get_transaction_totals($config["form_id"]);

                switch($config["meta"]["type"]){
                    case "product" :
                        $total_sales = $payment_totals["orders"];
                        $sales_label = esc_attr_e("Total Orders", "paytm-gravity-forms");
                    break;

                    case "donation" :
                        $total_sales = $payment_totals["orders"];
                        $sales_label = esc_attr_e("Total Donations", "paytm-gravity-forms");
                    break;
                }

                $total_revenue = empty($transaction_totals["payment"]["revenue"]) ? 0 : $transaction_totals["payment"]["revenue"];
                ?>
        <div class="paytm_form_summary_container">
            <div class="paytm_form_summary_item">
                <div class="paytm_form_summary_title"><?php esc_attr_e("Total Revenue", "paytm-gravity-forms")?></div>
                <div class="paytm_form_summary_value"><?php echo GFCommon::to_money($total_revenue) ?></div>
            </div>
            <div class="paytm_form_summary_item">
                <div class="paytm_form_summary_title"><?php echo $chart_info["revenue_label"]?></div>
                <div class="paytm_form_summary_value"><?php echo $chart_info["revenue"] ?></div>
            </div>
            <div class="paytm_form_summary_item">
                <div class="paytm_form_summary_title"><?php echo $sales_label?></div>
                <div class="paytm_form_summary_value"><?php echo $total_sales ?></div>
            </div>
            <div class="paytm_form_summary_item">
                <div class="paytm_form_summary_title"><?php echo $chart_info["sales_label"] ?></div>
                <div class="paytm_form_summary_value"><?php echo $chart_info["sales"] ?></div>
            </div>
        </div>
                <?php
                if(!$chart_info["series"] && $config["meta"]["trial_period_enabled"] && empty($config["meta"]["trial_amount"])){
                    ?>
        <div class="paytm_form_trial_disclaimer"><?php esc_attr_e("** Free trial transactions will only be reflected in the graph after the first payment is made (i.e. after trial period ends)", "paytm-gravity-forms") ?></div>
                    <?php
                }
                ?>
    </form>
</div>
        <?php
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

        $results = $wpdb->get_results("SELECT CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "') as date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                        FROM {$wpdb->prefix}rg_lead l
                                        INNER JOIN {$wpdb->prefix}rg_paytm_form_transaction t ON l.id = t.entry_id
                                        WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                        GROUP BY date(date)
                                        ORDER BY payment_date desc
                                        LIMIT 30");

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

            $results = $wpdb->get_results("SELECT yearweek(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "')) week_number, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_paytm_form_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            GROUP BY week_number
                                            ORDER BY week_number desc
                                            LIMIT 30");
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

            $results = $wpdb->get_results("SELECT date_format(CONVERT_TZ(t.date_created, '+00:00', '" . $tz_offset . "'), '%Y-%m-02') date, sum(t.amount) as amount_sold, sum(is_renewal) as renewals, sum(is_renewal=0) as new_sales
                                            FROM {$wpdb->prefix}rg_lead l
                                            INNER JOIN {$wpdb->prefix}rg_paytm_form_transaction t ON l.id = t.entry_id
                                            WHERE form_id={$config["form_id"]} AND t.transaction_type='payment'
                                            group by date
                                            order by date desc
                                            LIMIT 30");

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
    private static function edit_page(){
        ?>
<style>
    #paytm_form_submit_container{clear:both;}
    .paytm_form_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold; width:120px;}
    .paytm_form_field_cell {padding: 6px 17px 0 0; margin-right:15px;}

    .paytm_form_validation_error{ background-color:#FFDFDF; margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border:1px dotted #C89797;}
    .paytm_form_validation_error span {color: red;}
    .left_header{float:left; width:200px;}
    .margin_vertical_10{margin: 10px 0; padding-left:5px;}
    .margin_vertical_30{margin: 30px 0; padding-left:5px;}
    .width-1{width:300px;}
    .gf_paytm_form_invalid_form{margin-top:30px; background-color:#FFEBE8;border:1px solid #CC0000; padding:10px; width:600px;}
</style>
<script type="text/javascript">
    var form = Array();
    function ToggleNotifications(){

        var container = jQuery("#gf_paytm_form_notification_container");
        var isChecked = jQuery("#gf_paytm_form_delay_notifications").is(":checked");

        if(isChecked){
            container.slideDown();
            var isLoaded = jQuery(".gf_paytm_form_notification").length > 0
            if(!isLoaded){
                container.html("<li><img src='<?php echo esc_attr(GF_PAYTM_FORM_BASE_URL) ?>/images/loading.gif' title='<?php esc_attr_e("Please wait...", "paytm-gravity-forms"); ?>'></li>");
                jQuery.post(ajaxurl, {
                    action: "gf_paytm_form_load_notifications",
                    form_id: form["id"],
                    },
                    function(response){

                        var notifications = jQuery.parseJSON(response);
                        if(!notifications){
                            container.html("<li><div class='error' padding='20px;'><?php esc_attr_e("Notifications could not be loaded. Please try again later or contact support", "paytm-gravity-forms") ?></div></li>");
                        }
                        else if(notifications.length == 0){
                            container.html("<li><div class='error' padding='20px;'><?php esc_attr_e("The form selected does not have any notifications.", "paytm-gravity-forms") ?></div></li>");
                        }
                        else{
                            var str = "";
                            for(var i=0; i<notifications.length; i++){
                                str += "<li class='gf_paytm_form_notification'>"
                                    +       "<input type='checkbox' value='" + notifications[i]["id"] + "' name='gf_paytm_form_selected_notifications[]' id='gf_paytm_form_selected_notifications' checked='checked' /> "
                                    +       "<label class='inline' for='gf_paytm_form_selected_notifications'>" + notifications[i]["name"] + "</label>";
                                    +  "</li>";
                            }
                            container.html(str);
                        }
                    }
                );
            }
            jQuery(".gf_paytm_form_notification input").prop("checked", true);
        }
        else{
            container.slideUp();
            jQuery(".gf_paytm_form_notification input").prop("checked", false);
        }
    }
</script>
<div class="wrap">
    <img alt="<?php esc_attr_e("Paytm Form", "paytm-gravity-forms") ?>" style="margin: 15px 7px 0pt 0pt; float: left;" src="<?php echo esc_attr(GF_PAYTM_FORM_BASE_URL) ?>/images/paytm_form_wordpress_icon_32.jpg"/>
    <h2><?php esc_attr_e("Paytm Form Transaction Settings", "paytm-gravity-forms") ?></h2>

        <?php

        //getting setting id (0 when creating a new one)
        $id = !empty($_POST["paytm_form_setting_id"]) ? $_POST["paytm_form_setting_id"] : absint($_GET["id"]);
        $config = empty($id) ? array("meta" => array(), "is_active" => true) : GFPaytmFormData::get_feed($id);
        $is_validation_error = false;
        
        $config["form_id"] = rgpost("gf_paytm_form_submit") ? absint(rgpost("gf_paytm_form_form")) : $config["form_id"];

        $form = isset($config["form_id"]) && $config["form_id"] ? $form = RGFormsModel::get_form_meta($config["form_id"]) : array();

        //updating meta information
        if(rgpost("gf_paytm_form_submit")){
            
            $config["meta"]["type"] = rgpost("gf_paytm_form_type");
            $config["meta"]["cancel_url"] = rgpost("gf_paytm_form_cancel_url");
            $config["meta"]["delay_post"] = rgpost('gf_paytm_form_delay_post');
            $config["meta"]["update_post_action"] = rgpost('gf_paytm_form_update_action');

            if(isset($form["notifications"])){
                //new notification settings
                $config["meta"]["delay_notifications"] = rgpost('gf_paytm_form_delay_notifications');
                $config["meta"]["selected_notifications"] = $config["meta"]["delay_notifications"] ? rgpost('gf_paytm_form_selected_notifications') : array();

                if(isset($config["meta"]["delay_autoresponder"]))
                    unset($config["meta"]["delay_autoresponder"]);
                if(isset($config["meta"]["delay_notification"]))
                    unset($config["meta"]["delay_notification"]);
            }

            // paytm_form conditional
            $config["meta"]["paytm_form_conditional_enabled"] = rgpost('gf_paytm_form_conditional_enabled');
            $config["meta"]["paytm_form_conditional_field_id"] = rgpost('gf_paytm_form_conditional_field_id');
            $config["meta"]["paytm_form_conditional_operator"] = rgpost('gf_paytm_form_conditional_operator');
            $config["meta"]["paytm_form_conditional_value"] = rgpost('gf_paytm_form_conditional_value');

            //-----------------

            $customer_fields = self::get_customer_fields();
            $config["meta"]["customer_fields"] = array();
            foreach($customer_fields as $field){
                $config["meta"]["customer_fields"][$field["name"]] = $_POST["paytm_form_customer_field_{$field["name"]}"];
            }

            $config = apply_filters('gform_paytm_form_save_config', $config);

            $is_validation_error = apply_filters("gform_paytm_form_config_validation", false, $config);

            if(!$is_validation_error){
                $id = GFPaytmFormData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
    <div class="updated fade" style="padding:6px"><?php echo sprintf(esc_attr_e("Feed Updated. %sback to list%s", "paytm-gravity-forms"), "<a href='?page=gf_paytm_form'>", "</a>") ?></div>
                <?php
            }
            else{
                $is_validation_error = true;
            }

        }

        ?>
    <form method="post" action="">
        <input type="hidden" name="paytm_form_setting_id" value="<?php echo $id ?>" />

        <div class="margin_vertical_10 <?php echo $is_validation_error ? "paytm_form_validation_error" : "" ?>">
                <?php
                if($is_validation_error){
                    ?>
            <span><?php esc_attr_e('There was an issue saving your feed. Please address the errors below and try again.'); ?></span>
                    <?php
                }
                ?>
        </div> <!-- / validation message -->
        <div class="margin_vertical_10">
            <label class="left_header" for="gf_paytm_form_type"><?php esc_attr_e("Transaction Type", "paytm-gravity-forms"); ?> <?php gform_tooltip("paytm_form_transaction_type") ?></label>

            <select id="gf_paytm_form_type" name="gf_paytm_form_type" onchange="SelectType(jQuery(this).val());">
                <option value=""><?php esc_attr_e("Select a transaction type", "paytm-gravity-forms") ?></option>
                <option value="donation" <?php echo rgar($config['meta'], 'type') == "donation" ? "selected='selected'" : "" ?>><?php esc_attr_e("Donations", "paytm-gravity-forms") ?></option>
            </select>
        </div>
        <div id="paytm_form_form_container" valign="top" class="margin_vertical_10" <?php echo empty($config["meta"]["type"]) ? "style='display:none;'" : "" ?>>
            <label for="gf_paytm_form_form" class="left_header"><?php esc_attr_e("Gravity Form", "paytm-gravity-forms"); ?> <?php gform_tooltip("paytm_form_gravity_form") ?></label>

            <select id="gf_paytm_form_form" name="gf_paytm_form_form" onchange="SelectForm(jQuery('#gf_paytm_form_type').val(), jQuery(this).val(), '<?php echo rgar($config, 'id') ?>');">
                <option value=""><?php esc_attr_e("Select a form", "paytm-gravity-forms"); ?> </option>
                    <?php

                    $active_form = rgar($config, 'form_id');
                    $available_forms = GFPaytmFormData::get_available_forms($active_form);

                    foreach($available_forms as $current_form) {
                        $selected = absint($current_form->id) == rgar($config, 'form_id') ? 'selected="selected"' : '';
                        ?>

                <option value="<?php echo absint($current_form->id) ?>" <?php echo $selected; ?>><?php echo esc_html($current_form->title) ?></option>

                        <?php
                    }
                    ?>
            </select>
            &nbsp;&nbsp;
            <img src="<?php echo esc_attr(GF_PAYTM_FORM_BASE_URL) ?>/images/loading.gif" id="paytm_form_wait" style="display: none;"/>

            <div id="gf_paytm_form_invalid_product_form" class="gf_paytm_form_invalid_form"  style="display:none;">
                    <?php esc_attr_e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "paytm-gravity-forms") ?>
            </div>
            <div id="gf_paytm_form_invalid_donation_form" class="gf_paytm_form_invalid_form" style="display:none;">
                    <?php esc_attr_e("The form selected does not have any Product fields. Please add a Product field to the form and try again.", "paytm-gravity-forms") ?>
            </div>
        </div>
        <div id="paytm_form_field_group" valign="top" <?php echo empty($config["meta"]["type"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>

            <div class="margin_vertical_10">
                <label class="left_header"><?php esc_attr_e("Customer", "paytm-gravity-forms"); ?> <?php gform_tooltip("paytm_form_customer") ?></label>

                <div id="paytm_form_customer_fields">
                        <?php
                            if(!empty($form))
                                echo self::get_customer_information($form, $config);
                        ?>
                </div>
            </div>

            <div class="margin_vertical_10">
                <label class="left_header" for="gf_paytm_form_cancel_url"><?php esc_attr_e("Cancel URL", "paytm-gravity-forms"); ?> <?php gform_tooltip("paytm_form_cancel_url") ?></label>
                <input type="text" name="gf_paytm_form_cancel_url" id="gf_paytm_form_cancel_url" class="width-1" value="<?php echo rgars($config, "meta/cancel_url") ?>"/>
            </div>

            <div class="margin_vertical_10">
                <ul style="overflow:hidden;">

                    <li id="paytm_form_delay_notification" <?php echo isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                        <input type="checkbox" name="gf_paytm_form_delay_notification" id="gf_paytm_form_delay_notification" value="1" <?php echo rgar($config["meta"], 'delay_notification') ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_paytm_form_delay_notification"><?php esc_attr_e("Send admin notification only when payment is received.", "paytm-gravity-forms"); ?> <?php gform_tooltip("paytm_form_delay_admin_notification") ?></label>
                    </li>
                    <li id="paytm_form_delay_autoresponder" <?php echo isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                        <input type="checkbox" name="gf_paytm_form_delay_autoresponder" id="gf_paytm_form_delay_autoresponder" value="1" <?php echo rgar($config["meta"], 'delay_autoresponder') ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_paytm_form_delay_autoresponder"><?php esc_attr_e("Send user notification only when payment is received.", "paytm-gravity-forms"); ?> <?php gform_tooltip("paytm_form_delay_user_notification") ?></label>
                    </li>

                        <?php
                        $display_post_fields = !empty($form) ? GFCommon::has_post_field($form["fields"]) : false;
                        ?>
                    <li id="paytm_form_post_action" <?php echo $display_post_fields ? "" : "style='display:none;'" ?>>
                        <input type="checkbox" name="gf_paytm_form_delay_post" id="gf_paytm_form_delay_post" value="1" <?php echo rgar($config["meta"],"delay_post") ? "checked='checked'" : ""?> />
                        <label class="inline" for="gf_paytm_form_delay_post"><?php esc_attr_e("Create post only when payment is received.", "paytm-gravity-forms"); ?> <?php gform_tooltip("paytm_form_delay_post") ?></label>
                    </li>

                    <li id="paytm_form_post_update_action" <?php echo $display_post_fields && $config["meta"]["type"] == "subscription" ? "" : "style='display:none;'" ?>>
                        <input type="checkbox" name="gf_paytm_form_update_post" id="gf_paytm_form_update_post" value="1" <?php echo rgar($config["meta"],"update_post_action") ? "checked='checked'" : ""?> onclick="var action = this.checked ? 'draft' : ''; jQuery('#gf_paytm_form_update_action').val(action);" />
                        <label class="inline" for="gf_paytm_form_update_post"><?php esc_attr_e("Update Post when subscription is cancelled.", "paytm-gravity-forms"); ?> <?php gform_tooltip("paytm_form_update_post") ?></label>
                        <select id="gf_paytm_form_update_action" name="gf_paytm_form_update_action" onchange="var checked = jQuery(this).val() ? 'checked' : false; jQuery('#gf_paytm_form_update_post').attr('checked', checked);">
                            <option value=""></option>
                            <option value="draft" <?php echo rgar($config["meta"],"update_post_action") == "draft" ? "selected='selected'" : ""?>><?php esc_attr_e("Mark Post as Draft", "paytm-gravity-forms") ?></option>
                            <option value="delete" <?php echo rgar($config["meta"],"update_post_action") == "delete" ? "selected='selected'" : ""?>><?php esc_attr_e("Delete Post", "paytm-gravity-forms") ?></option>
                        </select>
                    </li>

                        <?php do_action("gform_paytm_form_action_fields", $config, $form) ?>
                </ul>
            </div>

            <div class="margin_vertical_10" id="gf_paytm_form_notifications" <?php echo !isset($form["notifications"]) ? "style='display:none;'" : "" ?>>
                <label class="left_header"><?php esc_attr_e("Notifications", "paytm-gravity-forms"); ?> <?php gform_tooltip("paytm_form_notifications") ?></label>
                    <?php
                    $has_delayed_notifications = rgar($config['meta'], 'delay_notifications') || rgar($config['meta'], 'delay_notification') || rgar($config['meta'], 'delay_autoresponder');
                    ?>
                <div style="overflow:hidden;">
                    <input type="checkbox" name="gf_paytm_form_delay_notifications" id="gf_paytm_form_delay_notifications" value="1" onclick="ToggleNotifications();" <?php checked("1", $has_delayed_notifications)?> />
                    <label class="inline" for="gf_paytm_form_delay_notifications"><?php esc_attr_e("Send notifications only when payment is received.", "paytm-gravity-forms"); ?></label>

                    <ul id="gf_paytm_form_notification_container" style="padding-left:20px; <?php echo $has_delayed_notifications ? "" : "display:none;"?>">
                        <?php
                        if(!empty($form) && is_array($form["notifications"])){
                            $selected_notifications = self::get_selected_notifications($config, $form);

                            foreach($form["notifications"] as $notification){
                                ?>
                        <li class="gf_paytm_form_notification">
                            <input type="checkbox" name="gf_paytm_form_selected_notifications[]" id="gf_paytm_form_selected_notifications" value="<?php echo $notification["id"]?>" <?php checked(true, in_array($notification["id"], $selected_notifications))?> />
                            <label class="inline" for="gf_paytm_form_selected_notifications"><?php echo $notification["name"]; ?></label>
                        </li>
                                <?php
                            }
                        }
                        ?>
                    </ul>
                </div>
            </div>

                <?php do_action("gform_paytm_form_add_option_group", $config, $form); ?>

            <div id="gf_paytm_form_conditional_section" valign="top" class="margin_vertical_10">
                <label for="gf_paytm_form_conditional_optin" class="left_header"><?php esc_attr_e("Paytm Form Condition", "paytm-gravity-forms"); ?> <?php gform_tooltip("paytm_form_conditional") ?></label>

                <div id="gf_paytm_form_conditional_option">
                    <table cellspacing="0" cellpadding="0">
                        <tr>
                            <td>
                                <input type="checkbox" id="gf_paytm_form_conditional_enabled" name="gf_paytm_form_conditional_enabled" value="1" onclick="if(this.checked){jQuery('#gf_paytm_form_conditional_container').fadeIn('fast');} else{ jQuery('#gf_paytm_form_conditional_container').fadeOut('fast'); }" <?php echo rgar($config['meta'], 'paytm_form_conditional_enabled') ? "checked='checked'" : ""?>/>
                                <label for="gf_paytm_form_conditional_enable"><?php esc_attr_e("Enable", "paytm-gravity-forms"); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <td>
                                <div id="gf_paytm_form_conditional_container" <?php echo !rgar($config['meta'], 'paytm_form_conditional_enabled') ? "style='display:none'" : ""?>>

                                    <div id="gf_paytm_form_conditional_fields" style="display:none">
                                            <?php esc_attr_e("Send to Paytm Form if ", "paytm-gravity-forms") ?>
                                        <select id="gf_paytm_form_conditional_field_id" name="gf_paytm_form_conditional_field_id" class="optin_select" onchange='jQuery("#gf_paytm_form_conditional_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'>
                                        </select>
                                        <select id="gf_paytm_form_conditional_operator" name="gf_paytm_form_conditional_operator">
                                            <option value="is" <?php echo rgar($config['meta'], 'paytm_form_conditional_operator') == "is" ? "selected='selected'" : "" ?>><?php esc_attr_e("is", "paytm-gravity-forms") ?></option>
                                            <option value="isnot" <?php echo rgar($config['meta'], 'paytm_form_conditional_operator') == "isnot" ? "selected='selected'" : "" ?>><?php esc_attr_e("is not", "paytm-gravity-forms") ?></option>
                                            <option value=">" <?php echo rgar($config['meta'], 'paytm_form_conditional_operator') == ">" ? "selected='selected'" : "" ?>><?php esc_attr_e("greater than", "paytm-gravity-forms") ?></option>
                                            <option value="<" <?php echo rgar($config['meta'], 'paytm_form_conditional_operator') == "<" ? "selected='selected'" : "" ?>><?php esc_attr_e("less than", "paytm-gravity-forms") ?></option>
                                            <option value="contains" <?php echo rgar($config['meta'], 'paytm_form_conditional_operator') == "contains" ? "selected='selected'" : "" ?>><?php esc_attr_e("contains", "paytm-gravity-forms") ?></option>
                                            <option value="starts_with" <?php echo rgar($config['meta'], 'paytm_form_conditional_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php esc_attr_e("starts with", "paytm-gravity-forms") ?></option>
                                            <option value="ends_with" <?php echo rgar($config['meta'], 'paytm_form_conditional_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php esc_attr_e("ends with", "paytm-gravity-forms") ?></option>
                                        </select>
                                        <div id="gf_paytm_form_conditional_value_container" name="gf_paytm_form_conditional_value_container" style="display:inline;"></div>
                                    </div>

                                    <div id="gf_paytm_form_conditional_message" style="display:none">
                                            <?php esc_attr_e("To create a registration condition, your form must have a field supported by conditional logic.", "paytm-gravity-forms") ?>
                                    </div>

                                </div>
                            </td>
                        </tr>
                    </table>
                </div>
            </div> <!-- / paytm_form conditional -->

            <div id="paytm_form_submit_container" class="margin_vertical_30">
                <input type="submit" name="gf_paytm_form_submit" value="<?php echo empty($id) ? esc_attr_e("  Save  ", "paytm-gravity-forms") : esc_attr_e("Update", "paytm-gravity-forms"); ?>" class="button-primary"/>
                <input type="button" value="<?php esc_attr_e("Cancel", "paytm-gravity-forms"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_paytm_form'" />
            </div>
        </div>
    </form>
</div>

<script type="text/javascript">
    jQuery(document).ready(function(){
        SetPeriodNumber('#gf_paytm_form_billing_cycle_number', jQuery("#gf_paytm_form_billing_cycle_type").val());
        SetPeriodNumber('#gf_paytm_form_trial_period_number', jQuery("#gf_paytm_form_trial_period_type").val());
    });

    function SelectType(type){
        jQuery("#paytm_form_field_group").slideUp();

        jQuery("#paytm_form_field_group input[type=\"text\"], #paytm_form_field_group select").val("");
        jQuery("#gf_paytm_form_trial_period_type, #gf_paytm_form_billing_cycle_type").val("M");

        jQuery("#paytm_form_field_group input:checked").attr("checked", false);

        if(type){
            jQuery("#paytm_form_form_container").slideDown();
            jQuery("#gf_paytm_form_form").val("");
        }
        else{
            jQuery("#paytm_form_form_container").slideUp();
        }
    }

    function SelectForm(type, formId, settingId){
        if(!formId){
            jQuery("#paytm_form_field_group").slideUp();
            return;
        }

        jQuery("#paytm_form_wait").show();
        jQuery("#paytm_form_field_group").slideUp();

        var mysack = new sack(ajaxurl);
        mysack.execute = 1;
        mysack.method = 'POST';
        mysack.setVar( "action", "gf_select_paytm_form_form" );
        mysack.setVar( "gf_select_paytm_form_form", "<?php echo wp_create_nonce("gf_select_paytm_form_form") ?>" );
        mysack.setVar( "type", type);
        mysack.setVar( "form_id", formId);
        mysack.setVar( "setting_id", settingId);
        mysack.onError = function() {jQuery("#paytm_form_wait").hide(); alert('<?php esc_attr_e("Ajax error while selecting a form", "paytm-gravity-forms") ?>' )};
        mysack.runAJAX();

        return true;
    }

    function EndSelectForm(form_meta, customer_fields, recurring_amount_options){

        //setting global form object
        form = form_meta;

        var type = jQuery("#gf_paytm_form_type").val();

        jQuery(".gf_paytm_form_invalid_form").hide();
        if( (type == "product" || type =="subscription") && GetFieldsByType(["product"]).length == 0){
            jQuery("#gf_paytm_form_invalid_product_form").show();
            jQuery("#paytm_form_wait").hide();
            return;
        }
        else if(type == "donation" && GetFieldsByType(["product", "donation"]).length == 0){
            jQuery("#gf_paytm_form_invalid_donation_form").show();
            jQuery("#paytm_form_wait").hide();
            return;
        }

        jQuery(".paytm_form_field_container").hide();
        jQuery("#paytm_form_customer_fields").html(customer_fields);
        jQuery("#gf_paytm_form_recurring_amount").html(recurring_amount_options);

        //displaying delayed post creation setting if current form has a post field
        var post_fields = GetFieldsByType(["post_title", "post_content", "post_excerpt", "post_category", "post_custom_field", "post_image", "post_tag"]);
        if(post_fields.length > 0){
            jQuery("#paytm_form_post_action").show();
        }
        else{
            jQuery("#gf_paytm_form_delay_post").attr("checked", false);
            jQuery("#paytm_form_post_action").hide();
        }

        if(type == "subscription" && post_fields.length > 0){
            jQuery("#paytm_form_post_update_action").show();
        }
        else{
            jQuery("#gf_paytm_form_update_post").attr("checked", false);
            jQuery("#paytm_form_post_update_action").hide();
        }

        SetPeriodNumber('#gf_paytm_form_billing_cycle_number', jQuery("#gf_paytm_form_billing_cycle_type").val());
        SetPeriodNumber('#gf_paytm_form_trial_period_number', jQuery("#gf_paytm_form_trial_period_type").val());

        //Calling callback functions
        jQuery(document).trigger('paytm_formFormSelected', [form]);

        jQuery("#gf_paytm_form_conditional_enabled").attr('checked', false);
        SetPaytmFormCondition("","");

        if(form["notifications"]){
            jQuery("#gf_paytm_form_notifications").show();
            jQuery("#paytm_form_delay_autoresponder, #paytm_form_delay_notification").hide();
        }
        else{
            jQuery("#paytm_form_delay_autoresponder, #paytm_form_delay_notification").show();
            jQuery("#gf_paytm_form_notifications").hide();
        }

        jQuery("#paytm_form_field_container_" + type).show();
        jQuery("#paytm_form_field_group").slideDown();
        jQuery("#paytm_form_wait").hide();
    }

    function SetPeriodNumber(element, type){
        var prev = jQuery(element).val();

        var min = 1;
        var max = 0;
        switch(type){
            case "D" :
                max = 100;
            break;
            case "W" :
                max = 52;
            break;
            case "M" :
                max = 12;
            break;
            case "Y" :
                max = 5;
            break;
        }
        var str="";
        for(var i=min; i<=max; i++){
            var selected = prev == i ? "selected='selected'" : "";
            str += "<option value='" + i + "' " + selected + ">" + i + "</option>";
        }
        jQuery(element).html(str);
    }

    function GetFieldsByType(types){
        var fields = new Array();
        for(var i=0; i<form["fields"].length; i++){
            if(IndexOf(types, form["fields"][i]["type"]) >= 0)
                fields.push(form["fields"][i]);
        }
        return fields;
    }

    function IndexOf(ary, item){
        for(var i=0; i<ary.length; i++)
            if(ary[i] == item)
                return i;

        return -1;
    }

</script>

<script type="text/javascript">

    // Paytm Form Conditional Functions

    <?php
    if(!empty($config["form_id"])){
        ?>

        // initilize form object
        form = <?php echo GFCommon::json_encode($form)?> ;

        // initializing registration condition drop downs
        jQuery(document).ready(function(){
            var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["paytm_form_conditional_field_id"])?>";
            var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["paytm_form_conditional_value"])?>";
            SetPaytmFormCondition(selectedField, selectedValue);
        });

        <?php
    }
    ?>

    function SetPaytmFormCondition(selectedField, selectedValue){

        // load form fields
        jQuery("#gf_paytm_form_conditional_field_id").html(GetSelectableFields(selectedField, 20));
        var optinConditionField = jQuery("#gf_paytm_form_conditional_field_id").val();
        var checked = jQuery("#gf_paytm_form_conditional_enabled").attr('checked');

        if(optinConditionField){
            jQuery("#gf_paytm_form_conditional_message").hide();
            jQuery("#gf_paytm_form_conditional_fields").show();
            jQuery("#gf_paytm_form_conditional_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
            jQuery("#gf_paytm_form_conditional_value").val(selectedValue);
        }
        else{
            jQuery("#gf_paytm_form_conditional_message").show();
            jQuery("#gf_paytm_form_conditional_fields").hide();
        }

        if(!checked) jQuery("#gf_paytm_form_conditional_container").hide();

    }

    function GetFieldValues(fieldId, selectedValue, labelMaxCharacters){
        if(!fieldId)
            return "";

        var str = "";
        var field = GetFieldById(fieldId);
        if(!field)
            return "";

        var isAnySelected = false;

        if(field["type"] == "post_category" && field["displayAllCategories"]){
            str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "gf_paytm_form_conditional_value", "name"=> "gf_paytm_form_conditional_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
        }
        else if(field.choices){
            str += '<select id="gf_paytm_form_conditional_value" name="gf_paytm_form_conditional_value" class="optin_select">'


            for(var i=0; i<field.choices.length; i++){
                var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
                var isSelected = fieldValue == selectedValue;
                var selected = isSelected ? "selected='selected'" : "";
                if(isSelected)
                    isAnySelected = true;

                str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
            }

            if(!isAnySelected && selectedValue){
                str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
            }
            str += "</select>";
        }
        else
        {
            selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
            //create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
            str += "<input type='text' placeholder='<?php esc_attr_e("Enter value", "paytm-gravity-forms"); ?>' id='gf_paytm_form_conditional_value' name='gf_paytm_form_conditional_value' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
        }

        return str;
    }

    function GetFieldById(fieldId){
        for(var i=0; i<form.fields.length; i++){
            if(form.fields[i].id == fieldId)
                return form.fields[i];
        }
        return null;
    }

    function TruncateMiddle(text, maxCharacters){
        if(!text)
            return "";

        if(text.length <= maxCharacters)
            return text;
        var middle = parseInt(maxCharacters / 2);
        return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
    }

    function GetSelectableFields(selectedFieldId, labelMaxCharacters){
        var str = "";
        var inputType;
        for(var i=0; i<form.fields.length; i++){
            fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
            inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
            if (IsConditionalLogicField(form.fields[i])) {
                var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
            }
        }
        return str;
    }

    function IsConditionalLogicField(field){
        inputType = field.inputType ? field.inputType : field.type;
        var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", "email", "hidden", "number", "phone", "multiselect", "post_title", "post_tags", "post_custom_field", "post_content", "post_excerpt"];

        var index = jQuery.inArray(inputType, supported_fields);

        return index >= 0;
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

        die("EndSelectForm(" . GFCommon::json_encode($form) . ", '" . str_replace("'", "\'", $customer_fields) . "', '" . str_replace("'", "\'", $recurring_amount_fields) . "');");
    }

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "paytm-gravity-forms");
        $wp_roles->add_cap("administrator", "paytm-gravity-forms_uninstall");
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("paytm-gravity-forms", "paytm-gravity-forms_uninstall"));
    }

    public static function get_active_config($form){

        require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");

        $configs = GFPaytmFormData::get_feed_by_form($form["id"], true);
        if(!$configs)
            return false;

        foreach($configs as $config){
            if(self::has_paytm_form_condition($form, $config))
                return $config;
        }

        return false;
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
            $config = GFPaytmFormData::get_feed_by_form($form["id"]);

      if(!$config){
        self::log_debug("NOT sending to Paytm Form: No Paytm Form setup was located for form_id = {$form['id']}.");
        return $confirmation;
            }else{
        $config = $config[0]; //using first sagepayform feed (only one sagepayform feed per form is supported)
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

            $confirmation = '<script type="application/javascript" crossorigin="anonymous" src="'.$checkout_url.'" "></script>
                     
                    <button type="button" class="button btn btn-info"   id="invovkePayment" >  Pay </button>
                    <a class="button cancel btn btn-danger" href="#">Cancel</a>



<div id="paytm-pg-spinner" class="paytm-pg-loader">
  <div class="bounce1"></div>
  <div class="bounce2"></div>
  <div class="bounce3"></div>
  <div class="bounce4"></div>
  <div class="bounce5"></div>
</div>
<div class="paytm-overlay paytm-pg-loader"></div>
<style type="text/css">
.btn-info {
    color: #fff;
    background-color: #5bc0de;
    border-color: #46b8da;
}

.btn-danger {
    color: #fff;
    background-color: #d9534f;
    border-color: #d43f3a;
    text-decoration: none;

}

.btn {
    display: inline-block;
    margin-bottom: 0;
    font-weight: 400;
    text-align: center;
    white-space: nowrap;
    vertical-align: middle;
    -ms-touch-action: manipulation;
    touch-action: manipulation;
    cursor: pointer;
    background-image: none;
    border: 1px solid transparent;
    padding: 6px 12px;
    font-size: 14px;
    line-height: 1.42857143;
    border-radius: 4px;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
}
#paytm-pg-spinner {margin: 0% auto 0;width: 70px;text-align: center;z-index: 999999;position: relative;}

#paytm-pg-spinner > div {width: 10px;height: 10px;background-color: #012b71;border-radius: 100%;display: inline-block;-webkit-animation: sk-bouncedelay 1.4s infinite ease-in-out both;animation: sk-bouncedelay 1.4s infinite ease-in-out both;}

#paytm-pg-spinner .bounce1 {-webkit-animation-delay: -0.64s;animation-delay: -0.64s;}

#paytm-pg-spinner .bounce2 {-webkit-animation-delay: -0.48s;animation-delay: -0.48s;}
#paytm-pg-spinner .bounce3 {-webkit-animation-delay: -0.32s;animation-delay: -0.32s;}

#paytm-pg-spinner .bounce4 {-webkit-animation-delay: -0.16s;animation-delay: -0.16s;}
#paytm-pg-spinner .bounce4, #paytm-pg-spinner .bounce5{background-color: #48baf5;} 
@-webkit-keyframes sk-bouncedelay {0%, 80%, 100% { -webkit-transform: scale(0) }40% { -webkit-transform: scale(1.0) }}

@keyframes sk-bouncedelay { 0%, 80%, 100% { -webkit-transform: scale(0);transform: scale(0); } 40% { 
    -webkit-transform: scale(1.0); transform: scale(1.0);}}
.paytm-overlay{width: 100%;position: fixed;top: 0px;opacity: .4;height: 100%;background: #000;margin-left: -53px;}

</style>
                 
<script>    
               
                 
                  
                var config = {
                    "root": "",
                    "flow": "DEFAULT",
                    "data": {
                      "orderId": "'.$orderid.'", 
                      "token": "'.$data['txnToken'].'", 
                      "tokenType": "TXN_TOKEN",
                      "amount": "'.$paytmParams["body"]['txnAmount']['value'].'"
                    },
                    "integration": {
                      "platform": "Wordpress GF",
                      "version": "'.get_bloginfo( 'version' ).'|2.1.0"
                    },
                    "handler": {
                      "notifyMerchant": function(eventName,data){
                        console.log("notifyMerchant handler function called");
                        if(eventName=="APP_CLOSED")
                        {
                            jQuery(".loading-paytm").hide();
                            jQuery("#paytm-pg-spinner").hide();
                            jQuery(".paytm-overlay").hide();  
                        }
                      } 
                    }
                  };
            
                  if(window.Paytm && window.Paytm.CheckoutJS){
                    
                      window.Paytm.CheckoutJS.onLoad(function excecuteAfterCompleteLoad() {
                        
                          window.Paytm.CheckoutJS.init(config).then(function onSuccess() {
                            
                            window.Paytm.CheckoutJS.invoke();


                            jQuery("#paytm-pg-spinner").hide();
                            jQuery(".paytm-overlay").hide();


                          }).catch(function onError(error){
                              console.log("error => ",error);
                          });
                      });
                  } 
       
jQuery(document).ready(function(){ jQuery("#invovkePayment").on("click",function(){ window.Paytm.CheckoutJS.invoke(); return false; }); });

</script>';
        }
        
RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(esc_attr_e("Payment has been initiated. Amount: %s. Transaction Id: %s", "paytm-gravity-forms"), GFCommon::to_money($paytmParams["body"]['txnAmount']['value'], $entry["currency"]), $orderid));        


return $confirmation;
    }




     public static function set_payment_status($config, $entry, $status, $transaction_id, $parent_transaction_id, $amount){
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
                        $entry["payment_status"] = "Success";
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
            
                
                
                $StatusDetail = $_POST['RESPMSG'];
                
                self::log_debug("Processed a Failed request.");
                if($entry["payment_status"] != "Failed"){
                    if(empty($entry["transaction_type"])){
                        $entry["payment_status"] = "Failed";
                        self::log_debug("Setting entry as Failed.");
                        RGFormsModel::update_lead($entry);
                    }
                    if(!empty($StatusDetail)){
                        RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(esc_attr_e("Payment has Failed. %s Transaction Id: %s", "paytm-gravity-forms"), $StatusDetail, $transaction_id));
                    }else{
                        RGFormsModel::add_note($entry["id"], $user_id, $user_name, sprintf(esc_attr_e("Payment has Failed. Failed payments occur when they are made via your customer's bank account and could not be completed. Transaction Id: %s", "paytm-gravity-forms"), $transaction_id));
                    }
                }

                GFPaytmFormData::insert_transaction($entry["id"], "failed", $transaction_id, $parent_transaction_id, $amount);

            break;

        }
                
        self::log_debug("Before gform_post_payment_status.");
        do_action("gform_post_payment_status", $config, $entry, $status, $transaction_id, $amount);
    }

    

    
    public static function has_paytm_form_condition($form, $config) {

        $config = $config["meta"];

        $operator = isset($config["paytm_form_conditional_operator"]) ? $config["paytm_form_conditional_operator"] : "";
        $field = RGFormsModel::get_field($form, $config["paytm_form_conditional_field_id"]);

        if(empty($field) || !$config["paytm_form_conditional_enabled"])
            return true;

        // if conditional is enabled, but the field is hidden, ignore conditional
        $is_visible = !RGFormsModel::is_field_hidden($form, $field, array());

        $field_value = RGFormsModel::get_field_value($field, array());

        $is_value_match = RGFormsModel::is_value_match($field_value, $config["paytm_form_conditional_value"], $operator);
        $go_to_paytm_form = $is_value_match && $is_visible;

        return  $go_to_paytm_form;
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
        $plugin = GF_SAGEPAY_FORM_PLUGIN;
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
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }

    private static function get_customer_information($form, $config=null){

        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form);

        $str = "<table cellpadding='0' cellspacing='0'><tr><td class='paytm_form_col_heading'>" . esc_attr_e("Paytm Form Fields", "paytm-gravity-forms") . "</td><td class='paytm_form_col_heading'>" . esc_attr_e("Form Fields", "paytm-gravity-forms") . "</td></tr>";
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
        $str = "<option value=''>" . esc_attr_e("Select a field", "paytm-gravity-forms") ."</option>";
        $fields = GFCommon::get_fields_by_type($form, array("product"));

        foreach($fields as $field){
            $field_id = $field["id"];
            $field_label = RGFormsModel::get_label($field);

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }

        $selected = $selected_field == 'all' ? "selected='selected'" : "";
        $str .= "<option value='all' " . $selected . ">" . esc_attr_e("Form Total", "paytm-gravity-forms") ."</option>";

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

    public static function admin_edit_payment_status($payment_status, $form_id, $lead)
    {
        //allow the payment status to be edited when for paytm_form, not set to Approved, and not a subscription
        $payment_gateway = gform_get_meta($lead["id"], "payment_gateway");
        require_once(GF_PAYTM_FORM_BASE_PATH . "/data.php");
        //get the transaction type out of the feed configuration, do not allow status to be changed when subscription
        $paytm_form_feed_id = gform_get_meta($lead["id"], "paytm_form_feed_id");
        $feed_config = GFPaytmFormData::get_feed($paytm_form_feed_id);
        $transaction_type = rgars($feed_config, "meta/type");
        if ($payment_gateway <> "paytm_form" || strtolower(rgpost("save")) <> "edit" || $payment_status == "Approved" || $transaction_type == "subscription")
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
        //check meta to see if this entry is paytm_form
        $payment_gateway = gform_get_meta($lead["id"], "payment_gateway");
        $form_action = strtolower(rgpost("save"));
        if ($payment_gateway <> "paytm_form" || $form_action <> "edit")
            return;

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
        if ($payment_gateway <> "paytm_form" || $form_action <> "update")
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

    private static function log_error($message){
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



/*
* Code to test Curl
*/
if(isset($_GET['paytm_action']) && $_GET['paytm_action'] == "curltest"){
    add_action('the_content', 'curltest');
}

function curltest($content){

    // phpinfo();exit;
    $debug = array();

    if(!function_exists("curl_init")){
        $debug[0]["info"][] = "cURL extension is either not available or disabled. Check phpinfo for more info.";

    // if curl is enable then see if outgoing URLs are blocked or not
    } else {

        // if any specific URL passed to test for
        if(isset($_GET["url"]) && $_GET["url"] != ""){
            $testing_urls = array($_GET["url"]);   
        
        } else {

            // this site homepage URL
            $server = get_site_url();

            $settings = get_option("gf_paytm_form_settings");

            $testing_urls = array(
                                            $server,
                                            "www.google.co.in",
                                            $settings["paytm_transaction_status_url"]
                                        );
        }

        // loop over all URLs, maintain debug log for each response received
        foreach($testing_urls as $key=>$url){

            $debug[$key]["info"][] = "Connecting to <b>" . $url . "</b> using cURL";

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            $res = curl_exec($ch);

            if (!curl_errno($ch)) {
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $debug[$key]["info"][] = "cURL executed succcessfully.";
                $debug[$key]["info"][] = "HTTP Response Code: <b>". $http_code . "</b>";

                // $debug[$key]["content"] = $res;

            } else {
                $debug[$key]["info"][] = "Connection Failed !!";
                $debug[$key]["info"][] = "Error Code: <b>" . curl_errno($ch) . "</b>";
                $debug[$key]["info"][] = "Error: <b>" . curl_error($ch) . "</b>";
                break;
            }

            curl_close($ch);
        }
    }

    $content = "<center><h1>cURL Test for Paytm Plugin</h1></center><hr/>";
    foreach($debug as $k=>$v){
        $content .= "<ul>";
        foreach($v["info"] as $info){
            $content .= "<li>".$info."</li>";
        }
        $content .= "</ul>";

        // echo "<div style='display:none;'>" . $v["content"] . "</div>";
        $content .= "<hr/>";
    }

    return $content;
}
/*
* Code to test Curl
*/

?>
