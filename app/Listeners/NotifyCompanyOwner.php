<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use App\Events\JobApplicationSubmitted;
use App\Notifications\newJobApply;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class NotifyCompanyOwner implements ShouldQueue
{
    use InteractsWithQueue;


    public string $queue = 'notifications';


    public int $tries = 3;

    public function handle(JobApplicationSubmitted $event): void
    {

        $job = $event->application->jobVacancy;


        $owner = $job?->company?->owner;


        if (! $owner) {
            Log::warning('Company owner not found for job vacancy', [
                'job_vacancy_id' => $job?->id,
                'application_id'  => $event->application->id,
            ]);
            return;
        }

        
        $owner->notify(new newJobApply(
            $event->jobSeeker,
            $job,
            $event->application,
            $event->application->id,
        ));
    }


    public function failed(JobApplicationSubmitted $event, \Throwable $exception): void
    {
        Log::error('erorr', [
            'application_id' => $event->application->id,
            'job_seeker_id'  => $event->jobSeeker->id,
            'error'          => $exception->getMessage(),
        ]);
    }
}
