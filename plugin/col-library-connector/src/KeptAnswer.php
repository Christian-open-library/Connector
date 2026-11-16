<?php
/**
 * One answer of the public quotation checker, kept for a while so a repeat of the same check (a share link opened
 * by many people) is answered without running it again (PublicCheck::kept).
 *
 * The words checked are not written down: the file's name and its AES-256-GCM key are both HMACs, with a server
 * secret, of what was checked (the build, the name and the words). Only a request with the same words finds the
 * file and can read it. Files older than the time allowed are not read, and are deleted when answers are written
 * (the public limits keep the folder to a few thousand small files).
 */

namespace ColLibrary;

defined('ABSPATH') || exit;

final class KeptAnswer
{
    /** Largest answer kept (bytes of JSON); a larger one is simply checked again next time. */
    private const MAX_BYTES = 262144;
    /** One write in SWEEP looks through the folder for files past their time. */
    private const SWEEP = 10;

    private readonly string $file;
    private readonly string $key;

    public function __construct(private readonly string $dir, string $secret, string $what, private readonly int $seconds,
                                private readonly string $field = 'verdict')
    {
        $this->file = $dir . '/' . hash_hmac('sha256', "name\0$what", $secret) . '.bin';
        $this->key = hash_hmac('sha256', "key\0$what", $secret, true);
    }

    /** @return array<string,mixed>|null the kept answer, or null if there is none still fresh */
    public function read(): ?array
    {
        $mtime = @filemtime($this->file);
        if ($mtime === false) {
            return null;
        }
        if (time() - $mtime >= $this->seconds) {
            @unlink($this->file);
            return null;
        }
        $raw = @file_get_contents($this->file, false, null, 0, self::MAX_BYTES + 64);
        if (!is_string($raw) || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, substr($raw, 0, 12),
            substr($raw, 12, 16));
        if (!is_string($plain)) {
            return null;
        }
        $data = json_decode($plain, true);
        return is_array($data) && isset($data[$this->field]) ? $data : null;
    }

    /** @param array<string,mixed> $answer */
    public function write(array $answer): void
    {
        try {
            $json = json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($json) > self::MAX_BYTES) {
                return;
            }
            if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
                return;
            }
            $iv = random_bytes(12);
            $tag = '';
            $cipher = openssl_encrypt($json, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
            if (!is_string($cipher)) {
                return;
            }
            $tmp = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
            if (@file_put_contents($tmp, $iv . $tag . $cipher) === false) {
                @unlink($tmp);
                return;
            }
            @chmod($tmp, 0600);
            if (!@rename($tmp, $this->file)) {
                @unlink($tmp);
            }
            if (random_int(1, self::SWEEP) === 1) {
                $this->sweep();
            }
        } catch (\Throwable) {
            // Keeping an answer is a saving, never a reason for the check to fail.
        }
    }

    /** Deletes the files past their time (and stray temporary files). */
    public function sweep(): void
    {
        $now = time();
        $h = @opendir($this->dir);
        if ($h === false) {
            return;
        }
        while (($name = readdir($h)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $this->dir . '/' . $name;
            $mtime = @filemtime($path);
            if ($mtime !== false && $now - $mtime >= (str_ends_with($name, '.tmp') ? 60 : $this->seconds)) {
                @unlink($path);
            }
        }
        closedir($h);
    }
}
