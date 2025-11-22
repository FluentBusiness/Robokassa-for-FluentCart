<?php

namespace RobokassaFluentCart\Onetime;

use FluentCart\App\Services\Payments\PaymentInstance;
use RobokassaFluentCart\API\RobokassaAPI;
use FluentCart\Framework\Support\Arr;

class RobokassaProcessor
{
    /**
     * Обработать разовый платеж
     */
    public function handleSinglePayment(PaymentInstance $paymentInstance, $paymentArgs = [])
    {
        $order = $paymentInstance->order;
        $transaction = $paymentInstance->transaction;
        $fcCustomer = $paymentInstance->order->customer;

        // Подготовить данные платежа для Robokassa
        $paymentData = [
            'amount'    => (int)($transaction->total), // Сумма в наименьшей единице валюты (копейки для RUB), центы для USD и т.д.
            'email'     => $fcCustomer->email,
            'currency'  => strtoupper($transaction->currency),
            'reference' => $transaction->uuid . '_' . time(),
            'metadata'  => [
                'order_id'         => $order->id,
                'order_hash'       => $order->uuid,
                'transaction_hash' => $transaction->uuid,
                'customer_name'    => $fcCustomer->first_name . ' ' . $fcCustomer->last_name,
            ]
        ];


        // Применить фильтры для настройки
        $paymentData = apply_filters('fluent_cart/robokassa/onetime_payment_args', $paymentData, [
            'order'       => $order,
            'transaction' => $transaction
        ]);

        // Инициализировать транзакцию Robokassa
        $robokassaTransaction = RobokassaAPI::createRobokassaObject('transaction/initialize', $paymentData);

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
                'intent'           => 'onetime',
                'transaction_hash' => $transaction->uuid,
            ]
        ];
    }

    public function getWebhookUrl()
    {
        return site_url('?fluent-cart=fct_payment_listener_ipn&method=robokassa');
    }
}