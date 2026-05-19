<?php

namespace App\Services;

use App\Services\Strategies\AuthStrategyInterface;
use App\Services\Strategies\TokenStorageInterface;
use App\Services\Strategies\SicoobAuthStrategy;
use App\Services\Strategies\DatabaseTokenStorage;
use Carbon\Carbon;

class SicoobService
{
    private AuthStrategyInterface $authStrategy;
    private TokenStorageInterface $tokenStorage;
    private string $contaCorrente;
    private string $cooperativa;
    private string $chaveAcesso;
    private string $numeroContrato;
    private string $password;

    /**
     * Construtor da classe SicoobService
     * 
     * @param array $config Configurações do SICOOB (opcional, usa variáveis de ambiente como fallback)
     * @param AuthStrategyInterface|null $authStrategy Estratégia de autenticação (opcional)
     * @param TokenStorageInterface|null $tokenStorage Estratégia de armazenamento de tokens (opcional)
     */
    public function __construct(
        array $config = [],
        ?AuthStrategyInterface $authStrategy = null,
        ?TokenStorageInterface $tokenStorage = null
    ) {
        // Configurações básicas da conta
        $this->contaCorrente = $config['conta_corrente'] ?? getenv('SICOOB_CONTA_CORRENTE');
        $this->cooperativa = $config['cooperativa'] ?? getenv('SICOOB_COOPERATIVA');
        $this->chaveAcesso = $config['chave_acesso'] ?? getenv('SICOOB_CHAVE_ACESSO');
        $this->numeroContrato = $config['numero_contrato'] ?? getenv('SICOOB_NUMERO_CONTRATO');
        $this->password = $config['password'] ?? getenv('SICOOB_PASSWORD');

        // Injeta estratégia de autenticação ou cria padrão
        $this->authStrategy = $authStrategy ?? new SicoobAuthStrategy([
            'basic_token' => $config['basic_token'] ?? getenv('SICOOB_BASIC_TOKEN'),
            'client_id' => $config['client_id'] ?? getenv('SICOOB_CLIENT_ID'),
            'client_secret' => $config['client_secret'] ?? getenv('SICOOB_CLIENT_SECRET'),
            'callback_uri' => $config['callback_uri'] ?? getenv('SICOOB_CALLBACK_URI'),
            'password' => $this->password,
            'chave_acesso' => $this->chaveAcesso,
            'cooperativa' => $this->cooperativa,
            'conta_corrente' => $this->contaCorrente
        ]);

        // Injeta estratégia de armazenamento ou cria padrão (Database)
        $this->tokenStorage = $tokenStorage ?? new DatabaseTokenStorage();
    }

    /**
     * Define configurações dinamicamente
     * 
     * @param array $config Array com as configurações
     */
    public function setConfig(array $config): void
    {
        foreach ($config as $key => $value) {
            $property = $this->normalizePropertyName($key);
            if (property_exists($this, $property)) {
                $this->$property = $value;
            }
        }
    }

    /**
     * Normaliza nomes de propriedades de snake_case para camelCase
     * 
     * @param string $key Nome da propriedade
     * @return string Nome normalizado
     */
    private function normalizePropertyName(string $key): string
    {
        return str_replace(
            ['conta_corrente', 'chave_acesso', 'numero_contrato'],
            ['contaCorrente', 'chaveAcesso', 'numeroContrato'], 
            $key
        );
    }

    /**
     * Obtém a estratégia de autenticação atual
     * 
     * @return AuthStrategyInterface
     */
    public function getAuthStrategy(): AuthStrategyInterface
    {
        return $this->authStrategy;
    }

    /**
     * Define uma nova estratégia de autenticação
     * 
     * @param AuthStrategyInterface $authStrategy Nova estratégia
     */
    public function setAuthStrategy(AuthStrategyInterface $authStrategy): void
    {
        $this->authStrategy = $authStrategy;
    }

    /**
     * Obtém a estratégia de armazenamento de tokens atual
     * 
     * @return TokenStorageInterface
     */
    public function getTokenStorage(): TokenStorageInterface
    {
        return $this->tokenStorage;
    }

    /**
     * Define uma nova estratégia de armazenamento de tokens
     * 
     * @param TokenStorageInterface $tokenStorage Nova estratégia
     */
    public function setTokenStorage(TokenStorageInterface $tokenStorage): void
    {
        $this->tokenStorage = $tokenStorage;
    }

    /**
     * Consulta um boleto pelo nosso número
     * 
     * @param array $config Configurações da consulta
     * @return array Dados do boleto
     * @throws \Exception Em caso de erro na requisição
     */
    public function consultarBoleto(array $config): array
    {
        $modalidade = $config['modalidade'] ?? 1;
        $numeroContrato = $config['numeroContrato'] ?? $this->numeroContrato;
        $scope = 'cobranca_boletos_consultar';
        $accessToken = $this->getAccessToken($scope);
        $nossoNumero = $config['nossoNumero'];
        
        $endpoint = "cooperado/cobranca-bancaria/v1/boletos?numeroContrato=$numeroContrato&modalidade=$modalidade&nossoNumero=$nossoNumero";
        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json",
            "client_id: $this->clientID"
        ];
        
        $response = $this->makeRequest($endpoint, compact('headers'));
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Erro ao decodificar resposta da API: ' . json_last_error_msg());
        }
        
        return $result ?? [];
    }

    /**
     * Gera código de autorização OAuth2
     * 
     * @param array $credentials Credenciais opcionais (sobrescreve as do construtor)
     * @return string Código de autorização
     * @throws \Exception Em caso de erro na geração
     */
    public function generateAuthorizationCode(array $credentials = []): string
    {
        return $this->authStrategy->getAuthorizationCode($credentials);
    }

    /**
     * Gera ou renova objeto de access token
     * 
     * @param string $scope Escopo de permissões
     * @param bool $forceRefreshToken Força renovação do token
     * @return array Objeto do token
     * @throws \Exception Em caso de erro na geração/renovação
     */
    public function generateAccessTokenObject(string $scope, bool $forceRefreshToken = false): array
    {
        $ultimoToken = $this->tokenStorage->getToken();

        if (!$ultimoToken) {
            throw new \Exception('Nenhum token válido encontrado. É necessário gerar um novo código de autorização.');
        }

        if ($this->tokenStorage->isTokenValid($ultimoToken) && !$forceRefreshToken) {
            return $ultimoToken;
        } else {
            // Renova o token usando a estratégia de autenticação
            $newToken = $this->authStrategy->refreshToken($ultimoToken['refresh_token']);
            
            // Salva o novo token
            $this->tokenStorage->saveToken($newToken);
            
            return $newToken;
        }
    }

    /**
     * Obtém access token para uso nas requisições
     * 
     * @param string $scope Escopo de permissões
     * @return string Access token
     * @throws \Exception Em caso de erro
     */
    public function getAccessToken(string $scope): string
    {
        try {
            $accessTokenObject = $this->generateAccessTokenObject($scope);
            return $accessTokenObject['access_token'];
        } catch (\Exception $error) {
            throw new \Exception('Erro ao gerar ACCESS_TOKEN; sua senha do banco pode estar bloqueada, entre em contato com a administração do banco SICOOB. Detalhes: ' . $error->getMessage());
        }
    }

    /**
     * Lista boletos por pagador (CPF/CNPJ)
     * 
     * @param array $config Configurações da consulta
     * @return array Lista de boletos
     * @throws \Exception Em caso de erro
     */
    public function listarBoletosPorPagador(array $config): array
    {
        $modalidade = $config['modalidade'] ?? 1;
        $numeroContrato = $config['numeroContrato'] ?? $this->numeroContrato;
        $scope = 'cobranca_boletos_pagador';
        $accessToken = $this->getAccessToken($scope);
        $cpf = $config['cpf'];
        $dataInicio = $config['data_inicio'] ?? '2020-10-30';
        $dataFim = $config['data_fim'] ?? date('Y-m-d');
        
        $endpoint = "cooperado/cobranca-bancaria/v1/boletos/pagadores/$cpf?numeroContrato=$numeroContrato&modalidade=$modalidade&dataInicio=$dataInicio&dataFim=$dataFim";
        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json",
            "client_id: $this->clientID"
        ];
        
        $response = $this->makeRequest($endpoint, compact('headers'));
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Erro ao decodificar resposta da API: ' . json_last_error_msg());
        }
        
        return $result ?? [];
    }

    /**
     * Inclui novo boleto
     * 
     * @param array $config Dados do boleto
     * @return array Resultado da inclusão
     * @throws \Exception Em caso de erro
     */
    public function incluirBoleto(array $config): array
    {
        $modalidade = $config['modalidade'] ?? 1;
        $numeroContrato = $config['numeroContrato'] ?? $this->numeroContrato;
        $scope = 'cobranca_boletos_incluir';
        $accessToken = $this->getAccessToken($scope);
        
        $endpoint = "cooperado/cobranca-bancaria/v1/boletos";
        $headers = [
            "Authorization: Bearer $accessToken",
            "Content-Type: application/json",
            "client_id: $this->clientID",
            "scope: cobranca_boletos_incluir"
        ];
        
        $dataEmissao = Carbon::now()->toDateTimeString();
        
        // Gera nosso número (depende de função externa - deve ser injetada ou implementada)
        $nossoNumero = $this->callHelperFunction('getNossoNumeroCompleto', [
            "numeroDoCliente" => $this->numeroContrato, 
            "nossoNumero" => $config['pedido_numero'], 
            "cooperativa" => $this->cooperativa
        ]);
        
        $mensagens = array_map('html_entity_decode', explode(' | ', $config['texto'] ?? ''));
        
        $dadosParaCriacaoDoBoleto = [
            [
                "nossoNumero" => $nossoNumero,
                "numeroContrato" => $numeroContrato,
                "modalidade" => $modalidade,
                "numeroContaCorrente" => $this->contaCorrente,
                "especieDocumento" => "DM",
                "dataEmissao" => implode("T", explode(" ", "$dataEmissao-03:00")),
                "seuNumero" => $config['pedido_numero'],
                "identificacaoBoletoEmpresa" => $config['empresa'] ?? "Instituto Dom Bosco",
                "identificacaoEmissaoBoleto" => 2,
                "identificacaoDistribuicaoBoleto" => 2,
                "valor" => floatval($config['valor']),
                "dataVencimento" => implode("T", explode(" ", $this->callHelperFunction('dataBrasilToSicoobPattern', [$config['vencimento']]))),
                "tipoDesconto" => floatval($config['desconto']) > 0 ? 1 : 0,
                "numeroParcela" => 1,
                "tipoJurosMora" => 2,
                "valorJurosMora" => 0.001,
                "dataJurosMora" => $this->callHelperFunction('getDataSomadoDias', [$config['vencimento'], 1]),
                "tipoMulta" => 0,
                "dataMulta" => $this->callHelperFunction('getDataSomadoDias', [$config['vencimento'], 1]),
                "valorMulta" => 10,
                "mensagensInstrucao" => ["tipoInstrucao" => 1, "mensagens" => [""]],
                "pagador" => [
                    "numeroCpfCnpj" => $this->callHelperFunction('cpfLimpar', [$config['cpf_cliente']]),
                    "nome" => $config['nome_cliente'],
                    "endereco" => $config['endereco_cliente'],
                    "bairro" => $config['bairro_cliente'],
                    "cidade" => $config['cidade_cliente'],
                    "cep" => $this->callHelperFunction('cpfLimpar', [$config['cep_cliente']]),
                    "uf" => $config['estado_cliente'],
                    "email" => [$config['email_cliente']]
                ],
                "gerarPdf" => true,
            ]
        ];
        
        if (floatval($config['desconto']) > 0) {
            $dadosParaCriacaoDoBoleto[0]["dataPrimeiroDesconto"] = $this->callHelperFunction('getDataSomadoDias', [$config['vencimento'], floatval($config['diasdesconto1']) * (-1)]);
            $dadosParaCriacaoDoBoleto[0]["valorPrimeiroDesconto"] = floatval($config['desconto']);
        }
        
        if (floatval($config['desconto_2']) > 0) {
            $dadosParaCriacaoDoBoleto[0]["dataSegundoDesconto"] = $this->callHelperFunction('getDataSomadoDias', [$config['vencimento'], floatval($config['diasdesconto2']) * (-1)]);
            $dadosParaCriacaoDoBoleto[0]["valorSegundoDesconto"] = floatval($config['desconto_2']);
        }
        
        if (floatval($config['desconto_3']) > 0) {
            $dadosParaCriacaoDoBoleto[0]["dataTerceiroDesconto"] = $this->callHelperFunction('getDataSomadoDias', [$config['vencimento'], floatval($config['diasdesconto3']) * (-1)]);
            $dadosParaCriacaoDoBoleto[0]["valorTerceiroDesconto"] = floatval($config['desconto_3']);
        }
        
        $response = $this->makeRequest($endpoint, [
            'requestType' => 'POST', 
            'headers' => $headers, 
            'postData' => json_encode($dadosParaCriacaoDoBoleto)
        ]);
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Erro ao decodificar resposta da API: ' . json_last_error_msg());
        }
        
        return $result ?? [];
    }

    /**
     * Chama funções helper externas (para manter compatibilidade)
     * 
     * @param string $functionName Nome da função
     * @param array $params Parâmetros
     * @return mixed Resultado da função
     * @throws \Exception Se a função não existir
     */
    private function callHelperFunction(string $functionName, array $params)
    {
        if (function_exists($functionName)) {
            return call_user_func_array($functionName, $params);
        }
        
        // Implementações fallback básicas
        switch ($functionName) {
            case 'cpfLimpar':
                return preg_replace('/[^0-9]/', '', $params[0]);
            case 'getDataSomadoDias':
                $date = Carbon::parse($params[0]);
                return $date->addDays($params[1])->format('Y-m-d');
            case 'dataBrasilToSicoobPattern':
                // Assume formato DD/MM/YYYY e converte para YYYY-MM-DD
                $parts = explode('/', $params[0]);
                if (count($parts) === 3) {
                    return "{$parts[2]}-{$parts[1]}-{$parts[0]}";
                }
                return $params[0];
            case 'getNossoNumeroCompleto':
                // Implementação básica - deve ser customizada conforme necessidade
                return str_pad($params['cooperativa'], 4, '0', STR_PAD_LEFT) . 
                       str_pad($params['numeroDoCliente'], 5, '0', STR_PAD_LEFT) . 
                       str_pad($params['nossoNumero'], 7, '0', STR_PAD_LEFT);
            default:
                throw new \Exception("Função helper '$functionName' não encontrada. Implemente-a ou injete-a via callback.");
        }
    }

    /**
     * Realiza requisição HTTP via cURL
     * 
     * @param string $endpoint Endpoint da API
     * @param array $config Configurações da requisição
     * @return string Resposta da requisição
     * @throws \Exception Em caso de erro no cURL
     */
    public function makeRequest(string $endpoint, array $config = []): string
    {
        // Delega a requisição para a estratégia de autenticação
        // Nota: Este método é mantido para compatibilidade, mas o ideal é usar diretamente a estratégia
        $reflection = new \ReflectionClass($this->authStrategy);
        $method = $reflection->getMethod('makeRequest');
        $method->setAccessible(true);
        
        // Adiciona o endpoint completo usando a URL base da estratégia
        return $method->invoke($this->authStrategy, $endpoint, $config);
    }

    /**
     * Define a URL base da API (para compatibilidade)
     * 
     * @param string $url URL base
     */
    public function setBaseUrl(string $url): void
    {
        $reflection = new \ReflectionClass($this->authStrategy);
        $property = $reflection->getProperty('baseUrl');
        $property->setAccessible(true);
        $property->setValue($this->authStrategy, $url);
    }
}
