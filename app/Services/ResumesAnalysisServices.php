<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class ResumesAnalysisServices
{
    // ─── Constants ────────────────────────────────────────────────────────────
    private const API_URL          = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';
    private const MAX_RETRIES      = 3;
    private const RETRY_DELAY_SEC  = 10;
    private const REQUEST_TIMEOUT  = 120;
    private const RETRYABLE_CODES  = [429, 503];

    // ─── Constructor ──────────────────────────────────────────────────────────
    public function __construct()
    {
        if (empty(config('services.gemini.key'))) {
            Log::error('Gemini API key is missing in configuration.');
        }
    }

    // ─── Public API ───────────────────────────────────────────────────────────

    /**
     * Extract structured information from a PDF resume stored in cloud.
     */
    public function extractResumeInformation(string $fileUri): array
    {
        try {
            $pdfText = $this->readPdfFromStorage($fileUri);

            if (empty(trim($pdfText))) {
                Log::warning('PDF parsed text is empty.', ['file' => $fileUri]);
                return $this->emptySchema();
            }

            $response = $this->callGeminiWithRetry(
                $this->buildExtractionPrompt($pdfText)
            );

            return $this->parseJsonResponse($response) ?? $this->emptySchema();

        } catch (\Throwable $e) {
            Log::error('Resume extraction failed.', [
                'file'  => $fileUri,
                'error' => $e->getMessage(),
            ]);
            return $this->emptySchema();
        }
    }

    /**
     * Score and provide feedback for a resume against a job vacancy.
     */
    public function analyzeResume(mixed $jobVacancy, array $resumeData): array
    {
        $fallback = ['aiGeneratedScore' => 0, 'aiGeneratedFeedback' => 'Analysis failed.'];

        try {
            $jobInfo  = $this->extractJobInfo($jobVacancy);
            $response = $this->callGeminiWithRetry(
                $this->buildAnalysisPrompt($jobInfo, $resumeData),
                temperature: 0.2
            );

            $result = $this->parseJsonResponse($response);

            return $this->validateAnalysisResult($result) ?? $fallback;

        } catch (\Throwable $e) {
            Log::error('Resume analysis failed.', [
                'job'   => $jobVacancy->title ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
            return $fallback;
        }
    }

    // ─── Private: Storage & PDF ───────────────────────────────────────────────

    /**
     * Download the file from cloud storage and extract its text via PDF parser.
     *
     * @throws RuntimeException if the file does not exist in storage.
     */
    private function readPdfFromStorage(string $fileUri): string
    {
        if (!Storage::disk('cloud')->exists($fileUri)) {
            throw new RuntimeException("File not found in cloud storage: {$fileUri}");
        }

        $content = Storage::disk('cloud')->get($fileUri);

        $parser = new \Smalot\PdfParser\Parser();
        $pdf    = $parser->parseContent($content);

        return $pdf->getText();
    }

    // ─── Private: Prompt Builders ─────────────────────────────────────────────

    private function buildExtractionPrompt(string $pdfText): string
    {
        return <<<PROMPT
        Extract resume details and return ONLY a valid JSON object matching this exact structure:
        {
            "summary":    "string",
            "skills":     ["string"],
            "experience": [{"title": "string", "company": "string", "duration": "string", "description": "string"}],
            "education":  [{"degree": "string", "institution": "string", "year": "string"}]
        }
        No markdown, no code blocks, no explanation — raw JSON only.

        Resume Text:
        {$pdfText}
        PROMPT;
    }

    private function buildAnalysisPrompt(array $jobInfo, array $resumeData): string
    {
        $job    = json_encode($jobInfo,    JSON_UNESCAPED_UNICODE);
        $resume = json_encode($resumeData, JSON_UNESCAPED_UNICODE);

        return <<<PROMPT
        Compare the resume against the job requirements and return ONLY a raw JSON object:
        {"aiGeneratedScore": <integer 0–100>, "aiGeneratedFeedback": "<string>"}
        No markdown, no code blocks.

        Job: {$job}
        Resume: {$resume}
        PROMPT;
    }

    // ─── Private: Job Info Extraction ─────────────────────────────────────────

    private function extractJobInfo(mixed $jobVacancy): array
    {
        return [
            'title'       => $jobVacancy->title       ?? 'Software Engineer',
            'description' => $jobVacancy->description ?? '',
        ];
    }

    // ─── Private: Gemini API ──────────────────────────────────────────────────

    /**
     * Call Gemini API with exponential-friendly retry on transient errors.
     */
    private function callGeminiWithRetry(string $prompt, float $temperature = 0.1): ?string
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'x-goog-api-key' => config('services.gemini.key'),
                    'Content-Type'   => 'application/json',
                ])
                ->timeout(self::REQUEST_TIMEOUT)
                ->post(self::API_URL, [
                    'contents'         => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => $temperature],
                ]);

                if ($response->successful()) {
                    return $response->json('candidates.0.content.parts.0.text');
                }

                $status = $response->status();

                // Non-retryable error — bail immediately
                if (!in_array($status, self::RETRYABLE_CODES, strict: true)) {
                    Log::error('Gemini API non-retryable error.', [
                        'status' => $status,
                        'body'   => $response->body(),
                    ]);
                    return null;
                }

                Log::warning("Gemini API error {$status} on attempt {$attempt}.", [
                    'max_retries' => self::MAX_RETRIES,
                ]);

            } catch (ConnectionException $e) {
                Log::warning("Gemini connection error on attempt {$attempt}.", [
                    'error' => $e->getMessage(),
                ]);
                $lastError = $e;
            }

            // Sleep before retry (skip sleep after the last attempt)
            if ($attempt < self::MAX_RETRIES) {
                sleep(self::RETRY_DELAY_SEC);
            }
        }

        Log::error('Gemini API failed after all retries.', [
            'exception' => $lastError?->getMessage(),
        ]);

        return null;
    }

    // ─── Private: Response Parsing & Validation ───────────────────────────────

    /**
     * Strip markdown fences and decode JSON from Gemini's raw response.
     */
    private function parseJsonResponse(?string $text): ?array
    {
        if (empty($text)) {
            return null;
        }

        // Remove ```json ... ``` or ``` ... ``` wrappers
        $clean = preg_replace('/^```(?:json)?\s*/m', '', $text);
        $clean = preg_replace('/\s*```$/m', '',          $clean);
        $clean = trim($clean);

        return json_decode($clean, associative: true) ?: null;
    }

    /**
     * Ensure the analysis result has the expected shape and sane score bounds.
     */
    private function validateAnalysisResult(?array $result): ?array
    {
        if (
            is_array($result)
            && isset($result['aiGeneratedScore'], $result['aiGeneratedFeedback'])
            && is_numeric($result['aiGeneratedScore'])
            && $result['aiGeneratedScore'] >= 0
            && $result['aiGeneratedScore'] <= 100
            && is_string($result['aiGeneratedFeedback'])
        ) {
            $result['aiGeneratedScore'] = (int) $result['aiGeneratedScore'];
            return $result;
        }

        return null;
    }

    // ─── Private: Helpers ─────────────────────────────────────────────────────

    private function emptySchema(): array
    {
        return [
            'summary'    => '',
            'skills'     => [],
            'experience' => [],
            'education'  => [],
        ];
    }
}