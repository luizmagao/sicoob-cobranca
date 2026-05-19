<?php

namespace App\Services;

use Carbon\Carbon;

class SicoobService
{
    private $sicoobRootPath = "https://api.sisbr.com.br/";
    private $basicToken;
    private $callbackURI;
    private $clientID;
    private $versaoHash = "3";
    private $contaCorrente;
    private $cooperativa;
    private $chaveAcesso;
    private $clientSecret;
    private $numeroContrato;
    private $password;

    /**
     * Construtor da classe SicoobService
     * 
     * @param array $config Configurações do SICOOB (opcional, usa variáveis de ambiente como fallback)
     */
    public function __construct(array $config = [])
    {
        $this->basicToken = $config['basic_token'] ?? getenv('SICOOB_BASIC_TOKEN');
        $this->callbackURI = $config['callback_uri'] ?? getenv('SICOOB_CALLBACK_URI');
        $this->clientID = $config['client_id'] ?? getenv('SICOOB_CLIENT_ID');
        $this->clientSecret = $config['client_secret'] ?? getenv('SICOOB_CLIENT_SECRET');
        $this->contaCorrente = $config['conta_corrente'] ?? getenv('SICOOB_CONTA_CORRENTE');
        $this->cooperativa = $config['cooperativa'] ?? getenv('SICOOB_COOPERATIVA');
        $this->chaveAcesso = $config['chave_acesso'] ?? getenv('SICOOB_CHAVE_ACESSO');
        $this->numeroContrato = $config['numero_contrato'] ?? getenv('SICOOB_NUMERO_CONTRATO');
        $this->password = $config['password'] ?? getenv('SICOOB_PASSWORD');
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
            ['basic_token', 'callback_uri', 'client_id', 'client_secret', 
             'conta_corrente', 'chave_acesso', 'numero_contrato'],
            ['basicToken', 'callbackURI', 'clientID', 'clientSecret',
             'contaCorrente', 'chaveAcesso', 'numeroContrato'], 
            $key
        );
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
     * @param string $scope Escopo de permissões
     * @return string Código de autorização
     * @throws \Exception Em caso de erro na geração
     */
    public function generateAuthorizationCode(string $scope = ''): string
    {
        $scope = 'cobranca_boletos_consultar%20cobranca_boletos_pagador%20cobranca_boletos_incluir';
        $endpoint = "auth/oauth2/authorize?password={$this->password}&response_type=code&chaveAcesso={$this->chaveAcesso}&cooperativa={$this->cooperativa}&contaCorrente={$this->contaCorrente}&redirect_uri={$this->callbackURI}&client_id={$this->clientID}&versaoHash={$this->versaoHash}&scope=$scope";
        
        $response = json_decode($this->makeRequest($endpoint, [
            "postData" => "grant_type=authorization_code&code=code&redirect_uri={$this->callbackURI}"
        ]), true);
        
        if (!isset($response['code'])) {
            throw new \Exception('Erro ao gerar código de autorização: ' . ($response['error_description'] ?? 'Resposta inválida da API'));
        }
        
        return $response['code'];
    }

    /**
     * Gera ou renova objeto de access token
     * 
     * @param string $scope Escopo de permissões
     * @param bool $forceRefreshToken Força renovação do token
     * @param callable|null $tokenStorageCallback Callback para armazenamento de tokens (opcional)
     * @return array Objeto do token
     * @throws \Exception Em caso de erro na geração/renovação
     */
    public function generateAccessTokenObject(string $scope, bool $forceRefreshToken = false, ?callable $tokenStorageCallback = null): array
    {
        $ultimoToken = null;
        
        // Tenta obter token via callback
        if ($tokenStorageCallback) {
            $ultimoToken = $tokenStorageCallback('get');
        } elseif (class_exists('\DB')) {
            // Fallback para Laravel DB
            try {
                $ultimoToken = \DB::table('tokens')
                    ->select('*')
                    ->where('owner', '=', 'sicoob')
                    ->whereNotNull('access_token')
                    ->whereNotNull('refresh_token')
                    ->orderBy('id', 'desc')
                    ->first();
            } catch (\Exception $e) {
                // DB não disponível ou tabela não existe
            }
        }

        if (!$ultimoToken) {
            throw new \Exception('Nenhum token válido encontrado. É necessário gerar um novo código de autorização.');
        }

        $vencimentoDoToken = Carbon::parse($ultimoToken->created_at)->addSeconds($ultimoToken->expires_in);
        
        if (Carbon::now()->lessThan($vencimentoDoToken) && !$forceRefreshToken) {
            return (array)$ultimoToken;
        } else {
            $refreshToken = $ultimoToken->refresh_token;
            $endpoint = "auth/token?grant_type=refresh_token&refresh_token=$refreshToken&client_id={$this->clientID}&client_secret={$this->clientSecret}";
            $headers = [
                "Content-Type: application/x-www-form-urlencoded",
                "Authorization: Basic {$this->basicToken}",
            ];
            
            $responseData = $this->makeRequest($endpoint, ['requestType' => 'POST', 'headers' => $headers]);
            $accessToken = json_decode($responseData, true);

            if (isset($accessToken['access_token']) && isset($accessToken['refresh_token'])) {
                $tokenData = [
                    'access_token' => $accessToken['access_token'],
                    'refresh_token' => $accessToken['refresh_token'],
                    'scope' => $accessToken['scope'] ?? $scope,
                    'type' => $accessToken['token_type'] ?? 'Bearer',
                    'expires_in' => $accessToken['expires_in'] ?? 3600,
                    'owner' => 'sicoob',
                    'created_at' => Carbon::now()
                ];

                // Salva token via callback ou DB
                if ($tokenStorageCallback) {
                    $tokenStorageCallback('save', $tokenData);
                } elseif (class_exists('\DB')) {
                    try {
                        \DB::table('tokens')->insert([
                            'access_token' => $tokenData['access_token'],
                            'refresh_token' => $tokenData['refresh_token'],
                            'scope' => $tokenData['scope'],
                            'type' => $tokenData['type'],
                            'expires_in' => $tokenData['expires_in'],
                            'owner' => 'sicoob',
                            'created_at' => Carbon::now()
                        ]);
                    } catch (\Exception $e) {
                        // Falha ao salvar no DB, mas continua com o token
                    }
                }
            } else {
                throw new \Exception('Erro ao renovar token: ' . ($accessToken['error_description'] ?? 'Resposta inválida da API'));
            }
            
            return $accessToken;
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
        $curl = curl_init();
        
        $options = [
            CURLOPT_URL => $this->sicoobRootPath . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => "",
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $config['requestType'] ?? "GET",
            CURLOPT_POSTFIELDS => $config["postData"] ?? "",
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
            throw new \Exception("Erro na requisição cURL: $error (código: $errno)");
        }
        
        if ($httpCode >= 400) {
            throw new \Exception("Erro na requisição HTTP: Status $httpCode. Resposta: $response");
        }
        
        return $response;
    }

    /**
     * Normaliza headers para formato esperado pelo cURL
     * 
     * @param array $headers Headers
     * @return array Headers normalizados
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
