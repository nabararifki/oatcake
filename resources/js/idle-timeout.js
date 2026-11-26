import { clearUserPwaCache } from './pwa';

const ACTIVITY_STORAGE_KEY = 'oatcake_last_activity_at';
const THROTTLE_MS = 2000;
const CHECK_INTERVAL_MS = 15000;

/**
 * Monitor user activity with throttled event listeners and trigger auto-logout
 * after exceeding the tenant-configured inactivity duration.
 */
export function initIdleTimeoutMonitor() {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    const authMeta = document.querySelector('meta[name="pwa-authenticated"]');
    const timeoutMeta = document.querySelector('meta[name="session-timeout-minutes"]');
    const logoutMeta = document.querySelector('meta[name="session-logout-url"]');
    const loginMeta = document.querySelector('meta[name="session-login-url"]');

    if (authMeta?.getAttribute('content') !== '1' || !timeoutMeta) {
        return;
    }

    const parsedMinutes = Number.parseInt(timeoutMeta.getAttribute('content') || '30', 10);
    const timeoutMinutes = Number.isFinite(parsedMinutes)
        ? Math.max(5, Math.min(1440, parsedMinutes))
        : 30;
    const timeoutMs = timeoutMinutes * 60 * 1000;
    const logoutUrl = logoutMeta?.getAttribute('content') || '/logout';
    const loginUrl = loginMeta?.getAttribute('content') || '/login?timeout=1';

    let lastActivityAt = Date.now();
    let lastThrottleWriteAt = 0;
    let isLoggingOut = false;

    const persistTimestamp = (ts) => {
        try {
            localStorage.setItem(ACTIVITY_STORAGE_KEY, String(ts));
        } catch (e) {
            // Ignore storage access errors in restricted contexts
        }
    };

    const readSharedTimestamp = () => {
        try {
            const raw = localStorage.getItem(ACTIVITY_STORAGE_KEY);
            const parsed = raw ? Number.parseInt(raw, 10) : 0;
            return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
        } catch (e) {
            return 0;
        }
    };

    persistTimestamp(lastActivityAt);

    const recordActivity = () => {
        if (isLoggingOut) {
            return;
        }

        const now = Date.now();
        if (now - lastThrottleWriteAt < THROTTLE_MS) {
            return;
        }

        lastThrottleWriteAt = now;
        lastActivityAt = now;
        persistTimestamp(now);
    };

    const triggerAutoLogout = async () => {
        if (isLoggingOut) {
            return;
        }
        isLoggingOut = true;

        const csrfToken =
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        try {
            await clearUserPwaCache();
        } catch (e) {
            // Continue logout even if cache clearing fails
        }

        try {
            await fetch(logoutUrl, {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ reason: 'idle_timeout' }),
            });
        } catch (e) {
            // Even if network/CSRF fails on an expired session, proceed to login warning screen
        }

        window.location.replace(loginUrl);
    };

    const checkIdleStatus = () => {
        if (isLoggingOut) {
            return;
        }

        const sharedTs = readSharedTimestamp();
        if (sharedTs > lastActivityAt) {
            lastActivityAt = sharedTs;
        }

        if (Date.now() - lastActivityAt >= timeoutMs) {
            triggerAutoLogout();
        }
    };

    const activityEvents = ['mousemove', 'keydown', 'touchstart', 'scroll'];
    activityEvents.forEach((evt) => {
        window.addEventListener(evt, recordActivity, { passive: true });
    });

    window.addEventListener('storage', (event) => {
        if (event.key === ACTIVITY_STORAGE_KEY && event.newValue) {
            const ts = Number.parseInt(event.newValue, 10);
            if (Number.isFinite(ts) && ts > lastActivityAt) {
                lastActivityAt = ts;
            }
        }
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            checkIdleStatus();
        }
    });

    setInterval(checkIdleStatus, CHECK_INTERVAL_MS);
}
