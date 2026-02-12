/**
 * Arctic Wolves Video Review – Professional Video Player
 * Hardware-accelerated video playback with frame-accurate controls
 * Designed for sports video analysis (Catapult-grade)
 */
(function() {
    'use strict';

    var DEFAULT_FPS = 30;
    var SPEED_PRESETS = [0.1, 0.25, 0.5, 0.75, 1, 1.5, 2, 4];
    var SYNC_THRESHOLD = 0.1;

    /**
     * Detect hardware acceleration capabilities
     */
    function detectHardwareAcceleration() {
        var caps = {
            webCodecs: typeof VideoDecoder !== 'undefined',
            requestVideoFrame: typeof HTMLVideoElement !== 'undefined' &&
                'requestVideoFrameCallback' in HTMLVideoElement.prototype,
            mediaCapabilities: 'mediaCapabilities' in navigator,
            playbackQuality: false,
            hardwareAccelerated: false
        };

        // Check if getVideoPlaybackQuality is available
        var testVideo = document.createElement('video');
        caps.playbackQuality = typeof testVideo.getVideoPlaybackQuality === 'function';

        // If MediaCapabilities is available, we can query for HW decode
        caps.hardwareAccelerated = caps.webCodecs || caps.requestVideoFrame;

        return caps;
    }

    /**
     * Format seconds to HH:MM:SS:FF timecode
     */
    function formatTimecode(seconds, fps) {
        if (!seconds || isNaN(seconds)) return '00:00:00:00';
        fps = fps || DEFAULT_FPS;
        var totalFrames = Math.floor(seconds * fps);
        var f = totalFrames % fps;
        var totalSeconds = Math.floor(seconds);
        var s = totalSeconds % 60;
        var m = Math.floor(totalSeconds / 60) % 60;
        var h = Math.floor(totalSeconds / 3600);
        return (h < 10 ? '0' : '') + h + ':' +
               (m < 10 ? '0' : '') + m + ':' +
               (s < 10 ? '0' : '') + s + ':' +
               (f < 10 ? '0' : '') + f;
    }

    /**
     * Format seconds to M:SS (simple display)
     */
    function formatDuration(seconds) {
        if (!seconds || isNaN(seconds)) return '0:00';
        var m = Math.floor(seconds / 60);
        var s = Math.floor(seconds % 60);
        return m + ':' + (s < 10 ? '0' : '') + s;
    }

    /**
     * VideoPlayer – Professional HTML5 video player wrapper
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

        // Hardware acceleration
        this.hwCaps = detectHardwareAcceleration();
        this.hwAccelSupported = this.hwCaps.hardwareAccelerated;

        // Frame rate
        this.fps = this.options.fps || DEFAULT_FPS;

        // A/B Loop
        this.loopA = null;
        this.loopB = null;
        this.loopEnabled = false;

        // Sync partners
        this._syncPartners = [];
        this._isSyncing = false;

        // Performance metrics
        this._droppedFrames = 0;
        this._totalFrames = 0;

        // Apply hardware acceleration hints
        this.video.style.transform = 'translateZ(0)';
        this.video.style.willChange = 'transform';

        this._bindEvents();
        this._initPerformanceMonitor();
    }

    VideoPlayer.prototype._bindEvents = function() {
        var self = this;

        this.video.addEventListener('play', function() {
            self.isPlaying = true;
            self._updatePlayButton();
            self._syncAction('play');
        });

        this.video.addEventListener('pause', function() {
            self.isPlaying = false;
            self._updatePlayButton();
            self._syncAction('pause');
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

        this.video.addEventListener('ratechange', function() {
            self.playbackRate = self.video.playbackRate;
            self._updateSpeedDisplay();
        });

        // Use requestVideoFrameCallback for precise frame tracking
        if (this.hwCaps.requestVideoFrame) {
            var onFrame = function(now, metadata) {
                if (metadata.mediaTime !== undefined) {
                    self._onPreciseFrame(metadata);
                }
                self.video.requestVideoFrameCallback(onFrame);
            };
            this.video.requestVideoFrameCallback(onFrame);
        }
    };

    VideoPlayer.prototype._onTimeUpdate = function() {
        var currentTime = this.video.currentTime;

        // A/B Loop enforcement
        if (this.loopEnabled && this.loopA !== null && this.loopB !== null) {
            if (currentTime >= this.loopB) {
                this.video.currentTime = this.loopA;
            }
        }

        // Fire callbacks
        for (var i = 0; i < this.onTimeUpdateCallbacks.length; i++) {
            this.onTimeUpdateCallbacks[i](currentTime, this.video.duration);
        }

        // Update time displays
        this._updateTimeDisplay();
        this._updateProgress();
        this._syncTime();
    };

    VideoPlayer.prototype._onMetadataLoaded = function() {
        var dur = this.video.duration;

        // Update duration displays
        document.querySelectorAll('[data-video-duration]').forEach(function(el) {
            el.textContent = formatTimecode(dur, this.fps);
        }.bind(this));

        // Simple duration display
        document.querySelectorAll('[data-video-duration-simple]').forEach(function(el) {
            el.textContent = formatDuration(dur);
        });

        // Render markers on timeline
        this._renderMarkers();
    };

    VideoPlayer.prototype._onPreciseFrame = function(metadata) {
        if (metadata.presentedFrames) {
            this._totalFrames = metadata.presentedFrames;
        }
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

    VideoPlayer.prototype._updateTimeDisplay = function() {
        var current = this.video.currentTime;
        var duration = this.video.duration || 0;

        // Timecode format
        document.querySelectorAll('[data-player-timecode]').forEach(function(el) {
            el.textContent = formatTimecode(current, this.fps) + ' / ' + formatTimecode(duration, this.fps);
        }.bind(this));

        // Simple time
        document.querySelectorAll('[data-player-time]').forEach(function(el) {
            el.textContent = formatDuration(current) + ' / ' + formatDuration(duration);
        });
    };

    VideoPlayer.prototype._updateSpeedDisplay = function() {
        var rateEls = document.querySelectorAll('[data-player-speed]');
        var self = this;
        rateEls.forEach(function(el) {
            el.textContent = self.playbackRate.toFixed(2) + 'x';
        });

        // Update speed selector buttons
        document.querySelectorAll('.speed-option').forEach(function(btn) {
            var rate = parseFloat(btn.getAttribute('data-rate'));
            if (rate === self.playbackRate) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
    };

    VideoPlayer.prototype._updateProgress = function() {
        var progress = this.video.duration ? (this.video.currentTime / this.video.duration) * 100 : 0;
        document.querySelectorAll('.video-progress-fill').forEach(function(el) {
            el.style.width = progress + '%';
        });
        document.querySelectorAll('.timeline-progress').forEach(function(el) {
            el.style.width = progress + '%';
        });
    };

    VideoPlayer.prototype._renderMarkers = function() {
        var duration = this.video.duration;
        if (!duration) return;

        document.querySelectorAll('.timeline-bar').forEach(function(bar) {
            // Remove existing markers
            bar.querySelectorAll('.timeline-marker').forEach(function(m) { m.remove(); });

            this.markers.forEach(function(marker) {
                var pos = (marker.time / duration) * 100;
                var el = document.createElement('div');
                el.className = 'timeline-marker';
                el.style.left = pos + '%';
                el.style.background = marker.color;
                el.title = marker.label;
                el.setAttribute('data-time', marker.time);
                el.addEventListener('click', function(e) {
                    e.stopPropagation();
                    this.seek(marker.time);
                }.bind(this));
                bar.appendChild(el);
            }.bind(this));
        }.bind(this));
    };

    VideoPlayer.prototype._initPerformanceMonitor = function() {
        if (!this.hwCaps.playbackQuality) return;

        var self = this;
        this._perfInterval = setInterval(function() {
            if (!self.video || self.video.paused) return;
            var quality = self.video.getVideoPlaybackQuality();
            if (quality) {
                self._droppedFrames = quality.droppedVideoFrames || 0;
                self._totalFrames = quality.totalVideoFrames || 0;
            }
        }, 2000);
    };

    /* =====================================================
       Player Controls
       ===================================================== */
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
        var frameTime = 1 / this.fps;
        this.pause();
        this.seekRelative(forward ? frameTime : -frameTime);
    };

    VideoPlayer.prototype.setPlaybackRate = function(rate) {
        this.playbackRate = Math.max(0.1, Math.min(rate, 4.0));
        this.video.playbackRate = this.playbackRate;
        this._updateSpeedDisplay();
    };

    VideoPlayer.prototype.speedUp = function() {
        var idx = SPEED_PRESETS.indexOf(this.playbackRate);
        if (idx >= 0 && idx < SPEED_PRESETS.length - 1) {
            this.setPlaybackRate(SPEED_PRESETS[idx + 1]);
        } else if (idx === -1) {
            for (var i = 0; i < SPEED_PRESETS.length; i++) {
                if (SPEED_PRESETS[i] > this.playbackRate) {
                    this.setPlaybackRate(SPEED_PRESETS[i]);
                    return;
                }
            }
        }
    };

    VideoPlayer.prototype.slowDown = function() {
        var idx = SPEED_PRESETS.indexOf(this.playbackRate);
        if (idx > 0) {
            this.setPlaybackRate(SPEED_PRESETS[idx - 1]);
        } else if (idx === -1) {
            for (var i = SPEED_PRESETS.length - 1; i >= 0; i--) {
                if (SPEED_PRESETS[i] < this.playbackRate) {
                    this.setPlaybackRate(SPEED_PRESETS[i]);
                    return;
                }
            }
        }
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

    /* =====================================================
       A/B Loop (Segment Review)
       ===================================================== */
    VideoPlayer.prototype.setLoopA = function() {
        this.loopA = this.video.currentTime;
        this._updateLoopDisplay();
    };

    VideoPlayer.prototype.setLoopB = function() {
        this.loopB = this.video.currentTime;
        if (this.loopA !== null && this.loopB <= this.loopA) {
            var tmp = this.loopA;
            this.loopA = this.loopB;
            this.loopB = tmp;
        }
        this._updateLoopDisplay();
    };

    VideoPlayer.prototype.toggleLoop = function() {
        if (this.loopA !== null && this.loopB !== null) {
            this.loopEnabled = !this.loopEnabled;
            if (this.loopEnabled) {
                this.seek(this.loopA);
                this.play();
            }
        }
        this._updateLoopDisplay();
    };

    VideoPlayer.prototype.clearLoop = function() {
        this.loopA = null;
        this.loopB = null;
        this.loopEnabled = false;
        this._updateLoopDisplay();
    };

    VideoPlayer.prototype._updateLoopDisplay = function() {
        document.querySelectorAll('[data-loop-a]').forEach(function(el) {
            el.textContent = this.loopA !== null ? formatTimecode(this.loopA, this.fps) : '--:--:--:--';
        }.bind(this));

        document.querySelectorAll('[data-loop-b]').forEach(function(el) {
            el.textContent = this.loopB !== null ? formatTimecode(this.loopB, this.fps) : '--:--:--:--';
        }.bind(this));

        document.querySelectorAll('[data-loop-status]').forEach(function(el) {
            el.classList.toggle('active', this.loopEnabled);
        }.bind(this));

        // Render loop region on progress bar
        document.querySelectorAll('.video-progress-bar').forEach(function(bar) {
            var existing = bar.querySelector('.clip-region');
            if (existing) existing.remove();

            if (this.loopA !== null && this.loopB !== null && this.video.duration) {
                var region = document.createElement('div');
                region.className = 'clip-region';
                region.style.left = ((this.loopA / this.video.duration) * 100) + '%';
                region.style.width = (((this.loopB - this.loopA) / this.video.duration) * 100) + '%';
                bar.appendChild(region);
            }
        }.bind(this));
    };

    /* =====================================================
       Multi-Angle Sync
       ===================================================== */
    VideoPlayer.prototype.syncWith = function(otherPlayer) {
        if (otherPlayer && otherPlayer !== this && this._syncPartners.indexOf(otherPlayer) === -1) {
            this._syncPartners.push(otherPlayer);
            otherPlayer._syncPartners.push(this);
        }
    };

    VideoPlayer.prototype.unsync = function(otherPlayer) {
        if (otherPlayer) {
            this._syncPartners = this._syncPartners.filter(function(p) { return p !== otherPlayer; });
            otherPlayer._syncPartners = otherPlayer._syncPartners.filter(function(p) { return p !== this; }.bind(this));
        }
    };

    VideoPlayer.prototype._syncAction = function(action) {
        if (this._isSyncing) return;
        this._isSyncing = true;

        this._syncPartners.forEach(function(partner) {
            partner._isSyncing = true;
            if (action === 'play') partner.video.play();
            else if (action === 'pause') partner.video.pause();
            partner._isSyncing = false;
        });

        this._isSyncing = false;
    };

    VideoPlayer.prototype._syncTime = function() {
        if (this._isSyncing || !this.isPlaying) return;

        var currentTime = this.video.currentTime;
        this._syncPartners.forEach(function(partner) {
            if (Math.abs(partner.video.currentTime - currentTime) > SYNC_THRESHOLD) {
                partner._isSyncing = true;
                partner.video.currentTime = currentTime;
                partner._isSyncing = false;
            }
        });
    };

    /* =====================================================
       Timeline Markers
       ===================================================== */
    VideoPlayer.prototype.addMarker = function(time, label, color) {
        this.markers.push({ time: time, label: label || '', color: color || '#6B46C1' });
        this._renderMarkers();
    };

    VideoPlayer.prototype.clearMarkers = function() {
        this.markers = [];
        this._renderMarkers();
    };

    /* =====================================================
       Performance Metrics
       ===================================================== */
    VideoPlayer.prototype.getPerformanceMetrics = function() {
        var metrics = {
            droppedFrames: this._droppedFrames,
            totalFrames: this._totalFrames,
            dropRate: this._totalFrames > 0 ? (this._droppedFrames / this._totalFrames * 100).toFixed(2) + '%' : '0%',
            hwAccelerated: this.hwAccelSupported,
            capabilities: this.hwCaps
        };

        if (this.hwCaps.playbackQuality && this.video) {
            var q = this.video.getVideoPlaybackQuality();
            if (q) {
                metrics.droppedFrames = q.droppedVideoFrames;
                metrics.totalFrames = q.totalVideoFrames;
                metrics.dropRate = q.totalVideoFrames > 0
                    ? (q.droppedVideoFrames / q.totalVideoFrames * 100).toFixed(2) + '%' : '0%';
            }
        }

        return metrics;
    };

    /* =====================================================
       Event Subscription & Utilities
       ===================================================== */
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
        this._syncPartners = [];
        this.loopA = null;
        this.loopB = null;
        this.loopEnabled = false;
        if (this._perfInterval) {
            clearInterval(this._perfInterval);
        }
    };

    /* =====================================================
       Initialize players on page
       ===================================================== */
    function initVideoPlayers() {
        document.querySelectorAll('.video-player').forEach(function(el) {
            if (!el._vrPlayer) {
                el._vrPlayer = new VideoPlayer(el);
            }
        });

        // Click-to-seek handler for progress and timeline bars
        function addSeekHandler(selector) {
            document.querySelectorAll(selector).forEach(function(bar) {
                bar.addEventListener('click', function(e) {
                    var rect = bar.getBoundingClientRect();
                    var percent = (e.clientX - rect.left) / rect.width;
                    var player = document.querySelector('.video-player');
                    if (player && player._vrPlayer) {
                        player._vrPlayer.seek(percent * player._vrPlayer.getDuration());
                    }
                });
            });
        }
        addSeekHandler('.video-progress-bar');
        addSeekHandler('.timeline-bar');

        // Initialize speed selector buttons
        document.querySelectorAll('.speed-option').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var rate = parseFloat(btn.getAttribute('data-rate'));
                var player = document.querySelector('.video-player');
                if (player && player._vrPlayer && !isNaN(rate)) {
                    player._vrPlayer.setPlaybackRate(rate);
                }
            });
        });

        // Log hardware acceleration status in development
        if (typeof console !== 'undefined' && console.log) {
            var caps = detectHardwareAcceleration();
            console.log('[VideoPlayer] Hardware acceleration: ' +
                (caps.hardwareAccelerated ? 'available' : 'software fallback'));
        }
    }

    // Auto-init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initVideoPlayers);
    } else {
        initVideoPlayers();
    }

    /* =====================================================
       Keyboard Shortcuts (Professional NLE-style)
       ===================================================== */
    document.addEventListener('keydown', function(e) {
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;

        var activeVideo = document.querySelector('.video-player');
        if (!activeVideo || !activeVideo._vrPlayer) return;

        var player = activeVideo._vrPlayer;

        switch (e.key) {
            // Play/Pause
            case ' ':
            case 'k':
            case 'K':
                e.preventDefault();
                player.togglePlay();
                break;

            // Seek
            case 'ArrowLeft':
                e.preventDefault();
                player.seekRelative(e.shiftKey ? -10 : -5);
                break;
            case 'ArrowRight':
                e.preventDefault();
                player.seekRelative(e.shiftKey ? 10 : 5);
                break;

            // Frame step
            case ',':
                e.preventDefault();
                player.frameStep(false);
                break;
            case '.':
                e.preventDefault();
                player.frameStep(true);
                break;

            // Speed (NLE-style)
            case 'j':
            case 'J':
                e.preventDefault();
                player.slowDown();
                break;
            case 'l':
            case 'L':
                e.preventDefault();
                player.speedUp();
                break;

            // Speed presets (1-8 map to SPEED_PRESETS)
            case '1': case '2': case '3': case '4':
            case '5': case '6': case '7': case '8':
                if (!e.ctrlKey && !e.metaKey && !e.altKey) {
                    e.preventDefault();
                    var idx = parseInt(e.key) - 1;
                    if (idx < SPEED_PRESETS.length) {
                        player.setPlaybackRate(SPEED_PRESETS[idx]);
                    }
                }
                break;

            // Speed increment
            case '+':
            case '=':
                e.preventDefault();
                player.setPlaybackRate(Math.min(4.0, player.playbackRate + 0.25));
                break;
            case '-':
            case '_':
                e.preventDefault();
                player.setPlaybackRate(Math.max(0.1, player.playbackRate - 0.25));
                break;

            // A/B Loop
            case '[':
                e.preventDefault();
                player.setLoopA();
                break;
            case ']':
                e.preventDefault();
                player.setLoopB();
                break;
            case '\\':
                e.preventDefault();
                player.toggleLoop();
                break;

            // Fullscreen
            case 'f':
            case 'F':
                e.preventDefault();
                player.toggleFullscreen();
                break;

            // Mute
            case 'm':
            case 'M':
                e.preventDefault();
                player.toggleMute();
                break;

            // Go to start/end
            case 'Home':
                e.preventDefault();
                player.seek(0);
                break;
            case 'End':
                e.preventDefault();
                player.seek(player.getDuration());
                break;
        }
    });

    // Expose globally
    window.VideoPlayer = VideoPlayer;
    window.formatTimecode = formatTimecode;
    window.formatDuration = formatDuration;

})();
