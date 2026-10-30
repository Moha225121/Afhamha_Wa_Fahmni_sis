<?php

namespace Tests\Unit;

use Illuminate\Support\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LessonVideoTimeoutConfigTest extends TestCase
{
    public static function deadlines(): array
    {
        return [
            'unset' => [null, 120],
            'minimum' => ['1', 1],
            'custom' => ['180', 180],
            'maximum' => ['300', 300],
            'zero_cannot_disable_timeout' => ['0', 120],
            'negative' => ['-1', 120],
            'unbounded' => ['301', 120],
            'invalid' => ['invalid', 120],
            'empty' => ['', 120],
            'fractional' => ['0.5', 120],
        ];
    }

    #[DataProvider('deadlines')]
    public function test_environment_always_provides_a_bounded_deadline(?string $value, int $expected): void
    {
        $repository = Env::getRepository();
        $key = 'LESSON_VIDEO_TIMEOUT_SECONDS';
        $previous = $repository->get($key);
        try {
            $repository->clear($key);
            if ($value !== null) {
                $repository->set($key, $value);
            }
            $config = require dirname(__DIR__, 2).'/config/lesson_uploads.php';
            $this->assertSame($expected, $config['video']['timeout_seconds']);
        } finally {
            $repository->clear($key);
            if ($previous !== null) {
                $repository->set($key, $previous);
            }
        }
    }
}
