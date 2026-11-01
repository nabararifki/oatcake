import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

const ch = (v) => `rgb(var(${v}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                accent: {
                    DEFAULT: ch('--primary-accent-rgb'),
                    fg: 'var(--primary-accent-fg)',
                    text: 'var(--primary-accent-text)',
                },
                canvas: ch('--c-canvas'),
                surface: {
                    DEFAULT: ch('--c-surface'),
                    muted: ch('--c-surface-muted'),
                    raised: ch('--c-surface-raised'),
                },
                line: {
                    DEFAULT: ch('--c-line'),
                    strong: ch('--c-line-strong'),
                },
                ink: {
                    DEFAULT: ch('--c-ink'),
                    muted: ch('--c-ink-muted'),
                    faint: ch('--c-ink-faint'),
                },
            },
            borderRadius: {
                sm: '6px',
                md: '8px',
                lg: '12px',
                xl: '16px',
            },
            zIndex: {
                sticky: '20',
                dropdown: '40',
                drawer: '60',
                modal: '100',
                toast: '150',
                tooltip: '200',
            },
            fontSize: {
                '2xs': ['11px', '16px'],
                'cell': ['13px', '18px'],
            },
            spacing: {
                '18': '4.5rem',
            },
        },
    },
    plugins: [forms],
};
