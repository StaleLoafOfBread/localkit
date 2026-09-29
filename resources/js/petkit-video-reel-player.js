/**
 * Petkit Video Reel Player Alpine.js Component
 *
 * Manages video reel playback, asynchronous progress polling during server-side
 * concatenation, time tracking, scrubber seeking, clip synchronization, and speed rates.
 */

(function () {
    'use strict';

    const DEFAULT_PLAYBACK_RATE = 1.0;
    const ELAPSED_INTERVAL_MS = 100;
    const PROGRESS_POLL_INTERVAL_MS = 1000;
    const FINISH_LOADING_DELAY_MS = 200;
    const RETRY_PLAY_DELAY_MS = 100;
    const PITCH_PRESERVATION_THRESHOLD = 2.0;
    const MILLISECONDS_PER_SECOND = 1000;
    const SECONDS_PER_MINUTE = 60;

    function petkitVideoReelPlayer(config = {}) {
        return {
            open: false,
            isFullscreen: false,
            clips: Array.isArray(config.clips) ? config.clips : [],
            videoUrl: config.videoUrl || null,
            downloadUrl: config.downloadUrl || null,
            progressUrl: config.progressUrl || null,
            isPlaying: false,
            isVideoReady: false,
            currentTime: 0,
            duration: 0,
            playbackRate: DEFAULT_PLAYBACK_RATE,
            showData: false,
            isLoading: false,
            hasError: false,
            errorMessage: '',
            loadingProgress: 0,
            loadingTitle: config.initialLoadingTitle || 'Initializing Assembly',
            loadingSubtitle: config.initialLoadingSubtitle || 'Preparing stream pipeline...',
            loadingCurrentClip: null,
            loadingTotalClips: config.clips ? config.clips.length : null,
            loadingStep: config.initialLoadingStep || 'Initializing H.264 stream assembly pipeline...',
            loadingInterval: null,
            progressPollInterval: null,
            eventSource: null,
            loadingStartTime: 0,
            loadingElapsedSeconds: 0,
            loadingEtaText: 'ETA: Calculating...',
            lastEtaSeconds: null,
            stats: null,
            isCached: false,
            hasNotifiedReady: false,

            videoPlayer() {
                return this.$refs.videoPlayer || null;
            },

            applyPlaybackRate() {
                const video = this.videoPlayer();
                if (!video) {
                    return;
                }

                video.playbackRate = this.playbackRate;
                if ('preservesPitch' in video) {
                    video.preservesPitch = this.playbackRate <= PITCH_PRESERVATION_THRESHOLD;
                }
            },

            clearIntervalByName(name) {
                if (this[name]) {
                    clearInterval(this[name]);
                    this[name] = null;
                }
            },

            clearTimers() {
                this.clearIntervalByName('loadingInterval');
                this.clearIntervalByName('progressPollInterval');
            },

            recomputeEta() {
                if (!this.isLoading || this.loadingProgress >= 100) {
                    this.loadingEtaText = '';
                    this.lastEtaSeconds = 0;
                    return;
                }

                const elapsed = (Date.now() - this.loadingStartTime) / MILLISECONDS_PER_SECOND;
                if (this.loadingProgress <= 0 || elapsed < 1.0) {
                    const total = this.loadingTotalClips;
                    if (total && total > 0) {
                        this.lastEtaSeconds = Math.max(5, Math.round(total * 0.24));
                        this.updateEtaText();
                    }
                    return;
                }

                const rate = this.loadingProgress / elapsed;
                if (rate > 0) {
                    const remainingPercent = Math.max(0, 100 - this.loadingProgress);
                    this.lastEtaSeconds = Math.max(0, Math.round(remainingPercent / rate));
                    this.updateEtaText();
                }
            },

            tickEtaCountdown() {
                if (this.lastEtaSeconds !== null && this.lastEtaSeconds > 0) {
                    this.lastEtaSeconds--;
                    this.updateEtaText();
                }
            },

            updateEtaText() {
                if (!this.isLoading || this.loadingProgress >= 100) {
                    this.loadingEtaText = '';
                    return;
                }

                const sec = this.lastEtaSeconds;
                if (sec === null || sec === undefined) {
                    this.loadingEtaText = 'ETA: Calculating...';
                    return;
                }

                if (sec <= 3) {
                    this.loadingEtaText = 'ETA: Almost done...';
                    return;
                }

                this.loadingEtaText = `ETA: ~${this.formatHumanSeconds(sec)}`;
            },

            formatHumanSeconds(sec) {
                const totalSec = Math.max(0, Math.round(sec));
                if (totalSec < 60) {
                    return `${totalSec}s`;
                }
                const m = Math.floor(totalSec / SECONDS_PER_MINUTE);
                const s = totalSec % SECONDS_PER_MINUTE;
                return s > 0 ? `${m}m ${s}s` : `${m}m`;
            },

            sendNotification(title, message) {
                if (window.FilamentNotification) {
                    new FilamentNotification()
                        .title(title)
                        .body(message)
                        .success()
                        .send();
                } else if (window.Livewire) {
                    window.dispatchEvent(new CustomEvent('notify', {
                        detail: {
                            status: 'success',
                            message: message,
                        },
                    }));
                }
            },

            init() {
                document.addEventListener('fullscreenchange', () => {
                    this.isFullscreen = !!document.fullscreenElement;
                });

                this.$watch('open', (value) => {
                    if (value) {
                        this.currentTime = 0;
                        this.startLoading();
                        this.$nextTick(() => {
                            const video = this.videoPlayer();
                            if (video) {
                                video.load();
                            }
                        });
                        return;
                    }

                    this.pause();
                    this.finishLoading();
                    if (this.isFullscreen && document.exitFullscreen) {
                        document.exitFullscreen().catch(() => {});
                    }
                });
            },

            toggleFullscreen() {
                const target = this.$refs.modalPanel || this.$refs.videoPlayer;
                if (!document.fullscreenElement) {
                    if (target && target.requestFullscreen) {
                        target.requestFullscreen().catch((err) => console.warn(err));
                    } else if (this.$refs.videoPlayer && this.$refs.videoPlayer.webkitEnterFullscreen) {
                        this.$refs.videoPlayer.webkitEnterFullscreen();
                    }
                } else {
                    if (document.exitFullscreen) {
                        document.exitFullscreen().catch((err) => console.warn(err));
                    }
                }
            },

            startLoading() {
                this.isLoading = true;
                this.isVideoReady = false;
                this.hasError = false;
                this.errorMessage = '';
                this.isCached = false;
                this.hasNotifiedReady = false;
                this.stats = null;
                this.loadingProgress = 0;
                this.loadingTitle = config.initialLoadingTitle || 'Initializing Assembly';
                this.loadingSubtitle = config.initialLoadingSubtitle || 'Preparing stream pipeline...';
                this.loadingCurrentClip = null;
                this.loadingTotalClips = (this.clips && this.clips.length > 0) ? this.clips.length : (config.clips ? config.clips.length : null);
                this.loadingStep = config.initialLoadingStep || 'Initializing H.264 stream assembly pipeline...';
                this.loadingStartTime = Date.now();
                this.loadingElapsedSeconds = 0;

                const initialClips = this.loadingTotalClips;
                if (initialClips && initialClips > 0) {
                    this.lastEtaSeconds = Math.max(5, Math.round(initialClips * 0.24));
                    this.loadingEtaText = `ETA: ~${this.formatHumanSeconds(this.lastEtaSeconds)}`;
                } else {
                    this.lastEtaSeconds = null;
                    this.loadingEtaText = 'ETA: Calculating...';
                }

                if (this.loadingInterval) {
                    clearInterval(this.loadingInterval);
                }

                let lastCountdownSecond = -1;

                this.loadingInterval = setInterval(() => {
                    if (!this.isLoading) {
                        clearInterval(this.loadingInterval);
                        this.loadingInterval = null;
                        return;
                    }

                    const elapsed = (Date.now() - this.loadingStartTime) / MILLISECONDS_PER_SECOND;
                    this.loadingElapsedSeconds = elapsed;

                    const currentWholeSecond = Math.floor(elapsed);
                    if (currentWholeSecond !== lastCountdownSecond) {
                        lastCountdownSecond = currentWholeSecond;
                        this.tickEtaCountdown();
                    }
                }, ELAPSED_INTERVAL_MS);

                if (this.progressUrl) {
                    if (window.EventSource) {
                        this.startEventSource();
                    } else {
                        this.startPolling();
                    }
                }
            },

            startEventSource() {
                this.closeEventSource();

                const sseUrl = this.progressUrl + (this.progressUrl.includes('?') ? '&' : '?') + 'stream=1';
                try {
                    this.eventSource = new EventSource(sseUrl);

                    this.eventSource.onmessage = (event) => {
                        if (!this.isLoading) {
                            this.closeEventSource();
                            return;
                        }

                        try {
                            const data = JSON.parse(event.data);
                            this.handleProgressData(data);
                            if (data && (data.ready || data.errorMessage || data.error)) {
                                this.closeEventSource();
                            }
                        } catch (err) {
                            console.debug('Failed to parse SSE progress payload:', err);
                        }
                    };

                    this.eventSource.onerror = () => {
                        // Fall back to polling if SSE is interrupted or unsupported by intermediate proxies
                        this.closeEventSource();
                        if (this.isLoading && !this.progressPollInterval) {
                            this.startPolling();
                        }
                    };
                } catch (e) {
                    this.startPolling();
                }
            },

            startPolling() {
                if (this.progressPollInterval) {
                    clearInterval(this.progressPollInterval);
                }

                this.pollProgress();
                this.progressPollInterval = setInterval(() => {
                    if (!this.isLoading) {
                        clearInterval(this.progressPollInterval);
                        this.progressPollInterval = null;
                        return;
                    }

                    this.pollProgress();
                }, PROGRESS_POLL_INTERVAL_MS);
            },

            closeEventSource() {
                if (this.eventSource) {
                    this.eventSource.close();
                    this.eventSource = null;
                }
            },

            handleProgressData(data) {
                if (!data) {
                    return;
                }

                if (typeof data.percent === 'number') {
                    this.loadingProgress = Math.max(this.loadingProgress, data.percent);
                    this.recomputeEta();
                }

                if (data.title) {
                    this.loadingTitle = data.title;
                } else if (data.step) {
                    this.loadingTitle = data.step;
                }

                if (data.subtitle !== undefined) {
                    this.loadingSubtitle = data.subtitle;
                }

                if (data.current_clip !== undefined) {
                    this.loadingCurrentClip = data.current_clip;
                }

                if (data.total_clips !== undefined) {
                    this.loadingTotalClips = data.total_clips;
                }

                if (data.step) {
                    this.loadingStep = data.step;
                }

                if (data.cached !== undefined) {
                    this.isCached = !!data.cached;
                }

                if (data.stats) {
                    this.stats = data.stats;
                }

                if (data.ready) {
                    this.notifyCompletion(data.stats, !!data.cached || this.isCached);
                    this.finishLoading();
                }

                if (data.error || data.errorMessage) {
                    this.hasError = true;
                    this.isLoading = false;
                    this.errorMessage = data.errorMessage || data.step || 'Compilation failed.';
                }
            },

            async pollProgress() {
                if (!this.progressUrl || !this.isLoading) {
                    return;
                }

                try {
                    const res = await fetch(this.progressUrl, {
                        headers: { Accept: 'application/json' },
                    });

                    if (!res.ok) {
                        return;
                    }

                    const data = await res.json();
                    this.handleProgressData(data);
                } catch (err) {
                    console.debug('Progress poll error:', err);
                }
            },

            notifyCompletion(stats, isCached = false) {
                if (this.hasNotifiedReady) {
                    return;
                }
                this.hasNotifiedReady = true;

                if (isCached) {
                    const clipCount = this.clips ? this.clips.length : 0;
                    const message = clipCount > 0
                        ? `Loaded ${clipCount} clips from cache.`
                        : 'Loaded video reel from cache.';

                    console.info('🎬 Video Reel Loaded from Cache:', { clipCount });
                    this.sendNotification('Video Reel Ready (Cached)', message);
                    return;
                }

                if (!stats) {
                    return;
                }

                console.info('🎬 FFmpeg Stream Assembly Timing Benchmark:', stats);

                const avgMs = Math.round((stats.avg_seconds_per_clip || 0) * 1000);
                const audioLabel = stats.audio ? ` [${stats.audio}]` : '';
                const message = `Assembled ${stats.total_clips} streams${audioLabel} in ${stats.formatted_total} (avg ${avgMs}ms/clip, stitch: ${stats.stitch_seconds}s, I/O upload: ${stats.upload_seconds}s).`;

                this.sendNotification('H.264 Stream Assembly Ready', message);
            },

            finishLoading() {
                this.loadingProgress = 100;
                this.loadingTitle = 'Ready';
                this.loadingSubtitle = this.isCached ? 'Loaded from cache' : 'Video reel ready to play';
                this.loadingCurrentClip = this.loadingTotalClips || (this.clips ? this.clips.length : null);
                this.loadingEtaText = '';
                this.lastEtaSeconds = 0;
                this.loadingStep = this.isCached ? 'Ready (loaded from cache)' : 'Ready';
                this.hasError = false;

                const video = this.videoPlayer();
                if (video && (!video.src || video.error)) {
                    video.src = this.videoUrl;
                    video.load();
                }

                this.notifyCompletion(this.stats, this.isCached);

                this.closeEventSource();

                setTimeout(() => {
                    this.isLoading = false;
                    this.clearTimers();
                }, FINISH_LOADING_DELAY_MS);
            },

            togglePlay() {
                const video = this.videoPlayer();
                if (!video) {
                    return;
                }

                if (video.paused || video.ended) {
                    video
                        .play()
                        .then(() => {
                            this.isPlaying = true;
                            this.isVideoReady = true;
                        })
                        .catch((e) => {
                            console.warn('Playback prevented:', e);
                        });
                    return;
                }

                video.pause();
                this.isPlaying = false;
            },

            pause() {
                const video = this.videoPlayer();
                if (video && !video.paused) {
                    video.pause();
                    this.isPlaying = false;
                }
            },

            seekTo(seconds) {
                const video = this.videoPlayer();
                if (!video) {
                    return;
                }

                video.currentTime = Math.max(0, Math.min(seconds, this.duration || 0));
                this.currentTime = video.currentTime;
            },

            seekBy(offset) {
                const video = this.videoPlayer();
                if (!video) {
                    return;
                }

                this.seekTo(video.currentTime + offset);
            },

            setSpeed(speed) {
                this.playbackRate = speed;
                this.applyPlaybackRate();
            },

            handleWaiting() {
                if (this.playbackRate > PITCH_PRESERVATION_THRESHOLD) {
                    const video = this.videoPlayer();

                    if (video && !video.paused) {
                        setTimeout(() => {
                            if (video && video.paused && this.isPlaying) {
                                video.play().catch(() => {});
                            }
                        }, RETRY_PLAY_DELAY_MS);
                    }
                }
            },

            handleEnded() {
                this.isPlaying = false;
                this.currentTime = this.duration;
            },

            handleTimeUpdate() {
                const video = this.videoPlayer();
                if (video) {
                    this.currentTime = video.currentTime;
                }
            },

            handleLoadedMetadata() {
                const video = this.videoPlayer();
                if (video) {
                    this.duration = video.duration || 0;
                    this.isVideoReady = true;
                    this.applyPlaybackRate();
                }
            },

            handleProgress() {
                const video = this.videoPlayer();
                if (video && video.buffered && video.buffered.length > 0 && video.duration > 0) {
                    const bufferedEnd = video.buffered.end(video.buffered.length - 1);
                    const rawProgress = (bufferedEnd / video.duration) * 100;
                    this.loadingProgress = Math.max(this.loadingProgress, Math.min(100, Math.round(rawProgress)));
                }
            },

            handleCanPlay() {
                this.isVideoReady = true;
                this.finishLoading();
            },

            async handleVideoError(e) {
                console.error('Video Reel Stream Error:', e);

                // If compilation is still running on the backend, do not abort polling!
                // Proxies or browsers often close idle streaming sockets at 60s while the background reel generation finishes.
                if (this.isLoading) {
                    console.warn('Video stream disconnected while compiling reel; continuing to poll progress until ready...');
                    return;
                }

                this.isVideoReady = false;
                this.clearTimers();

                if (this.progressUrl) {
                    try {
                        const res = await fetch(this.progressUrl, {
                            headers: { Accept: 'application/json' },
                        });
                        if (res.ok) {
                            const data = await res.json();
                            if (data && (data.errorMessage || (data.step && data.step.toLowerCase().includes('failed')))) {
                                this.errorMessage = data.errorMessage || data.step;
                            }
                        }
                    } catch (err) {
                        console.debug('Failed to poll progress on video error:', err);
                    }
                }

                if (!this.errorMessage && this.videoUrl) {
                    try {
                        const res = await fetch(this.videoUrl, {
                            headers: { Accept: 'application/json, text/plain, */*' },
                        });
                        if (!res.ok) {
                            const text = await res.text();
                            try {
                                const parsed = JSON.parse(text);
                                if (parsed && (parsed.errorMessage || parsed.message)) {
                                    this.errorMessage = parsed.errorMessage || parsed.message;
                                }
                            } catch {
                                if (text && text.trim().length > 0 && !text.includes('<!DOCTYPE') && !text.includes('<html')) {
                                    this.errorMessage = text.trim();
                                } else {
                                    this.errorMessage = `HTTP ${res.status} (${res.statusText || 'Server Error'})`;
                                }
                            }
                        }
                    } catch (err) {
                        console.debug('Failed to fetch video URL on error:', err);
                    }
                }

                if (!this.errorMessage) {
                    const video = this.videoPlayer();
                    if (video && video.error) {
                        const errorCodes = {
                            1: 'Media playback was aborted.',
                            2: 'Network error occurred while fetching video.',
                            3: 'Media decoding failed or was corrupted.',
                            4: 'The video could not be loaded, either because the server or network failed or because the format is not supported.',
                        };
                        this.errorMessage = video.error.message || errorCodes[video.error.code] || 'Video stream could not be loaded.';
                    } else {
                        this.errorMessage = 'Unable to stream or decode video reel.';
                    }
                }

                this.hasError = true;
            },

            formatSeconds(sec) {
                if (isNaN(sec) || sec === null || sec === undefined) {
                    return '0:00';
                }

                const m = Math.floor(sec / SECONDS_PER_MINUTE);
                const s = Math.floor(sec % SECONDS_PER_MINUTE);
                return m + ':' + (s < 10 ? '0' : '') + s;
            },

            currentClipIndex() {
                if (!this.clips || this.clips.length === 0 || this.duration <= 0) {
                    return 0;
                }

                // If clips provide duration metadata, synchronize scrubber with cumulative timestamps
                let accumulatedSeconds = 0;
                let hasValidDurations = true;

                for (let i = 0; i < this.clips.length; i++) {
                    const raw = this.clips[i].duration;
                    const parsed = typeof raw === 'string' ? parseFloat(raw.replace('s', '')) : parseFloat(raw);

                    if (isNaN(parsed) || parsed <= 0) {
                        hasValidDurations = false;
                        break;
                    }

                    accumulatedSeconds += parsed;
                    if (this.currentTime <= accumulatedSeconds) {
                        return i;
                    }
                }

                if (hasValidDurations) {
                    return this.clips.length - 1;
                }

                const clipDuration = this.duration / this.clips.length;
                return Math.min(
                    this.clips.length - 1,
                    Math.max(0, Math.floor(this.currentTime / clipDuration)),
                );
            },

            currentClip() {
                return this.clips[this.currentClipIndex()] || {};
            },

            currentItem() {
                return this.currentClip();
            },
        };
    }

    window.petkitVideoReelPlayer = petkitVideoReelPlayer;

    if (window.Alpine) {
        window.Alpine.data('petkitVideoReelPlayer', petkitVideoReelPlayer);
    } else {
        document.addEventListener('alpine:init', () => {
            window.Alpine.data('petkitVideoReelPlayer', petkitVideoReelPlayer);
        });
    }
})();
