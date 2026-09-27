<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class SmartTutorGatewayException extends RuntimeException
{
    public const INVALID_RESPONSE = 'invalid_response';

    public const NOT_CONFIGURED = 'not_configured';

    public const RATE_LIMITED = 'rate_limited';

    public const REQUEST_CONFLICT = 'request_conflict';

    public const REQUEST_IN_PROGRESS = 'request_in_progress';

    public const STALE_PENDING = 'stale_pending';

    public const TIMEOUT = 'timeout';

    public const UPSTREAM_FAILURE = 'upstream_failure';

    /** @var list<string> */
    private const REASONS = [
        self::INVALID_RESPONSE,
        self::NOT_CONFIGURED,
        self::RATE_LIMITED,
        self::REQUEST_CONFLICT,
        self::REQUEST_IN_PROGRESS,
        self::STALE_PENDING,
        self::TIMEOUT,
        self::UPSTREAM_FAILURE,
    ];

    public readonly string $reason;

    public readonly ?int $retryAfterSeconds;

    public function __construct(
        string $reason,
        ?Throwable $previous = null,
        ?int $retryAfterSeconds = null,
    ) {
        $this->reason = in_array($reason, self::REASONS, true) ? $reason : self::UPSTREAM_FAILURE;
        $this->retryAfterSeconds = $retryAfterSeconds;

        parent::__construct("Smart Tutor gateway failure [{$this->reason}].", 0, $previous);
    }

    public static function fromReason(string $reason, ?Throwable $previous = null, ?int $retryAfterSeconds = null): self
    {
        return new self($reason, $previous, $retryAfterSeconds);
    }

    public static function invalidResponse(?Throwable $previous = null): self
    {
        return new self(self::INVALID_RESPONSE, $previous);
    }

    public static function rateLimited(?int $retryAfterSeconds = null, ?Throwable $previous = null): self
    {
        return new self(self::RATE_LIMITED, $previous, $retryAfterSeconds);
    }

    public static function timeout(?Throwable $previous = null): self
    {
        return new self(self::TIMEOUT, $previous);
    }

    public static function upstreamFailure(?Throwable $previous = null): self
    {
        return new self(self::UPSTREAM_FAILURE, $previous);
    }
}
