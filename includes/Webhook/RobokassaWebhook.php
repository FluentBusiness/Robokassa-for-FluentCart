<?php

namespace RobokassaFluentCart\Webhook;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Events\Order\OrderRefund;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Services\DateTime\DateTime;
use RobokassaFluentCart\Settings\RobokassaSettingsBase;
use RobokassaFluentCart\Confirmations\RobokassaConfirmations;
use RobokassaFluentCart\RobokassaHelper;
use RobokassaFluentCart\Subscriptions\RobokassaSubscriptions;
use RobokassaFluentCart\Refund\RobokassaRefund;

class RobokassaWebhook
{
    public function init()
    {
        add_action('fluent_cart/payments/robokassa/webhook_charge_success', [$this, 'handleChargeSuccess'], 10, 1); // готово
        add_action('fluent_cart/payments/robokassa/webhook_subscription_create', [$this, 'handleSubscriptionCreate'], 10, 1); // готово
        add_action('fluent_cart/payments/robokassa/webhook_subscription_not_renew', [$this, 'handleSubscriptionCanceled'], 10, 1);
        add_action('fluent_cart/payments/robokassa/webhook_refund_processed', [$this, 'handleRefundProcessed'], 10, 1); // готово

        add_action('fluent_cart/payments/robokassa/webhook_invoice_create', [$this, 'handleInvoiceCreate'], 10, 1); // не обрабатывается
        add_action('fluent_cart/payments/robokassa/webhook_invoice_update', [$this, 'handleInvoiceUpdate'], 10, 1); // готово

        // .... остальные обработчики вебхуков можно добавить здесь
    }

    /**
     * Проверить и обработать вебхук Robokassa
     */
    public function verifyAndProcess()
    {
        $payload = $this->getWebhookPayload();
        if (is_wp_error($payload)) {
            http_response_code(400);
            exit('Not valid payload');
        }

        $data = json_decode($payload, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            exit('Invalid JSON payload');
        }

        if (!$this->verifySignature($payload)) {
            http_response_code(401);
            exit('Invalid signature / Verification failed');
        }

        $order = $this->getFluenCartOrder($data);

        if (!$order) {
            http_response_code(404);
            exit('Order not found');
        }

        $event = str_replace('.', '_', Arr::get($data, 'event'));


        if (has_action('fluent_cart/payments/robokassa/webhook_' . $event)) {
            do_action('fluent_cart/payments/robokassa/webhook_' . $event, [
                'payload' => Arr::get($data, 'data'),
                'order' => $order
            ]);

            $this->sendResponse(200, 'Webhook processed successfully');
        }

        http_response_code(200);
        exit('Webhook not handled');
    }


    private function getWebhookPayload()
    {
        $input = file_get_contents('php://input');
        
        // Проверить размер полезной нагрузки (максимум 1 МБ)
        if (strlen($input) > 1048576) {
            return new \WP_Error('payload_too_large', 'Webhook payload too large');
        }
        
        if (empty($input)) {
            return new \WP_Error('empty_payload', 'Empty webhook payload');
        }
        
        return $input;
    }

    
    private function verifySignature($payload)
    {
        // Очистить входные данные сервера
        $signature = isset($_SERVER['HTTP_X_ROBOKASSA_SIGNATURE']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_ROBOKASSA_SIGNATURE'])) : '';
        
        if (!$signature) {
            return false;
        }

        $secretKey = (new RobokassaSettingsBase())->getSecretKey();
        
        if (!$secretKey) {
            return false;
        }
        
        $computedSignature = hash_hmac('sha512', $payload, $secretKey);

        return hash_equals($signature, $computedSignature);
    }


    public function handleChargeSuccess($data)
    {
       $robokassaTransaction = Arr::get($data, 'payload');
       $robokassaTransactionId = Arr::get($robokassaTransaction, 'id');

        $transactionMeta = Arr::get($robokassaTransaction, 'metadata', []);
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

        $subscriptionHash = Arr::get($transactionMeta, 'subscription_hash', '');
        $subscriptionModel = null;

        if ($subscriptionHash) {
            $subscriptionModel = Subscription::query()
                ->where('uuid', $subscriptionHash)
                ->first();
        }

        // Проверить, не была ли транзакция уже обработана
        if ($transactionModel->status == Status::TRANSACTION_SUCCEEDED) {
            wp_send_json([
                'redirect_url' => $transactionModel->getReceiptPageUrl(),
                'order' => [
                    'uuid' => $transactionModel->order->uuid,
                ],
                'message' => __('Payment already confirmed. Redirecting...!', 'robokassa-for-fluent-cart'),
                'status' => 'success'
            ], 200);
        }


        $robokassaPlan = Arr::get($transactionMeta, 'robokassa_plan', '');
        $robokassaCustomer = Arr::get($robokassaTransaction, 'customer.customer_code', []);
        $robokassaCustomerAuthorization = Arr::get($robokassaTransaction, 'authorization.authorization_code', []);

        $billingInfo = [
            'type' => Arr::get($robokassaTransaction, 'authorization.payment_type', 'card'),
            'last4' =>  Arr::get($robokassaTransaction, 'authorization.last4'),
            'brand' => Arr::get($robokassaTransaction, 'authorization.brand'),
            'payment_method_id' => Arr::get($robokassaTransaction, 'authorization.authorization_code'),
            'payment_method_type' => Arr::get($robokassaTransaction, 'authorization.channel'),
            'exp_month' => Arr::get($robokassaTransaction, 'authorization.exp_month'),
            'exp_year' => Arr::get($robokassaTransaction, 'authorization.exp_year')
        ];

        if ($robokassaPlan) {
            $updatedSubData = (new RobokassaSubscriptions())->createSubscriptionOnRobokassa( $subscriptionModel, [
                'customer_code' => $robokassaCustomer,
                'authorization_code' => $robokassaCustomerAuthorization,
                'plan_code' => $robokassaPlan,
                'billing_info' => $billingInfo,
                'is_first_payment_only_for_authorization' => Arr::get($transactionMeta, 'amount_is_for_authorization_only', 'no') === 'yes'
            ]);
        }


        (new RobokassaConfirmations())->confirmPaymentSuccessByCharge($transactionModel, [
            'vendor_charge_id' => $robokassaTransactionId,
            'charge' => $robokassaTransaction,
            'subscription_data' => $updatedSubData ?? [],
            'billing_info' => $billingInfo
        ]);

    }

    
    public function handleSubscriptionCreate($data)
    {
        $robokassaSubscription = Arr::get($data, 'payload');
        
        $order = Arr::get($data, 'order');

        $subscriptionModel = Subscription::query()
            ->where('parent_order_id', $order->id)
            ->first();

        
        if (!$subscriptionModel) {
            $this->sendResponse(200, 'No subscription found for the order, skipping subscription creation handling.');
        }

        if (!in_array($subscriptionModel->status, [Status::SUBSCRIPTION_ACTIVE, Status::SUBSCRIPTION_TRIALING])) {
            $this->sendResponse(200, 'Subscription is already processed, skipping subscription creation handling.');
        }

        $oldStatus = $subscriptionModel->status;


        $oldStatus = $subscriptionModel->status;
        $status = RobokassaHelper::getFctSubscriptionStatus(Arr::get($robokassaSubscription, 'status'));

        $updateData = [
            'vendor_subscription_id' => Arr::get($robokassaSubscription, 'subscription_code'),
            'status'                 => $status,
            'vendor_customer_id'     => Arr::get($robokassaSubscription, 'customer.customer_code'),
            'next_billing_date'      => Arr::get($robokassaSubscription, 'next_payment_date') ? DateTime::anyTimeToGmt(Arr::get($robokassaSubscription, 'next_payment_date'))->format('Y-m-d H:i:s') : null,
        ];


        $billingInfo = [
            'type' => Arr::get($robokassaSubscription, 'authorization.payment_type', 'card'),
            'last4' =>  Arr::get($robokassaSubscription, 'authorization.last4'),
            'brand' => Arr::get($robokassaSubscription, 'authorization.brand'),
            'payment_method_id' => Arr::get($robokassaSubscription, 'authorization.authorization_code'),
            'payment_method_type' => Arr::get($robokassaSubscription, 'authorization.channel'),
            'exp_month' => Arr::get($robokassaSubscription, 'authorization.exp_month'),
            'exp_year' => Arr::get($robokassaSubscription, 'authorization.exp_year')
        ];

        $subscriptionModel->update($updateData);

        $subscriptionModel->updateMeta('active_payment_method', $billingInfo);
        $subscriptionModel->updateMeta('robokassa_email_token', Arr::get($robokassaSubscription, 'email_token'));

        fluent_cart_add_log(__('Robokassa Subscription Created', 'robokassa-for-fluent-cart'), 'Subscription created on Robokassa. Code: ' . Arr::get($robokassaSubscription, 'subscription_code'), 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id
        ]);

        if ($oldStatus != $subscriptionModel->status && (Status::SUBSCRIPTION_ACTIVE === $subscriptionModel->status || Status::SUBSCRIPTION_TRIALING === $subscriptionModel->status)) {
            (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
        }

    }

   
    public function handleInvoiceCreate($data)
    {
        // Вы можете реализовать любую логику, необходимую при создании счёта.
        // Пока что мы просто регистрируем событие.
        // Реализовать при необходимости;
        // Например, отправить уведомление администратору или клиенту.
    }

    // Обработка оплаты счёта
    public function handleInvoiceUpdate($data)
    {
        $invoice = Arr::get($data, 'payload');
 
        if (Arr::get($invoice, 'status') === 'success' && Arr::get($invoice, 'paid') === true) {
            

            $subscriptionCode = Arr::get($invoice, 'subscription.subscription_code');

            $subscriptionModel = Subscription::query()
                ->where('vendor_subscription_id', $subscriptionCode)
                ->first();
            
            if (!$subscriptionModel || ($subscriptionModel->status === Status::SUBSCRIPTION_CANCELED || $subscriptionModel->status === Status::SUBSCRIPTION_COMPLETED || $subscriptionModel->status === Status::SUBSCRIPTION_EXPIRED)) {
                return false;
            }

            $subscriptionModel->reSyncFromRemote();

        }

    }

    public function handleSubscriptionCanceled($data)
    {

        $robokassaSubscription = Arr::get($data, 'payload');

        $robokassaSubscriptionCode = Arr::get($robokassaSubscription, 'subscription_code');

        $subscriptionModel = Subscription::query()
            ->where('vendor_subscription_id', $robokassaSubscriptionCode)
            ->first();

        
        
        if (!$subscriptionModel || ($subscriptionModel->status === Status::SUBSCRIPTION_CANCELED || $subscriptionModel->status === Status::SUBSCRIPTION_COMPLETED || $subscriptionModel->status === Status::SUBSCRIPTION_EXPIRED)) {
            return false;
        }

        $subscriptionModel->reSyncFromRemote();

        fluent_cart_add_log(__('Subscription Canceled', 'robokassa-for-fluent-cart'), 'Subscription cancellation received from Robokassa for subscription code: ' . $robokassaSubscriptionCode, 'info', [
            'module_name' => 'subscription',
            'module_id'   => $subscriptionModel->id
        ]);

        $this->sendResponse(200, 'Subscription cancellation processed successfully');

    }

    public function handleRefundProcessed($data)
    {
       $refund = Arr::get($data, 'payload');
       $order = Arr::get($data, 'order');
    
       $transactionReference = Arr::get($refund, 'transaction_reference');
       $transactionHash = explode('_', $transactionReference)[0];

       $parentTransaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'robokassa')
            ->first();


        if (!$parentTransaction) {
           $this->sendResponse(200, 'Parent transaction found, refund processing can be handled here.');
        }

        $currentCreatedRefund = null;

        $refundId = Arr::get($refund, 'id');
        $amount = Arr::get($refund, 'amount');
        $refundCurrency = Arr::get($refund, 'currency');

        // Подготовить данные о возврате, соответствующие формату Stripe
        $refundData = [
            'order_id'           => $order->id,
            'transaction_type'   => Status::TRANSACTION_TYPE_REFUND,
            'status'             => Status::TRANSACTION_REFUNDED,
            'payment_method'     => 'robokassa',
            'payment_mode'       => $parentTransaction->payment_mode,
            'vendor_charge_id'   => $refundId,
            'total'              => $amount,
            'currency'           => $refundCurrency,
            'meta'               => [
                'parent_id'          => $parentTransaction->id,
                'refund_description' => Arr::get($refund, 'description', ''),
                'refund_source'      => 'webhook'
            ]
        ];


        $syncedRefund = (new RobokassaRefund())->createOrUpdateIpnRefund($refundData, $parentTransaction);
        if ($syncedRefund->wasRecentlyCreated) {
            $currentCreatedRefund = $syncedRefund;
        }

        (new OrderRefund($order, $currentCreatedRefund))->dispatch();


    }

    public function getFluenCartOrder($data)
    {
        $order = null;

        $orderHash = Arr::get($data, 'data.metadata.order_hash');

        if ($orderHash) {
            $order = Order::query()->where('uuid', $orderHash)->first();
        }

        // Ссылка на транзакцию
        $transactionreference = Arr::get($data, 'data.transaction_reference');

        if ($transactionreference && !$order) {
            $referenceParts = explode('_', $transactionreference);
            $transactionHash = $referenceParts[0];

            $order = RobokassaHelper::getOrderFromTransactionHash($transactionHash);
        }

        $robokassaSubscriptionCode = Arr::get($data, 'data.subscription_code');
        if (!$robokassaSubscriptionCode) {
            $robokassaSubscriptionCode = Arr::get($data, 'data.subscription.subscription_code');
        }

        if ($robokassaSubscriptionCode && !$order) {
            $subscriptionModel = Subscription::query()
                ->where('vendor_subscription_id', $robokassaSubscriptionCode)
                ->first();
            
            if ($subscriptionModel) {
                $order = Order::query()->where('id', $subscriptionModel->parent_order_id)->first();
            }
        }

        $emailToken = Arr::get($data, 'data.email_token');


        if ($emailToken && !$order) {
            $order = RobokassaHelper::getOrderFromEmailToken($emailToken);
        }

        return $order;
    }

    protected function sendResponse($statusCode = 200, $message = 'Success')
    {
        http_response_code($statusCode);
        echo json_encode([
            'message' => $message,
        ]);

        exit;
    }
}