<?php

declare(strict_types=1);

namespace App\Views;

/**
 * A "Notify me" control for Web Push (see the README's "Web Push
 * notifications" section for the server-side setup this depends on).
 */
class PushNotifyView
{
    /**
     * Renders nothing at all when $vapidPublicKey is empty (VAPID keys not
     * generated/configured on the host yet), since there'd be nothing useful
     * for the button to do.
     *
     * Registers the service worker and, if a subscription already exists,
     * silently re-POSTs it on every page load - iOS's own push subscriptions
     * are prone to dying silently with no error signal to the app, so
     * resubscribing on every open is what actually keeps a stale one from
     * just quietly stopping forever.
     */
    public static function push_notify_button_html(string $vapidPublicKey, string $csrfToken): string
    {
        if ($vapidPublicKey === '') {
            return '';
        }

        $html = <<<'HTML'
        <div id="push-notify-control" class="mt-1">
          <button type="button" id="push-notify-btn" class="rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5 hidden">
            Enable notifications
          </button>
          <span id="push-notify-status" class="text-xs text-slate-500"></span>
        </div>
        <script>
        (function () {
          var VAPID_PUBLIC_KEY = {{VAPID_PUBLIC_KEY_JSON}};
          var CSRF_TOKEN = {{CSRF_TOKEN_JSON}};
          var btn = document.getElementById('push-notify-btn');
          var status = document.getElementById('push-notify-status');

          if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            return; // not supported on this browser/OS - button stays hidden
          }

          // Badging API: opening either page counts as "seen" - clears
          // whatever badge sw.js's push handler set for a notification that
          // arrived while the app wasn't open. Feature-detected since support
          // (particularly on iOS home-screen PWAs, which is the only real
          // target for the push feature this rides along with) isn't
          // guaranteed - a harmless no-op everywhere else.
          if ('setAppBadge' in navigator) {
            navigator.clearAppBadge().catch(function () {});
          }

          // Web Push's applicationServerKey wants a raw Uint8Array, not the
          // base64url string VAPID::createVapidKeys() produces.
          function urlBase64ToUint8Array(base64String) {
            var padding = '='.repeat((4 - base64String.length % 4) % 4);
            var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
            var rawData = window.atob(base64);
            var outputArray = new Uint8Array(rawData.length);

            for (var i = 0; i < rawData.length; ++i) {
              outputArray[i] = rawData.charCodeAt(i);
            }

            return outputArray;
          }

          function postSubscription(subscription) {
            return fetch('/push_subscribe.php', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
              body: new URLSearchParams({
                csrf_token: CSRF_TOKEN,
                subscription: JSON.stringify(subscription)
              }).toString()
            });
          }

          navigator.serviceWorker.register('/sw.js').then(function (registration) {
            return registration.pushManager.getSubscription().then(function (existing) {
              if (existing) {
                postSubscription(existing);

                if (status) {
                  status.textContent = 'Push notifications enabled';
                }

                return;
              }

              if (Notification.permission === 'denied') {
                if (status) {
                  status.textContent = 'Notifications blocked in browser settings';
                }

                return;
              }

              if (!btn) {
                return;
              }

              btn.classList.remove('hidden');

              btn.addEventListener('click', function () {
                btn.disabled = true;

                Notification.requestPermission().then(function (permission) {
                  if (permission !== 'granted') {
                    if (status) {
                      status.textContent = 'Permission not granted';
                    }

                    btn.disabled = false;
                    return;
                  }

                  registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(VAPID_PUBLIC_KEY)
                  })
                    .then(postSubscription)
                    .then(function () {
                      btn.classList.add('hidden');

                      if (status) {
                        status.textContent = 'Push notifications enabled';
                      }
                    })
                    .catch(function () {
                      if (status) {
                        status.textContent = 'Could not enable notifications';
                      }

                      btn.disabled = false;
                    });
                });
              });
            });
          }).catch(function () {});
        })();
        </script>
        HTML;

        $html = str_replace('{{VAPID_PUBLIC_KEY_JSON}}', json_encode($vapidPublicKey), $html);

        return str_replace('{{CSRF_TOKEN_JSON}}', json_encode($csrfToken), $html);
    }
}
