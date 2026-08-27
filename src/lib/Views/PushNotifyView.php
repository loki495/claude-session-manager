<?php

declare(strict_types=1);

namespace App\Views;

/**
 * A "Notify me" control for Web Push (see the README's "Web Push
 * notifications" section for the server-side setup this depends on).
 */
class PushNotifyView extends View
{
    /**
     * Renders nothing at all when $vapidPublicKey is empty (VAPID keys not
     * generated/configured on the host yet), since there'd be nothing useful
     * for the button to do.
     */
    public static function push_notify_button_html(string $vapidPublicKey, string $csrfToken): string
    {
        if ($vapidPublicKey === '') {
            return '';
        }

        return self::render('push-notify/button', [
            'vapidPublicKey' => $vapidPublicKey,
            'csrfToken' => $csrfToken,
        ]);
    }
}
