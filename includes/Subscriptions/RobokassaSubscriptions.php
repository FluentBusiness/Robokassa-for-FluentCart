<?php

namespace RobokassaFluentCart\Subscriptions;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Helpers\StatusHelper;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\Subscription;
use FluentCart\App\Models\Order;
use FluentCart\App\Modules\PaymentMethods\Core\AbstractSubscriptionModule;
use FluentCart\App\Events\Subscription\SubscriptionActivated;
use FluentCart\App\Modules\Subscriptions\Services\SubscriptionService;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use RobokassaFluentCart\API\RobokassaAPI;
use RobokassaFluentCart\RobokassaHelper;

class RobokassaSubscriptions extends AbstractSubscriptionModule
{
    public function handleSubscription($paymentInstance, $paymentArgs)
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;
        $subscription = $paymentInstance->subscription;

        $plan = self::getOrCreateRobokassaPlan($paymentInstance);

        if (is_wp_error($plan)) {
            return $plan;
        }

        $subscription->update([
            'vendor_plan_id' => Arr::get($plan, 'data.plan_code'),
        ]);

        $firstPayment = [
            'email'     => $fcCustomer->email,
            'amount'    => (int)$transaction->total,
            'currency'  => strtoupper($transaction->currency),
            'reference' => $transaction->uuid . '_' . time(),
            'metadata'  => [
                'robokassa_plan'    => Arr::get($plan, 'data.plan_code'),
                'order_hash'       => $order->uuid,
                'transaction_hash' => $transaction->uuid,
                'subscription_hash'=> $subscription->uuid,
                'customer_name'    => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
                'amount_is_for_authorization_only' => 'no'
            ]
        ];

        // первая оплата подписки; Robokassa требует, чтобы клиент уже совершил транзакцию для авторизации
        // подробности: https://paystack.com/docs/payments/recurring-charges/      
        if ($firstPayment['amount'] <= 0) {
            $firstPayment['amount'] = RobokassaHelper::getMinimumAmountForAuthorization($transaction->currency);
            $firstPayment['metadata']['amount_is_for_authorization_only'] = 'yes'; // мы вернем эту сумму позже после подтверждения
        }

        
        // Применение фильтров для настройки
        $firstPayment = apply_filters('fluent_cart/robokassa/subscription_payment_args', $firstPayment, [
            'order'       => $order,
            'transaction' => $transaction,
            'subscription' => $subscription
        ]);

        // Инициализация транзакции Robokassa
        $robokassaTransaction = RobokassaAPI::createRobokassaObject('transaction/initialize', $firstPayment);

        if (is_wp_error($robokassaTransaction)) {
           return $robokassaTransaction;
        }

        if ($robokassaTransaction['status'] !== true) {
            return new \WP_Error(
                'robokassa_initialization_failed',
                __('Failed to initialize Robokassa transaction.', 'robokassa-for-fluent-cart'),
                ['response' => $robokassaTransaction]
            );
        }

        return [
            'status'       => 'success',
            'nextAction'   => 'robokassa',
            'actionName'   => 'custom',
            'message'      => __('Opening Robokassa payment popup...', 'robokassa-for-fluent-cart'),
            'data'         => [
                'robokassa_data'    => $robokassaTransaction['data'],
                'intent'           => 'subscription',
                'transaction_hash' => $transaction->uuid,
            ]
        ];
    }

    public static function getOrCreateRobokassaPlan($paymentInstance)
    {
        $subscription = $paymentInstance->subscription;
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $variation = $subscription->variation;
        $product = $subscription->product;


        $interval = RobokassaHelper::mapIntervalToRobokassa($subscription->billing_interval);

        $billingPeriod = [
            'interval_unit' => $interval
        ];

        $billingPeriod = apply_filters('fluent_cart/subscription_billing_period', $billingPeriod, [
            'subscription_interval' => $subscription->billing_interval,
            'payment_method' => 'robokassa',
        ]);

        $fctRobokassaPlanId = 'fct_robokassa_recurring_plan_'
            . $order->mode . '_'
            . $product->id . '_'
            . $order->variation_id
            . $subscription->recurring_total . '_'
            . $subscription->billing_interval . '_'
            . $subscription->bill_times . '_'
            . $subscription->trial_days . '_'
            . $transaction->currency;

        // создание плана с именем, суммой, интервалом и т.д.
        $planData = [
            'name'              => $subscription->item_name,
            'description'       => $fctRobokassaPlanId,   
            'amount'            => (int)($subscription->recurring_total),
            'interval'          => Arr::get($billingPeriod, 'interval_unit'),
            'send_invoices'    => apply_filters('fluent_cart/robokassa/send_invoices_for_subscription_plan', true, [
                'subscription' => $subscription,
                'order'        => $order,
            ]),
            'send_sms'         => apply_filters('fluent_cart/robokassa/send_sms_for_subscription_plan', false, [
                'subscription' => $subscription,
                'order'        => $order,
            ])
        ];

        if ($subscription->bill_times) {
            $planData['invoice_limit'] = $subscription->bill_times;
        }

        $fctRobokassaPlanId = apply_filters('fluent_cart/robokassa_recurring_plan_id', $fctRobokassaPlanId, [
            'plan_data' => $planData,
            'variation' => $variation,
            'product'   => $product
        ]);

        // проверка, создан ли этот план уже в Robokassa - начало (не требуется, если мы могли бы передать что-то уникальное в Robokassa при создании плана)
        $robokassaPlanCode = $product->getProductMeta($fctRobokassaPlanId);

        if ($robokassaPlanCode) {
            $plan = RobokassaAPI::getRobokassaObject('plan/' . $robokassaPlanCode);
            if (!is_wp_error($plan) && Arr::get($plan, 'status') === true) {
                return $plan;
            }
        }
        // проверка, создан ли этот план уже в Robokassa - конец

        $plan = RobokassaAPI::createRobokassaObject('plan', $planData);

        if (is_wp_error($plan)) {
            return $plan;
        }

        // обновление ID плана Robokassa в метаданных продукта - для будущего использования
        $product->updateProductMeta($fctRobokassaPlanId, Arr::get($plan, 'data.plan_code'));

        return $plan;
    }

 
    public function reSyncSubscriptionFromRemote(Subscription $subscriptionModel)
    {
        if ($subscriptionModel->current_payment_method !== 'robokassa') {
            return new \WP_Error(
                'invalid_payment_method',
                __('This subscription is not using Robokassa as payment method.', 'robokassa-for-fluent-cart')
            );
        }

        $vendorSubscriptionId = $subscriptionModel->vendor_subscription_id;
        $order = $subscriptionModel->order;

        if (!$vendorSubscriptionId) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'robokassa-for-fluent-cart')
            );
        }

        $robokassaCustomerId = $subscriptionModel->vendor_customer_id;
        $robokassaPlanId = $subscriptionModel->vendor_plan_id;

        $robokassaSubscription = RobokassaAPI::getRobokassaObject('subscription/' . $vendorSubscriptionId);
        if (is_wp_error($robokassaSubscription)) {
            return $robokassaSubscription;
        }

        $authorizationCode = Arr::get($robokassaSubscription, 'data.authorization.authorization_code');
        $subscriptionUpdateData = RobokassaHelper::getSubscriptionUpdateData($robokassaSubscription, $subscriptionModel);
        // получить все транзакции для этого клиента, затем сопоставить транзакции с vendor_plan_id с plan_code транзакций
        $customerTransactions = [];

        $next = null;
        do{
            if ($next) {
                $transactions = RobokassaAPI::getRobokassaObject('transaction', [
                    'customer' => $robokassaCustomerId,
                    'next'     => $next
                ]);
            } else {
                $transactions = RobokassaAPI::getRobokassaObject('transaction', [
                    'customer' => $robokassaCustomerId
                ]);
            }

            if (is_wp_error($transactions)) {
                break;
            }
            $customerTransactions = [...$customerTransactions, ...Arr::get($transactions, 'data', [])];

            $next = Arr::get($transactions, 'meta.next',null);

        } while($next);



        $subscriptionTransactions = array_filter($customerTransactions, function($transaction) use ($authorizationCode) {
            return Arr::get($transaction, 'authorization.authorization_code') === $authorizationCode;
        });

        $subscriptionTransactions = array_reverse($subscriptionTransactions);


        $newPayment = false;
        foreach($subscriptionTransactions as $payment){
            $vendorChargeId = Arr::get($payment, 'id');

            if (Arr::get($payment, 'status') == 'success') {

                $amount = Arr::get($payment, 'amount');
                $methodType  = Arr::get($payment, 'authorization.payment_type');
                $cardLast4 =  Arr::get($payment, 'authorization.last4', null);
                $cardBrand = Arr::get($payment, 'authorization.brand', null);

                $transaction = OrderTransaction::query()->where('vendor_charge_id', $vendorChargeId)->first();

                if (!$transaction) {

                    $transaction = OrderTransaction::query()
                        ->where('subscription_id', $subscriptionModel->id)
                        ->where('vendor_charge_id', '')
                        ->where('transaction_type', Status::TRANSACTION_TYPE_CHARGE)
                        ->first();

                    if ($transaction) {
                        $transaction->update([
                            'vendor_charge_id'      => $vendorChargeId,
                            'status'                => Status::TRANSACTION_SUCCEEDED,
                            'total'                 => $amount,
                            'card_last_4'           => $cardLast4,
                            'card_brand'            => $cardBrand,
                            'payment_method_type'   => $methodType
                        ]);

                        (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);

                        continue;
                    }
                    // Создание новой транзакции
                    $transactionData = [
                        'order_id'         => $order->id,
                        'amount'           => $amount,
                        'currency'         => Arr::get($payment, 'currency'),
                        'vendor_charge_id' => $vendorChargeId,
                        'status'           => status::TRANSACTION_SUCCEEDED,
                        'payment_method'   => 'robokassa',
                        'transaction_type' => Status::TRANSACTION_TYPE_CHARGE,
                        'meta'             => Arr::get($payment, 'authorization', []),
                        'card_last_4'      => $cardLast4,
                        'card_brand'       => $cardBrand,
                        'created_at'       => DateTime::anyTimeToGmt(Arr::get($payment, 'paidAt'))->format('Y-m-d H:i:s'),
                    ];
                    $newPayment = true;
                    SubscriptionService::recordRenewalPayment($transactionData, $subscriptionModel, $subscriptionUpdateData);
                } else if ($transaction->status !== Status::TRANSACTION_SUCCEEDED) {
                    // Обновление существующей транзакции, если статус изменился
                    $transaction->update([
                        'status' => status::TRANSACTION_SUCCEEDED,
                    ]);

                    (new StatusHelper($transaction->order))->syncOrderStatuses($transaction);
                }
            }
        }

        // Обновление данных подписки
        if (!$newPayment) {
            $subscriptionModel = SubscriptionService::syncSubscriptionStates($subscriptionModel, $subscriptionUpdateData);
        } else {
            $subscriptionModel = Subscription::query()->find($subscriptionModel->id);
        }

        return $subscriptionModel;
    }

    /**
     * Создание подписки в Robokassa
     * @param Subscription $subscriptionModel
     * @param array $args , ожидает 'customer_code', 'plan_code', 'authorization_code', 'billingInfo'
     */
    public function createSubscriptionOnRobokassa($subscriptionModel, $args = [])
    {
        $order = $subscriptionModel->order;
        $startDate = null;
        $oldStatus = $subscriptionModel->status;

        // подписка клиента на план

        if ($subscriptionModel->trial_days > 0) {
            $startDate = time() + ($subscriptionModel->trial_days * DAY_IN_SECONDS);
        }

        $data = [
            'customer' => Arr::get($args, 'customer_code'),
            'plan' => Arr::get($args, 'plan_code'),
            'authorization' => Arr::get($args, 'authorization_code')
        ];

        if ($startDate) {
            $data['start_date'] =  DateTime::anytimeToGmt($startDate)->format('Y-m-d H:i:s');
        }

        $robokassaSubscription = RobokassaAPI::createRobokassaObject('subscription', $data);


        if (is_wp_error($robokassaSubscription)) {
            // запись сообщения об ошибке
            fluent_cart_add_log(__('Robokassa Subscription Creation Failed', 'robokassa-for-fluent-cart'), __('Failed to create subscription in Robokassa. Error: ', 'robokassa-for-fluent-cart')  . $robokassaSubscription->get_error_message(), 'error', [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]);
    
            return [];
        }

        
        $status = RobokassaHelper::getFctSubscriptionStatus(Arr::get($robokassaSubscription, 'data.status'));

        $updateData = [
            'vendor_subscription_id' => Arr::get($robokassaSubscription, 'data.subscription_code'),
            'status'                 => $status,
            'vendor_customer_id'     => Arr::get($args, 'customer_code'),
            'next_billing_date'      => Arr::get($robokassaSubscription, 'data.next_payment_date') ? DateTime::anyTimeToGmt(Arr::get($robokassaSubscription, 'data.next_payment_date'))->format('Y-m-d H:i:s') : null,
        ];

        $subscriptionModel->update($updateData);

        $subscriptionModel->updateMeta('active_payment_method', Arr::get($args, 'billingInfo', []));
        $subscriptionModel->updateMeta('robokassa_email_token', Arr::get($robokassaSubscription, 'data.email_token'));

        fluent_cart_add_log(__('Robokassa Subscription Created', 'robokassa-for-fluent-cart'), 'Subscription created on Robokassa. Code: ' . Arr::get($robokassaSubscription, 'data.subscription_code'), 'info', [
            'module_name' => 'order',
            'module_id'   => $order->id
        ]);


        if ($oldStatus != $subscriptionModel->status && (Status::SUBSCRIPTION_ACTIVE === $subscriptionModel->status || Status::SUBSCRIPTION_TRIALING === $subscriptionModel->status)) {
            (new SubscriptionActivated($subscriptionModel, $order, $order->customer))->dispatch();
        }



        return $updateData;
    }

    public function cancel($vendorSubscriptionId, $args = [])
    {
        $subscriptionModel = Subscription::query()
            ->where('vendor_subscription_id', $vendorSubscriptionId)
            ->first();

        if (!$subscriptionModel) {
            return new \WP_Error(
                'invalid_subscription',
                __('Invalid vendor subscription ID.', 'robokassa-for-fluent-cart')
            );
        }

        // Получение кода подписки и токена для отмены
        $subscriptionCode = $vendorSubscriptionId;
        $token = $subscriptionModel->getMeta('robokassa_email_token');

        if (!$token) {
            return new \WP_Error(
                'missing_token',
                __('Missing email token for subscription cancellation.', 'robokassa-for-fluent-cart')
            );
        }

        // Отключение подписки через API Robokassa
        $response = RobokassaAPI::deleteRobokassaObject('subscription/disable', [
            'code'  => $subscriptionCode,
            'token' => $token
        ]);

        if (is_wp_error($response)) {
            fluent_cart_add_log('Robokassa Subscription Cancellation Failed', $response->get_error_message(), 'error', [
                'module_name' => 'subscription',
                'module_id'   => $subscriptionModel->id,
            ]);
            return $response;
        }


        if (Arr::get($response, 'status') != true) {
            return new \WP_Error(
                'cancellation_failed',
                Arr::get($response, 'message', __('Failed to cancel subscription on Robokassa.', 'robokassa-for-fluent-cart'))
            );
        }

        // Обновление статуса подписки
        $subscriptionModel->update([
            'status'     => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
        ]);

        $orderId = $subscriptionModel->parent_order_id;
        if ($orderId) {
            $order = Order::query()->where('id', $orderId)->first();
        } 

        fluent_cart_add_log(
            __('Robokassa Subscription Cancelled', 'robokassa-for-fluent-cart'),
            __('Subscription cancelled on Robokassa. Code: ', 'robokassa-for-fluent-cart') . $subscriptionCode,
            'info',
            [
                'module_name' => 'order',
                'module_id'   => $order->id,
            ]
        );


        fluent_cart_add_log(
            __('Robokassa Subscription Cancelled', 'robokassa-for-fluent-cart'),
            __('Subscription cancelled on Robokassa. Code: ', 'robokassa-for-fluent-cart') . $subscriptionCode,
            'info',
            [
                'module_name' => 'subscription',
                'module_id'   => $subscriptionModel->id,
            ]
        );

        return [
            'status'      => Status::SUBSCRIPTION_CANCELED,
            'canceled_at' => DateTime::gmtNow()->format('Y-m-d H:i:s')
        ];
    }

}