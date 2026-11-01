import './bootstrap';

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import { registerRosterMatrix } from './roster';

Alpine.plugin(collapse);
registerRosterMatrix(Alpine);

const resolveThemeDark = (mode) =>
    mode === 'dark' || (mode !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);

const initialThemeMode = document.documentElement.dataset.theme || 'system';

Alpine.store('theme', {
    mode: initialThemeMode,
    dark: document.documentElement.classList.contains('dark') || resolveThemeDark(initialThemeMode),
    init() {
        this.mode = document.documentElement.dataset.theme || this.mode || 'system';
        this.apply();
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => this.apply());
    },
    set(mode) {
        this.mode = mode;
        document.cookie = `theme=${mode}; path=/; max-age=31536000; SameSite=Lax`;
        this.apply();
    },
    toggle() {
        this.set(this.dark ? 'light' : 'dark');
    },
    apply() {
        const isDark = resolveThemeDark(this.mode);
        this.dark = isDark;
        document.documentElement.classList.toggle('dark', isDark);
        document.documentElement.dataset.theme = this.mode;
    }
});

Alpine.store('sidebar', {
    collapsed: document.cookie.includes('sidebar=collapsed'),
    mobileOpen: false,
    toggle() {
        this.collapsed = !this.collapsed;
        document.documentElement.dataset.sidebar = this.collapsed ? 'collapsed' : 'expanded';
        document.cookie = `sidebar=${this.collapsed ? 'collapsed' : 'expanded'}; path=/; max-age=31536000; SameSite=Lax`;
    },
    toggleMobile() {
        this.mobileOpen = !this.mobileOpen;
    },
    closeMobile() {
        this.mobileOpen = false;
    }
});

window.Alpine = Alpine;

Alpine.start();
