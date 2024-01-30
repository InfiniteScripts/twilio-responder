<?php
require_once( dirname( __DIR__, 1 ) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR .'autoload.php');
 use Twilio\Rest\Client;		

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://Kevin-Greene.com
 * @since      1.0.0
 *
 * @package    twilio_responder
 * @subpackage twilio_responder/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    twilio_responder
 * @subpackage twilio_responder/public
 * @author     Kevin Greene <seriouslyk@gmail.com>
 */
class twilio_responder_Public {

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
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

		/** Add Hourly Cron **/
		if ( ! wp_next_scheduled( 'twilio_custom_cron' ) ) {
			wp_schedule_event( time(), 'hourly', array($this, 'twilio_custom_cron' ) );
		}

		add_action( 'init', array($this, 'register_sms_order_status') );		
		add_action( 'init', array($this, 'register_no_reply_sms_order_status') );
		add_action( 'init', array($this, 'register_hold_ship_order_status') );

		add_filter( 'wc_order_statuses', array($this, 'add_new_order_statuses') );

		/** On Order change to SMS to ship (sms) - Send text **/
		add_action('woocommerce_order_status_changed', array($this, 'wc_status_change'), 10, 3);

		add_action( 'rest_api_init', array($this,'register_incoming') );
		add_action( 'twilio_custom_cron', array($this, 'twilio_custom_cron_func' ) );

	}

	public function register_incoming(){
		register_rest_route('endpoint', '/incoming_twilio', [
			'methods' => 'POST',
			'callback' => array($this, 'incoming_twilio_endpoint'),
		]);
	}

	public function twilio_custom_cron_func(){
		/** Select  all orders in sms status **/
		$orders = wc_get_orders(array(
			'limit'=>-1,
			'type'=> 'shop_order',
			'status'=> array( 'wc-sms' )  
			)    
		);
		if(!$orders) return;

		foreach( $orders as $order ){       
			$order_note = $this->get_private_order_notes($order->id);
			$update_time_note = $order_note[array_key_last($order_note)];
			$updated_time = strtotime($update_time_note['note_date']);
			
			/** More than an hour ago but less than 3 **/
			if( $updated_time - time() > strtotime('-1 hour') && $updated_time - time() <= strtotime('-3 hour'))
			{
				$phone = $order->get_billing_phone();
				$text = get_option('twilio_responder_outgoing_setting');
				$msg = "Order $order->id is ready to ship. " . $text;
				$this->send_twilio_text($phone, $msg);
			} 
			else {
				update_wc_status($order->id, 'wc-no-reply'); 
			}       
		}
	}
		
	public function incoming_message_logic($phone, $msg){
		global $wpdb; 
		
		/** Twilio includes the country code **/
		$phone = str_replace('+', '', $phone);
		if( strlen($phone) === 11 ) $wp_phone = substr($phone, 1);
		if( strlen($phone) === 10 ) $wp_phone = "1" . $phone;    
		
		$ar_args   = array( 'billing_phone' => $phone );
		$ar_orders = wc_get_orders( $ar_args );		
	
		if($ar_orders && !is_empty($ar_orders) )
		{          
			$order_id = $ar_orders[0]->get_id();        
		} 
		else 
		{       
			$ar_args = array( 'billing_phone' => $wp_phone );
			$ar_orders = wc_get_orders( $ar_args );
			if($ar_orders) $order_id = $ar_orders[0]->get_id();        
		}
		
		if(!$order_id) return 'No Order Found';
		
		$order = wc_get_order( $order_id );	
		
		if( strtolower($order->get_status()) == "delivered" || strtolower($order->get_status()) == "completed"){
			$text = "Your order has already been shipped or delivered and no further changes can be made to this order's shipping.";
			$this->send_twilio_text($phone, $text);
			return 'Confirmation text sent';
		}

		$keyword1 = strtolower(get_option('twilio_responder_keyword1_setting'));
		$keyword2 = strtolower(get_option('twilio_responder_keyword2_setting'));
		$keyword3 = strtolower(get_option('twilio_responder_keyword3_setting'));
	
		if(str_contains($msg, $keyword1)){
			/** Change order status to ready to ship (SMS Ship Today) **/         
			$this->update_wc_status($order_id, 'wc-sms-ship-today');
			$text = "Your order has been confirmed for shipping. If it is after 3PM, your order will be sent on the next available shipping day(Mon-Wed)";
			$this->send_twilio_text($phone, $text);
			return 'Confirmation text sent';
	    } 
		else if(str_contains($msg, $keyword2)){
			/** Change order status (hold-ship) **/
			$text = "Please reply with your requested shipping date.";        
			$this->send_twilio_text($phone, $text); 			
			$note = $order->get_customer_note(); 
			$note = $note . PHP_EOL . $msg;
			$order->set_customer_note( $note );   
			$order->save();
			$this->update_wc_status($order_id, 'wc-hold-ship');  
			return 'Confirmation text sent, customer note added and order status updated.';   
		}       
		else if(str_contains($msg, $keyword3)){
			/** Change order status (Order Review) **/
			$text = "Thank you, your order has been updated with this information.";    
			$note = $order->get_customer_note(); 
			$note = $note . PHP_EOL . $msg;
			$order->set_customer_note( $note );   
			$order->save();
			$this->send_twilio_text($phone, $text);  			
			$this->update_wc_status($order_id, 'wc-review');   
			return 'Confirmation text sent, customer note added and order status updated.';      
		}  
		else {        
			$text = "Thank you, your order has been updated with this information.";    
			$note = $order->get_customer_note(); 
			$note = $note . PHP_EOL . $msg;
			$order->set_customer_note( $note );   
			$order->save();
			$this->send_twilio_text($phone, $text);    			
			return 'Confirmation text sent, customer note added and order status updated.';      
		}    
	}
   
	public function update_wc_order_notes($order_id, $order_note)	{
		$order = new WC_Order( $order_id ); 
		$order->add_order_note( $order_note ); // This will add as a private note.
	}

	public function update_wc_status($order_id, $new_status){
		$order = new WC_Order($order_id); 
		if (!empty($order)) 
		{ 
			$order->update_status( $new_status );
		}
	}	

	public function incoming_twilio_endpoint($request){
		$parameters = $request->get_params();      
		$from = $parameters['from'];  
		$msg = $parameters['msg'];
		$control = $parameters['control'];
		if($control != 'fdgsertu45687wserhg78dq3' ) return;  		
		
		return $this->incoming_message_logic($from, $msg);   
	}


	public function wc_status_change($order_id, $old_status, $new_status){           
		if( $new_status == "sms" )
		{
			$order = wc_get_order($order_id);
			$phone = $order->get_billing_phone();       
			$text = get_option('twilio_responder_outgoing_setting');
			$msg = "Order $order_id is ready to ship. " . $text;
			$this->send_twilio_text($phone, $msg);
		}
	}   
	
	public function add_new_order_statuses( $order_statuses ){
		$new_order_statuses = array();
		foreach ( $order_statuses as $key => $status ) {
			$new_order_statuses[ $key ] = $status;        
			if ( 'wc-processing' === $key ) {
				$new_order_statuses['wc-hold-ship'] = 'Hold Ship';
				$new_order_statuses['wc-no-reply'] = 'No SMS Reply';
				$new_order_statuses['wc-ready-ship'] = 'Ready to Ship';
				$new_order_statuses['wc-sms'] = 'SMS to Ship';
			}
		}
		return $new_order_statuses;
	}

	public function register_sms_order_status(){
		register_post_status( 'wc-sms', array(
			'label'                     => 'SMS to Ship',
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
			'exclude_from_search'       => false,
			'label_count'               => _n_noop( 'SMS to Ship <span class="count">(%s)</span>', 'SMS to Ship <span class="count">(%s)</span>' )
		) );
	}	

	public function register_no_reply_sms_order_status(){
		register_post_status( 'wc-no-reply', array(
			'label'                     => 'No SMS Reply',
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
			'exclude_from_search'       => false,
			'label_count'               => _n_noop( 'No SMS Reply <span class="count">(%s)</span>', 'No SMS Reply <span class="count">(%s)</span>' )
		) );
	}

	public function register_hold_ship_order_status(){
		register_post_status( 'wc-hold-ship', array(
			'label'                     => 'Hold Ship',
			'public'                    => true,
			'show_in_admin_status_list' => true,
			'show_in_admin_all_list'    => true,
			'exclude_from_search'       => false,
			'label_count'               => _n_noop( 'Hold Ship <span class="count">(%s)</span>', 'Hold Ship <span class="count">(%s)</span>' )
		) );
	}

	public function get_private_order_notes($order_id){
		global $wpdb;
	
		$table_perfixed = $wpdb->prefix . 'comments';
		$results = $wpdb->get_results("
			SELECT *
			FROM $table_perfixed
			WHERE  `comment_post_ID` = $order_id
			AND  `comment_type` LIKE  'order_note'
		");
	
		foreach($results as $note){
			$order_note[]  = array(           
				'note_date'    => $note->comment_date,            
			);
		}
	
		return $order_note;
	}

	public function send_twilio_text($to, $msg) {   
				
		$sid    = get_option( 'twilio_responder_sid_setting' );
		$token  = get_option( 'twilio_responder_apikey_setting');
		$twilio = new Client($sid, $token);
		$from = "18777202290";   
		if(strlen($to) == 10){
			$to = "1" . $to;
		}
		
		$message = $twilio->messages->create($to, 
			array(
			"from" => $from,
			"body" => $msg
			)
		);
		
		return($message->sid); 
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in twilio_responder_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The twilio_responder_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/twilio-responder-public.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in twilio_responder_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The twilio_responder_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/twilio-responder-public.js', array( 'jquery' ), $this->version, false );

	}

}
