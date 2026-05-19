<?php

namespace App\Services\Strategies;

use Carbon\Carbon;
use Exception;

/**
 * Estratégia de armazenamento de tokens em memória (para testes ou uso temporário)
 */
class MemoryTokenStorage implements TokenStorageInterface
{
    private ?array $token = null;

    /**
     * {@inheritdoc}
     */
    public function getToken(): ?array
    {
        return $this->token;
    }

    /**
     * {@inheritdoc}
     */
    public function saveToken(array $tokenData): bool
    {
        $this->token = $tokenData;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isTokenValid(array $tokenData): bool
    {
        if (!isset($tokenData['created_at']) || !isset($tokenData['expires_in'])) {
            return false;
        }

        try {
            $vencimentoDoToken = Carbon::parse($tokenData['created_at'])
                ->addSeconds($tokenData['expires_in']);
            
            return Carbon::now()->lessThan($vencimentoDoToken);
        } catch (Exception $e) {
            return false;
        }
    }
}
