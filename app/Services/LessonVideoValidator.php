<?php

namespace App\Services;

use App\Exceptions\LessonVideoUnavailableException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class LessonVideoValidator
{
    public function assertAvailable(): string
    {
        if (! function_exists('proc_open')) {
            Log::error('lesson_upload.media_unavailable', ['reason' => 'process_unavailable']);
            throw new LessonVideoUnavailableException('process_unavailable');
        }
        $binary = (string) config('lesson_uploads.video.binary', 'ffmpeg');
        $executable = is_file($binary) ? realpath($binary) : (new ExecutableFinder)->find($binary);
        if (! $executable || ! is_executable($executable)) {
            Log::error('lesson_upload.media_unavailable', ['reason' => 'executable_unavailable']);
            throw new LessonVideoUnavailableException('executable_unavailable');
        }

        return $executable;
    }

    public function validate(string $file, ?string $receipt = null): string
    {
        $path = realpath($file);
        if (! $path || ! is_file($path) || ! is_readable($path)) {
            $this->invalid('file_unavailable');
        }
        $digest = hash_file('sha256', $path);
        if ($receipt !== null && $this->hasReceipt($receipt, $digest)) {
            return $digest;
        }
        $this->container($path);
        $executable = $this->assertAvailable();
        $command = [$executable, '-hide_banner', '-loglevel', 'error', '-xerror', '-nostdin',
            '-max_alloc', (string) config('lesson_uploads.video.max_alloc_bytes'),
            '-filter_threads', '1', '-filter_complex_threads', '1', '-threads', '1',
            '-max_pixels', (string) config('lesson_uploads.video.max_pixels'), '-err_detect', 'explode',
            '-protocol_whitelist', 'file', '-f', 'mov', '-enable_drefs', '0', '-use_absolute_path', '0',
            '-i', $path, '-map', '0:v:0', '-an', '-sn', '-dn', '-threads', '1',
            '-f', 'null', '-', '-progress', 'pipe:1', '-nostats'];
        // No shell, no client filename, no application credentials in the child environment.
        $environment = array_fill_keys(array_keys(getenv() + $_ENV + $_SERVER), false);
        foreach (['PATH', 'Path', 'SystemRoot', 'SYSTEMROOT', 'WINDIR', 'TEMP', 'TMP'] as $key) {
            if (getenv($key) !== false) {
                $environment[$key] = getenv($key);
            }
        }
        $environment['LC_ALL'] = 'C';
        $process = null;
        $progress = '';
        $outputBytes = 0;
        $stderrSeen = false;
        $started = microtime(true);
        try {
            $process = new Process($command, null, $environment, null, (float) config('lesson_uploads.video.timeout_seconds'));
            $process->disableOutput();
            $process->run(function (string $type, string $data) use (&$progress, &$outputBytes, &$stderrSeen): void {
                $outputBytes += strlen($data);
                if ($outputBytes > (int) config('lesson_uploads.video.max_output_bytes')) {
                    $this->invalid('output_limit');
                }
                if ($type === Process::OUT) {
                    $progress .= $data;
                } elseif (trim($data) !== '') {
                    $stderrSeen = true;
                }
            });
        } catch (ProcessTimedOutException) {
            $this->invalid('timeout');
        } catch (ExceptionInterface $exception) {
            Log::error('lesson_upload.media_unavailable', ['reason' => 'process_failed']);
            throw new LessonVideoUnavailableException('process_failed', $exception);
        } finally {
            if ($process !== null && $process->isRunning()) {
                $process->stop(0);
            }
        }
        preg_match_all('/^frame=(\d+)\s*$/m', $progress, $frames);
        preg_match_all('/^out_time_us=(-?\d+)\s*$/m', $progress, $times);
        if (! $process->isSuccessful() || $stderrSeen || ! in_array('progress=end', preg_split('/\r?\n/', $progress), true)
            || max(array_map('intval', $frames[1]) ?: [0]) < 1 || max(array_map('intval', $times[1]) ?: [0]) <= 0) {
            $this->invalid('decode_failed');
        }
        if (! hash_equals($digest, hash_file('sha256', $path))) {
            $this->invalid('file_changed');
        }
        if ($receipt !== null) {
            $data = json_encode(['digest' => $digest, 'policy' => $this->policy(), 'signature' => $this->signature($digest)], JSON_THROW_ON_ERROR);
            try {
                if (is_link($receipt) || file_put_contents($receipt, $data, LOCK_EX) !== strlen($data)) {
                    throw new \RuntimeException('Unable to persist video verification receipt.');
                }
            } catch (\Throwable $exception) {
                Log::error('lesson_upload.media_unavailable', ['reason' => 'receipt_unavailable']);
                throw new LessonVideoUnavailableException('receipt_unavailable', $exception);
            }
        }
        Log::info('lesson_upload.media_verified', ['duration_ms' => round((microtime(true) - $started) * 1000), 'frames' => max(array_map('intval', $frames[1]))]);

        return $digest;
    }

    public function hasReceipt(string $receipt, string $digest): bool
    {
        if (! is_file($receipt) || is_link($receipt) || filesize($receipt) > 1024 || config('app.key') === null || config('app.key') === '') {
            return false;
        }
        $data = json_decode(file_get_contents($receipt), true);

        return is_array($data) && ($data['policy'] ?? null) === $this->policy() && ($data['digest'] ?? null) === $digest
            && is_string($data['signature'] ?? null) && hash_equals($this->signature($digest), $data['signature']);
    }

    private function policy(): string
    {
        return hash('sha256', 'mp4-full-decode-v1|'.json_encode(config('lesson_uploads.video'), JSON_THROW_ON_ERROR));
    }

    private function signature(string $digest): string
    {
        return hash_hmac('sha256', $this->policy().'|'.$digest, (string) config('app.key'));
    }

    private function container(string $path): void
    {
        $size = filesize($path);
        $stream = fopen($path, 'rb');
        $offset = 0;
        $atoms = [];
        $count = 0;
        $started = microtime(true);
        try {
            while ($offset < $size) {
                if (++$count > 10000 || microtime(true) - $started > (float) config('lesson_uploads.video.timeout_seconds')) {
                    $this->invalid('container_limit');
                }
                if ($size - $offset < 8 || fseek($stream, $offset) !== 0) {
                    $this->invalid('truncated_atom');
                }
                $header = fread($stream, 8);
                if (strlen($header) !== 8) {
                    $this->invalid('truncated_atom');
                }
                $length = unpack('N', substr($header, 0, 4))[1];
                $type = substr($header, 4, 4);
                $minimum = 8;
                if ($length === 1) {
                    $wide = fread($stream, 8);
                    if (strlen($wide) !== 8) {
                        $this->invalid('truncated_atom');
                    }
                    $parts = unpack('Nhigh/Nlow', $wide);
                    // The application caps files below 2^32 bytes; any nonzero high word exceeds it.
                    if ($parts['high'] !== 0) {
                        $this->invalid('oversized_atom');
                    }
                    $length = $parts['low'];
                    $minimum = 16;
                } elseif ($length === 0) {
                    $length = $size - $offset;
                }
                if ($length < $minimum || $length > $size - $offset) {
                    $this->invalid('truncated_atom');
                }
                if (in_array($type, ['ftyp', 'moov', 'mdat'], true) && $length > $minimum) {
                    $atoms[$type] = true;
                }
                $offset += $length;
            }
        } finally {
            fclose($stream);
        }
        if (! isset($atoms['ftyp'], $atoms['moov'], $atoms['mdat'])) {
            $this->invalid('missing_media_structure');
        }
    }

    private function invalid(string $reason): never
    {
        Log::warning('lesson_upload.media_rejected', ['reason' => $reason]);
        throw ValidationException::withMessages(['attachment' => $reason === 'timeout'
            ? 'تجاوز فحص الفيديو المهلة المسموحة. جرّب فيديو بترميز أقل تعقيدًا أو تواصل مع الإدارة.'
            : 'الفيديو غير صالح أو مبتور أو يتجاوز حدود المعالجة الآمنة. أعد تصديره كملف MP4 صالح.']);
    }
}
