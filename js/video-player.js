/**
 * Arctic Wolves Video Review - Video Player
 * Custom video player with frame-step, speed control, and timeline markers
 */
(function() {
    'use strict';

    /**
     * VideoPlayer - Enhanced HTML5 video player wrapper
     * @param {HTMLVideoElement} videoEl - The video element
     * @param {Object} options - Configuration options
     */
    function VideoPlayer(videoEl, options) {
        if (!videoEl) return;

        this.video = videoEl;
        this.options = options || {};
        this.isPlaying = false;
        this.playbackRate = 1.0;
        this.markers = [];
        this.onTimeUpdateCallbacks = [];

        this._bindEvents();
    }

    VideoPlayer.prototype._bindEvents = function() {
        var self = this;

        this.video.addEventListener('play', function() {
            self.isPlaying = true;
            self._updatePlayButton();
        });

        this.video.addEventListener('pause', function() {
            self.isPlaying = false;
            self._updatePlayButton();
        });

        this.video.addEventListener('timeupdate', function() {
            self._onTimeUpdate();
        });

        this.video.addEventListener('loadedmetadata', function() {
            self._onMetadataLoaded();
        });

        this.video.addEventListener('ended', function() {
            self.isPlaying = false;
            self._updatePlayButton();
        });
    };

    VideoPlayer.prototype._onTimeUpdate = function() {
        var currentTime = this.video.currentTime;
        for (var i = 0; i < this.onTimeUpdateCallbacks.length; i++) {
            this.onTimeUpdateCallbacks[i](currentTime, this.video.duration);
        }
    };

    VideoPlayer.prototype._onMetadataLoaded = function() {
        // Update duration displays
        var durationEls = document.querySelectorAll('[data-video-duration]');
        var dur = this.video.duration;
        durationEls.forEach(function(el) {
            el.textContent = window.formatTimecode ? window.formatTimecode(dur) : Math.floor(dur) + 's';
        });
    };

    VideoPlayer.prototype._updatePlayButton = function() {
        var btns = document.querySelectorAll('[data-player-play]');
        var self = this;
        btns.forEach(function(btn) {
            var icon = btn.querySelector('i');
            if (icon) {
                icon.className = self.isPlaying ? 'fas fa-pause' : 'fas fa-play';
            }
        });
    };

    /* Player Controls */
    VideoPlayer.prototype.play = function() {
        this.video.play();
    };

    VideoPlayer.prototype.pause = function() {
        this.video.pause();
    };

    VideoPlayer.prototype.togglePlay = function() {
        if (this.isPlaying) {
            this.pause();
        } else {
            this.play();
        }
    };

    VideoPlayer.prototype.seek = function(time) {
        this.video.currentTime = Math.max(0, Math.min(time, this.video.duration || 0));
    };

    VideoPlayer.prototype.seekRelative = function(delta) {
        this.seek(this.video.currentTime + delta);
    };

    VideoPlayer.prototype.frameStep = function(forward) {
        // Assume ~30fps, step 1 frame
        var frameTime = 1 / 30;
        this.pause();
        this.seekRelative(forward ? frameTime : -frameTime);
    };

    VideoPlayer.prototype.setPlaybackRate = function(rate) {
        this.playbackRate = Math.max(0.25, Math.min(rate, 4.0));
        this.video.playbackRate = this.playbackRate;

        var rateEls = document.querySelectorAll('[data-player-speed]');
        var self = this;
        rateEls.forEach(function(el) {
            el.textContent = self.playbackRate.toFixed(2) + 'x';
        });
    };

    VideoPlayer.prototype.setVolume = function(vol) {
        this.video.volume = Math.max(0, Math.min(vol, 1));
    };

    VideoPlayer.prototype.toggleMute = function() {
        this.video.muted = !this.video.muted;
    };

    VideoPlayer.prototype.toggleFullscreen = function() {
        var container = this.video.closest('.video-player-container') || this.video;
        if (document.fullscreenElement) {
            document.exitFullscreen();
        } else if (container.requestFullscreen) {
            container.requestFullscreen();
        }
    };

    /* Timeline Markers */
    VideoPlayer.prototype.addMarker = function(time, label, color) {
        this.markers.push({ time: time, label: label || '', color: color || '#6B46C1' });
    };

    VideoPlayer.prototype.clearMarkers = function() {
        this.markers = [];
    };

    /* Event Subscription */
    VideoPlayer.prototype.onTimeUpdate = function(callback) {
        if (typeof callback === 'function') {
            this.onTimeUpdateCallbacks.push(callback);
        }
    };

    VideoPlayer.prototype.getCurrentTime = function() {
        return this.video.currentTime;
    };

    VideoPlayer.prototype.getDuration = function() {
        return this.video.duration || 0;
    };

    VideoPlayer.prototype.loadSource = function(src) {
        this.video.src = src;
        this.video.load();
    };

    VideoPlayer.prototype.destroy = function() {
        this.video.pause();
        this.video.removeAttribute('src');
        this.onTimeUpdateCallbacks = [];
        this.markers = [];
    };

    /* --------------------------------------------------
       Initialize players on page
    -------------------------------------------------- */
    function initVideoPlayers() {
        document.querySelectorAll('.video-player').forEach(function(el) {
            if (!el._vrPlayer) {
                el._vrPlayer = new VideoPlayer(el);
            }
        });
    }

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initVideoPlayers);
    } else {
        initVideoPlayers();
    }

    /* --------------------------------------------------
       Keyboard Shortcuts (when video is focused)
    -------------------------------------------------- */
    document.addEventListener('keydown', function(e) {
        // Only handle if not typing in an input/textarea
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

        var activeVideo = document.querySelector('.video-player');
        if (!activeVideo || !activeVideo._vrPlayer) return;

        var player = activeVideo._vrPlayer;

        switch (e.key) {
            case ' ':
                e.preventDefault();
                player.togglePlay();
                break;
            case 'ArrowLeft':
                e.preventDefault();
                player.seekRelative(e.shiftKey ? -10 : -5);
                break;
            case 'ArrowRight':
                e.preventDefault();
                player.seekRelative(e.shiftKey ? 10 : 5);
                break;
            case ',':
                e.preventDefault();
                player.frameStep(false);
                break;
            case '.':
                e.preventDefault();
                player.frameStep(true);
                break;
            case 'f':
                e.preventDefault();
                player.toggleFullscreen();
                break;
            case 'm':
                e.preventDefault();
                player.toggleMute();
                break;
        }
    });

    // Expose constructor globally
    window.VideoPlayer = VideoPlayer;

})();
