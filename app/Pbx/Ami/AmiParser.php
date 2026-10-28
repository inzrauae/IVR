<?php

namespace App\Pbx\Ami;

/**
 * Splits the AMI byte stream into messages ("Key: Value" lines, blank-line terminated).
 */
class AmiParser
{
    private string $buffer = '';

    /**
     * Feed raw bytes; returns every complete message now available.
     *
     * @return list<array<string, string>>
     */
    public function push(string $bytes): array
    {
        $this->buffer .= $bytes;
        $messages = [];

        while (($end = strpos($this->buffer, "\r\n\r\n")) !== false) {
            $block = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 4);

            if (($message = self::parseBlock($block)) !== []) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public static function parseBlock(string $block): array
    {
        $message = [];

        foreach (explode("\r\n", $block) as $line) {
            $colon = strpos($line, ':');

            if ($colon === false) {
                continue;
            }

            $message[substr($line, 0, $colon)] = ltrim(substr($line, $colon + 1));
        }

        return $message;
    }

    /**
     * @param  array<string, string>  $fields
     */
    public static function encode(array $fields): string
    {
        $lines = '';

        foreach ($fields as $key => $value) {
            // Strip CR/LF so a value can never inject extra headers or actions.
            $lines .= $key.': '.str_replace(["\r", "\n"], '', (string) $value)."\r\n";
        }

        return $lines."\r\n";
    }
}
