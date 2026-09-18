<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DesktopApplication extends Model
{
    use HasFactory;

    const WINDOWS_FILE_PATH = 'https://envato.froid.works/app/download/windows';
    const MAC_FILE_PATH = 'https://envato.froid.works/app/download/macos';
    /** @deprecated Linux desktop app no longer provided */
    const LINUX_FILE_PATH = 'https://envato.froid.works/app/download/linux';
    const PARTNER_APP_IOS_URL = 'https://apps.apple.com/in/app/tabletrack-rider/id6759326050';
    const PARTNER_APP_ANDROID_URL = 'https://play.google.com/store/apps/details?id=com.delivery.tabletrack&hl=en_IN';
    const WAITER_POS_APP_IOS_URL = '';
    const WAITER_POS_APP_ANDROID_URL = '';

    protected $guarded = ['id'];
    protected $table = 'desktop_mobile_application';

    public function getIsActiveAttribute()
    {
        return $this->windows_file_path || $this->mac_file_path;
    }

    public function getIsMobileActiveAttribute()
    {
        return ! empty($this->partner_app_ios) || ! empty($this->partner_app_android);
    }

    public function getIsDeliveryPartnerMobileActiveAttribute()
    {
        return ! empty($this->partner_app_ios) || ! empty($this->partner_app_android);
    }

    public function getIsWaiterPosMobileActiveAttribute()
    {
        return ! empty($this->waiter_pos_app_ios) || ! empty($this->waiter_pos_app_android);
    }
}
