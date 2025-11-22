<?php

namespace RobokassaFluentCart\Refund;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Services\Payments\PaymentHelper;
use FluentCart\Framework\Support\Arr;
use RobokassaFluentCart\API\RobokassaAPI;

class RobokassaRefund
{

    public static function processRemoteRefund($transaction, $amount, $args)
    {
        $robokassaTransactionId = $transaction->vendor_charge_id;

        if (!$robokassaTransactionId) {
            return new \WP_Error(
                'robokassa_refund_error',
                __('Payment ID not found for refund', 'robokassa-for-fluent-cart')
            );
        }

        $refundData = [
           'transaction' => $robokassaTransactionId,
           'amount' => $amount,
            'currency' => $transaction->currency
        ];

        if (!empty($args['note'])) {
            $refundData['merchant_note'] = $args['note'];
        }

        // Добавить причину как описание, если указано и нет заметки
        if (empty($args['note']) && !empty($args['reason'])) {
            $reasonMap = [
                'duplicate' => 'Duplicate payment',
                'fraudulent' => 'Fraudulent payment',
                'requested_by_customer' => 'Requested by customer'
            ];
            $refundData['merchant_note'] = $reasonMap[$args['reason']] ?? $args['reason'];
        }

        $refund = (new RobokassaAPI())->createRobokassaObject('refund', $refundData);

        if (is_wp_error($refund)) {
            return $refund;
        }

        if (Arr::get($refund, 'status') !== true) {
            return new \WP_Error('refund_failed', __('Refund could not be processed in Robokassa. Please check your Robokassa account', 'robokassa-for-fluent-cart'));
        }

        $status = Arr::get($refund, 'data.status');
        $acceptedStatus = ['pending', 'processing', 'processed'];

        if (!in_array($status, $acceptedStatus)) {
            return new \WP_Error('refund_failed', __('Refund could not be processed in Robokassa. Please check your Robokassa account', 'robokassa-for-fluent-cart'));
        }

        return Arr::get($refund, 'data.id');
    }

    public function refundMinimumAuthorizationAmount($transactionModel)
    {
        $amount = $transactionModel->total;

        $refundId = self::processRemoteRefund($transactionModel, $amount, [
            'note' => 'Refunded amount for authorization transaction'
        ]);

        if (is_wp_error($refundId)) {
            return $refundId;
        }

        $refundData = [
            'order_id' => $transactionModel->order_id,
            'order_type' => $transactionModel->order_type,
            'payment_method' => $transactionModel->payment_method,
            'payment_mode' => $transactionModel->payment_mode,
            'payment_method_type' => $transactionModel->payment_method_type,
            'transaction_type' => Status::TRANSACTION_TYPE_REFUND,
            'vendor_charge_id' => $refundId,
            'status' => Status::TRANSACTION_REFUNDED,
            'currency' => $transactionModel->currency,
            'total' => $amount,
            'meta' => [
                'parent_id' => $transactionModel->id,
                'reason' => 'Refunded amount for authorization transaction'
            ],
            'uuid' => md5(time() . wp_generate_uuid4())
        ];

        $createdRefund = OrderTransaction::query()->create($refundData);
        PaymentHelper::updateTransactionRefundedTotal($transactionModel, $createdRefund->total);

        fluent_cart_add_log(__('Robokassa Refund processed', 'robokassa-for-fluent-cart'), __('Refund processed for authorization transaction', 'robokassa-for-fluent-cart'), 'info', [
            'module_name' => 'order',
            'module_id'   => $transactionModel->order_id,
        ]);

        return $createdRefund;
    }

    public static function createOrUpdateIpnRefund($refundData, $parentTransaction)
    {
        $allRefunds = OrderTransaction::query()
            ->where('order_id', $refundData['order_id'])
            ->where('transaction_type', Status::TRANSACTION_TYPE_REFUND)
            ->orderBy('id', 'DESC')
            ->get();

        if ($allRefunds->isEmpty()) {
            // Это первый возврат для этого заказа
            $createdRefund = OrderTransaction::query()->create($refundData);
            return $createdRefund instanceof OrderTransaction ? $createdRefund : null;
        }

        $currentRefundRobokassaId = Arr::get($refundData, 'vendor_charge_id', '');

        $existingLocalRefund = null;
        foreach ($allRefunds as $refund) {
            if ($refund->vendor_charge_id == $refundData['vendor_charge_id']) {
                if ($refund->total != $refundData['total']) {
                    $refund->fill($refundData);
                    $refund->save();
                }
                // Этот возврат уже существует
                return $refund;
            }

            if (!$refund->vendor_charge_id) { // Это локальный возврат без идентификатора вендора
                $refundRobokassaId = Arr::get($refund->meta, 'robokassa_refund_id', '');
                $isRefundMatched = $refundRobokassaId == $currentRefundRobokassaId;

                // Это локальный возврат без идентификатора вендора, мы обновим его
                if ($refund->total == $refundData['total'] && $isRefundMatched) {
                    $existingLocalRefund = $refund;
                }
            }
        }

        if ($existingLocalRefund) {
            $existingLocalRefund->fill($refundData);
            $existingLocalRefund->save();
            return $existingLocalRefund;
        }

        $createdRefund = OrderTransaction::query()->create($refundData);
        PaymentHelper::updateTransactionRefundedTotal($parentTransaction, $createdRefund->total);

        return $createdRefund;
    }

}