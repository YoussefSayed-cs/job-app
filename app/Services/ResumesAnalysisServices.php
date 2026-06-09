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
            $response = Http::withHeaders([
                'x-goog-api-key' => config('services.gemini.key'),
                'Content-Type'   => 'application/json',
            ])->post($this->baseUrl, [
                'contents' => [
                    ['parts' => [['text' => $prompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                ]
            ]);

            // Handle Rate Limit (Free Tier)
            if ($response->status() === 429) {
                Log::warning('Gemini Rate Limit Hit (Analysis). Sleeping for 15 seconds...');
                sleep(15);
                $response = Http::withHeaders([
                    'x-goog-api-key' => config('services.gemini.key'),
                    'Content-Type'   => 'application/json',
                ])->post($this->baseUrl, [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature' => 0.2,
                    ]
                ]);
            }

            if ($response->failed()) {
                Log::error('Gemini Analysis Error: ' . $response->status() . ' ' . $response->body());
                return ['aiGeneratedScore' => 0, 'aiGeneratedFeedback' => 'Analysis failed.'];
            }

            $text = $response->json('candidates.0.content.parts.0.text');
            $text = preg_replace('/```json\s*(.*?)\s*```/is', '$1', $text);
            $text = preg_replace('/```\s*(.*?)\s*```/is', '$1', $text);
            return json_decode($text, true) ?? ['aiGeneratedScore' => 0, 'aiGeneratedFeedback' => 'Analysis failed.'];

        } catch (\Throwable $e) {
            Log::error('Resume Analysis Failed: ' . $e->getMessage());
            return ['aiGeneratedScore' => 0, 'aiGeneratedFeedback' => 'Service error.'];
        }
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
