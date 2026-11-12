/* Sidebar, mobile menu and theme for every page using layouts.app. */

/* =========================================================
   SYMBIOT NAVIGATION & THEME
   ========================================================= */

document.addEventListener('DOMContentLoaded', () => {

    const shell = document.getElementById('symbiotShell');

    if (!shell) return;

    const sidebar = document.getElementById('symbiotSidebar');

    const mobileButton = document.getElementById(
        'sidebarMobileToggle'
    );

    const mobileClose = document.getElementById(
        'sidebarMobileClose'
    );

    const overlay = document.getElementById(
        'sidebarOverlay'
    );

    const collapseButton = document.getElementById(
        'sidebarCollapse'
    );

    const themeButton = document.getElementById(
        'themeToggle'
    );


    /* =====================================================
       THEME
       ===================================================== */

    function applyTheme(theme) {

        shell.dataset.theme = theme;

        const icon = document.getElementById('themeIcon');

        if (icon) {

            icon.className = theme === 'dark'
                ? 'fa-solid fa-sun'
                : 'fa-solid fa-moon';

        }

    }

    const savedTheme = localStorage.getItem('symbiot-theme');

    const systemDark = window.matchMedia(
        '(prefers-color-scheme: dark)'
    ).matches;

    applyTheme(
        savedTheme || (systemDark ? 'dark' : 'light')
    );

    themeButton?.addEventListener('click', () => {

        const nextTheme =
            shell.dataset.theme === 'dark'
                ? 'light'
                : 'dark';

        applyTheme(nextTheme);

        localStorage.setItem(
            'symbiot-theme',
            nextTheme
        );

    });


    /* =====================================================
       DEVICE DETECTION
       ===================================================== */

    const isMobile = () => window.innerWidth < 768;

    const isTablet = () =>
        window.innerWidth >= 768 &&
        window.innerWidth < 1200;

    /* =====================================================
       TRANSPORT ICONS
       ===================================================== */


        const getTransportIcon = (type) => {
            switch (type) {
                case 'airport': return '<i class="fas fa-plane text-primary"></i>';
                case 'train': return '<i class="fas fa-train text-success"></i>';
                case 'subway': return '<i class="fas fa-subway text-danger"></i>';
                case 'bus': return '<i class="fas fa-bus text-warning"></i>';
                default: return '<i class="fas fa-map-marker-alt text-secondary"></i>';
            }
        };


    /* =====================================================
       MOBILE MENU
       ===================================================== */

    function openMobileMenu() {

        if (!isMobile()) return;

        shell.classList.add('mobile-menu-open');

        document.body.classList.add('symbiot-menu-active');

        mobileButton?.setAttribute(
            'aria-expanded',
            'true'
        );

        overlay?.setAttribute(
            'aria-hidden',
            'false'
        );

    }

    function closeMobileMenu() {

        shell.classList.remove('mobile-menu-open');

        document.body.classList.remove('symbiot-menu-active');

        mobileButton?.setAttribute(
            'aria-expanded',
            'false'
        );

        overlay?.setAttribute(
            'aria-hidden',
            'true'
        );

    }


    /* =====================================================
       MOBILE EVENTS
       ===================================================== */

    mobileButton?.addEventListener(
        'click',
        openMobileMenu
    );

    mobileClose?.addEventListener(
        'click',
        closeMobileMenu
    );

    overlay?.addEventListener(
        'click',
        closeMobileMenu
    );


    /* Close menu after navigation */

    sidebar?.querySelectorAll('a').forEach(link => {

        link.addEventListener('click', () => {

            if (isMobile()) {
                closeMobileMenu();
            }

        });

    });


    /* =====================================================
       DESKTOP / TABLET COLLAPSE
       ===================================================== */

    collapseButton?.addEventListener('click', () => {

        if (isMobile()) return;

        if (isTablet()) {

            shell.classList.toggle('sidebar-expanded');

            return;
        }

        const collapsed = shell.classList.toggle(
            'sidebar-collapsed'
        );

        localStorage.setItem(
            'symbiot-sidebar-collapsed',
            String(collapsed)
        );

    });


    /* =====================================================
       RESTORE SIDEBAR STATE
       ===================================================== */

    function restoreSidebar() {

        if (isMobile()) {

            shell.classList.remove(
                'sidebar-collapsed',
                'sidebar-expanded',
                'mobile-menu-open'
            );

            document.body.classList.remove(
                'symbiot-menu-active'
            );

            mobileButton?.setAttribute(
                'aria-expanded',
                'false'
            );

            return;
        }

        closeMobileMenu();

        if (isTablet()) {

            shell.classList.remove(
                'sidebar-collapsed',
                'sidebar-expanded'
            );

            return;
        }

        const collapsed =
            localStorage.getItem(
                'symbiot-sidebar-collapsed'
            ) === 'true';

        shell.classList.toggle(
            'sidebar-collapsed',
            collapsed
        );

        shell.classList.remove('sidebar-expanded');

    }

    restoreSidebar();


    /* =====================================================
       WINDOW RESIZE
       ===================================================== */

    let resizeTimeout;

    window.addEventListener('resize', () => {

        clearTimeout(resizeTimeout);

        resizeTimeout = setTimeout(() => {

            restoreSidebar();

        }, 100);

    });


    /* =====================================================
       ESCAPE KEY
       ===================================================== */

    document.addEventListener('keydown', event => {

        if (event.key === 'Escape') {

            closeMobileMenu();

            shell.classList.remove('sidebar-expanded');

        }

    });

});
