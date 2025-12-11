<?php

namespace App\Providers;
use Web3DecodePhp\DecodeLog;

class EvmEventDecoder
{
    private $abis = [
        [
            'name' => 'BridgeDeposit',
            'inputs' => [
                ['name' => 'nonce', 'type' => 'string', 'indexed' => true],
                ['name' => 'from', 'type' => 'address', 'indexed' => true],
                ['name' => 'tokenFrom', 'type' => 'address', 'indexed' => true],
                ['name' => 'amount', 'type' => 'uint256', 'indexed' => false],
                ['name' => 'to', 'type' => 'address', 'indexed' => false],
                ['name' => 'tokenTo', 'type' => 'address', 'indexed' => false],
                ['name' => 'poolAddress', 'type' => 'address', 'indexed' => false],
                ['name' => 'desChain', 'type' => 'uint64', 'indexed' => false],
            ],
        ],
        [
            'name' => 'PoolAddressUpdated',
            'inputs' => [
                ['name' => 'oldPool', 'type' => 'address', 'indexed' => true],
                ['name' => 'newPool', 'type' => 'address', 'indexed' => true],
            ],
        ],
    ];

    public function handle($logs)
    {
        $decoder = new DecodeLog($this->abis);

        foreach ($logs as $log) {
            $logObject = (object)[
                'address' => $log['account']['address'] ?? null,
                'topics' => $log['topics'] ?? [],
                'data' => $log['data'] ?? '',
                'blockHash' => $log['blockHash'] ?? null,
                'blockNumber' => $log['blockNumber'] ?? null,
                'transactionHash' => $log['transaction']['hash'] ?? null,
                'transactionIndex' => $log['transaction']['index'] ?? null,
                'logIndex' => $log['index'] ?? null,
            ];

            try {
                $decoded = $decoder->decode($logObject);

                if ($decoded['event'] === 'BridgeDeposit') {
                    return [
                        'event'=>'BridgeDeposit', 
                        'nonce' => $decoded['nonce'],
                        'from' => $decoded['from'],
                        'token_from' => $decoded['tokenFrom'],
                        'amount' => $decoded['amount'],
                        'to' => $decoded['to'],
                        'token_to' => $decoded['tokenTo'],
                        'pool_address' => $decoded['poolAddress'],
                        'des_chain' => $decoded['desChain'],
                        'tx_hash' => $decoded['transactionHash'] ?? null,
                        'block_number' => $decoded['blockNumber'] ?? null,
                    ];
                }

                if ($decoded['event'] === 'PoolAddressUpdated') {
                    return [
                        'event'=>'PoolAddressUpdated', 
                        'old_pool' => $decoded['oldPool'],
                        'new_pool' => $decoded['newPool'],
                        'tx_hash' => $decoded['transactionHash'] ?? null,
                        'block_number' => $decoded['blockNumber'] ?? null,
                    ];
                }

            } catch (\Exception $e) {
                \Log::error('Failed to decode log: ' . $e->getMessage());
            }
        }

        return null; 
    }
}
