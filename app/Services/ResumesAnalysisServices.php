<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class ResumesAnalysisServices
{
    private string $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
    private int $maxRetries = 3;
    private int $retryDelay = 10; // in seconds

    /**
     * Extract structured data from a resume PDF file
     */
    public function extractResumeInformation(string $fileUri): array
    {
        $empty = ['summary' => '', 'skills' => [], 'experience' => [], 'education' => []];

        if (!Storage::disk('cloud')->exists($fileUri)) {
            return $empty;
        }

        // Read the PDF and convert it to plain text
        $pdfContent = Storage::disk('cloud')->get($fileUri);
        $text       = (new \Smalot\PdfParser\Parser())->parseContent($pdfContent)->getText();

        if (trim($text) === '') {
            return $empty;
        }

        $prompt = "You are a resume-parsing assistant. Read the resume text below and extract the information into the exact JSON structure shown.

        Rules:
        - Return ONLY the raw JSON object. No markdown, no code fences, no explanation, no extra text before or after.
        - If a field is not mentioned in the resume, use an empty string \"\" or empty array [], never invent information.
        - \"summary\" should be a concise 2-3 sentence overview of the candidate's profile, written even if the resume has no explicit summary section (infer it from the overall content).
        - \"skills\" should list individual skills as separate strings (e.g. \"PHP\", \"Project Management\"), not full sentences.
        - \"experience\" should be ordered from most recent to oldest.
        - \"duration\" should preserve the original date format used in the resume (e.g. \"Jan 2020 - Mar 2022\").
        - \"education\" should be ordered from most recent to oldest.

        JSON structure to follow exactly:
        {\"summary\":\"string\",\"skills\":[\"string\"],
        \"experience\":[{\"title\":\"\",\"company\":\"\",\"duration\":\"\",\"description\":\"\"}],
        \"education\":[{\"degree\":\"\",\"institution\":\"\",\"year\":\"\"}]}

        Resume text:
        {$text}";

        return $this->askGemini($prompt) ?? $empty;
    }

    /**
     * Score and evaluate a resume against a job vacancy
     */
    public function analyzeResume(mixed $job, array $resumeData): array
    {
        $fallback = ['aiGeneratedScore' => 0, 'aiGeneratedFeedback' => 'Analysis failed.'];

        $prompt = "You are an experienced technical recruiter. Evaluate how well this resume matches the job requirements below.

        Scoring guide:
        - 90-100: Excellent match, meets nearly all requirements and has directly relevant experience.
        - 70-89: Strong match, meets most requirements with minor gaps.
        - 40-69: Partial match, has relevant skills but notable gaps in experience or requirements.
        - 0-39: Weak match, missing most core requirements.

        Feedback guide:
        - Write 2-4 sentences.
        - Mention specific strengths that align with the job.
        - Mention specific gaps or missing requirements, if any.
        - Be objective and evidence-based; do not invent skills or experience not present in the resume data.

        Return ONLY the raw JSON object below, no markdown, no code fences, no explanation:
        {\"aiGeneratedScore\": <integer 0-100>, \"aiGeneratedFeedback\": \"<string>\"}

        Job: " . json_encode(['title' => $job->title ?? '', 'description' => $job->description ?? ''], JSON_UNESCAPED_UNICODE) . "
        Resume: " . json_encode($resumeData, JSON_UNESCAPED_UNICODE);

        $result = $this->askGemini($prompt);

        if ($result && isset($result['aiGeneratedScore'], $result['aiGeneratedFeedback'])) {
            $result['aiGeneratedScore'] = (int) $result['aiGeneratedScore'];
            return $result;
        }

        return $fallback;
    }

    /**
     * Send a prompt to Gemini and get back a decoded JSON response
     */
    private function askGemini(string $prompt): ?array
    {
        for ($i = 1; $i <= $this->maxRetries; $i++) {
            try {
                $response = Http::withHeaders([
                    'x-goog-api-key' => config('services.gemini.key'),
                    'Content-Type'   => 'application/json',
                ])
                ->timeout(120)
                ->post($this->apiUrl, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                ]);

                // Request succeeded
                if ($response->successful()) {
                    $text = $response->json('candidates.0.content.parts.0.text');

                    if (empty($text)) {
                        return null;
                    }

                    // Strip ```json``` code fences if present
                    $clean = trim(preg_replace(['/^```(?:json)?/m', '/```$/m'], '', $text));

                    return json_decode($clean, true) ?: null;
                }

                // Non-retryable error (not 429 or 503), no point retrying
                if (!in_array($response->status(), [429, 503])) {
                    return null;
                }

            } catch (\Throwable $e) {
                // Will retry on the next loop iteration
            }

            // Wait a bit before retrying (skip on the last attempt)
            if ($i < $this->maxRetries) {
                sleep($this->retryDelay);
            }
        }

        return null;
    }
}
