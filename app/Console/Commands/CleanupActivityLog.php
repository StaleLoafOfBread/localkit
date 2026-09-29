<?php

namespace App\Console\Commands;

use App\Filament\Pages\MediaPage;
use App\Models\History;
use App\Models\MediaFile;
use App\Petkit\Storage\VideoRemuxer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Prunes old Activity Log entries (History rows) and recordings (MediaFile
 * rows + their storage objects) on independent, configurable retention
 * windows (config('localkit.retention')) - media is expected to be kept for
 * a shorter period than the log itself, since recordings take up real disk
 * space while a History row is tiny.
 */
class CleanupActivityLog extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-activity-log
        {--dry-run : List what would be deleted without deleting anything}
        {--force : Skip the confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete activity log entries and media files older than their configured retention periods';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $activityDays = (int) config('localkit.retention.activity_days');
        $mediaDays = (int) config('localkit.retention.media_days');
        $compilationDays = (int) config('localkit.retention.compilation_days');

        $mediaCutoff = now()->subDays($mediaDays);
        $activityCutoff = now()->subDays($activityDays);
        $compilationCutoff = now()->subDays($compilationDays)->getTimestamp();

        $staleMedia = MediaFile::where('created_at', '<', $mediaCutoff)->get();
        $historyQuery = History::where('created_at', '<', $activityCutoff);
        $historyCount = $historyQuery->count();

        $disk = Storage::disk(MediaPage::DISK);
        $staleCompilations = [];

        foreach (['compilations/reels', 'compilations/timelapses'] as $prefix) {
            $files = $disk->files($prefix);
            foreach ($files as $file) {
                if ($disk->lastModified($file) < $compilationCutoff) {
                    $staleCompilations[] = $file;
                }
            }
        }

        if ($staleMedia->isEmpty() && $historyCount === 0 && empty($staleCompilations)) {
            $this->info('Nothing to clean up.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Media older than %d day(s) (before %s): %d file(s)',
            $mediaDays,
            $mediaCutoff->toDateString(),
            $staleMedia->count(),
        ));
        $this->info(sprintf(
            'Activity log entries older than %d day(s) (before %s): %d row(s)',
            $activityDays,
            $activityCutoff->toDateString(),
            $historyCount,
        ));
        $this->info(sprintf(
            'Compilations older than %d day(s): %d file(s)',
            $compilationDays,
            count($staleCompilations),
        ));

        if ($this->option('dry-run')) {
            $this->info('Dry run - nothing deleted.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf(
            'Delete %d media file(s), %d activity log entrie(s), and %d compilation(s)? This cannot be undone.',
            $staleMedia->count(),
            $historyCount,
            count($staleCompilations),
        ))) {
            return self::SUCCESS;
        }

        foreach ($staleMedia as $media) {
            $disk->delete($media->object_key);
            $disk->delete(VideoRemuxer::mp4Key($media->object_key));
            $media->delete();
        }

        foreach ($staleCompilations as $compilationFile) {
            $disk->delete($compilationFile);
        }

        $historyQuery->delete();

        $this->info(sprintf(
            'Deleted %d media file(s), %d activity log entrie(s), and %d compilation(s).',
            $staleMedia->count(),
            $historyCount,
            count($staleCompilations),
        ));

        return self::SUCCESS;
    }
}
