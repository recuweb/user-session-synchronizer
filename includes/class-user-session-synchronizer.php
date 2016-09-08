<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class User_Session_Synchronizer {

	/**
	 * The single instance of User_Session_Synchronizer.
	 * @var 	object
	 * @access  private
	 * @since 	1.0.0
	 */
	private static $_instance = null;

	/**
	 * Settings class object
	 * @var     object
	 * @access  public
	 * @since   1.0.0
	 */
	public $settings = null;

	/**
	 * The version number.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $_version;

	/**
	 * The token.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $_token;

	/**
	 * The main plugin file.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $file;

	/**
	 * The main plugin directory.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $dir;

	/**
	 * The plugin assets directory.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $assets_dir;

	/**
	 * The plugin assets URL.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $assets_url;

	/**
	 * Suffix for Javascripts.
	 * @var     string
	 * @access  public
	 * @since   1.0.0
	 */
	public $script_suffix;

	/**
	 * Constructor function.
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	 
	public function __construct ( $file = '', $version = '1.0.0' ) {
		
		$this->_version = $version;
		$this->_token = 'user-session-synchronizer';

		// Load plugin environment variables
		$this->file = $file;
		$this->dir = dirname( $this->file );
		$this->assets_dir = trailingslashit( $this->dir ) . 'assets';
		$this->assets_url = esc_url( trailingslashit( plugins_url( '/assets/', $this->file ) ) );

		$this->script_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		
		// set user information
		
		$this->user_id = get_current_user_id();
		
		if( is_admin() ) {
			
			$this->user_verified = 'true';
		}
		else{
			
			$this->user_verified = get_user_meta( $this->user_id, "ussync_email_verified", TRUE);
		}
		
		// set user ip
		
		if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
			
			$this->user_ip = $_SERVER['HTTP_CLIENT_IP'];
		} 
		elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			
			$this->user_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} 
		else {
			
			$this->user_ip = $_SERVER['REMOTE_ADDR'];
		}
		
		// set user agent
		
		$this->user_agent = $_SERVER ['HTTP_USER_AGENT'];
		
		// register plugin activation hook
		
		register_activation_hook( $this->file, array( $this, 'install' ) );

		// Load frontend JS & CSS
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ), 10 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ), 10 );

		// Load admin JS & CSS
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ), 10, 1 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_styles' ), 10, 1 );

		// Load API for generic admin functions
		if ( is_admin() ) {
			
			$this->admin = new User_Session_Synchronizer_Admin_API();
		}

		// Handle localisation
		$this->load_plugin_textdomain();
		add_action( 'init', array( $this, 'load_localisation' ), 0 );

		// Handle login synchronization
		add_action( 'init', array( $this, 'ussync_synchronize_session' ), 0 );		
		
		// Handle profile updates
		add_action( 'user_profile_update_errors', array( $this, 'ussync_prevent_email_change'), 10, 3 );
		add_action( 'admin_init', array( $this, 'ussync_user_profile_fields_disable'));
		
		// Handle footers
		add_action( 'wp_footer', array( $this, 'ussync_add_footer' ));
		add_action( 'admin_footer_text', array( $this, 'ussync_add_footer' ));

	} // End __construct ()

	public function ussync_prevent_email_change( $errors, $update, $user ) {

		$old = get_user_by('id', $user->ID);

		if( $user->user_email != $old->user_email   && (!current_user_can('create_users')) )
				$user->user_email = $old->user_email;
	}
	
	public function ussync_user_profile_fields_disable() {
	 
		global $pagenow;
	 
		// apply only to user profile or user edit pages
		if ($pagenow!=='profile.php' && $pagenow!=='user-edit.php') {
			return;
		}
	 
		// do not change anything for the administrator
		if (current_user_can('administrator')) {
			return;
		}
	 
		add_action( 'admin_footer', array( $this,'ussync_user_profile_fields_disable_js' ));
	}
	 
	 
	/**
	 * Disables selected fields in WP Admin user profile (profile.php, user-edit.php)
	 */
	public function ussync_user_profile_fields_disable_js() {
		
		?>
			<script>
				jQuery(document).ready( function($) {
					var fields_to_disable = ['email', 'username'];
					for(i=0; i<fields_to_disable.length; i++) {
						if ( $('#'+ fields_to_disable[i]).length ) {
							$('#'+ fields_to_disable[i]).attr("disabled", "disabled");
							$('#'+ fields_to_disable[i]).after("<span class=\"description\"> " + fields_to_disable[i] + " cannot be changed.</span>");
						}
					}
				});
			</script>
		<?php
	}	
	
	public function ussync_synchronize_session(){
		
		if(isset($_GET['action'])&&$_GET['action']=='logout'){
			
			$this-> ussync_call_domains(true);
		}
		elseif(isset($_GET['ussync-status']) && $_GET['ussync-status']=='loggedin'){
			
			echo 'User logged in!';
			exit;
		}
		elseif(is_user_logged_in() && isset($_GET['redirect_to'])){
			
			wp_safe_redirect( trim( $_GET['redirect_to'] ) );
			exit;
		}
		elseif(isset($_GET['ussync-token'])&&isset($_GET['ussync-id'])&&isset($_GET['ussync-ref'])){
			
			// set secret key number
			
			$key_num=1;
			
			if(isset($_GET['ussync-key'])){
				
				$key_num=(int)trim($_GET['ussync-key']);
			}

			//decrypted user_name
			
			$user_name = trim($_GET['ussync-id']);
			$user_name = $this->ussync_decrypt_uri($user_name, get_option('ussync_secret_key_'.$key_num) );

			//decrypted user_name
			
			$user_ref = trim($_GET['ussync-ref']);
			$user_ref = $this->ussync_decrypt_uri($user_ref, get_option('ussync_secret_key_'.$key_num) );
			
			//decrypted user_email
			
			$user_email = trim($_GET['ussync-token']);
			$user_email = $this->ussync_decrypt_uri($user_email, get_option('ussync_secret_key_'.$key_num) );
			
			//set user ID
			
			$user_email = sanitize_email($user_email);
			
			//get valid domains
			
			$domains = get_option('ussync_domain_list_'.$key_num);
			$domains = explode(PHP_EOL,$domains);
			$domains = array_flip($domains);
			
			//check referer
			
			$valid_referer=false;
			
			if(isset($domains[$user_ref])){
				
				$valid_referer=true;
			}			
			
			if($valid_referer===true){
				
				if(isset($_GET['ussync-status']) && $_GET['ussync-status']=='loggingout'){
					
					// Logout user
					
					if( $user = get_user_by('email', $user_email ) ){
						
						// get all sessions for user with ID
						$sessions = WP_Session_Tokens::get_instance($user->ID);

						// we have got the sessions, destroy them all!
						$sessions->destroy_all();	

						echo 'User logged out...';
						exit;					
					} 
					else{
						
						var_dump($this->ussync_decrypt_uri($_GET['ussync-token'], get_option('ussync_secret_key_'.$key_num) ));exit;
						
						echo 'Error logging out...';
						exit;					
					}
				}			
				else{
					
					$current_user = wp_get_current_user();

					if(!is_user_logged_in() || $current_user->user_email != $user_email){				
						
						// check if the user exists
						
						if( !email_exists( $user_email ) ){
						
							$ussync_no_user = get_option('ussync_no_user_'.$key_num);
						
							if($ussync_no_user=='register_suscriber'){
								
								// register new suscriber
								
								$user_data = array(
									'user_login'  =>  $user_name,
									'user_email'   =>  $user_email,
								);
														
								if( get_userdatabylogin($user_name) ){
									
									echo 'User name already exists!';
									exit;							
								}
								elseif( $user_id = wp_insert_user( $user_data ) ) {
									
									// update email status
									
									add_user_meta( $user_id, 'ussync_email_verified', 'true');
								}
								else{
									
									echo 'Error creating a new user!';
									exit;								
								}
							}
							else{
								
								echo 'This user doesn\'t exist...';
								exit;							
							}
						}
						
						if($current_user->user_email != $user_email){
							
							//destroy current user session

							$sessions = WP_Session_Tokens::get_instance($current_user->ID);
							$sessions->destroy_all();	
						}					
						
						if($user=get_user_by('email',$user_email)){
							
							//do the authentication
							
							clean_user_cache($user->ID);
							
							wp_clear_auth_cookie();
							wp_set_current_user( $user->ID );
							wp_set_auth_cookie( $user->ID , true, false);

							update_user_caches($user);
							
							if(is_user_logged_in()){
								
								//redirect after authentication
								
								wp_safe_redirect( rtrim( get_site_url(), '/' ) . '/?ussync-status=loggedin');
							}
						}
						else{
							
							echo 'Error logging in...';
							exit;						
						}					
					}
					else{
						
						echo 'User already logged in...';
						exit;
					}
				}
			}
			else{
				
				echo 'Host not allowed to synchronize...';
				exit;				
			}
		}
	}
	
    public function ussync_add_footer(){
		
		if(is_user_logged_in() && !isset($_GET['ussync-token']) && $this->user_verified === 'true'){
			
			$this-> ussync_call_domains();
		}

        return true;
    }
	
	public function ussync_call_domains($loggingout=false){
		
		if($user = wp_get_current_user()){
			
			//get secret key number
			
			$key_num = 1; 			
			
			//get secret key
			
			$secret_key=get_option('ussync_secret_key_'.$key_num);
			
			//get list of domains
			
			$domains = get_option('ussync_domain_list_'.$key_num);
			$domains = explode(PHP_EOL,$domains);
			
			//get encrypted user name
			
			$user_name = $user->user_login;
			$user_name = $this->ussync_encrypt_uri($user_name, $secret_key);

			//get encrypted user referer
			
			$user_ref = $_SERVER['HTTP_HOST'];
			$user_ref = $this->ussync_encrypt_uri($user_ref, $secret_key);
			
			//get encrypted user email
			
			$user_email = $user->user_email;
			$user_email = $this->ussync_encrypt_uri($user_email, $secret_key);
			
			//get current domain
			
			$current_domain = get_site_url();
			$current_domain = rtrim($current_domain,'/');
			$current_domain = preg_replace("(^https?://)", "", $current_domain);
			
			if(!empty($domains)){
				
				foreach($domains as $domain){
					
					$domain = trim($domain);
					$domain = rtrim($domain,'/');
					$domain = preg_replace("(^https?://)", "", $domain);
					
					if($current_domain != $domain){
						
						if($loggingout===true){
							
							$opts = array(
							  'http'=>array(
								'method'=>"GET",
								'header'=>"User-Agent: " . $this->user_agent . "\r\n"
							  )
							);

							$context = stream_context_create($opts);							
							
							file_get_contents('http://' . $domain . '/?ussync-token='.$user_email.'&ussync-key='.$key_num.'&ussync-id='.$user_name.'&ussync-ref='.$user_ref.'&ussync-status=loggingout'.'&_' . time(), false, $context);
						}
						else{
							
							//output html
						
							echo '<img class="ussync" src="http://' . $domain . '/?ussync-token='.$user_email.'&ussync-key='.$key_num.'&ussync-id='.$user_name.'&ussync-ref='.$user_ref.'&_' . time() . '" style="display:none;width:0;height:0;">';								
						}
					}
				}				
			}
		}
	}
	

	private function ussync_encrypt_str($string, $secret_key){
		
		$output = false;

		$encrypt_method = "AES-256-CBC";
		
		$secret_key = md5($secret_key);
		
		$secret_iv = md5($this->user_agent . $this->user_ip);

		// hash
		$key = hash('sha256', $secret_key);
		
		// iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
		$iv = substr(hash('sha256', $secret_iv), 0, 16);

		$output = openssl_encrypt($string, $encrypt_method, $key, 0, $iv);
		$output = $this->ussync_base64_urlencode($output);

		return $output;
	}
	
	private function ussync_decrypt_str($string, $secret_key){
		
		$output = false;

		$encrypt_method = "AES-256-CBC";
		
		$secret_key = md5($secret_key);
		
		$secret_iv = md5($this->user_agent . $this->user_ip);

		// hash
		$key = hash('sha256', $secret_key);
		
		// iv - encrypt method AES-256-CBC expects 16 bytes - else you will get a warning
		$iv = substr(hash('sha256', $secret_iv), 0, 16);

		$output = openssl_decrypt($this->ussync_base64_urldecode($string), $encrypt_method, $key, 0, $iv);

		return $output;
	}
	
	private function ussync_encrypt_uri($uri,$secret_key,$len=250,$separator='/'){
		
		$uri = wordwrap($this->ussync_encrypt_str($uri,$secret_key),$len,$separator,true);
		
		return $uri;
	}
	
	private function ussync_decrypt_uri($uri,$secret_key,$separator='/'){
		
		$uri = $this->ussync_decrypt_str(str_replace($separator,'',$uri),$secret_key);
		
		return $uri;
	}
	
	private function ussync_base64_urlencode($inputStr=''){

		return strtr(base64_encode($inputStr), '+/=', '-_,');
	}

	private function ussync_base64_urldecode($inputStr=''){

		return base64_decode(strtr($inputStr, '-_,', '+/='));
	}

	/**
	 * Wrapper function to register a new post type
	 * @param  string $post_type   Post type name
	 * @param  string $plural      Post type item plural name
	 * @param  string $single      Post type item single name
	 * @param  string $description Description of post type
	 * @return object              Post type class object
	 */
	public function register_post_type ( $post_type = '', $plural = '', $single = '', $description = '', $options = array() ) {

		if ( ! $post_type || ! $plural || ! $single ) return;

		$post_type = new User_Session_Synchronizer_Post_Type( $post_type, $plural, $single, $description, $options );

		return $post_type;
	}

	/**
	 * Wrapper function to register a new taxonomy
	 * @param  string $taxonomy   Taxonomy name
	 * @param  string $plural     Taxonomy single name
	 * @param  string $single     Taxonomy plural name
	 * @param  array  $post_types Post types to which this taxonomy applies
	 * @return object             Taxonomy class object
	 */
	public function register_taxonomy ( $taxonomy = '', $plural = '', $single = '', $post_types = array(), $taxonomy_args = array() ) {

		if ( ! $taxonomy || ! $plural || ! $single ) return;

		$taxonomy = new User_Session_Synchronizer_Taxonomy( $taxonomy, $plural, $single, $post_types, $taxonomy_args );

		return $taxonomy;
	}

	/**
	 * Load frontend CSS.
	 * @access  public
	 * @since   1.0.0
	 * @return void
	 */
	public function enqueue_styles () {
		
		wp_register_style( $this->_token . '-frontend', esc_url( $this->assets_url ) . 'css/frontend.css', array(), $this->_version );
		wp_enqueue_style( $this->_token . '-frontend' );
	} // End enqueue_styles ()

	/**
	 * Load frontend Javascript.
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function enqueue_scripts () {
		wp_register_script( $this->_token . '-frontend', esc_url( $this->assets_url ) . 'js/frontend' . $this->script_suffix . '.js', array( 'jquery' ), $this->_version );
		wp_enqueue_script( $this->_token . '-frontend' );
	} // End enqueue_scripts ()

	/**
	 * Load admin CSS.
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function admin_enqueue_styles ( $hook = '' ) {
		wp_register_style( $this->_token . '-admin', esc_url( $this->assets_url ) . 'css/admin.css', array(), $this->_version );
		wp_enqueue_style( $this->_token . '-admin' );
	} // End admin_enqueue_styles ()

	/**
	 * Load admin Javascript.
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function admin_enqueue_scripts ( $hook = '' ) {
		wp_register_script( $this->_token . '-admin', esc_url( $this->assets_url ) . 'js/admin' . $this->script_suffix . '.js', array( 'jquery' ), $this->_version );
		wp_enqueue_script( $this->_token . '-admin' );
	} // End admin_enqueue_scripts ()

	/**
	 * Load plugin localisation
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function load_localisation () {
		load_plugin_textdomain( 'user-session-synchronizer', false, dirname( plugin_basename( $this->file ) ) . '/lang/' );
	} // End load_localisation ()

	/**
	 * Load plugin textdomain
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function load_plugin_textdomain () {
	    $domain = 'user-session-synchronizer';

	    $locale = apply_filters( 'plugin_locale', get_locale(), $domain );

	    load_textdomain( $domain, WP_LANG_DIR . '/' . $domain . '/' . $domain . '-' . $locale . '.mo' );
	    load_plugin_textdomain( $domain, false, dirname( plugin_basename( $this->file ) ) . '/lang/' );
	} // End load_plugin_textdomain ()

	/**
	 * Main User_Session_Synchronizer Instance
	 *
	 * Ensures only one instance of User_Session_Synchronizer is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 * @static
	 * @see User_Session_Synchronizer()
	 * @return Main User_Session_Synchronizer instance
	 */
	public static function instance ( $file = '', $version = '1.0.0' ) {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self( $file, $version );
		}
		return self::$_instance;
	} // End instance ()

	/**
	 * Cloning is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __clone () {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?' ), $this->_version );
	} // End __clone ()

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 1.0.0
	 */
	public function __wakeup () {
		_doing_it_wrong( __FUNCTION__, __( 'Cheatin&#8217; huh?' ), $this->_version );
	} // End __wakeup ()

	/**
	 * Installation. Runs on activation.
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function install () {
		$this->_log_version_number();
	} // End install ()

	/**
	 * Log the plugin version number.
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	private function _log_version_number () {
		update_option( $this->_token . '_version', $this->_version );
	} // End _log_version_number ()
}