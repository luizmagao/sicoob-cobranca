<?php

namespace App\Services\Strategies;

use Carbon\Carbon;
use Exception;

/**
 * Estratégia de armazenamento de tokens usando banco de dados Laravel
 */
class DatabaseTokenStorage implements TokenStorageInterface
{
    private string $table = 'tokens';
    private string $owner = 'sicoob';

    /**
     * Construtor da estratégia de armazenamento
     * 
     * @param array $config Configurações opcionais
     */
    public function __construct(array $config = [])
    {
        $this->table = $config['table'] ?? $this->table;
        $this->owner = $config['owner'] ?? $this->owner;
    }

    /**
     * {@inheritdoc}
     */
    public function getToken(): ?array
    {
        if (!class_exists('\DB')) {
            return null;
        }

        try {
            $token = \DB::table($this->table)
                ->select('*')
                ->where('owner', '=', $this->owner)
                ->whereNotNull('access_token')
                ->whereNotNull('refresh_token')
                ->orderBy('id', 'desc')
                ->first();

            return $token ? (array)$token : null;
        } catch (Exception $e) {
            // Tabela não existe ou erro no DB
            return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function saveToken(array $tokenData): bool
    {
        if (!class_exists('\DB')) {
            return false;
        }

        try {
            return \DB::table($this->table)->insert([
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'],
                'scope' => $tokenData['scope'] ?? '',
                'type' => $tokenData['token_type'] ?? 'Bearer',
                'expires_in' => $tokenData['expires_in'] ?? 3600,
                'owner' => $this->owner,
                'created_at' => $tokenData['created_at'] ?? Carbon::now()
            ]);
        } catch (Exception $e) {
            return false;
        }
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
