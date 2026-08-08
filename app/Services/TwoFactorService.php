<?php

declare(strict_types=1);

namespace App\Services;

use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class TwoFactorService
{
    private const VALID_CHARACTERS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a new secret key (16 chars base32)
     */
    public function generateSecret(int $length = 16): string
    {
        $secret = '';
        $validChars = self::VALID_CHARACTERS;
        for ($i = 0; $i < $length; $i++) {
            $secret .= $validChars[random_int(0, 31)];
        }
        return $secret;
    }

    /**
     * Gera o QR Code TOTP localmente para que o segredo nunca saia do servidor.
     */
    public function getQrCodeUrl(string $companyName, string $holder, string $secret): string
    {
        $otpauth = "otpauth://totp/" . rawurlencode($companyName) . ":" . rawurlencode($holder) . "?secret=" . $secret . "&issuer=" . rawurlencode($companyName);

        $qrCode = new QrCode(
            data: $otpauth,
            errorCorrectionLevel: new ErrorCorrectionLevelMedium(),
            size: 200,
            margin: 10
        );

        return (new PngWriter())->write($qrCode)->getDataUri();
    }

    /**
     * Verify the code
     */
    public function verifyCode(string $secret, string $code, int $window = 1): bool
    {
        $timestamp = time();
        for ($i = -$window; $i <= $window; $i++) {
            if ($this->getCode($secret, $timestamp + ($i * 30)) === $code) {
                return true;
            }
        }
        return false;
    }

    private function getCode(string $secret, int $timestamp): string
    {
        $timeSlice = floor($timestamp / 30);
        $timeSlice = pack("N", $timeSlice);
        $timeSlice = str_pad($timeSlice, 8, chr(0), STR_PAD_LEFT);

        $secretKey = $this->base32Decode($secret);

        $hash = hash_hmac("sha1", $timeSlice, $secretKey, true);
        $offset = ord(substr($hash, -1)) & 0x0F;

        $value = (
            ((ord(substr($hash, $offset + 0)) & 0x7F) << 24) |
            ((ord(substr($hash, $offset + 1)) & 0xFF) << 16) |
            ((ord(substr($hash, $offset + 2)) & 0xFF) << 8) |
            (ord(substr($hash, $offset + 3)) & 0xFF)
        );

        return str_pad((string)($value % 1000000), 6, "0", STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        if (empty($secret)) return '';

        $base32chars = self::VALID_CHARACTERS;
        $base32charsFlipped = array_flip(str_split($base32chars));

        $paddingCharCount = substr_count($secret, '=');
        $allowedValues = [6, 4, 3, 1, 0];
        if (!in_array($paddingCharCount, $allowedValues)) return '';

        for ($i = 0; $i < 4; $i++) {
            if (
                $paddingCharCount == $allowedValues[$i] &&
                substr($secret, - ($allowedValues[$i])) != str_repeat('=', $allowedValues[$i])
            ) return '';
        }

        $secret = str_replace('=', '', $secret);
        $secret = str_split($secret);
        $binaryString = "";

        foreach ($secret as $char) {
            if (!isset($base32charsFlipped[$char])) return '';
            $binaryString .= str_pad(base_convert((string) $base32charsFlipped[$char], 10, 2), 5, '0', STR_PAD_LEFT);
        }

        $binaryString = str_split($binaryString, 8);
        $result = "";

        foreach ($binaryString as $bin) {
            $result .= (($bin !== "") ? chr(bindec($bin)) : "");
        }

        return $result;
    }
}
