<?php

if (!defined("WHMCS")) die("Acesso restrito.");

use WHMCS\Database\Capsule;

/**
 * Função para verificar e criar a tabela de tokens de autologin, se não existir.
 */
function verificarOuCriarTabelaAutologin() {
    if (!Capsule::schema()->hasTable('autologin_tokens')) {
        Capsule::schema()->create('autologin_tokens', function ($table) {
            $table->increments('id');
            $table->integer('client_id')->unsigned();
            $table->string('token', 64)->unique();
            $table->string('destination');
            $table->integer('creation_time');
        });
        error_log("Tabela 'autologin_tokens' criada com sucesso.");
    } else {
        error_log("Tabela 'autologin_tokens' já existe. Verificando colunas...");

        // Verificar se cada coluna existe e criar se estiver ausente
        if (!Capsule::schema()->hasColumn('autologin_tokens', 'id')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->increments('id');
            });
            error_log("Coluna 'id' adicionada à tabela 'autologin_tokens'.");
        }

        if (!Capsule::schema()->hasColumn('autologin_tokens', 'client_id')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->integer('client_id')->unsigned();
            });
            error_log("Coluna 'client_id' adicionada à tabela 'autologin_tokens'.");
        }

        if (!Capsule::schema()->hasColumn('autologin_tokens', 'token')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->string('token', 64)->unique();
            });
            error_log("Coluna 'token' adicionada à tabela 'autologin_tokens'.");
        }

        if (!Capsule::schema()->hasColumn('autologin_tokens', 'destination')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->string('destination');
            });
            error_log("Coluna 'destination' adicionada à tabela 'autologin_tokens'.");
        }

        if (!Capsule::schema()->hasColumn('autologin_tokens', 'creation_time')) {
            Capsule::schema()->table('autologin_tokens', function ($table) {
                $table->integer('creation_time');
            });
            error_log("Coluna 'creation_time' adicionada à tabela 'autologin_tokens'.");
        }
    }
}

/**
 * Gera um link de login automático para o cliente com token personalizado e destino opcional.
 *
 * @param int $clientId ID do cliente no WHMCS.
 * @param string $destination Página de destino após o login: 'clientarea', 'clientarea:invoices', ou 'clientarea:submit_ticket'.
 * @param string $customRedirect Caminho personalizado para redirecionamento
 * @return string URL de login automático.
 */
function gerarLinkAutoLogin($clientId, $destination = 'clientarea', $customRedirect = '') {
    verificarOuCriarTabelaAutologin(); // Verifica e cria a tabela se necessário
    
    if (empty($clientId) || !is_numeric($clientId)) {
        error_log("Client ID inválido ao gerar link de autologin.");
        return ''; // Retorna vazio se o client_id for inválido
    }

    // Tempo de expiração do token em segundos (24 horas)
    $expirationTime = 86400;

    // Se houver um caminho de redirecionamento personalizado, inclua-o como parte do destination
    if ($customRedirect) {
        $destination = "sso:custom_redirect|" . $customRedirect;
    }

    // Verificar se já existe um token ativo para o client_id e destination completo (incluindo redirect)
    $tokenData = Capsule::table('autologin_tokens')
        ->where('client_id', $clientId)
        ->where('destination', $destination) // Verifica destination completo
        ->first();

    if ($tokenData && (time() - $tokenData->creation_time < $expirationTime)) {
        $token = $tokenData->token;
        error_log("Token ativo encontrado para o cliente ID: $clientId e destination: $destination, reutilizando token.");
    } else {
        // Deletar tokens expirados ou criar novo se nenhum token existir para o destino completo
        if ($tokenData) {
            Capsule::table('autologin_tokens')->where('id', $tokenData->id)->delete();
            error_log("Token expirado ou incorreto para destination, criando novo token para destination: $destination.");
        }
        $token = hash('sha256', uniqid(rand(), true));
        Capsule::table('autologin_tokens')->insert([
            'client_id' => $clientId,
            'token' => $token,
            'destination' => $destination, // Armazena destination completo
            'creation_time' => time()
        ]);
    }

    // Construir a URL de autologin com o token personalizado
    $whmcsUrl = Capsule::table('tblconfiguration')->where('setting', 'SystemURL')->value('value');
    $authUrl = $whmcsUrl . "auth.php?token=$token";

    // Adiciona o destination ou redirecionamento personalizado na URL
    if ($customRedirect) {
        $authUrl .= "&destination=sso:custom_redirect&sso_redirect_path=" . urlencode($customRedirect);
    } elseif ($destination !== 'clientarea') {
        $authUrl .= "&destination=$destination";
    }

    return $authUrl;
}

/**
 * Hook para adicionar diferentes campos de mesclagem de auto-login em todos os e-mails.
 */
function CustomEmail_EmailPreSend($vars) {
    if (isset($vars['relid']) && $vars['relid'] > 0) {
        $clientId = $vars['mergefields']['client_id'];

        // Verificar se o URL do ticket e o ID da fatura estão disponíveis no mergefields
        $ticketUrl = $vars['mergefields']['ticket_url'] ?? null;
        $invoiceId = $vars['mergefields']['invoice_id'] ?? null;

        // Extraímos apenas o caminho desejado da URL completa do ticket
        $customRedirectPathTicket = null;
        if ($ticketUrl) {
            $parsedUrl = parse_url($ticketUrl);
            $customRedirectPathTicket = ltrim($parsedUrl['path'], '/') . '?' . $parsedUrl['query'];
        }

        // Construir o caminho para a fatura específica, se o ID estiver disponível
        $customRedirectPathInvoice = $invoiceId ? "viewinvoice.php?id=" . $invoiceId : null;

        // Gerar links de autologin para diferentes destinos
        $autoLoginLink = gerarLinkAutoLogin($clientId, 'clientarea');
        $autoLoginLinkSubmitTicket = gerarLinkAutoLogin($clientId, 'clientarea:submit_ticket');
        $autoLoginLinkTicket = gerarLinkAutoLogin($clientId, 'clientarea:tickets');
        $autoLoginLinkInvoices = gerarLinkAutoLogin($clientId, 'clientarea:invoices');

        // Links específicos para o ticket e a fatura
        $autoLoginLinkSpecificTicket = $customRedirectPathTicket ? gerarLinkAutoLogin($clientId, 'clientarea', $customRedirectPathTicket) : null;
        $autoLoginLinkSpecificInvoice = $customRedirectPathInvoice ? gerarLinkAutoLogin($clientId, 'clientarea', $customRedirectPathInvoice) : null;

        error_log("Campo de mesclagem de auto-login adicionado para o cliente ID $clientId.");

        // Retornar os campos de mesclagem de links de autologin
        return [
            'auto_login_link' => $autoLoginLink,
            'auto_login_link_submit_ticket' => $autoLoginLinkSubmitTicket,
            'auto_login_link_ticket' => $autoLoginLinkTicket,
            'auto_login_link_invoices' => $autoLoginLinkInvoices,
            'auto_login_link_specific_ticket' => $autoLoginLinkSpecificTicket,
            'auto_login_link_specific_invoice' => $autoLoginLinkSpecificInvoice
        ];
    } else {
        error_log("ID do cliente não encontrado ou inválido.");
    }

    return [];
}

add_hook("EmailPreSend", 1, "CustomEmail_EmailPreSend");