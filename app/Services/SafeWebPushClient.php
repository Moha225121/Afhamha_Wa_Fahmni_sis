<?php

namespace App\Services;

use App\Rules\PushEndpoint;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\IpUtils;

/** Used only for legacy VAPID delivery; FCM and the tutor retain their provider clients. */
class SafeWebPushClient implements ClientInterface
{
    public function __construct(private readonly PushDnsResolver $resolver) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        if (! PushEndpoint::allowed((string) $request->getUri())) {
            throw new RequestException('Push destination rejected.', $request);
        }

        $host = trim($request->getUri()->getHost(), '[]');
        $addresses = $this->resolver->addresses($host);
        if ($addresses === []) {
            throw new RequestException('Push destination could not be resolved.', $request);
        }
        foreach ($addresses as $address) {
            $literal = str_contains($address, ':') ? '['.$address.']' : $address;
            if (! PushEndpoint::allowed('https://'.$literal)
                || IpUtils::checkIp($address, ['64:ff9b::/96', '64:ff9b:1::/48', '2002::/16', '2001::/32'])) {
                throw new RequestException('Push destination rejected.', $request);
            }
        }

        // A fresh cURL handle uses only these checked addresses, preserving the URL host for TLS/SNI.
        // No second DNS lookup, environment proxy, redirect, cookies or alternate HTTP transport.
        $curl = [];
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            $port = $request->getUri()->getPort() ?? 443;
            $pinned = array_map(fn ($ip) => str_contains($ip, ':') ? '['.$ip.']' : $ip, $addresses);
            $curl[CURLOPT_RESOLVE] = [$host.':'.$port.':'.implode(',', $pinned)];
        }

        return $this->client([
            'handler' => new CurlHandler,
            'curl' => $curl,
            'proxy' => '',
            'allow_redirects' => false,
            'verify' => true,
            'cookies' => false,
            'connect_timeout' => 5,
            'timeout' => 15,
            'http_errors' => false,
            'debug' => false,
        ])->sendRequest($request);
    }

    protected function client(array $options): ClientInterface
    {
        return new Client($options);
    }
}
