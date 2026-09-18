<?php

namespace App\Notifications;

use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\NotificationSetting;
use App\Models\Restaurant;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;

class SendMenuPdf extends BaseNotification
{
    use Queueable;

    protected $menu;
    protected $menuItems;
    protected $settings;
    protected $notificationSetting;
    protected $pdfContent;
    protected $pdfFileName;

    /**
     * Create a new notification instance.
     *
     * @param Menu|null $menu
     * @param \Illuminate\Support\Collection|array|null $menuItems
     * @param string $pdfContent
     * @param string $pdfFileName
     * @param Restaurant|null $restaurant
     */
    public function __construct($menu = null, $menuItems = null, $pdfContent = '', $pdfFileName = 'menu.pdf', $restaurant = null)
    {
        $this->menu = $menu;
        $this->menuItems = $menuItems;
        $this->pdfContent = $pdfContent;
        $this->pdfFileName = $pdfFileName;

        // Get restaurant from menu, menu items, or passed parameter
        if ($restaurant) {
            $this->settings = $restaurant;
        } elseif ($menu && $menu->branch && $menu->branch->restaurant) {
            $this->settings = $menu->branch->restaurant;
        } elseif ($menuItems && $menuItems->isNotEmpty() && $menuItems->first()->branch && $menuItems->first()->branch->restaurant) {
            $this->settings = $menuItems->first()->branch->restaurant;
        } else {
            $this->settings = restaurant();
        }

        // Set restaurant for BaseNotification
        $this->restaurant = $this->settings;

        // Get notification setting if restaurant is available
        if ($this->settings && $this->settings->id) {
            $this->notificationSetting = NotificationSetting::where('type', 'menu_pdf_sent')
                ->where('restaurant_id', $this->settings->id)
                ->first();
        }
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        $channels = [];

        // Check if email should be sent
        if ($notifiable->email != '') {
            if ($this->notificationSetting && $this->notificationSetting->send_email == 1) {
                $channels[] = 'mail';
            } elseif (!$this->notificationSetting) {
                // If no notification setting exists, default to sending email
                $channels[] = 'mail';
            }
        }

        // Always include database channel to trigger toArray() for push notifications
        $channels[] = 'database';

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $menuName = $this->menu ? $this->menu->menu_name : __('modules.menu.menu');
        $restaurantName = $this->settings ? $this->settings->name : config('app.name');

        // Generate PDF with all active menus and menu items
        $menuController = new \App\Http\Controllers\MenuController();
        $pdfContent = $menuController->getMenuPdfContent($this->settings->id ?? null);
        $pdfFileName = 'menu-' . date('Y-m-d') . '.pdf';

        // Signed download URL for the PDF (so the button downloads the PDF, not the menus page)
        $downloadUrl = $this->settings && $this->settings->id
            ? URL::temporarySignedRoute('menus.pdf', now()->addDays(7), ['restaurant' => $this->settings->id])
            : route('menus.index');

        // Fetch all active menus with their active menu items
        $allMenus = collect();
        if ($this->settings && $this->settings->id) {
            $branchIds = $this->settings->branches->pluck('id')->toArray();

            if (!empty($branchIds)) {
                // Get all menus with their active menu items from all branches
                $allMenus = Menu::whereIn('branch_id', $branchIds)
                    ->with(['items' => function($query) {
                        $query->withoutGlobalScope(\App\Scopes\AvailableMenuItemScope::class)
                            ->where('is_available', true)
                            ->with(['category'])
                            ->orderBy('item_category_id')
                            ->orderBy('sort_order');
                    }])
                    ->get()
                    ->filter(function($menu) {
                        return $menu->items->isNotEmpty();
                    });
            }
        }

        $build = parent::build($notifiable);
        return $build
            ->subject(__('email.sendMenuPdf.subject', ['menu_name' => $menuName, 'site_name' => $restaurantName]))
            ->markdown('emails.menu-pdf', [
                'menu' => $this->menu,
                'menuItems' => $this->menuItems,
                'allMenus' => $allMenus,
                'settings' => $this->settings,
                'notifiable' => $notifiable,
                'downloadUrl' => $downloadUrl,
            ])
            ->attachData($pdfContent, $pdfFileName, [
                'mime' => 'application/pdf',
            ]);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        $menuName = $this->menu ? $this->menu->menu_name : __('modules.menu.menu');
        $title = __('email.sendMenuPdf.subject', ['menu_name' => $menuName, 'site_name' => $this->settings->name ?? config('app.name')]);
        $url = $this->menu ? route('menus.index') : route('menu-items.index');
        $message = $title . " - " . __('email.sendMenuPdf.text1_general');

        // Send push notification
        $this->sendPushNotification(
            $notifiable,
            $message,
            $url
        );

        return [
            'restaurant_id' => $this->settings->id ?? null,
            'menu_id' => $this->menu ? $this->menu->id : null,
            'message' => $title,
            'url' => $url,
            'created_at' => now()->toDateTimeString(),
            'user_name' => $notifiable->name,
        ];
    }
}

