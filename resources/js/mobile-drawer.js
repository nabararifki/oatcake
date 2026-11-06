export function registerMobileDrawerSwipe(Alpine) {
    if (!Alpine.store('ui')) {
        Alpine.store('ui', {
            mobileDrawerOpen: false,
            openDrawer() {
                this.mobileDrawerOpen = true;
            },
            closeDrawer() {
                this.mobileDrawerOpen = false;
            },
            toggleDrawer() {
                this.mobileDrawerOpen = !this.mobileDrawerOpen;
            },
        });
    }

    if (typeof window === 'undefined') {
        return;
    }

    let touchStartX = 0;
    let touchStartY = 0;
    let ignoreSwipe = false;

    window.addEventListener('touchstart', (e) => {
        // Abaikan jika sentuhan berasal dari kontainer horizontal scroll
        if (e.target && typeof e.target.closest === 'function') {
            if (e.target.closest('.overflow-x-auto, [data-prevent-drawer-swipe], #roster-gantt-viewport, #roster-shift-palette')) {
                ignoreSwipe = true;
                return;
            }
        }
        if (!e.touches || !e.touches.length) {
            ignoreSwipe = true;
            return;
        }
        ignoreSwipe = false;
        touchStartX = e.touches[0].clientX;
        touchStartY = e.touches[0].clientY;
    }, { passive: true });

    window.addEventListener('touchmove', (e) => {
        if (ignoreSwipe || !e.touches || !e.touches.length) return;
    }, { passive: true });

    window.addEventListener('touchend', (e) => {
        if (ignoreSwipe || !e.changedTouches || !e.changedTouches.length) return;

        const touchEndX = e.changedTouches[0].clientX;
        const touchEndY = e.changedTouches[0].clientY;
        const deltaX = touchEndX - touchStartX;
        const deltaY = touchEndY - touchStartY;

        // Validasi: pergerakan horizontal dominan (|deltaX| > |deltaY| * 1.5) dan jarak geser > 60px
        if (Math.abs(deltaX) > Math.abs(deltaY) * 1.5) {
            // Swipe ke kanan saat drawer tertutup (hanya jika sentuhan berawal dari area kiri tepi layar <= 50px)
            if (deltaX > 60 && touchStartX <= 50 && !Alpine.store('ui').mobileDrawerOpen) {
                Alpine.store('ui').mobileDrawerOpen = true;
                window.dispatchEvent(new CustomEvent('mobile-drawer-change', { detail: { open: true } }));
            }
            // Swipe ke kiri saat drawer terbuka
            else if (deltaX < -60 && Alpine.store('ui').mobileDrawerOpen) {
                Alpine.store('ui').mobileDrawerOpen = false;
                window.dispatchEvent(new CustomEvent('mobile-drawer-change', { detail: { open: false } }));
            }
        }
    }, { passive: true });
}
