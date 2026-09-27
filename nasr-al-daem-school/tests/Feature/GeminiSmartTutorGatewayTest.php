<?php

namespace Tests\Feature;

use App\Contracts\SmartTutorGateway;
use App\Data\SmartTutorPrompt;
use App\Data\SmartTutorTurn;
use App\Exceptions\SmartTutorGatewayException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GeminiSmartTutorGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['smart_tutor.gemini.api_key' => 'test-key']);
        Http::preventStrayRequests();
    }

    public function test_sends_history_and_instructions_and_extracts_only_visible_text(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [
                ['text' => 'private thought', 'thought' => true],
                ['text' => 'الإجابة'], ['text' => ' التعليمية'],
            ]]]],
        ])]);
        $reply = app(SmartTutorGateway::class)->reply(new SmartTutorPrompt([
            new SmartTutorTurn('system', 'Teach gently'),
            new SmartTutorTurn('user', 'First question'),
            new SmartTutorTurn('assistant', 'First answer'),
            new SmartTutorTurn('user', 'Next question'),
        ], context: ['stage' => 'primary', 'student_id' => 123]));

        $this->assertSame('الإجابة التعليمية', $reply->content);
        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($request->url(), 'test-key')
            && $request['contents'][1]['role'] === 'model'
            && $request['contents'][2]['parts'][0]['text'] === 'Next question'
            && $request['systemInstruction']['parts'][0]['text'] === 'Teach gently'
            && ! str_contains($request->body(), 'student_id'));
    }

    public function test_missing_key_does_not_send_a_request(): void
    {
        config(['smart_tutor.gemini.api_key' => '']);
        try {
            app(SmartTutorGateway::class)->reply($this->prompt());
            $this->fail('Expected missing configuration');
        } catch (SmartTutorGatewayException $exception) {
            $this->assertSame('not_configured', $exception->reason);
        }
        Http::assertNothingSent();
    }

    #[DataProvider('failureResponses')]
    public function test_translates_failures_without_exposing_provider_details(int $status, array $body, string $reason): void
    {
        Http::fake(['*' => Http::response($body, $status, ['Retry-After' => '30'])]);
        try {
            app(SmartTutorGateway::class)->reply($this->prompt());
            $this->fail('Expected gateway failure');
        } catch (SmartTutorGatewayException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertNull($exception->getPrevious());
            if ($status === 429) {
                $this->assertSame(30, $exception->retryAfterSeconds);
            }
        }
    }

    public static function failureResponses(): array
    {
        return [
            [429, ['error' => 'secret provider detail'], 'rate_limited'],
            [403, ['error' => 'secret provider detail'], 'upstream_failure'],
            [500, [], 'upstream_failure'],
            [504, [], 'timeout'],
            [200, [], 'invalid_response'],
            [200, ['candidates' => [['finishReason' => 'SAFETY']]], 'invalid_response'],
            [200, ['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => 'partial']]]]]], 'invalid_response'],
            [200, ['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => ' ']]]]]], 'invalid_response'],
        ];
    }

    public function test_connection_failure_is_safe(): void
    {
        Http::fake(['*' => Http::failedConnection()]);
        $this->expectException(SmartTutorGatewayException::class);
        $this->expectExceptionMessage('Smart Tutor gateway failure [timeout].');
        app(SmartTutorGateway::class)->reply($this->prompt());
    }

    private function prompt(): SmartTutorPrompt
    {
        return new SmartTutorPrompt([new SmartTutorTurn('user', 'Explain fractions')]);
    }
}
