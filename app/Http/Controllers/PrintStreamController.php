<?php

namespace App\Http\Controllers;

use App\Helper\Files;
use App\Models\Branch;
use App\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PrintStreamController extends Controller
{
    /**
     * Server-Sent Events stream of pending print jobs for a branch.
     * Token is the branch unique_hash (same value as X-TABLETRACK-KEY for the desktop app).
     */
    public function stream(string $token)
    {
        Log::info('PrintStreamController::stream request', [
            'token_len' => strlen($token),
            'token_prefix' => substr($token, 0, 6) . '…',
        ]);
        $branch = Branch::where('unique_hash', $token)->first();
        if (! $branch) {
            Log::warning('PrintStreamController::stream unknown token', ['token' => $token]);
            abort(403, 'Unknown token');
        }

        return response()->stream(function () use ($branch) {
            @set_time_limit(0);
            @ini_set('max_execution_time', '0');
            ignore_user_abort(false);

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            echo ": connected\n\n";
            flush();

            $lastHeartbeat = time();

            while (true) {
                if (connection_aborted()) {
                    break;
                }

                // Re-query all pending jobs each tick. PNG captures may finish after the
                // PrintJob row is created, so we must not advance past jobs whose file
                // is not ready yet. Atomic claim below prevents re-broadcast.
                $jobs = PrintJob::query()
                    ->with('printer:id,name,printing_choice,print_format,share_name,type')
                    ->where('branch_id', $branch->id)
                    ->where('status', 'pending')
                    ->whereNotNull('image_filename')
                    ->orderBy('id')
                    ->limit(10)
                    ->get();

                foreach ($jobs as $job) {
                    Log::info('PrintStreamController::stream job', ['job' => $job]);

                    $imagePath = public_path(Files::UPLOAD_FOLDER . '/print/' . $job->image_filename);
                    Log::info('PrintStreamController::stream imagePath', ['imagePath' => $imagePath]);
                    if (! File::exists($imagePath)) {
                        $createdDiff = now()->diffInMinutes($job->created_at);
                        if ($createdDiff > 2) {
                            $job->update(['status' => 'failed', 'error' => 'Image file not found']);
                        }

                        continue;
                    }

                    // Claim the job atomically so concurrent SSE connections / polls
                    // do not print the same (or an older pending) job more than once.
                    $locked = PrintJob::query()
                        ->where('id', $job->id)
                        ->where('status', 'pending')
                        ->update(['status' => 'printing']);

                    if ($locked === 0) {
                        continue;
                    }

                    $job->refresh();

                    $type = $job->printer?->type ?? 'receipt';

                    echo 'id: ' . $job->id . "\n";
                    echo "event: print\n";
                    echo 'data: ' . json_encode([
                        'id' => $job->id,
                        'content' => $job->image_path,
                        'type' => $type,
                        'copies' => 1,
                        'printer' => $job->printer ? [
                            'id' => $job->printer->id,
                            'name' => $job->printer->name,
                            'printing_choice' => $job->printer->printing_choice,
                            'print_format' => $job->printer->print_format,
                            'share_name' => $job->printer->share_name,
                            'type' => $job->printer->type,
                        ] : null,
                    ]) . "\n\n";
                    flush();
                }

                if (time() - $lastHeartbeat >= 25) {
                    echo ": heartbeat\n\n";
                    flush();
                    $lastHeartbeat = time();
                }

                sleep(1);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Called by the desktop client after a successful print (companion to SSE / smart polling).
     */
    public function markPrinted(Request $request, PrintJob $printJob)
    {
        $branch = $request->get('branch');

        if (! $branch || (int) $printJob->branch_id !== (int) $branch->id) {
            abort(404);
        }

        $printJob->update([
            'status' => 'done',
            'printed_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
