<?php

namespace App\Http\Controllers;

use App\Models\PrintJob;
use Illuminate\Http\Request;
use App\Models\Printer;
use App\Helper\Files;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;


class PrintJobController extends Controller
{
    public function testConnection(Request $request)
    {
        $branch = $request->get('branch');

        if (!$branch) {
            return response()->json(['message' => 'Branch not found', 'status' => 'error'], 404);
        }

        $pusherSettings = pusherSettings();

        $pusherEnabled = $pusherSettings->is_enabled_pusher_broadcast;

        $response = [
            'message' => 'Connection established',
            'status' => 'success',
            'pusher_enabled' => $pusherEnabled,
        ];

        if ($pusherEnabled) {
            $response['pusher_config'] = [
                'app_id' => $pusherSettings->pusher_app_id,
                'key' => $pusherSettings->pusher_key,
                'cluster' => $pusherSettings->pusher_cluster ?? 'mt1',
                'channel' => 'print-jobs',
                'event' => 'print-job.created'
            ];
        }

        return response()->json($response, 200);
    }

    public function printerDetails(Request $request)
    {
        $branch = $request->get('branch');
        $printer = Printer::where('branch_id', $branch->id)->get();
        return response()->json($printer);
    }

    /**
     * Get print jobs for a specific printer
     * Desktop applications can use this to get printer-specific jobs
     */
    public function getPrinterJobs(Request $request, $printerId)
    {
        $branch = $request->get('branch');

        $printJobs = PrintJob::where('printer_id', $printerId)
            ->where('branch_id', $branch->id)
            ->where('status', 'pending')
            ->with('printer:id,name,printing_choice,print_format,share_name,type')
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'print_jobs' => $printJobs,
            'count' => $printJobs->count()
        ]);
    }


    public function pullMultiple(Request $request)
    {
        // Log::info('PrintJobController::pullMultiple request', ['request' => $request]);
        $branch = $request->get('branch');

        $jobs = PrintJob::with('printer:id,name,printing_choice,print_format,share_name,type')
            ->where('status', 'pending')
            ->where('branch_id', $branch->id)
            ->oldest()
            ->get();

        if ($jobs->isEmpty()) {
            return response()->json([]); // Return empty array instead of object
        }

        // Filter jobs to only include those where the image file exists,
        // then claim them atomically so concurrent pollers / SSE cannot double-print.
        $claimedJobs = [];
        foreach ($jobs as $job) {
            if (empty($job->image_filename)) {
                continue;
            }

            $imagePath = public_path(Files::UPLOAD_FOLDER . '/print/' . $job->image_filename);
            $fileExists = File::exists($imagePath);

            if (! $fileExists) {
                // Only update if the job is at least 2 minutes old
                $createdDiff = now()->diffInMinutes($job->created_at);
                if ($createdDiff > 2) {
                    $job->update(['status' => 'failed', 'error' => 'Image file not found']);
                }
                continue;
            }

            $locked = PrintJob::query()
                ->where('id', $job->id)
                ->where('status', 'pending')
                ->update(['status' => 'printing']);

            if ($locked === 0) {
                continue;
            }

            $job->refresh();
            $claimedJobs[] = $job;
        }

        if (count($claimedJobs) === 0) {
            return response()->json([]); // Return empty array instead of object
        }

        return response()->json(collect($claimedJobs)->values()->toArray());
    }

    // Electron calls this after attempting to print
    public function update(Request $request, PrintJob $printJob)
    {

        Log::info('PrintJobController::update printJob', ['printJob' => $printJob]);
        $request->validate([
            'status'      => 'required|in:done,failed',
            'printed_at'  => 'nullable|date',
            'error'       => 'nullable|string',
            'printer'     => 'nullable|string',
        ]);

        $printJob->update([
            'status'     => $request->status,
            'error' => $request->has('error') ? $request->error : null,
            'response_printer'    => $request->printer,
            'printed_at' => $request->printed_at,
        ]);

        return response()->json(['message' => 'Print job updated', 'status' => 'success']);
    }
}
