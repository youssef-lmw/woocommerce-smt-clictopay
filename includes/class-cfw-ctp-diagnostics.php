<?php
/**
 * ClicToPay validation-sheet helper.
 *
 * ClicToPay rejects results produced with external tools such as Postman: the
 * calls must come from the merchant site itself. This admin screen runs the
 * test cases that a normal checkout never produces (missing parameter,
 * duplicate order number, unknown orderId) and shows the raw JSON so it can be
 * pasted into the integration validation grid. Every call also lands in the
 * WooCommerce log (source: clictopay).
 */

defined('ABSPATH') or die('No script kiddies please!');

add_action('admin_menu', 'cfw_ctp_register_diagnostics_page', 20);
function cfw_ctp_register_diagnostics_page() {
    add_submenu_page(
        'woocommerce',
        __('ClicToPay Tests', 'clictopay-for-woocommerce'),
        __('ClicToPay Tests', 'clictopay-for-woocommerce'),
        'manage_woocommerce',
        'cfw-ctp-tests',
        'cfw_ctp_render_diagnostics_page'
    );
}

/**
 * URL of the gateway settings screen.
 */
function cfw_ctp_settings_url() {
    return admin_url('admin.php?page=wc-settings&tab=checkout&section=' . CFW_CTP_GATEWAY_ID);
}

/**
 * Runs the requested test case and returns a list of
 * array( 'label' => string, 'request' => array, 'raw' => string ).
 */
function cfw_ctp_run_diagnostic($test, $gateway, $input = array()) {
    $calls = array();

    $credentials = array(
        'userName' => $gateway->get_option('username'),
        'password' => $gateway->get_option('password'),
    );

    switch ($test) {
        case 'register_valid':
            $params = $credentials + array(
                'orderNumber' => 'TEST-REGISTER-' . time(),
                'amount'      => 10000,
                'currency'    => 788,
                'returnUrl'   => home_url('/?cfw_ctp_test=success'),
                'failUrl'     => home_url('/?cfw_ctp_test=fail'),
                'language'    => 'fr',
            );
            $result  = $gateway->api_request('register.do', $params, 'TEST register');
            $calls[] = array(
                'label'   => __('Valid registration — register.do with every mandatory parameter', 'clictopay-for-woocommerce'),
                'request' => $params,
                'raw'     => $result['raw'],
            );
            break;

        case 'register_missing_amount':
            // Deliberately omits "amount": expected errorCode 4.
            $params = $credentials + array(
                'orderNumber' => 'TEST-NOAMOUNT-' . time(),
                'currency'    => 788,
                'returnUrl'   => home_url('/?cfw_ctp_test=success'),
                'language'    => 'fr',
            );
            $result  = $gateway->api_request('register.do', $params, 'TEST register without amount');
            $calls[] = array(
                'label'   => __('Missing parameter — register.do without the amount parameter', 'clictopay-for-woocommerce'),
                'request' => $params,
                'raw'     => $result['raw'],
            );
            break;

        case 'register_duplicate':
            $params = $credentials + array(
                'orderNumber' => 'TEST-DUPLICATE-ORDER',
                'amount'      => 10000,
                'currency'    => 788,
                'returnUrl'   => home_url('/?cfw_ctp_test=success'),
                'failUrl'     => home_url('/?cfw_ctp_test=fail'),
                'language'    => 'fr',
            );
            $first   = $gateway->api_request('register.do', $params, 'TEST duplicate order number (1)');
            $second  = $gateway->api_request('register.do', $params, 'TEST duplicate order number (2)');
            $calls[] = array(
                'label'   => __('Duplicate order number — first call', 'clictopay-for-woocommerce'),
                'request' => $params,
                'raw'     => $first['raw'],
            );
            $calls[] = array(
                'label'   => __('Duplicate order number — second call with the same orderNumber', 'clictopay-for-woocommerce'),
                'request' => $params,
                'raw'     => $second['raw'],
            );
            break;

        case 'status_unknown_order':
            $params = $credentials + array(
                'orderId'  => '00000000-0000-0000-0000-000000000000',
                'language' => 'fr',
            );
            $result  = $gateway->api_request('getOrderStatusExtended.do', $params, 'TEST unknown order id');
            $calls[] = array(
                'label'   => __('Unknown order — getOrderStatusExtended.do with an unknown orderId', 'clictopay-for-woocommerce'),
                'request' => $params,
                'raw'     => $result['raw'],
            );
            break;

        case 'status':
            $order_id = isset($input['order_id']) ? $input['order_id'] : '';
            if ('' === $order_id) {
                break;
            }
            $result  = $gateway->get_order_status_extended($order_id);
            $calls[] = array(
                'label'   => sprintf(__('getOrderStatusExtended.do for orderId %s', 'clictopay-for-woocommerce'), $order_id),
                'request' => array('orderId' => $order_id),
                'raw'     => $result['raw'],
            );
            break;
    }

    return $calls;
}

function cfw_ctp_render_diagnostics_page() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('You are not allowed to run these tests.', 'clictopay-for-woocommerce'));
    }

    $gateway = cfw_ctp_get_gateway();
    $calls   = array();
    $test    = '';

    if (isset($_POST['cfw_ctp_test'])) {
        check_admin_referer('cfw_ctp_run_test');
        $test = sanitize_key(wp_unslash($_POST['cfw_ctp_test']));

        if (!$gateway) {
            add_settings_error('cfw_ctp', 'no_gateway', __('The ClicToPay gateway is not available.', 'clictopay-for-woocommerce'));
        } elseif ('' === trim((string) $gateway->get_option('username', '')) || '' === trim((string) $gateway->get_option('password', ''))) {
            add_settings_error(
                'cfw_ctp',
                'no_credentials',
                sprintf(
                    /* translators: %s: link to the gateway settings screen. */
                    __('Set the ClicToPay API user and password in the %s first, then save.', 'clictopay-for-woocommerce'),
                    '<a href="' . esc_url(cfw_ctp_settings_url()) . '">' . esc_html__('gateway settings', 'clictopay-for-woocommerce') . '</a>'
                )
            );
        } else {
            $input = array(
                'order_id' => isset($_POST['cfw_ctp_order_id']) ? sanitize_text_field(wp_unslash($_POST['cfw_ctp_order_id'])) : '',
            );
            $calls = cfw_ctp_run_diagnostic($test, $gateway, $input);
        }
    }

    $mode = $gateway && 'yes' === $gateway->get_option('testmode')
        ? __('TEST (test.clictopay.com)', 'clictopay-for-woocommerce')
        : __('PRODUCTION (ipay.clictopay.com)', 'clictopay-for-woocommerce');

    $buttons = array(
        'register_valid' => __('Run valid order registration', 'clictopay-for-woocommerce'),
        'register_missing_amount' => __('Run missing parameter', 'clictopay-for-woocommerce'),
        'register_duplicate' => __('Run duplicate order number', 'clictopay-for-woocommerce'),
        'status_unknown_order' => __('Run unknown order id', 'clictopay-for-woocommerce'),
    );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('ClicToPay — integration tests', 'clictopay-for-woocommerce'); ?></h1>
        <?php settings_errors('cfw_ctp'); ?>

        <?php
        $username = $gateway ? trim((string) $gateway->get_option('username', '')) : '';
        $password = $gateway ? trim((string) $gateway->get_option('password', '')) : '';
        $missing  = __('not set', 'clictopay-for-woocommerce');
        ?>
        <table class="widefat striped" style="max-width:640px;margin-bottom:1em;">
            <tbody>
                <tr>
                    <td><?php esc_html_e('Environment', 'clictopay-for-woocommerce'); ?></td>
                    <td><strong><?php echo esc_html($mode); ?></strong></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('API user', 'clictopay-for-woocommerce'); ?></td>
                    <td><strong><?php echo '' !== $username ? esc_html($username) : '<span style="color:#b32d2e;">' . esc_html($missing) . '</span>'; ?></strong></td>
                </tr>
                <tr>
                    <td><?php esc_html_e('API password', 'clictopay-for-woocommerce'); ?></td>
                    <td>
                        <strong>
                        <?php
                        echo '' !== $password
                            ? esc_html(sprintf(__('set (%d characters)', 'clictopay-for-woocommerce'), strlen($password)))
                            : '<span style="color:#b32d2e;">' . esc_html($missing) . '</span>';
                        ?>
                        </strong>
                    </td>
                </tr>
            </tbody>
        </table>
        <p>
            <a class="button" href="<?php echo esc_url(cfw_ctp_settings_url()); ?>">
                <?php esc_html_e('Open gateway settings', 'clictopay-for-woocommerce'); ?>
            </a>
        </p>
        <p class="description">
            <?php esc_html_e('Accepted payment, refused payment and direct access to the return URL are covered by a real checkout. Use the lookup below to fetch the JSON of those orders, or read WooCommerce > Status > Logs (source: clictopay).', 'clictopay-for-woocommerce'); ?>
        </p>

        <form method="post">
            <?php wp_nonce_field('cfw_ctp_run_test'); ?>
            <p>
                <?php foreach ($buttons as $value => $label) : ?>
                    <button type="submit" class="button button-primary" name="cfw_ctp_test" value="<?php echo esc_attr($value); ?>">
                        <?php echo esc_html($label); ?>
                    </button>
                <?php endforeach; ?>
            </p>
        </form>

        <hr />

        <h2><?php esc_html_e('Order status lookup', 'clictopay-for-woocommerce'); ?></h2>
        <form method="post">
            <?php wp_nonce_field('cfw_ctp_run_test'); ?>
            <p>
                <input type="text" class="regular-text" name="cfw_ctp_order_id"
                       placeholder="<?php esc_attr_e('ClicToPay orderId (UUID)', 'clictopay-for-woocommerce'); ?>"
                       value="<?php echo isset($_POST['cfw_ctp_order_id']) ? esc_attr(wp_unslash($_POST['cfw_ctp_order_id'])) : ''; ?>" />
                <button type="submit" class="button" name="cfw_ctp_test" value="status">
                    <?php esc_html_e('Call getOrderStatusExtended.do', 'clictopay-for-woocommerce'); ?>
                </button>
            </p>
            <p class="description">
                <?php esc_html_e('The orderId is stored on each order as the _cfw_ctp_order_id meta and written in the order notes.', 'clictopay-for-woocommerce'); ?>
            </p>
        </form>

        <?php if ($calls) : ?>
            <hr />
            <h2><?php esc_html_e('Results', 'clictopay-for-woocommerce'); ?></h2>
            <?php foreach ($calls as $index => $call) :
                $request = $call['request'];
                if (isset($request['password'])) {
                    $request['password'] = '***';
                }
                ?>
                <h3><?php echo esc_html($call['label']); ?></h3>
                <p><code><?php echo esc_html(wp_json_encode($request)); ?></code></p>
                <textarea readonly rows="10" style="width:100%;font-family:monospace;"
                          onclick="this.select();"><?php echo esc_textarea($call['raw']); ?></textarea>
                <p class="description"><?php esc_html_e('Click to select, then paste the raw JSON into your integration validation grid.', 'clictopay-for-woocommerce'); ?></p>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php
}
