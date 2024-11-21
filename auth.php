<?php
require_once 'init.php'; // Inicialize o WHMCS
use WHMCS\Database\Capsule;

$token = $_GET['token'] ?? null;
$destination = $_GET['destination'] ?? 'clientarea'; // Define como 'clientarea' se nenhum destination for passado
$ssoRedirectPath = $_GET['sso_redirect_path'] ?? null;

// Decodifica o sso_redirect_path para garantir que "&amp;" seja tratado como "&"
$ssoRedirectPath = $ssoRedirectPath ? html_entity_decode($ssoRedirectPath) : null;

// Log do token e outros parâmetros
error_log("Token recebido: " . ($token ?? 'Nenhum token'));
error_log("Destination recebido: " . ($destination ?? 'Nenhum destination'));
error_log("SSO Redirect Path recebido: " . ($ssoRedirectPath ?? 'Nenhum redirect_path'));

if (!$token) {
    die('Token inválido.');
}

// Ajusta o destination se houver um sso_redirect_path presente
$fullDestination = $destination;
if ($destination === 'sso:custom_redirect' && $ssoRedirectPath) {
    $fullDestination = "sso:custom_redirect|" . $ssoRedirectPath;
}

// Buscar o token e o destination completo no banco de dados
$tokenData = Capsule::table('autologin_tokens')
    ->where('token', $token)
    ->where('destination', $fullDestination) // Verifica destination completo
    ->first();

if (!$tokenData && $destination === 'clientarea') {
    // Tenta buscar o token para clientarea quando nenhum destination é passado
    $tokenData = Capsule::table('autologin_tokens')
        ->where('token', $token)
        ->where('destination', 'clientarea')
        ->first();
}

if ($tokenData) {
    $creationTime = $tokenData->creation_time;
    $expirationTime = 86400; // 24 horas em segundos

    // Verifique se o token está dentro do período de validade
    if (time() - $creationTime < $expirationTime) {
        // Gere o SSO token para o cliente
        $clientId = $tokenData->client_id;
        
        // Configura os parâmetros para a chamada à API
        $params = [
            'client_id' => $clientId
        ];
        
        // Adiciona o destination se ele estiver presente e não for 'clientarea'
        if ($destination !== 'clientarea') {
            $params['destination'] = $destination;
            error_log("Destination set to: " . $destination);
        }
        
        // Adiciona o sso_redirect_path se o destination for sso:custom_redirect
        if ($destination === 'sso:custom_redirect' && $ssoRedirectPath) {
            $params['sso_redirect_path'] = $ssoRedirectPath;
            error_log("SSO Redirect Path set to: " . $ssoRedirectPath);
        }

        // Chamando a API local para gerar o SSO token
        $response = localAPI('CreateSsoToken', $params);
        
        // Log da resposta da API
        error_log("Resposta da API CreateSsoToken: " . print_r($response, true));

        if ($response['result'] == 'success') {
            // Delete o token para garantir uso único
            Capsule::table('autologin_tokens')->where('token', $token)->delete();
            error_log("Token excluído após uso.");

            // Redirecione o cliente para o link SSO
            header("Location: " . $response['redirect_url']);
            exit;
        } else {
            error_log("Erro ao gerar o SSO token: " . $response['message']);
        }
    } else {
        error_log("Token expirado.");
    }
} else {
    error_log("Token inválido ou não encontrado, ou destination incorreto.");
}

die('Token inválido ou expirado.');