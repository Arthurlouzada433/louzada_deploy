<?php
require_once __DIR__ . '/config.php';

/**
 * Criptografa um texto (ex: telefone, endereço) com AES-256-GCM.
 * Retorna dados binários prontos para salvar numa coluna VARBINARY.
 * Guarda junto o IV e a "tag" de autenticação (necessários para descriptografar).
 */
function criptografar(?string $textoPlano): ?string {
    if ($textoPlano === null || $textoPlano === '') return null;
    $chave = hex2bin(CHAVE_CRIPTOGRAFIA_HEX);
    $iv = random_bytes(12); // recomendado para GCM
    $tag = '';
    $cifrado = openssl_encrypt($textoPlano, 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cifrado === false) throw new RuntimeException('Falha ao criptografar');
    // formato salvo: IV (12 bytes) + TAG (16 bytes) + texto cifrado
    return $iv . $tag . $cifrado;
}

/**
 * Descriptografa o que foi salvo por criptografar().
 */
function descriptografar(?string $valorBinario): ?string {
    if ($valorBinario === null || $valorBinario === '') return null;
    $chave = hex2bin(CHAVE_CRIPTOGRAFIA_HEX);
    $iv = substr($valorBinario, 0, 12);
    $tag = substr($valorBinario, 12, 16);
    $cifrado = substr($valorBinario, 28);
    $resultado = openssl_decrypt($cifrado, 'aes-256-gcm', $chave, OPENSSL_RAW_DATA, $iv, $tag);
    if ($resultado === false) return null; // dado corrompido ou chave errada
    return $resultado;
}

/**
 * Senha: NUNCA criptografamos senha (criptografia é reversível).
 * Usamos HASH DUPLO, em duas camadas:
 *   1) HMAC-SHA256 da senha com um "pepper" secreto (SENHA_PEPPER_HEX, guardado só no config.php)
 *   2) bcrypt em cima do resultado (com salt próprio e custo ajustável)
 * Se alguém roubar só o banco de dados, não consegue nem testar senhas, pois falta o pepper.
 * Os hashes novos começam com "v2$". Os antigos (bcrypt simples) continuam funcionando
 * e são convertidos para o formato novo automaticamente no próximo login.
 */
const SENHA_HASH_PREFIXO = 'v2$';

function primeiroHashSenha(string $senha): string {
    if (!defined('SENHA_PEPPER_HEX') || SENHA_PEPPER_HEX === '') {
        throw new RuntimeException('SENHA_PEPPER_HEX não configurado em config.php');
    }
    // base64 do HMAC: 44 caracteres, sem bytes nulos, cabe no limite de 72 bytes do bcrypt
    return base64_encode(hash_hmac('sha256', $senha, hex2bin(SENHA_PEPPER_HEX), true));
}

function gerarHashSenha(string $senha): string {
    return SENHA_HASH_PREFIXO . password_hash(primeiroHashSenha($senha), PASSWORD_BCRYPT, ['cost' => 12]);
}

function verificarSenha(string $senha, string $hash): bool {
    if (strncmp($hash, SENHA_HASH_PREFIXO, strlen(SENHA_HASH_PREFIXO)) === 0) {
        return password_verify(primeiroHashSenha($senha), substr($hash, strlen(SENHA_HASH_PREFIXO)));
    }
    // formato antigo (bcrypt simples), aceito só para migração
    return password_verify($senha, $hash);
}

/** true se o hash salvo ainda está no formato antigo (ou com custo menor) e deve ser refeito. */
function senhaPrecisaAtualizar(string $hash): bool {
    return strncmp($hash, SENHA_HASH_PREFIXO, strlen(SENHA_HASH_PREFIXO)) !== 0;
}