<?php

namespace Tests\Unit;

use App\Services\PushDnsResolver;
use App\Services\SafeWebPushClient;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;

class SafeWebPushClientTest extends TestCase
{
    public static function unsafeAnswers(): array
    {
        return array_map(fn ($ips) => [$ips], [[], ['127.0.0.1'], ['10.0.0.1'], ['169.254.169.254'], ['::1'], ['fd00::1'], ['fe80::1'], ['::ffff:127.0.0.1'], ['64:ff9b::a00:1'], ['2002:7f00:1::'], ['8.8.8.8', '192.168.1.1'], ['not-an-ip']]);
    }

    #[DataProvider('unsafeAnswers')]
    public function test_private_mixed_unresolved_and_translation_dns_answers_are_blocked_before_transport(array $ips): void
    {
        $resolver = $this->createMock(PushDnsResolver::class);
        $resolver->expects($this->once())->method('addresses')->with('push.example.test')->willReturn($ips);
        $client = new class($resolver) extends SafeWebPushClient
        {
            protected function client(array $options): ClientInterface
            {
                throw new \LogicException('Transport must not run.');
            }
        };
        $this->expectException(ClientExceptionInterface::class);
        $client->sendRequest(new Request('POST', 'https://push.example.test/opaque-subscription'));
    }

    public function test_public_provider_is_pinned_once_with_tls_and_no_redirects_or_proxy(): void
    {
        $resolver = $this->createMock(PushDnsResolver::class);
        $resolver->expects($this->once())->method('addresses')->willReturn(['8.8.8.8', '2606:4700:4700::1111']);
        $client = new class($resolver) extends SafeWebPushClient
        {
            public array $options;

            protected function client(array $options): ClientInterface
            {
                $this->options = $options;

                return new Client(['handler' => new MockHandler([new Response(201)])] + array_diff_key($options, ['handler' => true]));
            }
        };
        $this->assertSame(201, $client->sendRequest(new Request('POST', 'https://push.example.test/opaque'))->getStatusCode());
        $this->assertInstanceOf(CurlHandler::class, $client->options['handler']);
        $this->assertSame(['push.example.test:443:8.8.8.8,[2606:4700:4700::1111]'], $client->options['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $client->options['proxy']);
        $this->assertFalse($client->options['allow_redirects']);
        $this->assertTrue($client->options['verify']);
        $this->assertFalse($client->options['debug']);
        $this->assertSame(5, $client->options['connect_timeout']);
        $this->assertSame(15, $client->options['timeout']);
    }

    public function test_redirect_response_is_not_followed_to_an_internal_destination(): void
    {
        $resolver = $this->createMock(PushDnsResolver::class);
        $resolver->expects($this->once())->method('addresses')->willReturn(['8.8.8.8']);
        $client = new class($resolver) extends SafeWebPushClient
        {
            protected function client(array $options): ClientInterface
            {
                return new Client(['handler' => new MockHandler([new Response(307, ['Location' => 'https://127.0.0.1/private'])])] + array_diff_key($options, ['handler' => true]));
            }
        };
        $this->assertSame(307, $client->sendRequest(new Request('POST', 'https://push.example.test/opaque'))->getStatusCode());
    }

    public function test_unsafe_url_is_rejected_without_even_resolving_dns(): void
    {
        $resolver = $this->createMock(PushDnsResolver::class);
        $resolver->expects($this->never())->method('addresses');
        $this->expectException(ClientExceptionInterface::class);
        (new SafeWebPushClient($resolver))->sendRequest(new Request('POST', 'http://push.example.test/opaque'));
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], ['https://127.0.0.1/private', 'https://[::1]/private', 'https://user:fixture@push.example.test/push', 'https://service.internal/push', 'https://push.example.test/push#fragment', 'https://2130706433/push']);
    }

    #[DataProvider('unsafeUrls')]
    public function test_disallowed_urls_do_not_reach_dns_or_transport(string $url): void
    {
        $resolver = $this->createMock(PushDnsResolver::class);
        $resolver->expects($this->never())->method('addresses');
        $this->expectException(ClientExceptionInterface::class);
        (new SafeWebPushClient($resolver))->sendRequest(new Request('POST', $url));
    }

    public function test_public_to_private_dns_change_is_checked_again_for_the_next_request(): void
    {
        $resolver = $this->createMock(PushDnsResolver::class);
        $resolver->expects($this->exactly(2))->method('addresses')->willReturnOnConsecutiveCalls(['8.8.8.8'], ['127.0.0.1']);
        $client = new class($resolver) extends SafeWebPushClient
        {
            public int $transportCalls = 0;

            protected function client(array $options): ClientInterface
            {
                $this->transportCalls++;

                return new Client(['handler' => new MockHandler([new Response(201)])] + array_diff_key($options, ['handler' => true]));
            }
        };
        $request = new Request('POST', 'https://push.example.test/push');
        $this->assertSame(201, $client->sendRequest($request)->getStatusCode());
        try {
            $client->sendRequest($request);
            $this->fail('Rebound DNS must be rejected.');
        } catch (ClientExceptionInterface) {
            $this->assertSame(1, $client->transportCalls);
        }
    }

    public function test_connection_failure_stays_a_psr_network_failure_without_a_retry(): void
    {
        $resolver = $this->createMock(PushDnsResolver::class);
        $resolver->expects($this->once())->method('addresses')->willReturn(['8.8.8.8']);
        $client = new class($resolver) extends SafeWebPushClient
        {
            protected function client(array $options): ClientInterface
            {
                return new Client(['handler' => new MockHandler([new ConnectException('Controlled connection failure', new Request('POST', 'https://push.example.test/push'))])] + array_diff_key($options, ['handler' => true]));
            }
        };
        $this->expectException(NetworkExceptionInterface::class);
        $client->sendRequest(new Request('POST', 'https://push.example.test/push'));
    }
}
