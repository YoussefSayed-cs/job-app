<?php

namespace App\Http\Controllers;

use App\Models\job_application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;


class JobApplicationsController extends Controller
{
    public function index()
    {
        $jobApplications = job_application::where('userID', Auth::id())->orderBy('created_at' , 'desc')->paginate(10);

        return view('job-applications.index', compact('jobApplications'));
    }

    public function viewResume(string $id)
    {
        $jobApplication = job_application::where('userID', Auth::id())->findOrFail($id);

        $fileUri = $jobApplication->resume?->fileUri;

        if (!$fileUri) {
            abort(404, 'No resume file found for this application.');
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $cloud */
        $cloud = Storage::disk('cloud');

        return $cloud->download($fileUri);
    }
}
