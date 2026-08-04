// Service worker for Web Push notifications - see push_subscribe.php and
// the "Notify me" button's JS (in AgentClient.php's push_notify_button_html())
// for the subscribe flow. Registered at root scope (/sw.js), so it can
// receive pushes and handle notification clicks regardless of which page
// (index.php or session.php) is currently open, or even if none is.
//
// The push handler ALWAYS calls event.waitUntil(showNotification(...)),
// synchronously, even in a hypothetical no-data-parsed case - iOS Safari
// treats a push that doesn't result in a shown notification as "silent",
// and permanently kills the subscription after 3 of those with no error
// signal to the app. There is deliberately no code path here that can
// skip showing a notification.

self.addEventListener('push', function (event) {
  var data = {};

  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {}

  var title = data.title || 'Claude Session Manager';
  var options = {
    body: data.body || 'A session needs your attention.',
    data: { url: data.url || '/' }
  };

  event.waitUntil(self.registration.showNotification(title, options));

  // Badging API: flags the home-screen icon for a notification that
  // hasn't been seen/cleared yet (see push_notify_button_html()'s
  // navigator.clearAppBadge() on page load, and notificationclick below
  // for the tap-to-clear path). Feature-detected, not gated on the
  // showNotification path above at all - a separate concern, and a
  // failure here must never affect the anti-silent-push guarantee that
  // waitUntil() is protecting.
  if ('setAppBadge' in self.navigator) {
    event.waitUntil(self.navigator.setAppBadge().catch(function () {}));
  }
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  var url = (event.notification.data && event.notification.data.url) || '/';
  // clients.openWindow() takes a relative url just fine per spec (resolved
  // against the SW's own registration scope), but iOS Safari's PWA
  // (home-screen-installed, standalone display) mode has a known history
  // of mishandling that and opening/focusing the app at its start_url
  // instead of the requested one - Andres reported exactly this live.
  // Passing an already-absolute URL is the commonly-cited workaround, so
  // trying that here rather than the relative form.
  var absoluteUrl = new URL(url, self.location.origin).href;

  if ('setAppBadge' in self.navigator) {
    event.waitUntil(self.navigator.clearAppBadge().catch(function () {}));
  }

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (var i = 0; i < clientList.length; i++) {
        if (clientList[i].url.indexOf(url) !== -1 && 'focus' in clientList[i]) {
          return clientList[i].focus();
        }
      }

      if (clients.openWindow) {
        return clients.openWindow(absoluteUrl);
      }

      return undefined;
    })
  );
});
