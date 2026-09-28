<?php

declare(strict_types=1);

namespace WiseData\Mail\Mailer;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Traduz os anexos de um `Email` do Symfony para o formato da nossa API.
 *
 * Classe própria, e não um método do transport, porque muda por outro motivo:
 * o transport muda quando o contrato de ENVIO muda; isto aqui muda quando o
 * Symfony muda a forma de expor as partes. E é a peça que vale testar sozinha,
 * porque as três armadilhas abaixo são invisíveis no resultado.
 *
 * ## O que NÃO é conferido aqui
 *
 * A lista de extensões que o provedor recusa fica **só no servidor**. Duas
 * cópias divergem, e a do cliente é a que ninguém atualiza — e a divergência
 * ruim é a inversa: o pacote recusando um anexo legítimo que o servidor
 * aceitaria, sem recurso para quem integra. Tamanho e quantidade, sim, são
 * espelhados: são números estáveis, e descobrir tarde custa subir megabytes
 * para receber um 422.
 */
final class AttachmentConverter
{
    /** O mesmo teto do servidor. Em bytes, já decodificados. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_COUNT = 10;

    /**
     * @return list<array<string, string>>
     *
     * @throws WiseDataMailTransportException
     */
    public static function fromEmail(Email $email): array
    {
        $partes = $email->getAttachments();

        if ($partes === []) {
            return [];
        }

        if (count($partes) > self::MAX_COUNT) {
            throw new WiseDataMailTransportException(sprintf(
                'Esta mensagem tem %d anexos, e o máximo é %d. NADA foi enviado.',
                count($partes),
                self::MAX_COUNT,
            ));
        }

        $anexos = [];
        $total = 0;

        foreach ($partes as $posicao => $parte) {
            $anexo = self::converter($parte, $posicao);

            $total += strlen((string) base64_decode($anexo['content'], true));

            $anexos[] = $anexo;
        }

        if ($total > self::MAX_BYTES) {
            throw new WiseDataMailTransportException(sprintf(
                'A soma dos anexos tem %d MB, e o máximo é %d MB. NADA foi enviado.',
                (int) ceil($total / 1024 / 1024),
                (int) (self::MAX_BYTES / 1024 / 1024),
            ));
        }

        return $anexos;
    }

    /**
     * @return array<string, string>
     *
     * @throws WiseDataMailTransportException
     */
    private static function converter(DataPart $parte, int $posicao): array
    {
        $nome = $parte->getFilename();

        /*
         * O Symfony PERMITE parte sem nome. Inventar `anexo-1.bin` seria pior
         * que recusar: o arquivo chegaria ao destinatário com um nome que
         * ninguém escolheu e sem nenhum indício de que foi inventado.
         */
        if ($nome === null || trim($nome) === '') {
            throw new WiseDataMailTransportException(sprintf(
                'O anexo na posição %d não tem nome de arquivo, e o WiseData Mail exige um. '
                .'NADA foi enviado — use `attach($conteudo, "nome.pdf")`.',
                $posicao + 1,
            ));
        }

        /*
         * Nome fora de UTF-8 — típico de sistema Windows em latin-1 — faz o
         * `json_encode` do transporte devolver `false`, e o erro que chega a
         * quem integra é "o corpo não pôde virar JSON", que manda procurar no
         * lugar errado.
         */
        if (! mb_check_encoding($nome, 'UTF-8')) {
            throw new WiseDataMailTransportException(sprintf(
                'O nome do anexo na posição %d não está em UTF-8. NADA foi enviado.',
                $posicao + 1,
            ));
        }

        $embutido = $parte->getPreparedHeaders()->getHeaderBody('Content-Disposition') === 'inline';

        $anexo = [
            'filename' => $nome,
            'content' => base64_encode($parte->getBody()),
            'content_type' => $parte->getMediaType().'/'.$parte->getMediaSubtype(),
            'disposition' => $embutido ? 'inline' : 'attachment',
        ];

        /*
         * `getContentId()` GERA um identificador quando não existe. Lido fora do
         * ramo embutido, todo anexo comum ganharia um `content_id` — e aí o
         * inline deixaria de se distinguir do resto, que é justamente o que
         * decide se a imagem aparece no corpo ou vira um arquivo pendurado.
         */
        if ($embutido) {
            $anexo['content_id'] = $parte->getContentId();
        }

        return $anexo;
    }
}
