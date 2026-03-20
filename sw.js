const CACHE_NAME = 'mslog-static-v4.1'; // 🔑 อัปเดตเวอร์ชันแคช เพื่อบังคับให้ผู้ใช้โหลด SW ตัวใหม่
const urlsToCache = [
  '/',
  'index.php',
  'missions/mission_list.php', 
  'missions/mission_add.php', 
  'missions/mission_view.php', 

  'assets/css/style.css', 
  'assets/css/mission_list_styles.css', 
  'assets/images/icon-192x192.png',
  'assets/images/icon-512x512.png' 
];

// 1. EVENT: INSTALL
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('SW: Caching static assets (v3.9)');
        return cache.addAll(urlsToCache);
      })
      .catch(err => {
        console.error('SW: Failed to cache all assets.', err);
      })
  );
});

// 2. EVENT: ACTIVATE
self.addEventListener('activate', event => {
  const cacheWhitelist = [CACHE_NAME];
  
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheWhitelist.indexOf(cacheName) === -1) {
            console.log('SW: Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
  return self.clients.claim(); 
});

// 3. EVENT: FETCH (Network-First Strategy for PHP/HTML, Cache-First for static)
self.addEventListener('fetch', event => {
  const requestUrl = new URL(event.request.url);

  // ใช้ Network First สำหรับ request ที่หน้าเว็บ (PHP, HTML)
  if (event.request.mode === 'navigate' || requestUrl.pathname.endsWith('.php') || requestUrl.pathname === '/') {
    event.respondWith(
      fetch(event.request)
      .then(response => {
        // อัปเดตข้อมูลในแคช
        const responseClone = response.clone();
        caches.open(CACHE_NAME).then(cache => {
          cache.put(event.request, responseClone);
        });
        return response;
      })
      .catch(() => caches.match(event.request))
    );
  } else if (event.request.url.startsWith('http')) {
    // ใช้ Cache First สำหรับไฟล์ static (.css, .png, .js)
    event.respondWith(
      caches.match(event.request)
        .then(response => {
          if (response) {
            return response;
          }
          return fetch(event.request)
              .then(networkResponse => {
                  const clonedResponse = networkResponse.clone();
                  caches.open(CACHE_NAME).then(cache => {
                      cache.put(event.request, clonedResponse);
                  });
                  return networkResponse;
              });
        })
    );
  }
});

// -------------------------------------------------------------
// *** ส่วนของ PWA Sync/Push Features ***
// -------------------------------------------------------------

function syncQueuedMissions() {
    const FORM_DATA_KEY = 'mslog_offline_mission';
    const missionDataJson = localStorage.getItem(FORM_DATA_KEY);
    if (!missionDataJson) return Promise.resolve();
    
    const missionData = JSON.parse(missionDataJson);
    const formData = new URLSearchParams();
    
    for (const key in missionData) {
        formData.append(key, missionData[key]);
    }
    formData.append('sync_request', 'true'); 
    
    console.log('SW: Attempting to sync mission:', missionData.subject);

    return fetch('missions/mission_add.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: formData.toString()
    })
    .then(response => {
        if (response.ok) {
            console.log('SW: Mission synced successfully!');
            localStorage.removeItem(FORM_DATA_KEY);
            return self.registration.showNotification('✅ ซิงค์ภารกิจสำเร็จ', {
                body: `ภารกิจ "${missionData.subject}" ถูกบันทึกแล้ว`,
                icon: '/assets/images/icon-192x192.png'
            });
        } else {
            console.error('SW: Sync failed with server error status:', response.status);
            throw new Error('Server error during sync. Status: ' + response.status);
        }
    })
    .catch(error => {
        console.error('SW: Network error during sync:', error);
        throw error;
    });
}

// 4. EVENT: SYNC (Background Sync)
self.addEventListener('sync', event => {
  if (event.tag === 'new-mission-sync') {
    event.waitUntil(syncQueuedMissions());
  }
});

// 5. EVENT: PUSH (สำหรับรับแจ้งเตือน)
self.addEventListener('push', event => {
  const data = event.data.json();
  const title = data.title || 'แจ้งเตือนภารกิจ MSLog';
  const options = {
    body: data.body,
    icon: '/assets/images/icon-192x192.png', 
    badge: '/assets/images/icon-192x192.png', 
    data: {
      url: data.url || 'missions/mission_list.php' 
    }
  };
  event.waitUntil( self.registration.showNotification(title, options) );
});

// 6. EVENT: NOTIFICATION CLICK
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const targetUrl = event.notification.data.url || 'missions/mission_list.php';
  event.waitUntil(
    clients.matchAll({ type: 'window' }).then(clientList => {
      for (const client of clientList) {
        if (client.url.includes(targetUrl) && 'focus' in client) {
          return client.focus();
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
    })
  );
});