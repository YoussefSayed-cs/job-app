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

    // ─── إعدادات الـ Job ──────────────────────────────────────────────────────
    public int $tries   = 3;           // عدد محاولات إعادة التشغيل عند الفشل
    public int $timeout = 300;         // أقصى وقت للـ Job (5 دقائق)
    public array $backoff = [60, 120, 180]; // ثواني الانتظار بين كل محاولة

    // ─── Constructor ──────────────────────────────────────────────────────────

    /**
     * بنمرر IDs فقط (مش Objects) عشان SerializesModels يشتغل صح
     */
    public function __construct(
        private readonly string $jobApplicationId,
        private readonly string $resumeId,
        private readonly string $jobVacancyId,
        private readonly bool $isNewResume,
    ) {}

    // ─── Handle: ده اللي بيشتغل في الـ Background ────────────────────────────

    public function handle(ResumesAnalysisServices $resumeService): void
    {
        $jobApplication = job_application::findOrFail($this->jobApplicationId);
        $resume         = resume::findOrFail($this->resumeId);
        $jobVacancy     = job_vacancy::findOrFail($this->jobVacancyId);

        try {
            // ─── Step 1: لو CV جديد، نستخرج البيانات منه أولاً ────────────────
            if ($this->isNewResume) {
                $extracted = $resumeService->extractResumeInformation($resume->fileUri);

                $resume->update([
                    'summary'    => $extracted['summary']    ?? '',
                    'skills'     => $extracted['skills']     ?? [],
                    'experience' => $extracted['experience'] ?? [],
                    'education'  => $extracted['education']  ?? [],
                ]);
            } else {
                // ─── CV موجود: نجيب بياناته من الداتابيز ────────────────────
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

            // ─── Step 2: نحلل الـ CV مقابل الوظيفة ──────────────────────────
            $evaluation = $resumeService->analyzeResume($jobVacancy, $extracted);

            // ─── Step 3: نحدّث الـ Job Application بالنتيجة ──────────────────
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

            // بنـ throw الـ exception عشان Laravel يعرف يعيد المحاولة
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
