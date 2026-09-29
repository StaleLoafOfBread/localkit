/**
 * Petkit Timeline Scrubber Alpine.js Component
 *
 * Provides a CCTV / NVR style horizontal timeline scrubber with
 * continuous temporal navigation, event snapping, video & image preview,
 * rich info cards for non-media events, and keyboard controls.
 */

(function () {
    'use strict';

    const COLOR_HEX_MAP = {
        warning: '#f59e0b',
        success: '#10b981',
        info: '#06b6d4',
        primary: '#3b82f6',
        danger: '#ef4444',
        gray: '#6b7280',
        pink: '#ec4899',
        purple: '#a855f7',
    };

    function petkitTimelineScrubber(config = {}) {
        return {
            events: Array.isArray(config.events) ? config.events : [],
            currentIndex: 0,
            currentTimestamp: 0,
            isPlaying: false,
            playbackSpeed: 1,
            zoom: 'auto', // '1h', '6h', '12h', '24h', '7d', 'all', 'auto'
            pixelsPerSecond: 0.5,
            showData: false,
            showFilmstrip: true,
            isFullscreen: false,
            isMuted: false,
            volume: 1,
            hoverEvent: null,
            hoverScreenX: 0,
            hoverScreenY: 0,
            playTimer: null,
            minTime: 0,
            maxTime: 0,
            tapeWidth: 2000,
            rulerTicks: [],
            dateBoundaries: [],
            isDragging: false,
            dragStartX: 0,
            hasDragged: false,
            lastPointerX: 0,
            autoScrollVelocity: 0,
            autoScrollRafId: null,

            init() {
                this.calculateTimeBounds();
                this.calculateZoomScale();
                this.generateRuler();

                if (this.events.length > 0) {
                    // Start at the first (earliest) event by default
                    this.seekToEvent(0);
                } else {
                    this.currentTimestamp = Math.floor(Date.now() / 1000);
                }

                document.addEventListener('fullscreenchange', () => {
                    this.isFullscreen = !!document.fullscreenElement;
                });

                this.$watch('zoom', () => {
                    this.calculateZoomScale();
                    this.generateRuler();
                    this.syncScrollToCurrentTime();
                });

                this.$nextTick(() => {
                    this.syncScrollToCurrentTime();
                });
            },

            calculateTimeBounds() {
                if (this.events.length === 0) {
                    const now = Math.floor(Date.now() / 1000);
                    this.minTime = now - 86400;
                    this.maxTime = now;
                    return;
                }

                const firstTs = this.events[0].timestamp;
                const lastTs = this.events[this.events.length - 1].timestamp;

                // Add padding around earliest and latest events (at least 30 minutes)
                const padding = Math.max(1800, Math.floor((lastTs - firstTs) * 0.05));
                this.minTime = firstTs - padding;
                this.maxTime = lastTs + padding;

                if (this.maxTime <= this.minTime) {
                    this.maxTime = this.minTime + 3600;
                }
            },

            calculateZoomScale() {
                const totalSeconds = Math.max(60, this.maxTime - this.minTime);

                let desiredWidth;
                switch (this.zoom) {
                    case '1h':
                        desiredWidth = totalSeconds * (1200 / 3600); // ~1200px per hour
                        break;
                    case '6h':
                        desiredWidth = totalSeconds * (800 / (6 * 3600));
                        break;
                    case '12h':
                        desiredWidth = totalSeconds * (600 / (12 * 3600));
                        break;
                    case '24h':
                        desiredWidth = totalSeconds * (1200 / 86400);
                        break;
                    case '7d':
                        desiredWidth = totalSeconds * (1400 / (7 * 86400));
                        break;
                    case 'all':
                        desiredWidth = 2000;
                        break;
                    default:
                        desiredWidth = Math.max(2000, Math.min(10000, totalSeconds * 0.2));
                        break;
                }

                this.tapeWidth = Math.max(1400, Math.round(desiredWidth));
                this.pixelsPerSecond = this.tapeWidth / totalSeconds;
            },

            generateRuler() {
                const ticks = [];
                const boundaries = [];
                const totalSeconds = this.maxTime - this.minTime;

                // Determine interval between major ticks (in seconds)
                let majorInterval = 3600; // 1 hour
                if (this.pixelsPerSecond > 0.5) {
                    majorInterval = 900; // 15 min
                } else if (this.pixelsPerSecond > 0.2) {
                    majorInterval = 1800; // 30 min
                } else if (this.pixelsPerSecond < 0.02) {
                    majorInterval = 86400; // 1 day
                } else if (this.pixelsPerSecond < 0.06) {
                    majorInterval = 21600; // 6 hours
                }

                const minorInterval = Math.max(60, Math.round(majorInterval / 4));

                // Align start to the nearest interval
                const startAligned = Math.floor(this.minTime / majorInterval) * majorInterval;
                let lastDateStr = null;

                for (let t = startAligned; t <= this.maxTime + majorInterval; t += minorInterval) {
                    if (t < this.minTime) continue;

                    const isMajor = (t % majorInterval === 0);
                    const left = this.timeToPixels(t);

                    const dateObj = new Date(t * 1000);
                    const dateStr = dateObj.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
                    const timeStr = dateObj.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });

                    // Date boundary tag at midnight or first visible date
                    if (dateStr !== lastDateStr && isMajor) {
                        boundaries.push({
                            left,
                            label: dateStr,
                        });
                        lastDateStr = dateStr;
                    }

                    if (isMajor) {
                        ticks.push({
                            left,
                            isMajor: true,
                            label: timeStr,
                        });
                    } else {
                        ticks.push({
                            left,
                            isMajor: false,
                            label: null,
                        });
                    }
                }

                this.rulerTicks = ticks;
                this.dateBoundaries = boundaries;
            },

            timeToPixels(timestamp) {
                if (this.maxTime <= this.minTime) return 0;
                return Math.round((timestamp - this.minTime) * this.pixelsPerSecond);
            },

            pixelsToTime(px) {
                if (this.pixelsPerSecond <= 0) return this.minTime;
                return Math.round(this.minTime + (px / this.pixelsPerSecond));
            },

            currentEvent() {
                if (!this.events || this.events.length === 0) {
                    return {
                        id: null,
                        title: '',
                        type_title: '',
                        color: 'gray',
                        icon: 'heroicon-m-bolt',
                        datetime: '',
                        diff: '',
                        duration: null,
                        device_name: null,
                        pet_name: null,
                        media_type: null,
                        video_url: null,
                        image_url: null,
                        parameters: null,
                    };
                }
                const idx = Math.max(0, Math.min(this.events.length - 1, this.currentIndex || 0));
                return this.events[idx] || this.events[0];
            },

            seekToEvent(index) {
                if (this.events.length === 0) return;

                const clampedIndex = Math.max(0, Math.min(this.events.length - 1, index));
                this.currentIndex = clampedIndex;
                const event = this.events[clampedIndex];

                if (event) {
                    this.currentTimestamp = event.timestamp;
                    this.syncScrollToCurrentTime();
                    this.handleMediaOnSeek(event);
                }
            },

            findClosestEventIndex(ts) {
                if (!this.events || this.events.length === 0) return -1;
                let closestIdx = 0;
                let minDiff = Infinity;
                for (let idx = 0; idx < this.events.length; idx++) {
                    const diff = Math.abs(this.events[idx].timestamp - ts);
                    if (diff < minDiff) {
                        minDiff = diff;
                        closestIdx = idx;
                    }
                }
                return closestIdx;
            },

            seekToTimestamp(ts) {
                if (!this.events || this.events.length === 0) return;

                const clampedTs = Math.max(this.minTime, Math.min(this.maxTime, ts));
                const closestIdx = this.findClosestEventIndex(clampedTs);
                if (closestIdx !== -1) {
                    this.seekToEvent(closestIdx);
                }
            },

            applyPlaybackRate(player) {
                if (!player) return;
                try {
                    player.playbackRate = this.playbackSpeed;
                } catch (_) {
                    try {
                        player.playbackRate = Math.min(16, this.playbackSpeed);
                    } catch (e) {
                        console.warn('Playback rate error:', e);
                    }
                }
            },

            handleMediaOnSeek(event) {
                if (this.playTimer) {
                    clearTimeout(this.playTimer);
                    clearInterval(this.playTimer);
                    this.playTimer = null;
                }

                if (!event) return;

                this.$nextTick(() => {
                    if (event.media_type === 'video' && this.$refs.videoPlayer) {
                        try {
                            this.$refs.videoPlayer.currentTime = 0;
                            this.applyPlaybackRate(this.$refs.videoPlayer);
                            this.$refs.videoPlayer.muted = this.isMuted;
                            this.$refs.videoPlayer.volume = this.volume;
                            if (this.isPlaying) {
                                this.$refs.videoPlayer.play().catch(() => {
                                    if (!this.isMuted) {
                                        this.$refs.videoPlayer.muted = true;
                                        this.$refs.videoPlayer.play().catch((e) => console.warn(e));
                                    }
                                });
                            }
                        } catch (e) {
                            console.warn('Video playback error:', e);
                        }
                    }

                    // Non-video events hold for a brief dwell time then advance
                    if (this.isPlaying && event.media_type !== 'video') {
                        const dwellMs = Math.max(40, Math.round(2500 / this.playbackSpeed));
                        this.playTimer = setTimeout(() => {
                            if (this.isPlaying) {
                                if (this.currentIndex < this.events.length - 1) {
                                    this.nextEvent();
                                } else {
                                    this.pause();
                                }
                            }
                        }, dwellMs);
                    }
                });
            },

            onVideoEnded() {
                if (this.isPlaying) {
                    if (this.currentIndex < this.events.length - 1) {
                        this.nextEvent();
                    } else {
                        this.pause();
                    }
                }
            },

            onVideoTimeUpdate() {
                if (!this.isPlaying || !this.$refs.videoPlayer || this.isDragging) return;
                const ev = this.currentEvent();
                if (ev && ev.media_type === 'video') {
                    const offsetSec = Math.floor(this.$refs.videoPlayer.currentTime || 0);
                    this.currentTimestamp = ev.timestamp + offsetSec;
                }
            },

            syncScrollToCurrentTime() {
                const container = this.$refs.tapeContainer;
                if (!container) return;

                const tape = this.$refs.tape;
                const tapeOffset = tape ? tape.offsetLeft : 0;
                const playheadPx = this.timeToPixels(this.currentTimestamp);
                const halfViewport = container.clientWidth / 2;

                container.scrollTo({
                    left: Math.max(0, tapeOffset + playheadPx - halfViewport),
                    behavior: 'smooth',
                });
            },

            startAutoScrollLoop() {
                if (this.autoScrollRafId) return;

                const loop = () => {
                    if (!this.isDragging) {
                        this.autoScrollRafId = null;
                        return;
                    }

                    if (this.autoScrollVelocity !== 0) {
                        const container = this.$refs.tapeContainer;
                        if (container) {
                            container.scrollLeft += this.autoScrollVelocity;

                            // Recalculate time under the pointer as the tape scrolls
                            const tape = this.$refs.tape || container;
                            const tapeRect = tape.getBoundingClientRect();
                            const clickX = this.lastPointerX - tapeRect.left;
                            const targetTime = Math.max(this.minTime, Math.min(this.maxTime, this.pixelsToTime(clickX)));

                            this.currentTimestamp = targetTime;
                            const closestIdx = this.findClosestEventIndex(targetTime);
                            if (closestIdx !== -1 && closestIdx !== this.currentIndex) {
                                this.currentIndex = closestIdx;
                            }
                        }
                    }

                    this.autoScrollRafId = requestAnimationFrame(loop);
                };

                this.autoScrollRafId = requestAnimationFrame(loop);
            },

            stopAutoScrollLoop() {
                this.autoScrollVelocity = 0;
                if (this.autoScrollRafId) {
                    cancelAnimationFrame(this.autoScrollRafId);
                    this.autoScrollRafId = null;
                }
            },

            onTapePointerDown(e) {
                if (e.button !== undefined && e.button !== 0) return;

                const container = this.$refs.tapeContainer;
                if (!container) return;

                // Do not intercept scrollbar track or thumb interactions (bottom 16px of container)
                const containerRect = container.getBoundingClientRect();
                if (e.clientY >= containerRect.bottom - 16) {
                    return;
                }

                if (this.isPlaying) {
                    this.pause();
                }

                this.isDragging = true;
                this.hasDragged = false;
                this.dragStartX = e.clientX;
                this.lastPointerX = e.clientX;
                this.autoScrollVelocity = 0;

                try {
                    container.setPointerCapture(e.pointerId);
                } catch (_) {}

                const tape = this.$refs.tape || container;
                const tapeRect = tape.getBoundingClientRect();
                const clickX = e.clientX - tapeRect.left;
                const targetTime = Math.max(this.minTime, Math.min(this.maxTime, this.pixelsToTime(clickX)));

                this.currentTimestamp = targetTime;
                const closestIdx = this.findClosestEventIndex(targetTime);
                if (closestIdx !== -1 && closestIdx !== this.currentIndex) {
                    this.currentIndex = closestIdx;
                }

                this.startAutoScrollLoop();
            },

            onTapePointerMove(e) {
                if (!this.isDragging) return;

                const container = this.$refs.tapeContainer;
                if (!container) return;

                this.lastPointerX = e.clientX;

                if (Math.abs(e.clientX - this.dragStartX) > 3) {
                    this.hasDragged = true;
                }

                const tape = this.$refs.tape || container;
                const tapeRect = tape.getBoundingClientRect();
                const clickX = e.clientX - tapeRect.left;
                const targetTime = Math.max(this.minTime, Math.min(this.maxTime, this.pixelsToTime(clickX)));

                this.currentTimestamp = targetTime;
                const closestIdx = this.findClosestEventIndex(targetTime);
                if (closestIdx !== -1 && closestIdx !== this.currentIndex) {
                    this.currentIndex = closestIdx;
                }

                // Continuous edge auto-scroll calculation
                const containerRect = container.getBoundingClientRect();
                const edgeZone = 90;
                const mouseRelX = e.clientX - containerRect.left;

                if (mouseRelX > containerRect.width - edgeZone) {
                    // Right edge or past right edge
                    const overflow = Math.max(0, mouseRelX - (containerRect.width - edgeZone));
                    const factor = Math.min(5, 1 + (overflow / 35));
                    this.autoScrollVelocity = Math.round(14 * factor);
                } else if (mouseRelX < edgeZone) {
                    // Left edge or past left edge
                    const overflow = Math.max(0, edgeZone - mouseRelX);
                    const factor = Math.min(5, 1 + (overflow / 35));
                    this.autoScrollVelocity = -Math.round(14 * factor);
                } else {
                    this.autoScrollVelocity = 0;
                }
            },

            onTapePointerUp(e) {
                if (!this.isDragging) return;

                const container = this.$refs.tapeContainer;
                if (container) {
                    try {
                        container.releasePointerCapture(e.pointerId);
                    } catch (_) {}
                }

                this.stopAutoScrollLoop();
                this.isDragging = false;

                if (this.events.length > 0) {
                    const clampedIndex = Math.max(0, Math.min(this.events.length - 1, this.currentIndex));
                    this.currentIndex = clampedIndex;
                    const ev = this.events[clampedIndex];
                    if (ev) {
                        this.handleMediaOnSeek(ev);
                    }
                }
            },

            onTapePointerCancel(e) {
                this.onTapePointerUp(e);
            },

            onTapeWheel(e) {
                const container = this.$refs.tapeContainer;
                if (!container) return;

                const delta = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
                if (Math.abs(delta) > 0) {
                    e.preventDefault();
                    container.scrollLeft += delta;
                }
            },

            onTapeClick(e) {
                if (this.hasDragged) {
                    this.hasDragged = false;
                    return;
                }

                const container = this.$refs.tapeContainer;
                if (!container) return;

                const containerRect = container.getBoundingClientRect();
                if (e.clientY >= containerRect.bottom - 16) {
                    return;
                }

                const tape = this.$refs.tape || container;
                const tapeRect = tape.getBoundingClientRect();
                const clickX = e.clientX - tapeRect.left;
                const targetTime = this.pixelsToTime(clickX);

                this.seekToTimestamp(targetTime);
            },

            nextEvent() {
                if (this.currentIndex < this.events.length - 1) {
                    this.seekToEvent(this.currentIndex + 1);
                }
            },

            prevEvent() {
                if (this.currentIndex > 0) {
                    this.seekToEvent(this.currentIndex - 1);
                }
            },

            firstEvent() {
                if (this.events.length > 0) {
                    this.seekToEvent(0);
                }
            },

            lastEvent() {
                if (this.events.length > 0) {
                    this.seekToEvent(this.events.length - 1);
                }
            },

            togglePlay() {
                if (this.isPlaying) {
                    this.pause();
                } else {
                    this.play();
                }
            },

            play() {
                if (this.events.length === 0) return;

                // If playback ended at the final event, loop back to the first event
                if (this.currentIndex === this.events.length - 1) {
                    const player = this.$refs.videoPlayer;
                    if (player && (player.ended || player.currentTime >= player.duration)) {
                        this.seekToEvent(0);
                        this.isPlaying = true;
                        return;
                    }
                }

                this.isPlaying = true;

                const ev = this.currentEvent();
                if (ev) {
                    if (ev.media_type === 'video' && this.$refs.videoPlayer) {
                        try {
                            if (this.$refs.videoPlayer.ended) {
                                this.$refs.videoPlayer.currentTime = 0;
                            }
                            this.applyPlaybackRate(this.$refs.videoPlayer);
                            this.$refs.videoPlayer.muted = this.isMuted;
                            this.$refs.videoPlayer.volume = this.volume;
                            this.$refs.videoPlayer.play().catch(() => {
                                if (!this.isMuted) {
                                    this.$refs.videoPlayer.muted = true;
                                    this.$refs.videoPlayer.play().catch((e) => console.warn(e));
                                }
                            });
                        } catch (e) {
                            console.warn(e);
                        }
                    } else if (ev.media_type !== 'video') {
                        if (this.playTimer) {
                            clearTimeout(this.playTimer);
                            this.playTimer = null;
                        }
                        const dwellMs = Math.max(40, Math.round(2500 / this.playbackSpeed));
                        this.playTimer = setTimeout(() => {
                            if (this.isPlaying) {
                                if (this.currentIndex < this.events.length - 1) {
                                    this.nextEvent();
                                } else {
                                    this.pause();
                                }
                            }
                        }, dwellMs);
                    }
                }
            },

            pause() {
                this.isPlaying = false;
                if (this.playTimer) {
                    clearTimeout(this.playTimer);
                    clearInterval(this.playTimer);
                    this.playTimer = null;
                }

                if (this.$refs.videoPlayer) {
                    this.$refs.videoPlayer.pause();
                }
            },

            startSnapPlayback() {
                if (this.playTimer) {
                    clearTimeout(this.playTimer);
                    clearInterval(this.playTimer);
                    this.playTimer = null;
                }

                const ev = this.currentEvent();
                if (ev) {
                    this.handleMediaOnSeek(ev);
                }
            },

            setSpeed(speed) {
                this.playbackSpeed = Number(speed);
                if (this.$refs.videoPlayer) {
                    this.applyPlaybackRate(this.$refs.videoPlayer);
                }
                if (this.isPlaying) {
                    const ev = this.currentEvent();
                    if (ev && ev.media_type !== 'video') {
                        if (this.playTimer) {
                            clearTimeout(this.playTimer);
                            this.playTimer = null;
                        }
                        const dwellMs = Math.max(40, Math.round(2500 / this.playbackSpeed));
                        this.playTimer = setTimeout(() => {
                            if (this.isPlaying) {
                                if (this.currentIndex < this.events.length - 1) {
                                    this.nextEvent();
                                } else {
                                    this.pause();
                                }
                            }
                        }, dwellMs);
                    }
                }
            },

            setZoom(zoomLevel) {
                this.zoom = zoomLevel;
            },

            toggleMute() {
                this.isMuted = !this.isMuted;
                if (this.$refs.videoPlayer) {
                    this.$refs.videoPlayer.muted = this.isMuted;
                }
            },

            setVolume(vol) {
                this.volume = Math.max(0, Math.min(1, Number(vol)));
                this.isMuted = this.volume === 0;
                if (this.$refs.videoPlayer) {
                    this.$refs.videoPlayer.volume = this.volume;
                    this.$refs.videoPlayer.muted = this.isMuted;
                }
            },

            toggleFullscreen() {
                const target = this.$refs.stagePanel;
                if (!document.fullscreenElement) {
                    if (target && target.requestFullscreen) {
                        target.requestFullscreen().catch((err) => console.warn(err));
                    }
                } else {
                    if (document.exitFullscreen) {
                        document.exitFullscreen().catch((err) => console.warn(err));
                    }
                }
            },

            getEventColorHex(colorName) {
                return COLOR_HEX_MAP[colorName] || COLOR_HEX_MAP.gray;
            },

            setHoverEvent(event, mouseEvent) {
                this.hoverEvent = event;
                const stage = this.$refs.stagePanel;
                if (!stage || !mouseEvent) return;

                const stageRect = stage.getBoundingClientRect();
                const targetRect = mouseEvent.currentTarget.getBoundingClientRect();
                const centerX = targetRect.left + (targetRect.width / 2) - stageRect.left;
                const topY = targetRect.top - stageRect.top;

                // Keep hover card safely within stage margins
                this.hoverScreenX = Math.max(120, Math.min(stageRect.width - 120, centerX));
                this.hoverScreenY = topY;
            },

            clearHoverEvent() {
                this.hoverEvent = null;
            },

            formatTimestampLabel(ts) {
                if (!ts) return '';
                const d = new Date(ts * 1000);
                return d.toLocaleTimeString(undefined, {
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                });
            },

            formatTimeOnly(ts) {
                if (!ts) return '';
                const d = new Date(ts * 1000);
                return d.toLocaleTimeString(undefined, {
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                });
            },

            formatFullDateTime(ts) {
                if (!ts) return '';
                const d = new Date(ts * 1000);
                return d.toLocaleString(undefined, {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    second: '2-digit',
                });
            },
        };
    }

    window.petkitTimelineScrubber = petkitTimelineScrubber;
})();
