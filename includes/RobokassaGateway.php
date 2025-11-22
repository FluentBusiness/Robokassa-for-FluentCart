<?php
/**
 * Класс шлюза Robokassa
 *
 * @package RobokassaFluentCart
 * @since 1.0.0
 */


namespace RobokassaFluentCart;

if (!defined('ABSPATH')) {
    exit; // Прямой доступ не разрешен.
}

use FluentCart\App\Helpers\Helper;
use FluentCart\App\Helpers\Status;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Payments\PaymentInstance;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use RobokassaFluentCart\Settings\RobokassaSettingsBase;
use RobokassaFluentCart\Subscriptions\RobokassaSubscriptions;
use RobokassaFluentCart\Refund\RobokassaRefund;

class RobokassaGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'robokassa';

    public array $supportedFeatures = [
        'payment',
        'refund',
        'webhook',
        'subscriptions'
    ];

    public function __construct()
    {
        parent::__construct(
            new RobokassaSettingsBase(),
            new RobokassaSubscriptions()
        );
    }

    public function meta(): array
    {
        $logo = ROBOKASSA_FC_PLUGIN_URL . 'assets/images/robokassa-logo.svg';
        
        return [
            'title'              => __('Robokassa', 'robokassa-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Robokassa',
            'admin_title'        => 'Robokassa',
            'description'        => __('Pay securely with Robokassa - Card, Bank Transfer, USSD, and more', 'robokassa-for-fluent-cart'),
            'logo'               => $logo,
            'tag' => 'beta',
            'icon'               => $logo,
            'brand_color'        => '#00C3F7',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        // Инициализация обработчика IPN
        (new Webhook\RobokassaWebhook())->init();
        
        add_filter('fluent_cart/payment_methods/robokassa_settings', [$this, 'getSettings'], 10, 2);

        (new Confirmations\RobokassaConfirmations())->init();
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        $paymentArgs = [
            'success_url' => $this->getSuccessUrl($paymentInstance->transaction),
            'cancel_url'  => $this->getCancelUrl(),
        ];

        if ($paymentInstance->subscription) {
            return (new Subscriptions\RobokassaSubscriptions())->handleSubscription($paymentInstance, $paymentArgs);
        }

        return (new Onetime\RobokassaProcessor())->handleSinglePayment($paymentInstance, $paymentArgs);
    }

    public function getOrderInfo($data)
    {
        RobokassaHelper::checkCurrencySupport();

        $publicKey = (new Settings\RobokassaSettingsBase())->getPublicKey();

        wp_send_json([
            'status'       => 'success',
            'message'      => __('Order info retrieved!', 'robokassa-for-fluent-cart'),
            'payment_args' => [
                'public_key' => $publicKey

            ],
        ], 200);
    }


    public function handleIPN(): void
    {
        (new Webhook\RobokassaWebhook())->verifyAndProcess();
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'robokassa-fluent-cart-checkout-handler',
                'src'    => ROBOKASSA_FC_PLUGIN_URL . 'assets/robokassa-checkout.js',
                'version' => ROBOKASSA_FC_VERSION
            ]
        ];
    }

    public function getEnqueueStyleSrc(): array
    {
        return [];
    }

    public function getLocalizeData(): array
    {
        return [
            'fct_robokassa_data' => [
                'public_key' => $this->settings->getPublicKey(),
                'translations' => [
                    'Processing payment...' => __('Processing payment...', 'robokassa-for-fluent-cart'),
                    'Pay Now' => __('Pay Now', 'robokassa-for-fluent-cart'),
                    'Place Order' => __('Place Order', 'robokassa-for-fluent-cart'),
                ],
                'nonce' => wp_create_nonce('robokassa_fct_nonce')
            ]
        ];
    }

    public function webHookPaymentMethodName()
    {
        return $this->getMeta('route');
    }

    public function getTransactionUrl($url, $data): string
    {
        $transaction = Arr::get($data, 'transaction', null);
        if (!$transaction) {
            return 'https://dashboard.robokassa.ru/#/transactions';
        }

        $paymentId = $transaction->vendor_charge_id;

        if ($transaction->status === status::TRANSACTION_REFUNDED) {
            return 'https://dashboard.robokassa.ru/#/refunds/' . $paymentId;
        }

        return 'https://dashboard.robokassa.ru/#/transactions/' . $paymentId;
    }

    public function getSubscriptionUrl($url, $data): string
    {
        $subscription = Arr::get($data, 'subscription', null);
        if (!$subscription || !$subscription->vendor_subscription_id) {
            return 'https://dashboard.robokassa.ru/#/subscriptions';
        }

        return 'https://dashboard.robokassa.ru/#/subscriptions/' . $subscription->vendor_subscription_id;
    }

    public function processRefund($transaction, $amount, $args)
    {
        if (!$amount) {
            return new \WP_Error(
                'robokassa_refund_error',
                __('RobokassaRefund amount is required.', 'robokassa-for-fluent-cart')
            );
        }

        return (new RobokassaRefund())->processRemoteRefund($transaction, $amount, $args);

    }

    public function getWebhhoInstructions(): string
    { 
        $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=robokassa');
        $configureLink = 'https://dashboard.robokassa.ru/#/settings/developers';

        return sprintf(
            '<div>
                <p><b>%s</b><code class="copyable-content">%s</code></p>
                <p>%s</p>
            </div>',
            __('Webhook URL: ', 'robokassa-for-fluent-cart'),
            esc_html($webhook_url),
            sprintf(
                /* translators: %s: Robokassa Developer Settings link */
                __('Configure this webhook URL in your Robokassa Dashboard under Settings > Developers to receive payment notifications. You can access the <a href="%1$s" target="_blank">%2$s</a> here.', 'robokassa-for-fluent-cart'),
                esc_url($configureLink),
                __('Robokassa Developer Settings Page', 'robokassa-for-fluent-cart')
            )
        );

    }

    public function fields(): array
    {
        return [
            'notice' => [
                'value' => $this->renderStoreModeNotice(),
                'label' => __('Store Mode notice', 'robokassa-for-fluent-cart'),
                'type'  => 'notice'
            ],
            'payment_mode' => [
                'type'   => 'tabs',
                'schema' => [
                    [
                        'type'   => 'tab',
                        'label'  => __('Live credentials', 'robokassa-for-fluent-cart'),
                        'value'  => 'live',
                        'schema' => [
                            'live_public_key' => [
                                'value'       => '',
                                'label'       => __('Live Public Key', 'robokassa-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('pk_live_xxxxxxxxxxxxxxxx', 'robokassa-for-fluent-cart'),
                            ],
                            'live_secret_key' => [
                                'value'       => '',
                                'label'       => __('Live Secret Key', 'robokassa-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('sk_live_xxxxxxxxxxxxxxxx', 'robokassa-for-fluent-cart'),
                            ],
                        ]
                    ],
                    [
                        'type'   => 'tab',
                        'label'  => __('Test credentials', 'robokassa-for-fluent-cart'),
                        'value'  => 'test',
                        'schema' => [
                            'test_public_key' => [
                                'value'       => '',
                                'label'       => __('Test Public Key', 'robokassa-for-fluent-cart'),
                                'type'        => 'text',
                                'placeholder' => __('pk_test_xxxxxxxxxxxxxxxx', 'robokassa-for-fluent-cart'),
                            ],
                            'test_secret_key' => [
                                'value'       => '',
                                'label'       => __('Test Secret Key', 'robokassa-for-fluent-cart'),
                                'type'        => 'password',
                                'placeholder' => __('sk_test_xxxxxxxxxxxxxxxx', 'robokassa-for-fluent-cart'),
                            ],
                        ],
                    ],
                ]
            ],
            'webhook_info' => [
                'value' => $this->getWebhhoInstructions(),
                'label' => __('Webhook Configuration', 'robokassa-for-fluent-cart'),
                'type'  => 'html_attr'
            ],
        ];
    }

    public static function validateSettings($data): array
    {
        return $data;
    }

    public static function beforeSettingsUpdate($data, $oldSettings): array
    {
        $mode = Arr::get($data, 'payment_mode', 'test');

        if ($mode == 'test') {
            $data['test_secret_key'] = Helper::encryptKey($data['test_secret_key']);
        } else {
            $data['live_secret_key'] = Helper::encryptKey($data['live_secret_key']);
        }

        return $data;
    }

    public static function register(): void
    {
        fluent_cart_api()->registerCustomPaymentMethod('robokassa', new self());
    }
}