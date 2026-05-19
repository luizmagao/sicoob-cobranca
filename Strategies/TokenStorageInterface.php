<?php

namespace App\Services\Strategies;

use Exception;

/**
 * Interface para estratégias de armazenamento de tokens
 */
interface TokenStorageInterface
{
    /**
     * Obtém o último token armazenado
     * 
     * @return array|null Dados do token ou null se não encontrado
     */
    public function getToken(): ?array;

    /**
     * Armazena um novo token
     * 
     * @param array $tokenData Dados do token
     * @return bool Sucesso da operação
     */
    public function saveToken(array $tokenData): bool;

    /**
     * Verifica se o token está válido (não expirado)
     * 
     * @param array $tokenData Dados do token
     * @return bool True se válido, false se expirado
     */
    public function isTokenValid(array $tokenData): bool;
}
