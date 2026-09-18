<?php

namespace App\Notifications;

use App\Models\DeliveryCashSettlement;
use App\Models\DeliveryExecutive;
use App\Models\User;

class DeliveryCashSettlementNotification extends BaseNotification
{
    public function __construct(
        protected DeliveryCashSettlement $settlement,
        protected string $eventType
    ) {
        $this->settlement->loadMissing(['deliveryExecutive', 'branch.restaurant', 'items']);
        $this->restaurant = $this->settlement->branch?->restaurant;
    }

    public function via($notifiable): array
    {
        $channels = [];

        if (!empty($notifiable->email)) {
            $channels[] = 'mail';
        }

        if ($notifiable instanceof User) {
            $channels[] = 'database';
        }

        return array_values(array_unique($channels));
    }

    public function toMail($notifiable)
    {
        $build = parent::build($notifiable);

        return $build
            ->subject($this->notificationTitle($notifiable))
            ->greeting(trim(__('app.hello') . ' ' . ($notifiable->name ?? '')) . ',')
            ->line($this->notificationMessage($notifiable))
            ->line(__('modules.delivery.settlementNumber') . ': ' . ($this->settlement->settlement_number ?? '--'))
            ->line(__('menu.deliveryExecutive') . ': ' . ($this->settlement->deliveryExecutive?->name ?? '--'))
            ->line(__('modules.delivery.orderCount') . ': ' . $this->settlement->items->count())
            ->line(__('modules.delivery.submittedAmount') . ': ' . currency_format((float) $this->settlement->submitted_amount, $this->settlement->branch?->restaurant?->currency_id))
            ->action(__('modules.delivery.viewSettlement'), $this->notificationUrl($notifiable));
    }

    public function toArray($notifiable): array
    {
        $message = $this->notificationMessage($notifiable);
        $url = $this->notificationUrl($notifiable);

        $this->sendPushNotification($notifiable, $message, $url);

        return [
            'restaurant_id' => $this->settlement->branch?->restaurant_id,
            'settlement_id' => $this->settlement->id,
            'message' => $message,
            'url' => $url,
            'created_at' => now()->toDateTimeString(),
            'user_name' => $notifiable->name ?? null,
        ];
    }

    protected function notificationTitle(object $notifiable): string
    {
        return match ($this->eventType) {
            'submitted' => __('modules.delivery.settlementSubmittedTitle'),
            'approved' => $notifiable instanceof DeliveryExecutive
                ? __('modules.delivery.settlementSettledTitle')
                : __('modules.delivery.settlementApprovedTitle'),
            'rejected' => __('modules.delivery.settlementRejectedTitle'),
            default => __('modules.delivery.codSettlement'),
        };
    }

    protected function notificationMessage(object $notifiable): string
    {
        $executiveName = $this->settlement->deliveryExecutive?->name ?? __('menu.deliveryExecutive');

        return match ($this->eventType) {
            'submitted' => $notifiable instanceof DeliveryExecutive
                ? __('modules.delivery.settlementSubmittedForReviewMessage', [
                    'settlement' => $this->settlement->settlement_number ?? '--',
                ])
                : __('modules.delivery.settlementSubmittedMessage', [
                    'settlement' => $this->settlement->settlement_number ?? '--',
                    'executive' => $executiveName,
                ]),
            'approved' => $notifiable instanceof DeliveryExecutive
                ? __('modules.delivery.settlementSettledMessage', [
                    'settlement' => $this->settlement->settlement_number ?? '--',
                ])
                : __('modules.delivery.settlementApprovedMessage', [
                    'settlement' => $this->settlement->settlement_number ?? '--',
                    'executive' => $executiveName,
                ]),
            'rejected' => __('modules.delivery.settlementRejectedMessage', [
                'settlement' => $this->settlement->settlement_number ?? '--',
            ]),
            default => __('modules.delivery.codSettlement'),
        };
    }

    protected function notificationUrl(object $notifiable): string
    {
        return $notifiable instanceof User
            ? route('delivery-executives.cash-monitoring')
            : route('delivery.cod-settlement');
    }
}
