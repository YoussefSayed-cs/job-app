<?php

namespace App\Http\Controllers;

use App\Models\job_application;
use Illuminate\Support\Facades\Auth;


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

        try {
            return \Illuminate\Support\Facades\Storage::disk('cloud')->response($fileUri);
        } catch (\Throwable $e) {
            abort(500, 'Could not retrieve resume file: ' . $e->getMessage());
        }
    }
}
