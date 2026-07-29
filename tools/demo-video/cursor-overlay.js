/**
 * Курсор и подсветка поверх страницы. Playwright пишет видео без
 * указателя мыши — без этого оверлея зритель видит, как поля
 * заполняются сами собой, и не понимает, куда нажали.
 *
 * Скрипт ставится через addInitScript, поэтому переживает переходы
 * между страницами и Livewire-перерисовки.
 */
(() => {
    const install = () => {
        if (document.getElementById('demo-cursor') !== null) {
            return;
        }

        const style = document.createElement('style');
        style.textContent = `
            #demo-cursor {
                position: fixed; top: 0; left: 0; width: 22px; height: 22px;
                margin: -11px 0 0 -11px; border-radius: 9999px;
                background: rgba(37, 99, 235, .35);
                border: 2px solid rgba(37, 99, 235, .9);
                box-shadow: 0 2px 8px rgba(0, 0, 0, .35);
                pointer-events: none; z-index: 2147483647;
                transition: transform .08s ease-out;
            }
            #demo-cursor.is-down { transform: scale(.6); }
            .demo-ripple {
                position: fixed; width: 22px; height: 22px; margin: -11px 0 0 -11px;
                border-radius: 9999px; border: 2px solid rgba(37, 99, 235, .8);
                pointer-events: none; z-index: 2147483646;
                animation: demo-ripple .5s ease-out forwards;
            }
            @keyframes demo-ripple {
                to { transform: scale(3.2); opacity: 0; }
            }
            .demo-highlight {
                outline: 3px solid rgba(234, 88, 12, .9) !important;
                outline-offset: 3px !important;
                border-radius: 6px;
                transition: outline-color .2s ease-out;
            }
        `;
        document.head.append(style);

        const cursor = document.createElement('div');
        cursor.id = 'demo-cursor';
        document.body.append(cursor);

        const place = (event) => {
            cursor.style.top = `${event.clientY}px`;
            cursor.style.left = `${event.clientX}px`;
        };

        addEventListener('mousemove', place, true);
        addEventListener('mousedown', (event) => {
            place(event);
            cursor.classList.add('is-down');

            const ripple = document.createElement('div');
            ripple.className = 'demo-ripple';
            ripple.style.top = `${event.clientY}px`;
            ripple.style.left = `${event.clientX}px`;
            document.body.append(ripple);
            setTimeout(() => ripple.remove(), 500);
        }, true);
        addEventListener('mouseup', () => cursor.classList.remove('is-down'), true);
    };

    window.__demoHighlight = (element, duration) => {
        element.classList.add('demo-highlight');
        setTimeout(() => element.classList.remove('demo-highlight'), duration);
    };

    if (document.readyState === 'loading') {
        addEventListener('DOMContentLoaded', install);
    } else {
        install();
    }

    // Filament перерисовывает куски DOM — курсор возвращаем, если его снесло.
    new MutationObserver(() => install()).observe(document.documentElement, {
        childList: true,
        subtree: false,
    });
})();
