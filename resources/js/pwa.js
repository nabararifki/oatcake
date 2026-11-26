const SNAPSHOT_STORAGE_KEY = 'oatcake_offline_snapshot';
const SNAPSHOT_ENDPOINT = '/pwa/offline-snapshot';
const NOTIFICATIONS_ENDPOINT = '/notifications';
const PUSH_SUBSCRIPTIONS_ENDPOINT = '/notifications/push-subscriptions';
const TEST_PUSH_ENDPOINT = '/notifications/test-push';
const MARK_ALL_READ_ENDPOINT = '/notifications/read-all';
const USER_CACHE_NAMES = ['oatcake-pages-v3', 'oatcake-data-v3', 'oatcake-pages-v2', 'oatcake-data-v2', 'oatcake-pages-v1', 'oatcake-data-v1'];

let listenersInitialized = false;

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; i += 1) {
        outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
}

async function unsubscribeActivePushOnLogout() {
    if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) {
        return;
    }

    const timeout = new Promise((resolve) => setTimeout(resolve, 600));

    const task = (async () => {
        try {
            const registration = await navigator.serviceWorker.getRegistration('/');
            if (!registration || !registration.pushManager) {
                return;
            }
            const subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                return;
            }
            const endpoint = subscription.endpoint;
            const cleanupPromises = [];
            if (endpoint && navigator.onLine) {
                cleanupPromises.push(
                    fetch(PUSH_SUBSCRIPTIONS_ENDPOINT, {
                        method: 'DELETE',
                        keepalive: true,
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ endpoint }),
                    }).catch(() => undefined)
                );
            }
            cleanupPromises.push(subscription.unsubscribe().catch(() => false));
            await Promise.allSettled(cleanupPromises);
        } catch (e) {
            // Ignore push cleanup errors on logout
        }
    })();

    await Promise.race([task, timeout]);
}

export async function clearUserPwaCache() {
    try {
        localStorage.removeItem(SNAPSHOT_STORAGE_KEY);
    } catch (e) {
        // Ignore storage errors in restricted contexts
    }

    const tasks = [unsubscribeActivePushOnLogout()];

    if (typeof window !== 'undefined' && 'caches' in window) {
        for (const cacheName of USER_CACHE_NAMES) {
            tasks.push(window.caches.delete(cacheName).catch(() => false));
        }
    }

    if (typeof navigator !== 'undefined' && 'serviceWorker' in navigator) {
        const sendClearMessage = (worker) =>
            new Promise((resolve) => {
                if (!worker) {
                    resolve();
                    return;
                }
                const timeout = setTimeout(resolve, 250);
                try {
                    const channel = new MessageChannel();
                    channel.port1.onmessage = () => {
                        clearTimeout(timeout);
                        resolve();
                    };
                    worker.postMessage({ type: 'CLEAR_USER_CACHE' }, [channel.port2]);
                } catch (e) {
                    clearTimeout(timeout);
                    resolve();
                }
            });

        if (navigator.serviceWorker.controller) {
            tasks.push(sendClearMessage(navigator.serviceWorker.controller));
        } else {
            tasks.push(
                navigator.serviceWorker
                    .getRegistration('/')
                    .then((reg) => (reg?.active ? sendClearMessage(reg.active) : undefined))
                    .catch(() => undefined)
            );
        }
    }

    await Promise.allSettled(tasks);
}

export function logoutWithPwaCacheClear(form) {
    if (!form) {
        return;
    }
    clearUserPwaCache().finally(() => {
        form.submit();
    });
}

function readSnapshotFromStorage() {
    try {
        const raw = localStorage.getItem(SNAPSHOT_STORAGE_KEY);
        return raw ? JSON.parse(raw) : null;
    } catch (e) {
        return null;
    }
}

async function readSnapshotFromCacheStorage() {
    if (typeof window === 'undefined' || !('caches' in window)) {
        return null;
    }
    try {
        const cache = await window.caches.open('oatcake-data-v3');
        const response = await cache.match(SNAPSHOT_ENDPOINT);
        if (!response || !response.ok) {
            return null;
        }
        return await response.json();
    } catch (e) {
        return null;
    }
}

function isUserAuthenticated() {
    const meta = document.querySelector('meta[name="pwa-authenticated"]');
    return meta?.getAttribute('content') === '1';
}

export function registerPwaManager(Alpine) {
    if (typeof window !== 'undefined') {
        window.oatcakeClearUserCache = clearUserPwaCache;
        window.oatcakeLogoutSubmit = logoutWithPwaCacheClear;
    }

    const store = {
        isOnline: typeof navigator !== 'undefined' ? navigator.onLine : true,
        isStandalone:
            typeof window !== 'undefined' &&
            (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true),
        isSecureContext: typeof window !== 'undefined' ? Boolean(window.isSecureContext) : false,
        swSupported: typeof navigator !== 'undefined' && 'serviceWorker' in navigator,
        currentOrigin: typeof window !== 'undefined' ? window.location.origin : '',
        chromeFlagsUrl: 'chrome://flags/#unsafely-treat-insecure-origin-as-secure',
        deferredPrompt: null,
        canPromptNative: false,
        isInstalled: false,
        isAndroid: typeof navigator !== 'undefined' && /android/i.test(navigator.userAgent || ''),
        isIos:
            typeof navigator !== 'undefined' &&
            (/iphone|ipad|ipod/i.test(navigator.userAgent || '') ||
                (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)),
        copiedTarget: null,
        _copyTimer: null,
        snapshot: readSnapshotFromStorage(),
        syncingSnapshot: false,
        swRegistered: false,

        // Web Push & In-App Notification Center state
        pushSupported:
            typeof window !== 'undefined' &&
            'serviceWorker' in navigator &&
            'PushManager' in window &&
            'Notification' in window,
        pushPermission: typeof Notification !== 'undefined' ? Notification.permission : 'default',
        isPushSubscribed: false,
        pushBusy: false,
        vapidPublicKey: null,
        unreadCount: 0,
        notifications: [],
        loadingNotifications: false,

        get showInstallButton() {
            return !this.isStandalone;
        },

        init() {
            if (listenersInitialized || typeof window === 'undefined') {
                return;
            }
            listenersInitialized = true;

            this.isOnline = navigator.onLine;
            this.isStandalone =
                window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
            this.isInstalled = this.isStandalone;
            this.isSecureContext = Boolean(window.isSecureContext);
            this.swSupported = 'serviceWorker' in navigator;
            this.currentOrigin = window.location.origin;
            this.isAndroid = /android/i.test(navigator.userAgent || '');
            this.isIos =
                /iphone|ipad|ipod/i.test(navigator.userAgent || '') ||
                (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

            window.addEventListener('online', () => {
                this.isOnline = true;
                if (isUserAuthenticated()) {
                    this.syncSnapshot();
                    this.fetchNotifications();
                }
            });

            window.addEventListener('offline', () => {
                this.isOnline = false;
            });

            window.addEventListener('beforeinstallprompt', (event) => {
                event.preventDefault();
                this.deferredPrompt = event;
                this.canPromptNative = true;
            });

            window.addEventListener('appinstalled', () => {
                this.deferredPrompt = null;
                this.canPromptNative = false;
                this.isInstalled = true;
                this.isStandalone = true;
            });

            this.registerServiceWorker().then(() => {
                if (isUserAuthenticated()) {
                    this.refreshPushSubscriptionState();
                }
            });

            if (!this.snapshot) {
                readSnapshotFromCacheStorage().then((cached) => {
                    if (cached && !this.snapshot) {
                        this.snapshot = cached;
                    }
                });
            }

            if (isUserAuthenticated() && this.isOnline) {
                this.syncSnapshot();
                this.fetchNotifications();
            }
        },

        async registerServiceWorker() {
            if (!('serviceWorker' in navigator)) {
                return;
            }
            try {
                await navigator.serviceWorker.register('/sw.js?v=oatcake-v3', { scope: '/' });
                this.swRegistered = true;
            } catch (e) {
                // Service worker registration failed or unsupported in current context
            }
        },

        async syncSnapshot() {
            if (!this.isOnline || this.syncingSnapshot || !isUserAuthenticated()) {
                return null;
            }

            this.syncingSnapshot = true;
            try {
                const response = await fetch(SNAPSHOT_ENDPOINT, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    return null;
                }

                const payload = await response.json();
                if (payload && typeof payload === 'object' && !payload.offline) {
                    this.snapshot = payload;
                    try {
                        localStorage.setItem(SNAPSHOT_STORAGE_KEY, JSON.stringify(payload));
                    } catch (e) {
                        // Ignore localStorage quota issues
                    }
                }
                return payload;
            } catch (e) {
                return null;
            } finally {
                this.syncingSnapshot = false;
            }
        },

        async fetchNotifications() {
            if (!this.isOnline || !isUserAuthenticated()) {
                return null;
            }

            this.loadingNotifications = true;
            try {
                const response = await fetch(NOTIFICATIONS_ENDPOINT, {
                    method: 'GET',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    return null;
                }

                const payload = await response.json();
                if (payload && typeof payload === 'object') {
                    this.notifications = Array.isArray(payload.notifications) ? payload.notifications : [];
                    this.unreadCount = Number(payload.unread_count ?? 0);
                    if (payload.vapid_public_key) {
                        this.vapidPublicKey = payload.vapid_public_key;
                    }
                }
                return payload;
            } catch (e) {
                return null;
            } finally {
                this.loadingNotifications = false;
            }
        },

        async refreshPushSubscriptionState() {
            if (!this.pushSupported) {
                this.isPushSubscribed = false;
                return;
            }

            this.pushPermission = typeof Notification !== 'undefined' ? Notification.permission : 'default';

            try {
                const registration = await navigator.serviceWorker.ready;
                const subscription = await registration.pushManager.getSubscription();
                this.isPushSubscribed = subscription !== null;
            } catch (e) {
                this.isPushSubscribed = false;
            }
        },

        async togglePushSubscription() {
            if (!this.pushSupported || !this.isOnline || this.pushBusy || !isUserAuthenticated()) {
                return;
            }

            this.pushBusy = true;
            try {
                const registration = await navigator.serviceWorker.ready;
                const existing = await registration.pushManager.getSubscription();

                if (existing) {
                    await fetch(PUSH_SUBSCRIPTIONS_ENDPOINT, {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ endpoint: existing.endpoint }),
                    });
                    await existing.unsubscribe();
                    this.isPushSubscribed = false;
                    return;
                }

                const permission = await Notification.requestPermission();
                this.pushPermission = permission;
                if (permission !== 'granted') {
                    return;
                }

                if (!this.vapidPublicKey) {
                    await this.fetchNotifications();
                }

                if (!this.vapidPublicKey) {
                    return;
                }

                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(this.vapidPublicKey),
                });

                const jsonSub = subscription.toJSON();
                const response = await fetch(PUSH_SUBSCRIPTIONS_ENDPOINT, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        endpoint: jsonSub.endpoint,
                        keys: jsonSub.keys,
                        content_encoding: 'aes128gcm',
                    }),
                });

                if (response.ok) {
                    this.isPushSubscribed = true;
                }
            } catch (e) {
                // Keep UI responsive on browser/permission errors
            } finally {
                this.pushBusy = false;
            }
        },

        async sendTestNotification() {
            if (!this.isOnline || this.pushBusy || !isUserAuthenticated()) {
                return;
            }

            this.pushBusy = true;
            try {
                const response = await fetch(TEST_PUSH_ENDPOINT, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                });

                if (response.ok) {
                    await this.fetchNotifications();
                }
            } catch (e) {
                // Ignore transient errors
            } finally {
                this.pushBusy = false;
            }
        },

        async markNotificationRead(item) {
            if (!item || !item.uuid) {
                return;
            }

            if (!item.read_at && this.isOnline) {
                try {
                    const response = await fetch(`/notifications/${item.uuid}/read`, {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': getCsrfToken(),
                        },
                        credentials: 'same-origin',
                    });

                    if (response.ok) {
                        const data = await response.json();
                        item.read_at = data.notification?.read_at || new Date().toISOString();
                        this.unreadCount = Number(data.unread_count ?? Math.max(0, this.unreadCount - 1));
                    }
                } catch (e) {
                    // Continue navigation
                }
            }

            if (item.action_url) {
                window.dispatchEvent(new CustomEvent('close-modal', { detail: 'notifications-modal' }));
                window.location.href = item.action_url;
            }
        },

        async markAllNotificationsRead() {
            if (!this.isOnline || !isUserAuthenticated()) {
                return;
            }

            try {
                const response = await fetch(MARK_ALL_READ_ENDPOINT, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': getCsrfToken(),
                    },
                    credentials: 'same-origin',
                });

                if (response.ok) {
                    const nowIso = new Date().toISOString();
                    this.notifications = this.notifications.map((n) => ({
                        ...n,
                        read_at: n.read_at || nowIso,
                    }));
                    this.unreadCount = 0;
                }
            } catch (e) {
                // Ignore
            }
        },

        openNotificationsModal() {
            this.fetchNotifications();
            this.refreshPushSubscriptionState();
            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'notifications-modal' }));
        },

        async installApp() {
            if (this.deferredPrompt) {
                const promptEvent = this.deferredPrompt;
                await promptEvent.prompt();
                const choice = await promptEvent.userChoice.catch(() => null);
                this.deferredPrompt = null;
                this.canPromptNative = false;
                if (choice && choice.outcome === 'accepted') {
                    this.isInstalled = true;
                }
                return;
            }

            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'pwa-install-guide-modal' }));
        },

        async triggerInstall() {
            return this.installApp();
        },

        async promptInstall() {
            return this.installApp();
        },

        async copyToClipboard(text, labelKey = 'default', containerEl = null) {
            if (!text || typeof document === 'undefined') {
                return false;
            }

            let copied = false;

            if (
                typeof window !== 'undefined' &&
                window.isSecureContext === true &&
                typeof navigator !== 'undefined' &&
                navigator.clipboard &&
                typeof navigator.clipboard.writeText === 'function'
            ) {
                try {
                    await navigator.clipboard.writeText(text);
                    copied = true;
                } catch (e) {
                    copied = false;
                }
            }

            if (!copied) {
                const openDialogs = document.querySelectorAll('dialog[open]');
                const mountTarget =
                    (containerEl && containerEl instanceof HTMLElement ? containerEl : null) ||
                    (openDialogs.length > 0 ? openDialogs[openDialogs.length - 1] : null) ||
                    document.body;

                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.setAttribute('readonly', '');
                textarea.setAttribute('aria-hidden', 'true');
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                textarea.style.top = '0';
                textarea.style.opacity = '0';
                mountTarget.appendChild(textarea);
                textarea.focus({ preventScroll: true });
                textarea.select();
                textarea.setSelectionRange(0, text.length);
                try {
                    copied = document.execCommand('copy');
                } catch (e) {
                    copied = false;
                } finally {
                    if (textarea.parentNode) {
                        textarea.parentNode.removeChild(textarea);
                    }
                }
            }

            if (copied) {
                this.copiedTarget = labelKey;
                if (this._copyTimer) {
                    clearTimeout(this._copyTimer);
                }
                this._copyTimer = setTimeout(() => {
                    if (this.copiedTarget === labelKey) {
                        this.copiedTarget = null;
                    }
                    this._copyTimer = null;
                }, 2500);
            }

            return copied;
        },

        openSnapshotModal() {
            if (!this.snapshot) {
                readSnapshotFromCacheStorage().then((cached) => {
                    if (cached) {
                        this.snapshot = cached;
                    }
                });
            }
            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'offline-snapshot-modal' }));
        },

        reloadToDashboard() {
            if (window.location.pathname === '/dashboard') {
                window.location.reload();
                return;
            }
            window.location.href = '/dashboard';
        },
    };

    Alpine.store('pwa', store);

    const pwaManagerFactory = () => ({
        get isOnline() {
            return Alpine.store('pwa').isOnline;
        },
        get isStandalone() {
            return Alpine.store('pwa').isStandalone;
        },
        get isSecureContext() {
            return Alpine.store('pwa').isSecureContext;
        },
        get swSupported() {
            return Alpine.store('pwa').swSupported;
        },
        get currentOrigin() {
            return Alpine.store('pwa').currentOrigin;
        },
        get chromeFlagsUrl() {
            return Alpine.store('pwa').chromeFlagsUrl;
        },
        get isAndroid() {
            return Alpine.store('pwa').isAndroid;
        },
        get isIos() {
            return Alpine.store('pwa').isIos;
        },
        get copiedTarget() {
            return Alpine.store('pwa').copiedTarget;
        },
        get canPromptNative() {
            return Alpine.store('pwa').canPromptNative;
        },
        get showInstallButton() {
            return Alpine.store('pwa').showInstallButton;
        },
        get snapshot() {
            return Alpine.store('pwa').snapshot;
        },
        get syncingSnapshot() {
            return Alpine.store('pwa').syncingSnapshot;
        },
        get unreadCount() {
            return Alpine.store('pwa').unreadCount;
        },
        get notifications() {
            return Alpine.store('pwa').notifications;
        },
        get isPushSubscribed() {
            return Alpine.store('pwa').isPushSubscribed;
        },
        init() {
            Alpine.store('pwa').init();
        },
        syncSnapshot() {
            return Alpine.store('pwa').syncSnapshot();
        },
        fetchNotifications() {
            return Alpine.store('pwa').fetchNotifications();
        },
        togglePushSubscription() {
            return Alpine.store('pwa').togglePushSubscription();
        },
        sendTestNotification() {
            return Alpine.store('pwa').sendTestNotification();
        },
        markNotificationRead(item) {
            return Alpine.store('pwa').markNotificationRead(item);
        },
        markAllNotificationsRead() {
            return Alpine.store('pwa').markAllNotificationsRead();
        },
        openNotificationsModal() {
            return Alpine.store('pwa').openNotificationsModal();
        },
        installApp() {
            return Alpine.store('pwa').installApp();
        },
        triggerInstall() {
            return Alpine.store('pwa').triggerInstall();
        },
        promptInstall() {
            return Alpine.store('pwa').promptInstall();
        },
        copyToClipboard(text, labelKey, containerEl) {
            return Alpine.store('pwa').copyToClipboard(text, labelKey, containerEl);
        },
        openSnapshotModal() {
            return Alpine.store('pwa').openSnapshotModal();
        },
        reloadToDashboard() {
            return Alpine.store('pwa').reloadToDashboard();
        },
    });

    if (typeof window !== 'undefined') {
        window.pwaManager = pwaManagerFactory;
    }

    Alpine.data('pwaManager', pwaManagerFactory);
}
