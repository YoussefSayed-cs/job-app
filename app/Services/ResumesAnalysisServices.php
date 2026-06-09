<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class ResumesAnalysisServices
{
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent';

    public function __construct()
    {
        if (empty(config('services.gemini.key'))) {
            Log::error('Gemini API key is missing in configuration.');
        }
    }


    public function extractResumeInformation(string $fileUri): array
    {
        try {
            if (!Storage::disk('cloud')->exists($fileUri)) {
                Log::error("Gemini cannot find file at: " . $fileUri);
                return $this->emptySchema();
            }

            $fileContent = Storage::disk('cloud')->get($fileUri);
            
            // Parse PDF to Text using smalot/pdfparser
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseContent($fileContent);
            $pdfText = $pdf->getText();

            $promptText = "Extract resume details and return ONLY a valid JSON object with keys: summary (string), skills (array of strings), experience (array of objects), education (array of objects). No markdown, no explanation.\n\nResume Text:\n" . $pdfText;

            $text = $this->callGeminiWithRetry($promptText);
            
            if (!$text) {
                return $this->emptySchema();
            }

            $text = preg_replace('/```json\s*(.*?)\s*```/is', '$1', $text);
            $text = preg_replace('/```\s*(.*?)\s*```/is', '$1', $text);
            return json_decode($text, true) ?? $this->emptySchema();

        } catch (\Throwable $e) {
            Log::error('Extraction Failed: ' . $e->getMessage());
            return $this->emptySchema();
        }
    }


    public function analyzeResume($job_vacancy, array $resumeData): array
    {
        try {
            $jobInfo = [
                'title' => $job_vacancy->title ?? $job_vacancy->utils ?? 'Software Engineer',
                'description' => $job_vacancy->description ?? ''
            ];

            $prompt = "Compare this resume with the job requirements. Return ONLY a valid JSON object with exactly these keys: {\"aiGeneratedScore\": int between 0-100, \"aiGeneratedFeedback\": string}. No markdown, no code blocks, just raw JSON.\n\nJob: " . json_encode($jobInfo) . "\n\nResume: " . json_encode($resumeData);

            $text = $this->callGeminiWithRetry($prompt, 0.2);
            
            if (!$text) {
                return ['aiGeneratedScore' => 0, 'aiGeneratedFeedback' => 'Analysis failed due to API limits.'];
            }

            $text = preg_replace('/```json\s*(.*?)\s*```/is', '$1', $text);
            $text = preg_replace('/```\s*(.*?)\s*```/is', '$1', $text);
            return json_decode($text, true) ?? ['aiGeneratedScore' => 0, 'aiGeneratedFeedback' => 'Analysis failed.'];

        } catch (\Throwable $e) {
            Log::error('Resume Analysis Failed: ' . $e->getMessage());
            return ['aiGeneratedScore' => 0, 'aiGeneratedFeedback' => 'Service error.'];
        }
    }

    private function callGeminiWithRetry(string $prompt, float $temperature = 0.1): ?string
    {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            $attempt++;
            
            try {
                $response = Http::withHeaders([
                    'x-goog-api-key' => config('services.gemini.key'),
                    'Content-Type'   => 'application/json',
                ])->timeout(120)->post($this->baseUrl, [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature' => $temperature,
                    ]
                ]);
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // If there's a connection exception (like cURL 28 timeout), treat it as a retriable error
                Log::warning("Gemini API Connection Error on attempt {$attempt}: " . $e->getMessage() . ". Retrying in 10s...");
                if ($attempt < $maxRetries) {
                    sleep(10);
                    continue;
                }
                throw $e;
            }

            if ($response->successful()) {
                return $response->json('candidates.0.content.parts.0.text');
            }

            $status = $response->status();
            
            if ($status === 429 || $status === 503) {
                Log::warning("Gemini API Error {$status} on attempt {$attempt}. Retrying in 10s...");
                if ($attempt < $maxRetries) {
                    sleep(10);
                    continue;
                }
            }
            
            Log::error('Gemini API Final Error: ' . $status . ' ' . $response->body());
            break;
        }

        return null;
    }

    private function emptySchema(): array
    {
        return [
            'summary' => '',
            'skills' => [],
            'experience' => [],
            'education' => [],
        ];
    }
}
