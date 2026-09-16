<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_PersistedQuery
 * @copyright   Copyright © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace ScandiPWA\PersistedQuery\Model;

class QueryHash
{
    /** the theme calls hash() with one argument, so the seed is the default declared in Hash.ts */
    private const SEED = 1;

    private const C1 = 0xcc9e2d51;

    private const C2 = 0x1b873593;

    private const MASK = 0xffffffff;

    /**
     * hash a query the way the theme does, as an unsigned 32-bit decimal string
     * @param string $query
     * @return string
     */
    private function calculate(string $query): string
    {
        $units = $this->codeUnits($query);
        $length = count($units);
        $remainder = $length & 3;
        $tailStart = $length - $remainder;
        $h1 = self::SEED;
        $i = 0;

        while ($i < $tailStart) {
            $k1 = ($units[$i] & 0xff)
                | (($units[$i + 1] & 0xff) << 8)
                | (($units[$i + 2] & 0xff) << 16)
                | (($units[$i + 3] & 0xff) << 24);
            $i += 4;

            $k1 = $this->multiply($k1, self::C1);
            $k1 = $this->rotate($k1, 15);
            $k1 = $this->multiply($k1, self::C2);

            $h1 ^= $k1;
            $h1 = $this->rotate($h1, 13);
            $h1b = $this->multiply($h1, 5);
            $h1 = ((($h1b & 0xffff) + 0x6b64) + (((($h1b >> 16) + 0xe654) & 0xffff) << 16)) & self::MASK;
        }

        $k1 = 0;

        // the JS switch falls through, so a remainder of 3 folds in units 3, 2 and 1
        if ($remainder >= 3) {
            $k1 ^= ($units[$i + 2] & 0xff) << 16;
        }
        if ($remainder >= 2) {
            $k1 ^= ($units[$i + 1] & 0xff) << 8;
        }
        if ($remainder >= 1) {
            $k1 ^= $units[$i] & 0xff;
            $k1 = $this->multiply($k1, self::C1);
            $k1 = $this->rotate($k1, 15);
            $k1 = $this->multiply($k1, self::C2);
            $h1 ^= $k1;
        }

        $h1 ^= $length;
        $h1 &= self::MASK;

        $h1 ^= $h1 >> 16;
        $h1 = $this->multiply($h1, 0x85ebca6b);
        $h1 ^= $h1 >> 13;
        $h1 = $this->multiply($h1, 0xc2b2ae35);
        $h1 ^= $h1 >> 16;

        return (string)($h1 & self::MASK);
    }

    /**
     * whether a caller-supplied hash is the one this query text produces
     * @param string $query
     * @param string $hash
     * @return bool
     */
    public function matches(string $query, string $hash): bool
    {
        return hash_equals($this->calculate($query), $hash);
    }

    /**
     * String.charCodeAt() reads UTF-16 code units, so anything above ASCII is converted first
     * @param string $query
     * @return int[]
     */
    private function codeUnits(string $query): array
    {
        if ($query === '') {
            return [];
        }

        if (!preg_match('/[\x80-\xff]/', $query)) {
            return array_values(unpack('C*', $query));
        }

        return array_values(unpack('v*', mb_convert_encoding($query, 'UTF-16LE', 'UTF-8')));
    }

    /**
     * 32-bit multiplication in the halves JavaScript uses, so no product leaves integer range
     * @param int $value
     * @param int $factor
     * @return int
     */
    private function multiply(int $value, int $factor): int
    {
        $low = ($value & 0xffff) * $factor;
        $high = (((($value >> 16) & 0xffff) * $factor) & 0xffff) << 16;

        return ($low + $high) & self::MASK;
    }

    /**
     * 32-bit left rotation, the `(x << n) | (x >>> (32 - n))` of the original
     * @param int $value
     * @param int $bits
     * @return int
     */
    private function rotate(int $value, int $bits): int
    {
        return (($value << $bits) | ($value >> (32 - $bits))) & self::MASK;
    }
}
