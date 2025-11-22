<?php

namespace RobokassaFluentCart\Confirmations;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\Framework\Support\Arr;
use RobokassaFluentCart\API\RobokassaAPI;
use RobokassaFluentCart\Subscriptions\RobokassaSubscriptions;
use RobokassaFluentCart\Refund\RobokassaRefund;

class RobokassaConfirmations
{
    public function init()
    {
        add_action('wp_ajax_nopriv_fluent_cart_confirm_robokassa_payment', [$this, 'confirmRobokassaPayment']);
        add_action('wp_ajax_fluent_cart_confirm_robokassa_payment', [$this, 'confirmRobokassaPayment']);

        // не нужно, так как уже обрабатывается через пользовательское действие ajax в двух строках выше
        /*
         * параметры $data содержат
         *  - order_hash (uuid заказа)
         *  - trx_hash (uuid транзакции)
         *  - method (название шлюза)
         *  - is_receipt (yes/no), если 'yes', то мы на странице квитанции, а не на странице благодарности (подтверждать только во время отображения страницы благодарности)
         *
         * */
//        add_action('fluent_cart/before_render_redirect_page', [$this, 'maybeConfirmPaymentOnReturn']);

    }


    public function confirmRobokassaPayment()
    {
        
        if (isset($_REQUEST['robokassa_fct_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['robokassa_fct_nonce']));
            if (!wp_verify_nonce($nonce, 'robokassa_fct_nonce')) {
                wp_send_json([
                    'message' => 'Invalid nonce. Please refresh the page and try again.',
                    'status' => 'failed'
                ], 400);
            }
        } else {
            wp_send_json([
                'message' => 'Nonce is required for security verification.',
                'status' => 'failed'
            ], 400);
        }
        

        if (!isset($_REQUEST['trx_id'])) {
            wp_send_json([
                'message' => 'Transaction ID is required to confirm the payment.',
                'status' => 'failed'
            ], 400);
        }

        $robokassaTransactionId = sanitize_text_field(wp_unslash($_REQUEST['trx_id']) ?? '');
        
        // получить транзакцию из robokassa с использованием ссылки
        $robokassaTransaction = RobokassaAPI::getRobokassaObject('transaction/' . $robokassaTransactionId);

        if (is_wp_error($robokassaTransaction) || Arr::get($robokassaTransaction, 'status') !== true) {
            wp_send_json([
                'message' => $robokassaTransaction->get_error_message(),
                'status' => 'failed'
            ], 500);
        }

        $transactionMeta = Arr::get($robokassaTransaction, 'data.metadata', []);
        $transactionHash = Arr::get($transactionMeta, 'transaction_hash', '');

        // Найти транзакцию по UUID
        $transactionModel = null;

        if ($transactionHash) {
            $transactionModel = OrderTransaction::query()
                ->where('uuid', $transactionHash)
                ->where('payment_method', 'robokassa')
                ->first();
        }

        if (!$transactionModel) {
            wp_send_json([
                'message' => 'Transaction not found for the provided reference.',
                'status' => 'failed'
            ], 404);
        }

  
        // Проверить, если уже обработано
        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            wp_send_json([
                'redirect_url' => $transactionModel->getReceiptPageUrl(),
                'order' => [
                    'uuid' => $transactionModel->order->uuid,
                ],
                'message' => __('Payment already confirmed. Redirecting...!', 'robokassa-for-fluent-cart'),
                'status' => 'success'
            ], 200);
        }

        $data = Arr::get($robokassaTransaction, 'data');


        $robokassaPlan = Arr::get($transactionMeta, 'robokassa_plan', '');
        $subscriptionHash = Arr::get($transactionMeta, 'subscription_hash', '');
        $robokassaCustomer = Arr::get($data, 'customer.customer_code', []);
        $robokassaCustomerAuthorization = Arr::get($data, 'authorization.authorization_code', []);

        $billingInfo = [
            'type' => Arr::get($data, 'authorization.payment_type', 'card'),
            'last4' =>  Arr::get($data, 'authorization.last4'),
            'brand' => Arr::get($data, 'authorization.brand'),
            'payment_method_id' => Arr::get($data, 'authorization.authorization_code'),
            'payment_method_type' => Arr::get($data, 'authorization.channel'),
            'exp_month' => Arr::get($data, 'authorization.exp_month'),
            'exp_year' => Arr::get($data, 'authorization.exp_year')
        ];

        if ($robokassaPlan && $subscriptionHash) {
            $subscriptionModel = Subscription::query()
                ->where('uuid', $subscriptionHash)
                ->first();

            $updatedSubData  = [];

            if (!in_array($subscriptionModel->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
                $updatedSubData = (new RobokassaSubscriptions())->createSubscriptionOnRobokassa( $subscriptionModel, [
                    'customer_code' => $robokassaCustomer,
                    'authorization_code' => $robokassaCustomerAuthorization,
                    'plan_code' => $robokassaPlan,
                    'billing_info' => $billingInfo,
                    'is_first_payment_only_for_authorization' => Arr::get($transactionMeta, 'amount_is_for_authorization_only', 'no') === 'yes'
                ]);
            }


        }
        

        $this->confirmPaymentSuccessByCharge($transactionModel, [
            'vendor_charge_id' => $robokassaTransactionId,
            'charge' => $data,
            'subscription_data' => $updatedSubData ?? [],
            'billing_info' => $billingInfo
        ]);

        wp_send_json([
            'redirect_url' => $transactionModel->getReceiptPageUrl(),
            'order' => [
                'uuid' => $transactionModel->order->uuid,
            ],
            'message' => __('Payment confirmed successfully. Redirecting...!', 'robokassa-for-fluent-cart'),
            'status' => 'success'
        ], 200);
    }

    public function maybeConfirmPaymentOnReturn($data){
        return;
    }

    /**
     * Подтвердить успешный платеж и обновить транзакцию
     */
    public function confirmPaymentSuccessByCharge(OrderTransaction $transactionModel, $args = [])
    {
        $vendorChargeId = Arr::get($args, 'vendor_charge_id');
        $transactionData = Arr::get($args, 'charge');
        $subscriptionData = Arr::get($args, 'subscription_data', []);
        $billingInfo = Arr::get($args, 'billing_info', []);

        if ($transactionModel->status === Status::TRANSACTION_SUCCEEDED) {
            return;
        }

        $order = Order::query()->where('id', $transactionModel->order_id)->first();

        if (!$order) {
            return;
        }

        $amount = Arr::get($transactionData, 'amount', 0); // Robokassa возвращает сумму в копейках/центах
        $currency = Arr::get($transactionData, 'currency');
        $transactionMeta = Arr::get($args, 'charge.metadata', []);

        // Обновить транзакцию
        $transactionUpdateData = array_filter([
            'order_id' => $order->id,
            'total' => $amount,
            'currency' => $currency,
            'status' => Status::TRANSACTION_SUCCEEDED,
            'payment_method' => 'robokassa',
            'card_last_4' => Arr::get($billingInfo, 'last4', ''),
            'card_brand' => Arr::get($billingInfo, 'brand', ''),
            'payment_method_type' => Arr::get($billingInfo, 'payment_method_type', ''),
            'vendor_charge_id' => $vendorChargeId,
            'meta' => array_merge($transaction->meta ?? [], $billingInfo)
        ]);

        $transactionModel->fill($transactionUpdateData);
        $transactionModel->save();

        fluent_cart_add_log(__('Robokassa Payment Confirmation', 'robokassa-for-fluent-cart'), __('Payment confirmation received from Robokassa. Transaction ID:', 'robokassa-for-fluent-cart') . ' ' . $vendorChargeId, 'info', [
            'module_name' => 'order',
            'module_id' => $order->id,
        ]);

        // вернуть сумму, если она была только для авторизации
        if (Arr::get($transactionMeta, 'amount_is_for_authorization_only', 'no') == 'yes') {
            // вернуть сумму, так как она была только для авторизации
            $response = (new RobokassaRefund())->refundMinimumAuthorizationAmount($transactionModel);

            if (is_wp_error($response)) {
                fluent_cart_add_log('Refund failed of authorization amount', $response->get_error_message(), 'error', [
                    'module_name' => 'order',
                    'module_id'   => $transactionModel->order_id,
                ]);
            }
        }

        if ($order->type == status::ORDER_TYPE_RENEWAL) {
            $subscriptionModel = Subscription::query()->where('id', $transactionModel->subscription_id)->first();


            if (!$subscriptionModel || !$subscriptionData) {
                return $order; // Подписка не найдена для этого заказа продления. Что-то не так.
            }
            return SubscriptionService::recordManualRenewal($subscriptionModel, $transactionModel, [
                'billing_info'      => $billingInfo,
                'subscription_args' => $subscriptionData
            ]);
        }

        return (new StatusHelper($order))->syncOrderStatuses($transactionModel);
    }
}