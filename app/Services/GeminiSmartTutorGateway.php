<?php

namespace App\Services;

use App\Contracts\SmartTutorGateway;
use App\Data\SmartTutorPrompt;
use App\Data\SmartTutorReply;
use App\Exceptions\SmartTutorGatewayException;
use App\Exceptions\SmartTutorUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GeminiSmartTutorGateway implements SmartTutorGateway
{
    public function reply(SmartTutorPrompt $prompt): SmartTutorReply
    {
        $key = trim((string) config('smart_tutor.gemini.api_key'));
        $model = trim((string) config('smart_tutor.gemini.model'));

        if ($key === '' || ! preg_match('/^[a-zA-Z0-9._-]+$/', $model)) {
            throw new SmartTutorUnavailableException;
        }

        $instructions = [];
        $contents = [];
        foreach ($prompt->turns as $turn) {
            if ($turn->role === 'system') {
                $instructions[] = ['text' => $turn->content];
            } else {
                $contents[] = [
                    'role' => $turn->role === 'assistant' ? 'model' : 'user',
                    'parts' => [['text' => $turn->content]],
                ];
            }
        }

        $instructions[] = ['text' => 'Reply in locale '.$prompt->locale.'. Keep the answer concise and under '.config('smart_tutor.reply.max_characters', 12000).' characters.'];
        $context = array_intersect_key($prompt->context, array_flip(['classroom', 'stage']));
        if ($context !== []) {
            $instructions[] = ['text' => 'Educational context: '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)];
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => $key])
                ->connectTimeout(10)
                ->timeout(max(1, min(90, (int) config('smart_tutor.gemini.timeout_seconds', 60))))
                ->withOptions(['allow_redirects' => false])
                ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => $instructions],
                    'contents' => $contents,
                    'generationConfig' => [
                        'maxOutputTokens' => (int) config('smart_tutor.gemini.max_output_tokens', 4096),
                    ],
                ]);
        } catch (ConnectionException) {
            // Do not attach request exceptions, which can contain credentials.
            throw SmartTutorGatewayException::timeout();
        }

        if ($response->status() === 429) {
            $retryAfter = $response->header('Retry-After');
            throw SmartTutorGatewayException::rateLimited(ctype_digit($retryAfter) ? (int) $retryAfter : null);
        }
        if (in_array($response->status(), [408, 504], true)) {
            throw SmartTutorGatewayException::timeout();
        }
        if (! $response->successful()) {
            throw SmartTutorGatewayException::upstreamFailure();
        }

        $candidate = $response->json('candidates.0');
        if (! is_array($candidate) || ($candidate['finishReason'] ?? null) !== 'STOP') {
            throw SmartTutorGatewayException::invalidResponse();
        }

        $text = '';
        $parts = $candidate['content']['parts'] ?? [];
        if (! is_array($parts)) {
            throw SmartTutorGatewayException::invalidResponse();
        }
        foreach ($parts as $part) {
            if (is_array($part) && empty($part['thought']) && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > (int) config('smart_tutor.reply.max_characters', 12000)) {
            throw SmartTutorGatewayException::invalidResponse();
        }

        return new SmartTutorReply($text, 'STOP');
    }
}
