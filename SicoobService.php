<?php

namespace App\Services;

use Carbon\Carbon;

class SicoobService
{
  private $sicoobRootPath = "https://api.sisbr.com.br/";
  private $basicToken = "";
  private $callbackURI = "";
  private $clientID = "";
  private $versaoHash = "3";
  private $contaCorrente = "";
  private $cooperativa = "";
  private $chaveAcesso = "";
  private $clientSecret = "";
  private $numeroContrato = '';
  private $password = "";
  function __construct()
  {
  }
  function consultarBoleto($config)
  {
    $modalidade = isset($config['modalidade']) ? $config['modalidade'] : 1;
    $numeroContrato = isset($config['numeroContrato']) ? $config['numeroContrato'] : $this->numeroContrato;
    $scope = 'cobranca_boletos_consultar';
    $accessToken = $this->getAccessToken($scope);
    $nossoNumero = $config['nossoNumero'];
    $endpoint = "cooperado/cobranca-bancaria/v1/boletos?numeroContrato=$numeroContrato&modalidade=$modalidade&nossoNumero=$nossoNumero";
    $headers = [
      "Authorization: Bearer $accessToken",
      "Content-Type: application/json",
      "client_id: $this->clientID"
    ];
    return json_decode($this->makeRequest($endpoint, compact('headers')), true);
  }
  function generateAuthorizationCode($scope)
  {
    $scope = 'cobranca_boletos_consultar%20cobranca_boletos_pagador%20cobranca_boletos_incluir';
    $endpoint = "auth/oauth2/authorize?password=$this->password&response_type=code&chaveAcesso=$this->chaveAcesso&cooperativa=$this->cooperativa&contaCorrente=$this->contaCorrente&redirect_uri=$this->callbackURI&client_id=$this->clientID&versaoHash=$this->versaoHash&scope=$scope";
    $reponse = json_decode($this->makeRequest($endpoint, ["postData" => "grant_type=authorization_code&code=code&redirect_uri=$this->callbackURI"]), true)['code'];
    return $response;
  }
  function generateAccessTokenObject($scope, $forceRefreshToken = false)
  {
    $ultimoToken = \DB::table('tokens')
      ->select('*')
      ->where('owner', '=', 'sicoob')
      ->whereNotNull('access_token')
      ->whereNotNull('refresh_token')
      ->orderBy('id', 'desc')
      ->first();
    $vencimentoDoToken = \Carbon\Carbon::parse($ultimoToken->created_at)->addSeconds($ultimoToken->expires_in);
    
    if (\Carbon\Carbon::now()->lessThan($vencimentoDoToken) && !$forceRefreshToken) {
      return (array)$ultimoToken;
    } else {
      $refreshToken = $ultimoToken->refresh_token;
      $endpoint = "auth/token?grant_type=refresh_token&refresh_token=$refreshToken&client_id=$this->clientID&client_secret=$this->clientSecret";
      $headers = [
        "Content-Type" => "application/x-www-form-urlencoded",
        "Authorization" => "Basic $this->basicToken",
      ];
      $requestType = 'POST';
      $accessToken = json_decode($this->makeRequest($endpoint, compact('requestType', 'headers')), true);

      if(array_key_exists('access_token', $accessToken) && array_key_exists('refresh_token', $accessToken) && array_key_exists('scope', $accessToken)){
        \DB::table('tokens')->insert(
          [
            'access_token' => $accessToken['access_token'],
            'refresh_token' => $accessToken['refresh_token'],
            'scope' => $accessToken['scope'],
            'type' => $accessToken['token_type'],
            'expires_in' => $accessToken['expires_in'],
            'owner' => 'sicoob',
            'created_at' => \Carbon\Carbon::now()
          ]
        );

      }
      return $accessToken;
    }
  }
  function getAccessToken($scope)
  {
    $accessTokenObject = $this->generateAccessTokenObject($scope);
    try {
      $accessToken = $accessTokenObject['access_token'];
    } catch (\Exception $error) {
      throw new \Exception('Erro ao gerar ACCESS_TOKEN; sua senha do banco pode estar bloqueada, entre em contato com a administração do banco SICOOB');
    }
    return $accessToken;
  }
  function listarBoletosPorPagador($config)
  {
    $modalidade = isset($config['modalidade']) ? $config['modalidade'] : 1;
    $numeroContrato = isset($config['numeroContrato']) ? $config['numeroContrato'] : $this->numeroContrato;
    $scope = 'cobranca_boletos_pagador';
    $accessToken = $this->getAccessToken($scope);
    $cpf = $config['cpf'];
    $endpoint = "cooperado/cobranca-bancaria/v1/boletos/pagadores/$cpf?numeroContrato=$numeroContrato&modalidade=$modalidade&dataInicio=2020-10-30&dataFim=2021-12-30";
    $headers = [
      "Authorization: Bearer $accessToken",
      "Content-Type: application/x-www-form-urlencoded",
      "client_id: $this->clientID"
    ];
    return $this->makeRequest($endpoint, compact('headers'));
  }
  function incluirBoleto($config)
  {
    $modalidade = isset($config['modalidade']) ? $config['modalidade'] : 1;
    $numeroContrato = isset($config['numeroContrato']) ? $config['numeroContrato'] : $this->numeroContrato;
    $scope = 'cobranca_boletos_incluir';
    $accessToken = $this->getAccessToken($scope);
    $cpf = $config['cpf_cliente'];
    $endpoint = "cooperado/cobranca-bancaria/v1/boletos";
    $headers = [
      "Authorization: Bearer $accessToken",
      "Content-Type: application/json",
      "client_id: $this->clientID",
      "scope: cobranca_boletos_incluir"
    ];
    $dataEmissao = Carbon::now()->toDateTimeString();
    $nossoNumero = getNossoNumeroCompleto(["numeroDoCliente" => $this->numeroContrato, "nossoNumero" => $config['pedido_numero'], "cooperativa" => $this->cooperativa]);
    $mensagens = array_map('html_entity_decode', explode(' | ', $config['texto']));
    $dadosParaCriacaoDoBoleto = [
      [
        "nossoNumero" => $nossoNumero,
        "numeroContrato" => $numeroContrato,
        "modalidade" => $modalidade,
        "numeroContaCorrente" => $this->contaCorrente,
        "especieDocumento" => "DM",
        "dataEmissao" => implode("T", explode(" ", "$dataEmissao-03:00")),
        "seuNumero" => $config['pedido_numero'],
        "identificacaoBoletoEmpresa" => "Instituto Dom Bosco",
        "identificacaoEmissaoBoleto" => 2,
        "identificacaoDistribuicaoBoleto" => 2,
        "valor" => floatval($config['valor']),
        "dataVencimento" => implode("T", explode(" ", dataBrasilToSicoobPattern($config['vencimento']))),
        "tipoDesconto" => floatval($config['desconto']) > 0 ? 1 : 0,
        "numeroParcela" => 1,
        "tipoJurosMora" => 2,
        "valorJurosMora" => 0.001,
        "dataJurosMora" => getDataSomadoDias($config['vencimento'], 1),
        "tipoMulta" => 2,
        "dataMulta" => getDataSomadoDias($config['vencimento'], 1),
        "valorMulta" => 10,
        "mensagensInstrucao" => ["tipoInstrucao" => 1, "mensagens" => [""]],
        "tipoMulta" => 0,
        "pagador" => [
          "numeroCpfCnpj" => cpfLimpar($config['cpf_cliente']),
          "nome" => $config['nome_cliente'],
          "endereco" => $config['endereco_cliente'],
          "bairro" => $config['bairro_cliente'],
          "cidade" => $config['cidade_cliente'],
          "cep" => cpfLimpar($config['cep_cliente']),
          "uf" => $config['estado_cliente'],
          "email" => [
            $config['email_cliente']
          ]
        ],
        "gerarPdf" => true,
      ]
    ];
    if (floatval($config['desconto']) > 0) {
      $dadosParaCriacaoDoBoleto[0]["dataPrimeiroDesconto"] = getDataSomadoDias($config['vencimento'], floatval($config['diasdesconto1']) * (-1));
      $dadosParaCriacaoDoBoleto[0]["valorPrimeiroDesconto"] = floatval($config['desconto']);
    }
    if (floatval($config['desconto_2']) > 0) {
      $dadosParaCriacaoDoBoleto[0]["dataSegundoDesconto"] = getDataSomadoDias($config['vencimento'], floatval($config['diasdesconto2']) * (-1));
      $dadosParaCriacaoDoBoleto[0]["valorSegundoDesconto"] = floatval($config['desconto_2']);
    }
    if (floatval($config['desconto_3']) > 0) {
      $dadosParaCriacaoDoBoleto[0]["dataTerceiroDesconto"] = getDataSomadoDias($config['vencimento'], floatval($config['diasdesconto3']) * (-1));
      $dadosParaCriacaoDoBoleto[0]["valorTerceiroDesconto"] = floatval($config['desconto_3']);
    }
    return json_decode($this->makeRequest($endpoint, ['requestType' => 'POST', 'headers' => $headers, 'postData' => json_encode($dadosParaCriacaoDoBoleto)]), true);
  }
  function makeRequest($endpoint, $config = [])
  {
    $curl = curl_init();

    curl_setopt_array($curl, array(
      CURLOPT_URL => $this->sicoobRootPath . $endpoint,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => isset($config['requestType']) ? $config['requestType'] : "GET",
      CURLOPT_POSTFIELDS => isset($config["postData"]) ? $config["postData"] : "",
      CURLOPT_HTTPHEADER => isset($config['headers']) ? $config['headers'] : [],
    ));

    $response = curl_exec($curl);

    curl_close($curl);
    return $response;
  }
}
