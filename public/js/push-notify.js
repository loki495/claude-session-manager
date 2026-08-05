// Registers the service worker and, if a subscription already exists,
// silently re-POSTs it on every page load - iOS's own push subscriptions
// are prone to dying silently with no error signal to the app, so
// resubscribing on every open is what actually keeps a stale one from
// just quietly stopping forever.
(function () {
  var control = document.getElementById('push-notify-control');

  if (!control) {
    return; // no VAPID key configured - the template renders nothing at all
  }

  var VAPID_PUBLIC_KEY = control.dataset.vapidKey;
  var CSRF_TOKEN = control.dataset.csrfToken;
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
