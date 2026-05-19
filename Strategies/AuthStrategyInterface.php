<?php

namespace App\Services\Strategies;

/**
 * Interface para estratégias de autenticação OAuth2
 */
interface AuthStrategyInterface
{
    /**
     * Obtém o código de autorização
     * 
     * @param array $credentials Credenciais de acesso
     * @return string Código de autorização
     * @throws \Exception Em caso de erro
     */
    public function getAuthorizationCode(array $credentials): string;

    /**
     * Obtém o access token usando o código de autorização
     * 
     * @param string $code Código de autorização
     * @param array $credentials Credenciais de acesso
     * @return array Dados do token (access_token, refresh_token, expires_in, etc.)
     * @throws \Exception Em caso de erro
     */
    public function getTokenByCode(string $code, array $credentials): array;

    /**
     * Renova o access token usando o refresh token
     * 
     * @param string $refreshToken Refresh token
     * @param array $credentials Credenciais de acesso
     * @return array Dados do novo token
     * @throws \Exception Em caso de erro
     */
    public function refreshToken(string $refreshToken, array $credentials): array;
}
