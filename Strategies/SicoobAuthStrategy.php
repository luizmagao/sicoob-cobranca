<?php

namespace App\Services\Strategies;

use Exception;

/**
 * Estratégia de autenticação OAuth2 para o SICOOB
 * 
 * Implementa o fluxo de autorização com código (Authorization Code Flow)
 */
class SicoobAuthStrategy implements AuthStrategyInterface
{
    private string $baseUrl = 'https://api.sisbr.com.br/';
    private string $basicToken;
    private string $clientID;
    private string $clientSecret;
    private string $callbackURI;
    private string $password;
    private string $chaveAcesso;
    private string $cooperativa;
    private string $contaCorrente;
    private string $versaoHash = '3';

    /**
     * Construtor da estratégia de autenticação
     * 
     * @param array $config Configurações de autenticação
     */
    public function __construct(array $config)
    {
        $this->basicToken = $config['basic_token'] ?? '';
        $this->clientID = $config['client_id'] ?? '';
        $this->clientSecret = $config['client_secret'] ?? '';
        $this->callbackURI = $config['callback_uri'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->chaveAcesso = $config['chave_acesso'] ?? '';
        $this->cooperativa = $config['cooperativa'] ?? '';
        $this->contaCorrente = $config['conta_corrente'] ?? '';
        
        $this->validateRequiredConfig();
    }

    /**
     * Valida se todas as configurações necessárias estão presentes
     * 
     * @throws Exception Se alguma configuração obrigatória estiver faltando
     */
    private function validateRequiredConfig(): void
    {
        $required = ['basic_token', 'client_id', 'client_secret', 'callback_uri'];
        
        foreach ($required as $key) {
            $property = $this->normalizePropertyName($key);
            if (empty($this->$property)) {
                throw new Exception("Configuração obrigatória '$key' não fornecida.");
            }
        }
    }

    /**
     * Normaliza nomes de propriedades de snake_case para camelCase
     */
    private function normalizePropertyName(string $key): string
    {
        return str_replace(
            ['basic_token', 'callback_uri', 'client_id', 'client_secret', 
             'conta_corrente', 'chave_acesso'],
            ['basicToken', 'callbackURI', 'clientID', 'clientSecret',
             'contaCorrente', 'chaveAcesso'], 
            $key
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthorizationCode(array $credentials = []): string
    {
        // Usa credenciais do construtor ou as fornecidas
        $password = $credentials['password'] ?? $this->password;
        $chaveAcesso = $credentials['chave_acesso'] ?? $this->chaveAcesso;
        $cooperativa = $credentials['cooperativa'] ?? $this->cooperativa;
        $contaCorrente = $credentials['conta_corrente'] ?? $this->contaCorrente;
        
        $scope = urlencode('cobranca_boletos_consultar cobranca_boletos_pagador cobranca_boletos_incluir');
        
        $endpoint = sprintf(
            'auth/oauth2/authorize?password=%s&response_type=code&chaveAcesso=%s&cooperativa=%s&contaCorrente=%s&redirect_uri=%s&client_id=%s&versaoHash=%s&scope=%s',
            urlencode($password),
            urlencode($chaveAcesso),
            urlencode($cooperativa),
            urlencode($contaCorrente),
            urlencode($this->callbackURI),
            urlencode($this->clientID),
            $this->versaoHash,
            $scope
        );

        $response = $this->makeRequest($endpoint, [
            'requestType' => 'GET'
        ]);

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Erro ao decodificar resposta da API: ' . json_last_error_msg());
        }

        if (!isset($result['code'])) {
            throw new Exception('Erro ao gerar código de autorização: ' . ($result['error_description'] ?? 'Resposta inválida da API'));
        }

        return $result['code'];
    }

    /**
     * {@inheritdoc}
     */
    public function getTokenByCode(string $code, array $credentials = []): array
    {
        $clientID = $credentials['client_id'] ?? $this->clientID;
        $clientSecret = $credentials['client_secret'] ?? $this->clientSecret;
        $redirectURI = $credentials['callback_uri'] ?? $this->callbackURI;

        $endpoint = 'auth/token';
        $postData = http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectURI,
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        ]);

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $this->basicToken
        ];

        $response = $this->makeRequest($endpoint, [
            'requestType' => 'POST',
            'postData' => $postData,
            'headers' => $headers
        ]);

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Erro ao decodificar resposta da API: ' . json_last_error_msg());
        }

        if (!isset($result['access_token']) || !isset($result['refresh_token'])) {
            throw new Exception('Erro ao obter token: ' . ($result['error_description'] ?? 'Resposta inválida da API'));
        }

        return [
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'scope' => $result['scope'] ?? '',
            'token_type' => $result['token_type'] ?? 'Bearer',
            'expires_in' => $result['expires_in'] ?? 3600,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function refreshToken(string $refreshToken, array $credentials = []): array
    {
        $clientID = $credentials['client_id'] ?? $this->clientID;
        $clientSecret = $credentials['client_secret'] ?? $this->clientSecret;

        $endpoint = 'auth/token';
        $postData = http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientID,
            'client_secret' => $clientSecret
        ]);

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $this->basicToken
        ];

        $response = $this->makeRequest($endpoint, [
            'requestType' => 'POST',
            'postData' => $postData,
            'headers' => $headers
        ]);

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Erro ao decodificar resposta da API: ' . json_last_error_msg());
        }

        if (!isset($result['access_token']) || !isset($result['refresh_token'])) {
            throw new Exception('Erro ao renovar token: ' . ($result['error_description'] ?? 'Resposta inválida da API'));
        }

        return [
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'scope' => $result['scope'] ?? '',
            'token_type' => $result['token_type'] ?? 'Bearer',
            'expires_in' => $result['expires_in'] ?? 3600,
            'created_at' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Realiza requisição HTTP via cURL
     * 
     * @param string $endpoint Endpoint da API
     * @param array $config Configurações da requisição
     * @return string Resposta da requisição
     * @throws Exception Em caso de erro no cURL
     */
    private function makeRequest(string $endpoint, array $config = []): string
    {
        $curl = curl_init();

        $options = [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $config['requestType'] ?? 'GET',
            CURLOPT_POSTFIELDS => $config['postData'] ?? '',
            CURLOPT_HTTPHEADER => $this->normalizeHeaders($config['headers'] ?? []),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        $errno = curl_errno($curl);

        curl_close($curl);

        if ($errno !== CURLE_OK) {
            throw new Exception("Erro na requisição cURL: $error (código: $errno)");
        }

        if ($httpCode >= 400) {
            throw new Exception("Erro na requisição HTTP: Status $httpCode. Resposta: $response");
        }

        return $response;
    }

    /**
     * Normaliza headers para formato esperado pelo cURL
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            if (is_numeric($key)) {
                $normalized[] = $value;
            } else {
                $normalized[] = "$key: $value";
            }
        }
        return $normalized;
    }
}
