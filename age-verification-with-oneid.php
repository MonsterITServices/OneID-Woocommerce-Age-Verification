<?php
/**
 * Plugin Name:       Age Verification with OneID
 * Plugin URI:        https://www.monster-it.co.uk
 * Description:       Adds OneID age verification to WooCommerce checkouts via OIDC.
 * Version:           1.5.0
 * Author:            Monster IT Services Ltd
 * Author URI:        https://www.monster-it.co.uk
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       age-verification-with-oneid
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// 1. Load the Composer dependencies
require_once __DIR__ . '/vendor/autoload.php';


class OneID_Woo_Integration {

    /**
     * @var \Jumbojett\OpenIDConnectClient|null
     */
    private $oidc_client = null;

    /**
     * Constructor.
     */
    public function __construct() {
        // Ensure sessions are running
        add_action('init', [$this, 'start_session'], 1);

        // Hooks for the OIDC flow
        add_action('init', [$this, 'start_auth_flow']);
        add_action('template_redirect', [$this, 'handle_callback']);

        // WooCommerce checkout hooks
        add_action('woocommerce_before_checkout_form', [$this, 'show_verification_ui']);
        add_action('woocommerce_checkout_process', [$this, 'block_checkout_for_verification'], 10);
        
        // Admin Settings
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    /**
     * Starts the PHP session if not already started.
     */
    public function start_session() {
        if (!session_id()) {
            session_start();
        }
        if (class_exists('WooCommerce') && WC()->session && !WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
    }

    /**
     * Initializes and returns the OIDC client.
     * @return \Jumbojett\OpenIDConnectClient|false
     */
    private function get_oidc_client() {
        if ($this->oidc_client !== null) {
            return $this->oidc_client;
        }

        $options = get_option('oneid_woo_settings');
        $env = !empty($options['oneid_env']) ? $options['oneid_env'] : 'sandbox';
        $client_id = !empty($options['oneid_client_id']) ? $options['oneid_client_id'] : '';
        $client_secret = !empty($options['oneid_client_secret']) ? $options['oneid_client_secret'] : '';

        if (empty($client_id) || empty($client_secret)) {
            error_log('OneID Error: Client ID or Secret is not set in settings.');
            return false;
        }
        
        $provider_url = ($env === 'production') 
            ? 'https://controller.myoneid.co.uk' 
            : 'https://controller.sandbox.myoneid.co.uk';

        try {
            $this->oidc_client = new \Jumbojett\OpenIDConnectClient(
                $provider_url,
                $client_id,
                $client_secret
            );
            
            $this->oidc_client->setRedirectURL(home_url('/?oneid-callback=1'));
            $this->oidc_client->addScope(['profile', 'age_over_18']);
            
            return $this->oidc_client;

        } catch (\Exception $e) {
            error_log('OneID OIDC Client Init Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Checks for a "start" query param and redirects to OneID.
     */
    public function start_auth_flow() {
        if (isset($_GET['oneid-auth-start'])) {
            $oidc = $this->get_oidc_client();
            if ($oidc) {
                // Save the checkout URL to redirect back to
                if (function_exists('wc_get_checkout_url')) {
                    WC()->session->set('oneid_redirect_back_url', wc_get_checkout_url());
                }
                $oidc->authenticate();
            } else {
                wp_die('OneID is not configured correctly. Please contact the site administrator.');
            }
        }
    }

    /**
     * Handles the user returning from OneID.
     */
    public function handle_callback() {
        if (isset($_GET['oneid-callback']) && isset($_GET['code']) && isset($_GET['state'])) {
            $oidc = $this->get_oidc_client();
            
            if (!$oidc) {
                WC()->session->set('oneid_age_verified', 'error');
                wp_redirect(wc_get_checkout_url());
                exit;
            }
            
            try {
                $oidc->authenticate();
                $age_over_18_claim = $oidc->requestUserInfo('age_over_18');

                if ($age_over_18_claim === true) {
                    // 1. Set status for this specific session
                    WC()->session->set('oneid_age_verified', 'true');
                    
                    // 2. IMPORTANT: If user is logged in, save to DATABASE permanently
                    if (is_user_logged_in()) {
                        update_user_meta(get_current_user_id(), 'oneid_age_verified', 'true');
                    }

                } else {
                    WC()->session->set('oneid_age_verified', 'false');
                }

            } catch (\Exception $e) {
                error_log('OneID OIDC Callback Error: ' . $e->getMessage());
                WC()->session->set('oneid_age_verified', 'error');
            }
            
            // Redirect back to checkout
            $redirect_url = WC()->session->get('oneid_redirect_back_url') ? WC()->session->get('oneid_redirect_back_url') : wc_get_checkout_url();
            wp_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Helper function to check if the current user is verified.
     * Checks Session first, then Database.
     * @return string 'true', 'false', 'error', or 'not_verified'
     */
    private function get_verification_status() {
        // 1. Check Session (Fastest)
        $session_status = WC()->session->get('oneid_age_verified');
        if ($session_status === 'true' || $session_status === 'false' || $session_status === 'error') {
            return $session_status;
        }

        // 2. Check Database (User Meta) if logged in
        if (is_user_logged_in()) {
            $meta_status = get_user_meta(get_current_user_id(), 'oneid_age_verified', true);
            if ($meta_status === 'true') {
                // If verified in DB, set the session to true so we don't query DB on every page load
                WC()->session->set('oneid_age_verified', 'true');
                return 'true';
            }
        }
        
        return 'not_verified';
    }


    /**
     * Displays a status message.
     */
    public function show_verification_ui() {
        $status = $this->get_verification_status();
        
        echo '<h3>Age Verification</h3>';

        if ($status === 'true') {
            wc_print_notice('Your age has been successfully verified.', 'success');
        } elseif ($status === 'false') {
            wc_print_notice('You must be 18 or older to purchase these items. Age verification failed.', 'error');
        } elseif ($status === 'error') {
            $auth_url = esc_url(home_url('?oneid-auth-start=1'));
            $error_message = sprintf(
                'There was an error during age verification. <a href="%s" class="button">Please click here to try again.</a>',
                $auth_url
            );
            wc_print_notice($error_message, 'error');
        } else {
            wc_print_notice('This order requires age verification. Please complete checkout to verify.', 'notice');
        }
    }

    /**
     * Blocks the checkout process with a clear error
     * that includes the verification link.
     */
    public function block_checkout_for_verification() {
        $status = $this->get_verification_status();

        if ($status !== 'true') {
            $auth_url = esc_url(home_url('?oneid-auth-start=1'));
            $error_message = sprintf(
                'You must verify your age before placing an order. <a href="%s" class="button">Please click here to verify.</a>',
                $auth_url
            );
            wc_add_notice($error_message, 'error');
            return;
        }
    }

    // --- All functions below are for the admin settings page (unchanged) ---

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'OneID Age Verification', 
            'OneID Age Verification', 
            'manage_options',
            'oneid-woo-settings',
            [$this, 'create_settings_page']
        );
    }

    public function create_settings_page() {
        ?>
        <div class="wrap">
            <h1>OneID Age Verification Settings</h1>
            <p>Enter your OneID Client ID and Secret to configure the age verification service.</p>
            <form method="post" action="options.php">
                <?php
                settings_fields('oneid_woo_settings_group');
                do_settings_sections('oneid-woo-settings');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function register_settings() {
        register_setting('oneid_woo_settings_group', 'oneid_woo_settings');

        add_settings_section(
            'oneid_woo_api_section',
            'API Credentials',
            null,
            'oneid-woo-settings'
        );

        add_settings_field(
            'oneid_env',
            'Environment',
            [$this, 'render_env_field'],
            'oneid-woo-settings',
            'oneid_woo_api_section'
        );

        add_settings_field(
            'oneid_client_id',
            'Client ID',
            [$this, 'render_client_id_field'],
            'oneid-woo-settings',
            'oneid_woo_api_section'
        );

        add_settings_field(
            'oneid_client_secret',
            'Client Secret',
            [$this, 'render_client_secret_field'],
            'oneid-woo-settings',
            'oneid_woo_api_section'
        );
    }

    public function render_env_field() {
        $options = get_option('oneid_woo_settings');
        $env = !empty($options['oneid_env']) ? $options['oneid_env'] : 'sandbox';
        ?>
        <select name="oneid_woo_settings[oneid_env]">
            <option value="sandbox" <?php selected($env, 'sandbox'); ?>>Sandbox (Testing)</option>
            <option value="production" <?php selected($env, 'production'); ?>>Production (Live)</option>
        </select>
        <p class="description">Use Sandbox for testing, and Production when you are ready to go live.</p>
        <?php
    }

    public function render_client_id_field() {
        $options = get_option('oneid_woo_settings');
        $client_id = !empty($options['oneid_client_id']) ? $options['oneid_client_id'] : '';
        ?>
        <input type="text" class="regular-text" name="oneid_woo_settings[oneid_client_id]" value="<?php echo esc_attr($client_id); ?>">
        <p class="description">Get this from your OneID developer dashboard.</p>
        <?php
    }
    
    public function render_client_secret_field() {
        $options = get_option('oneid_woo_settings');
        $client_secret = !empty($options['oneid_client_secret']) ? $options['oneid_client_secret'] : '';
        ?>
        <input type="password" class="regular-text" name="oneid_woo_settings[oneid_client_secret]" value="<?php echo esc_attr($client_secret); ?>">
        <p class="description">Get this from your OneID developer dashboard.</p>
        <?php
    }
}

// Initialize the plugin
new OneID_Woo_Integration();