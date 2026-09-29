<?php

namespace App\Petkit\Storage;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The device's .ts captures are raw MPEG-TS, which Chrome won't play in a
 * <video> tag. Remuxes (not re-encodes - `-c copy`, so this is fast and
 * lossless) into a fragmented MP4 container carrying the same H.264 bytes,
 * which every browser plays natively.
 */
class VideoRemuxer
{
    public const DEFAULT_TIMEOUT_SECONDS = 30;
    public const DEFAULT_REENCODE_TIMEOUT_SECONDS = 120;
    public const DEFAULT_CONCAT_TIMEOUT_SECONDS = 300;

    private static function ffmpegBinary(): string
    {
        return env('FFMPEG_BINARY', 'ffmpeg');
    }

    /**
     * Runs FFmpeg with the supplied arguments and timeout.
     *
     * @param array<int, string> $arguments
     */
    private static function runFfmpeg(array $arguments, ?int $timeoutSeconds = null, ?string $input = null): Process
    {
        $process = new Process([self::ffmpegBinary(), ...$arguments]);

        if ($input !== null) {
            $process->setInput($input);
        }

        if ($timeoutSeconds !== null) {
            $process->setTimeout($timeoutSeconds);
        }

        $process->run();

        return $process;
    }

    private static function assertSucceeded(Process $process, string $errorPrefix, ?string $outputPath = null): void
    {
        $hasOutput = $outputPath === null
            ? $process->getOutput() !== ''
            : file_exists($outputPath) && filesize($outputPath) > 0;

        if (! $process->isSuccessful() || ! $hasOutput) {
            throw new RuntimeException($errorPrefix . ': ' . $process->getErrorOutput());
        }
    }

    public static function toMp4(string $ts): string
    {
        $process = self::runFfmpeg([
            '-hide_banner', '-loglevel', 'error',
            '-i', 'pipe:0',
            '-c', 'copy',
            // The device's AAC audio is raw ADTS, which MP4 can't mux
            // directly - repackage the bitstream framing (not a re-encode,
            // still lossless) into what MP4 expects. Harmless no-op if a
            // clip has no audio stream at all.
            '-bsf:a', 'aac_adtstoasc',
            '-avoid_negative_ts', 'make_zero',
            '-f', 'mp4',
            '-movflags', 'frag_keyframe+empty_moov+default_base_moof',
            'pipe:1',
        ], self::DEFAULT_TIMEOUT_SECONDS, $ts);

        self::assertSucceeded($process, 'ffmpeg remux failed');

        return $process->getOutput();
    }

    /**
     * Joins a new MPEG-TS segment onto an already-combined one using
     * ffmpeg's concat demuxer, which - unlike raw byte concatenation - shifts
     * the new segment's timestamps to continue where the previous one left
     * off. Each ~4s segment restarts its own PTS/DTS near zero; byte-pasting
     * them preserves those jumps, which players read as a hard reset at
     * every boundary (a 16-segment event plays as 16 separate resetting
     * clips instead of one). `-c copy` throughout, so this is lossless and
     * doesn't re-encode anything - just repackages/retimes.
     *
     * Needs real files (not pipes) - the concat demuxer reads a list of
     * file paths, it can't take multiple piped inputs.
     */
    public static function concatTs(string $existingTs, string $newSegmentTs): string
    {
        $dir = sys_get_temp_dir() . '/petkit-concat-' . Str::random(16);
        mkdir($dir);

        try {
            file_put_contents($dir . '/a.ts', $existingTs);
            file_put_contents($dir . '/b.ts', $newSegmentTs);
            file_put_contents($dir . '/list.txt', "file 'a.ts'\nfile 'b.ts'\n");

            $process = self::runFfmpeg([
                '-hide_banner', '-loglevel', 'error',
                '-f', 'concat',
                '-safe', '0',
                '-i', $dir . '/list.txt',
                '-c', 'copy',
                '-avoid_negative_ts', 'make_zero',
                '-f', 'mpegts',
                'pipe:1',
            ], self::DEFAULT_TIMEOUT_SECONDS);

            self::assertSucceeded($process, 'ffmpeg concat failed');

            return $process->getOutput();
        } finally {
            @unlink($dir . '/a.ts');
            @unlink($dir . '/b.ts');
            @unlink($dir . '/list.txt');
            @rmdir($dir);
        }
    }

    /**
     * Re-encodes (not a remux - full decode/encode) a .ts to MP4, letting the
     * encoder assign a fresh, monotonically increasing timeline instead of
     * carrying through whatever PTS/DTS are already in the source bytes.
     * `-c copy` alone can't do this: it's a stream copy, so discontinuous
     * per-segment timestamps (or drift that compounds across many appended
     * segments even with VideoRemuxer::concatTs() retiming each one) pass
     * through mostly unchanged. This is the standard step used when
     * decrypting and combining CLOUD_STORAGE segments (see
     * DevUploadFileInfoV2Controller::appendCloudStorageSegment) - slower and
     * lossy than a stream copy, but the only way to guarantee the MP4 in
     * front of the user always plays as one continuous clip.
     */
    public static function reencodeMp4(string $ts): string
    {
        $process = self::runFfmpeg([
            '-hide_banner', '-loglevel', 'error',
            '-fflags', '+genpts',
            '-i', 'pipe:0',
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '20',
            '-c:a', 'aac',
            '-avoid_negative_ts', 'make_zero',
            '-f', 'mp4',
            '-movflags', 'frag_keyframe+empty_moov+default_base_moof',
            'pipe:1',
        ], self::DEFAULT_REENCODE_TIMEOUT_SECONDS, $ts);

        self::assertSucceeded($process, 'ffmpeg re-encode failed');

        return $process->getOutput();
    }

    /**
     * Probes an input media file to determine whether an audio stream exists.
     */
    public static function hasAudioStream(string $filePath): bool
    {
        $process = self::runFfmpeg([
            '-hide_banner',
            '-i', $filePath,
        ]);
        $output = $process->getErrorOutput();

        return str_contains($output, 'Audio:');
    }

    /**
     * Converts one source clip into a clean, uniform MPEG-TS intermediate clip for reel stitching.
     *
     * Enforces uniform video resolution (1080p letterboxed), 30fps, Main Profile H.264, and
     * a guaranteed stereo 48kHz AAC audio stream (generating silence if the clip lacks audio).
     */
    public static function normalizeClip(
        string $inputPath,
        string $outputPath,
        int $timeoutSeconds = self::DEFAULT_REENCODE_TIMEOUT_SECONDS
    ): void {
        $hasAudio = self::hasAudioStream($inputPath);

        $args = [
            '-hide_banner',
            '-loglevel', 'error',
            '-i', $inputPath,
        ];

        if (! $hasAudio) {
            $args[] = '-f';
            $args[] = 'lavfi';
            $args[] = '-i';
            $args[] = 'anullsrc=r=48000:cl=stereo';
        }

        $args[] = '-vf';
        $args[] = 'fps=30,scale=1920:1080:force_original_aspect_ratio=decrease,pad=1920:1080:(ow-iw)/2:(oh-ih)/2:black,setsar=1,setpts=N/(30*TB)';

        if ($hasAudio) {
            $args[] = '-af';
            $args[] = 'aformat=sample_fmts=fltp:channel_layouts=stereo,aresample=48000,asetpts=N/SR/TB';
        }

        $args = array_merge($args, [
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-crf', '22',
            '-pix_fmt', 'yuv420p',
            '-profile:v', 'main',
            '-level', '4.1',
            '-c:a', 'aac',
            '-ar', '48000',
            '-ac', '2',
            '-avoid_negative_ts', 'make_zero',
        ]);

        if (! $hasAudio) {
            $args[] = '-shortest';
        }

        $args[] = '-f';
        $args[] = 'mpegts';
        $args[] = '-y';
        $args[] = $outputPath;

        $process = self::runFfmpeg($args, $timeoutSeconds);
        self::assertSucceeded($process, 'ffmpeg clip conversion failed', $outputPath);
    }

    /**
     * Prepares an intermediate MPEG-TS clip with Annex B SPS/PPS headers before keyframes.
     *
     * Video and audio streams are stream-copied (-c copy) directly without probing or re-encoding.
     * Non-fatal stream mappings (-map 0:v? -map 0:a?) seamlessly support clips with or without audio.
     */
    public static function prepareIntermediateTsClip(
        string $inputPath,
        string $outputPath,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS
    ): void {
        $process = self::runFfmpeg([
            '-hide_banner',
            '-loglevel', 'error',
            '-i', $inputPath,
            '-map', '0:v?',
            '-map', '0:a?',
            '-c', 'copy',
            '-bsf:v', 'h264_mp4toannexb',
            '-avoid_negative_ts', 'make_zero',
            '-f', 'mpegts',
            '-y',
            $outputPath,
        ], $timeoutSeconds);
        self::assertSucceeded($process, 'Failed to prepare intermediate clip', $outputPath);
    }

    /**
     * Stitches intermediate MPEG-TS clips together and packages them into the final unified MP4 with continuous timeline.
     *
     * Streams are merged via stream copy (-c copy) with non-fatal stream mapping (-map 0:v? -map 0:a?).
     *
     * @param string $manifestPath Absolute path to clips.txt.
     * @param string $outputPath Absolute path to the final MP4.
     * @param int $timeoutSeconds
     */
    public static function concatTsFilesToMp4(
        string $manifestPath,
        string $outputPath,
        int $timeoutSeconds = self::DEFAULT_CONCAT_TIMEOUT_SECONDS
    ): void {
        $process = self::runFfmpeg([
            '-hide_banner',
            '-loglevel', 'error',
            '-f', 'concat',
            '-safe', '0',
            '-i', $manifestPath,
            '-map', '0:v?',
            '-map', '0:a?',
            '-c', 'copy',
            '-bsf:a', 'aac_adtstoasc',
            '-avoid_negative_ts', 'make_zero',
            '-fflags', '+genpts',
            '-movflags', '+faststart',
            '-f', 'mp4',
            '-y',
            $outputPath,
        ], $timeoutSeconds);
        self::assertSucceeded($process, 'ffmpeg video stitch failed', $outputPath);
    }

    /**
     * Remuxes a standalone MPEG-TS file into a standalone MP4 file via stream copy without re-encoding.
     */
    public static function toMp4File(
        string $inputTsPath,
        string $outputMp4Path,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS
    ): void {
        $process = self::runFfmpeg([
            '-hide_banner',
            '-loglevel', 'error',
            '-i', $inputTsPath,
            '-c', 'copy',
            '-bsf:a', 'aac_adtstoasc',
            '-avoid_negative_ts', 'make_zero',
            '-movflags', '+faststart',
            '-y',
            $outputMp4Path,
        ], $timeoutSeconds);
        self::assertSucceeded($process, 'ffmpeg remux to MP4 failed', $outputMp4Path);
    }

    /**
     * Adds a silent AAC stereo audio track to an MP4 video using stream copy for the video track.
     */
    public static function addSilentAudioTrack(
        string $inputMp4Path,
        string $outputMp4Path,
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS
    ): void {
        $process = self::runFfmpeg([
            '-hide_banner',
            '-loglevel', 'error',
            '-i', $inputMp4Path,
            '-f', 'lavfi',
            '-i', 'anullsrc=r=48000:cl=stereo',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-ar', '48000',
            '-ac', '2',
            '-shortest',
            '-avoid_negative_ts', 'make_zero',
            '-movflags', '+faststart',
            '-y',
            $outputMp4Path,
        ], $timeoutSeconds);
        self::assertSucceeded($process, 'Failed to add silent audio track', $outputMp4Path);
    }

    /**
     * Stitches MP4 clips via stream-copy concat demuxer without re-encoding video frames.
     *
     * @param string $manifestPath Absolute path to clips.txt.
     * @param string $outputPath Absolute path to the destination MP4.
     * @param bool $includeAudio Whether to copy audio streams or drop them (-an).
     * @param int $timeoutSeconds
     */
    public static function fastConcatMp4Files(
        string $manifestPath,
        string $outputPath,
        bool $includeAudio = true,
        int $timeoutSeconds = self::DEFAULT_CONCAT_TIMEOUT_SECONDS
    ): void {
        $args = [
            '-hide_banner',
            '-loglevel', 'error',
            '-f', 'concat',
            '-safe', '0',
            '-i', $manifestPath,
            '-c:v', 'copy',
        ];

        if ($includeAudio) {
            $args[] = '-c:a';
            $args[] = 'copy';
        } else {
            $args[] = '-an';
        }

        $args = array_merge($args, [
            '-avoid_negative_ts', 'make_zero',
            '-fflags', '+genpts',
            '-movflags', '+faststart',
            '-y',
            $outputPath,
        ]);

        $process = self::runFfmpeg($args, $timeoutSeconds);
        self::assertSucceeded($process, 'ffmpeg fast concat failed', $outputPath);
    }

    /**
     * The sibling object key an original .ts key's remuxed MP4 is stored
     * under (see DevUploadFileInfoV2Controller).
     */
    public static function mp4Key(string $tsObjectKey): string
    {
        return preg_replace('/\.ts$/', '.mp4', $tsObjectKey);
    }

    /**
     * The reverse of mp4Key() - the combined .ts an object key's MP4 was
     * remuxed from (see DevUploadFileInfoV2Controller::appendCloudStorageSegment,
     * which keeps the accumulator .ts around alongside the remuxed MP4).
     * A no-op if the key is already a .ts.
     */
    public static function tsKey(string $objectKey): string
    {
        return preg_replace('/\.mp4$/', '.ts', $objectKey);
    }
}
