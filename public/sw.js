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

  // Both promises collected and passed to ONE event.waitUntil(Promise.all(...))
  // - matches WebKit's own documented pattern exactly (see
  // https://webkit.org/blog/14112/badging-for-home-screen-web-apps/), not
  // two separate waitUntil() calls as this originally shipped. Confirmed
  // live: the separate-calls version never actually showed a badge on
  // Andres's real device (notification itself worked fine, so this wasn't
  // a permissions issue) despite no thrown/caught error anywhere - iOS
  // Safari's Badging API implementation apparently doesn't reliably honor
  // a badge promise registered via a SECOND, separate waitUntil() call.
  // No real per-notification unseen count is tracked (would need
  // persistent storage across SW restarts) - a fixed 1 just flags "there's
  // something", cleared back off entirely by clearAppBadge() below/on
  // page load.
  var promises = [self.registration.showNotification(title, options)];

  if ('setAppBadge' in self.navigator) {
    promises.push(self.navigator.setAppBadge(1).catch(function () {}));
  }

  event.waitUntil(Promise.all(promises));
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

  // Same single-Promise.all()-in-one-waitUntil() pattern as the push
  // handler above, for the same reason.
  var promises = [
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
  ];

  if ('setAppBadge' in self.navigator) {
    promises.push(self.navigator.clearAppBadge().catch(function () {}));
  }

  event.waitUntil(Promise.all(promises));
});
