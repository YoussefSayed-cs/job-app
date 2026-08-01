<?php

namespace App\Jobs;

use App\Models\job_application;
use App\Models\job_vacancy;
use App\Models\resume;
use App\Services\ResumesAnalysisServices;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessResumeAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Job configuration settings
    public int $tries   = 3;           // Number of retry attempts on failure
    public int $timeout = 300;         // Maximum execution time (5 minutes)
    public array $backoff = [60, 120, 180]; // Wait time in seconds between retries

    // Constructor

    /**
     * Pass only IDs (not full objects) to ensure proper model serialization
     */
    public function __construct(
        private readonly string $jobApplicationId,
        private readonly string $resumeId,
        private readonly string $jobVacancyId,
        private readonly bool $isNewResume,
    ) {}

    // Main job handler that runs in the background queue

    public function handle(ResumesAnalysisServices $resumeService): void
    {
        $jobApplication = job_application::findOrFail($this->jobApplicationId);
        $resume         = resume::findOrFail($this->resumeId);
        $jobVacancy     = job_vacancy::findOrFail($this->jobVacancyId);

        try {
            // Step 1: If new resume, extract data from it first
            if ($this->isNewResume) {
                $extracted = $resumeService->extractResumeInformation($resume->fileUri);

                $resume->update([
                    'summary'    => $extracted['summary']    ?? '',
                    'skills'     => $extracted['skills']     ?? [],
                    'experience' => $extracted['experience'] ?? [],
                    'education'  => $extracted['education']  ?? [],
                ]);
            } else {
                // Step 1b: For existing resume, fetch data from database
                $extracted = [
                    'summary'    => $resume->summary ?? '',
                    'skills'     => is_array($resume->skills)
                                        ? $resume->skills
                                        : json_decode($resume->skills ?? '[]', true),
                    'experience' => is_array($resume->experience)
                                        ? $resume->experience
                                        : json_decode($resume->experience ?? '[]', true),
                    'education'  => is_array($resume->education)
                                        ? $resume->education
                                        : json_decode($resume->education ?? '[]', true),
                ];
            }

            // Step 2: Analyze resume against the job requirements
            $evaluation = $resumeService->analyzeResume($jobVacancy, $extracted);

            // Step 3: Update job application with analysis results
            $jobApplication->update([
                'aiGeneratedScore'    => $evaluation['aiGeneratedScore']    ?? 0,
                'aiGeneratedFeedback' => $evaluation['aiGeneratedFeedback'] ?? 'No AI feedback',
            ]);

            Log::info('Resume analysis completed via Queue.', [
                'jobApplicationId' => $this->jobApplicationId,
                'score'            => $evaluation['aiGeneratedScore'] ?? 0,
            ]);

        } catch (\Throwable $e) {
            Log::error('ProcessResumeAnalysis job failed.', [
                'jobApplicationId' => $this->jobApplicationId,
                'attempt'          => $this->attempts(),
                'error'            => $e->getMessage(),
            ]);

            // Throw exception so Laravel retries the job
            throw $e;
        }
    }

    // ─── لو فشلت كل المحاولات ─────────────────────────────────────────────────
    public function failed(\Throwable $exception): void
    {
        Log::critical('ProcessResumeAnalysis permanently failed after all retries.', [
            'jobApplicationId' => $this->jobApplicationId,
            'error'            => $exception->getMessage(),
        ]);

        // نحدث الـ Application بـ fallback message
        job_application::where('id', $this->jobApplicationId)->update([
            'aiGeneratedScore'    => 0,
            'aiGeneratedFeedback' => 'AI evaluation is temporarily unavailable. Please try again later.',
        ]);
    }
}
