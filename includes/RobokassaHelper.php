<?php

namespace RobokassaFluentCart;

use FluentCart\App\Helpers\Status;
use FluentCart\App\Services\DateTime\DateTime;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Models\Order;
use FluentCart\App\Models\OrderTransaction;
use FluentCart\App\Models\SubscriptionMeta;
use FluentCart\App\Models\Subscription;
use FluentCart\Api\CurrencySettings;

class RobokassaHelper
{
    public static function getRobokassaKeys()
    {
        $settings = (new Settings\RobokassaSettingsBase());

        $mode = $settings->getMode(); // 'test' or 'live'

        if ($mode === 'live') {
            $publicKey = $settings->get('live_public_key');
            $secretKey = $settings->get('live_secret_key');
        } else {
            $publicKey = $settings->get('test_public_key');
            $secretKey = $settings->get('test_secret_key');
        }

        return [
            'public_key' => trim($publicKey),
            'secret_key' => trim($secretKey),
        ];
    }

    public static function mapIntervalToRobokassa($interval)
    {
        $intervalMaps = [
            'daily'       => 'daily',
            'weekly'      => 'weekly',
            'monthly'     => 'monthly',
            'quarterly'   => 'quarterly',
            'half_yearly' => 'biannually',
            'yearly'      => 'annually',
        ];

        return $intervalMaps[$interval] ?? 'monthly';
    }

    public static function getFctSubscriptionStatus($status)
    {
        $statusMap = [
            'active'    => status::SUBSCRIPTION_ACTIVE,
            'inactive'  => status::SUBSCRIPTION_EXPIRED,
            'non-renewing' => status::SUBSCRIPTION_CANCELED,
            'cancelled' => status::SUBSCRIPTION_CANCELED,
            'paused'    => status::SUBSCRIPTION_PAUSED
        ];

        return $statusMap[$status] ?? 'active';
    }

    public static function getOrderFromTransactionHash($transactionHash)
    {
        $orderTransaction = OrderTransaction::query()
            ->where('uuid', $transactionHash)
            ->where('payment_method', 'robokassa')
            ->first();
            

        if ($orderTransaction) {
            return Order::query()->where('id', $orderTransaction->order_id)->first();
        }

        return null;
    }

    public static function getOrderFromEmailToken($emailToken)
    {
        $subscriptionId = SubscriptionMeta::query()
            ->where('meta_key', 'robokassa_email_token')
            ->where('meta_value', $emailToken)
            ->value('subscription_id');

        if ($subscriptionId) {
            $subscriptionModel = Subscription::query()
                ->where('id', $subscriptionId)
                ->first();

            if ($subscriptionModel) {
                return Order::query()->where('id', $subscriptionModel->parent_order_id)->first();
            }
        }

        return null;
    }

    public static function getMinimumAmountForAuthorization($currency)
    {
        $currency = strtoupper($currency);
        $minimumAmounts = [
            'NGN' => 50.00,
            'GHS' => 0.10,
            'ZAR' => 1.00,
            'KES' => 3.00,
            'USD' => 2.00
        ];

        return $minimumAmounts[$currency] * 100 ?? 100;
    }

    public static function getSubscriptionUpdateData($robokassaSubscription, $subscriptionModel)
    {
        $status = self::getFctSubscriptionStatus(Arr::get($robokassaSubscription, 'data.status'));

        $subscriptionUpdateData = array_filter([
            'current_payment_method' => 'robokassa',
            'status'                 => $status
        ]);

        // Обработка отмены
        if ($status === Status::SUBSCRIPTION_CANCELED) {
            $canceledAt = Arr::get($robokassaSubscription, 'canceledAt');
            if ($canceledAt) {
                $subscriptionUpdateData['canceled_at'] = DateTime::anyTimeToGmt($canceledAt)->format('Y-m-d H:i:s');
            } else {
                $subscriptionUpdateData['canceled_at'] = DateTime::gmtNow()->format('Y-m-d H:i:s');
            }
        }

        // Обработка даты следующего платежа
        $nextPaymentDate = Arr::get($robokassaSubscription, 'data.next_payment_date');
        if ($nextPaymentDate) {
            $subscriptionUpdateData['next_billing_date'] = DateTime::anyTimeToGmt($nextPaymentDate)->format('Y-m-d H:i:s');
        }

        return $subscriptionUpdateData;
    }


    public static function checkCurrencySupport()
    {
        $currency = CurrencySettings::get('currency');

        if (!in_array(strtoupper($currency), self::getRobokassaSupportedCurrency())) {
            wp_send_json([
                'status'  => 'failed',
                'message' => __('Robokassa does not support the currency you are using!', 'robokassa-for-fluent-cart')
            ], 422);
        }

    }

    public static function getRobokassaSupportedCurrency(): array
    {
        return ['NGN', 'GHS', 'ZAR', 'USD', 'XOF', 'KES'];
    }
}