/* SPDX-License-Identifier: GPL-3.0-or-later. Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE. */
/**
 * Flood Watcher service worker: shows push notifications and opens the right page when one is
 * tapped. It doesn't cache anything, so the page is always fetched fresh.
 */
'use strict';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (event) { event.waitUntil(self.clients.claim()); });

self.addEventListener('push', function (event) {
  var data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch (e) {
    data = { body: event.data ? event.data.text() : '' };
  }
  event.waitUntil(self.registration.showNotification(data.title || 'Flood Watcher', {
    body: data.body || '',
    tag: data.tag || undefined,
    renotify: !!data.tag,
    icon: 'icons/icon-192.png',
    badge: 'icons/badge-72.png',
    data: { url: data.url || './' }
  }));
});

/** Only open our own pages or the Bureau of Meteorology's. */
function safeUrl(url) {
  try {
    var u = new URL(url, self.registration.scope);
    if (u.origin === self.location.origin || /(^|\.)bom\.gov\.au$/.test(u.hostname)) { return u.href; }
  } catch (e) {
    /* Fall through to the home page. */
  }
  return self.registration.scope;
}

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var url = safeUrl(event.notification.data && event.notification.data.url);
  event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (wins) {
    for (var i = 0; i < wins.length; i++) {
      if (wins[i].url.indexOf(self.registration.scope) === 0 && new URL(url).origin === self.location.origin) {
        return wins[i].navigate(url).then(function (w) { return w && w.focus(); });
      }
    }
    return self.clients.openWindow(url);
  }));
});
