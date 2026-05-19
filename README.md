# SICOOB - Cobrança

Classe de serviços para acesso as cobranças do SICOOB

## 📋 Índice

- [Instalação](#instalação)
- [Configuração](#configuração)
- [Uso](#uso)
- [Adicionando Suporte a Outros Bancos](#adicionando-suporte-a-outros-bancos)
- [Contribuição](#contribuição)

---

## 🚀 Instalação

Certifique-se de ter o PHP 7.4+ instalado em seu projeto.

1. Clone este repositório ou copie os arquivos para seu projeto
2. Copie o arquivo `.env.example` para `.env` e preencha com suas credenciais:

```bash
cp .env.example .env
```

---

## ⚙️ Configuração

Edite o arquivo `.env` com as credenciais do SICOOB:

```env
SICOOB_BASIC_TOKEN=seu_token_base64
SICOOB_CLIENT_ID=seu_client_id
SICOOB_CLIENT_SECRET=seu_client_secret
SICOOB_CONTA_CORRENTE=sua_conta_corrente
SICOOB_COOPERATIVA=sua_cooperativa
SICOOB_CHAVE_ACESSO=sua_chave_acesso
SICOOB_NUMERO_CONTRATO=seu_numero_contrato
SICOOB_PASSWORD=sua_senha
SICOOB_CALLBACK_URI=sua_callback_uri
```

**Como gerar o BASIC_TOKEN:**

No Linux/Mac:
```bash
echo -n "CLIENT_ID:CLIENT_SECRET" | base64
```

No Windows (PowerShell):
```powershell
[Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes("CLIENT_ID:CLIENT_SECRET"))
```

---

## 💻 Uso

### Inicialização Básica

```php
use App\Services\SicoobService;

// Criar instância com configurações do .env
$service = new SicoobService();

// Ou passar configurações manualmente
$config = [
    'basic_token' => 'seu_token',
    'client_id' => 'seu_client_id',
    'client_secret' => 'seu_client_secret',
    'conta_corrente' => 'sua_conta',
    'cooperativa' => 'sua_cooperativa',
    'chave_acesso' => 'sua_chave',
    'numero_contrato' => 'seu_contrato',
    'password' => 'sua_senha',
    'callback_uri' => 'sua_callback_uri'
];

$service = new SicoobService($config);
```

### Exemplos de Utilização

#### Gerar Código de Autorização

```php
$urlAuthorization = $service->getAuthorizationUrl();
// Redirecione o usuário para esta URL
```

#### Obter Token de Acesso

```php
$response = $service->getTokenByCode('codigo_autorizacao');
```

#### Listar Cobranças

```php
$cobrancas = $service->listarCobrancas([
    'dataInicial' => '2024-01-01',
    'dataFinal' => '2024-01-31'
]);
```

#### Criar Cobrança

```php
$cobranca = $service->criarCobranca([
    'valor' => 100.00,
    'descricao' => 'Serviço prestado',
    'cpfCnpjBeneficiario' => '12345678900',
    'nomeBeneficiario' => 'João da Silva',
    // ... outros parâmetros
]);
```

---

## 🏦 Adicionando Suporte a Outros Bancos

Este projeto utiliza o **Padrão Strategy** para facilitar a extensão e suporte a múltiplos bancos. Para adicionar um novo banco (ex: Bradesco, Itaú, etc.), siga os passos abaixo:

### Passo a Passo

#### 1. Crie uma Nova Estratégia de Autenticação

Implemente a interface `AuthStrategyInterface` para definir como o novo banco autentica:

```php
<?php

namespace App\Services\Strategies;

class BancoStrategy implements AuthStrategyInterface
{
    protected array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
    }
    
    public function getAuthorizationUrl(): string
    {
        // Implementar URL de autorização específica do banco
        return 'https://api.banco.com.br/oauth/authorize?' . http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['callback_uri'],
            'response_type' => 'code',
            'scope' => $this->config['scope'] ?? ''
        ]);
    }
    
    public function getTokenByCode(string $code): array
    {
        // Implementar lógica para obter token usando o código de autorização
        // Fazer requisição POST para o endpoint do banco
        // Retornar array com access_token, refresh_token, expires_in, etc.
    }
    
    public function refreshToken(string $refreshToken): array
    {
        // Implementar lógica para renovar token
        // Fazer requisição POST para o endpoint de refresh do banco
        // Retornar novo array de tokens
    }
}
```

#### 2. (Opcional) Crie uma Estratégia de Armazenamento de Token

Se precisar de um armazenamento diferente do padrão:

```php
<?php

namespace App\Services\Strategies;

class RedisTokenStorage implements TokenStorageInterface
{
    protected \Redis $redis;
    
    public function __construct()
    {
        $this->redis = new \Redis();
        $this->redis->connect('127.0.0.1', 6379);
    }
    
    public function getToken(): ?string
    {
        return $this->redis->get('banco_access_token') ?: null;
    }
    
    public function saveToken(string $token, int $expiresIn): void
    {
        $this->redis->setex('banco_access_token', $expiresIn, $token);
    }
    
    public function clearToken(): void
    {
        $this->redis->del('banco_access_token');
    }
}
```

#### 3. Utilize a Nova Estratégia

```php
use App\Services\SicoobService;
use App\Services\Strategies\BancoStrategy;
use App\Services\Strategies\RedisTokenStorage;

// Configurações específicas do novo banco
$config = [
    'basic_token' => 'token_banco',
    'client_id' => 'client_banco',
    'client_secret' => 'secret_banco',
    'conta_corrente' => 'conta_banco',
    'cooperativa' => 'agencia_banco',
    'chave_acesso' => 'chave_banco',
    'numero_contrato' => 'contrato_banco',
    'password' => 'senha_banco',
    'callback_uri' => 'https://seusite.com/callback',
    'scope' => 'escopo_necessario' // Específico do banco
];

// Instanciar estratégias
$authStrategy = new BancoStrategy($config);
$tokenStorage = new RedisTokenStorage();

// Criar serviço com as novas estratégias
$service = new SicoobService($config, $authStrategy, $tokenStorage);

// Usar normalmente
$urlAuth = $service->getAuthorizationUrl();
```

### Checklist para Novo Banco

- [ ] Criar classe de estratégia de autenticação implementando `AuthStrategyInterface`
- [ ] Implementar método `getAuthorizationUrl()`
- [ ] Implementar método `getTokenByCode(string $code)`
- [ ] Implementar método `refreshToken(string $refreshToken)`
- [ ] (Opcional) Criar estratégia de armazenamento de token
- [ ] Testar fluxo completo de autenticação
- [ ] Documentar endpoints específicos do banco no README

### Benefícios do Padrão Strategy

✅ **Aberto para extensão**: Novos bancos podem ser adicionados sem modificar o código existente  
✅ **Fechado para modificação**: O núcleo do serviço permanece estável  
✅ **Testabilidade**: Estratégias podem ser mockadas em testes unitários  
✅ **Flexibilidade**: Troca de comportamento em tempo de execução  
✅ **Manutenibilidade**: Cada banco tem sua própria implementação isolada  

---

## 🤝 Contribuição

Contribuições são bem-vindas! Se você implementar suporte para outro banco, considere fazer um pull request.

**PIX para colaboração**: luizmagao@gmail.com

---

## 📄 Licença

Este projeto está disponível para uso livre. Consulte o repositório para mais detalhes.
