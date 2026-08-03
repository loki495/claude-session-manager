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
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  var url = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
      for (var i = 0; i < clientList.length; i++) {
        if (clientList[i].url.indexOf(url) !== -1 && 'focus' in clientList[i]) {
          return clientList[i].focus();
        }
      }

      if (clients.openWindow) {
        return clients.openWindow(url);
      }

      return undefined;
    })
  );
});
