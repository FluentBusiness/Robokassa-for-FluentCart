# Руководство по интеграции платежного шлюза FluentCart

Исчерпывающее руководство для разработчиков третьих сторон по интеграции пользовательских платежных шлюзов с FluentCart

## Содержание

1. [Введение](#introduction)
2. [Установка и предварительные условия](#setup--prerequisites)
3. [Регистрация шлюза](#gateway-registration)
4. [Конфигурация полей настроек](#settings-fields-configuration)
5. [Отображение метода оплаты](#payment-method-rendering)
6. [Обработка оформления заказа](#checkout-processing)
7. [Обработка платежей](#payment-processing)
8. [Подтверждение платежа](#payment-confirmation)
9. [Обработка Webhook/IPN](#webhookipn-handling)
10. [Расширенные возможности](#advanced-features)
11. [Тестирование и отладка](#testing--debugging)
12. [Полный пример](#complete-example)

---

## Введение

FluentCart предоставляет гибкую архитектуру для интеграции пользовательских платежных шлюзов. Это руководство поможет вам создать плагин платежного шлюза, который без проблем интегрируется с процессом оформления заказа FluentCart, поддерживает различные потоки платежей и обрабатывает как одноразовые платежи, так и подписки.

### Что вы узнаете

- Как создать структуру плагина платежного шлюза
- Зарегистрировать ваш шлюз в FluentCart
- Настроить поля настроек для административной панели
- Обработать различные потоки оформления заказа (редирект, на сайте, всплывающее окно/модальное окно)
- Обработать платежи и подтверждения
- Реализовать обработчики webhook/IPN
- Поддерживать подписки и возвраты

---

## Установка и предварительные условия

### Предварительные условия

- WordPress 5.6+
- PHP 7.4+
- Плагин FluentCart (Free или Pro)
- Базовое понимание разработки плагинов WordPress
- Знакомство с API вашего платежного шлюза

### Структура плагина

Создайте плагин WordPress со следующей структурой:

```
your-gateway-for-fluent-cart/
├── your-gateway-for-fluent-cart.php    # Основной файл плагина
├── includes/
│   ├── YourGateway.php                 # Основной класс шлюза
│   ├── Settings/
│   │   └── YourGatewaySettings.php     # Управление настройками
│   ├── Processor/
│   │   └── PaymentProcessor.php        # Логика обработки платежей
│   ├── Webhook/
│   │   └── WebhookHandler.php          # Обработчик Webhook/IPN
│   ├── Confirmations/
│   │   └── PaymentConfirmations.php    # Обработчик подтверждения платежа
│   └── API/
│       └── ApiClient.php               # Коммуникация с API
├── assets/
│   ├── css/
│   ├── js/
│   │   └── checkout-handler.js         # Обработка оформления заказа на фронтенде
│   └── images/
│       └── gateway-logo.svg            # Логотип шлюза
├── languages/                          # Файлы перевода
└── README.md                           # Документация плагина
```

### Основной файл плагина

```php
<?php
/**
 * Plugin Name: Your Gateway for FluentCart
 * Plugin URI: https://yourwebsite.com
 * Description: Payment gateway integration for FluentCart
 * Version: 1.0.0
 * Author: Your Name
 * Text Domain: your-gateway-for-fluent-cart
 * Requires plugins: fluent-cart
 * Requires at least: 5.6
 * Tested up to: 6.8
 * Requires PHP: 7.4
 * License: GPLv2 or later
 */

// Prevent direct access
defined('ABSPATH') || exit('Direct access not allowed.');

// Define plugin constants
define('YOUR_GATEWAY_FC_VERSION', '1.0.0');
define('YOUR_GATEWAY_FC_PLUGIN_FILE', __FILE__);
define('YOUR_GATEWAY_FC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YOUR_GATEWAY_FC_PLUGIN_URL', plugin_dir_url(__FILE__));

// Check dependencies
function your_gateway_fc_check_dependencies() {
    if (!defined('FLUENTCART_VERSION')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Your Gateway for FluentCart</strong> requires FluentCart to be installed and activated.</p></div>';
        });
        return false;
    }
    return true;
}

// Initialize plugin
add_action('plugins_loaded', function() {
    if (!your_gateway_fc_check_dependencies()) {
        return;
    }

    // Autoloader
    spl_autoload_register(function ($class) {
        $prefix = 'YourGatewayFluentCart\\';
        $base_dir = YOUR_GATEWAY_FC_PLUGIN_DIR . 'includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    });

    // Register payment method
    add_action('fluent_cart/register_payment_methods', function() {
        \YourGatewayFluentCart\YourGateway::register();
    });
}, 20);
```

---

## Регистрация шлюза

### Основной класс шлюза

Создайте ваш основной класс шлюза, который расширяет `AbstractPaymentGateway`:

```php
<?php

namespace YourGatewayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use YourGatewayFluentCart\Settings\YourGatewaySettings;

class YourGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'your_gateway';

    public array $supportedFeatures = [
        'payment',          // Обработка базовых платежей
        'refund',          // Поддержка возвратов
        'webhook',         // Поддержка Webhook/IPN
        'subscriptions',   // Поддержка подписок
        'custom_payment'   // Пользовательский поток оформления заказа
    ];

    public function __construct()
    {
        parent::__construct(
            new YourGatewaySettings(),
            // new YourGatewaySubscriptions() // Необязательно для подписок
        );
    }

    public function meta(): array
    {
        $logo = YOUR_GATEWAY_FC_PLUGIN_URL . 'assets/images/gateway-logo.svg';
        
        return [
            'title'              => __('Your Gateway', 'your-gateway-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Your Gateway',
            'admin_title'        => 'Your Gateway',
            'description'        => __('Pay securely with Your Gateway', 'your-gateway-for-fluent-cart'),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#007cba',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        // Инициализировать компоненты
        (new Webhook\WebhookHandler())->init();
        (new Confirmations\PaymentConfirmations())->init();
    }

    // вызывается после размещения заказа для обработки платежа
    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        if ($paymentInstance->subscription) {
            // Обработать платежи подписки
            return (new Processor\SubscriptionProcessor())->handle($paymentInstance);
        }

        // Обработать одноразовые платежи
        return (new Processor\PaymentProcessor())->handle($paymentInstance);
    }

    public static function register()
    {
        fluent_cart_api()->registerCustomPaymentMethod('your_gateway', new self());
    }
}
```

---

## Конфигурация полей настроек

### Класс настроек

Класс настроек управляет конфигурацией вашего шлюза:

```php
<?php

namespace YourGatewayFluentCart\Settings;

use FluentCart\App\Modules\PaymentMethods\Core\BaseGatewaySettings;

class YourGatewaySettings extends BaseGatewaySettings
{
    public $methodHandler = 'fluent_cart_payment_settings_your_gateway';

    public static function getDefaults()
    {
        return [
            'is_active'       => 'no',
            'payment_mode'    => 'test',
            'test_api_key'    => '',
            'test_secret_key' => '',
            'live_api_key'    => '',
            'live_secret_key' => '',
            'webhook_secret'  => '',
            'checkout_mode'   => 'redirect', // redirect, onsite, popup
        ];
    }

    public function getApiKey($mode = null)
    {
        $mode = $mode ?: $this->get('payment_mode', 'test');
        return $this->get($mode . '_api_key');
    }

    public function getSecretKey($mode = null)
    {
        $mode = $mode ?: $this->get('payment_mode', 'test');
        return $this->get($mode . '_secret_key');
    }
}
```

### Определение полей настроек

Определите поля настроек администратора в вашем основном классе шлюза:

```php
public function fields(): array
{
    return [
        'notice' => [
            'type'  => 'notice',
            'value' => '<p>Configure your gateway settings below. Get your API keys from your gateway dashboard.</p>'
        ],
        
        'is_active' => [
            'type'    => 'enable',
            'label'   => __('Enable/Disable', 'your-gateway-for-fluent-cart'),
            'value'   => 'yes',
        ],

        'payment_mode' => [
            'type'   => 'tabs',
            'schema' => [
                [
                    'type'   => 'tab',
                    'label'  => __('Test Credentials', 'your-gateway-for-fluent-cart'),
                    'value'  => 'test',
                    'schema' => [
                        'test_api_key' => [
                            'type'        => 'password',
                            'label'       => __('Test API Key', 'your-gateway-for-fluent-cart'),
                            'placeholder' => __('Enter your test API key', 'your-gateway-for-fluent-cart'),
                            'value'       => '',
                        ],
                        'test_secret_key' => [
                            'type'        => 'password',
                            'label'       => __('Test Secret Key', 'your-gateway-for-fluent-cart'),
                            'placeholder' => __('Enter your test secret key', 'your-gateway-for-fluent-cart'),
                            'value'       => '',
                        ],
                    ]
                ],
                [
                    'type'   => 'tab',
                    'label'  => __('Live Credentials', 'your-gateway-for-fluent-cart'),
                    'value'  => 'live',
                    'schema' => [
                        'live_api_key' => [
                            'type'        => 'password',
                            'label'       => __('Live API Key', 'your-gateway-for-fluent-cart'),
                            'placeholder' => __('Enter your live API key', 'your-gateway-for-fluent-cart'),
                            'value'       => '',
                        ],
                        'live_secret_key' => [
                            'type'        => 'password',
                            'label'       => __('Live Secret Key', 'your-gateway-for-fluent-cart'),
                            'placeholder' => __('Enter your live secret key', 'your-gateway-for-fluent-cart'),
                            'value'       => '',
                        ],
                    ]
                ]
            ]
        ],

        'checkout_mode' => [
            'type'    => 'select',
            'label'   => __('Checkout Mode', 'your-gateway-for-fluent-cart'),
            'value'   => 'redirect',
            'options' => [
                'redirect' => [
                    'label' => __('Redirect to Gateway', 'your-gateway-for-fluent-cart'),
                    'value' => 'redirect'
                ],
                'onsite' => [
                    'label' => __('On-site Payment', 'your-gateway-for-fluent-cart'),
                    'value' => 'onsite'
                ],
                'popup' => [
                    'label' => __('Popup/Modal', 'your-gateway-for-fluent-cart'),
                    'value' => 'popup'
                ]
            ],
            'tooltip' => __('Choose how customers will interact with your payment gateway', 'your-gateway-for-fluent-cart')
        ],

        'webhook_info' => [
            'type'  => 'webhook_info',
            'mode'  => 'both', // or 'test'/'live'
            'info'  => $this->getWebhookInstructions()
        ]
    ];
}

private function getWebhookInstructions()
{
    $webhook_url = site_url('?fluent-cart=fct_payment_listener_ipn&method=your_gateway');
    
    return '<h4>Webhook Configuration</h4>
            <p>Configure webhooks in your gateway dashboard:</p>
            <p><strong>Webhook URL:</strong> <code>' . $webhook_url . '</code></p>
            <p><strong>Events to listen:</strong> payment.succeeded, payment.failed, subscription.created, etc.</p>';
}
```

### Доступные типы полей

На основе файла Renderer.vue поддерживаются следующие типы полей:

- `notice` - Отображение информационного текста
- `upcoming` - Показать баннер "Предстоящее"
- `provider` - Интеграция подключения аккаунта
- `enable` - Переключатель вкл/выкл
- `checkbox` - Один флажок
- `select` - Выпадающий список
- `radio` - Группа радиокнопок
- `webhook_info` - Отображение конфигурации webhook
- `input` - Текстовое поле ввода
- `password` - Поле ввода пароля
- `number` - Поле ввода числа
- `email` - Поле ввода email
- `text` - Текстовое поле ввода
- `color` - Выбор цвета
- `file` - Загрузка файла (медиа-библиотека)
- `checkbox_group` - Несколько флажков
- `html_attr` - Сырое содержимое HTML
- `active_methods` - Отображение активных методов оплаты
- `tabs` - Вкладки для группировки полей
- `radio-select-dependants` - Радио с зависимыми полями

---

Все типы полей поддерживают эти общие свойства:

| Свойство | Описание |
|----------|-------------|
| `type` | Обязательное. Определяет тип поля (см. доступные типы ниже) |
| `label` | Метка поля, отображаемая пользователю |
| `value` | Значение по умолчанию для поля |
| `placeholder` | Текст-заполнитель для полей ввода |
| `tooltip` | Краткая всплывающая подсказка, отображаемая при наведении |
| `description` | Краткое описание, отображаемое под полем |
| `max_length` | Максимальная длина текстового/вводимого/парольного поля |
| `disabled` | Отключено ли поле (логическое значение) |

## Отображение метода оплаты

### Вариант 1: Использование хука (рекомендуется)

Используйте хук `fluent_cart/checkout_embed_payment_method_content` для отображения пользовательских элементов оплаты:

```php
// В методе boot() вашего шлюза
add_action('fluent_cart/checkout_embed_payment_method_content', [$this, 'renderPaymentContent'], 10, 3);

public function renderPaymentContent($method_name, $order_data, $form_id)
{
    if ($method_name !== $this->methodSlug) {
        return;
    }

    $checkout_mode = $this->settings->get('checkout_mode', 'redirect');
    
    switch ($checkout_mode) {
        case 'onsite':
            $this->renderOnsiteForm($order_data);
            break;
        case 'popup':
            $this->renderPopupButton($order_data);
            break;
        case 'redirect':
        default:
            $this->renderRedirectNotice($order_data);
            break;
    }
}

private function renderOnsiteForm($order_data)
{
    echo '<div class="fluent-cart-your-gateway-form">
            <div id="your-gateway-card-element">
                <!-- Элементы ввода карты будут вставлены сюда с помощью JavaScript -->
            </div>
            <div id="your-gateway-errors" role="alert"></div>
          </div>';
}

private function renderPopupButton($order_data)
{
    echo '<div class="fluent-cart-your-gateway-popup">
            <button type="button" id="your-gateway-popup-btn" class="btn btn-primary">
                ' . __('Pay with Your Gateway', 'your-gateway-for-fluent-cart') . '
            </button>
          </div>';
}

private function renderRedirectNotice($order_data)
{
    echo '<div class="fluent-cart-your-gateway-redirect">
            <p>' . __('You will be redirected to Your Gateway to complete your payment.', 'your-gateway-for-fluent-cart') . '</p>
          </div>';
}
```

### Вариант 2: Пользовательский JavaScript

Подключите пользовательский JavaScript для продвинутых взаимодействий:

```php
// В основном классе вашего шлюза
public function getEnqueueScriptSrc($hasSubscription = 'no'): array
{
    return [
        [
            'handle' => 'your-gateway-sdk',
            'src'    => 'https://js.yourgateway.com/v3/',
        ],
        [
            'handle' => 'your-gateway-checkout',
            'src'    => YOUR_GATEWAY_FC_PLUGIN_URL . 'assets/js/checkout-handler.js',
            'deps'   => ['your-gateway-sdk'],
            'data'   => [
                'api_key'     => $this->settings->getApiKey(),
                'mode'        => $this->settings->get('payment_mode'),
                'ajax_url'    => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('your_gateway_nonce'),
                'translations' => [
                    'loading'        => __('Processing payment...', 'your-gateway-for-fluent-cart'),
                    'error'          => __('Payment failed. Please try again.', 'your-gateway-for-fluent-cart'),
                    'confirm'        => __('Confirm Payment', 'your-gateway-for-fluent-cart'),
                ]
            ]
        ]
    ];
}
```

---

## Обработка оформления заказа

### Класс процессора оплаты

Создайте процессор для обработки различных сценариев оформления заказа:

```php
<?php

namespace YourGatewayFluentCart\Processor;

use FluentCart\App\Services\Payments\PaymentInstance;
use YourGatewayFluentCart\Settings\YourGatewaySettings;
use YourGatewayFluentCart\API\ApiClient;

class PaymentProcessor
{
    private $settings;
    private $apiClient;

    public function __construct()
    {
        $this->settings = new YourGatewaySettings();
        $this->apiClient = new ApiClient($this->settings);
    }

    public function handle(PaymentInstance $paymentInstance)
    {
        $checkout_mode = $this->settings->get('checkout_mode', 'redirect');

        switch ($checkout_mode) {
            case 'redirect':
                return $this->handleRedirectCheckout($paymentInstance);
            case 'onsite':
                return $this->handleOnsiteCheckout($paymentInstance);
            case 'popup':
                return $this->handlePopupCheckout($paymentInstance);
            default:
                return $this->handleRedirectCheckout($paymentInstance);
        }
    }
}
```

### Реализация перенаправляющей обработки заказа

```php
private function handleRedirectCheckout(PaymentInstance $paymentInstance)
{
    $order = $paymentInstance->order;
    $transaction = $paymentInstance->transaction;

    // Создайте сессию оплаты с вашим шлюзом
    $paymentData = [
        'amount'      => $transaction->payment_total,
        'currency'    => $order->currency,
        'description' => "Order #{$order->id}",
        'metadata'    => [
            'order_id'       => $order->id,
            'transaction_id' => $transaction->id,
            'customer_email' => $order->customer->email
        ],
        'success_url' => $this->getSuccessUrl($transaction),
        'cancel_url'  => $this->getCancelUrl($order),
    ];

    $response = $this->apiClient->createCheckoutSession($paymentData);

    if (is_wp_error($response)) {
        return [
            'nextAction' => 'error',
            'status'     => 'failed',
            'message'    => $response->get_error_message()
        ];
    }

    return [
        'status'     => 'success',
        'message'    => __('Redirecting to payment gateway...', 'your-gateway-for-fluent-cart'),
        'redirect_to' => $response['checkout_url'],
    ];
}
```

### Реализация оплаты на сайте

```php
private function handleOnsiteCheckout(PaymentInstance $paymentInstance)
{
    $order = $paymentInstance->order;
    $transaction = $paymentInstance->transaction;

    // Создать намерение оплаты
    $intentData = [
        'amount'   => $transaction->payment_total,
        'currency' => $order->currency,
        'metadata' => [
            'order_id'       => $order->id,
            'transaction_id' => $transaction->id,
        ]
    ];

    $intent = $this->apiClient->createPaymentIntent($intentData);

    if (is_wp_error($intent)) {
        return [
            'nextAction' => 'error',
            'status'     => 'failed',
            'message'    => $intent->get_error_message()
        ];
    }

     // выполнить оплату через ваш шлюз и подтвердить
    return [
        'status'       => 'success',
        'redirect_to'  => $transaction->getReceiptPageUrl(),
    ]

    // или если у вас есть пользовательский JS-файл для обработки оплаты
    return [
        'status'       => 'success',
        'message'      => __('Please complete your payment details', 'your-gateway-for-fluent-cart'),
        'actionName'   => 'custom',
        'nextAction'   => 'your_gateway', // Это должно совпадать с вашим слагом шлюза
        'payment_args' => [
            'client_secret' => $intent['client_secret'],
            'api_key'       => $this->settings->getApiKey(),
            'intent_id'     => $intent['id'],
        ],
    ];
}
```

### Реализация всплывающего окна/модального окна

```php
private function handlePopupCheckout(PaymentInstance $paymentInstance)
{
    // Аналогично перенаправлению, но с обработкой всплывающего окна
    $order = $paymentInstance->order;
    $transaction = $paymentInstance->transaction;

    $modalData = [
        'amount'        => $transaction->payment_total,
        'currency'      => $order->currency,
        'customer_email' => $order->customer->email,
        'order_id'      => $order->id,
        'transaction_id' => $transaction->id,
    ];

    // выполнить оплату через ваш шлюз и подтвердить
    return [
        'status'       => 'success',
        'redirect_to'  => $transaction->getReceiptPageUrl(),
    ]

    // или если у вас есть пользовательский JS-файл для обработки оплаты
    return [
        'status'       => 'success',
        'message'      => __('Opening payment modal...', 'your-gateway-for-fluent-cart'),
        'actionName'   => 'custom',
        'nextAction'   => 'your_gateway',
        'payment_args' => [
            'modal_data' => $modalData,
            'api_key'    => $this->settings->getApiKey(),
        ],
    ];
}
```

---

## Обработка оплаты

### Фронтенд-обработчик JavaScript (опционально)

Создайте пользовательский JavaScript-файл для обработки фронтенд-взаимодействий:

```javascript
// assets/js/checkout-handler.js

class YourGatewayCheckout {
    constructor(form, orderHandler, response, paymentLoader) {
        this.form = form;
        this.orderHandler = orderHandler;
        this.response = response;
        this.paymentArgs = response?.payment_args || {};
        this.paymentLoader = paymentLoader;
        
        this.init();
    }

    init() {
        const actionName = this.response?.actionName;
        
        switch (actionName) {
            case 'custom':
                this.handleOnsitePayment();
                break;
            case 'popup':
                this.handlePopupPayment();
                break;
            default:
                console.log('Unknown action:', actionName);
        }
    }

    handleOnsitePayment() {
        // Инициализируйте SDK вашего шлюза
        const gateway = YourGateway(this.paymentArgs.api_key);
        
        // Создайте элементы оплаты
        const cardElement = gateway.elements().create('card');
        cardElement.mount('#your-gateway-card-element');

        // Обработайте отправку формы
        const submitButton = this.form.querySelector('.fluent_cart_pay_btn');
        submitButton.addEventListener('click', (e) => {
            e.preventDefault();
            this.processOnsitePayment(gateway, cardElement);
        });
    }

    async processOnsitePayment(gateway, cardElement) {
        this.paymentLoader.enableCheckoutButton(false);
        
        try {
            const { error, paymentMethod } = await gateway.createPaymentMethod({
                type: 'card',
                card: cardElement,
            });

            if (error) {
                this.showError(error.message);
                return;
            }

            // Подтвердите оплату на бэкенде
            const confirmResult = await this.confirmPayment({
                payment_method_id: paymentMethod.id,
                client_secret: this.paymentArgs.client_secret,
            });

            if (confirmResult.success) {
                this.orderHandler.redirectToSuccessPage(confirmResult.redirect_url);
            } else {
                this.showError(confirmResult.message);
            }
        } catch (error) {
            this.showError('Payment processing failed');
        } finally {
            this.paymentLoader.enableCheckoutButton(true);
        }
    }

    handlePopupPayment() {
        const popup = this.createPaymentPopup();
        popup.open(this.paymentArgs.modal_data);
        
        popup.on('success', (result) => {
            this.handlePaymentSuccess(result);
        });
        
        popup.on('error', (error) => {
            this.showError(error.message);
        });
    }

    async confirmPayment(paymentData) {
        const response = await fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'your_gateway_confirm_payment',
                nonce: your_gateway_data.nonce,
                ...paymentData
            })
        });

        return await response.json();
    }

    showError(message) {
        const errorElement = document.getElementById('your-gateway-errors');
        if (errorElement) {
            errorElement.textContent = message;
            errorElement.style.display = 'block';
        }
    }
}

// Зарегистрируйте в FluentCart
window.addEventListener("fluent_cart_load_payments_your_gateway", function (e) {
    new YourGatewayCheckout(
        e.detail.form, 
        e.detail.orderHandler, 
        e.detail.response, 
        e.detail.paymentLoader
    );
});
```

---

## Подтверждение оплаты

### Понимание потока заказов FluentCart

FluentCart имеет сложную систему обработки заказов, которая обрабатывает различные типы заказов. Понимание этого потока критически важно для правильной интеграции платежного шлюза.

#### Типы заказов

FluentCart различает разные типы заказов:

- **Обычные заказы** (`ORDER_TYPE_SINGLE`): Единоразовые покупки
- **Заказы с подпиской** (`ORDER_TYPE_SUBSCRIPTION`): Первоначальная настройка подписки
- **Заказы на продление** (`ORDER_TYPE_RENEWAL`): Продления подписки

#### Критические точки интеграции

1. **Обновление статуса транзакции**: Всегда обновляйте статус транзакции до обработки заказа
2. **Хранение информации о выставлении счета**: Сохраняйте данные о методе оплаты для будущих продлений
3. **Обработка типов заказов**: Разные типы заказов требуют разных сценариев завершения
4. **Генерация URL-адреса квитанции**: Правильное перенаправление после подтверждения оплаты

#### Важные замечания для разработчиков

- **Заказы на продление**: Эти заказы автоматически создаются FluentCart для продлений подписки. При обработке оплаты для заказов на продление необходимо использовать `SubscriptionService::recordManualRenewal()` вместо стандартного сценария завершения заказа.

- **Хранение информации о выставлении счета**: Всегда сохраняйте информацию о выставлении счета (данные карты, идентификаторы методов оплаты), так как она используется для будущих списаний по подписке и ссылок на клиента.

- **Синхронизация статусов**: Используйте `StatusHelper` для правильной синхронизации статусов заказов в зависимости от требований к выполнению продуктов (цифровые vs физические продукты).

- **URL-адреса квитанций**: Метод `getReceiptPageUrl()` предоставляет страницу успеха по умолчанию. Используйте фильтр `fluentcart/transaction/receipt_page_url` для настройки поведения перенаправления.

### Класс обработчика подтверждения

Создайте класс для обработки подтверждений оплаты:

```php
<?php

namespace YourGatewayFluentCart\Confirmations;

use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;

class PaymentConfirmations
{
    public function init()
    {
        add_action('wp_ajax_your_gateway_confirm_payment', [$this, 'confirmPayment']);
        add_action('wp_ajax_nopriv_your_gateway_confirm_payment', [$this, 'confirmPayment']);
        
        // Обработка перенаправления на страницу благодарности FluentCart
        add_action('fluent_cart/before_render_redirect_page', [$this, 'handleRedirectConfirmation'], 10, 1);
    }

    public function confirmPayment()
    {
        // Проверить nonce
        if (!wp_verify_nonce($_POST['nonce'], 'your_gateway_nonce')) {
            wp_send_json_error(['message' => 'Invalid request']);
        }

        $payment_method_id = sanitize_text_field($_POST['payment_method_id']);
        $client_secret = sanitize_text_field($_POST['client_secret']);

        // Подтвердить оплату через ваш шлюз
        $confirmation = $this->confirmWithGateway($payment_method_id, $client_secret);

        if (is_wp_error($confirmation)) {
            wp_send_json_error(['message' => $confirmation->get_error_message()]);
        }

        // Обновить статус транзакции
        $transaction = OrderTransaction::where('vendor_transaction_id', $confirmation['payment_id'])->first();
        
        if ($transaction) {
            $this->updateTransactionStatus($transaction, $confirmation);
            
            wp_send_json_success([
                'message' => 'Payment confirmed successfully',
                'redirect_url' => $this->getSuccessRedirectUrl($transaction)
            ]);
        }

        wp_send_json_error(['message' => 'Transaction not found']);
    }

    public function handleRedirectConfirmation($data)
    {
        // Проверить, была ли оплата успешной на основе параметров URL
        $payment_id = $_GET['payment_id'] ?? null;
        $status = $_GET['status'] ?? null;

        $transaction = OrderTransaction::where('vendor_transaction_id', $payment_id)->first();

        if ($payment_id && $status === 'success') {
            // Проверить оплату через ваш шлюз
            $payment = $this->verifyPaymentStatus($payment_id);
            
            if ($payment && $payment['status'] === 'succeeded') {
                $this->updateTransactionStatus($transaction, $payment);
            }
        }

      
        wp_send_json_success([
            'message' => 'Payment confirmed successfully',
            'redirect_url' => $this->getSuccessRedirectUrl($transaction)
        ]);
    }

     /**
     * Получить URL-адрес перенаправления на страницу успеха после подтверждения оплаты
     * Это место, куда будут перенаправлены клиенты после успешной оплаты
     */
    private function getSuccessRedirectUrl($transaction)
    {
        // Получить URL-адрес страницы квитанции по умолчанию из транзакции
        $receiptUrl = $transaction->getReceiptPageUrl();
        
        // getReceiptPageUrl - это фильтруемая функция, которая возвращает URL-адрес страницы квитанции по умолчанию

        // фильтр: 'fluentcart/transaction/receipt_page_url
        
    }

    private function updateTransactionStatus($transaction, $paymentData)
    {
        $transaction->update([
            'status' => Status::PAID,
            'vendor_transaction_id' => $paymentData['id'],
            'payment_note' => 'Payment completed via Your Gateway',
            'updated_at' => current_time('mysql')
        ]);

        // Обработать различные типы заказов (обычные заказы vs продления подписок)
        $order = $transaction->order;
        if ($order) {
            $this->handleOrderCompletion($order, $transaction, $paymentData);
        }
    }

    private function handleOrderCompletion($order, $transaction, $paymentData)
    {
        // Подготовить информацию о выставлении счета из данных оплаты
        $billingInfo = [
            'type' => $paymentData['payment_method_type'] ?? 'card',
            'last4' => $paymentData['last4'] ?? null,
            'brand' => $paymentData['brand'] ?? null,
            'payment_method_id' => $paymentData['payment_method_id'] ?? null,
        ];

        // Проверить, является ли это заказом на продление подписки
        if ($order->type == Status::ORDER_TYPE_RENEWAL) {
            $subscriptionModel = Subscription::query()->where('id', $transaction->subscription_id)->first();
            
            if ($subscriptionModel) {
                // Обработать продление подписки - это обновит статус подписки и цикл выставления счета
                $subscriptionData = $paymentData['subscription_data'] ?? [];
                
                return SubscriptionService::recordManualRenewal($subscriptionModel, $transaction, [
                    'billing_info' => $billingInfo,
                    'subscription_args' => $subscriptionData
                ]);
            }
        }

        // Обработать обычные заказы - это обновит статус заказа в зависимости от требований к выполнению
        $statusHelper = new StatusHelper($order);
        $statusHelper->syncOrderStatuses($transaction);
    }
}
```

### URL-адреса успеха/отмены

Реализуйте генераторы URL для потока оплаты:

```php
private function getSuccessUrl($transaction)
{
    return add_query_arg([
        'fct_redirect' => 'yes',
        'method' => 'your_gateway',
        'trx_hash' => $transaction->transaction_hash,
        'status' => 'success'
    ], site_url());
}

private function getCancelUrl($order)
{
    return add_query_arg([
        'fct_redirect' => 'yes',
        'method' => 'your_gateway',
        'status' => 'cancelled'
    ], fluent_cart_get_checkout_url());
}
```

---

## Обработка вебхуков/IPN

### Класс обработчика вебхуков

```php
<?php

namespace YourGatewayFluentCart\Webhook;

use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Helpers\Status;
use YourGatewayFluentCart\Settings\YourGatewaySettings;

class WebhookHandler
{
    private $settings;

    public function __construct()
    {
        $this->settings = new YourGatewaySettings();
    }

    public function init()
    {
        add_action('init', [$this, 'handleWebhook']);
    }

    public function handleWebhook()
    {
        if (!isset($_GET['fluent-cart']) || $_GET['fluent-cart'] !== 'fct_payment_listener_ipn') {
            return;
        }

        if (!isset($_GET['method']) || $_GET['method'] !== 'your_gateway') {
            return;
        }

        $this->processWebhook();
    }

    private function processWebhook()
    {
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_YOUR_GATEWAY_SIGNATURE'] ?? '';

        // Проверить подпись вебхука
        if (!$this->verifySignature($payload, $signature)) {
            http_response_code(400);
            exit('Invalid signature');
        }

        $event = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            exit('Invalid JSON');
        }

        $this->handleWebhookEvent($event);
    }

    private function verifySignature($payload, $signature)
    {
        $webhook_secret = $this->settings->get('webhook_secret');
        
        if (empty($webhook_secret)) {
            return false;
        }

        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);
        
        return hash_equals($expected_signature, $signature);
    }

    private function handleWebhookEvent($event)
    {
        $event_type = $event['type'] ?? '';
        
        switch ($event_type) {
            case 'payment.succeeded':
                $this->handlePaymentSucceeded($event['data']);
                break;
            case 'payment.failed':
                $this->handlePaymentFailed($event['data']);
                break;
            case 'subscription.created':
                $this->handleSubscriptionCreated($event['data']);
                break;
            case 'subscription.updated':
                $this->handleSubscriptionUpdated($event['data']);
                break;
            case 'subscription.cancelled':
                $this->handleSubscriptionCancelled($event['data']);
                break;
            default:
                // Зарегистрировать неизвестное событие
                error_log("Unknown webhook event: {$event_type}");
        }

        http_response_code(200);
        exit('OK');
    }

    private function handlePaymentSucceeded($payment_data)
    {
        $payment_id = $payment_data['id'];
        $metadata = $payment_data['metadata'] ?? [];
        
        $transaction = OrderTransaction::where('vendor_transaction_id', $payment_id)->first();
        
        if (!$transaction) {
            // Попробовать найти по ID заказа из метаданных
            if (!empty($metadata['transaction_id'])) {
                $transaction = OrderTransaction::find($metadata['transaction_id']);
            }
        }

        if ($transaction && $transaction->status !== Status::PAID) {
            // Обновить статус транзакции
            $transaction->update([
                'status' => Status::PAID,
                'payment_note' => 'Payment confirmed via webhook',
                'updated_at' => current_time('mysql')
            ]);

            // Подготовить информацию о выставлении счета для хранения
            $billingInfo = [
                'type' => $payment_data['payment_method_type'] ?? 'card',
                'last4' => $payment_data['last4'] ?? null,
                'brand' => $payment_data['brand'] ?? null,
                'payment_method_id' => $payment_data['payment_method_id'] ?? null,
            ];

            $order = $transaction->order;

            // Обработать различные типы заказов соответствующим образом
            if ($order->type == Status::ORDER_TYPE_RENEWAL) {
                // Это продление подписки - обработать иначе
                $subscriptionModel = Subscription::query()->where('id', $transaction->subscription_id)->first();
                
                if ($subscriptionModel) {
                    $subscriptionData = $payment_data['subscription_data'] ?? [];
                    
                    SubscriptionService::recordManualRenewal($subscriptionModel, $transaction, [
                        'billing_info' => $billingInfo,
                        'subscription_args' => $subscriptionData
                    ]);
                }
            } else {
                // Обычный заказ - синхронизировать статус в зависимости от требований к выполнению продукта
                $statusHelper = new StatusHelper($order);
                $statusHelper->syncOrderStatuses($transaction);
            }

            // Триггер событий успешной оплаты
            do_action('fluent_cart/payment_success', $order, $transaction);
        }
    }

    private function handlePaymentFailed($payment_data)
    {
        $payment_id = $payment_data['id'];
        
        $transaction = OrderTransaction::where('vendor_transaction_id', $payment_id)->first();
        
        if ($transaction) {
            $transaction->update([
                'status' => Status::FAILED,
                'payment_note' => 'Payment failed: ' . ($payment_data['failure_reason'] ?? 'Unknown reason'),
                'updated_at' => current_time('mysql')
            ]);

            do_action('fluent_cart/payment_failed', $transaction->order, $transaction);
        }
    }
}
```

---

## Дополнительные функции

### Поддержка подписок

```php
<?php

namespace YourGatewayFluentCart\Subscriptions;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Services\Payments\PaymentInstance;
use YourGatewayFluentCart\API\ApiClient;

class YourGatewaySubscriptions extends AbstractSubscriptionModule
{
    public function handleSubscription(PaymentInstance $paymentInstance, $args = [])
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;

        // Создать план подписки через ваш шлюз
        $plan_data = [
            'amount' => $subscription->recurring_amount,
            'currency' => $order->currency,
            'interval' => $this->mapInterval($subscription->billing_interval),
            'product_name' => $subscription->subscription_items[0]->post_title ?? 'Subscription'
        ];

        $plan = $this->createSubscriptionPlan($plan_data);

        if (is_wp_error($plan)) {
            return $plan;
        }

        // Создать подписку
        $subscription_data = [
            'plan_id' => $plan['id'],
            'customer_email' => $order->customer->email,
            'metadata' => [
                'order_id' => $order->id,
                'subscription_id' => $subscription->id
            ]
        ];

        return $this->createSubscription($subscription_data);
    }

    public function cancelSubscription($subscription, $args = [])
    {
        $vendor_subscription_id = $subscription->vendor_subscription_id;
        
        if (empty($vendor_subscription_id)) {
            return new \WP_Error('no_vendor_id', 'No vendor subscription ID found');
        }

        return (new ApiClient())->cancelSubscription($vendor_subscription_id);
    }

    private function mapInterval($fluentcart_interval)
    {
        $interval_map = [
            'day' => 'daily',
            'week' => 'weekly',
            'month' => 'monthly',
            'year' => 'yearly'
        ];

        return $interval_map[$fluentcart_interval] ?? 'monthly';
    }
}
```

### Поддержка возвратов

```php
<?php

namespace YourGatewayFluentCart\Refund;

class RefundProcessor
{
    public function processRefund($transaction, $amount, $args = [])
    {
        if (!$amount || $amount <= 0) {
            return new \WP_Error('invalid_amount', 'Invalid refund amount');
        }

        $vendor_transaction_id = $transaction->vendor_transaction_id;
        
        if (empty($vendor_transaction_id)) {
            return new \WP_Error('no_transaction_id', 'No vendor transaction ID found');
        }

        $refund_data = [
            'payment_id' => $vendor_transaction_id,
            'amount' => $amount,
            'reason' => $args['reason'] ?? 'Requested by merchant'
        ];

        $response = (new ApiClient())->createRefund($refund_data);

        if (is_wp_error($response)) {
            return $response;
        }

        // Обновить транзакцию с информацией о возврате
        $transaction->update([
            'refund_amount' => $amount,
            'refund_status' => 'processing',
            'refund_note' => 'Refund initiated: ' . $response['id']
        ]);

        return [
            'status' => 'success',
            'refund_id' => $response['id'],
            'message' => 'Refund processed successfully'
        ];
    }
}
```

---

## Тестирование и отладка

### Ведение журнала отладки

```php
// Добавить в ваш основной класс шлюза
private function log($message, $data = [])
{
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[Your Gateway] %s: %s',
            $message,
            wp_json_encode($data)
        ));
    }
}

// Использование
$this->log('Payment processing started', [
    'order_id' => $order->id,
    'amount' => $transaction->payment_total
]);
```

### Определение режима тестирования

```php
public function isTestMode()
{
    return $this->settings->get('payment_mode') === 'test';
}

public function getApiEndpoint()
{
    return $this->isTestMode() 
        ? 'https://api-sandbox.yourgateway.com' 
        : 'https://api.yourgateway.com';
}
```

### Вспомогательные функции проверки

```php
public static function validateSettings($data): array
{
    $mode = $data['payment_mode'] ?? 'test';
    $api_key = $data[$mode . '_api_key'] ?? '';
    $secret_key = $data[$mode . '_secret_key'] ?? '';

    if (empty($api_key) || empty($secret_key)) {
        return [
            'status' => 'failed',
            'message' => __('API keys are required', 'your-gateway-for-fluent-cart')
        ];
    }

    // Проверить подключение к API
    $api_client = new ApiClient(['api_key' => $api_key, 'secret_key' => $secret_key]);
    $test_result = $api_client->testConnection();

    if (is_wp_error($test_result)) {
        return [
            'status' => 'failed',
            'message' => $test_result->get_error_message()
        ];
    }

    return [
        'status' => 'success',
        'message' => __('Gateway credentials verified successfully!', 'your-gateway-for-fluent-cart')
    ];
}
```

---

## Полный пример

Вот как должен выглядеть ваш основной класс шлюза, когда всё собрано вместе:

```php
<?php

namespace YourGatewayFluentCart;

use FluentCart\App\Modules\PaymentMethods\Core\AbstractPaymentGateway;
use FluentCart\App\Services\Payments\PaymentInstance;
use YourGatewayFluentCart\Settings\YourGatewaySettings;
use YourGatewayFluentCart\Subscriptions\YourGatewaySubscriptions;

class YourGateway extends AbstractPaymentGateway
{
    private $methodSlug = 'your_gateway';

    public array $supportedFeatures = [
        'payment',
        'refund', 
        'webhook',
        'subscriptions',
        'custom_payment'
    ];

    public function __construct()
    {
        parent::__construct(
            new YourGatewaySettings(),
            new YourGatewaySubscriptions()
        );
    }

    public function meta(): array
    {
        $logo = YOUR_GATEWAY_FC_PLUGIN_URL . 'assets/images/gateway-logo.svg';
        
        return [
            'title'              => __('Your Gateway', 'your-gateway-for-fluent-cart'),
            'route'              => $this->methodSlug,
            'slug'               => $this->methodSlug,
            'label'              => 'Your Gateway',
            'admin_title'        => 'Your Gateway',
            'description'        => __('Pay securely with Your Gateway', 'your-gateway-for-fluent-cart'),
            'logo'               => $logo,
            'icon'               => $logo,
            'brand_color'        => '#007cba',
            'status'             => $this->settings->get('is_active') === 'yes',
            'upcoming'           => false,
            'supported_features' => $this->supportedFeatures,
        ];
    }

    public function boot()
    {
        (new Webhook\WebhookHandler())->init();
        (new Confirmations\PaymentConfirmations())->init();
        
        add_action('fluent_cart/checkout_embed_payment_method_content', [$this, 'renderPaymentContent'], 10, 3);
    }

    public function fields(): array
    {
        return [
            // ... поля настроек, как показано выше
        ];
    }

    public function makePaymentFromPaymentInstance(PaymentInstance $paymentInstance)
    {
        if ($paymentInstance->subscription) {
            return $this->subscriptionHandler->handleSubscription($paymentInstance);
        }

        return (new Processor\PaymentProcessor())->handle($paymentInstance);
    }

    public function processRefund($transaction, $amount, $args)
    {
        return (new Refund\RefundProcessor())->processRefund($transaction, $amount, $args);
    }

    public function getEnqueueScriptSrc($hasSubscription = 'no'): array
    {
        return [
            [
                'handle' => 'your-gateway-sdk',
                'src'    => 'https://js.yourgateway.com/v3/',
            ],
            [
                'handle' => 'your-gateway-checkout',
                'src'    => YOUR_GATEWAY_FC_PLUGIN_URL . 'assets/js/checkout-handler.js',
                'deps'   => ['your-gateway-sdk']
            ]
        ];
    }

    public static function validateSettings($data): array
    {
        // Логика проверки, как показано выше
    }

    public static function register()
    {
        fluent_cart_api()->registerCustomPaymentMethod('your_gateway', new self());
    }

    public function renderPaymentContent($method_name, $order_data, $form_id)
    {
        if ($method_name !== $this->methodSlug) {
            return;
        }
        
        // Отобразить соответствующий контент в зависимости от режима оформления заказа
    }
}
```

---

## Резюме

Это руководство охватывает всё, что вам нужно для создания всесторонней интеграции платежного шлюза для FluentCart:

1. **Настройка**: Структура плагина и регистрация
2. **Настройки**: Административная конфигурация с различными типами полей
3. **Отображение**: Несколько вариантов отображения форм оплаты
4. **Обработка**: Поддержка перенаправления, оплаты на сайте и всплывающих окон
5. **Подтверждение**: Обработка успешных/неудачных платежей
6. **Вебхуки**: Обновления статуса оплаты в реальном времени
7. **Дополнительно**: Подписки, возвраты и отладка

### Ключевые выводы

- Всегда расширяйте `AbstractPaymentGateway` для вашего основного класса
- Используйте хук `fluent_cart/register_payment_methods` для регистрации вашего шлюза
- Поддерживайте несколько сценариев оформления заказа (перенаправление, оплата на сайте, всплывающее окно) для гибкости
- Реализуйте надежную обработку вебхуков для надежного подтверждения платежей
- Включите всестороннюю обработку ошибок и ведение журнала
- Тщательно протестируйте перед выпуском

### Следующие шаги

1. Изучите существующую реализацию Paystack в этом плагине
2. Проверьте реализацию Paddle в FluentCart Pro для примеров оплаты на сайте
3. Ознакомьтесь с документацией по пользовательским методам оплаты [здесь](https://dev.fluentcart.com/payment-methods-integration/custom-payment-methods/)
4. Тщательно протестируйте свою реализацию перед выпуском

Для дополнительных примеров и продвинутых функций изучите существующие реализации платежных шлюзов в кодовой базе FluentCart.
