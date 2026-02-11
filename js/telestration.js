/**
 * Arctic Wolves Video Review - Telestration Engine
 * Canvas-based drawing overlay for video annotation
 * Supports: freehand, arrows, lines, rectangles, circles, text, player markers
 */
(function() {
    'use strict';

    /**
     * Telestration - Canvas drawing overlay for video
     * @param {HTMLCanvasElement} canvas
     * @param {Object} options
     */
    function Telestration(canvas, options) {
        if (!canvas) return;

        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.options = options || {};

        this.isDrawing = false;
        this.tool = 'freehand'; // freehand, arrow, line, rectangle, circle, text, player_marker
        this.color = '#EF4444';
        this.lineWidth = 3;
        this.fontSize = 16;

        this.strokes = [];       // Completed strokes
        this.currentStroke = null; // In-progress stroke
        this.undoStack = [];

        this.referenceWidth = options.referenceWidth || 1920;
        this.referenceHeight = options.referenceHeight || 1080;

        this._bindEvents();
        this._resizeCanvas();
    }

    Telestration.prototype._resizeCanvas = function() {
        var parent = this.canvas.parentElement;
        if (parent) {
            this.canvas.width = parent.clientWidth;
            this.canvas.height = parent.clientHeight;
        }
        this.redraw();
    };

    Telestration.prototype._bindEvents = function() {
        var self = this;

        // Pointer events for cross-device support
        this.canvas.addEventListener('pointerdown', function(e) { self._onPointerDown(e); });
        this.canvas.addEventListener('pointermove', function(e) { self._onPointerMove(e); });
        this.canvas.addEventListener('pointerup', function(e) { self._onPointerUp(e); });
        this.canvas.addEventListener('pointerleave', function(e) { self._onPointerUp(e); });

        // Prevent context menu on canvas
        this.canvas.addEventListener('contextmenu', function(e) { e.preventDefault(); });

        // Resize handler
        window.addEventListener('resize', function() { self._resizeCanvas(); });
    };

    Telestration.prototype._getPos = function(e) {
        var rect = this.canvas.getBoundingClientRect();
        return {
            x: (e.clientX - rect.left) / rect.width,
            y: (e.clientY - rect.top) / rect.height
        };
    };

    Telestration.prototype._onPointerDown = function(e) {
        e.preventDefault();
        this.isDrawing = true;
        var pos = this._getPos(e);

        this.currentStroke = {
            tool: this.tool,
            color: this.color,
            lineWidth: this.lineWidth,
            fontSize: this.fontSize,
            points: [pos],
            startX: pos.x,
            startY: pos.y
        };

        if (this.tool === 'text') {
            this.isDrawing = false;
            var text = prompt('Enter annotation text:');
            if (text) {
                this.currentStroke.text = text;
                this.strokes.push(this.currentStroke);
                this.undoStack = [];
                this.redraw();
            }
            this.currentStroke = null;
        }
    };

    Telestration.prototype._onPointerMove = function(e) {
        if (!this.isDrawing || !this.currentStroke) return;
        e.preventDefault();
        var pos = this._getPos(e);

        if (this.tool === 'freehand') {
            this.currentStroke.points.push(pos);
        } else {
            this.currentStroke.endX = pos.x;
            this.currentStroke.endY = pos.y;
        }

        this.redraw();
        this._drawStroke(this.currentStroke);
    };

    Telestration.prototype._onPointerUp = function(e) {
        if (!this.isDrawing) return;
        this.isDrawing = false;

        if (this.currentStroke) {
            if (this.tool !== 'freehand' || this.currentStroke.points.length > 1) {
                var pos = this._getPos(e);
                this.currentStroke.endX = pos.x;
                this.currentStroke.endY = pos.y;
                this.strokes.push(this.currentStroke);
                this.undoStack = [];
            }
            this.currentStroke = null;
        }

        this.redraw();
    };

    /* --------------------------------------------------
       Drawing
    -------------------------------------------------- */
    Telestration.prototype.redraw = function() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        for (var i = 0; i < this.strokes.length; i++) {
            this._drawStroke(this.strokes[i]);
        }
    };

    Telestration.prototype._drawStroke = function(stroke) {
        var ctx = this.ctx;
        var w = this.canvas.width;
        var h = this.canvas.height;

        ctx.strokeStyle = stroke.color;
        ctx.fillStyle = stroke.color;
        ctx.lineWidth = stroke.lineWidth;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';

        switch (stroke.tool) {
            case 'freehand':
                if (stroke.points.length < 2) return;
                ctx.beginPath();
                ctx.moveTo(stroke.points[0].x * w, stroke.points[0].y * h);
                for (var i = 1; i < stroke.points.length; i++) {
                    ctx.lineTo(stroke.points[i].x * w, stroke.points[i].y * h);
                }
                ctx.stroke();
                break;

            case 'line':
                if (stroke.endX === undefined) return;
                ctx.beginPath();
                ctx.moveTo(stroke.startX * w, stroke.startY * h);
                ctx.lineTo(stroke.endX * w, stroke.endY * h);
                ctx.stroke();
                break;

            case 'arrow':
                if (stroke.endX === undefined) return;
                this._drawArrow(ctx,
                    stroke.startX * w, stroke.startY * h,
                    stroke.endX * w, stroke.endY * h
                );
                break;

            case 'rectangle':
                if (stroke.endX === undefined) return;
                ctx.strokeRect(
                    stroke.startX * w, stroke.startY * h,
                    (stroke.endX - stroke.startX) * w,
                    (stroke.endY - stroke.startY) * h
                );
                break;

            case 'circle':
                if (stroke.endX === undefined) return;
                var rx = Math.abs(stroke.endX - stroke.startX) * w / 2;
                var ry = Math.abs(stroke.endY - stroke.startY) * h / 2;
                var cx = (stroke.startX + stroke.endX) / 2 * w;
                var cy = (stroke.startY + stroke.endY) / 2 * h;
                ctx.beginPath();
                ctx.ellipse(cx, cy, rx, ry, 0, 0, 2 * Math.PI);
                ctx.stroke();
                break;

            case 'text':
                if (!stroke.text) return;
                ctx.font = stroke.fontSize + 'px Inter, sans-serif';
                ctx.fillText(stroke.text, stroke.startX * w, stroke.startY * h);
                break;

            case 'player_marker':
                var px = stroke.startX * w;
                var py = stroke.startY * h;
                var radius = 14;
                ctx.beginPath();
                ctx.arc(px, py, radius, 0, 2 * Math.PI);
                ctx.fill();
                ctx.fillStyle = '#FFFFFF';
                ctx.font = 'bold 12px Inter, sans-serif';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(stroke.text || '#', px, py);
                ctx.textAlign = 'start';
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = stroke.color;
                break;
        }
    };

    Telestration.prototype._drawArrow = function(ctx, x1, y1, x2, y2) {
        var headLen = 15;
        var angle = Math.atan2(y2 - y1, x2 - x1);

        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.stroke();

        ctx.beginPath();
        ctx.moveTo(x2, y2);
        ctx.lineTo(x2 - headLen * Math.cos(angle - Math.PI / 6), y2 - headLen * Math.sin(angle - Math.PI / 6));
        ctx.lineTo(x2 - headLen * Math.cos(angle + Math.PI / 6), y2 - headLen * Math.sin(angle + Math.PI / 6));
        ctx.closePath();
        ctx.fill();
    };

    /* --------------------------------------------------
       Actions
    -------------------------------------------------- */
    Telestration.prototype.setTool = function(tool) {
        this.tool = tool;
    };

    Telestration.prototype.setColor = function(color) {
        this.color = color;
    };

    Telestration.prototype.setLineWidth = function(width) {
        this.lineWidth = width;
    };

    Telestration.prototype.undo = function() {
        if (this.strokes.length > 0) {
            this.undoStack.push(this.strokes.pop());
            this.redraw();
        }
    };

    Telestration.prototype.redo = function() {
        if (this.undoStack.length > 0) {
            this.strokes.push(this.undoStack.pop());
            this.redraw();
        }
    };

    Telestration.prototype.clear = function() {
        this.undoStack = this.undoStack.concat(this.strokes.reverse());
        this.strokes = [];
        this.redraw();
    };

    Telestration.prototype.exportData = function() {
        return JSON.stringify({
            strokes: this.strokes,
            referenceWidth: this.referenceWidth,
            referenceHeight: this.referenceHeight
        });
    };

    Telestration.prototype.importData = function(jsonStr) {
        try {
            var data = JSON.parse(jsonStr);
            this.strokes = data.strokes || [];
            this.referenceWidth = data.referenceWidth || 1920;
            this.referenceHeight = data.referenceHeight || 1080;
            this.redraw();
        } catch (e) {
            console.error('Failed to import telestration data:', e);
        }
    };

    Telestration.prototype.toDataURL = function() {
        return this.canvas.toDataURL('image/png');
    };

    Telestration.prototype.destroy = function() {
        this.strokes = [];
        this.undoStack = [];
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
    };

    // Expose globally
    window.Telestration = Telestration;

})();
