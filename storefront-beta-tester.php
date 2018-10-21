<?php
/**
 * Plugin Name: Storefront Beta Tester
 * Plugin URI: https://github.com/seb86/Storefront-Beta-Tester
 * Description: Run bleeding edge versions of Storefront from Github.
 * Version: 1.0.3
 * Author: Sébastien Dumont
 * Author URI: http://sebastiendumont.com
 * GitHub Plugin URI: https://github.com/seb86/Storefront-Beta-Tester
 *
 * Text Domain: storefront-beta-tester
 * Domain Path: /languages/
 *
 * Requires at least: 4.2
 * Tested up to: 4.4.2
 *
 * Based on WP_GitHub_Updater by Joachim Kudish.
 * Forked from WooCommerce Beta Tester by Mike Jolly and Claudio Sanches.
 */
if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Confirm Storefront is installed before doing anything.
 * Curiously, there is not a constant for getting the theme directory so we have 
 * to use get_theme_root() function instead, so this is what we have to do.
 */
if ( ! file_exists( trailingslashit( get_theme_root() ) . 'storefront/style.css' ) ) {
	add_action( 'admin_notices', 'storefront_not_installed' );
}
elseif ( ! class_exists( 'Storefront_Beta_Tester' ) ) {

	/**
	 * Storefront_Beta_Tester Main Class
	 */
	class Storefront_Beta_Tester {

		/**
		 * Config
		 *
		 * @access private
		 */
		private $config = array();

		/**
		 * Github Data
		 * 
		 * @access protected
		 * @static
		 */
		protected static $_instance = null;

		/**
		 * Main Instance
		 * 
		 * @access public
		 * @static
		 */
		public static function instance() {
			return self::$_instance = is_null( self::$_instance ) ? new self() : self::$_instance;
		}

		/**
		 * Run on activation to flush update cache.
		 * 
		 * @access public
		 * @static
		 */
		public static function activate() {
			delete_site_transient( 'update_themes' );
			delete_site_transient( 'storefront_latest_tag' );
		}

		/**
		 * Constructor
		 * 
		 * @access public
		 */
		public function __construct() {
			$this->config = array(
				'theme_file'         => 'storefront/style.css',
				'slug'               => 'storefront',
				'proper_folder_name' => 'storefront',
				'api_url'            => 'https://api.github.com/repos/woocommerce/storefront',
				'github_url'         => 'https://github.com/woocommerce/storefront',
				'requires'           => '4.2',
				'tested'             => '4.4.2'
			);

			add_filter( 'pre_set_site_transient_update_themes', array( $this, 'api_check' ) );
			add_filter( 'themes_api', array( $this, 'get_theme_info' ), 10, 3 );
			add_action( 'init', array( $this, 'load_textdomain' ) );
			add_filter( 'plugin_row_meta', array( $this, 'plugin_meta_links' ), 10, 4 );
		}

		/**
		 * Update args
		 *
		 * @access public
		 * @return array
		 */
		public function set_update_args() {
			$theme_data                   = $this->get_theme_data();
			$this->config['theme_name']   = $theme_data['Name'];
			$this->config['version']      = $theme_data['Version'];
			$this->config['author']       = $theme_data['Author'];
			$this->config['homepage']     = esc_url_raw( $theme_data['ThemeURI'] );
			$this->config['new_version']  = str_replace( 'version/', '', $this->get_latest_tag() );
			$this->config['new_release']  = $this->get_latest_tag();
			$this->config['last_updated'] = $this->get_date();
			$this->config['description']  = $this->get_description();
			$this->config['zip_url']      = 'https://github.com/woocommerce/storefront/releases/download/' . $this->config['new_release']. '/storefront.zip';
			$this->config['screenshot']   = 'https://raw.githubusercontent.com/woocommerce/storefront/master/screenshot.png';
		}

		/**
		 * Check wether or not the transients need to be overruled and API needs to be called for every single page load
		 *
		 * @access public
		 * @return bool overrule or not
		 */
		public function overrule_transients() {
			return ( defined( 'STOREFRONT_BETA_TESTER_FORCE_UPDATE' ) && STOREFRONT_BETA_TESTER_FORCE_UPDATE );
		}

		/**
		 * Get New Version from GitHub
		 *
		 * @since   1.0.0
		 * @version 1.0.2
		 * @access  public
		 * @return  int $version the version number
		 */
		public function get_latest_tag() {
			$tagged_version = get_site_transient( md5( $this->config['slug'] ) . '_latest_tag' );

			if ( $this->overrule_transients() || empty( $tagged_version ) ) {

				$raw_response = wp_remote_get( trailingslashit( $this->config['api_url'] ) . 'releases' );

				if ( is_wp_error( $raw_response ) ) {
					return '<div id="message" class="error"><p>' . $raw_response->get_error_message() . '</p></div>';
				}

				$releases       = json_decode( $raw_response['body'] );
				$tagged_version = false;

				if ( is_array( $releases ) ) {
					foreach ( $releases as $release ) {
						if ( $release->prerelease ) {
							$tagged_version = $release->tag_name;
							break;
						}
					}
				}

				// refresh every 6 hours
				if ( ! empty( $tagged_version ) ) {
					set_site_transient( md5( $this->config['slug'] ) . '_latest_tag', $tagged_version, 60*60*6 );
				}
			}

			return $tagged_version;
		}

		/**
		 * Get GitHub Data from the specified repository
		 *
		 * @since   1.0.0
		 * @version 1.0.2
		 * @access  public
		 * @return  array $github_data the data
		 */
		public function get_github_data() {
			if ( ! empty( $this->github_data ) ) {
				$github_data = $this->github_data;
			} else {
				$github_data = get_site_transient( md5( $this->config['slug'] ) . '_github_data' );

				if ( $this->overrule_transients() || ( ! isset( $github_data ) || ! $github_data || '' == $github_data ) ) {
					$github_data = wp_remote_get( $this->config['api_url'] );

					if ( is_wp_error( $github_data ) ) {
						return '<div id="message" class="error"><p>' . $github_data->get_error_message() . '</p></div>';
					}

					$github_data = json_decode( $github_data['body'] );

					// refresh every 6 hours
					set_site_transient( md5( $this->config['slug'] ) . '_github_data', $github_data, 60*60*6 );
				}

				// Store the data in this class instance for future calls
				$this->github_data = $github_data;
			}

			return $github_data;
		}
		/**
		 * Get update date
		 *
		 * @access public
		 * @return string $date the date
		 */
		public function get_date() {
			$_date = $this->get_github_data();
			return ! empty( $_date->updated_at ) ? date( 'Y-m-d', strtotime( $_date->updated_at ) ) : false;
		}

		/**
		 * Get theme description
		 *
		 * @access public
		 * @return string $description the description
		 */
		public function get_description() {
			$_description = $this->get_github_data();
			return ! empty( $_description->description ) ? $_description->description : __( 'Storefront is a robust and flexible WordPress theme, designed by WooCommerce to help you make the most out of using WooCommerce to power your online store.', 'storefront-beta-tester' );
		}

		/**
		 * Get Theme data
		 *
		 * @access public
		 * @return object $data the data
		 */
		public function get_theme_data() {
			return wp_get_theme( $this->config['slug'] );
		}

		/**
		 * Hook into the theme update check and connect to GitHub
		 *
		 * @access public
		 * @param  object  $transient the theme data transient
		 * @return object $transient updated theme data transient
		 */
		public function api_check( $transient ) {
			// Check if the transient contains the 'checked' information
			// If not, just return its value without hacking it
			if ( empty( $transient->checked ) ) {
				return $transient;
			}

			// Clear our transient
			delete_site_transient( md5( $this->config['slug'] ) . '_latest_tag' );

			// Update tags
			$this->set_update_args();

			// check the version and decide if it's new
			if ( version_compare( $this->config['new_version'], $this->config['version'], '>' ) ) {
				$transient->response[ $this->config['slug'] ] = array(
					'new_version' => $this->config['new_version'],
					'package'     => $this->config['zip_url'],
					'url'         => $this->config['github_url']
				);
			}

			return $transient;
		}

		/**
		 * Get Theme info
		 *
		 * @access public
		 * @param  bool   $false  always false
		 * @param  string $action the API function being performed
		 * @param  object $args   theme arguments
		 * @return object $response the theme info
		 */
		public function get_theme_info( $false, $action, $response ) {
			// Check if this call API is for the right theme
			if ( ! isset( $response->slug ) || $response->slug != $this->config['slug'] ) {
				return false;
			}

			// Update tags
			$this->set_update_args();

			$response->slug           = $this->config['slug'];
			$response->theme          = $this->config['slug'];
			$response->name           = $this->config['theme_name'];
			$response->theme_name     = $this->config['theme_name'];
			$response->version        = $this->config['new_version'];
			$response->author         = $this->config['author'];
			$response->homepage       = $this->config['homepage'];
			$response->requires       = $this->config['requires'];
			$response->tested         = $this->config['tested'];
			$response->screenshot_url = $this->config['screenshot'];
			$response->downloaded     = 0;
			$response->last_updated   = $this->config['last_updated'];
			$response->sections       = array( 'description' => $this->config['description'] );
			$response->download_link  = $this->config['zip_url'];
			$response->package        = $this->config['zip_url'];
			$response->file_name      = $this->config['slug'] . '.zip';

			return $response;
		}

		/**
		 * Rename the downloaded zip
		 *
		 * @access public
		 * @global $wp_filesystem
		 */
		public function upgrader_source_selection( $source, $remote_source, $upgrader ) {
			global $wp_filesystem;

			if ( strstr( $source, '/woothemes-Storefront-' ) ) {
				$corrected_source = trailingslashit( $remote_source ) . trailingslashit( $this->config[ 'proper_folder_name' ] );

				if ( $wp_filesystem->move( $source, $corrected_source, true ) ) {
					return $corrected_source;
				} else {
					return new WP_Error();
				}
			}

			return $source;
		}

		/**
		 * Load textdomain.
		 *
		 * @access public
		 * @return void
		 */
		public function load_textdomain() {
			load_plugin_textdomain( 'storefront-beta-tester', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
		}

		/**
		 * Show row meta on the plugin screen.
		 *
		 * @access public
		 * @param  mixed $links Plugin Row Meta
		 * @param  mixed $file  Plugin Base file
		 * @return array
		 */
		public function plugin_meta_links( $links, $file, $data, $status ) {
			if ( $file == plugin_basename( __FILE__ ) ) {
				$author1  = '<a href="' . $data[ 'AuthorURI' ] . '">' . $data[ 'Author' ] . '</a>';
				$author2  = '<a href="http://jameskoster.co.uk/">James Koster</a>';
				$author3  = '<a href="https://bradgriffin.me/">Brad Griffin</a>';
				$links[1] = sprintf( __( 'By %s' ), sprintf( __( '%s and %s and %s' ), $author1, $author2, $author3 ) );
			}

			return $links;
		}

	} // END class

	register_activation_hook( __FILE__, array( 'Storefront_Beta_Tester', 'activate' ) );

	add_action( 'admin_init', array( 'Storefront_Beta_Tester', 'instance' ) );

} // END if theme installed / class exists

/**
 * Storefront Not Installed Notice
 */
if ( ! function_exists( 'storefront_not_installed' ) ) {
	function storefront_not_installed() {
		echo '<div class="notice notice-error is-dismissible"><p>' . sprintf( __( 'Storefront Beta Tester requires %s to be installed.', 'storefront-beta-tester'), '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'update.php?action=install-theme&theme=storefront' ), 'install-theme_storefront' ) ) . '">Storefront</a>' ) . '</p></div>';
	}
}