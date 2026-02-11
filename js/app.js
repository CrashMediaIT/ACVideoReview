/**
 * Arctic Wolves Video Review - Core Application JavaScript
 * Handles sidebar navigation, notifications, modals, and shared utilities
 */
(function() {
    'use strict';

    /* --------------------------------------------------
       Sidebar & Mobile Navigation
    -------------------------------------------------- */
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebarOverlay');
    var hamburger = document.getElementById('hamburgerBtn');

    function closeSidebar() {
        if (sidebar) sidebar.classList.remove('open');
        if (overlay) overlay.classList.remove('active');
    }

    // Close sidebar when clicking a nav link on mobile
    if (sidebar) {
        sidebar.querySelectorAll('.sidebar-nav a').forEach(function(link) {
            link.addEventListener('click', function() {
                if (window.innerWidth <= 768) closeSidebar();
            });
        });
    }

    /* --------------------------------------------------
       Notification Bell
    -------------------------------------------------- */
    var notificationBell = document.querySelector('.notification-bell');
    if (notificationBell) {
        notificationBell.addEventListener('click', function() {
            // Navigate to dashboard notifications
            window.location.href = '?page=home';
        });
    }

    // Notification items: click to navigate + mark as read
    document.querySelectorAll('.notification-item[data-notification-id]').forEach(function(item) {
        item.addEventListener('click', function() {
            var notifId = this.dataset.notificationId;
            var link = this.dataset.link;

            // Mark as read via AJAX (fire-and-forget)
            var formData = new FormData();
            formData.append('notification_id', notifId);
            formData.append('csrf_token', getCSRFToken());
            fetch('api/notification_read.php', {
                method: 'POST',
                body: formData
            }).catch(function() { /* silent */ });

            // Remove unread styling
            this.classList.remove('notification-unread');

            // Navigate if link exists
            if (link) {
                window.location.href = link;
            }
        });
    });

    /* --------------------------------------------------
       CSRF Token Utility
    -------------------------------------------------- */
    function getCSRFToken() {
        var input = document.querySelector('input[name="csrf_token"]');
        if (input) return input.value;
        // Fallback: try to read from a data attribute on the body
        var bodyToken = document.body.dataset.csrfToken;
        return bodyToken || '';
    }

    // Expose globally for use by other scripts
    window.AppUtils = {
        getCSRFToken: getCSRFToken,
        closeSidebar: closeSidebar
    };

    /* --------------------------------------------------
       Modal Helpers
    -------------------------------------------------- */
    // Close modals when clicking the overlay backdrop
    document.querySelectorAll('.modal-overlay').forEach(function(modalOverlay) {
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
                this.style.display = 'none';
            }
        });
    });

    // Close modal buttons
    document.querySelectorAll('.modal-close, [data-dismiss="modal"]').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = this.closest('.modal-overlay');
            if (modal) {
                modal.classList.remove('active');
                modal.style.display = 'none';
            }
        });
    });

    /* --------------------------------------------------
       Escape key closes modals and sidebar
    -------------------------------------------------- */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeSidebar();
            document.querySelectorAll('.modal-overlay.active, .modal-overlay[style*="display: flex"]').forEach(function(m) {
                m.classList.remove('active');
                m.style.display = 'none';
            });
        }
    });

    /* --------------------------------------------------
       Service Worker Registration (PWA)
    -------------------------------------------------- */
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(function() {
            // Service worker not available - expected in development
        });
    }

    /* --------------------------------------------------
       Format Helpers (shared with inline scripts)
    -------------------------------------------------- */
    window.formatDuration = function(seconds) {
        if (!seconds || isNaN(seconds)) return '0:00';
        var m = Math.floor(seconds / 60);
        var s = Math.floor(seconds % 60);
        return m + ':' + (s < 10 ? '0' : '') + s;
    };

    window.formatTimecode = function(seconds) {
        if (!seconds || isNaN(seconds)) return '00:00:00.000';
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = Math.floor(seconds % 60);
        var ms = Math.floor((seconds % 1) * 1000);
        return (h < 10 ? '0' : '') + h + ':' +
               (m < 10 ? '0' : '') + m + ':' +
               (s < 10 ? '0' : '') + s + '.' +
               (ms < 100 ? '0' : '') + (ms < 10 ? '0' : '') + ms;
    };

})();
