/**
 * Petkit Timelapse Player Alpine.js Component
 *
 * Handles frame preloading, canvas drawing with aspect-ratio fitting,
 * variable FPS playback loop, scrubber seeking, and keyboard controls.
 */

(function () {
    'use strict';

    const DEFAULT_FPS = 2;
    const CANVAS_FILL_STYLE = '#000000';
    const MILLISECONDS_PER_SECOND = 1000;

    function petkitTimelapsePlayer(config = {}) {
        return {
            open: false,
            isFullscreen: false,
            frames: Array.isArray(config.frames) ? config.frames : [],
            downloadUrl: config.downloadUrl || null,
            currentIndex: 0,
            isPlaying: false,
            timer: null,
            fps: Number(config.fps) || DEFAULT_FPS,
            showData: false,
            isLoaded: false,
            loadedImages: [],

            init() {
                document.addEventListener('fullscreenchange', () => {
                    this.isFullscreen = !!document.fullscreenElement;
                });

                this.$watch('open', (value) => {
                    if (value) {
                        this.currentIndex = 0;
                        this.preloadImages();
                        this.renderCurrentFrame();
                        return;
                    }

                    this.stop();
                    if (this.isFullscreen && document.exitFullscreen) {
                        document.exitFullscreen().catch(() => {});
                    }
                });
            },

            toggleFullscreen() {
                const target = this.$refs.modalPanel;
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

            preloadImages() {
                this.loadedImages = [];

                this.frames.forEach((frame, idx) => {
                    if (frame && frame.url) {
                        const img = new Image();
                        img.src = frame.url;
                        this.loadedImages[idx] = img;
                    }
                });
            },

            renderCurrentFrame() {
                if (!this.frames || this.frames.length === 0) {
                    return;
                }

                const frame = this.frames[this.currentIndex];
                if (!frame) {
                    return;
                }

                const canvas = this.$refs.canvas;
                if (!canvas) {
                    return;
                }

                const ctx = canvas.getContext('2d');
                const img = this.loadedImages[this.currentIndex] || new Image();

                if (img.complete && img.naturalWidth > 0) {
                    this.drawFrame(ctx, canvas, img, frame);
                    return;
                }

                img.onload = () => {
                    this.drawFrame(ctx, canvas, img, frame);
                };

                if (!img.src && frame.url) {
                    img.src = frame.url;
                }
            },

            drawFrame(ctx, canvas, img, frame) {
                ctx.fillStyle = CANVAS_FILL_STYLE;
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                if (img.naturalWidth > 0) {
                    const hRatio = canvas.width / img.naturalWidth;
                    const vRatio = canvas.height / img.naturalHeight;
                    const ratio = Math.min(hRatio, vRatio);
                    const centerShiftX = (canvas.width - img.naturalWidth * ratio) / 2;
                    const centerShiftY = (canvas.height - img.naturalHeight * ratio) / 2;

                    ctx.drawImage(
                        img,
                        0,
                        0,
                        img.naturalWidth,
                        img.naturalHeight,
                        centerShiftX,
                        centerShiftY,
                        img.naturalWidth * ratio,
                        img.naturalHeight * ratio,
                    );
                }
            },

            play() {
                if (this.frames.length <= 1) {
                    return;
                }

                this.isPlaying = true;
                this.scheduleNext();
            },

            scheduleNext() {
                if (!this.isPlaying) {
                    return;
                }

                const interval = MILLISECONDS_PER_SECOND / this.fps;

                this.timer = setTimeout(() => {
                    if (!this.isPlaying) {
                        return;
                    }

                    this.currentIndex = (this.currentIndex + 1) % this.frames.length;
                    this.renderCurrentFrame();
                    this.scheduleNext();
                }, interval);
            },

            pause() {
                this.isPlaying = false;

                if (this.timer) {
                    clearTimeout(this.timer);
                    this.timer = null;
                }
            },

            togglePlay() {
                if (this.isPlaying) {
                    this.pause();
                    return;
                }

                this.play();
            },

            stop() {
                this.pause();
                this.currentIndex = 0;
            },

            nextFrame() {
                if (this.frames.length === 0) {
                    return;
                }

                this.pause();
                this.currentIndex = (this.currentIndex + 1) % this.frames.length;
                this.renderCurrentFrame();
            },

            prevFrame() {
                if (this.frames.length === 0) {
                    return;
                }

                this.pause();
                this.currentIndex = (this.currentIndex - 1 + this.frames.length) % this.frames.length;
                this.renderCurrentFrame();
            },

            seek(index) {
                this.pause();
                this.currentIndex = Math.max(0, Math.min(index, this.frames.length - 1));
                this.renderCurrentFrame();
            },

            setFps(val) {
                this.fps = Number(val);

                if (this.isPlaying) {
                    this.pause();
                    this.play();
                }
            },

            currentFrame() {
                return this.frames[this.currentIndex] || {};
            },

            currentItem() {
                return this.currentFrame();
            },

            getDownloadUrl() {
                if (!this.downloadUrl) {
                    return '#';
                }

                try {
                    const url = new URL(this.downloadUrl, window.location.origin);
                    url.searchParams.set('fps', this.fps);
                    return url.toString();
                } catch (e) {
                    const separator = this.downloadUrl.includes('?') ? '&' : '?';
                    return `${this.downloadUrl}${separator}fps=${encodeURIComponent(this.fps)}`;
                }
            },
        };
    }

    window.petkitTimelapsePlayer = petkitTimelapsePlayer;

    if (window.Alpine) {
        window.Alpine.data('petkitTimelapsePlayer', petkitTimelapsePlayer);
    } else {
        document.addEventListener('alpine:init', () => {
            window.Alpine.data('petkitTimelapsePlayer', petkitTimelapsePlayer);
        });
    }
})();
