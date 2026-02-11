/**
 * Arctic Wolves Video Review - Device Sync
 * Handles pairing a viewer device with a controller device
 * for live telestration sessions using polling
 */
(function() {
    'use strict';

    /**
     * DeviceSync - Manages viewer/controller device pairing
     * @param {Object} options
     * @param {string} options.role - 'viewer' or 'controller'
     * @param {string} options.sessionCode - Pairing code (required for controller)
     * @param {string} options.csrfToken - CSRF token for API calls
     */
    function DeviceSync(options) {
        this.role = options.role || 'viewer';
        this.sessionCode = options.sessionCode || '';
        this.csrfToken = options.csrfToken || '';
        this.status = 'disconnected'; // disconnected, waiting, paired, active, expired
        this.pollInterval = null;
        this.heartbeatInterval = null;
        this.sessionId = null;
        this.pollIntervalMs = options.pollIntervalMs || 3000;
        this.heartbeatIntervalMs = options.heartbeatIntervalMs || 10000;
        this.onStatusChange = options.onStatusChange || function() {};
        this.onCommand = options.onCommand || function() {};
        this.onError = options.onError || function() {};
    }

    DeviceSync.prototype.start = function() {
        if (this.role === 'viewer') {
            this._createSession();
        } else if (this.role === 'controller') {
            this._joinSession();
        }
    };

    DeviceSync.prototype._createSession = function() {
        var self = this;
        var formData = new FormData();
        formData.append('action', 'create_session');
        formData.append('csrf_token', this.csrfToken);

        fetch('api/device_sync.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    self.sessionCode = data.session_code;
                    self.sessionId = data.session_id;
                    self._setStatus('waiting');
                    self._startPolling();
                    self._startHeartbeat();
                } else {
                    self.onError(data.error || 'Failed to create session');
                }
            })
            .catch(function(err) {
                self.onError('Network error: ' + err.message);
            });
    };

    DeviceSync.prototype._joinSession = function() {
        var self = this;
        var formData = new FormData();
        formData.append('action', 'join_session');
        formData.append('session_code', this.sessionCode);
        formData.append('csrf_token', this.csrfToken);

        fetch('api/device_sync.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    self.sessionId = data.session_id;
                    self._setStatus('paired');
                    self._startHeartbeat();
                } else {
                    self.onError(data.error || 'Failed to join session');
                }
            })
            .catch(function(err) {
                self.onError('Network error: ' + err.message);
            });
    };

    DeviceSync.prototype._startPolling = function() {
        var self = this;
        this.pollInterval = setInterval(function() {
            self._poll();
        }, self.pollIntervalMs);
    };

    DeviceSync.prototype._poll = function() {
        var self = this;
        var formData = new FormData();
        formData.append('action', 'poll');
        formData.append('session_id', this.sessionId);
        formData.append('role', this.role);
        formData.append('csrf_token', this.csrfToken);

        fetch('api/device_sync.php', { method: 'POST', body: formData })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    if (data.status && data.status !== self.status) {
                        self._setStatus(data.status);
                    }
                    if (data.command) {
                        self.onCommand(data.command);
                    }
                } else if (data.expired) {
                    self._setStatus('expired');
                    self.stop();
                }
            })
            .catch(function() {
                // Silent polling failure
            });
    };

    DeviceSync.prototype._startHeartbeat = function() {
        var self = this;
        this.heartbeatInterval = setInterval(function() {
            self._sendHeartbeat();
        }, self.heartbeatIntervalMs);
    };

    DeviceSync.prototype._sendHeartbeat = function() {
        var formData = new FormData();
        formData.append('action', 'heartbeat');
        formData.append('session_id', this.sessionId);
        formData.append('csrf_token', this.csrfToken);

        fetch('api/device_sync.php', { method: 'POST', body: formData })
            .catch(function() { /* silent */ });
    };

    DeviceSync.prototype.sendCommand = function(command) {
        var formData = new FormData();
        formData.append('action', 'send_command');
        formData.append('session_id', this.sessionId);
        formData.append('command', JSON.stringify(command));
        formData.append('csrf_token', this.csrfToken);

        fetch('api/device_sync.php', { method: 'POST', body: formData })
            .catch(function() { /* silent */ });
    };

    DeviceSync.prototype._setStatus = function(status) {
        this.status = status;
        this.onStatusChange(status, this.sessionCode);
    };

    DeviceSync.prototype.stop = function() {
        if (this.pollInterval) {
            clearInterval(this.pollInterval);
            this.pollInterval = null;
        }
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
        this._setStatus('disconnected');
    };

    DeviceSync.prototype.getSessionCode = function() {
        return this.sessionCode;
    };

    // Expose globally
    window.DeviceSync = DeviceSync;

})();
