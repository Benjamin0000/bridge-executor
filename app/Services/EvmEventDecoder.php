<?php

namespace App\Services;

/**
 * Pure-PHP EVM log decoder for your two contract events:
 * - BridgeDeposit(string indexed nonce, address indexed from, address indexed tokenFrom, uint256 amount, address to, address tokenTo, address poolAddress, uint64 desChain)
 * - PoolAddressUpdated(address indexed oldPool, address indexed newPool)
 *
 * No external libraries required.
 */
class EvmEventDecoder
{
    private array $abis;

    public function __construct()
    {
        // ABI-like definitions we will decode against (no keccak needed)
        $this->abis = [
            [
                'name' => 'BridgeDeposit',
                'indexed' => [
                    ['name' => 'nonce', 'type' => 'string'],   // indexed string -> keccak in topic (we return hash)
                    ['name' => 'from', 'type' => 'address'],
                    ['name' => 'tokenFrom', 'type' => 'address'],
                ],
                'non_indexed' => [
                    ['name' => 'amount', 'type' => 'uint256'],
                    ['name' => 'to', 'type' => 'address'],
                    ['name' => 'tokenTo', 'type' => 'address'],
                    ['name' => 'poolAddress', 'type' => 'address'],
                    ['name' => 'desChain', 'type' => 'uint64'],
                ],
            ],
            [
                'name' => 'PoolAddressUpdated',
                'indexed' => [
                    ['name' => 'oldPool', 'type' => 'address'],
                    ['name' => 'newPool', 'type' => 'address'],
                ],
                'non_indexed' => [],
            ],
        ];
    }

    /**
     * Decode a single EVM log object (array or object) delivered by Alchemy.
     * Returns decoded event array(s) — single event or null if none matched.
     *
     * $log expected shape (alchemy):
     *  [
     *    "topics" => [...],
     *    "data" => "0x....",
     *    "account" => ["address" => "0x..."], // contract address
     *    "transaction" => ["hash" => "..."]
     *  ]
     */
    public function decodeLog($log): ?array
    {
        // normalize array/object access
        $topics = $this->get($log, 'topics', []);
        $data = $this->get($log, 'data', '0x');

        // require at least a topic0
        if (!is_array($topics) || count($topics) === 0) {
            return null;
        }

        // Try each ABI entry: check shape (topic count must be >= 1 + indexed count)
        foreach ($this->abis as $abi) {
            $expectedIndexed = count($abi['indexed']);
            // topic0 + indexed topics => total topics count must be >= expectedIndexed + 1
            if (count($topics) < 1 + $expectedIndexed) {
                continue;
            }

            // Attempt decode indexed fields
            $decodedIndexed = [];
            $ok = true;
            for ($i = 0; $i < $expectedIndexed; $i++) {
                $topicVal = $topics[$i + 1]; // topic 0 is signature
                $type = $abi['indexed'][$i]['type'];
                $name = $abi['indexed'][$i]['name'];

                if ($type === 'address') {
                    $decodedIndexed[$name] = $this->decodeTopicAddress($topicVal);
                } elseif ($type === 'string') {
                    // indexed string stores keccak hash in topic — original string is not recoverable
                    $decodedIndexed[$name] = $this->strip0x($topicVal);
                } else {
                    // fallback: return raw topic hex
                    $decodedIndexed[$name] = $this->strip0x($topicVal);
                }
            }

            // Decode non-indexed data: split into 32-byte words
            $decodedNonIndexed = [];
            $chunks = $this->splitDataWords($data);

            $nonIndexedDefs = $abi['non_indexed'];
            if (count($chunks) < count($nonIndexedDefs)) {
                // Not enough data words; skip this ABI as non-matching
                $ok = false;
            } else {
                for ($j = 0; $j < count($nonIndexedDefs); $j++) {
                    $def = $nonIndexedDefs[$j];
                    $word = $chunks[$j];
                    $decodedNonIndexed[$def['name']] = $this->decodeWordByType($word, $def['type'], $chunks, $j);
                }
            }

            if (!$ok) continue;

            // success: combine and return structured result
            $result = [
                'event' => $abi['name'],
                'contract' => $this->get($log, 'account.address') ?? null,
                'tx_hash' => $this->get($log, 'transaction.hash') ?? null,
                'topics' => $topics,
                'data_raw' => $data,
                'decoded' => array_merge($decodedIndexed, $decodedNonIndexed),
            ];

            return $result;
        }

        // none matched
        return null;
    }

    // ----------------------
    // Helpers
    // ----------------------

    private function get($arrOrObj, $key, $default = null)
    {
        if (is_array($arrOrObj)) {
            return $arrOrObj[$key] ?? $default;
        }
        if (is_object($arrOrObj)) {
            return $arrOrObj->{$key} ?? $default;
        }
        return $default;
    }

    private function strip0x(string $h): string
    {
        if (strpos($h, '0x') === 0 || strpos($h, '0X') === 0) return substr($h, 2);
        return $h;
    }

    /** split data hex into 64-char words (32 bytes) — returns array of hex strings (no 0x) */
    private function splitDataWords(string $dataHex): array
    {
        $d = $this->strip0x($dataHex);
        if ($d === '') return [];
        $parts = str_split($d, 64);
        // ensure each chunk is 64 chars (pad last if needed)
        foreach ($parts as &$p) {
            $p = str_pad($p, 64, '0', STR_PAD_LEFT);
        }
        return $parts;
    }

    /** decode an indexed topic that contains an address (right-most 20 bytes) */
    private function decodeTopicAddress(string $topicHex): ?string
    {
        $h = $this->strip0x($topicHex);
        if (strlen($h) < 40) return null;
        $addr = '0x' . substr($h, -40);
        return strtolower($addr);
    }

    /**
     * Decode a single 32-byte word by solidity type.
     * For static types (uint, int, address) we parse directly.
     *
     * For completeness we support uint256 -> decimal string.
     *
     * @param string $word 64-char hex (no 0x)
     * @param string $type
     * @param array $allChunks (for dynamic types — not used here)
     * @param int $index (position)
     * @return mixed
     */
    private function decodeWordByType(string $word, string $type, array $allChunks = [], int $index = 0)
    {
        $word = strtolower($word);
        switch (true) {
            case preg_match('/^uint(256|128|64|32|16|8)?$/', $type):
                return $this->hexToDec($word);
            case preg_match('/^int(256|128|64|32|16|8)?$/', $type):
                // interpret signed two's complement for 256-bit; we only need for small ints maybe
                return $this->hexToSignedDec($word);
            case $type === 'address':
                return '0x' . substr($word, 24);
            case $type === 'bytes32':
                return '0x' . $word;
            case $type === 'string':
                // dynamic string not expected in your non-indexed list — basic support:
                // first word is offset — compute offset into full data, read length word then bytes
                $offset = hexdec($word);
                // offset is in bytes; get substring from raw data
                // WARNING: we did not implement because your non-indexed types are static
                return null;
            default:
                // fallback return raw
                return '0x' . $word;
        }
    }

    /** Convert 32-byte hex to unsigned decimal string using BCMath if available */
    private function hexToDec(string $hex): string
    {
        $hex = preg_replace('/^0+/', '', $hex);
        if ($hex === '') return '0';
        // use BCMath if available
        if (function_exists('bcadd')) {
            $dec = '0';
            $len = strlen($hex);
            for ($i = 0; $i < $len; $i++) {
                $dec = bcmul($dec, '16');
                $dec = bcadd($dec, (string) hexdec($hex[$i]));
            }
            return $dec;
        } else {
            // fallback (may lose precision for very large numbers)
            return (string) hexdec($hex);
        }
    }

    /** Convert 2's complement 256-bit word to signed decimal string */
    private function hexToSignedDec(string $hex): string
    {
        // if highest bit set -> negative
        $firstByte = hexdec(substr($hex, 0, 2));
        $isNeg = ($firstByte & 0x80) === 0x80;
        if (!$isNeg) return $this->hexToDec($hex);

        // compute two's complement: -( ( ~x & 2^256-1 ) + 1 )
        // ~x = (2^256 - 1) - x
        $unsigned = $this->hexToDec($hex);
        // compute (2^256) as decimal string
        $two256 = '1';
        for ($i = 0; $i < 64; $i++) {
            // multiply by 16 for each hex digit -> 16^64 = 2^256
            $two256 = bcmul($two256, '16');
        }
        // negative value = unsigned - 2^256
        $val = bcsub($unsigned, $two256);
        return $val; // a negative number string
    }
}
